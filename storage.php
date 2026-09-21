<?php
// storage.php

declare(strict_types=1);

class InvalidResultIdException extends RuntimeException
{
}

class ResultIntegrityException extends RuntimeException
{
}

class UnsupportedResultSchemaException extends RuntimeException
{
}

/**
 * Return the SQLite database path used for immutable result records.
 *
 * Production deployments should set SECUREDICE_DB_PATH to a location outside
 * the web root. The bundled data directory is protected for Apache installs
 * and provides a zero-configuration default for small deployments.
 */
function result_storage_path(): string
{
    $configuredPath = getenv('SECUREDICE_DB_PATH');

    if ($configuredPath !== false && trim($configuredPath) !== '') {
        return trim($configuredPath);
    }

    return __DIR__ . '/data/securedice.sqlite';
}

/**
 * Open the result database and create its schema when necessary.
 */
function result_storage_connection(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        error_log('Secure Dice result storage requires the PDO SQLite extension.');
        throw new RuntimeException('The roll could not be stored. Please try again later.');
    }

    $databasePath = result_storage_path();
    $databaseDirectory = dirname($databasePath);

    if (!is_dir($databaseDirectory) || !is_writable($databaseDirectory)) {
        error_log('Secure Dice result storage directory is missing or not writable.');
        throw new RuntimeException('The roll could not be stored. Please try again later.');
    }

    try {
        $connection = new PDO(
            'sqlite:' . $databasePath,
            null,
            null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        $connection->exec('PRAGMA busy_timeout = 5000');
        $connection->exec('PRAGMA foreign_keys = ON');
        $connection->query('PRAGMA journal_mode = WAL');
        initialize_result_storage($connection);

        harden_result_storage_permissions($databasePath);
    } catch (Throwable $e) {
        $connection = null;
        error_log('Secure Dice result storage error: ' . $e->getMessage());
        throw new RuntimeException('The roll could not be stored. Please try again later.');
    }

    return $connection;
}

/** Restrict the database and SQLite sidecar files to the PHP process owner. */
function harden_result_storage_permissions(string $databasePath): void
{
    foreach ([$databasePath, $databasePath . '-shm', $databasePath . '-wal'] as $path) {
        if (file_exists($path)) {
            @chmod($path, 0600);
        }
    }
}

/**
 * Create the insert-only result table and its database-level update guard.
 */
function initialize_result_storage(PDO $connection): void
{
    $storageSchemaVersion = (int) $connection->query('PRAGMA user_version')->fetchColumn();

    if ($storageSchemaVersion > 4) {
        throw new RuntimeException('The result database uses a newer schema.');
    }

    $connection->beginTransaction();

    try {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS result_records (
                public_id TEXT PRIMARY KEY CHECK (length(public_id) = 32),
                schema_version INTEGER NOT NULL,
                generated_at TEXT NOT NULL,
                canonical_json TEXT NOT NULL,
                content_sha256 TEXT NOT NULL CHECK (length(content_sha256) = 64),
                stored_at TEXT NOT NULL
            ) WITHOUT ROWID'
        );

        $connection->exec(
            "CREATE TRIGGER IF NOT EXISTS result_records_prevent_update
            BEFORE UPDATE ON result_records
            BEGIN
                SELECT RAISE(ABORT, 'result records are immutable');
            END"
        );

        if ($storageSchemaVersion < 2) {
            $connection->exec(
                "CREATE TABLE IF NOT EXISTS recipients (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    email_fingerprint TEXT NOT NULL UNIQUE CHECK (length(email_fingerprint) = 64),
                    email_ciphertext TEXT NOT NULL,
                    status TEXT NOT NULL CHECK (status IN ('pending', 'active', 'revoked')),
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    confirmed_at TEXT,
                    revoked_at TEXT
                )"
            );

            $connection->exec(
                'CREATE TABLE IF NOT EXISTS consent_challenges (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    recipient_id INTEGER NOT NULL,
                    token_hash TEXT NOT NULL UNIQUE CHECK (length(token_hash) = 64),
                    expires_at TEXT NOT NULL,
                    consumed_at TEXT,
                    created_at TEXT NOT NULL,
                    FOREIGN KEY (recipient_id) REFERENCES recipients(id) ON DELETE CASCADE
                )'
            );

            $connection->exec(
                "CREATE TABLE IF NOT EXISTS recipient_capabilities (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    recipient_id INTEGER NOT NULL,
                    code_hash TEXT NOT NULL UNIQUE CHECK (length(code_hash) = 64),
                    status TEXT NOT NULL CHECK (status IN ('active', 'revoked')),
                    created_at TEXT NOT NULL,
                    revoked_at TEXT,
                    FOREIGN KEY (recipient_id) REFERENCES recipients(id) ON DELETE CASCADE
                )"
            );

            $connection->exec(
                "CREATE TABLE IF NOT EXISTS recipient_management_tokens (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    recipient_id INTEGER NOT NULL,
                    token_hash TEXT NOT NULL UNIQUE CHECK (length(token_hash) = 64),
                    status TEXT NOT NULL CHECK (status IN ('active', 'revoked')),
                    created_at TEXT NOT NULL,
                    revoked_at TEXT,
                    FOREIGN KEY (recipient_id) REFERENCES recipients(id) ON DELETE CASCADE
                )"
            );

            $connection->exec(
                "CREATE TABLE IF NOT EXISTS outbound_messages (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    message_type TEXT NOT NULL CHECK (message_type IN ('consent_confirmation')),
                    recipient_id INTEGER NOT NULL,
                    payload_ciphertext TEXT NOT NULL,
                    status TEXT NOT NULL CHECK (status IN ('pending', 'sending', 'sent', 'failed')),
                    attempts INTEGER NOT NULL DEFAULT 0,
                    available_at TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    sent_at TEXT,
                    last_error TEXT,
                    FOREIGN KEY (recipient_id) REFERENCES recipients(id) ON DELETE CASCADE
                )"
            );

            $connection->exec(
                'CREATE TABLE IF NOT EXISTS rate_limits (
                    bucket_key TEXT PRIMARY KEY CHECK (length(bucket_key) = 64),
                    window_started_at INTEGER NOT NULL,
                    attempts INTEGER NOT NULL
                ) WITHOUT ROWID'
            );

            $connection->exec(
                'CREATE INDEX IF NOT EXISTS consent_challenges_recipient
                ON consent_challenges(recipient_id, consumed_at, expires_at)'
            );
            $connection->exec(
                'CREATE INDEX IF NOT EXISTS recipient_capabilities_recipient
                ON recipient_capabilities(recipient_id, status)'
            );
            $connection->exec(
                'CREATE INDEX IF NOT EXISTS recipient_management_recipient
                ON recipient_management_tokens(recipient_id, status)'
            );
            $connection->exec(
                'CREATE INDEX IF NOT EXISTS outbound_messages_pending
                ON outbound_messages(status, available_at)'
            );

            $connection->exec('PRAGMA user_version = 2');
        }

        if ($storageSchemaVersion < 3) {
            $connection->exec(
                "ALTER TABLE consent_challenges
                ADD COLUMN purpose TEXT NOT NULL DEFAULT 'enroll'
                CHECK (purpose IN ('enroll', 'management_recovery'))"
            );

            $connection->exec(
                "CREATE TABLE outbound_messages_v3 (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    message_type TEXT NOT NULL CHECK (
                        message_type IN ('consent_confirmation', 'management_recovery', 'consent_credentials')
                    ),
                    recipient_id INTEGER NOT NULL,
                    payload_ciphertext TEXT NOT NULL,
                    status TEXT NOT NULL CHECK (status IN ('pending', 'sending', 'sent', 'failed')),
                    attempts INTEGER NOT NULL DEFAULT 0,
                    available_at TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    sent_at TEXT,
                    last_error TEXT,
                    FOREIGN KEY (recipient_id) REFERENCES recipients(id) ON DELETE CASCADE
                )"
            );
            $connection->exec(
                'INSERT INTO outbound_messages_v3
                (id, message_type, recipient_id, payload_ciphertext, status, attempts,
                    available_at, created_at, sent_at, last_error)
                SELECT id, message_type, recipient_id, payload_ciphertext,
                    CASE WHEN status = \'sending\' THEN \'pending\' ELSE status END, attempts,
                    available_at, created_at, sent_at, last_error
                FROM outbound_messages'
            );
            $connection->exec('DROP TABLE outbound_messages');
            $connection->exec('ALTER TABLE outbound_messages_v3 RENAME TO outbound_messages');

            $connection->exec(
                'CREATE TABLE recipient_unsubscribe_tokens (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    recipient_id INTEGER NOT NULL,
                    token_hash TEXT NOT NULL UNIQUE CHECK (length(token_hash) = 64),
                    created_at TEXT NOT NULL,
                    used_at TEXT,
                    FOREIGN KEY (recipient_id) REFERENCES recipients(id) ON DELETE CASCADE
                )'
            );

            $connection->exec(
                'CREATE INDEX outbound_messages_pending
                ON outbound_messages(status, available_at)'
            );
            $connection->exec(
                'CREATE INDEX recipient_unsubscribe_recipient
                ON recipient_unsubscribe_tokens(recipient_id, used_at)'
            );

            $connection->exec('PRAGMA user_version = 3');
        }

        if ($storageSchemaVersion < 4) {
            $connection->exec(
                "ALTER TABLE recipients
                ADD COLUMN delivery_mode TEXT NOT NULL DEFAULT 'tabletop'
                CHECK (delivery_mode IN ('tabletop', 'occasional', 'paused'))"
            );

            $connection->exec(
                'CREATE TABLE email_requests (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    public_id TEXT NOT NULL UNIQUE CHECK (length(public_id) = 32),
                    result_id TEXT NOT NULL,
                    source_bucket TEXT NOT NULL CHECK (length(source_bucket) = 64),
                    intended_count INTEGER NOT NULL,
                    opted_in_count INTEGER NOT NULL,
                    not_opted_in_count INTEGER NOT NULL,
                    not_queued_count INTEGER NOT NULL,
                    created_at TEXT NOT NULL,
                    FOREIGN KEY (result_id) REFERENCES result_records(public_id)
                )'
            );

            $connection->exec(
                "CREATE TABLE outbound_messages_v4 (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    message_type TEXT NOT NULL CHECK (
                        message_type IN (
                            'consent_confirmation', 'management_recovery',
                            'consent_credentials', 'result_delivery'
                        )
                    ),
                    recipient_id INTEGER NOT NULL,
                    request_id INTEGER,
                    result_id TEXT,
                    payload_ciphertext TEXT,
                    status TEXT NOT NULL CHECK (status IN ('pending', 'sending', 'sent', 'failed')),
                    attempts INTEGER NOT NULL DEFAULT 0,
                    available_at TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    claimed_at TEXT,
                    lease_token TEXT,
                    sent_at TEXT,
                    last_error TEXT,
                    FOREIGN KEY (recipient_id) REFERENCES recipients(id) ON DELETE CASCADE,
                    FOREIGN KEY (request_id) REFERENCES email_requests(id),
                    FOREIGN KEY (result_id) REFERENCES result_records(public_id)
                )"
            );
            $connection->exec(
                'INSERT INTO outbound_messages_v4
                (id, message_type, recipient_id, payload_ciphertext, status, attempts,
                    available_at, created_at, sent_at, last_error)
                SELECT id, message_type, recipient_id, payload_ciphertext,
                    CASE WHEN status = \'sending\' THEN \'pending\' ELSE status END, attempts,
                    available_at, created_at, sent_at, last_error
                FROM outbound_messages'
            );
            $connection->exec('DROP TABLE outbound_messages');
            $connection->exec('ALTER TABLE outbound_messages_v4 RENAME TO outbound_messages');

            $connection->exec(
                "CREATE TABLE email_delivery_history (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    message_id INTEGER NOT NULL,
                    request_id INTEGER,
                    recipient_id INTEGER NOT NULL,
                    result_id TEXT,
                    message_type TEXT NOT NULL,
                    status TEXT NOT NULL CHECK (status IN ('queued', 'retrying', 'sent', 'failed', 'cancelled')),
                    attempt_count INTEGER NOT NULL DEFAULT 0,
                    queued_at TEXT NOT NULL,
                    last_attempt_at TEXT,
                    delivered_at TEXT,
                    error_category TEXT,
                    provider_message_id TEXT,
                    UNIQUE (message_id),
                    FOREIGN KEY (recipient_id) REFERENCES recipients(id) ON DELETE CASCADE,
                    FOREIGN KEY (request_id) REFERENCES email_requests(id),
                    FOREIGN KEY (result_id) REFERENCES result_records(public_id)
                )"
            );
            $connection->exec(
                "INSERT INTO email_delivery_history
                (message_id, recipient_id, message_type, status, attempt_count,
                    queued_at, delivered_at, error_category)
                SELECT id, recipient_id, message_type,
                    CASE
                        WHEN status = 'sent' THEN 'sent'
                        WHEN status = 'failed' THEN 'failed'
                        ELSE 'queued'
                    END,
                    attempts, created_at, sent_at,
                    CASE WHEN status = 'failed' THEN 'legacy' ELSE NULL END
                FROM outbound_messages"
            );

            $connection->exec(
                'CREATE INDEX outbound_messages_pending
                ON outbound_messages(status, available_at)'
            );
            $connection->exec(
                'CREATE INDEX outbound_messages_recipient_batch
                ON outbound_messages(recipient_id, message_type, status, created_at)'
            );
            $connection->exec(
                'CREATE INDEX email_history_recipient
                ON email_delivery_history(recipient_id, queued_at)'
            );

            // Schema 4 replaces permanent recipient share codes with direct,
            // server-side address lookup after one-time opt-in confirmation.
            $connection->exec(
                "UPDATE recipient_capabilities
                SET status = 'revoked', revoked_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now')
                WHERE status = 'active'"
            );

            $connection->exec('PRAGMA user_version = 4');
        }

        $connection->commit();
    } catch (Throwable $e) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }

        throw $e;
    }
}

/**
 * Recursively sort object keys while preserving the order of list elements.
 */
function canonicalize_json_value($value)
{
    if (!is_array($value)) {
        return $value;
    }

    if (result_array_is_list($value)) {
        return array_map('canonicalize_json_value', $value);
    }

    ksort($value, SORT_STRING);

    foreach ($value as $key => $item) {
        $value[$key] = canonicalize_json_value($item);
    }

    return $value;
}

/** Return true when an array uses consecutive integer keys starting at zero. */
function result_array_is_list(array $value): bool
{
    return ($value === []) || (array_keys($value) === range(0, count($value) - 1));
}

/**
 * Encode a result in a deterministic form suitable for permanent storage.
 */
function encode_canonical_result(array $result): string
{
    try {
        return json_encode(
            canonicalize_json_value($result),
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        );
    } catch (JsonException $e) {
        error_log('Secure Dice canonical JSON error: ' . $e->getMessage());
        throw new RuntimeException('The roll could not be stored. Please try again later.');
    }
}

/**
 * Persist one result and return the result augmented with its public ID.
 *
 * There is deliberately no update function. The database trigger also rejects
 * updates so later application changes cannot silently rewrite old results.
 */
function store_result_record(array $result): array
{
    if (isset($result['result_id'])) {
        throw new InvalidArgumentException('The result already has an ID.');
    }

    $result['result_id'] = bin2hex(random_bytes(16));
    $canonicalJson = encode_canonical_result($result);
    $contentSha256 = hash('sha256', $canonicalJson);
    $generatedAt = (string) ($result['generated_at'] ?? '');
    $schemaVersion = (int) ($result['schema_version'] ?? 0);

    if ($generatedAt === '' || $schemaVersion < 1) {
        throw new InvalidArgumentException('The result record is incomplete.');
    }

    try {
        $statement = result_storage_connection()->prepare(
            'INSERT INTO result_records (
                public_id,
                schema_version,
                generated_at,
                canonical_json,
                content_sha256,
                stored_at
            ) VALUES (
                :public_id,
                :schema_version,
                :generated_at,
                :canonical_json,
                :content_sha256,
                :stored_at
            )'
        );

        $statement->execute([
            ':public_id' => $result['result_id'],
            ':schema_version' => $schemaVersion,
            ':generated_at' => $generatedAt,
            ':canonical_json' => $canonicalJson,
            ':content_sha256' => $contentSha256,
            ':stored_at' => gmdate(DATE_ATOM),
        ]);

        harden_result_storage_permissions(result_storage_path());
    } catch (Throwable $e) {
        error_log('Secure Dice result insert error: ' . $e->getMessage());
        throw new RuntimeException('The roll could not be stored. Please try again later.');
    }

    return $result;
}

/**
 * Validate the parts of a version-2 result required for safe presentation.
 */
function validate_result_payload_v2(array $result): bool
{
    if (
        (int) ($result['schema_version'] ?? 0) !== 2
        || preg_match('/^[a-f0-9]{32}$/', (string) ($result['result_id'] ?? '')) !== 1
        || (string) ($result['generated_at'] ?? '') === ''
        || !is_array($result['specification'] ?? null)
        || !is_array($result['summary'] ?? null)
        || !is_array($result['sets'] ?? null)
    ) {
        return false;
    }

    try {
        new DateTimeImmutable((string) $result['generated_at']);
    } catch (Throwable $e) {
        return false;
    }

    $specification = $result['specification'];
    $summary = $result['summary'];
    $pools = $specification['pools'] ?? null;
    $repeat = (int) ($specification['repeat'] ?? 0);
    $sets = $result['sets'];

    if (
        $repeat < 1
        || !is_array($pools)
        || !is_array($pools['A'] ?? null)
        || (($pools['B'] ?? null) !== null && !is_array($pools['B']))
        || !isset($summary['min'], $summary['max'], $summary['avg'])
        || !is_numeric($summary['min'])
        || !is_numeric($summary['max'])
        || !is_numeric($summary['avg'])
        || !result_array_is_list($sets)
        || count($sets) !== $repeat
    ) {
        return false;
    }

    foreach ($sets as $set) {
        if (
            !is_array($set)
            || !isset($set['number'], $set['total'])
            || !is_int($set['number'])
            || !is_int($set['total'])
            || !is_array($set['terms'] ?? null)
            || !result_array_is_list($set['terms'])
            || $set['terms'] === []
        ) {
            return false;
        }

        foreach ($set['terms'] as $term) {
            if (
                !is_array($term)
                || !isset($term['pool'], $term['operator'])
                || !is_array($term['result'] ?? null)
                || !is_array($term['result']['dice'] ?? null)
                || !result_array_is_list($term['result']['dice'])
            ) {
                return false;
            }

            foreach ($term['result']['dice'] as $die) {
                if (
                    !is_array($die)
                    || !isset($die['index'], $die['value'], $die['kept'], $die['role'])
                    || !is_int($die['index'])
                    || !is_int($die['value'])
                    || !is_bool($die['kept'])
                    || !is_string($die['role'])
                ) {
                    return false;
                }
            }
        }
    }

    return true;
}

/**
 * Load and authenticate one immutable result record.
 *
 * A null return means the well-formed public ID does not exist. Exceptions
 * distinguish malformed IDs, corrupt records, and unsupported schemas.
 */
function load_result_record(string $publicId): ?array
{
    $publicId = strtolower(trim($publicId));

    if (preg_match('/^[a-f0-9]{32}$/', $publicId) !== 1) {
        throw new InvalidResultIdException('The result ID is invalid.');
    }

    try {
        $statement = result_storage_connection()->prepare(
            'SELECT
                public_id,
                schema_version,
                generated_at,
                canonical_json,
                content_sha256,
                stored_at
            FROM result_records
            WHERE public_id = :public_id'
        );
        $statement->execute([':public_id' => $publicId]);
        $record = $statement->fetch();
    } catch (Throwable $e) {
        error_log('Secure Dice result read error: ' . $e->getMessage());
        throw new RuntimeException('The result database could not be read.');
    }

    if (!is_array($record)) {
        return null;
    }

    $canonicalJson = (string) ($record['canonical_json'] ?? '');
    $storedDigest = (string) ($record['content_sha256'] ?? '');
    $calculatedDigest = hash('sha256', $canonicalJson);

    if (
        strlen($storedDigest) !== 64
        || !hash_equals($storedDigest, $calculatedDigest)
    ) {
        throw new ResultIntegrityException('The stored result failed its integrity check.');
    }

    try {
        $result = json_decode($canonicalJson, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new ResultIntegrityException('The stored result is not valid JSON.');
    }

    if (
        !is_array($result)
        || (string) ($result['result_id'] ?? '') !== $publicId
        || (int) ($result['schema_version'] ?? 0) !== (int) $record['schema_version']
        || (string) ($result['generated_at'] ?? '') !== (string) $record['generated_at']
    ) {
        throw new ResultIntegrityException('The stored result metadata does not match its record.');
    }

    if ((int) $record['schema_version'] !== 2) {
        throw new UnsupportedResultSchemaException('The stored result uses an unsupported schema.');
    }

    if (!validate_result_payload_v2($result)) {
        throw new ResultIntegrityException('The stored result structure is invalid.');
    }

    return [
        'data' => $result,
        'canonical_json' => $canonicalJson,
        'stored_at' => (string) $record['stored_at'],
    ];
}

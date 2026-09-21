<?php
// storage.php

declare(strict_types=1);

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

    if ($storageSchemaVersion > 1) {
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

        if ($storageSchemaVersion === 0) {
            $connection->exec('PRAGMA user_version = 1');
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

    $isList = ($value === []) || (array_keys($value) === range(0, count($value) - 1));

    if ($isList) {
        return array_map('canonicalize_json_value', $value);
    }

    ksort($value, SORT_STRING);

    foreach ($value as $key => $item) {
        $value[$key] = canonicalize_json_value($item);
    }

    return $value;
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

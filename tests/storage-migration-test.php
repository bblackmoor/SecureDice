<?php

declare(strict_types=1);

$databasePath = sys_get_temp_dir()
    . '/securedice-migration-test-'
    . bin2hex(random_bytes(8))
    . '.sqlite';

putenv('SECUREDICE_DB_PATH=' . $databasePath);
putenv('SECUREDICE_SECRET=' . str_repeat('33', 32));

function migration_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $legacy = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $legacyCanonicalJson = '{"generated_at":"2026-09-21T00:00:00+00:00","result_id":"'
        . str_repeat('d', 32)
        . '","schema_version":2}';
    $legacy->exec(
        "CREATE TABLE result_records (
            public_id TEXT PRIMARY KEY,
            schema_version INTEGER NOT NULL,
            generated_at TEXT NOT NULL,
            canonical_json TEXT NOT NULL,
            content_sha256 TEXT NOT NULL,
            stored_at TEXT NOT NULL
        ) WITHOUT ROWID"
    );
    $legacyResult = $legacy->prepare(
        'INSERT INTO result_records
        (public_id, schema_version, generated_at, canonical_json, content_sha256, stored_at)
        VALUES (?, 2, ?, ?, ?, ?)'
    );
    $legacyResult->execute([
        str_repeat('d', 32),
        '2026-09-21T00:00:00+00:00',
        $legacyCanonicalJson,
        hash('sha256', $legacyCanonicalJson),
        '2026-09-21T00:00:01+00:00',
    ]);
    $legacy->exec(
        "CREATE TRIGGER result_records_prevent_update
        BEFORE UPDATE ON result_records
        BEGIN
            SELECT RAISE(ABORT, 'result records are immutable');
        END"
    );
    $legacy->exec(
        "CREATE TABLE recipients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email_fingerprint TEXT NOT NULL UNIQUE,
            email_ciphertext TEXT NOT NULL,
            status TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            confirmed_at TEXT,
            revoked_at TEXT
        )"
    );
    $legacy->exec(
        'CREATE TABLE consent_challenges (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipient_id INTEGER NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            expires_at TEXT NOT NULL,
            consumed_at TEXT,
            created_at TEXT NOT NULL
        )'
    );
    $legacy->exec(
        "CREATE TABLE outbound_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            message_type TEXT NOT NULL CHECK (message_type IN ('consent_confirmation')),
            recipient_id INTEGER NOT NULL,
            payload_ciphertext TEXT NOT NULL,
            status TEXT NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            available_at TEXT NOT NULL,
            created_at TEXT NOT NULL,
            sent_at TEXT,
            last_error TEXT
        )"
    );
    $legacy->exec(
        "CREATE TABLE recipient_capabilities (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipient_id INTEGER NOT NULL,
            code_hash TEXT NOT NULL UNIQUE,
            status TEXT NOT NULL,
            created_at TEXT NOT NULL,
            revoked_at TEXT
        )"
    );
    $legacy->exec(
        "INSERT INTO recipients
        (email_fingerprint, email_ciphertext, status, created_at, updated_at)
        VALUES ('" . str_repeat('a', 64) . "', 'encrypted', 'pending', '2026-09-21T00:00:00Z', '2026-09-21T00:00:00Z')"
    );
    $legacy->exec(
        "INSERT INTO consent_challenges
        (recipient_id, token_hash, expires_at, created_at)
        VALUES (1, '" . str_repeat('b', 64) . "', '2026-09-22T00:00:00Z', '2026-09-21T00:00:00Z')"
    );
    $legacy->exec(
        "INSERT INTO outbound_messages
        (message_type, recipient_id, payload_ciphertext, status, available_at, created_at)
        VALUES ('consent_confirmation', 1, 'encrypted', 'sending',
            '2026-09-21T00:00:00Z', '2026-09-21T00:00:00Z')"
    );
    $legacy->exec(
        "INSERT INTO recipient_capabilities
        (recipient_id, code_hash, status, created_at)
        VALUES (1, '" . str_repeat('c', 64) . "', 'active', '2026-09-21T00:00:00Z')"
    );
    $legacy->exec('PRAGMA user_version = 2');
    $legacy = null;

    require_once dirname(__DIR__) . '/storage.php';
    $connection = result_storage_connection();

    migration_test_assert(
        (int) $connection->query('PRAGMA user_version')->fetchColumn() === 5,
        'The version-2 database was not migrated to version 5.'
    );
    migration_test_assert(
        $connection->query('SELECT purpose FROM consent_challenges WHERE id = 1')->fetchColumn() === 'enroll',
        'The existing confirmation challenge did not retain enrollment purpose.'
    );
    migration_test_assert(
        (int) $connection->query('SELECT COUNT(*) FROM outbound_messages')->fetchColumn() === 1,
        'The queued confirmation was lost during migration.'
    );
    migration_test_assert(
        $connection->query('SELECT status FROM outbound_messages WHERE id = 1')->fetchColumn() === 'pending',
        'An abandoned legacy queue claim was not released during migration.'
    );

    $insert = $connection->prepare(
        "INSERT INTO outbound_messages
        (message_type, recipient_id, payload_ciphertext, status, available_at, created_at)
        VALUES ('result_delivery', 1, 'encrypted', 'pending', :now, :now)"
    );
    $insert->execute([':now' => '2026-09-21T00:00:01Z']);
    migration_test_assert(
        (int) $connection->query(
            "SELECT COUNT(*) FROM sqlite_master
            WHERE type = 'table' AND name = 'recipient_unsubscribe_tokens'"
        )->fetchColumn() === 1,
        'The unsubscribe-token table was not created.'
    );
    migration_test_assert(
        $connection->query('SELECT delivery_mode FROM recipients WHERE id = 1')->fetchColumn() === 'tabletop',
        'The migrated recipient did not receive the tabletop delivery default.'
    );
    migration_test_assert(
        (int) $connection->query('SELECT COUNT(*) FROM email_delivery_history')->fetchColumn() === 1,
        'The existing queued message did not receive delivery history.'
    );
    migration_test_assert(
        $connection->query('SELECT status FROM recipient_capabilities WHERE id = 1')->fetchColumn() === 'revoked',
        'The retired recipient code remained active after migration.'
    );
    migration_test_assert(
        (int) $connection->query(
            "SELECT COUNT(*) FROM sqlite_master
            WHERE type = 'table' AND name = 'result_delivery_claims'"
        )->fetchColumn() === 1,
        'The result replay-claim table was not created.'
    );
    $migratedHmac = (string) $connection->query(
        'SELECT auth_hmac_sha256 FROM result_records LIMIT 1'
    )->fetchColumn();
    migration_test_assert(
        hash_equals(result_authentication_hmac($legacyCanonicalJson), $migratedHmac),
        'An existing result did not receive a valid authentication code.'
    );

    $immutableAfterMigration = false;

    try {
        $connection->exec("UPDATE result_records SET canonical_json = '{}'");
    } catch (PDOException $e) {
        $immutableAfterMigration = true;
    }
    migration_test_assert(
        $immutableAfterMigration,
        'The result immutability trigger was not restored after migration.'
    );

    echo "Storage migration tests passed.\n";
} finally {
    foreach ([$databasePath, $databasePath . '-shm', $databasePath . '-wal'] as $path) {
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}

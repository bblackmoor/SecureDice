<?php

declare(strict_types=1);

$databasePath = sys_get_temp_dir()
    . '/securedice-migration-test-'
    . bin2hex(random_bytes(8))
    . '.sqlite';

putenv('SECUREDICE_DB_PATH=' . $databasePath);

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
        VALUES ('consent_confirmation', 1, 'encrypted', 'pending',
            '2026-09-21T00:00:00Z', '2026-09-21T00:00:00Z')"
    );
    $legacy->exec('PRAGMA user_version = 2');
    $legacy = null;

    require_once dirname(__DIR__) . '/storage.php';
    $connection = result_storage_connection();

    migration_test_assert(
        (int) $connection->query('PRAGMA user_version')->fetchColumn() === 3,
        'The version-2 database was not migrated to version 3.'
    );
    migration_test_assert(
        $connection->query('SELECT purpose FROM consent_challenges WHERE id = 1')->fetchColumn() === 'enroll',
        'The existing confirmation challenge did not retain enrollment purpose.'
    );
    migration_test_assert(
        (int) $connection->query('SELECT COUNT(*) FROM outbound_messages')->fetchColumn() === 1,
        'The queued confirmation was lost during migration.'
    );

    $insert = $connection->prepare(
        "INSERT INTO outbound_messages
        (message_type, recipient_id, payload_ciphertext, status, available_at, created_at)
        VALUES ('management_recovery', 1, 'encrypted', 'pending', :now, :now)"
    );
    $insert->execute([':now' => '2026-09-21T00:00:01Z']);
    migration_test_assert(
        (int) $connection->query(
            "SELECT COUNT(*) FROM sqlite_master
            WHERE type = 'table' AND name = 'recipient_unsubscribe_tokens'"
        )->fetchColumn() === 1,
        'The unsubscribe-token table was not created.'
    );

    echo "Storage migration tests passed.\n";
} finally {
    foreach ([$databasePath, $databasePath . '-shm', $databasePath . '-wal'] as $path) {
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}

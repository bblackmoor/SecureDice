<?php

declare(strict_types=1);

$databasePath = sys_get_temp_dir()
    . '/securedice-storage-test-'
    . bin2hex(random_bytes(8))
    . '.sqlite';

putenv('SECUREDICE_DB_PATH=' . $databasePath);

require_once dirname(__DIR__) . '/storage.php';

function storage_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$payload = [
    'schema_version' => 2,
    'generated_at' => '2026-09-21T12:00:00+00:00',
    'specification' => [
        'repeat' => 1,
        'sort_results' => false,
        'pools' => [
            'A' => [
                'dice_count' => 1,
                'die_type' => 'd6',
            ],
            'B' => null,
        ],
    ],
    'summary' => ['min' => 4, 'max' => 4, 'avg' => 4.0],
    'sets' => [
        ['number' => 1, 'terms' => [], 'total' => 4],
    ],
];

try {
    $stored = store_result_record($payload);

    storage_test_assert(
        preg_match('/^[a-f0-9]{32}$/', (string) ($stored['result_id'] ?? '')) === 1,
        'A random 128-bit public result ID was not generated.'
    );

    $connection = result_storage_connection();
    storage_test_assert(
        (int) $connection->query('PRAGMA user_version')->fetchColumn() === 3,
        'The result database schema version was not initialized.'
    );
    $statement = $connection->prepare(
        'SELECT canonical_json, content_sha256
        FROM result_records
        WHERE public_id = :public_id'
    );
    $statement->execute([':public_id' => $stored['result_id']]);
    $record = $statement->fetch();

    storage_test_assert(is_array($record), 'The result record was not stored.');
    storage_test_assert(
        hash_equals(
            hash('sha256', (string) $record['canonical_json']),
            (string) $record['content_sha256']
        ),
        'The stored integrity digest does not match the canonical result.'
    );
    storage_test_assert(
        (string) $record['canonical_json'] === encode_canonical_result($stored),
        'The stored canonical result differs from the generated result.'
    );

    $updateWasRejected = false;

    try {
        $connection->exec("UPDATE result_records SET canonical_json = '{}'");
    } catch (PDOException $e) {
        $updateWasRejected = true;
    }

    storage_test_assert($updateWasRejected, 'The database allowed an immutable result to be updated.');

    $second = store_result_record($payload);
    storage_test_assert(
        $second['result_id'] !== $stored['result_id'],
        'Two stored results received the same public ID.'
    );

    echo "Storage tests passed.\n";
} finally {
    foreach ([$databasePath, $databasePath . '-shm', $databasePath . '-wal'] as $path) {
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}

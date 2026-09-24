<?php

declare(strict_types=1);

require_once __DIR__ . '/mysql-test-bootstrap.php';
securedice_test_reset();

putenv('SECUREDICE_SECRET=' . str_repeat('31', 32));

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
        (string) $connection->query("SELECT meta_value FROM sd2_schema_meta WHERE meta_key = 'schema_version'")->fetchColumn() === '1',
        'The result database schema version was not initialized.'
    );
    $statement = $connection->prepare(
        'SELECT canonical_json, content_sha256, auth_hmac_sha256
        FROM sd2_result_records
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
    storage_test_assert(
        hash_equals(
            result_authentication_hmac((string) $record['canonical_json']),
            (string) $record['auth_hmac_sha256']
        ),
        'The stored result authentication code is invalid.'
    );

    // Shared DreamHost MySQL cannot create triggers. A direct database edit
    // must still be detected by the application authentication check.
    $connection->exec("UPDATE sd2_result_records SET canonical_json = '{}'");
    $tamperingDetected = false;
    try {
        load_result_record($stored['result_id']);
    } catch (ResultIntegrityException $e) {
        $tamperingDetected = true;
    }
    storage_test_assert($tamperingDetected, 'A direct database edit passed verification.');

    $second = store_result_record($payload);
    storage_test_assert(
        $second['result_id'] !== $stored['result_id'],
        'Two stored results received the same public ID.'
    );

    echo "Storage tests passed.\n";
} finally {
    // The dedicated test database is reset before the next test.
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/mysql-test-bootstrap.php';
securedice_test_reset();

putenv('SECUREDICE_SECRET=' . str_repeat('32', 32));

require_once dirname(__DIR__) . '/storage.php';

function verification_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function insert_verification_test_record(array $result, string $digest, ?string $hmac = null): void
{
    $canonicalJson = encode_canonical_result($result);
    $statement = result_storage_connection()->prepare(
        'INSERT INTO sd2_result_records (
            public_id,
            schema_version,
            generated_at,
            canonical_json,
            content_sha256,
            auth_hmac_sha256,
            stored_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
        $result['result_id'],
        $result['schema_version'],
        $result['generated_at'],
        $canonicalJson,
        $digest,
        $hmac ?? result_authentication_hmac($canonicalJson),
        '2026-09-21T12:00:01+00:00',
    ]);
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
                'die_kind' => 'normal',
                'sides' => 6,
                'die_label' => 'd6',
                'modifier' => 0,
                'mode' => 'sum',
            ],
            'B' => null,
        ],
    ],
    'summary' => ['min' => 4, 'max' => 4, 'avg' => 4.0],
    'sets' => [
        [
            'number' => 1,
            'terms' => [
                [
                    'pool' => 'A',
                    'operator' => 1,
                    'result' => [
                        'dice' => [
                            ['index' => 0, 'value' => 4, 'kept' => true, 'role' => 'normal'],
                        ],
                        'dice_total' => 4,
                        'modifier' => 0,
                        'total' => 4,
                        'mode' => 'sum',
                        'die_kind' => 'normal',
                        'special' => null,
                    ],
                ],
            ],
            'total' => 4,
        ],
    ],
];

try {
    $stored = store_result_record($payload);
    $loaded = load_result_record($stored['result_id']);

    verification_test_assert(is_array($loaded), 'A stored result was not found.');
    verification_test_assert(
        $loaded['canonical_json'] === encode_canonical_result($stored),
        'The authenticated result did not match the stored result.'
    );
    verification_test_assert(
        load_result_record(str_repeat('f', 32)) === null,
        'An unknown result ID did not return null.'
    );

    $invalidIdWasRejected = false;

    try {
        load_result_record('../not-a-result');
    } catch (InvalidResultIdException $e) {
        $invalidIdWasRejected = true;
    }

    verification_test_assert($invalidIdWasRejected, 'A malformed result ID was not rejected.');

    $corrupt = $stored;
    $corrupt['result_id'] = str_repeat('b', 32);
    insert_verification_test_record($corrupt, str_repeat('0', 64));

    $corruptionWasDetected = false;

    try {
        load_result_record($corrupt['result_id']);
    } catch (ResultIntegrityException $e) {
        $corruptionWasDetected = true;
    }

    verification_test_assert($corruptionWasDetected, 'A corrupt result passed verification.');

    $forged = $stored;
    $forged['result_id'] = str_repeat('a', 32);
    $forged['sets'][0]['total'] = 6;
    $forgedJson = encode_canonical_result($forged);
    insert_verification_test_record(
        $forged,
        hash('sha256', $forgedJson),
        str_repeat('0', 64)
    );

    $forgeryWasDetected = false;

    try {
        load_result_record($forged['result_id']);
    } catch (ResultIntegrityException $e) {
        $forgeryWasDetected = true;
    }

    verification_test_assert(
        $forgeryWasDetected,
        'A forged result with a recomputed public digest passed authentication.'
    );

    $unsupported = $stored;
    $unsupported['result_id'] = str_repeat('c', 32);
    $unsupported['schema_version'] = 99;
    $unsupportedJson = encode_canonical_result($unsupported);
    insert_verification_test_record($unsupported, hash('sha256', $unsupportedJson));

    $unsupportedWasRejected = false;

    try {
        load_result_record($unsupported['result_id']);
    } catch (UnsupportedResultSchemaException $e) {
        $unsupportedWasRejected = true;
    }

    verification_test_assert($unsupportedWasRejected, 'An unsupported schema was accepted.');

    $malformed = $stored;
    $malformed['result_id'] = str_repeat('d', 32);
    unset($malformed['sets']);
    $malformedJson = encode_canonical_result($malformed);
    insert_verification_test_record($malformed, hash('sha256', $malformedJson));

    $malformedWasRejected = false;

    try {
        load_result_record($malformed['result_id']);
    } catch (ResultIntegrityException $e) {
        $malformedWasRejected = true;
    }

    verification_test_assert($malformedWasRejected, 'A malformed result structure was accepted.');

    echo "Verification tests passed.\n";
} finally {
    // The dedicated test database is reset before the next test.
}

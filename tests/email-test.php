<?php

declare(strict_types=1);

$databasePath = sys_get_temp_dir()
    . '/securedice-email-test-'
    . bin2hex(random_bytes(8))
    . '.sqlite';

putenv('SECUREDICE_DB_PATH=' . $databasePath);
putenv('SECUREDICE_SECRET=' . str_repeat('51', 32));
putenv('SECUREDICE_BASE_URL=https://dice.example.test');

require_once dirname(__DIR__) . '/smtp.php';

function email_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function email_test_result_payload(int $total): array
{
    return [
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
        'summary' => ['min' => $total, 'max' => $total, 'avg' => (float) $total],
        'sets' => [[
            'number' => 1,
            'terms' => [[
                'pool' => 'A',
                'operator' => 1,
                'result' => [
                    'dice' => [[
                        'index' => 0,
                        'value' => $total,
                        'kept' => true,
                        'role' => 'normal',
                    ]],
                    'dice_total' => $total,
                    'modifier' => 0,
                    'total' => $total,
                    'mode' => 'sum',
                    'die_kind' => 'normal',
                    'special' => null,
                ],
            ]],
            'total' => $total,
        ]],
    ];
}

try {
    putenv('SECUREDICE_SMTP_HOST=smtp.example.test');
    putenv('SECUREDICE_SMTP_PORT=587');
    putenv('SECUREDICE_SMTP_ENCRYPTION=starttls');
    putenv('SECUREDICE_SMTP_USERNAME=test-user');
    putenv('SECUREDICE_SMTP_PASSWORD=test-password');
    putenv('SECUREDICE_SMTP_FROM_ADDRESS=dice@example.test');
    putenv('SECUREDICE_SMTP_FROM_NAME=Secure Dice Test');
    $smtp = smtp_configuration();
    email_test_assert($smtp['port'] === 587, 'The SMTP port was not parsed.');
    email_test_assert($smtp['encryption'] === 'starttls', 'The SMTP encryption mode is wrong.');

    $connection = result_storage_connection();
    $result = store_result_record(email_test_result_payload(4));
    $addresses = [];
    $now = consent_timestamp();

    for ($i = 1; $i <= 8; $i++) {
        $email = "player{$i}@example.com";
        $addresses[] = $email;
        $insert = $connection->prepare(
            "INSERT INTO recipients
            (email_fingerprint, email_ciphertext, status, delivery_mode,
                created_at, updated_at, confirmed_at)
            VALUES (:fingerprint, :ciphertext, 'active', 'tabletop', :now, :now, :now)"
        );
        $insert->execute([
            ':fingerprint' => consent_fingerprint('recipient-email', $email),
            ':ciphertext' => consent_encrypt_string($email),
            ':now' => $now,
        ]);
    }

    $submitted = implode("\n", array_merge(
        $addresses,
        ['not-opted-1@example.com', 'not-opted-2@example.com']
    ));
    $accepted = request_result_email($result['result_id'], $submitted, '192.0.2.20');
    email_test_assert($accepted['accepted'] === true, 'The email request was not accepted.');

    $request = $connection->query('SELECT * FROM email_requests ORDER BY id DESC LIMIT 1')->fetch();
    email_test_assert((int) $request['intended_count'] === 10, 'The intended recipient count is wrong.');
    email_test_assert((int) $request['opted_in_count'] === 8, 'The opted-in recipient count is wrong.');
    email_test_assert((int) $request['not_opted_in_count'] === 2, 'The non-opted-in count is wrong.');
    email_test_assert(
        (int) $connection->query("SELECT COUNT(*) FROM outbound_messages WHERE message_type = 'result_delivery'")->fetchColumn() === 8,
        'The result was not queued for every opted-in recipient.'
    );

    $tooMany = implode(',', array_map(
        static fn (int $number): string => "person{$number}@example.com",
        range(1, 11)
    ));
    $tooManyRejected = false;

    try {
        request_result_email($result['result_id'], $tooMany, '192.0.2.21');
    } catch (InvalidArgumentException $e) {
        $tooManyRejected = true;
    }
    email_test_assert($tooManyRejected, 'A submission with 11 recipients was accepted.');

    $connection->exec(
        "UPDATE outbound_messages SET available_at = '2026-09-21T00:00:00Z'
        WHERE message_type = 'result_delivery'"
    );
    $delivered = [];
    $workerResult = process_email_queue(
        static function (array $message) use (&$delivered): string {
            $delivered[] = $message;
            return '<fake-provider-id>';
        },
        50
    );
    email_test_assert($workerResult['sent'] === 8, 'The worker did not deliver all queued results.');
    email_test_assert(count($delivered) === 8, 'Unexpected SMTP message count.');

    foreach ($delivered as $message) {
        email_test_assert(
            str_contains($message['text'], '8 were opted in. 2 were not opted in'),
            'The aggregate opt-in count was omitted from a result email.'
        );
    }

    email_test_assert(
        (int) $connection->query("SELECT COUNT(*) FROM email_delivery_history WHERE status = 'sent'")->fetchColumn() === 8,
        'Successful delivery history was not recorded.'
    );
    email_test_assert(
        (int) $connection->query(
            "SELECT COUNT(*) FROM outbound_messages
            WHERE status = 'sent' AND payload_ciphertext IS NULL"
        )->fetchColumn() === 8,
        'Sensitive queue payloads were not purged after delivery.'
    );

    request_result_email($result['result_id'], $addresses[0], '192.0.2.20');
    email_test_assert(
        (int) $connection->query("SELECT COUNT(*) FROM outbound_messages WHERE message_type = 'result_delivery'")->fetchColumn() === 8,
        'The same result was queued twice inside the duplicate-suppression window.'
    );

    $resultTwo = store_result_record(email_test_result_payload(5));
    $resultThree = store_result_record(email_test_result_payload(6));
    request_result_email($resultTwo['result_id'], $addresses[0], '192.0.2.20');
    request_result_email($resultThree['result_id'], $addresses[0], '192.0.2.20');
    $connection->exec(
        "UPDATE outbound_messages SET available_at = '2026-09-21T00:00:00Z'
        WHERE message_type = 'result_delivery' AND status = 'pending'"
    );
    $batched = [];
    $batchResult = process_email_queue(
        static function (array $message) use (&$batched): string {
            $batched[] = $message;
            return '<batch-provider-id>';
        },
        20
    );
    email_test_assert($batchResult['sent'] === 2, 'The batched results were not delivered.');
    email_test_assert(count($batched) === 1, 'Nearby results were not combined into one email.');
    email_test_assert($batched[0]['subject'] === '2 Secure Dice results', 'The batch subject is wrong.');

    $recipientId = (int) $connection->query('SELECT id FROM recipients ORDER BY id LIMIT 1')->fetchColumn();
    queue_consent_message($connection, $recipientId, 'consent_confirmation', [
        'email' => $addresses[0],
        'confirmation_token' => consent_base64url_encode(str_repeat('x', 32)),
        'expires_at' => consent_timestamp(time() + 3600),
    ], consent_timestamp());
    $retryResult = process_email_queue(
        static function (): string {
            throw new EmailTransportException('temporary failure', true);
        },
        1
    );
    email_test_assert($retryResult['failed'] === 1, 'A temporary failure was not recorded.');
    $retry = $connection->query(
        "SELECT status, attempts, payload_ciphertext FROM outbound_messages
        WHERE message_type = 'consent_confirmation' ORDER BY id DESC LIMIT 1"
    )->fetch();
    email_test_assert($retry['status'] === 'pending', 'A temporary failure was not requeued.');
    email_test_assert((int) $retry['attempts'] === 1, 'The retry attempt count is wrong.');
    email_test_assert($retry['payload_ciphertext'] !== null, 'A retryable payload was purged.');

    $connection->exec(
        "UPDATE outbound_messages SET available_at = '2026-09-21T00:00:00Z'
        WHERE message_type = 'consent_confirmation' AND status = 'pending'"
    );
    process_email_queue(
        static function (): string {
            throw new EmailTransportException('permanent failure', false);
        },
        1
    );
    $failed = $connection->query(
        "SELECT status, payload_ciphertext FROM outbound_messages
        WHERE message_type = 'consent_confirmation' ORDER BY id DESC LIMIT 1"
    )->fetch();
    email_test_assert($failed['status'] === 'failed', 'A permanent failure was not finalized.');
    email_test_assert($failed['payload_ciphertext'] === null, 'A permanent-failure payload was retained.');

    email_test_assert(email_retry_delay(1, 0) === 60, 'The first retry delay is wrong.');
    email_test_assert(email_retry_delay(5, 0) === 21600, 'The final retry delay is wrong.');

    echo "Email tests passed.\n";
} finally {
    foreach ([$databasePath, $databasePath . '-shm', $databasePath . '-wal'] as $path) {
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}

<?php

declare(strict_types=1);

$databasePath = sys_get_temp_dir()
    . '/securedice-consent-test-'
    . bin2hex(random_bytes(8))
    . '.sqlite';

putenv('SECUREDICE_DB_PATH=' . $databasePath);
putenv('SECUREDICE_SECRET=' . str_repeat('42', 32));

require_once dirname(__DIR__) . '/consent.php';

function consent_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function consent_test_expect_exception(callable $operation, string $className, string $message): void
{
    try {
        $operation();
    } catch (Throwable $e) {
        consent_test_assert($e instanceof $className, $message . ' Wrong exception: ' . get_class($e));
        return;
    }

    throw new RuntimeException($message . ' No exception was thrown.');
}

try {
    $requested = request_recipient_consent(' Player.Example@example.com ', '192.0.2.10');
    consent_test_assert($requested['queued'] === true, 'The first opt-in did not queue confirmation.');

    $connection = result_storage_connection();
    consent_test_assert(
        (int) $connection->query('PRAGMA user_version')->fetchColumn() === 2,
        'The consent schema was not initialized.'
    );

    $recipient = $connection->query(
        'SELECT id, email_ciphertext, status FROM recipients LIMIT 1'
    )->fetch();
    consent_test_assert(is_array($recipient), 'The pending recipient was not stored.');
    consent_test_assert($recipient['status'] === 'pending', 'The recipient was activated before confirmation.');
    consent_test_assert(
        strpos((string) $recipient['email_ciphertext'], 'player.example@example.com') === false,
        'The email address was stored in plaintext.'
    );

    $queued = $connection->query(
        "SELECT payload_ciphertext FROM outbound_messages WHERE status = 'pending' LIMIT 1"
    )->fetch();
    consent_test_assert(is_array($queued), 'The confirmation message was not queued.');
    consent_test_assert(
        strpos((string) $queued['payload_ciphertext'], 'player.example@example.com') === false,
        'The queued address was stored in plaintext.'
    );

    $payload = consent_decrypt_payload((string) $queued['payload_ciphertext']);
    consent_test_assert(
        $payload['email'] === 'player.example@example.com',
        'The encrypted queue payload did not preserve the normalized address.'
    );
    $confirmationToken = (string) $payload['confirmation_token'];
    consent_test_assert(
        preg_match('/^[A-Za-z0-9_-]{43}$/', $confirmationToken) === 1,
        'The confirmation token does not contain 256 bits in base64url form.'
    );

    $confirmed = confirm_recipient_consent($confirmationToken, '192.0.2.10');
    consent_test_assert(
        preg_match('/^[a-f0-9]{5}(?:-[a-f0-9]{5}){3}$/', $confirmed['recipient_code']) === 1,
        'The recipient code format is invalid.'
    );
    consent_test_assert(
        preg_match('/^[A-Za-z0-9_-]{43}$/', $confirmed['management_token']) === 1,
        'The management token format is invalid.'
    );
    consent_test_assert(
        $confirmed['masked_email'] === 'p********@example.com',
        'The confirmed address was not safely masked.'
    );

    $connection->exec('PRAGMA wal_checkpoint(FULL)');
    $rawDatabase = file_get_contents($databasePath);
    consent_test_assert(is_string($rawDatabase), 'The test database could not be inspected.');
    consent_test_assert(
        strpos($rawDatabase, $confirmationToken) === false,
        'A raw confirmation token was retained in the database.'
    );
    consent_test_assert(
        strpos($rawDatabase, $confirmed['recipient_code']) === false,
        'A raw recipient code was retained in the database.'
    );
    consent_test_assert(
        strpos($rawDatabase, str_replace('-', '', $confirmed['recipient_code'])) === false,
        'A normalized raw recipient code was retained in the database.'
    );
    consent_test_assert(
        strpos($rawDatabase, $confirmed['management_token']) === false,
        'A raw management token was retained in the database.'
    );

    consent_test_expect_exception(
        static fn () => confirm_recipient_consent($confirmationToken, '192.0.2.10'),
        InvalidConsentTokenException::class,
        'A one-time confirmation token was accepted twice.'
    );

    $resolved = resolve_recipient_code($confirmed['recipient_code']);
    consent_test_assert(is_array($resolved), 'The active recipient code did not resolve.');
    consent_test_assert(
        $resolved['email'] === 'player.example@example.com',
        'The active recipient code resolved to the wrong address.'
    );
    consent_test_assert(resolve_recipient_code('not-a-code') === null, 'A malformed code resolved.');

    $management = get_recipient_management($confirmed['management_token'], '192.0.2.10');
    consent_test_assert(
        $management['masked_email'] === 'p********@example.com',
        'The management capability returned the wrong recipient.'
    );

    $oldCode = $confirmed['recipient_code'];
    $newCode = rotate_recipient_code($confirmed['management_token']);
    consent_test_assert($newCode !== $oldCode, 'Code rotation did not create a new code.');
    consent_test_assert(resolve_recipient_code($oldCode) === null, 'The prior code survived rotation.');
    consent_test_assert(is_array(resolve_recipient_code($newCode)), 'The replacement code is inactive.');

    $messageCountBefore = (int) $connection->query('SELECT COUNT(*) FROM outbound_messages')->fetchColumn();
    $duplicate = request_recipient_consent('player.example@example.com', '192.0.2.10');
    $messageCountAfter = (int) $connection->query('SELECT COUNT(*) FROM outbound_messages')->fetchColumn();
    consent_test_assert($duplicate['accepted'] === true, 'An active enrollment was not accepted generically.');
    consent_test_assert($duplicate['queued'] === false, 'An active address queued a duplicate confirmation.');
    consent_test_assert(
        $messageCountBefore === $messageCountAfter,
        'An active enrollment leaked state by creating another queued message.'
    );

    revoke_recipient_consent($confirmed['management_token']);
    consent_test_assert(resolve_recipient_code($newCode) === null, 'A recipient code survived revocation.');
    consent_test_expect_exception(
        static fn () => find_recipient_management($confirmed['management_token']),
        InvalidConsentTokenException::class,
        'A management token survived revocation.'
    );

    consent_test_assert(
        consume_consent_rate_limit('test-bucket', 'subject', 2, 60, 1000),
        'The first rate-limit attempt was blocked.'
    );
    consent_test_assert(
        consume_consent_rate_limit('test-bucket', 'subject', 2, 60, 1001),
        'The second rate-limit attempt was blocked.'
    );
    consent_test_assert(
        !consume_consent_rate_limit('test-bucket', 'subject', 2, 60, 1002),
        'The rate limit did not block excess attempts.'
    );
    consent_test_assert(
        consume_consent_rate_limit('test-bucket', 'subject', 2, 60, 1061),
        'The rate-limit window did not reset.'
    );

    consent_test_expect_exception(
        static fn () => validate_long_consent_token('short'),
        InvalidConsentTokenException::class,
        'A malformed long token was accepted.'
    );

    echo "Consent tests passed.\n";
} finally {
    foreach ([$databasePath, $databasePath . '-shm', $databasePath . '-wal'] as $path) {
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}

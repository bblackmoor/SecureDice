<?php

declare(strict_types=1);

require_once __DIR__ . '/mysql-test-bootstrap.php';
securedice_test_reset();

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
        (string) $connection->query("SELECT meta_value FROM sd2_schema_meta WHERE meta_key = 'schema_version'")->fetchColumn() === '1',
        'The consent schema was not initialized.'
    );

    $recipient = $connection->query(
        'SELECT id, email_ciphertext, status FROM sd2_recipients LIMIT 1'
    )->fetch();
    consent_test_assert(is_array($recipient), 'The pending recipient was not stored.');
    consent_test_assert($recipient['status'] === 'pending', 'The recipient was activated before confirmation.');
    consent_test_assert(
        strpos((string) $recipient['email_ciphertext'], 'player.example@example.com') === false,
        'The email address was stored in plaintext.'
    );

    $queued = $connection->query(
        "SELECT payload_ciphertext FROM sd2_outbound_messages WHERE status = 'pending' LIMIT 1"
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

    $tamperedConfirmation = substr($confirmationToken, 0, -1)
        . ($confirmationToken[-1] === 'A' ? 'B' : 'A');
    consent_test_expect_exception(
        static fn () => confirm_recipient_consent($tamperedConfirmation, '192.0.2.10'),
        InvalidConsentTokenException::class,
        'A tampered confirmation token was accepted.'
    );

    $connection->exec(
        "UPDATE sd2_consent_challenges SET expires_at = '2000-01-01T00:00:00Z'
        WHERE purpose = 'enroll' AND consumed_at IS NULL"
    );
    consent_test_expect_exception(
        static fn () => confirm_recipient_consent($confirmationToken, '192.0.2.10'),
        InvalidConsentTokenException::class,
        'An expired confirmation token was accepted.'
    );
    $connection->exec(
        "UPDATE sd2_consent_challenges SET expires_at = '2999-01-01T00:00:00Z'
        WHERE purpose = 'enroll' AND consumed_at IS NULL"
    );

    $confirmed = confirm_recipient_consent($confirmationToken, '192.0.2.10');
    consent_test_assert(
        preg_match('/^[A-Za-z0-9_-]{43}$/', $confirmed['management_token']) === 1,
        'The management token format is invalid.'
    );
    consent_test_assert(
        $confirmed['masked_email'] === 'p********@example.com',
        'The confirmed address was not safely masked.'
    );
    consent_test_assert(
        (int) $connection->query(
            "SELECT COUNT(*) FROM sd2_outbound_messages WHERE message_type = 'consent_credentials'"
        )->fetchColumn() === 1,
        'Confirmation did not queue a durable credentials message.'
    );

    $storedTokens = implode(' ', $connection->query(
        'SELECT token_hash FROM sd2_consent_challenges'
    )->fetchAll(PDO::FETCH_COLUMN));
    $storedPayloads = implode(' ', $connection->query(
        'SELECT payload_ciphertext FROM sd2_outbound_messages'
    )->fetchAll(PDO::FETCH_COLUMN));
    consent_test_assert(
        !str_contains($storedTokens . $storedPayloads, $confirmationToken),
        'A raw confirmation token was retained in the database.'
    );
    consent_test_assert(
        !str_contains($storedTokens . $storedPayloads, $confirmed['management_token']),
        'A raw management token was retained in the database.'
    );

    consent_test_expect_exception(
        static fn () => confirm_recipient_consent($confirmationToken, '192.0.2.10'),
        InvalidConsentTokenException::class,
        'A one-time confirmation token was accepted twice.'
    );

    $management = get_recipient_management($confirmed['management_token'], '192.0.2.10');
    consent_test_assert(
        $management['masked_email'] === 'p********@example.com',
        'The management capability returned the wrong recipient.'
    );

    set_recipient_delivery_mode($confirmed['management_token'], 'occasional');
    consent_test_assert(
        find_recipient_management($confirmed['management_token'])['delivery_mode'] === 'occasional',
        'The recipient email-frequency setting was not saved.'
    );

    $duplicate = request_recipient_consent('player.example@example.com', '192.0.2.10');
    consent_test_assert(
        $duplicate === $requested,
        'An active address produced a distinguishable enrollment response.'
    );
    consent_test_assert($duplicate['accepted'] === true, 'An active enrollment was not accepted generically.');
    consent_test_assert($duplicate['queued'] === true, 'An active address did not queue management recovery.');

    $recoveryMessage = $connection->query(
        "SELECT payload_ciphertext FROM sd2_outbound_messages
        WHERE message_type = 'management_recovery' AND status = 'pending'
        ORDER BY id DESC LIMIT 1"
    )->fetch();
    consent_test_assert(is_array($recoveryMessage), 'The management recovery message was not queued.');
    $recoveryPayload = consent_decrypt_payload((string) $recoveryMessage['payload_ciphertext']);
    $recoveryToken = (string) $recoveryPayload['recovery_token'];

    consent_test_expect_exception(
        static fn () => confirm_recipient_consent($recoveryToken, '192.0.2.10'),
        InvalidConsentTokenException::class,
        'A recovery token was accepted as an opt-in token.'
    );

    $recovered = recover_recipient_management($recoveryToken, '192.0.2.10');
    consent_test_assert(
        $recovered['management_token'] !== $confirmed['management_token'],
        'Recovery did not create a replacement management token.'
    );
    consent_test_assert(
        is_array(find_recipient_management($recovered['management_token'])),
        'The recovered management link is inactive.'
    );
    consent_test_expect_exception(
        static fn () => find_recipient_management($confirmed['management_token']),
        InvalidConsentTokenException::class,
        'The prior management link survived recovery.'
    );
    consent_test_expect_exception(
        static fn () => recover_recipient_management($recoveryToken, '192.0.2.10'),
        InvalidConsentTokenException::class,
        'A management recovery token was accepted twice.'
    );

    $credentialsMessage = $connection->query(
        "SELECT payload_ciphertext FROM sd2_outbound_messages
        WHERE message_type = 'consent_credentials' AND status = 'pending'
        ORDER BY id DESC LIMIT 1"
    )->fetch();
    consent_test_assert(is_array($credentialsMessage), 'Recovered credentials were not queued.');
    $credentialsPayload = consent_decrypt_payload((string) $credentialsMessage['payload_ciphertext']);
    $unsubscribeToken = (string) $credentialsPayload['unsubscribe_token'];
    $unsubscribe = get_recipient_unsubscribe($unsubscribeToken, '192.0.2.10');
    consent_test_assert(
        $unsubscribe['masked_email'] === 'p********@example.com',
        'The unsubscribe capability returned the wrong recipient.'
    );

    revoke_recipient_with_unsubscribe_token($unsubscribeToken);
    consent_test_assert(
        (int) $connection->query("SELECT COUNT(*) FROM sd2_outbound_messages WHERE status = 'pending'")->fetchColumn() === 0,
        'Pending messages survived recipient revocation.'
    );
    consent_test_expect_exception(
        static fn () => find_recipient_management($recovered['management_token']),
        InvalidConsentTokenException::class,
        'A management token survived revocation.'
    );
    consent_test_expect_exception(
        static fn () => find_recipient_unsubscribe($unsubscribeToken),
        InvalidConsentTokenException::class,
        'An unsubscribe token was accepted twice.'
    );

    $optedInAgain = request_recipient_consent('player.example@example.com', '192.0.2.11');
    consent_test_assert($optedInAgain['queued'] === true, 'A revoked recipient could not opt in again.');
    $newConfirmation = $connection->query(
        "SELECT payload_ciphertext FROM sd2_outbound_messages
        WHERE message_type = 'consent_confirmation' AND status = 'pending'
        ORDER BY id DESC LIMIT 1"
    )->fetch();
    $newConfirmationPayload = consent_decrypt_payload((string) $newConfirmation['payload_ciphertext']);
    $confirmedAgain = confirm_recipient_consent(
        (string) $newConfirmationPayload['confirmation_token'],
        '192.0.2.11'
    );
    revoke_recipient_consent($confirmedAgain['management_token']);
    consent_test_assert(
        $connection->query("SELECT status FROM sd2_recipients LIMIT 1")->fetchColumn() === 'revoked',
        'Management-link revocation did not revoke the recipient.'
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
    $rateBucket = (string) $connection->query(
        "SELECT bucket_key FROM sd2_rate_limits
        WHERE attempts = 1 ORDER BY window_started_at DESC LIMIT 1"
    )->fetchColumn();
    consent_test_assert(
        $rateBucket !== '' && !str_contains($rateBucket, 'subject'),
        'A raw rate-limit identifier was retained.'
    );

    $_SESSION = [];
    $csrfToken = consent_csrf_token();
    validate_consent_csrf($csrfToken);
    consent_test_expect_exception(
        static fn () => validate_consent_csrf(''),
        InvalidArgumentException::class,
        'A missing CSRF token was accepted.'
    );
    consent_test_expect_exception(
        static fn () => validate_consent_csrf(str_repeat('A', 43)),
        InvalidArgumentException::class,
        'A forged CSRF token was accepted.'
    );

    consent_test_expect_exception(
        static fn () => validate_long_consent_token('short'),
        InvalidConsentTokenException::class,
        'A malformed long token was accepted.'
    );

    echo "Consent tests passed.\n";
} finally {
    // The dedicated test database is reset before the next test.
}

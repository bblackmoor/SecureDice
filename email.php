<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/consent.php';
require_once __DIR__ . '/storage.php';

class EmailConfigurationException extends RuntimeException
{
}

class EmailTransportException extends RuntimeException
{
    public bool $transient;

    public function __construct(string $message, bool $transient = true)
    {
        parent::__construct($message);
        $this->transient = $transient;
    }
}

function email_public_base_url(): string
{
    $value = rtrim(trim((string) getenv('SECUREDICE_BASE_URL')), '/');

    if (filter_var($value, FILTER_VALIDATE_URL) === false) {
        throw new EmailConfigurationException('SECUREDICE_BASE_URL must be an absolute URL.');
    }

    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
    $host = strtolower((string) parse_url($value, PHP_URL_HOST));

    if ($scheme !== 'https' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        throw new EmailConfigurationException('SECUREDICE_BASE_URL must use HTTPS.');
    }

    return $value;
}

function email_url(string $path): string
{
    return email_public_base_url() . '/' . ltrim($path, '/');
}

/** Parse up to ten unique addresses while treating malformed entries as ineligible. */
function parse_email_recipients(string $submitted): array
{
    if (strlen($submitted) > 3000) {
        throw new InvalidArgumentException('Enter no more than 10 email addresses.');
    }

    $parts = preg_split('/[\s,;]+/', trim($submitted), -1, PREG_SPLIT_NO_EMPTY);
    $parts = is_array($parts) ? $parts : [];
    $unique = [];

    foreach ($parts as $part) {
        $candidate = strtolower(trim((string) $part));

        try {
            $email = normalize_recipient_email($candidate);
            $key = 'email:' . $email;
        } catch (InvalidArgumentException $e) {
            $email = null;
            $key = 'invalid:' . hash('sha256', $candidate);
        }

        $unique[$key] = $email;
    }

    if ($unique === []) {
        throw new InvalidArgumentException('Enter at least one recipient email address.');
    }

    if (count($unique) > 10) {
        throw new InvalidArgumentException('Enter no more than 10 recipient email addresses.');
    }

    return array_values($unique);
}

function recipient_delivery_limits(string $mode): array
{
    if ($mode === 'occasional') {
        return [
            ['recipient-10-minute', 10, 600],
            ['recipient-hour', 20, 3600],
            ['recipient-day', 100, 86400],
        ];
    }

    return [
        ['recipient-10-minute', 20, 600],
        ['recipient-hour', 150, 3600],
        ['recipient-day', 1000, 86400],
    ];
}

function recipient_delivery_available(int $recipientId, string $mode): bool
{
    if ($mode === 'paused') {
        return false;
    }

    $available = true;

    foreach (recipient_delivery_limits($mode) as [$context, $maximum, $seconds]) {
        if (!consume_consent_rate_limit($context, (string) $recipientId, $maximum, $seconds)) {
            $available = false;
        }
    }

    return $available;
}

function insert_result_delivery_message(
    PDO $connection,
    int $recipientId,
    int $requestId,
    string $resultId,
    string $email,
    string $now
): void {
    $availableAt = consent_timestamp(strtotime($now) + 15);
    $statement = $connection->prepare(
        "INSERT INTO sd2_outbound_messages
        (message_type, recipient_id, request_id, result_id, payload_ciphertext,
            status, available_at, created_at)
        VALUES ('result_delivery', :recipient_id, :request_id, :result_id, :payload,
            'pending', :available_at, :created_at)"
    );
    $statement->execute([
        ':recipient_id' => $recipientId,
        ':request_id' => $requestId,
        ':result_id' => $resultId,
        ':payload' => consent_encrypt_payload([
            'email' => $email,
            'unsubscribe_token' => insert_unsubscribe_token($connection, $recipientId, $now),
        ]),
        ':available_at' => $availableAt,
        ':created_at' => $now,
    ]);
    $messageId = (int) $connection->lastInsertId();
    $history = $connection->prepare(
        "INSERT INTO sd2_email_delivery_history
        (message_id, request_id, recipient_id, result_id, message_type, status, queued_at)
        VALUES (:message_id, :request_id, :recipient_id, :result_id,
            'result_delivery', 'queued', :queued_at)"
    );
    $history->execute([
        ':message_id' => $messageId,
        ':request_id' => $requestId,
        ':recipient_id' => $recipientId,
        ':result_id' => $resultId,
        ':queued_at' => $now,
    ]);
}

function find_active_email_recipients(PDO $connection, array $recipients): array
{
    $eligible = [];
    $activeCount = 0;
    $lookup = $connection->prepare(
        "SELECT id, email_ciphertext, delivery_mode
        FROM sd2_recipients WHERE email_fingerprint = :fingerprint AND status = 'active'"
    );

    foreach ($recipients as $email) {
        if (!is_string($email)) {
            continue;
        }

        $lookup->execute([
            ':fingerprint' => consent_fingerprint('recipient-email', $email),
        ]);
        $recipient = $lookup->fetch();

        if (is_array($recipient)) {
            $activeCount++;
            $eligible[] = $recipient;
        }
    }

    return ['recipients' => $eligible, 'active_count' => $activeCount];
}

function claim_result_delivery_recipient(
    PDOStatement $claim,
    PDOStatement $releaseClaim,
    array $recipient,
    string $resultId,
    string $now
): ?array {
    $recipientId = (int) $recipient['id'];
    $claim->execute([
        ':recipient_id' => $recipientId,
        ':result_id' => $resultId,
        ':created_at' => $now,
    ]);

    if ($claim->rowCount() !== 1) {
        return null;
    }

    if (!recipient_delivery_available($recipientId, (string) $recipient['delivery_mode'])) {
        $releaseClaim->execute([
            ':recipient_id' => $recipientId,
            ':result_id' => $resultId,
        ]);
        return null;
    }

    return [
        'recipient_id' => $recipientId,
        'email' => consent_decrypt_string((string) $recipient['email_ciphertext']),
    ];
}

function build_result_delivery_queue(
    PDO $connection,
    array $eligible,
    string $resultId,
    string $now
): array {
    $queue = [];
    $claim = $connection->prepare(
        'INSERT IGNORE INTO sd2_result_delivery_claims
        (recipient_id, result_id, created_at)
        VALUES (:recipient_id, :result_id, :created_at)'
    );
    $releaseClaim = $connection->prepare(
        'DELETE FROM sd2_result_delivery_claims
        WHERE recipient_id = :recipient_id AND result_id = :result_id'
    );

    foreach ($eligible as $recipient) {
        $queuedRecipient = claim_result_delivery_recipient(
            $claim,
            $releaseClaim,
            $recipient,
            $resultId,
            $now
        );

        if ($queuedRecipient !== null) {
            $queue[] = $queuedRecipient;
        }
    }

    return $queue;
}

function insert_email_request(
    PDO $connection,
    string $publicId,
    string $resultId,
    string $sourceIp,
    int $intendedCount,
    int $activeCount,
    int $notOptedInCount,
    int $queuedCount,
    string $now
): int {
    $request = $connection->prepare(
        'INSERT INTO sd2_email_requests
        (public_id, result_id, source_bucket, intended_count, opted_in_count,
            not_opted_in_count, not_queued_count, created_at)
        VALUES (:public_id, :result_id, :source_bucket, :intended_count, :opted_in_count,
            :not_opted_in_count, :not_queued_count, :created_at)'
    );
    $request->execute([
        ':public_id' => $publicId,
        ':result_id' => $resultId,
        ':source_bucket' => consent_fingerprint('email-source', $sourceIp),
        ':intended_count' => $intendedCount,
        ':opted_in_count' => $activeCount,
        ':not_opted_in_count' => $notOptedInCount,
        ':not_queued_count' => $activeCount - $queuedCount,
        ':created_at' => $now,
    ]);

    return (int) $connection->lastInsertId();
}

/** Queue one verified result for every active address without revealing address state. */
function request_result_email(string $resultId, string $submittedRecipients, string $sourceIp): array
{
    $record = load_result_record($resultId);

    if ($record === null) {
        throw new InvalidResultIdException('The stored result was not found.');
    }

    $recipients = parse_email_recipients($submittedRecipients);
    enforce_consent_rate_limit('result-email-ip-hour', $sourceIp, 300, 3600);
    $connection = result_storage_connection();
    $active = find_active_email_recipients($connection, $recipients);
    $eligible = $active['recipients'];
    $activeCount = (int) $active['active_count'];
    $intendedCount = count($recipients);
    $notOptedInCount = $intendedCount - $activeCount;

    if (
        $notOptedInCount > 0
        && !consume_consent_rate_limit('mixed-recipient-ip', $sourceIp, 20, 3600)
    ) {
        $eligible = [];
    }

    $normalizedResultId = strtolower(trim($resultId));
    $now = consent_timestamp();
    $publicId = bin2hex(random_bytes(16));
    $connection->beginTransaction();

    try {
        $queue = build_result_delivery_queue($connection, $eligible, $normalizedResultId, $now);
        $requestId = insert_email_request(
            $connection,
            $publicId,
            $normalizedResultId,
            $sourceIp,
            $intendedCount,
            $activeCount,
            $notOptedInCount,
            count($queue),
            $now
        );

        foreach ($queue as $recipient) {
            insert_result_delivery_message(
                $connection,
                (int) $recipient['recipient_id'],
                $requestId,
                $normalizedResultId,
                (string) $recipient['email'],
                $now
            );
        }

        $connection->commit();
    } catch (Throwable $e) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }

        throw $e;
    }

    return ['accepted' => true, 'request_id' => $publicId];
}

function recover_stale_email_claims(PDO $connection, string $now, string $stale): void
{
    $recover = $connection->prepare(
        "UPDATE sd2_outbound_messages
        SET status = 'pending', lease_token = NULL, claimed_at = NULL,
            available_at = :now, last_error = 'worker_abandoned'
        WHERE status = 'sending' AND claimed_at < :stale"
    );
    $recover->execute([':now' => $now, ':stale' => $stale]);
}

function find_email_batch(PDO $connection, string $now): array
{
    $first = $connection->prepare(
        "SELECT * FROM sd2_outbound_messages
        WHERE status = 'pending' AND available_at <= :now
        ORDER BY available_at, id LIMIT 1"
    );
    $first->execute([':now' => $now]);
    $message = $first->fetch();

    if (!is_array($message)) {
        return [];
    }

    $messages = [$message];

    if ($message['message_type'] !== 'result_delivery') {
        return $messages;
    }

    $batch = $connection->prepare(
        "SELECT * FROM sd2_outbound_messages
        WHERE id <> :id AND recipient_id = :recipient_id
            AND message_type = 'result_delivery' AND status = 'pending'
            AND (available_at <= :now OR (attempts = 0 AND created_at <= :created_before))
        ORDER BY created_at, id LIMIT 19"
    );
    $batch->execute([
        ':id' => (int) $message['id'],
        ':recipient_id' => (int) $message['recipient_id'],
        ':now' => $now,
        ':created_before' => $now,
    ]);

    return array_merge($messages, $batch->fetchAll());
}

function mark_email_batch_claimed(
    PDO $connection,
    array $messages,
    string $now,
    string $leaseToken
): void {
    $ids = array_map(static fn (array $item): int => (int) $item['id'], $messages);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $claim = $connection->prepare(
        "UPDATE sd2_outbound_messages SET status = 'sending', claimed_at = ?, lease_token = ?,
            attempts = attempts + 1 WHERE status = 'pending' AND id IN ({$placeholders})"
    );
    $claim->execute(array_merge([$now, $leaseToken], $ids));

    if ($claim->rowCount() !== count($ids)) {
        throw new RuntimeException('The email queue claim was not atomic.');
    }

    $history = $connection->prepare(
        "UPDATE sd2_email_delivery_history SET status = 'retrying',
            attempt_count = attempt_count + 1, last_attempt_at = :now
        WHERE message_id = :message_id"
    );

    foreach ($ids as $id) {
        $history->execute([':now' => $now, ':message_id' => $id]);
    }
}

function claim_email_batch(?int $nowUnix = null): array
{
    $connection = result_storage_connection();
    $nowUnix = $nowUnix ?? time();
    $now = consent_timestamp($nowUnix);
    $stale = consent_timestamp($nowUnix - 300);
    $leaseToken = bin2hex(random_bytes(16));
    $connection->beginTransaction();

    try {
        recover_stale_email_claims($connection, $now, $stale);
        $messages = find_email_batch($connection, $now);

        if ($messages === []) {
            $connection->commit();
            return [];
        }

        mark_email_batch_claimed($connection, $messages, $now, $leaseToken);
        $connection->commit();
    } catch (Throwable $e) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }

        throw $e;
    }

    foreach ($messages as &$claimed) {
        $claimed['lease_token'] = $leaseToken;
        $claimed['attempts'] = (int) $claimed['attempts'] + 1;
    }
    unset($claimed);

    return $messages;
}

function email_retry_delay(int $attempt, ?int $jitter = null): int
{
    $delays = [60, 300, 1800, 7200, 21600];
    $base = $delays[max(0, min(count($delays) - 1, $attempt - 1))];

    return $base + ($jitter ?? random_int(0, max(1, (int) floor($base / 5))));
}

function finish_email_batch(array $messages, bool $sent, string $category, ?string $providerId = null): void
{
    if ($messages === []) {
        return;
    }

    $connection = result_storage_connection();
    $nowUnix = time();
    $now = consent_timestamp($nowUnix);
    $connection->beginTransaction();

    try {
        foreach ($messages as $message) {
            $attempts = (int) $message['attempts'];
            $permanent = !$sent && ($category !== 'transient' || $attempts >= 5);
            $status = $sent ? 'sent' : ($permanent ? 'failed' : 'pending');
            $available = $sent || $permanent
                ? (string) $message['available_at']
                : consent_timestamp($nowUnix + email_retry_delay($attempts));
            $update = $connection->prepare(
                'UPDATE sd2_outbound_messages
                SET status = :status, available_at = :available_at, claimed_at = NULL,
                    lease_token = NULL, sent_at = :sent_at, last_error = :last_error,
                    payload_ciphertext = :payload
                WHERE id = :id AND lease_token = :lease_token'
            );
            $update->execute([
                ':status' => $status,
                ':available_at' => $available,
                ':sent_at' => $sent ? $now : null,
                ':last_error' => $sent ? null : $category,
                ':payload' => ($sent || $permanent) ? null : $message['payload_ciphertext'],
                ':id' => (int) $message['id'],
                ':lease_token' => (string) $message['lease_token'],
            ]);
            $historyStatus = $sent ? 'sent' : ($permanent ? 'failed' : 'retrying');
            $history = $connection->prepare(
                'UPDATE sd2_email_delivery_history
                SET status = :status, delivered_at = :delivered_at,
                    error_category = :error_category, provider_message_id = :provider_message_id
                WHERE message_id = :message_id'
            );
            $history->execute([
                ':status' => $historyStatus,
                ':delivered_at' => $sent ? $now : null,
                ':error_category' => $sent ? null : $category,
                ':provider_message_id' => $sent ? $providerId : null,
                ':message_id' => (int) $message['id'],
            ]);
        }

        $connection->commit();
    } catch (Throwable $e) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }

        throw $e;
    }
}

function cancel_email_batch(array $messages, string $category): void
{
    if ($messages === []) {
        return;
    }

    $connection = result_storage_connection();
    $connection->beginTransaction();

    try {
        foreach ($messages as $message) {
            $update = $connection->prepare(
                "UPDATE sd2_outbound_messages
                SET status = 'failed', claimed_at = NULL, lease_token = NULL,
                    payload_ciphertext = NULL, last_error = :category
                WHERE id = :id AND lease_token = :lease_token"
            );
            $update->execute([
                ':category' => $category,
                ':id' => (int) $message['id'],
                ':lease_token' => (string) $message['lease_token'],
            ]);
            $history = $connection->prepare(
                "UPDATE sd2_email_delivery_history
                SET status = 'cancelled', error_category = :category
                WHERE message_id = :message_id"
            );
            $history->execute([
                ':category' => $category,
                ':message_id' => (int) $message['id'],
            ]);
        }

        $connection->commit();
    } catch (Throwable $e) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }

        throw $e;
    }
}

function email_result_plain_text(array $data): string
{
    $lines = [];

    foreach ($data['sets'] as $set) {
        $terms = [];

        foreach ($set['terms'] as $termIndex => $term) {
            $dice = [];

            foreach ($term['result']['dice'] as $die) {
                $value = (string) $die['value'];
                $dice[] = $die['kept'] ? $value : '(' . $value . ' dropped)';
            }

            $text = implode(' + ', $dice);
            $modifier = (int) ($term['result']['modifier'] ?? 0);

            if ($modifier !== 0) {
                $text .= $modifier > 0 ? ' + ' . $modifier : ' - ' . abs($modifier);
            }

            if ($termIndex === 0) {
                $terms[] = $text;
            } else {
                $terms[] = ((int) ($term['operator'] ?? 1) < 0 ? '- (' : '+ (') . $text . ')';
            }
        }

        $lines[] = 'Set ' . $set['number'] . ': '
            . implode(' ', $terms) . ' = ' . $set['total'];
    }

    return implode("\n", $lines);
}

function build_consent_email_message(string $type, string $email, array $payload): ?array
{
    if ($type === 'consent_confirmation') {
        $url = email_url('confirm.php?token=' . rawurlencode((string) $payload['confirmation_token']));
        $body = "Confirm that this address may receive Secure Dice results:\n\n{$url}\n\n"
            . "This one-time link expires at {$payload['expires_at']}. If you did not request it, ignore this message.";
        return ['to' => $email, 'subject' => 'Confirm Secure Dice email opt-in', 'text' => $body];
    }

    if ($type === 'management_recovery') {
        $url = email_url('recover-recipient.php?token=' . rawurlencode((string) $payload['recovery_token']));
        $body = "Recover or replace your private Secure Dice settings link:\n\n{$url}\n\n"
            . "This one-time link expires at {$payload['expires_at']}. If you did not request it, ignore this message.";
        return ['to' => $email, 'subject' => 'Recover Secure Dice email settings', 'text' => $body];
    }

    if ($type === 'consent_credentials') {
        $manage = email_url('manage-recipient.php?token=' . rawurlencode((string) $payload['management_token']));
        $unsubscribe = email_url('unsubscribe.php?token=' . rawurlencode((string) $payload['unsubscribe_token']));
        $body = "Your address is opted in to Secure Dice result email.\n\n"
            . "Change settings or pause result email:\n{$manage}\n\nStop all Secure Dice email:\n{$unsubscribe}";
        return ['to' => $email, 'subject' => 'Secure Dice email opt-in confirmed', 'text' => $body];
    }

    return null;
}

function load_email_request_counts(int $requestId): ?array
{
    $request = result_storage_connection()->prepare(
        'SELECT intended_count, opted_in_count, not_opted_in_count, not_queued_count
        FROM sd2_email_requests WHERE id = :id'
    );
    $request->execute([':id' => $requestId]);
    $counts = $request->fetch();

    return is_array($counts) ? $counts : null;
}

function build_result_email_section(array $message): string
{
    $record = load_result_record((string) $message['result_id']);

    if ($record === null) {
        throw new ResultIntegrityException('A queued result no longer exists.');
    }

    $counts = load_email_request_counts((int) $message['request_id']);
    $verify = email_url('verify.php?id=' . rawurlencode((string) $message['result_id']));
    $section = "Secure Dice result {$message['result_id']}\n"
        . email_result_plain_text($record['data']) . "\nVerify: {$verify}";

    if ($counts !== null) {
        $section .= "\n\nDelivery was requested for {$counts['intended_count']} recipient(s). "
            . "{$counts['opted_in_count']} were opted in. "
            . "{$counts['not_opted_in_count']} were not opted in and were not sent this result.";

        if ((int) $counts['not_queued_count'] > 0) {
            $section .= "\n{$counts['not_queued_count']} opted-in recipient(s) were not queued because of delivery settings or limits.";
        }
    }

    return $section;
}

function build_email_message(array $messages): array
{
    $first = $messages[0];
    $payload = consent_decrypt_payload((string) $first['payload_ciphertext']);
    $email = (string) $payload['email'];
    $type = (string) $first['message_type'];
    $consentMessage = build_consent_email_message($type, $email, $payload);

    if ($consentMessage !== null) {
        return $consentMessage;
    }

    $unsubscribeToken = isset($payload['unsubscribe_token'])
        ? validate_long_consent_token((string) $payload['unsubscribe_token'])
        : issue_recipient_unsubscribe_token((int) $first['recipient_id']);
    $unsubscribe = email_url('unsubscribe.php?token=' . rawurlencode($unsubscribeToken));
    $sections = array_map('build_result_email_section', $messages);
    $body = implode("\n\n----------------------------------------\n\n", $sections)
        . "\n\nStop all Secure Dice email:\n{$unsubscribe}";

    return [
        'to' => $email,
        'subject' => count($messages) > 1
            ? count($messages) . ' Secure Dice results'
            : 'Secure Dice result',
        'text' => $body,
    ];
}

function email_recipient_is_available(array $message): bool
{
    $recipient = result_storage_connection()->prepare(
        'SELECT status, delivery_mode FROM sd2_recipients WHERE id = :id'
    );
    $recipient->execute([':id' => (int) $message['recipient_id']]);
    $state = $recipient->fetch();

    return is_array($state)
        && $state['status'] === 'active'
        && ($message['message_type'] !== 'result_delivery' || $state['delivery_mode'] !== 'paused');
}

function process_email_queue(callable $transport, int $limit = 50): array
{
    $processed = 0;
    $sent = 0;
    $failed = 0;

    while ($processed < $limit) {
        $messages = claim_email_batch();

        if ($messages === []) {
            break;
        }

        if (
            !consume_consent_rate_limit('smtp-global-minute', 'global', 60, 60)
            || !consume_consent_rate_limit('smtp-global-hour', 'global', 500, 3600)
        ) {
            finish_email_batch($messages, false, 'transient');
            break;
        }

        $processed += count($messages);

        if (!email_recipient_is_available($messages[0])) {
            cancel_email_batch($messages, 'recipient_unavailable');
            $failed += count($messages);
            continue;
        }

        try {
            $outgoing = build_email_message($messages);
            $providerId = (string) $transport($outgoing);
            finish_email_batch($messages, true, '', $providerId);
            $sent += count($messages);
        } catch (EmailTransportException $e) {
            finish_email_batch($messages, false, $e->transient ? 'transient' : 'permanent');
            $failed += count($messages);
        } catch (Throwable $e) {
            error_log('Secure Dice email worker error: ' . $e->getMessage());
            finish_email_batch($messages, false, 'permanent');
            $failed += count($messages);
        }
    }

    return ['processed' => $processed, 'sent' => $sent, 'failed' => $failed];
}

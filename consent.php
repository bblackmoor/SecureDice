<?php

declare(strict_types=1);

require_once __DIR__ . '/storage.php';

class ConsentConfigurationException extends RuntimeException
{
}

class ConsentRateLimitException extends RuntimeException
{
}

class InvalidConsentTokenException extends RuntimeException
{
}

class RecipientNotActiveException extends RuntimeException
{
}

/** Return the required 256-bit application secret. */
function consent_secret_bytes(): string
{
    static $secret = null;

    if (is_string($secret)) {
        return $secret;
    }

    if (!function_exists('sodium_crypto_secretbox')) {
        error_log('Secure Dice consent storage requires the Sodium extension.');
        throw new ConsentConfigurationException('Recipient consent is temporarily unavailable.');
    }

    $configured = getenv('SECUREDICE_SECRET');
    $configured = is_string($configured) ? trim($configured) : '';

    if (preg_match('/^[a-f0-9]{64}$/i', $configured) !== 1) {
        error_log('SECUREDICE_SECRET must contain exactly 64 hexadecimal characters.');
        throw new ConsentConfigurationException('Recipient consent is temporarily unavailable.');
    }

    $decoded = hex2bin($configured);

    if (!is_string($decoded) || strlen($decoded) !== 32) {
        throw new ConsentConfigurationException('Recipient consent is temporarily unavailable.');
    }

    return $secret = $decoded;
}

function consent_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function consent_base64url_decode(string $value): string
{
    if (preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
        throw new InvalidConsentTokenException('The consent link is invalid or has expired.');
    }

    $padding = (4 - (strlen($value) % 4)) % 4;
    $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);

    if (!is_string($decoded)) {
        throw new InvalidConsentTokenException('The consent link is invalid or has expired.');
    }

    return $decoded;
}

/** Normalize and validate an email address without retaining the submitted form. */
function normalize_recipient_email(string $email): string
{
    $email = strtolower(trim($email));

    if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }

    return $email;
}

function consent_fingerprint(string $context, string $value): string
{
    return hash_hmac('sha256', $context . "\0" . $value, consent_secret_bytes());
}

function consent_encryption_key(): string
{
    return sodium_crypto_generichash(
        'Secure Dice consent encryption v1',
        consent_secret_bytes(),
        SODIUM_CRYPTO_SECRETBOX_KEYBYTES
    );
}

function consent_encrypt_string(string $plaintext): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, consent_encryption_key());

    return consent_base64url_encode($nonce . $ciphertext);
}

function consent_decrypt_string(string $encoded): string
{
    try {
        $combined = consent_base64url_decode($encoded);
    } catch (InvalidConsentTokenException $e) {
        throw new RuntimeException('Encrypted consent data is corrupt.');
    }

    if (strlen($combined) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        throw new RuntimeException('Encrypted consent data is corrupt.');
    }

    $nonce = substr($combined, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = substr($combined, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, consent_encryption_key());

    if (!is_string($plaintext)) {
        throw new RuntimeException('Encrypted consent data failed authentication.');
    }

    return $plaintext;
}

function consent_encrypt_payload(array $payload): string
{
    return consent_encrypt_string(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

function consent_decrypt_payload(string $ciphertext): array
{
    $value = json_decode(consent_decrypt_string($ciphertext), true, 32, JSON_THROW_ON_ERROR);

    if (!is_array($value)) {
        throw new RuntimeException('Encrypted consent payload is invalid.');
    }

    return $value;
}

function consent_timestamp(?int $unixTime = null): string
{
    return gmdate('Y-m-d\TH:i:s\Z', $unixTime ?? time());
}

function consent_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function validate_long_consent_token(string $token): string
{
    $token = trim($token);

    if (strlen($token) !== 43 || strlen(consent_base64url_decode($token)) !== 32) {
        throw new InvalidConsentTokenException('The consent link is invalid or has expired.');
    }

    return $token;
}

function generate_long_consent_token(): string
{
    return consent_base64url_encode(random_bytes(32));
}

function normalize_recipient_code(string $code): string
{
    $normalized = strtolower(str_replace(['-', ' '], '', trim($code)));

    if (preg_match('/^[a-f0-9]{20}$/', $normalized) !== 1) {
        throw new InvalidConsentTokenException('The recipient code is invalid.');
    }

    return $normalized;
}

function format_recipient_code(string $normalized): string
{
    return implode('-', str_split($normalized, 5));
}

function generate_recipient_code(): array
{
    $normalized = bin2hex(random_bytes(10));

    return [
        'normalized' => $normalized,
        'formatted' => format_recipient_code($normalized),
    ];
}

function mask_recipient_email(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    $visible = substr($local, 0, 1);

    return $visible . str_repeat('*', max(2, min(8, strlen($local) - 1))) . '@' . $domain;
}

/** Start a private, CSRF-protected session for consent forms. */
function consent_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');

    session_name('securedice_consent');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function consent_csrf_token(): string
{
    if (!isset($_SESSION['consent_csrf']) || !is_string($_SESSION['consent_csrf'])) {
        $_SESSION['consent_csrf'] = consent_base64url_encode(random_bytes(32));
    }

    return $_SESSION['consent_csrf'];
}

function validate_consent_csrf(string $submitted): void
{
    $expected = $_SESSION['consent_csrf'] ?? '';

    if (!is_string($expected) || $submitted === '' || !hash_equals($expected, $submitted)) {
        throw new InvalidArgumentException('This form expired. Refresh the page and try again.');
    }
}

function consent_source_ip(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

    return $ip === '' ? 'unknown' : $ip;
}

/** Increment a privacy-preserving fixed-window counter and report availability. */
function consume_consent_rate_limit(
    string $context,
    string $identifier,
    int $maximum,
    int $windowSeconds,
    ?int $now = null
): bool {
    $now = $now ?? time();
    $bucket = consent_fingerprint('rate-limit:' . $context, $identifier);
    $connection = result_storage_connection();
    $statement = $connection->prepare(
        'INSERT INTO rate_limits (bucket_key, window_started_at, attempts)
        VALUES (:bucket_key, :now, 1)
        ON CONFLICT(bucket_key) DO UPDATE SET
            window_started_at = CASE
                WHEN rate_limits.window_started_at <= :window_boundary THEN :now
                ELSE rate_limits.window_started_at
            END,
            attempts = CASE
                WHEN rate_limits.window_started_at <= :window_boundary THEN 1
                ELSE rate_limits.attempts + 1
            END'
    );
    $statement->execute([
        ':bucket_key' => $bucket,
        ':now' => $now,
        ':window_boundary' => $now - $windowSeconds,
    ]);

    $lookup = $connection->prepare('SELECT attempts FROM rate_limits WHERE bucket_key = :bucket_key');
    $lookup->execute([':bucket_key' => $bucket]);

    return (int) $lookup->fetchColumn() <= $maximum;
}

function enforce_consent_rate_limit(
    string $context,
    string $identifier,
    int $maximum,
    int $windowSeconds
): void {
    if (!consume_consent_rate_limit($context, $identifier, $maximum, $windowSeconds)) {
        throw new ConsentRateLimitException('Too many attempts. Please wait and try again.');
    }
}

/** Create or refresh a pending opt-in and queue its confirmation message. */
function request_recipient_consent(string $submittedEmail, string $sourceIp): array
{
    $email = normalize_recipient_email($submittedEmail);
    $fingerprint = consent_fingerprint('recipient-email', $email);

    enforce_consent_rate_limit('enrollment-ip', $sourceIp, 5, 3600);
    enforce_consent_rate_limit('enrollment-email', $fingerprint, 3, 86400);

    $connection = result_storage_connection();
    $lookup = $connection->prepare('SELECT id, status FROM recipients WHERE email_fingerprint = :fingerprint');
    $lookup->execute([':fingerprint' => $fingerprint]);
    $existing = $lookup->fetch();

    // Active addresses get the same outward response without another message.
    if (is_array($existing) && $existing['status'] === 'active') {
        return ['accepted' => true, 'queued' => false];
    }

    $now = consent_timestamp();
    $token = generate_long_consent_token();
    $expires = consent_timestamp(time() + 86400);
    $connection->beginTransaction();

    try {
        if (is_array($existing)) {
            $recipientId = (int) $existing['id'];
            $update = $connection->prepare(
                "UPDATE recipients
                SET email_ciphertext = :ciphertext, status = 'pending', updated_at = :updated_at,
                    confirmed_at = NULL, revoked_at = NULL
                WHERE id = :id"
            );
            $update->execute([
                ':ciphertext' => consent_encrypt_string($email),
                ':updated_at' => $now,
                ':id' => $recipientId,
            ]);
        } else {
            $insert = $connection->prepare(
                "INSERT INTO recipients
                (email_fingerprint, email_ciphertext, status, created_at, updated_at)
                VALUES (:fingerprint, :ciphertext, 'pending', :created_at, :updated_at)"
            );
            $insert->execute([
                ':fingerprint' => $fingerprint,
                ':ciphertext' => consent_encrypt_string($email),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $recipientId = (int) $connection->lastInsertId();
        }

        $consume = $connection->prepare(
            'UPDATE consent_challenges SET consumed_at = :consumed_at
            WHERE recipient_id = :recipient_id AND consumed_at IS NULL'
        );
        $consume->execute([':consumed_at' => $now, ':recipient_id' => $recipientId]);

        $supersede = $connection->prepare(
            "UPDATE outbound_messages
            SET status = 'failed', last_error = 'superseded'
            WHERE recipient_id = :recipient_id AND message_type = 'consent_confirmation'
                AND status = 'pending'"
        );
        $supersede->execute([':recipient_id' => $recipientId]);

        $challenge = $connection->prepare(
            'INSERT INTO consent_challenges
            (recipient_id, token_hash, expires_at, created_at)
            VALUES (:recipient_id, :token_hash, :expires_at, :created_at)'
        );
        $challenge->execute([
            ':recipient_id' => $recipientId,
            ':token_hash' => consent_token_hash($token),
            ':expires_at' => $expires,
            ':created_at' => $now,
        ]);

        $message = $connection->prepare(
            "INSERT INTO outbound_messages
            (message_type, recipient_id, payload_ciphertext, status, available_at, created_at)
            VALUES ('consent_confirmation', :recipient_id, :payload, 'pending', :available_at, :created_at)"
        );
        $message->execute([
            ':recipient_id' => $recipientId,
            ':payload' => consent_encrypt_payload([
                'email' => $email,
                'confirmation_token' => $token,
                'expires_at' => $expires,
            ]),
            ':available_at' => $now,
            ':created_at' => $now,
        ]);

        $connection->commit();
    } catch (Throwable $e) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }

        throw $e;
    }

    return ['accepted' => true, 'queued' => true];
}

/** Consume a one-time confirmation and return capabilities that are shown once. */
function confirm_recipient_consent(string $submittedToken, string $sourceIp): array
{
    enforce_consent_rate_limit('confirmation-ip', $sourceIp, 20, 3600);
    $token = validate_long_consent_token($submittedToken);
    $connection = result_storage_connection();
    $connection->beginTransaction();

    try {
        $lookup = $connection->prepare(
            "SELECT c.id AS challenge_id, c.recipient_id, r.email_ciphertext
            FROM consent_challenges c
            JOIN recipients r ON r.id = c.recipient_id
            WHERE c.token_hash = :token_hash
                AND c.consumed_at IS NULL
                AND c.expires_at >= :now
                AND r.status = 'pending'"
        );
        $now = consent_timestamp();
        $lookup->execute([':token_hash' => consent_token_hash($token), ':now' => $now]);
        $record = $lookup->fetch();

        if (!is_array($record)) {
            throw new InvalidConsentTokenException('The consent link is invalid or has expired.');
        }

        $recipientId = (int) $record['recipient_id'];
        $code = generate_recipient_code();
        $managementToken = generate_long_consent_token();

        $consume = $connection->prepare(
            'UPDATE consent_challenges SET consumed_at = :now WHERE id = :id AND consumed_at IS NULL'
        );
        $consume->execute([':now' => $now, ':id' => (int) $record['challenge_id']]);

        $activate = $connection->prepare(
            "UPDATE recipients
            SET status = 'active', updated_at = :now, confirmed_at = :now, revoked_at = NULL
            WHERE id = :id"
        );
        $activate->execute([':now' => $now, ':id' => $recipientId]);

        foreach (['recipient_capabilities', 'recipient_management_tokens'] as $table) {
            $revoke = $connection->prepare(
                "UPDATE {$table} SET status = 'revoked', revoked_at = :now
                WHERE recipient_id = :recipient_id AND status = 'active'"
            );
            $revoke->execute([':now' => $now, ':recipient_id' => $recipientId]);
        }

        $capability = $connection->prepare(
            "INSERT INTO recipient_capabilities
            (recipient_id, code_hash, status, created_at)
            VALUES (:recipient_id, :code_hash, 'active', :created_at)"
        );
        $capability->execute([
            ':recipient_id' => $recipientId,
            ':code_hash' => consent_token_hash($code['normalized']),
            ':created_at' => $now,
        ]);

        $management = $connection->prepare(
            "INSERT INTO recipient_management_tokens
            (recipient_id, token_hash, status, created_at)
            VALUES (:recipient_id, :token_hash, 'active', :created_at)"
        );
        $management->execute([
            ':recipient_id' => $recipientId,
            ':token_hash' => consent_token_hash($managementToken),
            ':created_at' => $now,
        ]);

        $connection->commit();
    } catch (Throwable $e) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }

        throw $e;
    }

    return [
        'recipient_code' => $code['formatted'],
        'management_token' => $managementToken,
        'masked_email' => mask_recipient_email(consent_decrypt_string((string) $record['email_ciphertext'])),
    ];
}

/** Look up a live private management capability. */
function find_recipient_management(string $submittedToken): array
{
    $token = validate_long_consent_token($submittedToken);
    $statement = result_storage_connection()->prepare(
        "SELECT r.id, r.email_ciphertext
        FROM recipient_management_tokens m
        JOIN recipients r ON r.id = m.recipient_id
        WHERE m.token_hash = :token_hash AND m.status = 'active' AND r.status = 'active'"
    );
    $statement->execute([':token_hash' => consent_token_hash($token)]);
    $record = $statement->fetch();

    if (!is_array($record)) {
        throw new InvalidConsentTokenException('The management link is invalid or has been revoked.');
    }

    return [
        'recipient_id' => (int) $record['id'],
        'masked_email' => mask_recipient_email(consent_decrypt_string((string) $record['email_ciphertext'])),
    ];
}

function get_recipient_management(string $submittedToken, string $sourceIp): array
{
    enforce_consent_rate_limit('management-ip', $sourceIp, 20, 3600);

    return find_recipient_management($submittedToken);
}

/** Revoke the prior code and return a replacement that is shown once. */
function rotate_recipient_code(string $managementToken): string
{
    $management = find_recipient_management($managementToken);
    $recipientId = $management['recipient_id'];
    $connection = result_storage_connection();
    $now = consent_timestamp();
    $code = generate_recipient_code();
    $connection->beginTransaction();

    try {
        $revoke = $connection->prepare(
            "UPDATE recipient_capabilities SET status = 'revoked', revoked_at = :now
            WHERE recipient_id = :recipient_id AND status = 'active'"
        );
        $revoke->execute([':now' => $now, ':recipient_id' => $recipientId]);
        $insert = $connection->prepare(
            "INSERT INTO recipient_capabilities
            (recipient_id, code_hash, status, created_at)
            VALUES (:recipient_id, :code_hash, 'active', :created_at)"
        );
        $insert->execute([
            ':recipient_id' => $recipientId,
            ':code_hash' => consent_token_hash($code['normalized']),
            ':created_at' => $now,
        ]);
        $connection->commit();
    } catch (Throwable $e) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }

        throw $e;
    }

    return $code['formatted'];
}

/** Revoke the recipient, their share code, and all management capabilities. */
function revoke_recipient_consent(string $managementToken): void
{
    $management = find_recipient_management($managementToken);
    $recipientId = $management['recipient_id'];
    $connection = result_storage_connection();
    $now = consent_timestamp();
    $connection->beginTransaction();

    try {
        $recipient = $connection->prepare(
            "UPDATE recipients SET status = 'revoked', updated_at = :now, revoked_at = :now
            WHERE id = :recipient_id"
        );
        $recipient->execute([':now' => $now, ':recipient_id' => $recipientId]);

        foreach (['recipient_capabilities', 'recipient_management_tokens'] as $table) {
            $revoke = $connection->prepare(
                "UPDATE {$table} SET status = 'revoked', revoked_at = :now
                WHERE recipient_id = :recipient_id AND status = 'active'"
            );
            $revoke->execute([':now' => $now, ':recipient_id' => $recipientId]);
        }

        $consume = $connection->prepare(
            'UPDATE consent_challenges SET consumed_at = :now
            WHERE recipient_id = :recipient_id AND consumed_at IS NULL'
        );
        $consume->execute([':now' => $now, ':recipient_id' => $recipientId]);
        $connection->commit();
    } catch (Throwable $e) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }

        throw $e;
    }
}

/** Resolve a shareable recipient code only while its owner remains opted in. */
function resolve_recipient_code(string $submittedCode): ?array
{
    try {
        $code = normalize_recipient_code($submittedCode);
    } catch (InvalidConsentTokenException $e) {
        return null;
    }

    $statement = result_storage_connection()->prepare(
        "SELECT r.id, r.email_ciphertext
        FROM recipient_capabilities c
        JOIN recipients r ON r.id = c.recipient_id
        WHERE c.code_hash = :code_hash AND c.status = 'active' AND r.status = 'active'"
    );
    $statement->execute([':code_hash' => consent_token_hash($code)]);
    $record = $statement->fetch();

    if (!is_array($record)) {
        return null;
    }

    return [
        'recipient_id' => (int) $record['id'],
        'email' => consent_decrypt_string((string) $record['email_ciphertext']),
    ];
}

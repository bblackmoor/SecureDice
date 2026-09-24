<?php

declare(strict_types=1);

class SecureDiceConfigurationException extends RuntimeException
{
}

/** Return the application's persistent 256-bit secret. */
function securedice_secret_bytes(): string
{
    static $secret = null;

    if (is_string($secret)) {
        return $secret;
    }

    $configured = getenv('SECUREDICE_SECRET');
    $configured = is_string($configured) ? trim($configured) : '';

    if (preg_match('/^[a-f0-9]{64}$/i', $configured) !== 1) {
        throw new SecureDiceConfigurationException(
            'SECUREDICE_SECRET must contain exactly 64 hexadecimal characters.'
        );
    }

    $decoded = hex2bin($configured);

    if (!is_string($decoded) || strlen($decoded) !== 32) {
        throw new SecureDiceConfigurationException('SECUREDICE_SECRET is invalid.');
    }

    return $secret = $decoded;
}

/** Configuration keys accepted from the private Secure Dice environment file. */
function securedice_configuration_keys(): array
{
    return [
        'SECUREDICE_DB_HOST',
        'SECUREDICE_DB_PORT',
        'SECUREDICE_DB_NAME',
        'SECUREDICE_DB_USER',
        'SECUREDICE_DB_PASSWORD',
        'SECUREDICE_SECRET',
        'SECUREDICE_BASE_URL',
        'SECUREDICE_SMTP_HOST',
        'SECUREDICE_SMTP_PORT',
        'SECUREDICE_SMTP_ENCRYPTION',
        'SECUREDICE_SMTP_USERNAME',
        'SECUREDICE_SMTP_PASSWORD',
        'SECUREDICE_SMTP_FROM_ADDRESS',
        'SECUREDICE_SMTP_FROM_NAME',
        'SECUREDICE_SMTP_TIMEOUT',
    ];
}

function securedice_configuration_path(): ?string
{
    $explicit = getenv('SECUREDICE_CONFIG_PATH');

    if ($explicit !== false && trim($explicit) !== '') {
        return trim($explicit);
    }

    $home = getenv('HOME');

    if ($home === false || trim($home) === '') {
        $home = $_SERVER['HOME'] ?? '';
    }

    $home = is_string($home) ? rtrim(trim($home), DIRECTORY_SEPARATOR) : '';

    return $home === '' ? null : $home . DIRECTORY_SEPARATOR . '.securedice.env';
}

function securedice_decode_configuration_value(string $value, int $lineNumber): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $quote = $value[0];

    if ($quote !== '"' && $quote !== "'") {
        return $value;
    }

    if (strlen($value) < 2 || substr($value, -1) !== $quote) {
        throw new SecureDiceConfigurationException(
            "The Secure Dice configuration has an unterminated value on line {$lineNumber}."
        );
    }

    $inner = substr($value, 1, -1);
    $decoded = '';
    $length = strlen($inner);

    for ($index = 0; $index < $length; $index++) {
        $character = $inner[$index];

        if ($character !== '\\') {
            $decoded .= $character;
            continue;
        }

        if (++$index >= $length) {
            throw new SecureDiceConfigurationException(
                "The Secure Dice configuration has an invalid escape on line {$lineNumber}."
            );
        }

        $escaped = $inner[$index];

        if ($quote === "'") {
            if ($escaped !== "'" && $escaped !== '\\') {
                throw new SecureDiceConfigurationException(
                    "The Secure Dice configuration has an invalid escape on line {$lineNumber}."
                );
            }

            $decoded .= $escaped;
            continue;
        }

        $escapes = ['"' => '"', '\\' => '\\', 'n' => "\n", 'r' => "\r", 't' => "\t"];

        if (!array_key_exists($escaped, $escapes)) {
            throw new SecureDiceConfigurationException(
                "The Secure Dice configuration has an invalid escape on line {$lineNumber}."
            );
        }

        $decoded .= $escapes[$escaped];
    }

    return $decoded;
}

function securedice_check_configuration_file(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        throw new SecureDiceConfigurationException(
            'The configured Secure Dice configuration file is missing or unreadable.'
        );
    }

    if (DIRECTORY_SEPARATOR === '/') {
        $permissions = fileperms($path);

        if ($permissions !== false && ($permissions & 0077) !== 0) {
            throw new SecureDiceConfigurationException(
                'The Secure Dice configuration file must be private; run chmod 600 on it.'
            );
        }
    }
}

function securedice_parse_configuration_file(string $path): array
{
    securedice_check_configuration_file($path);
    $lines = file($path, FILE_IGNORE_NEW_LINES);

    if (!is_array($lines)) {
        throw new SecureDiceConfigurationException('The Secure Dice configuration file could not be read.');
    }

    $allowed = array_fill_keys(securedice_configuration_keys(), true);
    $configuration = [];

    foreach ($lines as $offset => $line) {
        $lineNumber = $offset + 1;
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (preg_match('/^([A-Z][A-Z0-9_]*)\s*=(.*)$/', $line, $matches) !== 1) {
            throw new SecureDiceConfigurationException(
                "The Secure Dice configuration has invalid syntax on line {$lineNumber}."
            );
        }

        $key = $matches[1];

        if (!isset($allowed[$key])) {
            throw new SecureDiceConfigurationException(
                "The Secure Dice configuration contains an unsupported key on line {$lineNumber}."
            );
        }

        if (array_key_exists($key, $configuration)) {
            throw new SecureDiceConfigurationException(
                "The Secure Dice configuration repeats a key on line {$lineNumber}."
            );
        }

        $configuration[$key] = securedice_decode_configuration_value($matches[2], $lineNumber);
    }

    return $configuration;
}

/** Apply file values only when the process environment did not define the key. */
function securedice_apply_configuration(array $configuration): void
{
    foreach ($configuration as $key => $value) {
        if (!in_array($key, securedice_configuration_keys(), true) || !is_string($value)) {
            throw new SecureDiceConfigurationException('Invalid Secure Dice configuration data.');
        }

        if (getenv($key) !== false) {
            continue;
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}

function securedice_load_configuration(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $loaded = true;
    $path = securedice_configuration_path();

    if ($path === null) {
        return;
    }

    $explicit = getenv('SECUREDICE_CONFIG_PATH');

    if (!file_exists($path)) {
        if ($explicit !== false && trim($explicit) !== '') {
            securedice_check_configuration_file($path);
        }

        return;
    }

    securedice_apply_configuration(securedice_parse_configuration_file($path));
}

securedice_load_configuration();

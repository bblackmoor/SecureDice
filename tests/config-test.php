<?php

declare(strict_types=1);

$temporaryDirectory = sys_get_temp_dir()
    . '/securedice-config-test-'
    . bin2hex(random_bytes(8));

if (!mkdir($temporaryDirectory, 0700, true) && !is_dir($temporaryDirectory)) {
    throw new RuntimeException('The configuration test directory could not be created.');
}

$originalHome = getenv('HOME');
putenv('HOME=' . $temporaryDirectory);
putenv('SECUREDICE_CONFIG_PATH');

require_once dirname(__DIR__) . '/config.php';

function config_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function config_test_write(string $path, string $contents, int $mode = 0600): void
{
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('A configuration fixture could not be written.');
    }

    chmod($path, $mode);
    clearstatcache(true, $path);
}

try {
    config_test_assert(
        securedice_configuration_path() === $temporaryDirectory . '/.securedice.env',
        'The default configuration path is not relative to HOME.'
    );

    $validPath = $temporaryDirectory . '/valid.env';
    config_test_write(
        $validPath,
        "# private settings\n"
        . "SECUREDICE_SMTP_HOST=smtp.file.example\n"
        . "SECUREDICE_SMTP_PORT=2525\n"
        . "SECUREDICE_SMTP_PASSWORD='hash#equals=spaces allowed'\n"
        . 'SECUREDICE_SMTP_FROM_NAME="Secure \\"Dice\\""' . "\n"
    );
    $parsed = securedice_parse_configuration_file($validPath);
    config_test_assert(
        $parsed['SECUREDICE_SMTP_PASSWORD'] === 'hash#equals=spaces allowed',
        'A quoted SMTP password was not parsed exactly.'
    );
    config_test_assert(
        $parsed['SECUREDICE_SMTP_FROM_NAME'] === 'Secure "Dice"',
        'A double-quoted escaped value was not parsed exactly.'
    );

    putenv('SECUREDICE_SMTP_HOST=smtp.environment.example');
    putenv('SECUREDICE_SMTP_PORT');
    securedice_apply_configuration($parsed);
    config_test_assert(
        getenv('SECUREDICE_SMTP_HOST') === 'smtp.environment.example',
        'A file value overrode an existing environment variable.'
    );
    config_test_assert(
        getenv('SECUREDICE_SMTP_PORT') === '2525',
        'A missing environment variable was not loaded from the file.'
    );

    $duplicatePath = $temporaryDirectory . '/duplicate.env';
    config_test_write(
        $duplicatePath,
        "SECUREDICE_SMTP_PORT=587\nSECUREDICE_SMTP_PORT=465\n"
    );
    $duplicateRejected = false;

    try {
        securedice_parse_configuration_file($duplicatePath);
    } catch (SecureDiceConfigurationException $e) {
        $duplicateRejected = str_contains($e->getMessage(), 'repeats a key');
    }
    config_test_assert($duplicateRejected, 'A duplicate configuration key was accepted.');

    $unknownPath = $temporaryDirectory . '/unknown.env';
    config_test_write($unknownPath, "SECUREDICE_UNKNOWN=value\n");
    $unknownRejected = false;

    try {
        securedice_parse_configuration_file($unknownPath);
    } catch (SecureDiceConfigurationException $e) {
        $unknownRejected = str_contains($e->getMessage(), 'unsupported key');
    }
    config_test_assert($unknownRejected, 'An unsupported configuration key was accepted.');

    $secretValue = 'do-not-repeat-this-password';
    $malformedPath = $temporaryDirectory . '/malformed.env';
    config_test_write($malformedPath, 'SECUREDICE_SMTP_PASSWORD="' . $secretValue . "\n");
    $secretProtected = false;

    try {
        securedice_parse_configuration_file($malformedPath);
    } catch (SecureDiceConfigurationException $e) {
        $secretProtected = !str_contains($e->getMessage(), $secretValue);
    }
    config_test_assert($secretProtected, 'A configuration error disclosed a secret value.');

    $publicPath = $temporaryDirectory . '/public.env';
    config_test_write($publicPath, "SECUREDICE_SMTP_PORT=587\n", 0644);
    $publicRejected = false;

    try {
        securedice_parse_configuration_file($publicPath);
    } catch (SecureDiceConfigurationException $e) {
        $publicRejected = str_contains($e->getMessage(), 'chmod 600');
    }
    config_test_assert($publicRejected, 'An overly permissive configuration file was accepted.');

    $missingRejected = false;

    try {
        securedice_parse_configuration_file($temporaryDirectory . '/missing.env');
    } catch (SecureDiceConfigurationException $e) {
        $missingRejected = str_contains($e->getMessage(), 'missing or unreadable');
    }
    config_test_assert($missingRejected, 'An explicitly requested missing file was accepted.');

    echo "Configuration tests passed.\n";
} finally {
    foreach (glob($temporaryDirectory . '/*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($temporaryDirectory);

    foreach (securedice_configuration_keys() as $key) {
        putenv($key);
    }

    if ($originalHome === false) {
        putenv('HOME');
    } else {
        putenv('HOME=' . $originalHome);
    }
}

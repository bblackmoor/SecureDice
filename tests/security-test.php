<?php

declare(strict_types=1);

function security_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function security_test_file(string $relativePath): string
{
    $contents = file_get_contents(dirname(__DIR__) . '/' . $relativePath);

    if (!is_string($contents)) {
        throw new RuntimeException("Could not read {$relativePath}.");
    }

    return $contents;
}

$lib = security_test_file('lib.php');
security_test_assert(
    str_contains($lib, "session.use_strict_mode")
        && str_contains($lib, "'httponly' => true")
        && str_contains($lib, "'samesite' => 'Strict'"),
    'Session cookies are not strictly configured.'
);
security_test_assert(
    str_contains($lib, "Content-Security-Policy:")
        && str_contains($lib, "frame-ancestors 'none'")
        && str_contains($lib, 'X-Content-Type-Options: nosniff'),
    'Required browser security headers are missing.'
);

$webEntries = [
    'securedice.php',
    'roll.php',
    'results.php',
    'verify.php',
    'recipient.php',
    'confirm.php',
    'recover-recipient.php',
    'manage-recipient.php',
    'unsubscribe.php',
    'email-result.php',
];

foreach ($webEntries as $entry) {
    security_test_assert(
        str_contains(security_test_file($entry), 'send_security_headers();'),
        "{$entry} does not send the shared security headers."
    );
}

foreach (['confirm.php', 'recover-recipient.php'] as $entry) {
    $contents = security_test_file($entry);
    security_test_assert(
        str_contains($contents, "\$_SERVER['REQUEST_METHOD'] === 'POST'")
            && str_contains($contents, 'validate_consent_csrf('),
        "{$entry} can consume its one-time token without a CSRF-protected POST."
    );
}

foreach (glob(dirname(__DIR__) . '/*.php') ?: [] as $path) {
    $contents = file_get_contents($path);
    security_test_assert(
        !is_string($contents) || preg_match('/\son[a-z]+\s*=/i', $contents) !== 1,
        basename($path) . ' contains an inline event handler blocked by the CSP.'
    );
}

$storage = security_test_file('storage.php');
security_test_assert(
    str_contains($storage, 'result_authentication_hmac')
        && str_contains($storage, 'auth_hmac_sha256')
        && str_contains($storage, 'hash_equals($storedHmac, $calculatedHmac)'),
    'Stored results are not authenticated with a server-secret HMAC.'
);
security_test_assert(
    str_contains(security_test_file('mysql-schema.php'), 'PRIMARY KEY (recipient_id, result_id)'),
    'Permanent recipient-result replay protection is missing.'
);

$email = security_test_file('email.php');
$claimPosition = strpos($email, '$claim->rowCount() !== 1');
$limitPosition = strpos($email, 'recipient_delivery_available($recipientId');
security_test_assert(
    $claimPosition !== false && $limitPosition !== false && $claimPosition < $limitPosition,
    'A replay can consume a recipient delivery allowance before it is rejected.'
);

echo "Security tests passed.\n";

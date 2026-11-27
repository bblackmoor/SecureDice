<?php
// results.php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/storage.php';

send_security_headers();
app_start_session();

$data = $_SESSION['last_roll'] ?? null;

if (!is_array($data) || !validate_result_payload_v2($data)) {
    header('Location: securedice.php');
    exit;
}

header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$canonicalJson = encode_canonical_result($data);
$resultViewMode = 'new';
$storedAt = null;

require __DIR__ . '/result-view.php';

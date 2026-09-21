<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/smtp.php';

$limit = 50;

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--limit=(\d+)$/', $argument, $matches) === 1) {
        $limit = max(1, min(500, (int) $matches[1]));
    }
}

try {
    email_public_base_url();
    smtp_configuration();
    $result = process_email_queue('send_smtp_message', $limit);
    fwrite(
        STDOUT,
        "Processed {$result['processed']}; sent {$result['sent']}; failed {$result['failed']}.\n"
    );
} catch (Throwable $e) {
    error_log('Secure Dice email worker failed: ' . $e->getMessage());
    fwrite(STDERR, "Secure Dice email worker failed. Check the server log.\n");
    exit(1);
}

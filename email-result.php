<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

send_security_headers();
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
http_response_code(410);
echo "Email recipients must be chosen before rolling. Return to securedice.php to start a new roll.\n";

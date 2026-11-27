<?php

declare(strict_types=1);

// Only use the shared theme from the RPG Library site's own document root.
$host = $_SERVER['HTTP_HOST'] ?? '';
if (preg_match('/\A(?:www\.)?rpglibrary\.org(?::(?:80|443))?\z/i', $host) === 1) {
    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '/');
    if ($documentRoot !== false && str_starts_with(__DIR__, $documentRoot . DIRECTORY_SEPARATOR)) {
        $layout = $documentRoot . '/standalone/_layout.php';
        $theme = $documentRoot . '/extensions/RpgLibraryTheme';

        if (is_readable($layout)
            && is_file($documentRoot . '/favicon.ico')
            && is_file($theme . '/icon.png')
            && is_file($theme . '/theme.css')
            && is_file($theme . '/standalone.css')
        ) {
            require_once $layout;
            return;
        }
    }
}

require_once __DIR__ . '/layout-local.php';

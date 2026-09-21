<?php

declare(strict_types=1);

function ui_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function ui_test_file(string $relativePath): string
{
    $contents = file_get_contents(dirname(__DIR__) . '/' . $relativePath);

    if (!is_string($contents)) {
        throw new RuntimeException("Could not read {$relativePath}.");
    }

    return $contents;
}

$pages = [
    'securedice.php',
    'result-view.php',
    'verify.php',
    'recipient.php',
    'confirm.php',
    'recover-recipient.php',
    'manage-recipient.php',
    'unsubscribe.php',
    'email-result.php',
];

foreach ($pages as $page) {
    $contents = ui_test_file($page);
    ui_test_assert(
        str_contains($contents, 'class="skip-link" href="#main-content"'),
        "{$page} does not provide skip navigation."
    );
    ui_test_assert(
        str_contains($contents, '<main id="main-content" tabindex="-1">'),
        "{$page} does not expose the main-content target."
    );
}

$css = ui_test_file('securedice.base.css');
ui_test_assert(str_contains($css, 'a:focus-visible'), 'Visible keyboard focus styling is missing.');
ui_test_assert(str_contains($css, '.sr-only'), 'The screen-reader-only utility is missing.');
ui_test_assert(
    str_contains($css, '@media (prefers-reduced-motion: reduce)'),
    'Reduced-motion handling is missing.'
);

$mobileCss = ui_test_file('securedice.mobile.css');
ui_test_assert(
    str_contains($mobileCss, 'body:has(.floating-actions)'),
    'Mobile toolbar spacing is not conditional.'
);
ui_test_assert(
    str_contains($mobileCss, '.preset-table tr'),
    'The URL-preset table lacks its narrow-screen layout.'
);

$javascript = ui_test_file('securedice.js');
ui_test_assert(!str_contains($javascript, 'alert('), 'Browser-alert validation remains in the UI.');
ui_test_assert(
    str_contains($javascript, 'aria-live') && str_contains($javascript, 'announceAction'),
    'Copy-action results are not announced to assistive technology.'
);
ui_test_assert(
    str_contains($javascript, 'document.querySelectorAll(".roll-b, .dice-section-title-b")'),
    'The complete secondary-roll section is not toggled together.'
);

$deployment = ui_test_file('DEPLOYMENT.md');
foreach ([
    '.securedice.env',
    'chmod 600',
    'smtp.dreamhost.com',
    '--quiet',
    '## Backups',
    '## Upgrading',
    '## Rollback',
    '## Lost-secret recovery',
] as $requiredText) {
    ui_test_assert(
        str_contains($deployment, $requiredText),
        "The deployment guide is missing {$requiredText}."
    );
}

$worker = ui_test_file('bin/process-email-queue.php');
ui_test_assert(str_contains($worker, "\$argument === '--quiet'"), 'The cron worker lacks quiet mode.');

$resultFunctions = ui_test_file('results-functions.php');
ui_test_assert(
    str_contains($resultFunctions, '"wild die explosion"')
        && str_contains($resultFunctions, '"dropped"')
        && str_contains($resultFunctions, 'class="sr-only"'),
    'Visual die states lack screen-reader text.'
);

echo "UI and documentation tests passed.\n";

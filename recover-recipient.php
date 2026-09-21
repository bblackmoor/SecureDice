<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/consent.php';

header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$token = trim((string) ($_GET['token'] ?? ''));
$result = null;
$error = '';
$statusCode = 200;

try {
    $result = recover_recipient_management($token, consent_source_ip());
} catch (InvalidConsentTokenException $e) {
    $statusCode = 400;
    $error = $e->getMessage();
} catch (ConsentRateLimitException $e) {
    $statusCode = 429;
    $error = $e->getMessage();
} catch (ConsentConfigurationException $e) {
    $statusCode = 503;
    $error = 'Management-link recovery is temporarily unavailable.';
} catch (Throwable $e) {
    error_log('Secure Dice management recovery error: ' . $e->getMessage());
    $statusCode = 503;
    $error = 'Management-link recovery is temporarily unavailable.';
}

http_response_code($statusCode);
$managementUrl = is_array($result)
    ? build_absolute_url('manage-recipient.php?token=' . rawurlencode($result['management_token']))
    : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Recover consent management — Secure Dice</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <link rel="stylesheet" href="securedice.base.css">
    <link rel="stylesheet" href="securedice.mobile.css">
    <script src="securedice.js" defer></script>
</head>
<body>
<a class="skip-link" href="#main-content">Skip to main content</a>
<header class="site-header" role="banner">
    <div class="site-header-inner">
        <div class="site-title-wrap">
            <h1 class="site-title">Secure Dice</h1>
            <div class="site-badges" aria-label="Status">
                <span class="badge">Private</span>
                <span class="badge badge-primary">Link Recovery</span>
            </div>
        </div>
        <p class="site-subtitle">Replace a lost private settings link without creating an account.</p>
    </div>
</header>

<main id="main-content" tabindex="-1">
    <section class="card consent-card" aria-labelledby="recovery-title">
        <?php if (is_array($result)): ?>
            <h2 id="recovery-title">Settings link replaced</h2>
            <p>The previous settings link for <strong><?= h($result['masked_email']) ?></strong> has been revoked.</p>
            <div class="consent-notice is-success" role="status">Save this private replacement. A copy has also been queued for your address.</div>
            <div class="secret-row">
                <code class="secret-value secret-url"><?= h($managementUrl) ?></code>
                <button class="sd2-action-btn neutral inline-action" type="button" data-copy="<?= h($managementUrl) ?>">Copy Link</button>
            </div>
            <p><a class="sd2-action-btn primary inline-action" href="<?= h($managementUrl) ?>">Open Email Settings</a></p>
        <?php else: ?>
            <h2 id="recovery-title">Recovery unavailable</h2>
            <div class="consent-notice is-error" role="alert"><?= h($error) ?></div>
            <p><a class="sd2-action-btn primary inline-action" href="recipient.php">Request Another Email</a></p>
        <?php endif; ?>
    </section>
</main>

<footer class="site-footer" role="contentinfo">
    Copyright &copy; 2005-2026 Brandon Blackmoor<br>
    Source: <a href="https://github.com/bblackmoor/securedice">github.com/bblackmoor/securedice</a><br>
    Last updated: <?= h(app_updated_date()) ?> (version <?= h(app_version()) ?>)
</footer>
</body>
</html>

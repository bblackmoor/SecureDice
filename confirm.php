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
    $result = confirm_recipient_consent($token, consent_source_ip());
} catch (InvalidConsentTokenException $e) {
    $statusCode = 400;
    $error = $e->getMessage();
} catch (ConsentRateLimitException $e) {
    $statusCode = 429;
    $error = $e->getMessage();
} catch (ConsentConfigurationException $e) {
    $statusCode = 503;
    $error = 'Recipient confirmation is temporarily unavailable.';
} catch (Throwable $e) {
    error_log('Secure Dice consent confirmation error: ' . $e->getMessage());
    $statusCode = 503;
    $error = 'Recipient confirmation is temporarily unavailable.';
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
    <title>Confirm recipient — Secure Dice</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <link rel="stylesheet" href="securedice.base.css">
    <link rel="stylesheet" href="securedice.mobile.css">
    <script src="securedice.js" defer></script>
</head>
<body>
<header class="site-header" role="banner">
    <div class="site-header-inner">
        <div class="site-title-wrap">
            <h1 class="site-title">Secure Dice</h1>
            <div class="site-badges" aria-label="Status">
                <span class="badge">Private</span>
                <span class="badge badge-primary">Recipient Opt-in</span>
            </div>
        </div>
        <p class="site-subtitle">Confirm control of your address and receive private recipient credentials.</p>
    </div>
</header>

<main>
    <section class="card consent-card" aria-labelledby="confirmation-title">
        <?php if (is_array($result)): ?>
            <h2 id="confirmation-title">Recipient confirmed</h2>
            <p><strong><?= h($result['masked_email']) ?></strong> can now receive Secure Dice results.</p>
            <div class="consent-notice is-success" role="status">
                Save both values now. Secure Dice stores only their hashes and cannot show them again.
            </div>

            <h3>Recipient code</h3>
            <p>Share this code with people you permit to email results to you.</p>
            <div class="secret-row">
                <code class="secret-value"><?= h($result['recipient_code']) ?></code>
                <button class="sd2-action-btn neutral inline-action" type="button" data-copy="<?= h($result['recipient_code']) ?>">Copy Code</button>
            </div>

            <h3>Private management link</h3>
            <p>Keep this link private. It can rotate your code or revoke consent immediately.</p>
            <div class="secret-row">
                <code class="secret-value secret-url"><?= h($managementUrl) ?></code>
                <button class="sd2-action-btn neutral inline-action" type="button" data-copy="<?= h($managementUrl) ?>">Copy Link</button>
            </div>
            <p><a class="sd2-action-btn primary inline-action" href="<?= h($managementUrl) ?>">Manage Consent</a></p>
        <?php else: ?>
            <h2 id="confirmation-title">Confirmation unavailable</h2>
            <div class="consent-notice is-error" role="alert"><?= h($error) ?></div>
            <p>Request a new confirmation if this link has expired or was already used.</p>
            <p><a class="sd2-action-btn primary inline-action" href="recipient.php">Request Confirmation</a></p>
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

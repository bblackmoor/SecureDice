<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/consent.php';

header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
send_security_headers();
consent_start_session();

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$result = null;
$ready = false;
$error = '';
$statusCode = 200;

try {
    validate_long_consent_token($token);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validate_consent_csrf((string) ($_POST['csrf_token'] ?? ''));
        $result = confirm_recipient_consent($token, consent_source_ip());
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $ready = true;
    } else {
        $statusCode = 405;
        $error = 'Open the confirmation link from your email.';
    }
} catch (InvalidArgumentException $e) {
    $statusCode = 400;
    $error = $e->getMessage();
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
<a class="skip-link" href="#main-content">Skip to main content</a>
<header class="site-header" role="banner">
    <div class="site-header-inner">
        <div class="site-title-wrap">
            <h1 class="site-title">Secure Dice</h1>
            <div class="site-badges" aria-label="Status">
                <span class="badge">Private</span>
                <span class="badge badge-primary">Recipient Opt-in</span>
            </div>
        </div>
        <p class="site-subtitle">Confirm control of your address and choose to receive Secure Dice results.</p>
    </div>
</header>

<main id="main-content" tabindex="-1">
    <section class="card consent-card" aria-labelledby="confirmation-title">
        <?php if (is_array($result)): ?>
            <h2 id="confirmation-title">Recipient confirmed</h2>
            <p><strong><?= h($result['masked_email']) ?></strong> can now receive Secure Dice results.</p>
            <div class="consent-notice is-success" role="status">
                Opt-in is complete. Your private settings link has also been queued for delivery.
            </div>

            <h3>Private settings link</h3>
            <p>Keep this link private. It can pause result email, choose a delivery setting, or permanently revoke consent.</p>
            <div class="secret-row">
                <code class="secret-value secret-url"><?= h($managementUrl) ?></code>
                <button class="sd2-action-btn neutral inline-action" type="button" data-copy="<?= h($managementUrl) ?>">Copy Link</button>
            </div>
            <p><a class="sd2-action-btn primary inline-action" href="<?= h($managementUrl) ?>">Open Email Settings</a></p>
        <?php elseif ($ready): ?>
            <h2 id="confirmation-title">Confirm email opt-in?</h2>
            <p>This will allow Secure Dice results to be sent to the address that requested this link.</p>
            <form method="post" action="confirm.php">
                <input type="hidden" name="csrf_token" value="<?= h(consent_csrf_token()) ?>">
                <input type="hidden" name="token" value="<?= h($token) ?>">
                <button class="sd2-action-btn primary inline-action" type="submit">Confirm Email Opt-in</button>
            </form>
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

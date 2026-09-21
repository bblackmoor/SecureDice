<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/consent.php';

header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
consent_start_session();

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$recipient = null;
$revoked = false;
$error = '';
$statusCode = 200;

try {
    $recipient = get_recipient_unsubscribe($token, consent_source_ip());

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validate_consent_csrf((string) ($_POST['csrf_token'] ?? ''));
        revoke_recipient_with_unsubscribe_token($token);
        $recipient = null;
        $revoked = true;
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
    $error = 'Unsubscribe is temporarily unavailable.';
} catch (Throwable $e) {
    error_log('Secure Dice unsubscribe error: ' . $e->getMessage());
    $statusCode = 503;
    $error = 'Unsubscribe is temporarily unavailable.';
}

http_response_code($statusCode);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Stop Secure Dice email — Secure Dice</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <link rel="stylesheet" href="securedice.base.css">
    <link rel="stylesheet" href="securedice.mobile.css">
</head>
<body>
<header class="site-header" role="banner">
    <div class="site-header-inner">
        <div class="site-title-wrap">
            <h1 class="site-title">Secure Dice</h1>
            <div class="site-badges" aria-label="Status">
                <span class="badge">Private</span>
                <span class="badge badge-primary">Unsubscribe</span>
            </div>
        </div>
        <p class="site-subtitle">Stop result email without signing in.</p>
    </div>
</header>

<main>
    <section class="card consent-card" aria-labelledby="unsubscribe-title">
        <?php if ($revoked): ?>
            <h2 id="unsubscribe-title">Email consent revoked</h2>
            <div class="consent-notice is-success" role="status">Secure Dice will no longer send results to this address.</div>
            <p>You can opt in again later if you change your mind.</p>
        <?php elseif (is_array($recipient)): ?>
            <h2 id="unsubscribe-title">Stop Secure Dice email?</h2>
            <p>This immediately revokes consent for <strong><?= h($recipient['masked_email']) ?></strong>, including its private management link and pending result email.</p>
            <?php if ($error !== ''): ?>
                <div class="consent-notice is-error" role="alert"><?= h($error) ?></div>
            <?php endif; ?>
            <form method="post" action="unsubscribe.php">
                <input type="hidden" name="csrf_token" value="<?= h(consent_csrf_token()) ?>">
                <input type="hidden" name="token" value="<?= h($token) ?>">
                <button class="sd2-action-btn danger inline-action" type="submit">Stop All Secure Dice Email</button>
            </form>
        <?php else: ?>
            <h2 id="unsubscribe-title">Unsubscribe unavailable</h2>
            <div class="consent-notice is-error" role="alert"><?= h($error) ?></div>
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

<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/consent.php';

header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
consent_start_session();

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$management = null;
$newCode = '';
$revoked = false;
$error = '';
$statusCode = 200;

try {
    $management = get_recipient_management($token, consent_source_ip());

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validate_consent_csrf((string) ($_POST['csrf_token'] ?? ''));
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'rotate') {
            $newCode = rotate_recipient_code($token);
        } elseif ($action === 'revoke') {
            revoke_recipient_consent($token);
            $revoked = true;
            $management = null;
        } else {
            throw new InvalidArgumentException('Choose a valid consent action.');
        }
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
    $error = 'Consent management is temporarily unavailable.';
} catch (Throwable $e) {
    error_log('Secure Dice consent management error: ' . $e->getMessage());
    $statusCode = 503;
    $error = 'Consent management is temporarily unavailable.';
}

http_response_code($statusCode);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Manage recipient consent — Secure Dice</title>
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
                <span class="badge badge-primary">Consent Management</span>
            </div>
        </div>
        <p class="site-subtitle">Rotate your recipient code or withdraw consent immediately.</p>
    </div>
</header>

<main>
    <section class="card consent-card" aria-labelledby="management-title">
        <?php if ($revoked): ?>
            <h2 id="management-title">Consent revoked</h2>
            <div class="consent-notice is-success" role="status">Your recipient code and this management link no longer work.</div>
            <p>You may opt in again later with a new confirmation.</p>
        <?php elseif (is_array($management)): ?>
            <h2 id="management-title">Manage consent</h2>
            <p>Recipient: <strong><?= h($management['masked_email']) ?></strong></p>

            <?php if ($newCode !== ''): ?>
                <div class="consent-notice is-success" role="status">Your previous code was revoked. Save the replacement now; it cannot be shown again.</div>
                <div class="secret-row">
                    <code class="secret-value"><?= h($newCode) ?></code>
                    <button class="sd2-action-btn neutral inline-action" type="button" data-copy="<?= h($newCode) ?>">Copy Code</button>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="consent-notice is-error" role="alert"><?= h($error) ?></div>
            <?php endif; ?>

            <div class="consent-actions">
                <form method="post" action="manage-recipient.php">
                    <input type="hidden" name="csrf_token" value="<?= h(consent_csrf_token()) ?>">
                    <input type="hidden" name="token" value="<?= h($token) ?>">
                    <input type="hidden" name="action" value="rotate">
                    <button class="sd2-action-btn primary inline-action" type="submit">Rotate Recipient Code</button>
                </form>

                <form method="post" action="manage-recipient.php" onsubmit="return window.confirm('Revoke this recipient and all of its codes?');">
                    <input type="hidden" name="csrf_token" value="<?= h(consent_csrf_token()) ?>">
                    <input type="hidden" name="token" value="<?= h($token) ?>">
                    <input type="hidden" name="action" value="revoke">
                    <button class="sd2-action-btn danger inline-action" type="submit">Revoke Consent</button>
                </form>
            </div>
        <?php else: ?>
            <h2 id="management-title">Management unavailable</h2>
            <div class="consent-notice is-error" role="alert"><?= h($error) ?></div>
            <p>The link may be invalid, rotated, or revoked.</p>
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

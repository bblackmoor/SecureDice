<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/consent.php';

header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
consent_start_session();

$status = '';
$message = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));

    try {
        validate_consent_csrf((string) ($_POST['csrf_token'] ?? ''));
        request_recipient_consent($email, consent_source_ip());
        $status = 'success';
        $message = 'If this address can be enrolled, a confirmation message has been queued. Follow its link within 24 hours.';
        $email = '';
    } catch (InvalidArgumentException $e) {
        $status = 'error';
        $message = $e->getMessage();
    } catch (ConsentRateLimitException $e) {
        http_response_code(429);
        $status = 'error';
        $message = $e->getMessage();
    } catch (ConsentConfigurationException $e) {
        http_response_code(503);
        $status = 'error';
        $message = 'Recipient enrollment is temporarily unavailable.';
    } catch (Throwable $e) {
        error_log('Secure Dice consent request error: ' . $e->getMessage());
        http_response_code(503);
        $status = 'error';
        $message = 'Recipient enrollment is temporarily unavailable.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Recipient opt-in — Secure Dice</title>
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
                <span class="badge badge-primary">Recipient Opt-in</span>
            </div>
        </div>
        <p class="site-subtitle">Choose whether Secure Dice may send results to your address.</p>
    </div>
</header>

<main>
    <section class="card consent-card" aria-labelledby="consent-title">
        <h2 id="consent-title">Request a recipient code</h2>
        <p>Your address is encrypted at rest. It is never included in the code you share with a roller.</p>

        <?php if ($message !== ''): ?>
            <div class="consent-notice is-<?= h($status) ?>" role="<?= $status === 'error' ? 'alert' : 'status' ?>">
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <form class="consent-form" method="post" action="recipient.php">
            <input type="hidden" name="csrf_token" value="<?= h(consent_csrf_token()) ?>">
            <label for="recipient-email">Email address</label>
            <input
                id="recipient-email"
                name="email"
                type="email"
                value="<?= h($email) ?>"
                maxlength="254"
                autocomplete="email"
                inputmode="email"
                required
            >
            <p class="field-help">The confirmation link expires after 24 hours.</p>
            <button class="sd2-action-btn primary inline-action" type="submit">Request Confirmation</button>
        </form>
    </section>

    <p><a class="sd2-action-btn neutral inline-action" href="securedice.php">Back to Secure Dice</a></p>
</main>

<footer class="site-footer" role="contentinfo">
    Copyright &copy; 2005-2026 Brandon Blackmoor<br>
    Source: <a href="https://github.com/bblackmoor/securedice">github.com/bblackmoor/securedice</a><br>
    Last updated: <?= h(app_updated_date()) ?> (version <?= h(app_version()) ?>)
</footer>
</body>
</html>

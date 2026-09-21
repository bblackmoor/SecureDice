<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/email.php';

header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
send_security_headers();

app_start_session();

$accepted = false;
$error = '';
$resultId = trim((string) ($_POST['result_id'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $error = 'Submit recipient addresses from a stored result page.';
} else {
    try {
        validate_consent_csrf((string) ($_POST['csrf_token'] ?? ''));
        request_result_email(
            $resultId,
            (string) ($_POST['recipients'] ?? ''),
            consent_source_ip()
        );
        $accepted = true;
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        $error = $e->getMessage();
    } catch (ConsentRateLimitException $e) {
        http_response_code(429);
        $error = 'Too many email requests. Please wait and try again.';
    } catch (InvalidResultIdException $e) {
        http_response_code(400);
        $error = 'The stored result could not be found.';
    } catch (Throwable $e) {
        error_log('Secure Dice result email request error: ' . $e->getMessage());
        http_response_code(503);
        $error = 'Result email is temporarily unavailable.';
    }
}

$returnPath = preg_match('/^[a-f0-9]{32}$/', strtolower($resultId)) === 1
    ? 'verify.php?id=' . rawurlencode(strtolower($resultId))
    : 'securedice.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Email result — Secure Dice</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <link rel="stylesheet" href="securedice.base.css">
    <link rel="stylesheet" href="securedice.mobile.css">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to main content</a>
<header class="site-header" role="banner">
    <div class="site-header-inner">
        <div class="site-title-wrap">
            <h1 class="site-title">Secure Dice</h1>
            <div class="site-badges" aria-label="Status">
                <span class="badge">Private</span>
                <span class="badge badge-primary">Result Email</span>
            </div>
        </div>
    </div>
</header>

<main id="main-content" tabindex="-1">
    <section class="card consent-card" aria-labelledby="email-status-title">
        <?php if ($accepted): ?>
            <h2 id="email-status-title">Email request accepted</h2>
            <div class="consent-notice is-success" role="status">Secure Dice accepted the request and will deliver the result to eligible, opted-in addresses.</div>
            <p>For recipient privacy, this page does not identify which addresses are registered, paused, revoked, or currently limited.</p>
        <?php else: ?>
            <h2 id="email-status-title">Email request unavailable</h2>
            <div class="consent-notice is-error" role="alert"><?= h($error) ?></div>
        <?php endif; ?>
        <p><a class="sd2-action-btn primary inline-action" href="<?= h($returnPath) ?>">Return to Result</a></p>
    </section>
</main>

<footer class="site-footer" role="contentinfo">
    Copyright &copy; 2005-2026 Brandon Blackmoor<br>
    Source: <a href="https://github.com/bblackmoor/securedice">github.com/bblackmoor/securedice</a><br>
    Last updated: <?= h(app_updated_date()) ?> (version <?= h(app_version()) ?>)
</footer>
</body>
</html>

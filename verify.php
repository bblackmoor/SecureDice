<?php
// verify.php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/storage.php';

send_security_headers();
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$rawId = filter_input(INPUT_GET, 'id', FILTER_UNSAFE_RAW);
$resultId = is_string($rawId) ? trim($rawId) : '';
$rawFormat = filter_input(INPUT_GET, 'format', FILTER_UNSAFE_RAW);
$format = is_string($rawFormat) ? strtolower(trim($rawFormat)) : '';

$statusCode = 200;
$statusKind = 'prompt';
$statusTitle = 'Verify a Secure Dice result';
$statusMessage = 'Enter the result ID from a Secure Dice verification link or result page.';
$record = null;

if ($resultId !== '') {
    try {
        $record = load_result_record($resultId);

        if ($record === null) {
            $statusCode = 404;
            $statusKind = 'not-found';
            $statusTitle = 'Result not found';
            $statusMessage = 'This server has no stored result with that ID. Check the complete ID and try again.';
        }
    } catch (InvalidResultIdException $e) {
        $statusCode = 400;
        $statusKind = 'invalid';
        $statusTitle = 'Invalid result ID';
        $statusMessage = 'A Secure Dice result ID contains exactly 32 hexadecimal characters.';
    } catch (ResultIntegrityException $e) {
        $statusCode = 500;
        $statusKind = 'failed';
        $statusTitle = 'Verification failed';
        $statusMessage = 'The stored record failed its integrity check and cannot be presented as authentic.';
    } catch (UnsupportedResultSchemaException $e) {
        $statusCode = 422;
        $statusKind = 'unsupported';
        $statusTitle = 'Unsupported result record';
        $statusMessage = 'This result uses a record format that this version of Secure Dice cannot display.';
    } catch (Throwable $e) {
        error_log('Secure Dice verification error: ' . $e->getMessage());
        $statusCode = 503;
        $statusKind = 'unavailable';
        $statusTitle = 'Verification temporarily unavailable';
        $statusMessage = 'The result database could not be read. Please try again later.';
    }
}

if (is_array($record)) {
    if ($format === 'json') {
        header('Content-Type: application/json; charset=UTF-8');
        header(
            'Content-Disposition: attachment; filename="SecureDice-'
            . strtolower($resultId)
            . '.json"'
        );
        echo $record['canonical_json'];
        exit;
    }

    $data = $record['data'];
    $canonicalJson = $record['canonical_json'];
    $storedAt = $record['stored_at'];
    $resultViewMode = 'verified';

    require __DIR__ . '/result-view.php';
    exit;
}

http_response_code($statusCode);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= h($statusTitle) ?> — Secure Dice</title>
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
                <span class="badge">Cryptographic</span>
                <span class="badge">Immutable</span>
                <span class="badge badge-primary">Server Verification</span>
            </div>
        </div>
        <p class="site-subtitle">Retrieve the server's authoritative copy of a stored dice result.</p>
    </div>
</header>

<main id="main-content" tabindex="-1">
    <section class="card verification-status <?= $statusKind === 'prompt' ? 'is-prompt' : 'is-error' ?>" aria-labelledby="verification-title" <?= $statusKind === 'prompt' ? '' : 'role="alert"' ?>>
        <h2 id="verification-title"><?= h($statusTitle) ?></h2>
        <p><?= h($statusMessage) ?></p>

        <form class="verification-form" method="get" action="verify.php">
            <label for="verification-id">Result ID</label>
            <div class="verification-form-row">
                <input
                    id="verification-id"
                    name="id"
                    type="text"
                    value="<?= h($resultId) ?>"
                    maxlength="32"
                    pattern="[A-Fa-f0-9]{32}"
                    autocomplete="off"
                    autocapitalize="none"
                    spellcheck="false"
                    required
                    aria-describedby="verification-id-help"
                >
                <button class="sd2-action-btn primary" type="submit">Verify Result</button>
            </div>
            <div id="verification-id-help" class="field-help">32 hexadecimal characters</div>
        </form>
    </section>

    <p><a class="sd2-action-btn neutral inline-action" href="securedice.php">Roll Dice</a></p>
</main>

<footer class="site-footer" role="contentinfo">
    Copyright &copy; 2005-2026 Brandon Blackmoor
    <a href="mailto:bblackmoor@blackgate.net?subject=Secure%20Dice">&lt;bblackmoor@blackgate.net&gt;</a><br>
    Licensed under the GNU General Public License v3.0:
    <a href="https://www.gnu.org/licenses/gpl-3.0.en.html">https://www.gnu.org/licenses/gpl-3.0.en.html</a><br>
    Source: <a href="https://github.com/bblackmoor/securedice">https://github.com/bblackmoor/securedice</a><br>
    Last updated: <?= h(app_updated_date()) ?>
    (version <?= h(app_version()) ?>)
</footer>

</body>
</html>

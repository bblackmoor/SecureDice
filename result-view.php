<?php
// Shared presentation for newly generated and independently verified results.

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/results-functions.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/consent.php';

app_start_session();

if (!isset($data, $canonicalJson, $resultViewMode) || !is_array($data)) {
    throw new RuntimeException('Result view data was not provided.');
}

$isVerifiedView = ($resultViewMode === 'verified');
$resultId = (string) $data['result_id'];
$verificationPath = 'verify.php?id=' . rawurlencode($resultId);
$verificationUrl = build_absolute_url($verificationPath);
$downloadPath = $verificationPath . '&format=json';

$generatedAt = new DateTimeImmutable((string) $data['generated_at']);
$generatedAtDisplay = $generatedAt
    ->setTimezone(new DateTimeZone('UTC'))
    ->format('Y-m-d H:i:s \U\T\C');

$specification = $data['specification'];
$summary = $data['summary'];
$sets = $data['sets'];
$pools = $specification['pools'];
$poolA = $pools['A'];
$poolB = is_array($pools['B'] ?? null) ? $pools['B'] : null;
$repeat = (int) $specification['repeat'];
$sortResults = !empty($specification['sort_results']);
$isFudgeOutput = ((string) ($poolA['die_kind'] ?? 'normal') === 'fudge');

$displaySets = $sets;

if ($sortResults) {
    usort($displaySets, function (array $a, array $b): int {
        $totalComparison = (int) $b['total'] <=> (int) $a['total'];

        if ($totalComparison !== 0) {
            return $totalComparison;
        }

        return (int) $a['number'] <=> (int) $b['number'];
    });
}

$modeLabels = [
    'sum' => 'sum them all',
    'drop_lowest' => 'drop the lowest die',
    'drop_highest' => 'drop the highest die',
    'wild' => 'wild die',
    'stunt' => 'stunt die',
];
$modeToAd = [
    'sum' => 'none',
    'drop_lowest' => 'lowest',
    'drop_highest' => 'highest',
    'wild' => 'wild',
    'stunt' => 'stunt',
];

$labelA = (string) ((int) ($poolA['dice_count'] ?? 0))
    . (string) ($poolA['die_label'] ?? '');
$modeALabel = $modeLabels[(string) ($poolA['mode'] ?? '')] ?? '';

$query = [
    'aq' => (int) ($poolA['dice_count'] ?? 3),
    'am' => (int) ($poolA['modifier'] ?? 0),
    'ad' => $modeToAd[(string) ($poolA['mode'] ?? 'sum')] ?? 'none',
];

if ($isFudgeOutput) {
    $query['af'] = 1;
    $query['as'] = 6;
} else {
    $query['as'] = (int) ($poolA['sides'] ?? 6);
}

$labelB = '';
$modeBLabel = '';
$poolBOperator = 1;

if ($poolB !== null) {
    $poolBOperator = ((int) ($poolB['operator'] ?? 1) < 0) ? -1 : 1;
    $labelB = (string) ((int) ($poolB['dice_count'] ?? 0))
        . (string) ($poolB['die_label'] ?? '');
    $modeBLabel = $modeLabels[(string) ($poolB['mode'] ?? '')] ?? '';

    $query['bq'] = $poolBOperator * (int) ($poolB['dice_count'] ?? 0);
    $query['bs'] = (int) ($poolB['sides'] ?? 6);
    $query['bm'] = (int) ($poolB['modifier'] ?? 0);
    $query['bd'] = $modeToAd[(string) ($poolB['mode'] ?? 'sum')] ?? 'none';
}

$query['dt'] = $repeat;

if ($sortResults) {
    $query['sdt'] = 1;
}

$rollAgainUrl = 'securedice.php?'
    . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
$rollAgainAbsoluteUrl = build_absolute_url($rollAgainUrl);
$showLimit = 200;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= $isVerifiedView ? 'Verified Secure Dice Result' : 'Secure Dice Results' ?></title>
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
                <span class="badge">Cryptographic</span>
                <span class="badge">Immutable</span>
                <span class="badge badge-primary">Server Verified</span>
            </div>
        </div>

        <p class="site-subtitle">
            <?= $isVerifiedView
                ? 'An authenticated result retrieved from the Secure Dice server.'
                : 'Your roll results and persistent server-verification link.' ?>
        </p>
    </div>
</header>

<main id="main-content" tabindex="-1">

<section class="card verification-status is-verified" aria-labelledby="verification-title" role="status">
    <h2 id="verification-title">
        <?= $isVerifiedView ? 'Verified Secure Dice result' : 'Stored Secure Dice result' ?>
    </h2>
    <p>
        <?php if ($isVerifiedView): ?>
            This page was reconstructed from the immutable result retained by this Secure Dice server.
        <?php else: ?>
            This roll has been saved as an immutable result. Share the verification link so others can retrieve the server's authoritative copy.
        <?php endif; ?>
    </p>
    <dl class="result-metadata">
        <div>
            <dt>Result ID</dt>
            <dd><code><?= h($resultId) ?></code></dd>
        </div>
        <div>
            <dt>Generated</dt>
            <dd><time datetime="<?= h((string) $data['generated_at']) ?>"><?= h($generatedAtDisplay) ?></time></dd>
        </div>
        <?php if ($isVerifiedView): ?>
            <div>
                <dt>Record status</dt>
                <dd>Integrity check passed</dd>
            </div>
        <?php endif; ?>
    </dl>
    <p class="verification-link">
        <b>Verification link:</b><br>
        <a href="<?= h($verificationPath) ?>"><code><?= h($verificationUrl) ?></code></a>
    </p>
</section>

<section class="card email-result-card" aria-labelledby="email-result-title">
    <h2 id="email-result-title">Email this result</h2>
    <p>Enter up to 10 recipient email addresses, separated by spaces, commas, or new lines. Delivery is attempted only for addresses that have confirmed their opt-in.</p>
    <form class="email-result-form" method="post" action="email-result.php">
        <input type="hidden" name="csrf_token" value="<?= h(consent_csrf_token()) ?>">
        <input type="hidden" name="result_id" value="<?= h($resultId) ?>">
        <label for="result-recipients">Recipient email addresses</label>
        <textarea
            id="result-recipients"
            name="recipients"
            rows="4"
            maxlength="3000"
            autocomplete="off"
            spellcheck="false"
            required
            aria-describedby="result-recipients-help"
        ></textarea>
        <p id="result-recipients-help" class="field-help">The request response does not identify which addresses are opted in. Delivered messages contain counts, never recipient names or addresses.</p>
        <button class="sd2-action-btn primary inline-action" type="submit">Send Result</button>
    </form>
</section>

<div class="card">
    <div class="results-info">
        <?= h((string) $repeat) ?> ×
        <span class="pill"><?= h(format_roll_summary(
            $labelA,
            (int) ($poolA['modifier'] ?? 0),
            $modeALabel
        )) ?></span>

        <?php if ($poolB !== null): ?>
            &nbsp; <?= h($poolBOperator < 0 ? 'minus' : 'plus') ?> &nbsp;
            <span class="pill"><?= h(format_roll_summary(
                $labelB,
                (int) ($poolB['modifier'] ?? 0),
                $modeBLabel
            )) ?></span>
        <?php endif; ?>
    </div>

    <div class="results-info">
        Summary (final totals):
        <?php if ($isFudgeOutput): ?>
            min <?= h(format_signed_int_txt((int) $summary['min'])) ?>,
            max <?= h(format_signed_int_txt((int) $summary['max'])) ?>,
            avg <?= h(format_signed_float((float) $summary['avg'], 2)) ?>
        <?php else: ?>
            min <?= h((string) $summary['min']) ?>,
            max <?= h((string) $summary['max']) ?>,
            avg <?= h(number_format((float) $summary['avg'], 2)) ?>
        <?php endif; ?>
    </div>
</div>

<div class="card results-card">
    <div class="result-sets">
        <?php foreach ($displaySets as $displayIndex => $set): ?>
            <?php if ($displayIndex >= $showLimit): ?>
                <?php break; ?>
            <?php endif; ?>

            <?php
            $setNumber = (int) $set['number'];
            $setTotal = (int) $set['total'];
            $terms = $set['terms'];
            ?>

            <section class="result-set" aria-labelledby="set-<?= h((string) $setNumber) ?>-title">
                <h2 class="result-set-title" id="set-<?= h((string) $setNumber) ?>-title">
                    Set <?= h((string) $setNumber) ?>
                </h2>

                <div class="expression-stack">
                    <?php foreach ($terms as $termIndex => $term): ?>
                        <?php
                        $result = $term['result'];
                        $operator = ($termIndex === 0)
                            ? 1
                            : (((int) ($term['operator'] ?? 1) < 0) ? -1 : 1);

                        echo render_pool_expression_html($result, $operator);
                        ?>
                    <?php endforeach; ?>
                </div>

                <div class="result-equation-total">
                    <span aria-hidden="true">=</span>
                    <strong><?= h(
                        $isFudgeOutput
                            ? format_signed_int_txt($setTotal)
                            : (string) $setTotal
                    ) ?></strong>
                </div>

                <?php foreach ($terms as $term): ?>
                    <?php $note = render_special_note_html($term['result']); ?>
                    <?php if ($note !== ''): ?>
                        <div class="result-special-note"><?= $note ?></div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <div class="results-info">
        <div><b>Roll-again preset:</b></div>
        <div><code><?= h($rollAgainAbsoluteUrl) ?></code></div>
    </div>
</div>

<div class="card">
    <div class="results-info">
        <label for="json-output"><b>Canonical result JSON:</b></label>
        <div>This is the exact result retained by the server.</div>
        <textarea id="json-output" class="json" readonly spellcheck="false" wrap="off"><?= h($canonicalJson) ?></textarea>
    </div>
</div>

<div class="floating-actions has-four-actions" role="group" aria-label="Result actions">
    <a class="sd2-action-btn primary" href="<?= h($rollAgainUrl) ?>">Roll Again</a>
    <?php if ($isVerifiedView): ?>
        <a class="sd2-action-btn neutral" href="<?= h($downloadPath) ?>">Download JSON</a>
    <?php else: ?>
        <a class="sd2-action-btn neutral" href="<?= h($verificationPath) ?>">View Verified</a>
    <?php endif; ?>
    <button class="sd2-action-btn neutral" type="button" id="copy-verification-url" data-copy="<?= h($verificationUrl) ?>">Copy Verification Link</button>
    <button class="sd2-action-btn neutral" type="button" id="copy-json">Copy JSON</button>
</div>
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

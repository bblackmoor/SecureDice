<?php
// results.php

declare(strict_types=1);

require_once __DIR__ . "/lib.php";
require_once __DIR__ . "/results-functions.php";

session_start();

$data = $_SESSION["last_roll"] ?? null;

if (!is_array($data) || (int) ($data["schema_version"] ?? 0) !== 2) {
    header("Location: securedice.php");
    exit;
}

$specification = is_array($data["specification"] ?? null)
    ? $data["specification"]
    : [];
$summary = is_array($data["summary"] ?? null)
    ? $data["summary"]
    : ["min" => 0, "max" => 0, "avg" => 0.0];
$sets = is_array($data["sets"] ?? null) ? $data["sets"] : [];
$pools = is_array($specification["pools"] ?? null)
    ? $specification["pools"]
    : [];
$poolA = is_array($pools["A"] ?? null) ? $pools["A"] : [];
$poolB = is_array($pools["B"] ?? null) ? $pools["B"] : null;
$repeat = (int) ($specification["repeat"] ?? count($sets));
$sortResults = !empty($specification["sort_results"]);
$isFudgeOutput = ((string) ($poolA["die_kind"] ?? "normal") === "fudge");

$displaySets = $sets;

if ($sortResults) {
    usort($displaySets, function (array $a, array $b): int {
        $totalComparison = (int) ($b["total"] ?? 0) <=> (int) ($a["total"] ?? 0);

        if ($totalComparison !== 0) {
            return $totalComparison;
        }

        return (int) ($a["number"] ?? 0) <=> (int) ($b["number"] ?? 0);
    });
}

$modeLabels = [
    "sum" => "sum them all",
    "drop_lowest" => "drop the lowest die",
    "drop_highest" => "drop the highest die",
    "wild" => "wild die",
    "stunt" => "stunt die",
];
$modeToAd = [
    "sum" => "none",
    "drop_lowest" => "lowest",
    "drop_highest" => "highest",
    "wild" => "wild",
    "stunt" => "stunt",
];

$labelA = (string) ((int) ($poolA["dice_count"] ?? 0))
    . (string) ($poolA["die_label"] ?? "");
$modeALabel = $modeLabels[(string) ($poolA["mode"] ?? "")] ?? "";

$qs = [
    "aq" => (int) ($poolA["dice_count"] ?? 3),
    "am" => (int) ($poolA["modifier"] ?? 0),
    "ad" => $modeToAd[(string) ($poolA["mode"] ?? "sum")] ?? "none",
];

if ($isFudgeOutput) {
    $qs["af"] = 1;
    $qs["as"] = 6;
} else {
    $qs["as"] = (int) ($poolA["sides"] ?? 6);
}

$labelB = "";
$modeBLabel = "";
$poolBOperator = 1;

if ($poolB !== null) {
    $poolBOperator = ((int) ($poolB["operator"] ?? 1) < 0) ? -1 : 1;
    $labelB = (string) ((int) ($poolB["dice_count"] ?? 0))
        . (string) ($poolB["die_label"] ?? "");
    $modeBLabel = $modeLabels[(string) ($poolB["mode"] ?? "")] ?? "";

    $qs["bq"] = $poolBOperator * (int) ($poolB["dice_count"] ?? 0);
    $qs["bs"] = (int) ($poolB["sides"] ?? 6);
    $qs["bm"] = (int) ($poolB["modifier"] ?? 0);
    $qs["bd"] = $modeToAd[(string) ($poolB["mode"] ?? "sum")] ?? "none";
}

$qs["dt"] = $repeat;

if ($sortResults) {
    $qs["sdt"] = 1;
}

$rollAgainUrl = "securedice.php?" . http_build_query($qs, "", "&", PHP_QUERY_RFC3986);
$rollAgainAbs = build_absolute_url($rollAgainUrl);
$exampleUrl = build_absolute_url("securedice.php")
    . "?"
    . http_build_query($qs, "", "&", PHP_QUERY_RFC3986);

$jsonForUser = json_encode(
    $data,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);

if ($jsonForUser === false) {
    $jsonForUser = "";
}

$jsonMd5 = md5($jsonForUser);
$showLimit = 200;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Secure Dice Results</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
                <span class="badge">Cryptographic</span>
                <span class="badge">Auditable</span>
                <span class="badge badge-primary">Predictable URLs</span>
            </div>
        </div>

        <p class="site-subtitle">
            Your roll results, summary stats, and a shareable preset URL.
        </p>
        <p class="site-subtitle">
            Tip: Bookmark preset URLs for your most frequently used rolls.
        </p>
    </div>
</header>

<div class="card">
    <div class="results-info">
        <?= h((string) $repeat) ?> ×
        <span class="pill"><?= h(format_roll_summary(
            $labelA,
            (int) ($poolA["modifier"] ?? 0),
            $modeALabel
        )) ?></span>

        <?php if ($poolB !== null): ?>
            &nbsp; <?= h($poolBOperator < 0 ? "minus" : "plus") ?> &nbsp;
            <span class="pill"><?= h(format_roll_summary(
                $labelB,
                (int) ($poolB["modifier"] ?? 0),
                $modeBLabel
            )) ?></span>
        <?php endif; ?>
    </div>

    <div class="results-info">
        Summary (final totals):
        <?php if ($isFudgeOutput): ?>
            min <?= h(format_signed_int_txt((int) ($summary["min"] ?? 0))) ?>,
            max <?= h(format_signed_int_txt((int) ($summary["max"] ?? 0))) ?>,
            avg <?= h(format_signed_float((float) ($summary["avg"] ?? 0.0), 2)) ?>
        <?php else: ?>
            min <?= h((string) ($summary["min"] ?? 0)) ?>,
            max <?= h((string) ($summary["max"] ?? 0)) ?>,
            avg <?= h(number_format((float) ($summary["avg"] ?? 0.0), 2)) ?>
        <?php endif; ?>
    </div>
</div>

<div class="card results-card">
    <div class="result-sets">
        <?php foreach ($displaySets as $displayIndex => $set): ?>
            <?php if ($displayIndex >= $showLimit || !is_array($set)): ?>
                <?php break; ?>
            <?php endif; ?>

            <?php
            $setNumber = (int) ($set["number"] ?? ($displayIndex + 1));
            $setTotal = (int) ($set["total"] ?? 0);
            $terms = is_array($set["terms"] ?? null) ? $set["terms"] : [];
            ?>

            <section class="result-set" aria-labelledby="set-<?= h((string) $setNumber) ?>-title">
                <h2 class="result-set-title" id="set-<?= h((string) $setNumber) ?>-title">
                    Set <?= h((string) $setNumber) ?>
                </h2>

                <div class="expression-stack">
                    <?php foreach ($terms as $termIndex => $term): ?>
                        <?php
                        if (!is_array($term)) {
                            continue;
                        }

                        $result = is_array($term["result"] ?? null) ? $term["result"] : [];
                        $operator = ($termIndex === 0)
                            ? 1
                            : (((int) ($term["operator"] ?? 1) < 0) ? -1 : 1);

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
                    <?php
                    $result = is_array($term["result"] ?? null) ? $term["result"] : [];
                    $note = render_special_note_html($result);
                    ?>
                    <?php if ($note !== ""): ?>
                        <div class="result-special-note"><?= $note ?></div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <div class="results-info">
        <div><b>Example URL:</b></div>
        <div><code><?= h($exampleUrl) ?></code></div>
    </div>
</div>

<div class="card">
    <div class="results-info">
        <div><b>Dice results JSON:</b></div>
        <div>Copy this JSON (and its MD5 hash) to store or verify rolls later.</div>
        <textarea id="json-output" class="json" readonly spellcheck="false"><?= h($jsonForUser) ?></textarea>
        <div><b>MD5:</b> <code><?= h($jsonMd5) ?></code></div>
    </div>
</div>

<div class="floating-actions" aria-label="Quick actions">
    <a class="sd2-action-btn primary" href="<?= h($rollAgainUrl) ?>">Roll Again</a>
    <button class="sd2-action-btn neutral" type="button" id="copy-url" data-copy="<?= h($rollAgainAbs) ?>">Copy URL</button>
    <button class="sd2-action-btn neutral" type="button" id="copy-json">Copy JSON</button>
    <button class="sd2-action-btn neutral" type="button" id="copy-md5" data-copy="<?= h($jsonMd5) ?>">Copy Hash</button>
</div>

<footer class="site-footer" role="contentinfo">
    Copyright &copy; 2005-2026 Brandon Blackmoor
    <a href="mailto:bblackmoor@blackgate.net?subject=Secure%20Dice">&lt;bblackmoor@blackgate.net&gt;</a><br>
    Licensed under the GNU General Public License v3.0:
    <a href="https://www.gnu.org/licenses/gpl-3.0.en.html">https://www.gnu.org/licenses/gpl-3.0.en.html</a><br>
    Source: <a href="https://github.com/bblackmoor/securedice">https://github.com/bblackmoor/securedice</a><br>
    Version: <?= h(app_version()) ?><br>
    Last updated: <?= h(date("Y-m-d", filemtime(__FILE__))) ?>
</footer>

</body>
</html>

<?php
// results.php

declare(strict_types=1);

require_once __DIR__ . "/lib.php";
require_once __DIR__ . "/results-functions.php";

session_start();

$data = $_SESSION["last_roll"] ?? null;

if (!is_array($data)) {
    header("Location: securedice.php");
    exit;
}

$input = is_array($data["input"] ?? null)
    ? $data["input"]
    : [];

$summary = is_array($data["summary"] ?? null)
    ? $data["summary"]
    : [
        "min" => 0,
        "max" => 0,
        "avg" => 0.0,
    ];

$trials = is_array($data["trials"] ?? null)
    ? $data["trials"]
    : [];

$repeat = (int) ($input["repeat"] ?? count($trials));

$sortResults = !empty($input["sortResults"]);
$activeB = !empty($input["activeB"]);

$displayTrials = [];

foreach ($trials as $originalIndex => $trial) {
    if (!is_array($trial)) {
        continue;
    }

    $trial["_originalIndex"] = $originalIndex;
    $displayTrials[] = $trial;
}

if ($sortResults) {
    usort($displayTrials, function (array $a, array $b): int {
        $finalA = (int) ($a["final"] ?? 0);
        $finalB = (int) ($b["final"] ?? 0);

        if ($finalA !== $finalB) {
            return $finalB <=> $finalA;
        }

        return ((int) ($a["_originalIndex"] ?? 0)) <=> ((int) ($b["_originalIndex"] ?? 0));
    });
}

$modeLabels = [
    "sum" => "sum them all",
    "drop_lowest" => "drop the lowest die",
    "drop_highest" => "drop the highest die",
    "wild" => "wild die",
    "stunt" => "stunt die",
];

$emdashChar = "—";
$showLimit = 200;

$rollA = is_array($input["rollA"] ?? null)
    ? $input["rollA"]
    : [];

$rollB = is_array($input["rollB"] ?? null)
    ? $input["rollB"]
    : [];

$labelA = (string) ($rollA["diceCount"] ?? 0) . (string) ($rollA["dieLabel"] ?? "");
$modeALabel = $modeLabels[(string) ($rollA["mode"] ?? "")] ?? (string) ($rollA["mode"] ?? "");

$rollBSign = ((int) ($rollB["sign"] ?? 1) < 0) ? -1 : 1;
$rollBConnector = ($rollBSign < 0) ? "minus" : "plus";
$rollBDiceCountAbs = (int) ($rollB["diceCountAbs"] ?? abs((int) ($rollB["diceCount"] ?? 0)));

$labelB = (string) $rollBDiceCountAbs . (string) ($rollB["dieLabel"] ?? "");
$modeBLabel = $modeLabels[(string) ($rollB["mode"] ?? "")] ?? (string) ($rollB["mode"] ?? "");

$modeToAd = [
    "sum" => "none",
    "drop_lowest" => "lowest",
    "drop_highest" => "highest",
    "wild" => "wild",
    "stunt" => "stunt",
];

$qs = [];

$qs["aq"] = (int) ($rollA["diceCount"] ?? 3);
$qs["am"] = (int) ($rollA["mod"] ?? 0);
$qs["ad"] = $modeToAd[(string) ($rollA["mode"] ?? "sum")] ?? "none";

if (($rollA["dieKind"] ?? "normal") === "fudge") {
    $qs["af"] = 1;
    $qs["as"] = 6;
} else {
    $qs["as"] = (int) ($rollA["sides"] ?? 6);
}

if ($activeB) {
    $qs["bq"] = (int) ($rollB["diceCount"] ?? 0);
    $qs["bs"] = (int) ($rollB["sides"] ?? 6);
    $qs["bm"] = (int) ($rollB["mod"] ?? 0);
    $qs["bd"] = $modeToAd[(string) ($rollB["mode"] ?? "sum")] ?? "none";
}

$qs["dt"] = (int) $repeat;

if ($sortResults) {
    $qs["sdt"] = 1;
}

$rollAgainUrl = "securedice.php?" . http_build_query($qs, "", "&", PHP_QUERY_RFC3986);
$rollAgainAbs = build_absolute_url($rollAgainUrl);

$rollPageUrl = build_absolute_url("securedice.php");
$exampleUrl = $rollPageUrl . "?" . http_build_query($qs, "", "&", PHP_QUERY_RFC3986);

$jsonForUser = json_encode(
    $data,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);

if ($jsonForUser === false) {
    $jsonForUser = "";
}

$jsonMd5 = md5($jsonForUser);

$isFudgeOutput = (($rollA["dieKind"] ?? "normal") === "fudge");
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
            Tip: Bookmark a preset URL once you've dialed in your favorite roll.
        </p>
    </div>
</header>

<div class="card">
    <div class="results-info">
        <?= h((string) $repeat) ?> ×
        <span class="pill"><?= h(format_roll_summary($labelA, (int) ($rollA["mod"] ?? 0), $modeALabel)) ?></span>

        <?php if ($activeB): ?>
            &nbsp; <?= h($rollBConnector) ?> &nbsp;
            <span class="pill"><?= h(format_roll_summary($labelB, (int) ($rollB["mod"] ?? 0), $modeBLabel)) ?></span>
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

<div class="card">
    <table class="results-table">
        <tbody>
            <?php foreach ($displayTrials as $i => $t): ?>
                <?php if ($i >= $showLimit): ?>
                    <?php break; ?>
                <?php endif; ?>

                <?php
                $rA = is_array($t["rollA"] ?? null) ? $t["rollA"] : [];
                $rB = is_array($t["rollB"] ?? null) ? $t["rollB"] : null;
                $final = (int) ($t["final"] ?? 0);

                $setNum = (string) ((int) ($t["_originalIndex"] ?? $i) + 1);
                $setLabelA = "Set " . $setNum;
                $setLabelB = $rollBConnector;

                $itemsA = build_dice_items($rA);
                ?>

                <tr class="results-a">
                    <td class="col-num"><?= h($setLabelA) ?></td>

                    <td class="col-dice">
                        <div class="dice-chip-wrap">
                            <?php foreach ($itemsA as $it): ?>
                                <?php
                                echo render_die_chip_html($it, $emdashChar);
                                ?>
                            <?php endforeach; ?>
                        </div>
                    </td>

                    <td class="col-details">
                        <?php
                        $specialDetailA = render_special_detail($rA);

                        if ($specialDetailA !== "") {
                            echo $specialDetailA . "<br>";
                        }

                        $detailTotalA = get_detail_roll_total($rA);
                        $modifierA = (int) ($rA["mod"] ?? 0);

                        echo render_detail_expression(
                            $detailTotalA,
                            $modifierA
                        );
                        ?>
                    </td>

                    <td class="col-total<?= !is_array($rB) ? " is-final" : "" ?>">
                        <?php
                        $totalFinalA = get_detail_roll_total($rA) + (int) ($rA["mod"] ?? 0);

                        if (!is_array($rB)) {
                            echo "<b>";
                        }

                        echo "= ";

                        echo h(
                            ((string) ($rA["die_kind"] ?? "normal") === "fudge")
                                ? format_signed_int_txt($totalFinalA)
                                : (string) $totalFinalA
                        );

                        if (!is_array($rB)) {
                            echo "</b>";
                        }
                        ?>
                    </td>
                </tr>

                <?php if (is_array($rB)): ?>
                    <?php
                    $itemsB = build_dice_items($rB);
                    ?>
                    <tr class="results-b">
                        <td class="col-num"><?= h($setLabelB) ?></td>

                        <td class="col-dice">
                            <div class="dice-chip-wrap">
                                <?php foreach ($itemsB as $it): ?>
                                    <?php
                                    echo render_die_chip_html($it, $emdashChar);
                                    ?>
                                <?php endforeach; ?>
                            </div>
                        </td>

                        <td class="col-details">
                            <?php
                            $detailTotalB = get_detail_roll_total($rB);
                            $modifierB = (int) ($rB["mod"] ?? 0);

                            $rowBPrefix = ($rollBSign < 0)
                                ? '<span class="mod-penalty">- </span>'
                                : '<span class="mod-bonus">+ </span>';

                            echo $rowBPrefix;

                            echo render_roll_b_detail_expression(
                                $detailTotalB,
                                $modifierB
                            );
                            ?>
                        </td>

                        <td class="col-total is-final">
                            <?php
                            $displayTotalB = $final;

                            $specialB = is_array($rB["special"] ?? null)
                                ? $rB["special"]
                                : null;

                            if ($specialB && (string) ($specialB["kind"] ?? "") === "wild") {
                                $displayTotalB = (int) ($rB["total_final"] ?? $final);

                                if (!empty($specialB["complication"])) {
                                    $removedValue = (int) ($specialB["removed_highest"] ?? 0);
                                    $baseTotalB = (int) ($rB["total_final"] ?? 0);

                                    $displayTotalB = $baseTotalB - $removedValue;
                                }
                            }

                            echo "<b>= " . h(
                                $isFudgeOutput
                                    ? format_signed_int_txt($displayTotalB)
                                    : (string) $displayTotalB
                            ) . "</b>";
                            ?>
                        </td>
                    </tr>
                <?php endif; ?>

            <?php endforeach; ?>
        </tbody>
    </table>
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
    Last updated: <?= h(date("Y-m-d", filemtime(__FILE__))) ?>
</footer>

</body>
</html>

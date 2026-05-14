<?php
// results.php

declare(strict_types=1);

require_once __DIR__ . "/lib.php";

session_start();

$data = $_SESSION["last_roll"] ?? null;

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, "UTF-8");
}

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

$repeat = (int) ($input["repeat"] ?? 1);
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

$negativeChar = "-";
$emdashChar = "—";

function split_signed_int(int $v): array {
    return [
        "is_negative" => ($v < 0),
        "abs" => abs($v),
        "sign" => ($v < 0) ? "-" : "+",
    ];
}

function format_signed_int_txt(int $v): string {
    $parts = split_signed_int($v);

    if ($parts["is_negative"]) {
        return "- " . (string) $parts["abs"];
    }

    return "+ " . (string) $parts["abs"];
}

function format_roll_summary(string $label, int $mod, string $modeLabel): string {
    $out = $label;

    if ($mod !== 0) {
        $out .= " " . format_signed_int_txt($mod);
    }

    if ($modeLabel !== "") {
        $out .= ", " . $modeLabel;
    }

    return $out;
}

function format_signed_float(float $v, int $decimals): string {
    $txt = number_format($v, $decimals);

    if ($v >= 0) {
        return "+" . $txt;
    }

    return $txt;
}

function make_token(string $text, array $classes = [], ?string $tag = null): array {
    return [
        "text" => $text,
        "classes" => $classes,
        "tag" => $tag,
    ];
}

function render_tokens_html(array $tokens): string {
    $out = "";

    foreach ($tokens as $t) {
        $text = h((string) ($t["text"] ?? ""));
        $classes = is_array($t["classes"] ?? null) ? $t["classes"] : [];
        $tag = $t["tag"] ?? null;

        $classes = array_values(array_filter(array_map("strval", $classes), function (string $c): bool {
            return ($c !== "");
        }));

        if ($tag === "b") {
            if ($classes) {
                $out .= "<b class=\"" . h(implode(" ", $classes)) . "\">" . $text . "</b>";
            } else {
                $out .= "<b>" . $text . "</b>";
            }

            continue;
        }

        if ($tag === "span" || $classes) {
            $classAttr = $classes ? " class=\"" . h(implode(" ", $classes)) . "\"" : "";
            $out .= "<span" . $classAttr . ">" . $text . "</span>";
            continue;
        }

        $out .= $text;
    }

    return $out;
}

function dice_item_classlist(array $item, bool $includeSpecial): array {
    $classes = [];

    if (!empty($item["is_dropped"])) {
        $classes[] = "muted";
    }

    if ($includeSpecial) {
        if (
            !empty($item["is_stunt"])
            || !empty($item["is_wild_initial"])
            || !empty($item["is_wild_consequence"])
        ) {
            $classes[] = "die-special";
        }
    }

    return $classes;
}

function sort_dice_items(array $items): array {
    $items = array_values($items);

    usort($items, function (array $a, array $b): int {
        $av = (int) ($a["value"] ?? 0);
        $bv = (int) ($b["value"] ?? 0);

        if ($av === $bv) {
            $ai = (int) ($a["orig_index"] ?? 0);
            $bi = (int) ($b["orig_index"] ?? 0);

            return $ai <=> $bi;
        }

        return $bv <=> $av;
    });

    return $items;
}

function format_dice_tokens(array $items, bool $sorted, string $context): array {
    $items = $sorted ? sort_dice_items($items) : array_values($items);

    $tokens = [];

    if ($context === "details_fudge") {
        foreach ($items as $idx => $it) {
            $v = (int) ($it["value"] ?? 0);
            $parts = split_signed_int($v);

            $classes = dice_item_classlist($it, false);

            $tokens[] = make_token($parts["sign"], array_merge($classes, ["die-special"]), "span");
            $tokens[] = make_token((string) $parts["abs"], array_merge($classes, ["die-special"]), "span");

            if ($idx < count($items) - 1) {
                $tokens[] = make_token(" ");
            }
        }

        return $tokens;
    }

    $sep = " ";

    if ($context === "details_sum") {
        $sep = " + ";
    }

    foreach ($items as $idx => $it) {
        $v = (int) ($it["value"] ?? 0);
        $isFudge = !empty($it["is_fudge"]);

        $classes = dice_item_classlist($it, true);

        if ($context === "cell" && $isFudge) {
            $parts = split_signed_int($v);

            $tokens[] = make_token($parts["sign"], $classes, "span");
            $tokens[] = make_token(" ");

            $valueTag = (!empty($it["is_dropped"]) || in_array("die-special", $classes, true))
                ? "span"
                : "b";

            $tokens[] = make_token((string) $parts["abs"], $classes, $valueTag);
        } else {
            $valueTag = (!empty($it["is_dropped"]) || in_array("die-special", $classes, true))
                ? "span"
                : "b";

            $tokens[] = make_token((string) $v, $classes, $valueTag);
        }

        if ($idx < count($items) - 1) {
            $tokens[] = make_token($sep);
        }
    }

    return $tokens;
}

function format_dice_html(array $items, bool $sorted, string $context): string {
    $tokens = format_dice_tokens($items, $sorted, $context);

    return render_tokens_html($tokens);
}

function render_die_chip_html(?array $item, string $emdashChar): string {
    if (!is_array($item)) {
        return "<span class=\"die-chip is-empty\">" . h($emdashChar) . "</span>";
    }

    $classes = ["die-chip"];

    if (!empty($item["is_dropped"])) {
        $classes[] = "is-dropped";
    }

    if (
        !empty($item["is_stunt"])
        || !empty($item["is_wild_initial"])
        || !empty($item["is_wild_consequence"])
    ) {
        $classes[] = "is-special";
    }

    $text = (string) ((int) ($item["value"] ?? 0));

    if (!empty($item["is_fudge"])) {
        $parts = split_signed_int((int) ($item["value"] ?? 0));
        $text = $parts["sign"] . " " . (string) $parts["abs"];
    }

    return "<span class=\"" . h(implode(" ", $classes)) . "\">" . h($text) . "</span>";
}

function render_modifier_html(int $mod): string {
    if ($mod === 0) {
        return "";
    }

    $parts = split_signed_int($mod);

    $spanClass = $parts["is_negative"]
        ? "mod-penalty"
        : "mod-bonus";

    $sign = h($parts["sign"]);
    $abs = h((string) $parts["abs"]);

    return " <span class=\"" . h($spanClass) . "\">" . $sign . " " . $abs . "</span>";
}

function build_dice_items(array $r): array {
    $rolls = is_array($r["rolls"] ?? null) ? $r["rolls"] : [];
    $kind = (string) ($r["die_kind"] ?? "normal");

    $special = is_array($r["special"] ?? null) ? $r["special"] : null;

    $droppedIndices = is_array($r["dropped_indices"] ?? null)
        ? $r["dropped_indices"]
        : [];

    $droppedIndexSet = [];

    foreach ($droppedIndices as $di) {
        $droppedIndexSet[(int) $di] = true;
    }

    $specialKind = $special ? (string) ($special["kind"] ?? "") : "";
    $specialIndex = $special ? (int) ($special["index"] ?? -1) : -1;

    $items = [];

    foreach ($rolls as $i => $v) {
        $isSpecial = ($specialKind === "stunt" || $specialKind === "wild") && ($i === $specialIndex);

        $items[] = [
            "value" => (int) $v,
            "orig_index" => (int) $i,
            "is_dropped" => !empty($droppedIndexSet[(int) $i]),
            "is_fudge" => ($kind === "fudge"),
            "is_stunt" => ($isSpecial && $specialKind === "stunt"),
            "is_wild_initial" => ($isSpecial && $specialKind === "wild"),
            "is_wild_consequence" => false,
        ];
    }

    return $items;
}

function get_wild_die_value(array $r, array $special): int {
    $rolls = is_array($r["rolls"] ?? null)
        ? $r["rolls"]
        : [];

    $wildIndex = (int) ($special["index"] ?? -1);

    if ($wildIndex >= 0 && array_key_exists($wildIndex, $rolls)) {
        return (int) $rolls[$wildIndex];
    }

    $seq = is_array($special["wild_seq"] ?? null)
        ? array_values($special["wild_seq"])
        : [];

    if ($seq) {
        return (int) $seq[0];
    }

    return 0;
}

function render_special_detail(array $r, bool $sortResults): string {
    $special = is_array($r["special"] ?? null) ? $r["special"] : null;
    $kind = (string) ($r["die_kind"] ?? "normal");
    $mode = (string) ($r["mode"] ?? "sum");
    $mod = (int) ($r["mod"] ?? 0);

    if ($kind === "fudge") {
        if ($mod === 0) {
            $baseTotal = (int) ($r["total_final"] ?? 0);
            return h((string) $baseTotal);
        }

        $baseTotal = (int) ($r["total_final"] ?? 0) - $mod;
        return h((string) $baseTotal) . render_modifier_html($mod);
    }

    if ($special && (string) ($special["kind"] ?? "") === "wild") {
        if (!empty($special["complication"])) {
            $removed = $special["removed_highest"] ?? null;
            $removedValue = ($removed === null)
                ? 0
                : (int) $removed;

            return "Wild first roll: "
                . "<span class=\"die-special\">1</span>"
                . " (complication)<br>"
                . "Removed highest die: "
                . "<b>" . h((string) $removedValue) . "</b>";
        }

        $wildValue = get_wild_die_value($r, $special);

        return "Wild die: "
            . "<span class=\"die-special\">"
            . h((string) $wildValue)
            . "</span>";
    }

    if ($special && (string) ($special["kind"] ?? "") === "stunt") {
        $stuntValue = (int) ($r["stunt_value"] ?? 0);

        return "Stunt die: <span class=\"die-special\">" . h((string) $stuntValue) . "</span>";
    }

    if ($mode === "sum" || $mode === "drop_lowest" || $mode === "drop_highest") {
        if ($mod === 0) {
            $baseTotal = (int) ($r["total_final"] ?? 0);
            return h((string) $baseTotal);
        }

        $baseTotal = (int) ($r["total_final"] ?? 0) - $mod;
        return h((string) $baseTotal) . render_modifier_html($mod);
    }

    return "";
}


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

$diceColumns = max(
    1,
    (int) ($rollA["diceCount"] ?? 1),
    $activeB ? $rollBDiceCountAbs : 0
);

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

                <!-- results.php -->

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
                        $diceTotalA = (int) ($rA["total"] ?? 0);
                        $modA = (int) ($rA["mod"] ?? 0);

                        $specialA = is_array($rA["special"] ?? null)
                            ? $rA["special"]
                            : null;

                        if (
                            $specialA
                            && (string) ($specialA["kind"] ?? "") === "wild"
                            && !empty($specialA["complication"])
                        ) {
                            $diceTotalA += (int) ($specialA["removed_highest"] ?? 0);
                        }

                        echo h((string) $diceTotalA);

                        if ($modA !== 0) {
                            echo " "
                                . h(($modA < 0) ? "-" : "+")
                                . " "
                                . h((string) abs($modA));
                        }

                        $specialDetailA = render_special_detail($rA, false);

                        if ($specialDetailA !== "") {
                            echo "<br>" . $specialDetailA;
                        }
                        ?>
                    </td>

                    <td class="col-total<?= !is_array($rB) ? " is-final" : "" ?>">
                        <?php
                        $totalFinalA = (int) ($rA["total_final"] ?? 0);

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

                    $totalFinalB = (int) ($rB["total_final"] ?? 0);
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
                            $rowBParenSign = ($rollBSign < 0) ? "-" : "+";

                            $diceTotalB = (int) ($rB["total"] ?? 0);
                            $modB = (int) ($rB["mod"] ?? 0);

                            $specialB = is_array($rB["special"] ?? null)
                                ? $rB["special"]
                                : null;

                            if (
                                $specialB
                                && (string) ($specialB["kind"] ?? "") === "wild"
                                && !empty($specialB["complication"])
                            ) {
                                $diceTotalB += (int) ($specialB["removed_highest"] ?? 0);
                            }

                            echo h($rowBParenSign) . " ( ";
                            echo h((string) $diceTotalB);

                            if ($modB !== 0) {
                                echo " "
                                    . h(($modB < 0) ? "-" : "+")
                                    . " "
                                    . h((string) abs($modB));
                            }

                            $specialDetailB = render_special_detail($rB, false);

                            if ($specialDetailB !== "") {
                                echo "<br>" . $specialDetailB;
                            }

                            echo " )";
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

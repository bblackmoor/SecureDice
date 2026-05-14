<?php
// results-functions.php

declare(strict_types=1);

function split_signed_int(int $v): array 
{
    return [
        "is_negative" => ($v < 0),
        "abs" => abs($v),
        "sign" => ($v < 0) ? "-" : "+",
    ];
}

function format_signed_int_txt(int $v): string 
{
    $parts = split_signed_int($v);

    if ($parts["is_negative"]) {
        return "- " . (string) $parts["abs"];
    }

    return "+ " . (string) $parts["abs"];
}

function format_roll_summary(string $label, int $mod, string $modeLabel): string 
{
    $out = $label;

    if ($mod !== 0) {
        $out .= " " . format_signed_int_txt($mod);
    }

    if ($modeLabel !== "") {
        $out .= ", " . $modeLabel;
    }

    return $out;
}

function format_signed_float(float $v, int $decimals): string 
{
    $txt = number_format($v, $decimals);

    if ($v >= 0) {
        return "+" . $txt;
    }

    return $txt;
}

function render_die_chip_html(?array $item, string $emdashChar): string 
{
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

function build_dice_items(array $r): array 
{
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

function get_wild_die_value(array $r, array $special): int 
{
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

function format_signed_value(
    int $value,
    string $class = ""
): string 
{
    $sign = ($value < 0) ? "-" : "+";
    $abs = abs($value);

    $classAttr = ($class !== "")
        ? ' class="' . h($class) . '"'
        : "";

    return '<span' . $classAttr . '>'
        . h($sign . " " . (string) $abs)
        . '</span>';
}

function format_modifier_value(int $value): string 
{
    if ($value < 0) {
        return format_signed_value($value, "mod-penalty");
    }

    return format_signed_value($value, "mod-bonus");
}

function format_roll_value(int $value): string 
{
    return format_signed_value($value, "detail-roll");
}

function get_detail_roll_total(array $roll): int 
{
    $rolls = is_array($roll["rolls"] ?? null)
        ? $roll["rolls"]
        : [];

    $droppedIndices = is_array($roll["dropped_indices"] ?? null)
        ? $roll["dropped_indices"]
        : [];

    $droppedIndexSet = [];

    foreach ($droppedIndices as $index) {
        $droppedIndexSet[(int) $index] = true;
    }

    $total = 0;

    foreach ($rolls as $index => $value) {
        if (!empty($droppedIndexSet[(int) $index])) {
            continue;
        }

        $total += (int) $value;
    }

    $special = is_array($roll["special"] ?? null)
        ? $roll["special"]
        : null;

    if ($special && (string) ($special["kind"] ?? "") === "wild") {
        $wildSeq = is_array($special["wild_seq"] ?? null)
            ? array_values($special["wild_seq"])
            : [];

        if (count($wildSeq) > 1) {
            for ($i = 1; $i < count($wildSeq); $i++) {
                $total += (int) $wildSeq[$i];
            }
        }
    }

    return $total;
}

function render_detail_expression(
    int $rollTotal,
    int $modifier
): string 
{
    if ($modifier === 0) {
        return format_roll_value($rollTotal);
    }

    $rollText = ($rollTotal < 0)
        ? format_roll_value($rollTotal)
        : '<span class="detail-roll">' . h((string) $rollTotal) . '</span>';

    return "("
        . $rollText
        . " "
        . format_modifier_value($modifier)
        . ")";
}

function render_roll_b_detail_expression(
    int $rollTotal,
    int $modifier
): string 
{
    if ($modifier === 0) {
        if ($rollTotal < 0) {
            return format_roll_value($rollTotal);
        }

        return '<span class="detail-roll">' . h((string) $rollTotal) . '</span>';
    }

    return render_detail_expression(
        $rollTotal,
        $modifier
    );
}

function get_stunt_die_value(array $r, array $special): int 
{
    $rolls = is_array($r["rolls"] ?? null)
        ? $r["rolls"]
        : [];

    $stuntIndex = (int) ($special["index"] ?? -1);

    if ($stuntIndex >= 0 && array_key_exists($stuntIndex, $rolls)) {
        return (int) $rolls[$stuntIndex];
    }

    if (array_key_exists("stunt_die", $special)) {
        return (int) $special["stunt_die"];
    }

    if (array_key_exists("stunt_value", $special)) {
        return (int) $special["stunt_value"];
    }

    return 0;
}

function render_special_detail(array $roll): string 
{
    $special = is_array($roll["special"] ?? null)
        ? $roll["special"]
        : null;

    if (!$special) {
        return "";
    }

    $kind = (string) ($special["kind"] ?? "");

    if ($kind === "stunt") {
        $stunt = get_stunt_die_value($roll, $special);

        return "Stunt die: "
            . '<span class="detail-roll">'
            . h((string) $stunt)
            . "</span>";
    }

    if ($kind === "wild") {
        if (!empty($special["complication"])) {
            $removed = (int) ($special["removed_highest"] ?? 0);

            return "Wild first roll: "
                . '<span class="detail-roll">1</span>'
                . " (complication)<br>"
                . "Removed highest die: "
                . '<span class="detail-roll">'
                . h((string) $removed)
                . "</span>";
        }

        $wildSeq = $special["wild_seq"] ?? [];

        if (
            is_array($wildSeq)
            && count($wildSeq) > 1
        ) {
            $parts = [];

            foreach ($wildSeq as $value) {
                $parts[] =
                    '<span class="detail-roll">'
                    . h((string) $value)
                    . "</span>";
            }

            return "Wild die: ("
                . implode(" + ", $parts)
                . ")";
        }

        $wild = get_wild_die_value($roll, $special);

        return "Wild die: "
            . '<span class="detail-roll">'
            . h((string) $wild)
            . "</span>";
    }

    return "";
}

<?php
// lib.php

declare(strict_types=1);

/**
 * Shared helpers for Secure Dice.
 * RNG: random_int() (CSPRNG).
 */

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function app_version(): string
{
    static $version = null;

    if ($version !== null) {
        return $version;
    }

    $versionFile = __DIR__ . '/VERSION';

    if (!is_readable($versionFile)) {
        return $version = 'Unknown';
    }

    $contents = file_get_contents($versionFile);

    if ($contents === false) {
        return $version = 'Unknown';
    }

    $candidate = trim($contents);

    if (preg_match('/^\d+\.\d+\.\d+$/', $candidate) !== 1) {
        return $version = 'Unknown';
    }

    return $version = $candidate;
}

function app_updated_date(): string
{
    static $updatedDate = null;

    if ($updatedDate !== null) {
        return $updatedDate;
    }

    $versionFile = __DIR__ . '/VERSION';

    if (!is_readable($versionFile)) {
        return $updatedDate = 'Unknown';
    }

    $modified = filemtime($versionFile);

    if ($modified === false) {
        return $updatedDate = 'Unknown';
    }

    return $updatedDate = date('Y-m-d', $modified);
}

function clamp_int(int $v, int $min, int $max): int
{
    if ($v < $min) {
        return $min;
    }

    if ($v > $max) {
        return $max;
    }

    return $v;
}

/**
 * Build an absolute shareable URL to a path in this app
 * (for example, "securedice.php").
 *
 * Uses the current request host so generated links can be copied
 * and shared with other players.
 *
 * Intentionally does NOT trust X-Forwarded-Host, since that header
 * is commonly client-controlled unless a reverse proxy is explicitly
 * trusted and configured for it.
 *
 * If the detected host is wrong because of a proxy or unusual server
 * setup, the shared link may fail—which is acceptable for this use case.
 */
function build_absolute_url(string $path): string
{
    $path = ltrim($path, '/');

    $https =
        (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
        || ((string) ($_SERVER["SERVER_PORT"] ?? "") === "443");

    $scheme = $https ? "https" : "http";

    $host = (string) ($_SERVER["HTTP_HOST"] ?? "localhost");
    $host = trim($host);

    if (!preg_match('/^[a-z0-9.-]+(?::[0-9]{1,5})?$/i', $host)) {
        $host = "localhost";
    }

    $scriptName = (string) ($_SERVER["SCRIPT_NAME"] ?? "/");
    $baseDir = rtrim(
        str_replace("\\", "/", dirname($scriptName)),
        "/"
    );

    if ($baseDir === "." || $baseDir === "/") {
        $baseDir = "";
    }

    return $scheme
        . "://"
        . $host
        . ($baseDir !== "" ? $baseDir . "/" : "/")
        . $path;
}

/**
 * Repeat options: 1–20, then 30–100 by tens.
 */
function build_repeat_options(): array
{
    $out = [];

    for ($i = 1; $i <= 20; $i++) {
        $out[] = $i;
    }

    for ($i = 30; $i <= 100; $i += 10) {
        $out[] = $i;
    }

    return $out;
}

/**
 * Single source of truth for available die types.
 *
 * - kind: normal|fudge
 * - sides: integer
 * - label: used for results display (for example, "d6" or "dF")
 * - option_label: used for form controls
 */
function allowed_die_types(): array
{
    $types = [];

    foreach ([
        2, 3, 4, 5, 6, '6f', 7, 8, 9, 10, 12, 13, 18, 20,
        25, 30, 35, 40, 45, 50, 60, 70, 80, 90, 100,
    ] as $sides) {
        if ($sides === '6f') {
            $types["d6f"] = [
                "kind" => "fudge",
                "sides" => 6,
                "label" => "dF",
                "option_label" => "d6 (Fudge)",
            ];

            continue;
        }

        $types["d" . $sides] = [
            "kind" => "normal",
            "sides" => $sides,
            "label" => "d" . $sides,
            "option_label" => "d" . $sides,
        ];
    }

    return $types;
}

function allowed_die_type_options(bool $includeFudge): array
{
    $options = [];

    foreach (allowed_die_types() as $raw => $dieType) {
        if (!$includeFudge && (string) $dieType["kind"] === "fudge") {
            continue;
        }

        $options[$raw] = (string) $dieType["option_label"];
    }

    return $options;
}

/**
 * Parse die type selection.
 */
function parse_die_type(string $raw): array
{
    $raw = trim($raw);
    $dieTypes = allowed_die_types();

    if (!isset($dieTypes[$raw])) {
        throw new RuntimeException("Unknown die type.");
    }

    return $dieTypes[$raw];
}

function validate_mode_constraints(
    string $mode,
    string $dieKind,
    int $dieSides,
    int $diceCount,
    string $contextLabel
): void {
    $mode = trim($mode);

    if ($dieKind === "fudge") {
        if ($mode !== "sum") {
            throw new RuntimeException($contextLabel . ": FUDGE only supports Sum.");
        }

        return;
    }

    if ($mode === "stunt") {
        if ($dieSides !== 6) {
            throw new RuntimeException($contextLabel . ": Stunt die requires d6.");
        }

        if ($diceCount !== 3) {
            throw new RuntimeException($contextLabel . ": Stunt die requires exactly 3 dice.");
        }

        return;
    }

    if ($mode === "wild") {
        if ($dieSides !== 6) {
            throw new RuntimeException($contextLabel . ": Wild die requires d6.");
        }

        if ($diceCount < 2) {
            throw new RuntimeException($contextLabel . ": Wild die requires at least 2 dice.");
        }

        return;
    }

    if ($mode === "drop_lowest" || $mode === "drop_highest") {
        if ($diceCount < 2) {
            throw new RuntimeException($contextLabel . ": Drop modes require at least 2 dice.");
        }
    }

    if ($dieSides < 2) {
        throw new RuntimeException($contextLabel . ": Die sides must be >= 2.");
    }
}

function roll_one(int $sides): int
{
    return random_int(1, $sides);
}

function roll_set(int $count, int $sides): array
{
    $out = [];

    for ($i = 0; $i < $count; $i++) {
        $out[] = roll_one($sides);
    }

    return $out;
}

function roll_set_fudge(int $count): array
{
    $raws = [];
    $vals = [];

    for ($i = 0; $i < $count; $i++) {
        $raw = random_int(1, 6);
        $raws[] = $raw;

        if ($raw <= 2) {
            $vals[] = -1;
        } elseif ($raw <= 4) {
            $vals[] = 0;
        } else {
            $vals[] = 1;
        }
    }

    return [
        "raws" => $raws,
        "vals" => $vals,
    ];
}

/**
 * Apply a roll mode and return the canonical dice representation.
 *
 * Every die is represented once, with its display value, optional raw value,
 * whether it contributes to the total, and its role. Renderers should use the
 * returned totals directly rather than reconstructing them from the dice.
 */
function apply_mode(array $rolls, string $mode, int $sides, ?array $rawRolls = null): array
{
    $dice = [];

    foreach ($rolls as $index => $value) {
        $die = [
            "index" => (int) $index,
            "value" => (int) $value,
            "kept" => true,
            "role" => "normal",
        ];

        if ($rawRolls !== null && array_key_exists($index, $rawRolls)) {
            $die["raw_value"] = (int) $rawRolls[$index];
        }

        $dice[] = $die;
    }

    $special = null;

    if ($mode === "drop_lowest") {
        $idx = index_of_extreme($rolls, false);
        $dice[$idx]["kept"] = false;
    } elseif ($mode === "drop_highest") {
        $idx = index_of_extreme($rolls, true);
        $dice[$idx]["kept"] = false;
    } elseif ($mode === "wild") {
        $wildIndex = count($rolls) - 1;
        $wildValue = (int) $rolls[$wildIndex];
        $dice[$wildIndex]["role"] = "wild";

        if ($wildValue === 1) {
            $otherRolls = $rolls;
            unset($otherRolls[$wildIndex]);
            $highestIndex = index_of_extreme($otherRolls, true);
            $removedHighest = (int) $rolls[$highestIndex];

            $dice[$wildIndex]["kept"] = false;
            $dice[$highestIndex]["kept"] = false;

            $special = [
                "kind" => "wild",
                "die_index" => $wildIndex,
                "sequence" => [$wildValue],
                "complication" => true,
                "removed_highest_index" => $highestIndex,
                "removed_highest" => $removedHighest,
            ];
        } else {
            $sequence = [$wildValue];

            while (end($sequence) === $sides) {
                $nextValue = roll_one($sides);
                $sequence[] = $nextValue;
                $dice[] = [
                    "index" => count($dice),
                    "value" => $nextValue,
                    "kept" => true,
                    "role" => "wild_explosion",
                ];
            }

            $special = [
                "kind" => "wild",
                "die_index" => $wildIndex,
                "sequence" => $sequence,
                "complication" => false,
                "removed_highest_index" => null,
                "removed_highest" => null,
            ];
        }
    } elseif ($mode === "stunt") {
        $stuntIndex = count($rolls) - 1;
        $stuntValue = (int) $rolls[$stuntIndex];
        $dice[$stuntIndex]["role"] = "stunt";

        $special = [
            "kind" => "stunt",
            "die_index" => $stuntIndex,
            "value" => $stuntValue,
        ];
    }

    $diceTotal = 0;

    foreach ($dice as $die) {
        if (!empty($die["kept"])) {
            $diceTotal += (int) $die["value"];
        }
    }

    return [
        "dice" => $dice,
        "dice_total" => $diceTotal,
        "special" => $special,
    ];
}

function index_of_extreme(array $values, bool $highest): int
{
    $bestIndex = (int) array_key_first($values);
    $bestValue = (int) $values[$bestIndex];

    foreach ($values as $i => $v) {
        $v = (int) $v;

        if ($highest) {
            if ($v > $bestValue) {
                $bestValue = $v;
                $bestIndex = (int) $i;
            }
        } else {
            if ($v < $bestValue) {
                $bestValue = $v;
                $bestIndex = (int) $i;
            }
        }
    }

    return $bestIndex;
}

function summarize_totals(array $totals): array
{
    if (count($totals) === 0) {
        return [
            "min" => 0,
            "max" => 0,
            "avg" => 0.0,
        ];
    }

    return [
        "min" => min($totals),
        "max" => max($totals),
        "avg" => array_sum($totals) / count($totals),
    ];
}

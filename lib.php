<?php
declare(strict_types=1);

/**
 * Shared helpers for Secure Dice.
 * RNG: random_int() (CSPRNG).
 */

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

function safe_bool($v): bool
{
    if (is_bool($v)) {
        return $v;
    }

    if (is_int($v)) {
        return ($v !== 0);
    }

    if (is_string($v)) {
        $t = strtolower(trim($v));

        return (
            $t === "1"
            || $t === "true"
            || $t === "yes"
            || $t === "on"
        );
    }

    return false;
}

/**
 * Build an absolute URL to a path in this app (e.g. "securedice.php").
 * Tries to respect reverse proxies via X-Forwarded-* headers when present.
 */
function build_absolute_url(string $path): string
{
    $path = ltrim($path, '/');

    $https = false;
    $xfProto = $_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "";

    if (is_string($xfProto) && strtolower(trim(explode(",", $xfProto)[0])) === "https") {
        $https = true;
    } elseif (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") {
        $https = true;
    } elseif (isset($_SERVER["SERVER_PORT"]) && (string) $_SERVER["SERVER_PORT"] === "443") {
        $https = true;
    }

    $scheme = $https ? "https" : "http";

    $host = "";
    $xfHost = $_SERVER["HTTP_X_FORWARDED_HOST"] ?? "";

    if (is_string($xfHost) && trim($xfHost) !== "") {
        $host = trim(explode(",", $xfHost)[0]);
    } elseif (!empty($_SERVER["HTTP_HOST"])) {
        $host = (string) $_SERVER["HTTP_HOST"];
    } elseif (!empty($_SERVER["SERVER_NAME"])) {
        $host = (string) $_SERVER["SERVER_NAME"];
    } else {
        $host = "localhost";
    }

    // Base directory of the current script (e.g. "/secure-dice/")
    $scriptName = (string) ($_SERVER["SCRIPT_NAME"] ?? "/");
    $baseDir = rtrim(str_replace("\\", "/", dirname($scriptName)), "/");

    if ($baseDir === "") {
        $baseDir = "";
    }

    return $scheme . "://" . $host . ($baseDir ? $baseDir . "/" : "/") . $path;
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
 * Die sides allowed for "normal dice" URL presets + UI.
 */
function allowed_die_sides(): array
{
    return [
        2, 3, 4, 5, 6, 7, 8, 9, 10, 12, 13, 18, 20,
        25, 30, 35, 40, 45, 50, 60, 70, 80, 90, 100,
    ];
}

/**
 * Parse die type selection.
 * - kind: normal|fudge
 * - sides: integer (for normal)
 * - label: used for display (e.g. "d6", "dF")
 */
function parse_die_type(string $raw): array
{
    $raw = trim($raw);

    if ($raw === "d6f") {
        return [
            "kind" => "fudge",
            "sides" => 6,
            "label" => "dF",
        ];
    }

    if (preg_match("/^d(\\d+)$/", $raw, $m) === 1) {
        $sides = (int) $m[1];

        if (!in_array($sides, allowed_die_sides(), true)) {
            throw new RuntimeException("Unknown die type.");
        }

        return [
            "kind" => "normal",
            "sides" => $sides,
            "label" => "d" . $sides,
        ];
    }

    throw new RuntimeException("Unknown die type.");
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

/**
 * FUDGE via d6 mapping: 1–2=-1, 3–4=0, 5–6=+1
 */
function roll_set_fudge(int $count): array
{
    $raws = [];
    $vals = [];

    for ($i = 0; $i < $count; $i++) {
        $r = roll_one(6);
        $raws[] = $r;

        if ($r <= 2) {
            $vals[] = -1;
        } elseif ($r <= 4) {
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

function summarize_totals(array $totals): array
{
    if (!$totals) {
        return [
            "min" => 0,
            "max" => 0,
            "avg" => 0.0,
        ];
    }

    $min = $totals[0];
    $max = $totals[0];
    $sum = 0.0;

    foreach ($totals as $t) {
        if ($t < $min) {
            $min = $t;
        }

        if ($t > $max) {
            $max = $t;
        }

        $sum += $t;
    }

    return [
        "min" => $min,
        "max" => $max,
        "avg" => $sum / count($totals),
    ];
}

/**
 * Apply roll mode to a set of dice.
 * Wild/stunt die is treated as the LAST die in the array.
 */
function apply_mode(array $rolls, string $mode, int $sides, int $diceCount): array
{
    $mode = trim($mode);

    if ($mode === "sum") {
        return [
            "total_dice" => array_sum($rolls),
            "dropped_rolls" => [],
            "dropped_indices" => [],
            "special" => null,
        ];
    }

    if ($mode === "drop_lowest") {
        if (count($rolls) <= 1) {
            return [
                "total_dice" => array_sum($rolls),
                "dropped_rolls" => [],
                "dropped_indices" => [],
                "special" => null,
            ];
        }

        $min = min($rolls);
        $dropIndex = array_search($min, $rolls, true);

        if ($dropIndex === false) {
            $dropIndex = 0;
        }

        return [
            "total_dice" => array_sum($rolls) - (int) $min,
            "dropped_rolls" => [(int) $min],
            "dropped_indices" => [(int) $dropIndex],
            "special" => null,
        ];
    }

    if ($mode === "drop_highest") {
        if (count($rolls) <= 1) {
            return [
                "total_dice" => array_sum($rolls),
                "dropped_rolls" => [],
                "dropped_indices" => [],
                "special" => null,
            ];
        }

        $max = max($rolls);
        $dropIndex = array_search($max, $rolls, true);

        if ($dropIndex === false) {
            $dropIndex = 0;
        }

        return [
            "total_dice" => array_sum($rolls) - (int) $max,
            "dropped_rolls" => [(int) $max],
            "dropped_indices" => [(int) $dropIndex],
            "special" => null,
        ];
    }

    if ($mode === "stunt") {
        $idx = count($rolls) - 1;

        $special = [
            "kind" => "stunt",
            "index" => $idx,
            "value" => (int) $rolls[$idx],
        ];

        return [
            "total_dice" => array_sum($rolls),
            "dropped_rolls" => [],
            "dropped_indices" => [],
            "special" => $special,
        ];
    }

    if ($mode === "wild") {
        $idx = count($rolls) - 1;
        $wildFirst = (int) $rolls[$idx];

        // Complication: first wild roll is 1 => remove the 1 and the highest other die
        if ($wildFirst === 1) {
            $removedHighest = null;
            $removedIndex = null;

            foreach ($rolls as $i => $v) {
                if ($i === $idx) {
                    continue;
                }

                $iv = (int) $v;

                if ($removedHighest === null || $iv > $removedHighest) {
                    $removedHighest = $iv;
                    $removedIndex = (int) $i;
                }
            }

            $sumOthers = 0;

            foreach ($rolls as $i => $v) {
                if ($i === $idx) {
                    continue;
                }

                $sumOthers += (int) $v;
            }

            $droppedRolls = [];
            $droppedIndices = [];

            if ($removedHighest !== null && $removedIndex !== null) {
                $sumOthers -= $removedHighest;
                $droppedRolls[] = $removedHighest;
                $droppedIndices[] = $removedIndex;
            }

            $special = [
                "kind" => "wild",
                "index" => $idx,
                "complication" => true,
                "wild_seq" => [1],
                "removed_highest" => $removedHighest,
            ];

            return [
                "total_dice" => $sumOthers,
                "dropped_rolls" => $droppedRolls,
                "dropped_indices" => $droppedIndices,
                "special" => $special,
            ];
        }

        // Explode on max
        $seq = [$wildFirst];

        while (end($seq) === $sides) {
            $seq[] = roll_one($sides);
        }

        $wildTotal = array_sum($seq);
        $sum = 0;

        foreach ($rolls as $i => $v) {
            if ($i !== $idx) {
                $sum += (int) $v;
            }
        }

        $sum += $wildTotal;

        $special = [
            "kind" => "wild",
            "index" => $idx,
            "complication" => false,
            "wild_seq" => $seq,
        ];

        return [
            "total_dice" => $sum,
            "dropped_rolls" => [],
            "dropped_indices" => [],
            "special" => $special,
        ];
    }

    throw new RuntimeException("Unknown mode.");
}

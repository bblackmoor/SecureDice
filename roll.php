<?php
// roll.php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/storage.php';

send_security_headers();

$useGetPresets = empty($_POST);

$allowedDiceCountsRow1 = range(1, 20);
$allowedDiceCountsRow2 = range(-20, 20);
$allowedRepeats = build_repeat_options();
$allowedDieTypes = allowed_die_types();

$allowedModesRow1 = [
    'sum',
    'drop_lowest',
    'drop_highest',
    'wild',
    'stunt',
];

$allowedModesRow2 = [
    'sum',
    'drop_lowest',
    'drop_highest',
];

function read_int(string $key, int $default, bool $fromGet): int
{
    $raw = filter_input($fromGet ? INPUT_GET : INPUT_POST, $key, FILTER_UNSAFE_RAW);

    if ($raw === null) {
        return $default;
    }

    $raw = trim((string) $raw);

    if ($raw === '') {
        return $default;
    }

    if (preg_match('/^[+-]?\d+$/', $raw) !== 1) {
        return $default;
    }

    return (int) $raw;
}

function read_str(string $key, string $default, bool $fromGet): string
{
    $v = filter_input($fromGet ? INPUT_GET : INPUT_POST, $key, FILTER_UNSAFE_RAW);

    if ($v === null) {
        return $default;
    }

    $v = trim((string) $v);

    if ($v === '') {
        return $default;
    }

    return $v;
}

function do_one_roll(
    int $diceCount,
    string $dieTypeRaw,
    string $mode,
    int $mod
): array {
    $dt = parse_die_type($dieTypeRaw);
    $kind = (string) $dt['kind'];
    $sides = (int) $dt['sides'];

    validate_mode_constraints($mode, $kind, $sides, $diceCount, 'Roll');

    $rolls = [];
    $rolls_raw = null;

    if ($kind === 'fudge') {
        $f = roll_set_fudge($diceCount);
        $rolls_raw = $f['raws'];
        $rolls = $f['vals'];
    } else {
        $rolls = roll_set($diceCount, $sides);
    }

    $applied = apply_mode($rolls, $mode, $sides, $rolls_raw);
    $diceTotal = (int) $applied['dice_total'];
    $total = $diceTotal + $mod;

    return [
        'dice' => $applied['dice'],
        'dice_total' => $diceTotal,
        'modifier' => $mod,
        'total' => $total,
        'mode' => $mode,
        'die_kind' => $kind,
        'special' => $applied['special'],
    ];
}

try {
    if ($useGetPresets) {
        $diceCountA = read_int('aq', 3, true);
        $asA = read_int('as', 6, true);
        $afA = read_int('af', 0, true);
        $modA = read_int('am', 0, true);
        $adA = strtolower(read_str('ad', 'sum', true));

        $diceCountB = read_int('bq', 0, true);
        $asB = read_int('bs', 6, true);
        $modB = read_int('bm', 0, true);
        $adB = strtolower(read_str('bd', 'sum', true));

        $repeat = read_int('dt', 1, true);
        $sortResults = (read_int('sdt', 0, true) === 1);

        if ($afA === 1) {
            $dieTypeRawA = 'd6f';
        } else {
            $presetDieTypeRawA = 'd' . $asA;
            $dieTypeRawA = (
                isset($allowedDieTypes[$presetDieTypeRawA])
                && (string) $allowedDieTypes[$presetDieTypeRawA]['kind'] === 'normal'
            )
                ? $presetDieTypeRawA
                : 'd6';
        }

        $modeA = in_array($adA, $allowedModesRow1, true)
            ? $adA
            : 'sum';

        $presetDieTypeRawB = 'd' . $asB;
        $dieTypeRawB = (
            isset($allowedDieTypes[$presetDieTypeRawB])
            && (string) $allowedDieTypes[$presetDieTypeRawB]['kind'] === 'normal'
        )
            ? $presetDieTypeRawB
            : 'd6';

        $modeB = in_array($adB, $allowedModesRow2, true)
            ? $adB
            : 'sum';
    } else {
        // POST keys from form
        $diceCountA = read_int('dice_count', 3, false);
        $dieTypeRawA = read_str('die_type', 'd6', false);
        $modA = read_int('mod', 0, false);
        $modeA = read_str('mode', 'sum', false);

        $diceCountB = read_int('dice_count_b', 0, false);
        $dieTypeRawB = read_str('die_type_b', 'd6', false);
        $modB = read_int('mod_b', 0, false);
        $modeB = read_str('mode_b', 'sum', false);

        $repeat = read_int('repeat', 1, false);
        $sortResults = (read_int('sort_results', 0, false) === 1);
    }

    // Allowlists / clamps
    if (!in_array($diceCountA, $allowedDiceCountsRow1, true)) {
        $diceCountA = 3;
    }

    if (!in_array($repeat, $allowedRepeats, true)) {
        throw new RuntimeException('Invalid repeat count.');
    }

    $modA = clamp_int($modA, -60, 60);

    if (!in_array($modeA, $allowedModesRow1, true)) {
        $modeA = 'sum';
    }

    $dtA = parse_die_type($dieTypeRawA);

    // Enforce Row1 special rules server-side
    if ($dtA['kind'] === 'fudge') {
        $modeA = 'sum';
    }

    if ($modeA === 'stunt') {
        $diceCountA = 3;
        $dieTypeRawA = 'd6';
        $dtA = parse_die_type($dieTypeRawA);
    }

    if ($modeA === 'wild') {
        if ($diceCountA < 2) {
            $diceCountA = 2;
        }

        $dieTypeRawA = 'd6';
        $dtA = parse_die_type($dieTypeRawA);
    }

    validate_mode_constraints(
        $modeA,
        (string) $dtA['kind'],
        (int) $dtA['sides'],
        $diceCountA,
        'First roll'
    );

    // Row2
    if (!in_array($diceCountB, $allowedDiceCountsRow2, true)) {
        $diceCountB = 0;
    }

    $diceCountBSign = ($diceCountB < 0) ? -1 : 1;
    $diceCountBRoll = abs($diceCountB);

    $modB = clamp_int($modB, -60, 60);

    if (!in_array($modeB, $allowedModesRow2, true)) {
        $modeB = 'sum';
    }

    // Row2 forced off if Row1 is FUDGE/WILD/STUNT
    $row1ForcesRow2Off = (
        ($dtA['kind'] === 'fudge')
        || $modeA === 'wild'
        || $modeA === 'stunt'
    );

    if ($row1ForcesRow2Off) {
        $diceCountB = 0;
        $diceCountBSign = 1;
        $diceCountBRoll = 0;
    }

    // Row2 restrictions
    if ($diceCountBRoll > 0) {
        if ($dieTypeRawB === 'd6f') {
            throw new RuntimeException('Second roll: FUDGE is not allowed.');
        }

        if ($modeB === 'wild' || $modeB === 'stunt') {
            throw new RuntimeException('Second roll: wild/stunt not allowed.');
        }

        $dt2 = parse_die_type($dieTypeRawB);

        validate_mode_constraints(
            $modeB,
            (string) $dt2['kind'],
            (int) $dt2['sides'],
            $diceCountBRoll,
            'Second roll'
        );
    }

    $activeB = ($diceCountBRoll > 0);

    // Trials
    $sets = [];
    $totals = [];

    for ($i = 0; $i < $repeat; $i++) {
        $r1 = do_one_roll($diceCountA, $dieTypeRawA, $modeA, $modA);

        $r2 = null;

        if ($activeB) {
            $r2 = do_one_roll($diceCountBRoll, $dieTypeRawB, $modeB, $modB);
        }

        $terms = [
            [
                'pool' => 'A',
                'operator' => 1,
                'result' => $r1,
            ],
        ];

        $final = (int) $r1['total'];

        if ($r2 !== null) {
            $terms[] = [
                'pool' => 'B',
                'operator' => $diceCountBSign,
                'result' => $r2,
            ];
            $final += $diceCountBSign * (int) $r2['total'];
        }

        $sets[] = [
            'number' => $i + 1,
            'terms' => $terms,
            'total' => $final,
        ];

        $totals[] = $final;
    }

    $summary = summarize_totals($totals);

    $dieA = parse_die_type($dieTypeRawA);
    $dieB = parse_die_type($dieTypeRawB);

    app_start_session();

    $_SESSION['last_roll'] = store_result_record([
        'schema_version' => 2,
        'generated_at' => gmdate(DATE_ATOM),
        'specification' => [
            'repeat' => $repeat,
            'sort_results' => $sortResults,
            'pools' => [
                'A' => [
                    'dice_count' => $diceCountA,
                    'die_type' => $dieTypeRawA,
                    'die_kind' => $dieA['kind'],
                    'sides' => (int) $dieA['sides'],
                    'die_label' => (string) $dieA['label'],
                    'modifier' => $modA,
                    'mode' => $modeA,
                ],
                'B' => $activeB ? [
                    'dice_count' => $diceCountBRoll,
                    'operator' => $diceCountBSign,
                    'die_type' => $dieTypeRawB,
                    'die_kind' => $dieB['kind'],
                    'sides' => (int) $dieB['sides'],
                    'die_label' => (string) $dieB['label'],
                    'modifier' => $modB,
                    'mode' => $modeB,
                ] : null,
            ],
        ],
        'summary' => $summary,
        'sets' => $sets,
    ]);

    header('Location: results.php');
    exit;
} catch (Throwable $e) {
    http_response_code(400);

    echo '<p>Error: ' . h($e->getMessage()) . '</p>';
    echo '<p><a href="securedice.php">Back</a></p>';
}

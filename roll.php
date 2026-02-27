<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$useGetPresets = empty($_POST);

$allowedDiceCountsRow1 = range(1, 20);
$allowedDiceCountsRow2 = range(0, 20);
$allowedRepeats = build_repeat_options();
$allowedSides = allowed_die_sides();

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

$ddMap = [
    'none' => 'sum',
    'lowest' => 'drop_lowest',
    'highest' => 'drop_highest',
    'wild' => 'wild',
    'stunt' => 'stunt',
];

$mddMap = [
    'none' => 'sum',
    'lowest' => 'drop_lowest',
    'highest' => 'drop_highest',
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

    $applied = apply_mode($rolls, $mode, $sides, $diceCount);

    $totalDice = (int) $applied['total_dice'];
    $special = $applied['special'];

    $stuntValue = null;

    if ($special && ($special['kind'] ?? '') === 'stunt') {
        $stuntValue = (int) ($special['value'] ?? 0);
    }

    $totalFinal = $totalDice + $mod;

    return [
        'rolls' => $rolls,
        'rolls_raw' => $rolls_raw,
        'dropped' => $applied['dropped_rolls'],
        'dropped_indices' => $applied['dropped_indices'],
        'special' => $special,
        'die_kind' => $kind,
        'die_sides' => $sides,
        'die_label' => (string) $dt['label'],
        'dice_count' => $diceCount,
        'mod' => $mod,
        'mode' => $mode,
        'total_dice' => $totalDice,
        'total_final' => $totalFinal,
        'stunt_value' => $stuntValue,
    ];
}

try {
    if ($useGetPresets) {
        // Legacy GET keys (+ new optional key df)
        $diceCountA = read_int('dq', 3, true);
        $dsA = read_int('ds', 6, true);
        $dfA = read_int('df', 0, true);
        $modA = read_int('dm', 0, true);
        $ddA = strtolower(read_str('dd', 'none', true));

        $diceCountB = read_int('mdq', 0, true);
        $dsB = read_int('mds', 6, true);
        $modB = read_int('mdm', 0, true);
        $ddB = strtolower(read_str('mdd', 'none', true));

        $repeat = read_int('dt', 1, true);
        $sortResults = (read_int('sdt', 0, true) === 1);

        if ($dfA === 1) {
            $dieTypeRawA = 'd6f';
        } else {
            $dieTypeRawA = 'd' . (in_array($dsA, $allowedSides, true) ? $dsA : 6);
        }

        $modeA = $ddMap[$ddA] ?? 'sum';

        $dieTypeRawB = 'd' . (in_array($dsB, $allowedSides, true) ? $dsB : 6);
        $modeB = $mddMap[$ddB] ?? 'sum';
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
    }

    // Row2 restrictions
    if ($diceCountB > 0) {
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
            $diceCountB,
            'Second roll'
        );
    }

    $activeB = ($diceCountB > 0);

    // Trials
    $trials = [];
    $totals = [];

    for ($i = 0; $i < $repeat; $i++) {
        $r1 = do_one_roll($diceCountA, $dieTypeRawA, $modeA, $modA);

        $r2 = null;

        if ($activeB) {
            $r2 = do_one_roll($diceCountB, $dieTypeRawB, $modeB, $modB);
        }

        $final = $r1['total_final'] - ($r2 ? $r2['total_final'] : 0);

        $trials[] = [
            'rollA' => $r1,
            'rollB' => $r2,
            'final' => $final,
        ];

        $totals[] = $final;
    }

    $summary = summarize_totals($totals);

    $dieA = parse_die_type($dieTypeRawA);
    $dieB = parse_die_type($dieTypeRawB);

    session_start();

    $_SESSION['last_roll'] = [
        'ts' => time(),
        'input' => [
            'repeat' => $repeat,
            'sortResults' => $sortResults,
            'activeB' => $activeB,
            'rollA' => [
                'diceCount' => $diceCountA,
                'dieTypeRaw' => $dieTypeRawA,
                'dieKind' => $dieA['kind'],
                'sides' => (int) $dieA['sides'],
                'dieLabel' => (string) $dieA['label'],
                'mod' => $modA,
                'mode' => $modeA,
            ],
            'rollB' => [
                'diceCount' => $diceCountB,
                'dieTypeRaw' => $dieTypeRawB,
                'dieKind' => $dieB['kind'],
                'sides' => (int) $dieB['sides'],
                'dieLabel' => (string) $dieB['label'],
                'mod' => $modB,
                'mode' => $modeB,
            ],
        ],
        'summary' => $summary,
        'trials' => $trials,
    ];

    header('Location: results.php');
    exit;
} catch (Throwable $e) {
    http_response_code(400);

    echo '<p>Error: ' . h($e->getMessage()) . '</p>';
    echo '<p><a href="index.php">Back</a></p>';
}

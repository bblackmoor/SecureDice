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

function preset_normal_die_type(int $sides, array $allowedDieTypes): string
{
    $raw = 'd' . $sides;

    return (
        isset($allowedDieTypes[$raw])
        && (string) $allowedDieTypes[$raw]['kind'] === 'normal'
    ) ? $raw : 'd6';
}

function read_roll_request(
    bool $useGetPresets,
    array $allowedDieTypes,
    array $allowedModesRow1,
    array $allowedModesRow2
): array {
    if ($useGetPresets) {
        $fudgeA = read_int('af', 0, true);
        $modeA = strtolower(read_str('ad', 'sum', true));
        $modeB = strtolower(read_str('bd', 'sum', true));

        return [
            'dice_count_a' => read_int('aq', 3, true),
            'die_type_a' => $fudgeA === 1
                ? 'd6f'
                : preset_normal_die_type(read_int('as', 6, true), $allowedDieTypes),
            'modifier_a' => read_int('am', 0, true),
            'mode_a' => in_array($modeA, $allowedModesRow1, true) ? $modeA : 'sum',
            'dice_count_b' => read_int('bq', 0, true),
            'die_type_b' => preset_normal_die_type(read_int('bs', 6, true), $allowedDieTypes),
            'modifier_b' => read_int('bm', 0, true),
            'mode_b' => in_array($modeB, $allowedModesRow2, true) ? $modeB : 'sum',
            'repeat' => read_int('dt', 1, true),
            'sort_results' => read_int('sdt', 0, true) === 1,
        ];
    }

    return [
        'dice_count_a' => read_int('dice_count', 3, false),
        'die_type_a' => read_str('die_type', 'd6', false),
        'modifier_a' => read_int('mod', 0, false),
        'mode_a' => read_str('mode', 'sum', false),
        'dice_count_b' => read_int('dice_count_b', 0, false),
        'die_type_b' => read_str('die_type_b', 'd6', false),
        'modifier_b' => read_int('mod_b', 0, false),
        'mode_b' => read_str('mode_b', 'sum', false),
        'repeat' => read_int('repeat', 1, false),
        'sort_results' => read_int('sort_results', 0, false) === 1,
    ];
}

function normalize_primary_roll(array $request, array $allowedDiceCounts, array $allowedModes): array
{
    $diceCount = (int) $request['dice_count_a'];

    if (!in_array($diceCount, $allowedDiceCounts, true)) {
        $diceCount = 3;
    }

    $mode = (string) $request['mode_a'];

    if (!in_array($mode, $allowedModes, true)) {
        $mode = 'sum';
    }

    $dieType = (string) $request['die_type_a'];
    $die = parse_die_type($dieType);

    if ($die['kind'] === 'fudge') {
        $mode = 'sum';
    }

    if ($mode === 'stunt') {
        $diceCount = 3;
        $dieType = 'd6';
        $die = parse_die_type($dieType);
    }

    if ($mode === 'wild') {
        if ($diceCount < 2) {
            $diceCount = 2;
        }

        $dieType = 'd6';
        $die = parse_die_type($dieType);
    }

    validate_mode_constraints(
        $mode,
        (string) $die['kind'],
        (int) $die['sides'],
        $diceCount,
        'First roll'
    );

    return [
        'dice_count' => $diceCount,
        'die_type' => $dieType,
        'die' => $die,
        'modifier' => clamp_int((int) $request['modifier_a'], -60, 60),
        'mode' => $mode,
    ];
}

function normalize_secondary_roll(
    array $request,
    array $primary,
    array $allowedDiceCounts,
    array $allowedModes
): array {
    $diceCount = (int) $request['dice_count_b'];

    if (!in_array($diceCount, $allowedDiceCounts, true)) {
        $diceCount = 0;
    }

    $sign = $diceCount < 0 ? -1 : 1;
    $rollCount = abs($diceCount);
    $mode = (string) $request['mode_b'];

    if (!in_array($mode, $allowedModes, true)) {
        $mode = 'sum';
    }

    if (
        $primary['die']['kind'] === 'fudge'
        || $primary['mode'] === 'wild'
        || $primary['mode'] === 'stunt'
    ) {
        $diceCount = 0;
        $sign = 1;
        $rollCount = 0;
    }

    $dieType = (string) $request['die_type_b'];

    if ($rollCount > 0) {
        if ($dieType === 'd6f') {
            throw new RuntimeException('Second roll: FUDGE is not allowed.');
        }

        if ($mode === 'wild' || $mode === 'stunt') {
            throw new RuntimeException('Second roll: wild/stunt not allowed.');
        }

        $die = parse_die_type($dieType);
        validate_mode_constraints(
            $mode,
            (string) $die['kind'],
            (int) $die['sides'],
            $rollCount,
            'Second roll'
        );
    }

    return [
        'dice_count' => $diceCount,
        'roll_count' => $rollCount,
        'operator' => $sign,
        'die_type' => $dieType,
        'modifier' => clamp_int((int) $request['modifier_b'], -60, 60),
        'mode' => $mode,
        'active' => $rollCount > 0,
    ];
}

function execute_roll_sets(array $primary, array $secondary, int $repeat): array
{
    $sets = [];
    $totals = [];

    for ($i = 0; $i < $repeat; $i++) {
        $first = do_one_roll(
            $primary['dice_count'],
            $primary['die_type'],
            $primary['mode'],
            $primary['modifier']
        );
        $second = $secondary['active']
            ? do_one_roll(
                $secondary['roll_count'],
                $secondary['die_type'],
                $secondary['mode'],
                $secondary['modifier']
            )
            : null;
        $terms = [[
            'pool' => 'A',
            'operator' => 1,
            'result' => $first,
        ]];
        $total = (int) $first['total'];

        if ($second !== null) {
            $terms[] = [
                'pool' => 'B',
                'operator' => $secondary['operator'],
                'result' => $second,
            ];
            $total += $secondary['operator'] * (int) $second['total'];
        }

        $sets[] = [
            'number' => $i + 1,
            'terms' => $terms,
            'total' => $total,
        ];
        $totals[] = $total;
    }

    return ['sets' => $sets, 'totals' => $totals];
}

function build_roll_result_record(
    array $primary,
    array $secondary,
    int $repeat,
    bool $sortResults,
    array $rolls
): array {
    $dieA = parse_die_type($primary['die_type']);
    $dieB = parse_die_type($secondary['die_type']);

    return [
        'schema_version' => 2,
        'generated_at' => gmdate(DATE_ATOM),
        'specification' => [
            'repeat' => $repeat,
            'sort_results' => $sortResults,
            'pools' => [
                'A' => [
                    'dice_count' => $primary['dice_count'],
                    'die_type' => $primary['die_type'],
                    'die_kind' => $dieA['kind'],
                    'sides' => (int) $dieA['sides'],
                    'die_label' => (string) $dieA['label'],
                    'modifier' => $primary['modifier'],
                    'mode' => $primary['mode'],
                ],
                'B' => $secondary['active'] ? [
                    'dice_count' => $secondary['roll_count'],
                    'operator' => $secondary['operator'],
                    'die_type' => $secondary['die_type'],
                    'die_kind' => $dieB['kind'],
                    'sides' => (int) $dieB['sides'],
                    'die_label' => (string) $dieB['label'],
                    'modifier' => $secondary['modifier'],
                    'mode' => $secondary['mode'],
                ] : null,
            ],
        ],
        'summary' => summarize_totals($rolls['totals']),
        'sets' => $rolls['sets'],
    ];
}

try {
    $request = read_roll_request(
        $useGetPresets,
        $allowedDieTypes,
        $allowedModesRow1,
        $allowedModesRow2
    );
    $repeat = (int) $request['repeat'];

    if (!in_array($repeat, $allowedRepeats, true)) {
        throw new RuntimeException('Invalid repeat count.');
    }

    $primary = normalize_primary_roll($request, $allowedDiceCountsRow1, $allowedModesRow1);
    $secondary = normalize_secondary_roll(
        $request,
        $primary,
        $allowedDiceCountsRow2,
        $allowedModesRow2
    );
    $rolls = execute_roll_sets($primary, $secondary, $repeat);
    $result = build_roll_result_record(
        $primary,
        $secondary,
        $repeat,
        (bool) $request['sort_results'],
        $rolls
    );

    app_start_session();
    $_SESSION['last_roll'] = store_result_record($result);

    header('Location: results.php');
    exit;
} catch (Throwable $e) {
    http_response_code(400);

    echo '<p>Error: ' . h($e->getMessage()) . '</p>';
    echo '<p><a href="securedice.php">Back</a></p>';
}

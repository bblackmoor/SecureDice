<?php
// securedice.php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function signed_label(int $n): string
{
    $sign = ($n >= 0) ? '+' : '−';

    return sprintf('%s %2d', $sign, abs($n));
}

function get_qs(string $key): ?string
{
    $v = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW);

    if ($v === null) {
        return null;
    }

    $v = trim((string) $v);

    return ($v === '') ? null : $v;
}

function get_qi(string $key): ?int
{
    $raw = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW);

    if ($raw === null) {
        return null;
    }

    $raw = trim((string) $raw);

    if ($raw === '') {
        return null;
    }

    if (preg_match('/^[+-]?\d+$/', $raw) !== 1) {
        return null;
    }

    return (int) $raw;
}

$allowedSides      = allowed_die_sides();
$repeatOptions     = build_repeat_options();

$diceOptions1      = range(0, 20);
$diceOptions2      = range(1, 20);
$diceOptions3      = range(-20, 20);

$modeOptionsRow1 = [
    'sum'           => 'sum them all (default)',
    'drop_lowest'   => 'drop the lowest die',
    'drop_highest'  => 'drop the highest die',
    'wild'          => 'use one as a wild die',
    'stunt'         => 'use one as a stunt die',
];

$modeOptionsRow2 = [
    'sum'           => 'sum them all (default)',
    'drop_lowest'   => 'drop the lowest die',
    'drop_highest'  => 'drop the highest die',
];

$dieTypeOptionsRow1 = [];

foreach ($allowedSides as $s) {
    $dieTypeOptionsRow1['d' . $s] = 'd' . $s;

    if ($s === 6) {
        $dieTypeOptionsRow1['d6f'] = 'd6 (Fudge)';
    }
}

$dieTypeOptionsRow2 = [];

foreach ($allowedSides as $s) {
    $dieTypeOptionsRow2['d' . $s] = 'd' . $s;
}

$ddMap  = ['none' => 'sum', 'lowest' => 'drop_lowest', 'highest' => 'drop_highest', 'wild' => 'wild', 'stunt' => 'stunt'];
$mddMap = ['none' => 'sum', 'lowest' => 'drop_lowest', 'highest' => 'drop_highest'];

$defaultDiceCount1 = 3;
$defaultDieType1   = 'd6';
$defaultMod1       = 0;
$defaultMode1      = 'sum';

$defaultDiceCount2 = 0;
$defaultDieType2   = 'd6';
$defaultMod2       = 0;
$defaultMode2      = 'sum';

$defaultRepeat     = 1;
$defaultSort       = false;

$pref_df = get_qi('df');

if (($dq = get_qi('dq')) !== null && in_array($dq, $diceOptions2, true)) {
    $defaultDiceCount1 = $dq;
}

if (($ds = get_qi('ds')) !== null && in_array($ds, $allowedSides, true)) {
    $defaultDieType1 = 'd' . $ds;
}

if (($dm = get_qi('dm')) !== null) {
    $defaultMod1 = clamp_int($dm, -60, 60);
}

if (($dd = get_qs('dd')) !== null) {
    $m = $ddMap[strtolower($dd)] ?? null;

    if ($m !== null && isset($modeOptionsRow1[$m])) {
        $defaultMode1 = $m;
    }
}

if ($pref_df !== null && $pref_df === 1) {
    $defaultDieType1 = 'd6f';
}

if (($mdq = get_qi('mdq')) !== null && in_array($mdq, $diceOptions3, true)) {
    $defaultDiceCount2 = $mdq;
}

if (($mds = get_qi('mds')) !== null && in_array($mds, $allowedSides, true)) {
    $defaultDieType2 = 'd' . $mds;
}

if (($mdm = get_qi('mdm')) !== null) {
    $defaultMod2 = clamp_int($mdm, -60, 60);
}

if (($mdd = get_qs('mdd')) !== null) {
    $m = $mddMap[strtolower($mdd)] ?? null;

    if ($m !== null && isset($modeOptionsRow2[$m])) {
        $defaultMode2 = $m;
    }
}

if (($dt = get_qi('dt')) !== null && in_array($dt, $repeatOptions, true)) {
    $defaultRepeat = $dt;
}

if (($sdt = get_qi('sdt')) !== null && $sdt === 1) {
    $defaultSort = true;
}

$rollPageUrl  = build_absolute_url('securedice.php');
$exampleQuery = 'dq=7&ds=4&dt=5&dd=highest&sdt=1';
$exampleUrl   = $rollPageUrl . '?' . $exampleQuery;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Secure Dice</title>
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
            Roll common dice patterns with optional subtraction, sorting, and URL presets.
        </p>
        <p class="site-subtitle">
            Tip: Bookmark a preset URL once you've dialed in your favorite roll.
        </p>
    </div>
</header>

<form id="sd2-form" method="post" action="roll.php">

    <div class="floating-actions" aria-label="Quick actions">
        <button type="submit" class="sd2-action-btn primary">Roll Dice</button>
        <button id="sd2-copy-url" type="button" class="sd2-action-btn neutral">Copy URL</button>
        <button id="sd2-reset" type="button" class="sd2-action-btn danger">Reset</button>
    </div>

    <div class="card">
		<table class="dice-builder">
			<tr id="roll-a" class="roll-a dice-row">
				<td class="col-num">
					<label for="dice_count">Roll</label>
					<select id="dice_count" name="dice_count" required>
						<?php foreach ($diceOptions2 as $d): ?>
							<option value="<?= (int) $d ?>" <?= ((int) $defaultDiceCount1 === (int) $d) ? 'selected' : '' ?>>
								<?= (int) $d ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>

				<td class="col-dice">
					<select id="die_type" name="die_type" required>
						<?php foreach ($dieTypeOptionsRow1 as $val => $label): ?>
							<option value="<?= h($val) ?>" <?= ($defaultDieType1 === $val) ? 'selected' : '' ?>>
								<?= h($label) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>

				<td class="col-mod">
					<select id="mod" name="mod">
						<?php foreach ($diceOptions3 as $d): ?>
							<option value="<?= (int) $d ?>" <?= ((int) $defaultMod1 === (int) $d) ? 'selected' : '' ?>>
								<?= signed_label((int) $d) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>

				<td class="col-and">
					<span>and</span>
				</td>

				<td class="col-mode">
					<select id="mode" name="mode" required>
						<?php foreach ($modeOptionsRow1 as $k => $label): ?>
							<option value="<?= h($k) ?>" <?= ($defaultMode1 === $k) ? 'selected' : '' ?>>
								<?= h($label) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>

			</tr>

			<tr id="roll-b" class="roll-b dice-row">

				<td class="col-num">
					<label for="dice_count_b">(+/-)</label>
					<select id="dice_count_b" name="dice_count_b">
						<?php foreach ($diceOptions3 as $d): ?>
							<option value="<?= (int) $d ?>" <?= ((int) $defaultDiceCount2 === (int) $d) ? 'selected' : '' ?>>
								<?= signed_label((int) $d) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>

				<td class="col-dice">
					<select id="die_type_b" name="die_type_b">
						<?php foreach ($dieTypeOptionsRow2 as $val => $label): ?>
							<option value="<?= h($val) ?>" <?= ($defaultDieType2 === $val) ? 'selected' : '' ?>>
								<?= h($label) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>

				<td class="col-mod">
					<select id="mod_b" name="mod_b">
						<?php foreach ($diceOptions3 as $d): ?>
							<option value="<?= (int) $d ?>" <?= ((int) $defaultMod2 === (int) $d) ? 'selected' : '' ?>>
								<?= signed_label((int) $d) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>

				<td class="col-and">
					<span>and</span>
				</td>

				<td class="col-mode">
					<select id="mode_b" name="mode_b">
						<?php foreach ($modeOptionsRow2 as $k => $label): ?>
							<option value="<?= h($k) ?>" <?= ($defaultMode2 === $k) ? 'selected' : '' ?>>
								<?= h($label) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>

			</tr>

			<tr class="roll-repeat">

				<td colspan="5">
					<div class="roll-repeat-controls">
						<span>Roll this set of dice</span>

						<select id="repeat" name="repeat" required>
							<?php foreach ($repeatOptions as $r): ?>
								<option value="<?= (int) $r ?>" <?= ((int) $defaultRepeat === (int) $r) ? 'selected' : '' ?>>
									<?= (int) $r ?>
								</option>
							<?php endforeach; ?>
						</select>

						<span>times.</span>

						<input
							id="sort_results"
							name="sort_results"
							type="checkbox"
							value="1"
							<?= $defaultSort ? 'checked' : '' ?>
						>

						<label for="sort_results">Sort dice sets?</label>
					</div>
				</td>

			</tr>

		</table>
    </div>

    <div class="card">

        <div class="preset-help">

            <p>
                <b>URL presets:</b>
            </p>
            <p>
                Add values to the URL query string.<br>
                The first parameter uses <code>?</code>, additional parameters use <code>&amp;</code>.
            </p>

            <table>

                <tr>
                    <td><code>dq=</code></td>
                    <td>(row 1 dice): 1 to 20</td>
                    <td><i>Example:</i> <code class="preset-example">dq=4</code></td>
                </tr>

                <tr>
                    <td><code>ds=</code></td>
                    <td>(row 1 sides): 2 to 100</td>
                    <td><i>Example:</i> <code class="preset-example">ds=12</code></td>
                </tr>

                <tr>
                    <td><code>dm=</code></td>
                    <td>(row 1 modifier): -20 to 20</td>
                    <td><i>Example:</i> <code class="preset-example">dm=+3</code></td>
                </tr>

                <tr>
                    <td><code>dd=</code></td>
                    <td>(row 1 mode): none, lowest, highest, <span class="special">wild</span>, <span class="special">stunt</span></td>
                    <td><i>Example:</i> <code class="preset-example">dd=wild</code></td>
                </tr>

                <tr>
                    <td><code>df=</code></td>
                    <td>(row 1 FUDGE flag): 1 to select <span class="special">d6 (Fudge)</span></td>
                    <td><i>Example:</i> <code class="preset-example">df=1</code></td>
                </tr>

                <tr>
                    <td><code>mdq=</code></td>
                    <td>(row 2 dice): -20 to 20; positive adds, negative subtracts</td>
                    <td><i>Example:</i> <code class="preset-example">mdq=-2</code></td>
                </tr>

                <tr>
                    <td><code>mds=</code></td>
                    <td>(row 2 sides): same as <code>ds</code></td>
                    <td><i>Example:</i> <code class="preset-example">mds=8</code></td>
                </tr>

                <tr>
                    <td><code>mdm=</code></td>
                    <td>(row 2 modifier): -60 to 60</td>
                    <td><i>Example:</i> <code class="preset-example">mdm=-1</code></td>
                </tr>

                <tr>
                    <td><code>mdd=</code></td>
                    <td>(row 2 mode): none, lowest, highest</td>
                    <td><i>Example:</i> <code class="preset-example">mdd=highest</code></td>
                </tr>

                <tr>
                    <td><code>dt=</code></td>
                    <td>(repeat): 1 to 100</td>
                    <td><i>Example:</i> <code class="preset-example">dt=10</code></td>
                </tr>

                <tr>
                    <td><code>sdt=</code></td>
                    <td>(sort dice sets): 1</td>
                    <td><i>Example:</i> <code class="preset-example">sdt=1</code></td>
                </tr>

            </table>

            <p>
                <b>Note:</b>
                If <span class="special">FUDGE dice</span>, a
                <span class="special">wild die</span>, or a
                <span class="special">stunt die</span> is selected,
                the subtraction row is hidden (and ignored).
            </p>

            <p>
                <i>Example:</i>
                <code class="preset-example-url"><?= h($exampleUrl) ?></code>
            </p>

        </div>

    </div>

</form>

<footer class="site-footer" role="contentinfo">
    <p>
        Copyright &copy; 2005-2026 Brandon Blackmoor
        <a href="mailto:bblackmoor@blackgate.net?subject=Secure%20Dice">&lt;bblackmoor@blackgate.net&gt;</a><br>
        Licensed under the GNU General Public License v3.0:
        <a href="https://www.gnu.org/licenses/gpl-3.0.en.html">https://www.gnu.org/licenses/gpl-3.0.en.html</a><br>
        Source: <a href="https://github.com/bblackmoor/securedice">https://github.com/bblackmoor/securedice</a><br>
        Last updated: <?= h(date("Y-m-d", filemtime(__FILE__))) ?>
    </p>
</footer>

</body>
</html>

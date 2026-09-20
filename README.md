# Secure Dice

## The Short Version

Secure Dice is a free, account-free online dice roller for tabletop roleplaying games. It uses PHP's cryptographically secure random-number generator and presents each roll as readable arithmetic.

- **Flexible dice pools:** Roll 1 to 20 dice with common die sizes from d2 through d100 and modifiers from -60 to +60.
- **Combined rolls:** Add or subtract a second dice pool, with its own die size, modifier, and roll mode.
- **Special modes:** Sum every die, drop the lowest or highest die, use a D6 System wild die, use a Dragon Age stunt die, or roll Fudge dice.
- **Repeated sets:** Generate as many as 100 sets at once, optionally sorted by final total.
- **Predictable URLs:** Copy or bookmark a URL that restores the complete roll setup.
- **Readable results:** Results show individual dice, dropped and special dice, modifiers, arithmetic, final totals, and summary statistics.
- **Portable records:** Copy the canonical result data as JSON together with its MD5 hash.

Secure Dice is available at [RPG Library](https://www.rpglibrary.org/software/securedice/).

## Download

Versioned ZIP packages are published on [GitHub Releases](https://github.com/bblackmoor/SecureDice/releases). Each release provides `SecureDice-<version>.zip`, built automatically from the corresponding version.

## Screenshots

## Getting Started

1. Choose the number and type of dice for the **Primary Roll**.
2. Optionally add a bonus or penalty and select a roll mode.
3. Optionally configure a **Secondary Roll** to add to or subtract from the primary result.
4. Choose how many sets to roll and whether to sort them by final total.
5. Select **Roll Dice**.

The **Copy URL** button copies the current setup as a reusable preset. **Reset** restores the default `3d6` roll.

## Dice and Roll Options

The primary pool supports 1 to 20 dice. Available standard dice are d2, d3, d4, d5, d6, d7, d8, d9, d10, d12, d13, d18, d20, d25, d30, d35, d40, d45, d50, d60, d70, d80, d90, and d100. It also supports d6-based Fudge dice.

Each standard pool can use a modifier from -60 to +60. The available roll modes are:

- **Sum them all:** Every die contributes to the pool total.
- **Drop the lowest die:** Rolls at least two dice and removes one lowest result.
- **Drop the highest die:** Rolls at least two dice and removes one highest result.
- **Wild die:** Uses at least two d6s. The final die is wild; a 6 explodes, while a 1 removes itself and the highest other die.
- **Stunt die:** Rolls exactly 3d6 and identifies the final die as the stunt die.
- **Fudge dice:** Converts each d6 result to minus, blank, or plus and sums the converted values.

Wild dice, stunt dice, and Fudge dice use only the primary pool. Selecting one of these modes disables the secondary pool.

The secondary pool supports up to 20 standard dice and can be added to or subtracted from the primary pool. It supports summing, dropping the lowest die, and dropping the highest die.

Repeat options include 1 through 20 sets and then 30 through 100 sets in increments of 10. Sorted results are ordered from highest final total to lowest while retaining their original set numbers.

## Results

Each result set displays its dice and modifier as an arithmetic expression followed by the final total. Dropped dice remain visible but are marked as excluded. Wild-die explosions, wild complications, stunt dice, bonuses, and penalties receive distinct treatments.

The results page also provides:

- Minimum, maximum, and average final totals.
- A preset URL for rolling the same specification again.
- Canonical JSON containing the specification, generated time, individual dice, and totals.
- An MD5 hash of that JSON.
- Buttons for copying the preset URL, JSON, or hash.

The MD5 hash can reveal whether copied JSON has changed. It is an integrity check, not a digital signature or proof that a result originated from a particular server.

## URL Presets

Secure Dice encodes roll settings in ordinary query parameters so presets can be bookmarked or shared.

| Parameter | Purpose | Example |
| :-------- | :------ | :------ |
| `aq` | Primary dice count, 1 to 20 | `aq=4` |
| `as` | Primary die sides | `as=12` |
| `am` | Primary modifier, -60 to +60 | `am=3` |
| `ad` | Primary mode: `none`, `lowest`, `highest`, `wild`, or `stunt` | `ad=wild` |
| `af` | Select Fudge dice when set to `1` | `af=1` |
| `bq` | Secondary dice count, -20 to 20; the sign determines addition or subtraction | `bq=-2` |
| `bs` | Secondary die sides | `bs=8` |
| `bm` | Secondary modifier, -60 to +60 | `bm=-1` |
| `bd` | Secondary mode: `none`, `lowest`, or `highest` | `bd=highest` |
| `dt` | Number of repeated sets | `dt=10` |
| `sdt` | Sort sets by total when set to `1` | `sdt=1` |

Example:

```text
https://www.rpglibrary.org/software/securedice/securedice.php?aq=7&as=4&dt=5&ad=highest&sdt=1
```

## Installation

Place the repository files in a PHP-enabled web directory and direct users to `securedice.php`. The included `.htaccess` makes `securedice.php` the default page on Apache and redirects explicit requests for `index.php`.

Secure Dice requires:

- PHP with `random_int()` and session support.
- A web server capable of running PHP.
- Browser cookies for the short-lived session that transfers a roll to its results page.

No database or account system is required.

## Versioning

Secure Dice uses `2.0.(build number)` versions. The tracked pre-commit hook sets the build number to the number of the commit being created and records the complete version in `VERSION`. The page footers read both the version and its last-updated date from that file.

Configure a new clone to use the hook with:

```shell
git config core.hooksPath .githooks
```

## AI Disclaimer

AI-assisted tools were used during the development of this project. The author reviewed and approved the resulting code and documentation and remains responsible for the project.

---

Copyright © 2005-2026 Brandon Blackmoor (<bblackmoor@blackgate.net>)<br>
Licensed under the GNU General Public License v3.0 (GPL-3.0):<br>
https://www.gnu.org/licenses/gpl-3.0.en.html<br>
Source: https://github.com/bblackmoor/securedice

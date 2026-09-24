<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/storage.php';

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute' || count($argv) !== 2) {
    fwrite(STDERR, "Run from the CLI with --execute after taking a database backup and stopping Secure Dice 1 writes.\n");
    exit(2);
}

/** @return bool */
function legacy_table_exists(PDO $db, string $name): bool
{
    $statement = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name');
    $statement->execute([':name' => $name]);
    return (int) $statement->fetchColumn() === 1;
}

function legacy_state(PDO $db): string
{
    $statement = $db->prepare("SELECT meta_value FROM sd2_schema_meta WHERE meta_key = 'legacy_import'");
    $statement->execute();
    return (string) ($statement->fetchColumn() ?: '');
}

function set_legacy_state(PDO $db, string $state): void
{
    $statement = $db->prepare("INSERT INTO sd2_schema_meta (meta_key, meta_value)
        VALUES ('legacy_import', :state)
        ON DUPLICATE KEY UPDATE meta_value = :updated_state");
    $statement->execute([':state' => $state, ':updated_state' => $state]);
}

/** Preserve all four legacy roll fields exactly; no old addresses are read. */
function legacy_roll_matches(array $source, array $copy): bool
{
    return (int) $source['id'] === (int) $copy['old_id']
        && (int) $source['dice_rolled'] === (int) $copy['dice_rolled']
        && (string) $source['hash'] === (string) $copy['legacy_hash']
        && (string) $source['results'] === (string) $copy['results'];
}

function scan_legacy_rolls(PDO $source, PDO $target, string $table, int $maxId, bool $copy): int
{
    // An unbuffered cursor keeps even a 1.4 GiB table out of PHP memory.
    // Separate connections permit target queries while the source cursor is active.
    $scan = $source->query("SELECT id, dice_rolled, hash, results FROM `{$table}`
        WHERE id <= {$maxId} ORDER BY id");
    $lookup = $target->prepare('SELECT old_id, dice_rolled, legacy_hash, results
        FROM sd2_legacy_rolls WHERE old_id = :old_id');
    $insert = $target->prepare('INSERT INTO sd2_legacy_rolls
        (old_id, dice_rolled, legacy_hash, results) VALUES (:id, :dice, :hash, :results)');
    $count = 0;

    try {
        while (($row = $scan->fetch(PDO::FETCH_ASSOC)) !== false) {
            $lookup->execute([':old_id' => (int) $row['id']]);
            $saved = $lookup->fetch(PDO::FETCH_ASSOC);
            $lookup->closeCursor();

            if ($saved === false && $copy) {
                $insert->execute([
                    ':id' => (int) $row['id'],
                    ':dice' => (int) $row['dice_rolled'],
                    ':hash' => (string) $row['hash'],
                    ':results' => (string) $row['results'],
                ]);
            } elseif ($saved === false || !legacy_roll_matches($row, $saved)) {
                throw new RuntimeException('Legacy roll mismatch at ID ' . $row['id']);
            }

            ++$count;
            if ($count % 10000 === 0) {
                fwrite(STDOUT, ($copy ? 'Copied/checked ' : 'Verified ') . "{$count} rolls\n");
            }
        }
    } finally {
        $scan->closeCursor();
    }
    return $count;
}

function verify_legacy_rolls(PDO $source, PDO $target, string $table, int $maxId): int
{
    $count = scan_legacy_rolls($source, $target, $table, $maxId, false);
    $targetCount = (int) $target->query('SELECT COUNT(*) FROM sd2_legacy_rolls')->fetchColumn();
    $sourceCount = (int) $target->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    $sourceMax = (int) $target->query("SELECT COALESCE(MAX(id), 0) FROM `{$table}`")->fetchColumn();
    if ($count !== $sourceCount || $count !== $targetCount || $maxId !== $sourceMax) {
        throw new RuntimeException('Roll counts or source watermark changed; stop legacy writes and rerun.');
    }
    return $count;
}

function rename_legacy_tables(PDO $db): void
{
    $names = [
        'secure_dice' => 'sd1_secure_dice',
        'rpglibrary_securedice_rolllog' => 'sd1_rpglibrary_securedice_rolllog',
        'rpglibrary_securedice_senders' => 'sd1_rpglibrary_securedice_senders',
        'rpglibrary_securedice_recipients' => 'sd1_rpglibrary_securedice_recipients',
    ];
    $renames = [];
    foreach ($names as $old => $new) {
        if (legacy_table_exists($db, $new)) {
            throw new RuntimeException("Destination {$new} already exists; no tables were renamed.");
        }
        if (legacy_table_exists($db, $old)) {
            $renames[] = "`{$old}` TO `{$new}`";
        }
    }
    if (!legacy_table_exists($db, 'secure_dice')) {
        throw new RuntimeException('Source secure_dice does not exist.');
    }
    $db->exec('RENAME TABLE ' . implode(', ', $renames));
}

try {
    $db = result_storage_connection();
    if ((int) $db->query("SELECT GET_LOCK(CONCAT(DATABASE(), ':sd2_legacy_import'), 0)")->fetchColumn() !== 1) {
        throw new RuntimeException('Another migration is already running.');
    }

    try {
        if (legacy_state($db) === 'complete') {
            echo "Legacy roll migration already completed; skipped.\n";
            exit(0);
        }
        $renamed = legacy_table_exists($db, 'sd1_secure_dice');
        $table = $renamed ? 'sd1_secure_dice' : 'secure_dice';
        if (!legacy_table_exists($db, $table)) {
            throw new RuntimeException('Neither the original nor renamed roll table exists.');
        }
        if ($renamed && legacy_table_exists($db, 'secure_dice')) {
            throw new RuntimeException('Both original and renamed roll tables exist; inspect manually.');
        }

        $maxId = (int) $db->query("SELECT COALESCE(MAX(id), 0) FROM `{$table}`")->fetchColumn();
        $source = securedice_mysql_connection(false);
        if (!$renamed) {
            scan_legacy_rolls($source, $db, $table, $maxId, true);
        }
        $count = verify_legacy_rolls($source, $db, $table, $maxId);
        // Store the verified state before a possible crash between RENAME and completion.
        set_legacy_state($db, 'verified');
        if (!$renamed) {
            rename_legacy_tables($db);
            $table = 'sd1_secure_dice';
            verify_legacy_rolls($source, $db, $table, $maxId);
        }
        set_legacy_state($db, 'complete');
        echo "Verified {$count} legacy rolls. Old tables retained under sd1_ names.\n";
    } finally {
        $db->query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':sd2_legacy_import'))");
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Migration stopped: {$e->getMessage()}\n");
    exit(1);
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/mysql-test-bootstrap.php';
securedice_test_reset();
putenv('SECUREDICE_SECRET=' . str_repeat('33', 32));
require_once dirname(__DIR__) . '/storage.php';

$db = result_storage_connection();
$db->exec('CREATE TABLE secure_dice (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    dice_rolled INT NOT NULL DEFAULT 0,
    hash CHAR(32) NOT NULL,
    results MEDIUMTEXT NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3');
foreach (['rolllog', 'senders', 'recipients'] as $suffix) {
    $db->exec("CREATE TABLE rpglibrary_securedice_{$suffix} (id INT PRIMARY KEY) ENGINE=InnoDB");
}
$insert = $db->prepare('INSERT INTO secure_dice (id, dice_rolled, hash, results)
    VALUES (?, ?, ?, ?)');
$insert->execute([1, 4, str_repeat('a', 32), 'Roll 4d6: 1 + 2 + 3 + 4']);
$insert->execute([12, 2, str_repeat('b', 32), "Roll: ñ + 4\n"]);

$run = escapeshellarg(PHP_BINARY) . ' '
    . escapeshellarg(dirname(__DIR__) . '/bin/migrate-legacy-mysql.php') . ' --execute';
$db->exec("INSERT INTO sd2_legacy_rolls (old_id, dice_rolled, legacy_hash, results)
    VALUES (1, 4, '" . str_repeat('a', 32) . "', 'corrupted copy')");
exec($run . ' 2>&1', $failedOutput, $failedStatus);
if ($failedStatus === 0 || !str_contains(implode("\n", $failedOutput), 'mismatch')
    || (int) $db->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'secure_dice'")->fetchColumn() !== 1) {
    throw new RuntimeException('A mismatched copy did not stop before source table renaming.');
}
$db->exec("DELETE FROM sd2_legacy_rolls WHERE old_id = 1");
exec($run . ' 2>&1', $output, $status);
if ($status !== 0) {
    throw new RuntimeException('Legacy migration failed: ' . implode("\n", $output));
}
if ((int) $db->query('SELECT COUNT(*) FROM sd2_legacy_rolls')->fetchColumn() !== 2
    || (int) $db->query('SELECT COUNT(*) FROM sd1_secure_dice')->fetchColumn() !== 2
    || (int) $db->query('SELECT COUNT(*) FROM sd2_recipients')->fetchColumn() !== 0
    || (string) $db->query("SELECT meta_value FROM sd2_schema_meta WHERE meta_key = 'legacy_import'")->fetchColumn() !== 'complete') {
    throw new RuntimeException('The legacy roll copy or completion marker is missing.');
}
foreach (['rolllog', 'senders', 'recipients'] as $suffix) {
    $db->query("SELECT COUNT(*) FROM sd1_rpglibrary_securedice_{$suffix}")->fetchColumn();
}
$output = [];
exec($run . ' 2>&1', $output, $status);
if ($status !== 0 || !str_contains(implode("\n", $output), 'already completed')
    || (int) $db->query('SELECT COUNT(*) FROM sd2_legacy_rolls')->fetchColumn() !== 2) {
    throw new RuntimeException('A repeat invocation did not skip the completed import.');
}
echo "Legacy MySQL migration tests passed.\n";

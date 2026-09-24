<?php

declare(strict_types=1);

/** Refuse to alter a non-test database, even if production credentials leak into CI. */
function securedice_test_reset(): void
{
    $name = getenv('SECUREDICE_TEST_DB_NAME');
    if (!is_string($name) || preg_match('/^securedice_test_[a-z0-9_]+$/', $name) !== 1) {
        throw new RuntimeException('Set SECUREDICE_TEST_DB_NAME to a dedicated securedice_test_* database.');
    }
    putenv('SECUREDICE_DB_HOST=' . (getenv('SECUREDICE_TEST_DB_HOST') ?: '127.0.0.1'));
    putenv('SECUREDICE_DB_PORT=' . (getenv('SECUREDICE_TEST_DB_PORT') ?: '3306'));
    putenv('SECUREDICE_DB_NAME=' . $name);
    putenv('SECUREDICE_DB_USER=' . (getenv('SECUREDICE_TEST_DB_USER') ?: 'root'));
    putenv('SECUREDICE_DB_PASSWORD=' . (getenv('SECUREDICE_TEST_DB_PASSWORD') ?: ''));
    $db = new PDO(
        'mysql:host=' . getenv('SECUREDICE_DB_HOST') . ';dbname=' . $name . ';charset=utf8mb4',
        getenv('SECUREDICE_DB_USER'),
        getenv('SECUREDICE_DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    $tables = $db->query('SELECT TABLE_NAME FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if (str_starts_with($table, 'sd2_') || str_starts_with($table, 'sd1_')
            || $table === 'secure_dice' || str_starts_with($table, 'rpglibrary_securedice_')) {
            $db->exec('DROP TABLE `' . $table . '`');
        } else {
            throw new RuntimeException('Unexpected table in dedicated test database: ' . $table);
        }
    }
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
}

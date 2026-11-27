<?php

declare(strict_types=1);

/** Secure Dice 2 owns only sd2_ tables in the shared rpglibrary database. */
function securedice_mysql_schema_statements(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS sd2_result_records (
            public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            schema_version INT NOT NULL,
            generated_at VARCHAR(35) NOT NULL,
            canonical_json LONGTEXT NOT NULL,
            content_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            auth_hmac_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            stored_at VARCHAR(35) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS sd2_recipients (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            email_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            email_ciphertext TEXT NOT NULL,
            status VARCHAR(12) NOT NULL,
            created_at VARCHAR(35) NOT NULL,
            updated_at VARCHAR(35) NOT NULL,
            confirmed_at VARCHAR(35) NULL,
            revoked_at VARCHAR(35) NULL,
            delivery_mode VARCHAR(12) NOT NULL DEFAULT 'tabletop'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS sd2_consent_challenges (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            recipient_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            expires_at VARCHAR(35) NOT NULL,
            consumed_at VARCHAR(35) NULL,
            created_at VARCHAR(35) NOT NULL,
            purpose VARCHAR(24) NOT NULL DEFAULT 'enroll',
            KEY sd2_challenges_recipient (recipient_id, consumed_at, expires_at),
            FOREIGN KEY (recipient_id) REFERENCES sd2_recipients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS sd2_recipient_capabilities (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            recipient_id BIGINT UNSIGNED NOT NULL,
            code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            status VARCHAR(12) NOT NULL,
            created_at VARCHAR(35) NOT NULL,
            revoked_at VARCHAR(35) NULL,
            KEY sd2_capabilities_recipient (recipient_id, status),
            FOREIGN KEY (recipient_id) REFERENCES sd2_recipients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS sd2_recipient_management_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            recipient_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            status VARCHAR(12) NOT NULL,
            created_at VARCHAR(35) NOT NULL,
            revoked_at VARCHAR(35) NULL,
            KEY sd2_management_recipient (recipient_id, status),
            FOREIGN KEY (recipient_id) REFERENCES sd2_recipients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS sd2_email_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            result_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            source_bucket CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            intended_count INT NOT NULL,
            opted_in_count INT NOT NULL,
            not_opted_in_count INT NOT NULL,
            not_queued_count INT NOT NULL,
            created_at VARCHAR(35) NOT NULL,
            FOREIGN KEY (result_id) REFERENCES sd2_result_records(public_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS sd2_outbound_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            message_type VARCHAR(32) NOT NULL,
            recipient_id BIGINT UNSIGNED NOT NULL,
            request_id BIGINT UNSIGNED NULL,
            result_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
            payload_ciphertext LONGTEXT NULL,
            status VARCHAR(12) NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            available_at VARCHAR(35) NOT NULL,
            created_at VARCHAR(35) NOT NULL,
            claimed_at VARCHAR(35) NULL,
            lease_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
            sent_at VARCHAR(35) NULL,
            last_error VARCHAR(255) NULL,
            KEY sd2_messages_pending (status, available_at),
            KEY sd2_messages_batch (recipient_id, message_type, status, created_at),
            FOREIGN KEY (recipient_id) REFERENCES sd2_recipients(id) ON DELETE CASCADE,
            FOREIGN KEY (request_id) REFERENCES sd2_email_requests(id),
            FOREIGN KEY (result_id) REFERENCES sd2_result_records(public_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS sd2_rate_limits (
            bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            window_started_at BIGINT NOT NULL,
            attempts INT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS sd2_recipient_unsubscribe_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            recipient_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            created_at VARCHAR(35) NOT NULL,
            used_at VARCHAR(35) NULL,
            KEY sd2_unsubscribe_recipient (recipient_id, used_at),
            FOREIGN KEY (recipient_id) REFERENCES sd2_recipients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS sd2_email_delivery_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            message_id BIGINT UNSIGNED NOT NULL UNIQUE,
            request_id BIGINT UNSIGNED NULL,
            recipient_id BIGINT UNSIGNED NOT NULL,
            result_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
            message_type VARCHAR(32) NOT NULL,
            status VARCHAR(12) NOT NULL,
            attempt_count INT NOT NULL DEFAULT 0,
            queued_at VARCHAR(35) NOT NULL,
            last_attempt_at VARCHAR(35) NULL,
            delivered_at VARCHAR(35) NULL,
            error_category VARCHAR(64) NULL,
            provider_message_id VARCHAR(255) NULL,
            KEY sd2_history_recipient (recipient_id, queued_at),
            FOREIGN KEY (message_id) REFERENCES sd2_outbound_messages(id),
            FOREIGN KEY (recipient_id) REFERENCES sd2_recipients(id) ON DELETE CASCADE,
            FOREIGN KEY (request_id) REFERENCES sd2_email_requests(id),
            FOREIGN KEY (result_id) REFERENCES sd2_result_records(public_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS sd2_result_delivery_claims (
            recipient_id BIGINT UNSIGNED NOT NULL,
            result_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            created_at VARCHAR(35) NOT NULL,
            PRIMARY KEY (recipient_id, result_id),
            FOREIGN KEY (recipient_id) REFERENCES sd2_recipients(id) ON DELETE CASCADE,
            FOREIGN KEY (result_id) REFERENCES sd2_result_records(public_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS sd2_legacy_rolls (
            old_id INT UNSIGNED NOT NULL PRIMARY KEY,
            dice_rolled INT NOT NULL,
            legacy_hash CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            results MEDIUMTEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS sd2_schema_meta (
            meta_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
            meta_value VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

function initialize_result_storage(PDO $connection): void
{
    if (securedice_mysql_schema_ready($connection)) {
        return;
    }

    $lock = $connection->query("SELECT GET_LOCK(CONCAT(DATABASE(), ':sd2_schema'), 15)");
    if ($lock->fetchColumn() != 1) {
        throw new RuntimeException('Could not lock Secure Dice schema initialization.');
    }

    try {
        if (securedice_mysql_schema_ready($connection)) {
            return;
        }
        foreach (securedice_mysql_schema_statements() as $statement) {
            $connection->exec($statement);
        }

        $connection->exec("INSERT INTO sd2_schema_meta (meta_key, meta_value)
            VALUES ('schema_version', '1') ON DUPLICATE KEY UPDATE meta_value = '1'");
    } finally {
        $connection->query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':sd2_schema'))");
    }
}

function securedice_mysql_schema_ready(PDO $connection): bool
{
    $table = $connection->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sd2_schema_meta'");
    if ((int) $table->fetchColumn() !== 1) {
        return false;
    }
    $version = $connection->query("SELECT meta_value FROM sd2_schema_meta WHERE meta_key = 'schema_version'");
    $value = $version->fetchColumn();
    if ($value !== false && $value !== '1') {
        throw new RuntimeException('The Secure Dice database uses an unsupported schema.');
    }
    return $value === '1';
}

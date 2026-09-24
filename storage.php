<?php
// storage.php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mysql-schema.php';

class InvalidResultIdException extends RuntimeException {}
class ResultIntegrityException extends RuntimeException {}
class UnsupportedResultSchemaException extends RuntimeException {}

/** Create a connection using the private DreamHost MySQL credentials. */
function securedice_mysql_connection(bool $buffered = true): PDO
{
    if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('Secure Dice requires PDO MySQL.');
    }

    $host = trim((string) getenv('SECUREDICE_DB_HOST'));
    $name = trim((string) getenv('SECUREDICE_DB_NAME'));
    $user = trim((string) getenv('SECUREDICE_DB_USER'));
    $password = getenv('SECUREDICE_DB_PASSWORD');
    $port = getenv('SECUREDICE_DB_PORT');
    $port = $port === false || $port === '' ? '3306' : trim($port);
    if ($host === '' || $name === '' || $user === '' || $password === false
        || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('Secure Dice MySQL configuration is incomplete.');
    }

    return new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => $buffered,
        ]
    );
}

function result_storage_connection(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    try {
        $connection = securedice_mysql_connection();
        initialize_result_storage($connection);
    } catch (Throwable $e) {
        $connection = null;
        error_log('Secure Dice result storage error: ' . $e->getMessage());
        throw new RuntimeException('The result database could not be opened.');
    }
    return $connection;
}

/**
 * Recursively sort object keys while preserving the order of list elements.
 */
function canonicalize_json_value($value)
{
    if (!is_array($value)) {
        return $value;
    }

    if (result_array_is_list($value)) {
        return array_map('canonicalize_json_value', $value);
    }

    ksort($value, SORT_STRING);

    foreach ($value as $key => $item) {
        $value[$key] = canonicalize_json_value($item);
    }

    return $value;
}

/** Return true when an array uses consecutive integer keys starting at zero. */
function result_array_is_list(array $value): bool
{
    return ($value === []) || (array_keys($value) === range(0, count($value) - 1));
}

/**
 * Encode a result in a deterministic form suitable for permanent storage.
 */
function encode_canonical_result(array $result): string
{
    try {
        return json_encode(
            canonicalize_json_value($result),
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        );
    } catch (JsonException $e) {
        error_log('Secure Dice canonical JSON error: ' . $e->getMessage());
        throw new RuntimeException('The roll could not be stored. Please try again later.');
    }
}

/** Authenticate canonical result bytes with a server-only, domain-separated key. */
function result_authentication_hmac(string $canonicalJson): string
{
    return hash_hmac(
        'sha256',
        "Secure Dice stored result v1\0" . $canonicalJson,
        securedice_secret_bytes()
    );
}

/**
 * Persist one result and return the result augmented with its public ID.
 *
 * There is deliberately no update function. Authentication detects direct
 * database changes when a result is read.
 */
function store_result_record(array $result): array
{
    if (isset($result['result_id'])) {
        throw new InvalidArgumentException('The result already has an ID.');
    }

    $result['result_id'] = bin2hex(random_bytes(16));
    $canonicalJson = encode_canonical_result($result);
    $contentSha256 = hash('sha256', $canonicalJson);
    $authenticationHmac = result_authentication_hmac($canonicalJson);
    $generatedAt = (string) ($result['generated_at'] ?? '');
    $schemaVersion = (int) ($result['schema_version'] ?? 0);

    if ($generatedAt === '' || $schemaVersion < 1) {
        throw new InvalidArgumentException('The result record is incomplete.');
    }

    try {
        $statement = result_storage_connection()->prepare(
            'INSERT INTO sd2_result_records (
                public_id,
                schema_version,
                generated_at,
                canonical_json,
                content_sha256,
                auth_hmac_sha256,
                stored_at
            ) VALUES (
                :public_id,
                :schema_version,
                :generated_at,
                :canonical_json,
                :content_sha256,
                :auth_hmac_sha256,
                :stored_at
            )'
        );

        $statement->execute([
            ':public_id' => $result['result_id'],
            ':schema_version' => $schemaVersion,
            ':generated_at' => $generatedAt,
            ':canonical_json' => $canonicalJson,
            ':content_sha256' => $contentSha256,
            ':auth_hmac_sha256' => $authenticationHmac,
            ':stored_at' => gmdate(DATE_ATOM),
        ]);

    } catch (Throwable $e) {
        error_log('Secure Dice result insert error: ' . $e->getMessage());
        throw new RuntimeException('The roll could not be stored. Please try again later.');
    }

    return $result;
}

/**
 * Validate the parts of a version-2 result required for safe presentation.
 */
function validate_result_payload_v2(array $result): bool
{
    if (
        (int) ($result['schema_version'] ?? 0) !== 2
        || preg_match('/^[a-f0-9]{32}$/', (string) ($result['result_id'] ?? '')) !== 1
        || (string) ($result['generated_at'] ?? '') === ''
        || !is_array($result['specification'] ?? null)
        || !is_array($result['summary'] ?? null)
        || !is_array($result['sets'] ?? null)
    ) {
        return false;
    }

    try {
        new DateTimeImmutable((string) $result['generated_at']);
    } catch (Throwable $e) {
        return false;
    }

    $specification = $result['specification'];
    $summary = $result['summary'];
    $pools = $specification['pools'] ?? null;
    $repeat = (int) ($specification['repeat'] ?? 0);
    $sets = $result['sets'];

    if (
        $repeat < 1
        || !is_array($pools)
        || !is_array($pools['A'] ?? null)
        || (($pools['B'] ?? null) !== null && !is_array($pools['B']))
        || !isset($summary['min'], $summary['max'], $summary['avg'])
        || !is_numeric($summary['min'])
        || !is_numeric($summary['max'])
        || !is_numeric($summary['avg'])
        || !result_array_is_list($sets)
        || count($sets) !== $repeat
    ) {
        return false;
    }

    foreach ($sets as $set) {
        if (
            !is_array($set)
            || !isset($set['number'], $set['total'])
            || !is_int($set['number'])
            || !is_int($set['total'])
            || !is_array($set['terms'] ?? null)
            || !result_array_is_list($set['terms'])
            || $set['terms'] === []
        ) {
            return false;
        }

        foreach ($set['terms'] as $term) {
            if (
                !is_array($term)
                || !isset($term['pool'], $term['operator'])
                || !is_array($term['result'] ?? null)
                || !is_array($term['result']['dice'] ?? null)
                || !result_array_is_list($term['result']['dice'])
            ) {
                return false;
            }

            foreach ($term['result']['dice'] as $die) {
                if (
                    !is_array($die)
                    || !isset($die['index'], $die['value'], $die['kept'], $die['role'])
                    || !is_int($die['index'])
                    || !is_int($die['value'])
                    || !is_bool($die['kept'])
                    || !is_string($die['role'])
                ) {
                    return false;
                }
            }
        }
    }

    return true;
}

/**
 * Load and authenticate one immutable result record.
 *
 * A null return means the well-formed public ID does not exist. Exceptions
 * distinguish malformed IDs, corrupt records, and unsupported schemas.
 */
function load_result_record(string $publicId): ?array
{
    $publicId = strtolower(trim($publicId));

    if (preg_match('/^[a-f0-9]{32}$/', $publicId) !== 1) {
        throw new InvalidResultIdException('The result ID is invalid.');
    }

    try {
        $statement = result_storage_connection()->prepare(
            'SELECT
                public_id,
                schema_version,
                generated_at,
                canonical_json,
                content_sha256,
                auth_hmac_sha256,
                stored_at
            FROM sd2_result_records
            WHERE public_id = :public_id'
        );
        $statement->execute([':public_id' => $publicId]);
        $record = $statement->fetch();
    } catch (Throwable $e) {
        error_log('Secure Dice result read error: ' . $e->getMessage());
        throw new RuntimeException('The result database could not be read.');
    }

    if (!is_array($record)) {
        return null;
    }

    $canonicalJson = (string) ($record['canonical_json'] ?? '');
    $storedDigest = (string) ($record['content_sha256'] ?? '');
    $calculatedDigest = hash('sha256', $canonicalJson);
    $storedHmac = (string) ($record['auth_hmac_sha256'] ?? '');
    $calculatedHmac = result_authentication_hmac($canonicalJson);

    if (
        strlen($storedDigest) !== 64
        || !hash_equals($storedDigest, $calculatedDigest)
        || strlen($storedHmac) !== 64
        || !hash_equals($storedHmac, $calculatedHmac)
    ) {
        throw new ResultIntegrityException('The stored result failed its integrity check.');
    }

    try {
        $result = json_decode($canonicalJson, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new ResultIntegrityException('The stored result is not valid JSON.');
    }

    if (
        !is_array($result)
        || (string) ($result['result_id'] ?? '') !== $publicId
        || (int) ($result['schema_version'] ?? 0) !== (int) $record['schema_version']
        || (string) ($result['generated_at'] ?? '') !== (string) $record['generated_at']
    ) {
        throw new ResultIntegrityException('The stored result metadata does not match its record.');
    }

    if ((int) $record['schema_version'] !== 2) {
        throw new UnsupportedResultSchemaException('The stored result uses an unsupported schema.');
    }

    if (!validate_result_payload_v2($result)) {
        throw new ResultIntegrityException('The stored result structure is invalid.');
    }

    return [
        'data' => $result,
        'canonical_json' => $canonicalJson,
        'stored_at' => (string) $record['stored_at'],
    ];
}

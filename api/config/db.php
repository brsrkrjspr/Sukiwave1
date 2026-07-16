<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const DB_HOST_DEFAULT = '127.0.0.1';
const DB_PORT_DEFAULT = 3306;
const DB_NAME_DEFAULT = 'sukiwave_db';
const DB_USER_DEFAULT = 'sukiwave_user';
const DB_PASS_DEFAULT = 'sukiwave_pass123';

function env_or_default(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

function db_config(): array
{
    // Railway commonly exposes a URL and individual MYSQL* variables.
    $databaseUrl = env_or_default('MYSQL_URL')
        ?? env_or_default('DATABASE_URL')
        ?? env_or_default('RAILWAY_DATABASE_URL');
    if ($databaseUrl !== null) {
        $parts = parse_url($databaseUrl);
        if (is_array($parts)) {
            return [
                'host' => (string)($parts['host'] ?? DB_HOST_DEFAULT),
                'port' => (int)($parts['port'] ?? DB_PORT_DEFAULT),
                'name' => ltrim((string)($parts['path'] ?? DB_NAME_DEFAULT), '/'),
                'user' => (string)($parts['user'] ?? DB_USER_DEFAULT),
                'pass' => (string)($parts['pass'] ?? DB_PASS_DEFAULT),
            ];
        }
    }

    return [
        'host' => (string)env_or_default('MYSQLHOST', DB_HOST_DEFAULT),
        'port' => (int)env_or_default('MYSQLPORT', (string)DB_PORT_DEFAULT),
        'name' => (string)env_or_default('MYSQLDATABASE', DB_NAME_DEFAULT),
        'user' => (string)env_or_default('MYSQLUSER', DB_USER_DEFAULT),
        'pass' => (string)env_or_default('MYSQLPASSWORD', DB_PASS_DEFAULT),
    ];
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = db_config();
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $cfg['host'],
        $cfg['port'],
        $cfg['name']
    );

    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

/**
 * True when `products.image_url` exists (older DBs may not have run the migration).
 */
function sukiwave_products_has_image_url_column(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($db === false || $db === null || (string)$db === '') {
            $cached = false;
            return false;
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([(string)$db, 'products', 'image_url']);
        $cached = (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * True when seller_profiles has store pickup columns (see schema.v4).
 */
function sukiwave_seller_profiles_has_store_location_columns(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($db === false || $db === null || (string)$db === '') {
            $cached = false;
            return false;
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([(string)$db, 'seller_profiles', 'store_latitude']);
        $cached = (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * True when seller_profiles has store image column (see schema.v6).
 */
function sukiwave_seller_profiles_has_store_image_column(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($db === false || $db === null || (string)$db === '') {
            $cached = false;
            return false;
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([(string)$db, 'seller_profiles', 'store_image_url']);
        $cached = (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * True when users has profile image column.
 */
function sukiwave_users_has_profile_image_column(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($db === false || $db === null || (string)$db === '') {
            $cached = false;
            return false;
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([(string)$db, 'users', 'profile_image_url']);
        $cached = (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * True when users.firebase_uid exists (social / Firebase link column).
 */
function sukiwave_users_has_firebase_uid_column(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($db === false || $db === null || (string)$db === '') {
            $cached = false;
            return false;
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([(string)$db, 'users', 'firebase_uid']);
        $cached = (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

function sukiwave_seller_profiles_has_settings_columns(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($db === false || $db === null || (string)$db === '') {
            $cached = false;
            return false;
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME IN (?, ?)'
        );
        $st->execute([(string)$db, 'seller_profiles', 'store_description', 'operating_days_json']);
        $cached = (int)$st->fetchColumn() >= 2;
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

function sukiwave_rider_profiles_has_compliance_columns(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($db === false || $db === null || (string)$db === '') {
            $cached = false;
            return false;
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME IN (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            (string)$db,
            'rider_profiles',
            'profile_image_url',
            'registration_number',
            'valid_id_number',
            'license_file_url',
            'registration_file_url',
            'valid_id_file_url',
        ]);
        $cached = (int)$st->fetchColumn() >= 6;
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * True when rider_profiles has the default ("home") location columns (see schema.v14).
 * Only positive results are memoized so callers can see new columns after a live migration.
 */
function sukiwave_rider_profiles_has_default_location_columns(PDO $pdo): bool
{
    static $cachedTrue = null;
    if ($cachedTrue === true) {
        return true;
    }
    try {
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($db === false || $db === null || (string)$db === '') {
            return false;
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME IN (?, ?, ?, ?, ?)'
        );
        $st->execute([
            (string)$db,
            'rider_profiles',
            'home_address_line1',
            'home_barangay',
            'home_city',
            'home_latitude',
            'home_longitude',
        ]);
        $ok = (int)$st->fetchColumn() >= 5;
        if ($ok) {
            $cachedTrue = true;
        }
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Applies schema.v14 home-location columns when missing (idempotent).
 * Requires ALTER privilege on rider_profiles. Returns false if migration could not complete.
 */
function sukiwave_ensure_rider_default_location_columns(PDO $pdo): bool
{
    if (sukiwave_rider_profiles_has_default_location_columns($pdo)) {
        return true;
    }
    $defs = [
        'home_address_line1' => 'VARCHAR(255) NULL',
        'home_barangay' => 'VARCHAR(128) NULL',
        'home_city' => 'VARCHAR(128) NULL',
        'home_latitude' => 'DECIMAL(10,7) NULL',
        'home_longitude' => 'DECIMAL(10,7) NULL',
    ];
    foreach ($defs as $col => $sqlType) {
        try {
            $pdo->exec(
                'ALTER TABLE rider_profiles ADD COLUMN `' . $col . '` ' . $sqlType
            );
        } catch (PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            // 1060 = ER_DUP_FIELDNAME (column already exists)
            if ($code !== 1060) {
                return false;
            }
        }
    }

    return sukiwave_rider_profiles_has_default_location_columns($pdo);
}

/**
 * Authoritative Pabili completion table (see schema.v13). Lazily created so
 * deployments that never ran the v13 SQL migration (e.g. Railway DBs built
 * before that file existed) self-heal on first use rather than 1146'ing out
 * of chat-send / mark-done.
 */
function sukiwave_ensure_pabili_sessions_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS pabili_sessions (
            buyer_user_id INT UNSIGNED NOT NULL,
            rider_user_id INT UNSIGNED NOT NULL,
            session_id BIGINT UNSIGNED NOT NULL,
            pabili_status ENUM('active', 'completed') NOT NULL DEFAULT 'active',
            completed_at DATETIME NULL,
            completed_by ENUM('buyer', 'rider') NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (buyer_user_id, rider_user_id, session_id),
            KEY idx_pabili_sessions_status (pabili_status, rider_user_id),
            KEY idx_pabili_sessions_completed (completed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $done = true;
}

/**
 * Table for the buyer's last live GPS during Pabili (see schema.v15).
 * CREATE TABLE IF NOT EXISTS is safe for repeated calls.
 */
function sukiwave_ensure_pabili_pair_buyer_locations_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS pabili_pair_buyer_locations (
            buyer_user_id INT UNSIGNED NOT NULL,
            rider_user_id INT UNSIGNED NOT NULL,
            session_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            latitude DECIMAL(10,7) NOT NULL,
            longitude DECIMAL(10,7) NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (buyer_user_id, rider_user_id, session_id),
            KEY idx_pb_rider (rider_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // Lazy migration for deployments that created the table from schema.v15
    // (before session_id was part of the key). Only runs when the column is
    // missing, so it's idempotent and cheap on subsequent calls.
    try {
        $check = $pdo->query(
            "SELECT COUNT(*) AS c
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'pabili_pair_buyer_locations'
               AND column_name = 'session_id'"
        );
        $colExists = $check ? (int)($check->fetch()['c'] ?? 0) : 0;
        if ($colExists === 0) {
            $pdo->exec(
                'ALTER TABLE pabili_pair_buyer_locations
                   ADD COLUMN session_id BIGINT UNSIGNED NOT NULL DEFAULT 0
                     AFTER rider_user_id,
                   DROP PRIMARY KEY,
                   ADD PRIMARY KEY (buyer_user_id, rider_user_id, session_id)'
            );
        }
    } catch (Throwable $_) {
        // Table newly created above already has session_id; nothing to do.
    }

    $done = true;
}


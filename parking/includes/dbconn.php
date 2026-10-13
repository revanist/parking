<?php
// Local development defaults. For deployment, set CAMPUSPARK_DB_HOST,
// CAMPUSPARK_DB_NAME, CAMPUSPARK_DB_USER, and CAMPUSPARK_DB_PASS in the server environment.
$host = getenv('CAMPUSPARK_DB_HOST') ?: 'localhost';
$dbname = getenv('CAMPUSPARK_DB_NAME') ?: 'campus_parking';
$username = getenv('CAMPUSPARK_DB_USER') ?: 'root';
$password = getenv('CAMPUSPARK_DB_PASS') !== false ? getenv('CAMPUSPARK_DB_PASS') : '';

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]
    );

    // Synchronize application and database timezone (defaults to Asia/Manila / Philippine Standard Time)
    $appTimezone = 'Asia/Manila';
    try {
        $tzStmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'timezone' LIMIT 1");
        $savedTz = $tzStmt ? $tzStmt->fetchColumn() : null;
        if ($savedTz && in_array($savedTz, DateTimeZone::listIdentifiers(), true)) {
            $appTimezone = $savedTz;
        }
    } catch (Throwable $e) {
        // Table may not exist during initial setup
    }

    date_default_timezone_set($appTimezone);
    try {
        $tzOffset = (new DateTime('now', new DateTimeZone($appTimezone)))->format('P');
        $pdo->exec("SET time_zone = '{$tzOffset}'");
    } catch (Throwable $e) {
        // Continue gracefully
    }

    // Ensure critical columns and tables exist (self-healing schema)
    ensure_database_schema($pdo);
} catch (PDOException $e) {
    error_log('[CampusPark] Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('The system could not connect to the database. Please check the server configuration.');
}

/**
 * Automatically verifies that critical schema updates exist in the active database.
 * Prevents unknown column / missing table Fatal errors across all application routes.
 */
function ensure_database_schema(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        // 1. Ensure vehicles.photo_path exists
        $vCol = $pdo->query("SHOW COLUMNS FROM `vehicles` LIKE 'photo_path'")->fetch();
        if (!$vCol) {
            $pdo->exec("ALTER TABLE `vehicles` ADD COLUMN `photo_path` VARCHAR(255) NULL AFTER `vehicle_year`");
        }

        // 2. Ensure parking_permits.qr_token exists
        $pCol = $pdo->query("SHOW COLUMNS FROM `parking_permits` LIKE 'qr_token'")->fetch();
        if (!$pCol) {
            $pdo->exec("ALTER TABLE `parking_permits` ADD COLUMN `qr_token` CHAR(64) NULL AFTER `permit_number`");
            $keys = $pdo->query("SHOW KEYS FROM `parking_permits` WHERE Key_name = 'uq_permit_qr_token'")->fetchAll();
            if (empty($keys)) {
                $pdo->exec("ALTER TABLE `parking_permits` ADD UNIQUE KEY `uq_permit_qr_token` (`qr_token`)");
            }
        }

        // 3. Ensure approved/existing permits have a valid qr_token
        $missingTokens = $pdo->query("SELECT id FROM `parking_permits` WHERE qr_token IS NULL OR qr_token = ''")->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($missingTokens)) {
            $upStmt = $pdo->prepare("UPDATE `parking_permits` SET qr_token = ? WHERE id = ?");
            foreach ($missingTokens as $pid) {
                $upStmt->execute([bin2hex(random_bytes(32)), $pid]);
            }
        }

        // 4. Ensure password_resets table exists
        $resetsTable = $pdo->query("SHOW TABLES LIKE 'password_resets'")->fetch();
        if (!$resetsTable) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `password_resets` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `token_hash` CHAR(64) NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `used_at` DATETIME DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_pwd_reset_user` (`user_id`),
                KEY `idx_pwd_reset_token` (`token_hash`),
                KEY `idx_pwd_reset_expires` (`expires_at`),
                CONSTRAINT `fk_pwd_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    } catch (Throwable $e) {
        // Fail softly so initial setups or constrained DB permissions don't crash
    }
}

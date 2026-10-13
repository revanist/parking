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
} catch (PDOException $e) {
    error_log('[CampusPark] Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('The system could not connect to the database. Please check the server configuration.');
}

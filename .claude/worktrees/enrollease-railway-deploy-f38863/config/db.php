<?php
// ============================================================
//  DATABASE CONNECTION  (config/db.php)
// ============================================================
//  Works on XAMPP (local) and Railway (hosted).
//  Railway may use either MYSQLDATABASE or MYSQL_DATABASE
//  depending on how the service was linked.
// ============================================================

// The clinic is in the Philippines. Without this, "today" / "tomorrow"
// follow the server's clock (UTC on Railway, Berlin on XAMPP) and are
// wrong for part of every day.
date_default_timezone_set('Asia/Manila');

$db_host = getenv('MYSQLHOST')     ?: getenv('MYSQL_HOST')     ?: '127.0.0.1';
$db_user = getenv('MYSQLUSER')     ?: getenv('MYSQL_USER')     ?: 'root';
$db_pass = getenv('MYSQLPASSWORD') ?: getenv('MYSQL_PASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD') ?: '';
$db_name = getenv('MYSQLDATABASE') ?: getenv('MYSQL_DATABASE') ?: 'dental_clinic';
$db_port = getenv('MYSQLPORT')     ?: getenv('MYSQL_PORT')     ?: '3306';

try {
    $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+08:00'",   // NOW()/CURDATE() in clinic time
    ]);
} catch (PDOException $e) {
    // Details go to the server error log only — never show hosts/usernames to visitors.
    error_log('Database connection failed: ' . $e->getMessage() . " (host {$db_host}:{$db_port}, db {$db_name}, user {$db_user})");
    http_response_code(500);
    die('The system is temporarily unavailable. Please try again later.');
}
?>

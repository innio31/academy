<?php
// includes/config.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('DB_HOST', 'localhost');
define('DB_USER', 'impactdi_school_portal');
define('DB_PASS', 'Innioluwa@1995');
define('DB_NAME', 'impactdi_school_portal');

// School configuration (set these per school folder)
if (!defined('SCHOOL_ID')) {
    define('SCHOOL_ID', 3);
    define('SCHOOL_CODE', 'IDA');
    define('SCHOOL_NAME', 'Impact Digital Academy');
    define('SCHOOL_PRIMARY', '#1B2A4A');
    define('SCHOOL_SECONDARY', '#2E7D32');
    define('SCHOOL_LOGO', '/assets/logos/logo.png');
}

// Global PDO variable
$pdo = null;

function getDBConnection()
{
    global $pdo;

    // Return existing connection if already open
    if ($pdo !== null) {
        return $pdo;
    }

    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5, // 5 second timeout
                PDO::ATTR_PERSISTENT => false // DON'T use persistent connections
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

function closeDBConnection()
{
    global $pdo;
    $pdo = null;
}

// Register shutdown function to close connection
register_shutdown_function('closeDBConnection');

// Get connection
$pdo = getDBConnection();

date_default_timezone_set('Africa/Lagos');

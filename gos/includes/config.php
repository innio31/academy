<?php
// gos/includes/config.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'impactdi_school_portal');
define('DB_PASS', 'Innioluwa@1995');
define('DB_NAME', 'impactdi_school_portal');

// School configuration
define('SCHOOL_ID', 1);
define('SCHOOL_CODE', 'GOS');
define('SCHOOL_NAME', 'Great Optimist School, Ota');
define('SCHOOL_PRIMARY', '#1B2A4A');
define('SCHOOL_SECONDARY', '#2E7D32');
define('SCHOOL_LOGO', '/gos/assets/logos/gos001.png');

// Create database connection as GLOBAL variable
try {
    $GLOBALS['pdo'] = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS
    );
    $GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $GLOBALS['pdo']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Also create local $pdo for convenience
    $pdo = $GLOBALS['pdo'];
    
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Timezone
date_default_timezone_set('Africa/Lagos');
?>
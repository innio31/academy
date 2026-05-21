<?php
// api/db_config.php - Database configuration for cloud API
// Location: acad.com.ng/gos/api/db_config.php

define('DB_HOST', 'localhost');
define('DB_NAME', 'impactdi_school_portal');
define('DB_USER', 'impactdi_school_portal');
define('DB_PASS', 'Innioluwa@1995');

function getDBConnection()
{
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection error: " . $e->getMessage());
        throw new Exception("Database connection failed");
    }
}

// Ensure sync_log table exists
function ensureSyncLogTable($pdo)
{
    $sql = "
        CREATE TABLE IF NOT EXISTS sync_log (
            id INT PRIMARY KEY AUTO_INCREMENT,
            school_id INT NOT NULL,
            table_name VARCHAR(50),
            records_synced INT DEFAULT 0,
            records_failed INT DEFAULT 0,
            sync_type ENUM('push', 'pull', 'full') DEFAULT 'push',
            status ENUM('success', 'failed', 'partial') DEFAULT 'success',
            error_message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_school_id (school_id),
            INDEX idx_created_at (created_at)
        )
    ";
    $pdo->exec($sql);
}

// Initialize on API load
try {
    $pdo = getDBConnection();
    ensureSyncLogTable($pdo);
} catch (Exception $e) {
    error_log("Init error: " . $e->getMessage());
}

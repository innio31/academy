<?php
// /central_bank/includes/config.php - Central Bank Configuration

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'impactdi_school_portal');
define('DB_USER', 'impactdi_school_portal');
define('DB_PASS', 'Innioluwa@1995');

// Central Bank Configuration
define('CENTRAL_BANK_NAME', 'ACAD Central Question Bank');
define('CENTRAL_BANK_URL', 'https://acad.com.ng/central_bank');

// API Configuration
define('API_KEY', 'YOUR_SECURE_API_KEY_HERE_32_CHARS_MIN');

// Database connection
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Create central_admins table if not exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS central_admins (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            email VARCHAR(100),
            role ENUM('super_admin', 'admin') DEFAULT 'admin',
            status ENUM('active', 'inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_login TIMESTAMP NULL
        )
    ");
    
    // Insert default super admin if not exists
    $stmt = $pdo->prepare("SELECT id FROM central_admins WHERE username = 'superadmin'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $hashed = password_hash('ChangeMe123!', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO central_admins (username, password, full_name, email, role) VALUES (?, ?, ?, ?, 'super_admin')")
            ->execute(['superadmin', $hashed, 'Super Administrator', 'admin@acad.com.ng']);
    }
} catch (Exception $e) {
    error_log("Table creation error: " . $e->getMessage());
}
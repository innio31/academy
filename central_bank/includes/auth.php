<?php
// /central_bank/includes/auth.php - Authentication for Central Bank

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Super admin credentials (hardcoded or from database)
// Option 1: Hardcoded for initial setup
define('SUPER_ADMIN_USERNAME', 'superadmin');
define('SUPER_ADMIN_PASSWORD', 'ChangeMe123!'); // CHANGE THIS!

// Option 2: From database (recommended)
// Create a central_admins table

function is_super_admin_logged_in() {
    return isset($_SESSION['central_admin_id']) && $_SESSION['central_admin_role'] === 'super_admin';
}

function require_super_admin() {
    if (!is_super_admin_logged_in()) {
        header('Location: login.php');
        exit();
    }
}

function central_admin_login($username, $password) {
    // First check hardcoded credentials
    if ($username === SUPER_ADMIN_USERNAME && $password === SUPER_ADMIN_PASSWORD) {
        $_SESSION['central_admin_id'] = 1;
        $_SESSION['central_admin_username'] = $username;
        $_SESSION['central_admin_name'] = 'Super Administrator';
        $_SESSION['central_admin_role'] = 'super_admin';
        $_SESSION['central_admin_logged_in'] = true;
        return true;
    }
    
    // Check database for additional central admins
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM central_admins WHERE username = ? AND status = 'active'");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        
        if ($admin && password_verify($password, $admin['password'])) {
            $_SESSION['central_admin_id'] = $admin['id'];
            $_SESSION['central_admin_username'] = $admin['username'];
            $_SESSION['central_admin_name'] = $admin['full_name'];
            $_SESSION['central_admin_role'] = $admin['role'];
            $_SESSION['central_admin_logged_in'] = true;
            return true;
        }
    } catch (Exception $e) {
        error_log("Central admin login error: " . $e->getMessage());
    }
    
    return false;
}

function central_admin_logout() {
    session_destroy();
    header('Location: login.php');
    exit();
}
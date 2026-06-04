<?php
require_once __DIR__ . '/config.php';

// Function to login user with email OR staff ID OR student reg number
function loginUser($identifier, $password, $pdo) {
    $user = null;
    
    // FIRST: Try to find by email
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
    $stmt->execute([$identifier]);
    $user = $stmt->fetch();
    
    // SECOND: If not found, try as student registration number
    if (!$user) {
        $stmt = $pdo->prepare("
            SELECT u.* 
            FROM users u
            INNER JOIN students s ON u.id = s.user_id
            WHERE s.reg_number = ? 
            AND u.is_active = 1
        ");
        $stmt->execute([$identifier]);
        $user = $stmt->fetch();
        
        if ($user) {
            error_log("Found user by reg_number: " . $identifier);
        } else {
            error_log("No user found by reg_number: " . $identifier);
        }
    }
    
    // THIRD: If still not found, try as staff number
    if (!$user) {
        $stmt = $pdo->prepare("
            SELECT u.* 
            FROM users u
            INNER JOIN staff s ON u.id = s.user_id
            WHERE s.staff_number = ? 
            AND u.is_active = 1
        ");
        $stmt->execute([$identifier]);
        $user = $stmt->fetch();
    }
    
    // Verify password and complete login
    if ($user && password_verify($password, $user['password'])) {
        // Update last login
        $update = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $update->execute([$user['id']]);
        
        // Set basic session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['login_via'] = $identifier;
        
        // =============================================
        // SET SESSION TIMEOUT ACTIVITY TIMESTAMP
        // =============================================
        $_SESSION['last_activity'] = time();
        $_SESSION['last_regeneration'] = time();
        $_SESSION['session_start_time'] = time();
        
        // Get role-specific data
        if ($user['role'] == 'student') {
            $stmt2 = $pdo->prepare("SELECT id, reg_number, department_id, current_level FROM students WHERE user_id = ?");
            $stmt2->execute([$user['id']]);
            $student = $stmt2->fetch();
            if ($student) {
                $_SESSION['student_id'] = $student['id'];
                $_SESSION['reg_number'] = $student['reg_number'];
                $_SESSION['department_id'] = $student['department_id'];
                $_SESSION['level'] = $student['current_level'];
            } else {
                error_log("CRITICAL: Student record missing for user_id: " . $user['id']);
                return false;
            }
        } elseif ($user['role'] == 'staff') {
            $stmt2 = $pdo->prepare("SELECT id, staff_number, department_id, designation FROM staff WHERE user_id = ?");
            $stmt2->execute([$user['id']]);
            $staff = $stmt2->fetch();
            if ($staff) {
                $_SESSION['staff_id'] = $staff['id'];
                $_SESSION['staff_number'] = $staff['staff_number'];
                $_SESSION['department_id'] = $staff['department_id'];
                $_SESSION['designation'] = $staff['designation'];
            } else {
                error_log("CRITICAL: Staff record missing for user_id: " . $user['id']);
                return false;
            }
        } elseif ($user['role'] == 'parent') {
            $stmt2 = $pdo->prepare("SELECT id, student_id FROM parents WHERE user_id = ?");
            $stmt2->execute([$user['id']]);
            $parent = $stmt2->fetch();
            if ($parent) {
                $_SESSION['parent_id'] = $parent['id'];
                $_SESSION['monitored_student_id'] = $parent['student_id'];
            } else {
                error_log("CRITICAL: Parent record missing for user_id: " . $user['id']);
                return false;
            }
        }
        
        return true;
    }
    
    return false;
}

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Function to restrict access by role
function requireRole($allowedRoles) {
    if (!isLoggedIn()) {
        header("Location: ../index.php?error=please_login");
        exit();
    }
    
    if (!in_array($_SESSION['role'], $allowedRoles)) {
        header("Location: ../dashboard.php?error=access_denied");
        exit();
    }
}

// Function to logout
function logout() {
    // Clear all session variables
    $_SESSION = array();
    
    // Destroy the session cookie
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    // Destroy the session
    session_destroy();
    
    header("Location: ../index.php");
    exit();
}

// Function to get user dashboard URL based on role
function getDashboardUrl() {
    if (!isLoggedIn()) return 'index.php';
    
    switch($_SESSION['role']) {
        case 'super_admin':
        case 'admin':
            return 'admin/index.php';
        case 'staff':
            return 'staff/index.php';
        case 'student':
            return 'student/index.php';
        case 'parent':
            return 'parent/index.php';
        default:
            return 'index.php';
    }
}

// Function to get session remaining time (in minutes)
function getSessionRemainingTime() {
    if (!isset($_SESSION['last_activity'])) {
        return SESSION_TIMEOUT_MINUTES;
    }
    
    $elapsed = time() - $_SESSION['last_activity'];
    $remaining = SESSION_TIMEOUT_SECONDS - $elapsed;
    
    return max(0, round($remaining / 60, 1));
}

// Function to display session timeout warning (optional)
function getSessionTimeoutWarning() {
    $remaining = getSessionRemainingTime();
    if ($remaining <= 5 && $remaining > 0) {
        return "<div class='session-warning' style='background: #feebc8; color: #7c2d12; padding: 8px 15px; border-radius: 8px; font-size: 12px; position: fixed; bottom: 10px; right: 10px; z-index: 9999;'>
                    ⏰ Your session will expire in " . ceil($remaining) . " minutes due to inactivity.
                </div>";
    }
    return '';
}
?>
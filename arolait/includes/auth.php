<?php
require_once __DIR__ . '/config.php';

// Function to login user with email OR staff ID OR student reg number (Multi-tenant)
function loginUser($identifier, $password, $pdo, $school_id = null) {
    // If school_id not provided, get from current context or use default
    if (!$school_id) {
        $school_id = getCurrentSchoolId();
    }
    
    if (!$school_id) {
        error_log("No school_id available for login");
        return false;
    }
    
    error_log("Login attempt for school_id: " . $school_id . " with identifier: " . $identifier);
    
    $user = null;
    
    // FIRST: Try to find by email within the specific school
    $stmt = $pdo->prepare("SELECT * FROM users WHERE school_id = ? AND email = ? AND is_active = 1");
    $stmt->execute([$school_id, $identifier]);
    $user = $stmt->fetch();
    
    // SECOND: If not found, try as student registration number
    if (!$user) {
        $stmt = $pdo->prepare("
            SELECT u.* 
            FROM users u
            INNER JOIN students s ON u.id = s.user_id AND u.school_id = s.school_id
            WHERE u.school_id = ? 
            AND s.reg_number = ? 
            AND u.is_active = 1
        ");
        $stmt->execute([$school_id, $identifier]);
        $user = $stmt->fetch();
        
        if ($user) {
            error_log("Found user by reg_number: " . $identifier . " for school: " . $school_id);
        }
    }
    
    // THIRD: If still not found, try as staff number
    if (!$user) {
        $stmt = $pdo->prepare("
            SELECT u.* 
            FROM users u
            INNER JOIN staff s ON u.id = s.user_id AND u.school_id = s.school_id
            WHERE u.school_id = ? 
            AND s.staff_number = ? 
            AND u.is_active = 1
        ");
        $stmt->execute([$school_id, $identifier]);
        $user = $stmt->fetch();
        
        if ($user) {
            error_log("Found user by staff_number: " . $identifier . " for school: " . $school_id);
        }
    }
    
    // Verify password and complete login
    if ($user && password_verify($password, $user['password'])) {
        error_log("Password verified for user: " . $user['email']);
        
        // Update last login
        $update = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $update->execute([$user['id']]);
        
        // Set basic session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['login_via'] = $identifier;
        $_SESSION['school_id'] = $user['school_id'];
        
        // Set school context
        if (function_exists('setSchoolContext')) {
            setSchoolContext($user['school_id']);
        }
        
        // =============================================
        // SET SESSION TIMEOUT ACTIVITY TIMESTAMP
        // =============================================
        $_SESSION['last_activity'] = time();
        $_SESSION['last_regeneration'] = time();
        $_SESSION['session_start_time'] = time();
        
        // Get role-specific data
        if ($user['role'] == 'student') {
            $stmt2 = $pdo->prepare("SELECT id, reg_number, department_id, current_level FROM students WHERE user_id = ? AND school_id = ?");
            $stmt2->execute([$user['id'], $user['school_id']]);
            $student = $stmt2->fetch();
            if ($student) {
                $_SESSION['student_id'] = $student['id'];
                $_SESSION['reg_number'] = $student['reg_number'];
                $_SESSION['department_id'] = $student['department_id'];
                $_SESSION['level'] = $student['current_level'];
            } else {
                error_log("CRITICAL: Student record missing for user_id: " . $user['id'] . " in school: " . $user['school_id']);
                return false;
            }
        } elseif ($user['role'] == 'staff') {
            $stmt2 = $pdo->prepare("SELECT id, staff_number, department_id, designation FROM staff WHERE user_id = ? AND school_id = ?");
            $stmt2->execute([$user['id'], $user['school_id']]);
            $staff = $stmt2->fetch();
            if ($staff) {
                $_SESSION['staff_id'] = $staff['id'];
                $_SESSION['staff_number'] = $staff['staff_number'];
                $_SESSION['department_id'] = $staff['department_id'];
                $_SESSION['designation'] = $staff['designation'];
            } else {
                error_log("CRITICAL: Staff record missing for user_id: " . $user['id'] . " in school: " . $user['school_id']);
                return false;
            }
        } elseif ($user['role'] == 'parent') {
            $stmt2 = $pdo->prepare("SELECT id, student_id FROM parents WHERE user_id = ? AND school_id = ?");
            $stmt2->execute([$user['id'], $user['school_id']]);
            $parent = $stmt2->fetch();
            if ($parent) {
                $_SESSION['parent_id'] = $parent['id'];
                $_SESSION['monitored_student_id'] = $parent['student_id'];
            } else {
                error_log("CRITICAL: Parent record missing for user_id: " . $user['id'] . " in school: " . $user['school_id']);
                return false;
            }
        }
        
        return true;
    }
    
    if ($user) {
        error_log("Password verification failed for user: " . $user['email']);
    } else {
        error_log("No user found for identifier: " . $identifier . " in school: " . $school_id);
    }
    
    return false;
}

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']);
}

// Function to restrict access by role
function requireRole($allowedRoles) {
    if (!isLoggedIn()) {
        header("Location: ../login.php?error=please_login");
        exit();
    }
    
    if (!in_array($_SESSION['role'], $allowedRoles)) {
        // Redirect to appropriate dashboard instead of login
        $dashboard = getDashboardUrl();
        if ($dashboard && $dashboard != 'login.php') {
            header("Location: " . $dashboard);
        } else {
            header("Location: ../login.php?error=access_denied");
        }
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
    
    // Redirect to login
    header("Location: ../login.php");
    exit();
}

// Function to get user dashboard URL based on role - FIXED to use correct paths
function getDashboardUrl() {
    if (!isLoggedIn()) {
        return 'login.php';
    }
    
    switch($_SESSION['role']) {
        case 'super_admin':
            return 'admin/index.php';
        case 'admin':
            return 'admin/index.php';
        case 'staff':
            return 'staff/index.php';
        case 'student':
            return 'student/index.php';
        case 'parent':
            return 'parent/index.php';
        default:
            return 'login.php';
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

// Function to check if user belongs to current school
function verifyUserSchool($user_id, $school_id = null) {
    global $pdo;
    
    if (!$school_id) {
        if (function_exists('getCurrentSchoolId')) {
            $school_id = getCurrentSchoolId();
        } else {
            return true; // Skip verification if function doesn't exist
        }
    }
    
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND school_id = ? AND is_active = 1");
    $stmt->execute([$user_id, $school_id]);
    return $stmt->fetch() !== false;
}
?>
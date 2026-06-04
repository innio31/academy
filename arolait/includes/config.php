<?php
// =============================================
// DATABASE CONFIGURATION - MULTI-TENANT
// =============================================

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'impactdi_school_management');
define('DB_USER', 'impactdi_school_management');
define('DB_PASS', '@impact2026');

// Application settings
define('APP_NAME', 'University Portal');
define('APP_URL', 'http://arolait.acad.com.ng');
define('TIMEZONE', 'Africa/Lagos');
date_default_timezone_set(TIMEZONE);

// =============================================
// MULTI-TENANT SETTINGS
// =============================================
// School context - will be set after login or from subdomain
// For single-school deployment, set default school ID
define('DEFAULT_SCHOOL_ID', 1);

// School detection method: 'subdomain', 'domain', 'session', 'header'
define('SCHOOL_DETECTION_METHOD', 'session'); // Change to 'subdomain' for multi-domain setup

// =============================================
// SESSION TIMEOUT SETTINGS (30 minutes)
// =============================================
define('SESSION_TIMEOUT_MINUTES', 30);
define('SESSION_TIMEOUT_SECONDS', SESSION_TIMEOUT_MINUTES * 60);

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =============================================
// SCHOOL CONTEXT MANAGEMENT
// =============================================

/**
 * Get current school ID based on detection method
 * @return int|null School ID or null if not found
 */
function getCurrentSchoolId() {
    // Check if already set in session
    if (isset($_SESSION['school_id']) && !empty($_SESSION['school_id'])) {
        return (int)$_SESSION['school_id'];
    }
    
    // Try subdomain detection
    if (SCHOOL_DETECTION_METHOD === 'subdomain') {
        $school = getSchoolBySubdomain();
        if ($school) {
            $_SESSION['school_id'] = $school['id'];
            $_SESSION['school_code'] = $school['code'];
            $_SESSION['school_name'] = $school['name'];
            return $school['id'];
        }
    }
    
    // Try domain detection
    if (SCHOOL_DETECTION_METHOD === 'domain') {
        $school = getSchoolByDomain();
        if ($school) {
            $_SESSION['school_id'] = $school['id'];
            $_SESSION['school_code'] = $school['code'];
            $_SESSION['school_name'] = $school['name'];
            return $school['id'];
        }
    }
    
    // Fall back to default school ID
    if (defined('DEFAULT_SCHOOL_ID')) {
        return DEFAULT_SCHOOL_ID;
    }
    
    return null;
}

/**
 * Get school by subdomain (e.g., schoolname.example.com)
 * @return array|null School data or null
 */
function getSchoolBySubdomain() {
    global $pdo;
    
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $subdomain = explode('.', $host)[0] ?? '';
    
    if ($subdomain && $subdomain !== 'www' && $subdomain !== 'arolait') {
        $stmt = $pdo->prepare("SELECT * FROM schools WHERE code = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$subdomain]);
        return $stmt->fetch();
    }
    
    return null;
}

/**
 * Get school by custom domain (e.g., school.com)
 * @return array|null School data or null
 */
function getSchoolByDomain() {
    global $pdo;
    
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $host = preg_replace('/^www\./', '', $host);
    
    $stmt = $pdo->prepare("SELECT * FROM schools WHERE domain = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$host]);
    return $stmt->fetch();
}

/**
 * Set school context in session (after login or selection)
 * @param int $schoolId School ID
 * @return bool Success status
 */
function setSchoolContext($schoolId) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT * FROM schools WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$schoolId]);
    $school = $stmt->fetch();
    
    if ($school) {
        $_SESSION['school_id'] = (int)$school['id'];
        $_SESSION['school_code'] = $school['code'];
        $_SESSION['school_name'] = $school['name'];
        $_SESSION['school_settings'] = [
            'subscription_plan' => $school['subscription_plan'],
            'max_students' => $school['max_students'],
            'max_staff' => $school['max_staff'],
            'subscription_expires_at' => $school['subscription_expires_at']
        ];
        return true;
    }
    
    return false;
}

/**
 * Check if user belongs to current school context
 * @param int $userId User ID
 * @return bool True if user belongs to current school
 */
function verifyUserSchoolContext($userId) {
    global $pdo;
    
    $schoolId = getCurrentSchoolId();
    if (!$schoolId) {
        return false;
    }
    
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND school_id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$userId, $schoolId]);
    return $stmt->fetch() !== false;
}

/**
 * Get all schools (for super admin)
 * @return array List of schools
 */
function getAllSchools() {
    global $pdo;
    
    $stmt = $pdo->query("SELECT * FROM schools ORDER BY name");
    return $stmt->fetchAll();
}

/**
 * Check subscription status for current school
 * @return bool True if subscription is active
 */
function checkSubscriptionStatus() {
    if (isset($_SESSION['school_settings']['subscription_expires_at'])) {
        $expires = $_SESSION['school_settings']['subscription_expires_at'];
        if ($expires && strtotime($expires) < time()) {
            return false;
        }
    }
    return true;
}

// =============================================
// SESSION TIMEOUT CHECK
// =============================================
function checkSessionTimeout() {
    // Only check for logged-in users
    if (isset($_SESSION['user_id'])) {
        $timeout = SESSION_TIMEOUT_SECONDS;
        
        // Check if last activity time is set
        if (isset($_SESSION['last_activity'])) {
            $inactive_time = time() - $_SESSION['last_activity'];
            
            // If inactive for more than timeout period, destroy session
            if ($inactive_time > $timeout) {
                // Clear all session variables
                $_SESSION = array();
                
                // Destroy the session cookie
                if (isset($_COOKIE[session_name()])) {
                    setcookie(session_name(), '', time() - 3600, '/');
                }
                
                // Destroy the session
                session_destroy();
                
                // Redirect to login page with timeout message
                header("Location: ../login.php?error=session_timeout");
                exit();
            }
        }
        
        // Update last activity time
        $_SESSION['last_activity'] = time();
    }
}

// =============================================
// REGENERATE SESSION ID PERIODICALLY (for security)
// =============================================
function regenerateSessionId() {
    if (isset($_SESSION['user_id'])) {
        // Regenerate session ID every 15 minutes to prevent fixation
        if (!isset($_SESSION['last_regeneration'])) {
            $_SESSION['last_regeneration'] = time();
        } elseif (time() - $_SESSION['last_regeneration'] > 900) { // 15 minutes
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = time();
        }
    }
}

// Run timeout check on every page load
checkSessionTimeout();
regenerateSessionId();

// =============================================
// PDO Connection
// =============================================
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
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// =============================================
// Initialize school context after PDO is ready
// =============================================
$current_school_id = getCurrentSchoolId();

// Helper function for debugging (disable on production)
function debug($data) {
    echo '<pre>';
    print_r($data);
    echo '</pre>';
}

// Helper function to add school_id filter to queries
function withSchool($query) {
    global $current_school_id;
    if (strpos($query, ':school_id') !== false) {
        return $query;
    }
    return $query;
}
?>
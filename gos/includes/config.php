<?php
// gos/includes/config.php - Portal Configuration with Subscription Check
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Africa/Lagos');

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// DATABASE CONFIGURATION
// ============================================
define('DB_HOST', 'localhost');
define('DB_USER', 'impactdi_school_portal');
define('DB_PASS', 'Innioluwa@1995');
define('DB_NAME', 'impactdi_school_portal');

// ============================================
// SCHOOL IDENTIFICATION
// ============================================
define('SCHOOL_ID', 1);  // Great Optimist School, Ota ID
define('SCHOOL_CODE', 'GOS');

// ============================================
// SCHOOL INFORMATION
// ============================================
define('SCHOOL_NAME', 'Great Optimist School, Ota');
define('SCHOOL_PRIMARY', '#722F37');
define('SCHOOL_SECONDARY', '#d4af7a');
define('SCHOOL_ACCENT', '#ffffff');
define('SCHOOL_LOGO', 'gos/assets/logos/logo.png');  // Added back
define('SCHOOL_MOTTO', 'Excellence in Digital Education');  // Added back
define('SCHOOL_ADDRESS', 'Great Optimist School, Ota, Lagos, Nigeria');  // Added back
define('SCHOOL_PHONE', '09035448295');  // Added back
define('SCHOOL_EMAIL', 'dig2skills@gmail.com');  // Added back

// ============================================
// SYSTEM CONFIGURATION
// ============================================
define('SYSTEM_NAME', 'School Management Portal');
define('SYSTEM_VERSION', '1.0.0');

// ============================================
// DATABASE CONNECTION
// ============================================
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
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// ============================================
// SUBSCRIPTION CHECK FUNCTION
// ============================================

/**
 * Check if school subscription is active
 * @return array Subscription status with details
 */
function checkSubscriptionStatus($pdo, $school_id = SCHOOL_ID)
{
    try {
        $stmt = $pdo->prepare("
            SELECT 
                id,
                school_name,
                school_code,
                subscription_status,
                subscription_expiry,
                status as school_status,
                contact_email,
                contact_phone
            FROM schools 
            WHERE id = ?
        ");
        $stmt->execute([$school_id]);
        $school = $stmt->fetch();

        if (!$school) {
            return [
                'active' => false,
                'status' => 'not_found',
                'message' => 'School not found in system',
                'expiry_date' => null,
                'expiry_formatted' => 'N/A',
                'days_remaining' => 0,
                'is_expired' => true,
                'should_block' => true,
                'contact_email' => null,
                'contact_phone' => null
            ];
        }

        $expiry_date = $school['subscription_expiry'];
        $is_expired = false;
        $days_remaining = 0;
        $should_block = false;

        // Check if subscription exists and is active
        if ($expiry_date && $expiry_date !== '0000-00-00') {
            $expiry_timestamp = strtotime($expiry_date);
            $current_timestamp = time();
            $is_expired = ($expiry_timestamp < $current_timestamp);
            $days_remaining = ceil(($expiry_timestamp - $current_timestamp) / (60 * 60 * 24));
            $days_remaining = max(0, $days_remaining);

            // Block if subscription is expired
            if ($is_expired) {
                $should_block = true;
            }
        } else {
            // No expiry date set - assume active
            $is_expired = false;
            $should_block = false;
        }

        // Check subscription status from database
        $subscription_active = ($school['subscription_status'] === 'active' && !$is_expired);

        // If subscription status is not active, block access
        if ($school['subscription_status'] !== 'active') {
            $should_block = true;
        }

        return [
            'active' => $subscription_active,
            'status' => $school['subscription_status'],
            'message' => $subscription_active ? 'Subscription active' : 'Subscription ' . $school['subscription_status'],
            'expiry_date' => $expiry_date,
            'expiry_formatted' => ($expiry_date && $expiry_date !== '0000-00-00') ? date('F j, Y', strtotime($expiry_date)) : 'No expiry date',
            'days_remaining' => $days_remaining,
            'is_expired' => $is_expired,
            'should_block' => $should_block,
            'school_name' => $school['school_name'],
            'school_code' => $school['school_code'],
            'contact_email' => $school['contact_email'],
            'contact_phone' => $school['contact_phone']
        ];
    } catch (Exception $e) {
        error_log("Subscription check error: " . $e->getMessage());
        return [
            'active' => false,
            'status' => 'error',
            'message' => 'Error checking subscription status',
            'expiry_date' => null,
            'expiry_formatted' => 'N/A',
            'days_remaining' => 0,
            'is_expired' => true,
            'should_block' => true,
            'contact_email' => null,
            'contact_phone' => null
        ];
    }
}

/**
 * Verify subscription and block access if expired
 * @param bool $redirect Whether to redirect or just return status
 * @return bool True if access allowed, false if blocked
 */
function verifySubscription($pdo, $redirect = true)
{
    global $subscription_status;

    // Cache subscription status in session to reduce database queries
    if (!isset($_SESSION['subscription_checked']) || $_SESSION['subscription_checked'] < time() - 3600) {
        $subscription_status = checkSubscriptionStatus($pdo);
        $_SESSION['subscription_status'] = $subscription_status;
        $_SESSION['subscription_checked'] = time();
    } else {
        $subscription_status = $_SESSION['subscription_status'];
    }

    // Check if subscription has expired
    if ($subscription_status['should_block']) {
        if ($redirect) {
            // Store the attempted URL
            $_SESSION['blocked_url'] = $_SERVER['REQUEST_URI'];
            $_SESSION['subscription_message'] = $subscription_status['message'];

            // Redirect to subscription expired page
            header("Location: /gos/subscription-expired.php");
            exit();
        }
        return false;
    }

    return true;
}

/**
 * Get current subscription status (cached)
 * @return array Subscription information
 */
function getCurrentSubscriptionStatus()
{
    global $subscription_status;
    return $subscription_status ?? ['active' => false, 'should_block' => true];
}

// ============================================
// INITIAL SUBSCRIPTION CHECK FOR ALL PAGES
// ============================================

// Check subscription for all admin/staff pages (not login page)
$current_file = basename($_SERVER['PHP_SELF']);
$excluded_pages = ['login.php', 'subscription-expired.php', 'logout.php', 'index.php'];

if (!in_array($current_file, $excluded_pages)) {
    // For API endpoints, don't redirect but return error
    $is_api = (strpos($_SERVER['REQUEST_URI'], '/api/') !== false);

    if ($is_api) {
        $subscription_status = checkSubscriptionStatus($pdo);
        if ($subscription_status['should_block']) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'code' => 'SUBSCRIPTION_EXPIRED',
                'message' => 'Your subscription has expired. Please contact the administrator to renew.',
                'expiry_date' => $subscription_status['expiry_formatted']
            ]);
            exit();
        }
    } elseif ($current_file !== 'index.php') {
        // For web pages, verify and redirect if needed
        verifySubscription($pdo, true);
    }
}

// Store subscription status globally for use in pages
$subscription_status = getCurrentSubscriptionStatus();


// Make subscription status available globally
if (!isset($subscription_status)) {
    $subscription_status = checkSubscriptionStatus($pdo);
}

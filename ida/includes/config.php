<?php
// ida/includes/config.php - Portal Configuration with Subscription Check
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Africa/Lagos');

// ============================================
// GLOBAL CACHE CONTROL - Prevents Browser Caching
// ============================================
if (!headers_sent()) {
    // Single, clean Cache-Control header - no duplicates
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

    // For AJAX/API responses, set correct content type
    if (
        strpos($_SERVER['SCRIPT_NAME'], 'ajax') !== false ||
        strpos($_SERVER['REQUEST_URI'], '/api/') !== false
    ) {
        header("Content-Type: application/json; charset=utf-8");
    }
}
// ============================================
// END CACHE CONTROL
// ============================================

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
define('SCHOOL_ID', 3);  // Impact Digital Academy, Ota ID
define('SCHOOL_CODE', 'ida');

// ============================================
// SCHOOL INFORMATION
// ============================================
define('SCHOOL_NAME', 'Impact Digital Academy');
define('SCHOOL_PRIMARY', '#1a3a8f');
define('SCHOOL_SECONDARY', '#d4500a');
define('SCHOOL_ACCENT', '#ffffff');
define('SCHOOL_LOGO', 'ida/assets/logos/logo.png');
define('SCHOOL_MOTTO', 'Excellence in Digital Education');
define('SCHOOL_ADDRESS', 'Impact Digital Academy');
define('SCHOOL_PHONE', '09035448295');
define('SCHOOL_EMAIL', 'dig2skills@gmail.com');

// ============================================
// SYSTEM CONFIGURATION
// ============================================
define('SYSTEM_NAME', 'School Management Portal');
define('SYSTEM_VERSION', '1.0.0');

// ============================================
// ASSET VERSIONING
// ============================================
// In development: time() forces a fresh load on every request.
// In production: replace time() with a static string e.g. '1.0.4'
// and increment it manually whenever you deploy an update.
// Example: define('ASSET_VERSION', '1.0.4');
// define('ASSET_VERSION', time());

// ============================================
// DATABASE CONNECTION
// ============================================
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false
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
                'active'            => false,
                'status'            => 'not_found',
                'message'           => 'School not found in system',
                'expiry_date'       => null,
                'expiry_formatted'  => 'N/A',
                'days_remaining'    => 0,
                'is_expired'        => true,
                'should_block'      => true,
                'contact_email'     => null,
                'contact_phone'     => null
            ];
        }

        $expiry_date    = $school['subscription_expiry'];
        $is_expired     = false;
        $days_remaining = 0;
        $should_block   = false;

        if ($expiry_date && $expiry_date !== '0000-00-00') {
            $expiry_timestamp  = strtotime($expiry_date);
            $current_timestamp = time();
            $is_expired        = ($expiry_timestamp < $current_timestamp);
            $days_remaining    = max(0, ceil(($expiry_timestamp - $current_timestamp) / 86400));

            if ($is_expired) {
                $should_block = true;
            }
        }
        // No expiry date set — assume active

        $subscription_active = ($school['subscription_status'] === 'active' && !$is_expired);

        if ($school['subscription_status'] !== 'active') {
            $should_block = true;
        }

        return [
            'active'           => $subscription_active,
            'status'           => $school['subscription_status'],
            'message'          => $subscription_active
                ? 'Subscription active'
                : 'Subscription ' . $school['subscription_status'],
            'expiry_date'      => $expiry_date,
            'expiry_formatted' => ($expiry_date && $expiry_date !== '0000-00-00')
                ? date('F j, Y', strtotime($expiry_date))
                : 'No expiry date',
            'days_remaining'   => $days_remaining,
            'is_expired'       => $is_expired,
            'should_block'     => $should_block,
            'school_name'      => $school['school_name'],
            'school_code'      => $school['school_code'],
            'contact_email'    => $school['contact_email'],
            'contact_phone'    => $school['contact_phone']
        ];
    } catch (Exception $e) {
        error_log("Subscription check error: " . $e->getMessage());
        return [
            'active'           => false,
            'status'           => 'error',
            'message'          => 'Error checking subscription status',
            'expiry_date'      => null,
            'expiry_formatted' => 'N/A',
            'days_remaining'   => 0,
            'is_expired'       => true,
            'should_block'     => true,
            'contact_email'    => null,
            'contact_phone'    => null
        ];
    }
}

/**
 * Verify subscription and block access if expired.
 * Call clearSubscriptionCache() after any DB update so the
 * session does not serve a stale status for up to 5 minutes.
 *
 * @param  bool $redirect Whether to redirect or just return status
 * @return bool True if access allowed, false if blocked
 */
function verifySubscription($pdo, $redirect = true)
{
    global $subscription_status;

    // Cache subscription status in session for 5 minutes (300 s)
    // to reduce DB queries without going stale for too long.
    $cache_ttl = 300;

    if (
        !isset($_SESSION['subscription_checked']) ||
        $_SESSION['subscription_checked'] < time() - $cache_ttl
    ) {
        $subscription_status              = checkSubscriptionStatus($pdo);
        $_SESSION['subscription_status']  = $subscription_status;
        $_SESSION['subscription_checked'] = time();
    } else {
        $subscription_status = $_SESSION['subscription_status'];
    }

    if ($subscription_status['should_block']) {
        if ($redirect) {
            $_SESSION['blocked_url']           = $_SERVER['REQUEST_URI'];
            $_SESSION['subscription_message']  = $subscription_status['message'];
            header("Location: /ida/subscription-expired.php");
            exit();
        }
        return false;
    }

    return true;
}

/**
 * Call this after any admin action that changes subscription data
 * (e.g. renewing a subscription, updating status from the UI).
 * It clears the session cache so the next page load re-reads the DB.
 */
function clearSubscriptionCache()
{
    unset($_SESSION['subscription_checked']);
    unset($_SESSION['subscription_status']);
}

/**
 * Get current subscription status (session-cached)
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
$current_file   = basename($_SERVER['PHP_SELF']);
$excluded_pages = ['login.php', 'subscription-expired.php', 'logout.php', 'index.php'];

if (!in_array($current_file, $excluded_pages)) {
    $is_api = (strpos($_SERVER['REQUEST_URI'], '/api/') !== false);

    if ($is_api) {
        $subscription_status = checkSubscriptionStatus($pdo);
        if ($subscription_status['should_block']) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode([
                'status'      => 'error',
                'code'        => 'SUBSCRIPTION_EXPIRED',
                'message'     => 'Your subscription has expired. Please contact the administrator to renew.',
                'expiry_date' => $subscription_status['expiry_formatted']
            ]);
            exit();
        }
    } elseif ($current_file !== 'index.php') {
        verifySubscription($pdo, true);
    }
}

// Store subscription status globally for use in pages
if (!isset($subscription_status)) {
    $subscription_status = checkSubscriptionStatus($pdo);
}

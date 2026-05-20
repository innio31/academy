<?php
// includes/config.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('DB_HOST', 'localhost');
define('DB_USER', 'impactdi_school_portal');
define('DB_PASS', 'Innioluwa@1995');
define('DB_NAME', 'impactdi_school_portal');

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
                PDO::ATTR_TIMEOUT => 5,
                PDO::ATTR_PERSISTENT => false
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

// ============================================
// SCHOOL DETECTION AND CONFIGURATION
// ============================================

// Function to detect school from URL or session
function detectSchoolCode()
{
    // Check if already set in session
    if (isset($_SESSION['school_code']) && !empty($_SESSION['school_code'])) {
        return $_SESSION['school_code'];
    }

    // Detect from URL subdomain or path
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';

    // Subdomain detection (gos.impactdigitals.com, ida.impactdigitals.com, etc.)
    if (strpos($host, 'gos.') === 0) {
        return 'GOS';
    } elseif (strpos($host, 'ida.') === 0) {
        return 'IDA';
    } elseif (strpos($host, 'tcba.') === 0) {
        return 'TCBA';
    }

    // Path detection (impactdigitals.com/gos, impactdigitals.com/ida, etc.)
    if (preg_match('/\/(GOS|IDA|TCBA|gos|ida|tcba)\//i', $request_uri, $matches)) {
        return strtoupper($matches[1]);
    }

    // Default fallback
    return 'IDA';
}

// Get school code
$school_code = detectSchoolCode();

// Fetch school details from database
$stmt = $pdo->prepare("SELECT * FROM schools WHERE school_code = ? OR url_path = ? LIMIT 1");
$stmt->execute([$school_code, strtolower($school_code)]);
$school = $stmt->fetch(PDO::FETCH_ASSOC);

// If school not found by code, try to get first active school
if (!$school) {
    $stmt = $pdo->prepare("SELECT * FROM schools WHERE status = 'active' LIMIT 1");
    $stmt->execute();
    $school = $stmt->fetch(PDO::FETCH_ASSOC);
}

// If still no school, die with error
if (!$school) {
    die("No school configuration found. Please contact administrator.");
}

// Store school code in session
$_SESSION['school_code'] = $school['school_code'];
$_SESSION['selected_school_id'] = $school['id'];

// Define school constants
define('SCHOOL_ID', $school['id']);
define('SCHOOL_CODE', $school['school_code']);
define('SCHOOL_NAME', $school['school_name']);
define('SCHOOL_PRIMARY', $school['primary_color']);
define('SCHOOL_SECONDARY', $school['secondary_color']);
define('SCHOOL_WHATSAPP', $school['whatsapp_number'] ?? '');
define('SCHOOL_MOTTO', $school['motto'] ?? '');
define('SCHOOL_EMAIL', $school['contact_email'] ?? '');
define('SCHOOL_PHONE', $school['contact_phone'] ?? '');

// Fix logo path - ensure it's correct
$logo_path = $school['logo_path'] ?? '/assets/logos/gos001.png';

// If logo path doesn't start with /, add it
if (!empty($logo_path) && $logo_path[0] !== '/') {
    $logo_path = '/' . $logo_path;
}

// If logo path doesn't have school folder prefix, add it
if (!empty($logo_path) && strpos($logo_path, '/' . strtolower(SCHOOL_CODE)) === false && strpos($logo_path, '/assets/') === 0) {
    $logo_path = '/' . strtolower(SCHOOL_CODE) . $logo_path;
}

// If logo path still doesn't exist, use default
$full_logo_path = $_SERVER['DOCUMENT_ROOT'] . $logo_path;
if (!file_exists($full_logo_path)) {
    // Try alternative paths
    $alt_paths = [
        '/assets/logos/' . strtolower(SCHOOL_CODE) . '.png',
        '/assets/logos/' . strtolower(SCHOOL_CODE) . '.jpg',
        '/assets/logos/' . strtolower(SCHOOL_CODE) . '.jpeg',
        '/assets/logos/' . strtolower(SCHOOL_CODE) . '.webp',
        '/assets/logos/default.png',
        '/' . strtolower(SCHOOL_CODE) . '/assets/logos/logo.png',
        '/' . strtolower(SCHOOL_CODE) . '/assets/logo.png',
    ];

    foreach ($alt_paths as $alt_path) {
        if (file_exists($_SERVER['DOCUMENT_ROOT'] . $alt_path)) {
            $logo_path = $alt_path;
            break;
        }
    }
}

define('SCHOOL_LOGO', $logo_path);

// Base URL for assets
define('BASE_URL', 'https://' . $_SERVER['HTTP_HOST'] . '/');

// Function to get full asset path
function asset_path($path)
{
    return BASE_URL . ltrim($path, '/');
}

// Debug info (remove in production)
if (!defined('DEBUG')) {
    define('DEBUG', false);
}

if (DEBUG) {
    error_log("School loaded: " . SCHOOL_NAME . " (ID: " . SCHOOL_ID . ")");
    error_log("Logo path: " . SCHOOL_LOGO);
    error_log("Full logo path: " . $_SERVER['DOCUMENT_ROOT'] . SCHOOL_LOGO);
    error_log("File exists: " . (file_exists($_SERVER['DOCUMENT_ROOT'] . SCHOOL_LOGO) ? 'Yes' : 'No'));
}

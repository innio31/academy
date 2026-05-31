<?php
// logout.php - Logout user and redirect to login with same school
session_start();

// Store the selected school ID before destroying session
$selected_school_id = $_SESSION['selected_school_id'] ?? null;
$selected_school_name = $_SESSION['selected_school_name'] ?? null;
$school_code = $_SESSION['school_code'] ?? null;

// Destroy all session data
$_SESSION = array();

// Destroy session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Start new session to preserve school selection
session_start();

// Restore school selection
if ($selected_school_id) {
    $_SESSION['selected_school_id'] = $selected_school_id;
    $_SESSION['selected_school_name'] = $selected_school_name;
    $_SESSION['school_code'] = $school_code;
}

// Redirect to login page
header("Location: login.php");
exit();

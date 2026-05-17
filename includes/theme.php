<?php
// includes/theme.php - Central theme configuration
// Call this AFTER config.php and session start

if (!isset($_SESSION['selected_school_id'])) {
    // No school selected yet - use default
    define('SCHOOL_PRIMARY', '#1B2A4A');
    define('SCHOOL_SECONDARY', '#2E7D32');
    define('SCHOOL_ACCENT', '#FFFFFF');
    define('SCHOOL_LOGO', '/assets/logos/default.png');
    define('SCHOOL_NAME', 'School Portal');
    return;
}

// Fetch school theme from database
global $pdo;
$stmt = $pdo->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$_SESSION['selected_school_id']]);
$school = $stmt->fetch();

if ($school) {
    define('SCHOOL_PRIMARY', $school['primary_color']);
    define('SCHOOL_SECONDARY', $school['secondary_color']);
    define('SCHOOL_ACCENT', $school['accent_color']);
    define('SCHOOL_LOGO', $school['logo_path']);
    define('SCHOOL_NAME', $school['school_name']);
} else {
    // Fallback
    define('SCHOOL_PRIMARY', '#1B2A4A');
    define('SCHOOL_SECONDARY', '#2E7D32');
    define('SCHOOL_ACCENT', '#FFFFFF');
    define('SCHOOL_LOGO', '/assets/logos/default.png');
    define('SCHOOL_NAME', 'School Portal');
}

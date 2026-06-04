<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Check if logged in
if (!isLoggedIn()) {
    header("Location: index.php");
    exit();
}

// Redirect to role-specific dashboard
$dashboardUrl = getDashboardUrl();
header("Location: " . $dashboardUrl);
exit();
?>
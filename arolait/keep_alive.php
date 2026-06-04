<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

header('Content-Type: application/json');

if (isLoggedIn()) {
    // Update last activity time
    $_SESSION['last_activity'] = time();
    echo json_encode(['success' => true, 'message' => 'Session extended']);
} else {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
}
?>
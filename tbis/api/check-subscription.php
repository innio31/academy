<?php
// gos/api/check-subscription.php - Manual subscription check endpoint
header('Content-Type: application/json');
require_once '../includes/config.php';

// Only allow admin access
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$subscription = checkSubscriptionStatus($pdo);

// Clear session cache
unset($_SESSION['subscription_status']);
unset($_SESSION['subscription_checked']);

echo json_encode([
    'status' => 'success',
    'subscription' => $subscription
]);

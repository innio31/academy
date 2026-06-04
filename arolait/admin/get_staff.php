<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

header('Content-Type: application/json');

$staff_id = $_GET['id'] ?? 0;

if (!$staff_id) {
    echo json_encode(['success' => false, 'error' => 'Staff ID required']);
    exit();
}

$stmt = $pdo->prepare("
    SELECT s.*, u.first_name, u.last_name, u.email, u.phone 
    FROM staff s 
    JOIN users u ON s.user_id = u.id 
    WHERE s.id = ?
");
$stmt->execute([$staff_id]);
$staff = $stmt->fetch();

if ($staff) {
    echo json_encode(['success' => true, 'staff' => $staff]);
} else {
    echo json_encode(['success' => false, 'error' => 'Staff not found']);
}
?>
<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

header('Content-Type: application/json');

$parent_id = $_GET['id'] ?? 0;

if (!$parent_id) {
    echo json_encode(['success' => false, 'error' => 'Parent ID required']);
    exit();
}

$stmt = $pdo->prepare("
    SELECT p.*, u.first_name, u.last_name, u.email, u.phone 
    FROM parents p 
    JOIN users u ON p.user_id = u.id 
    WHERE p.id = ?
");
$stmt->execute([$parent_id]);
$parent = $stmt->fetch();

if ($parent) {
    echo json_encode(['success' => true, 'parent' => $parent]);
} else {
    echo json_encode(['success' => false, 'error' => 'Parent not found']);
}
?>
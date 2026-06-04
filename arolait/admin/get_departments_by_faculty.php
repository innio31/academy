<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

header('Content-Type: application/json');

$faculty_id = $_GET['faculty_id'] ?? 0;

if (!$faculty_id) {
    echo json_encode([]);
    exit();
}

$stmt = $pdo->prepare("SELECT id, name FROM departments WHERE faculty_id = ? ORDER BY name");
$stmt->execute([$faculty_id]);
$departments = $stmt->fetchAll();

echo json_encode($departments);
?>
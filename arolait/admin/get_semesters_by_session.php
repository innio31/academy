<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

header('Content-Type: application/json');

$session_id = $_GET['session_id'] ?? 0;

if (!$session_id) {
    echo json_encode([]);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT id, name FROM semesters WHERE session_id = ? ORDER BY id");
    $stmt->execute([$session_id]);
    $semesters = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($semesters);
} catch(PDOException $e) {
    echo json_encode([]);
}
exit();
?>
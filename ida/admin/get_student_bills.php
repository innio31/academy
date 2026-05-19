<?php
// admin/get_student_bills.php - Get bills for a student
session_start();
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isset($_GET['student_id'])) {
    echo json_encode(['success' => false, 'error' => 'Student ID required']);
    exit();
}

$student_id = (int)$_GET['student_id'];
$school_id = SCHOOL_ID;

try {
    // Get all bills for this student
    $stmt = $pdo->prepare("SELECT * FROM bills WHERE student_id = ? AND school_id = ? ORDER BY created_at DESC");
    $stmt->execute([$student_id, $school_id]);
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'bills' => $bills]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
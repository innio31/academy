<?php
// admin/check_attendance.php - Check if attendance already marked
session_start();
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isset($_GET['student_id']) || !isset($_GET['date'])) {
    echo json_encode(['marked' => false, 'error' => 'Missing parameters']);
    exit();
}

$student_id = (int)$_GET['student_id'];
$date = $_GET['date'];
$school_id = SCHOOL_ID;

try {
    $stmt = $pdo->prepare("SELECT status FROM attendance WHERE student_id = ? AND date = ? AND school_id = ?");
    $stmt->execute([$student_id, $date, $school_id]);
    $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($attendance) {
        echo json_encode(['marked' => true, 'status' => $attendance['status']]);
    } else {
        echo json_encode(['marked' => false]);
    }
} catch (PDOException $e) {
    echo json_encode(['marked' => false, 'error' => $e->getMessage()]);
}
?>
<?php
// gos/report-card/get-students.php - Get students by class (AJAX)
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id']) && !isset($_SESSION['staff_id'])) {
    echo json_encode([]);
    exit();
}

$class = $_GET['class'] ?? '';
$school_id = SCHOOL_ID;

if ($class) {
    $stmt = $pdo->prepare("SELECT id, full_name, admission_number FROM students WHERE class = ? AND school_id = ? AND status = 'active' ORDER BY full_name");
    $stmt->execute([$class, $school_id]);
    echo json_encode($stmt->fetchAll());
} else {
    echo json_encode([]);
}
?>
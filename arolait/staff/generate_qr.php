<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_GET['student_id']) || !isset($_GET['reg_number'])) {
    echo json_encode(['success' => false, 'error' => 'Student ID and Registration Number required']);
    exit();
}

$student_id = $_GET['student_id'];
$reg_number = $_GET['reg_number'];

$result = generateStudentQR($reg_number, $student_id, $pdo);

if ($result) {
    echo json_encode([
        'success' => true,
        'qr_url' => $result
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to generate QR code']);
}
?>
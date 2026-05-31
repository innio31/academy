<?php
// admin/regenerate_qr.php - Regenerate QR code for a single student
session_start();
require_once '../includes/config.php';
require_once '../includes/qr_functions.php';

header('Content-Type: application/json');

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

if (!isset($_GET['student_id'])) {
    echo json_encode(['success' => false, 'error' => 'Student ID required']);
    exit();
}

$student_id = (int)$_GET['student_id'];
$school_id = SCHOOL_ID;

// Verify student belongs to this school
$stmt = $pdo->prepare("SELECT id FROM students WHERE id = ? AND school_id = ?");
$stmt->execute([$student_id, $school_id]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Student not found']);
    exit();
}

// Regenerate QR code
if (regenerateStudentQRCode($pdo, $student_id)) {
    echo json_encode(['success' => true, 'message' => 'QR code regenerated successfully']);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to generate QR code']);
}
?>
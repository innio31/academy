<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/phpqrcode/qrlib.php';
requireRole(['super_admin', 'admin']);

$student_id = $_GET['id'] ?? 0;

if (!$student_id) {
    die("Student ID required");
}

// Get student
$stmt = $pdo->prepare("
    SELECT s.*, u.first_name, u.last_name 
    FROM students s 
    JOIN users u ON s.user_id = u.id 
    WHERE s.id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    die("Student not found");
}

// Create directory
$qr_dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/qrcodes/';
if (!file_exists($qr_dir)) {
    mkdir($qr_dir, 0777, true);
}

// IMPORTANT: Replace slashes in registration number for filename
$safe_filename = str_replace('/', '_', $student['reg_number']);
$filename = $safe_filename . '.png';
$full_path = $qr_dir . $filename;
$web_path = '/assets/qrcodes/' . $filename;

// QR data
$qr_data = json_encode([
    'student_id' => $student['id'],
    'reg_number' => $student['reg_number'],
    'name' => $student['first_name'] . ' ' . $student['last_name']
]);

// Generate QR
QRcode::png($qr_data, $full_path, QR_ECLEVEL_L, 10);

if (file_exists($full_path)) {
    // Update database
    $update = $pdo->prepare("UPDATE students SET qr_code = ? WHERE id = ?");
    $update->execute([$web_path, $student_id]);
    
    header("Location: view_student.php?id=" . $student_id . "&message=QR Code generated successfully");
} else {
    header("Location: view_student.php?id=" . $student_id . "&error=Failed to generate QR code");
}
exit();
?>
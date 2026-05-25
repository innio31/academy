<?php
// admin/generate_qr.php - Display QR code from saved file
session_start();
require_once '../includes/config.php';

$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

if ($student_id <= 0) {
    header('HTTP/1.0 404 Not Found');
    die('Invalid student ID');
}

// Get QR code path from database
$stmt = $pdo->prepare("SELECT qr_code FROM students WHERE id = ? AND school_id = ?");
$stmt->execute([$student_id, SCHOOL_ID]);
$qr_path = $stmt->fetchColumn();

// FIXED: Check if file exists with correct path including /msv/
if ($qr_path && file_exists($_SERVER['DOCUMENT_ROOT'] . $qr_path)) {
    $file_path = $_SERVER['DOCUMENT_ROOT'] . $qr_path;
    $file_info = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($file_info, $file_path);
    finfo_close($file_info);

    header('Content-Type: ' . $mime_type);
    header('Cache-Control: public, max-age=86400');
    readfile($file_path);
} else {
    // Generate on the fly if file doesn't exist
    require_once '../includes/qr_functions.php';

    $stmt = $pdo->prepare("SELECT admission_number, full_name FROM students WHERE id = ? AND school_id = ?");
    $stmt->execute([$student_id, SCHOOL_ID]);
    $student = $stmt->fetch();

    if ($student) {
        $qr_data = generateStudentQRCode($student_id, $student['admission_number'], $student['full_name']);

        // Create QR code directly to output
        header('Content-Type: image/png');
        QRcode::png($qr_data, null, QR_ECLEVEL_L, 10);
    } else {
        header('HTTP/1.0 404 Not Found');
        die('Student not found');
    }
}

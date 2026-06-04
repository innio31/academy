<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

// Include phpqrcode library
require_once '../includes/phpqrcode/qrlib.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit();
}

$student_id = $_POST['student_id'] ?? 0;

if (!$student_id) {
    echo json_encode(['success' => false, 'error' => 'Student ID required']);
    exit();
}

// Get student details
$stmt = $pdo->prepare("
    SELECT s.*, u.first_name, u.last_name 
    FROM students s 
    JOIN users u ON s.user_id = u.id 
    WHERE s.id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    echo json_encode(['success' => false, 'error' => 'Student not found']);
    exit();
}

// Create QR directory
$qr_dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/qrcodes/';
if (!file_exists($qr_dir)) {
    mkdir($qr_dir, 0777, true);
}

// IMPORTANT: Replace slashes in registration number for filename
$safe_filename = str_replace('/', '_', $student['reg_number']);
$filename = $safe_filename . '.png';
$full_path = $qr_dir . $filename;
$web_path = '/assets/qrcodes/' . $filename;

// Prepare QR data
$qr_data = json_encode([
    'student_id' => $student['id'],
    'reg_number' => $student['reg_number'],
    'name' => $student['first_name'] . ' ' . $student['last_name']
]);

try {
    // Generate QR code using phpqrcode
    QRcode::png($qr_data, $full_path, QR_ECLEVEL_L, 10);
    
    if (file_exists($full_path)) {
        // Update database with QR path
        $update = $pdo->prepare("UPDATE students SET qr_code = ? WHERE id = ?");
        $update->execute([$web_path, $student_id]);
        
        echo json_encode([
            'success' => true,
            'qr_url' => $web_path,
            'reg_number' => $student['reg_number'],
            'student_name' => $student['first_name'] . ' ' . $student['last_name']
        ]);
    } else {
        throw new Exception("QR file was not created at: " . $full_path);
    }
} catch(Exception $e) {
    echo json_encode([
        'success' => false, 
        'error' => 'QR Generation failed: ' . $e->getMessage()
    ]);
}
?>
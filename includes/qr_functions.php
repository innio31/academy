<?php
// includes/qr_functions.php - QR Code Generation Functions

function generateStudentQRCode($student_id, $admission_number, $full_name) {
    // Create QR code data payload
    $qr_data = json_encode([
        'id' => $student_id,
        'admission' => $admission_number,
        'name' => $full_name,
        'type' => 'student',
        'timestamp' => time()
    ]);
    
    // URL-encode the data
    $encoded_data = urlencode($qr_data);
    
    // Use Google Charts API for QR generation (free, no library needed)
    $qr_url = "https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=" . $encoded_data . "&choe=UTF-8";
    
    return $qr_url;
}

function saveStudentQRCode($pdo, $student_id, $qr_code_url) {
    $stmt = $pdo->prepare("UPDATE students SET qr_code = ?, qr_updated_at = NOW() WHERE id = ?");
    $stmt->execute([$qr_code_url, $student_id]);
}

function getStudentQRCode($pdo, $student_id) {
    $stmt = $pdo->prepare("SELECT qr_code, admission_number, full_name FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    return $stmt->fetch();
}

// Alternative: Generate QR using PHP (requires phpqrcode library)
// Download from: https://sourceforge.net/projects/phpqrcode/
function generateQRCodeWithPHP($data, $size = 300) {
    require_once '../includes/phpqrcode/qrlib.php';
    
    $tempDir = __DIR__ . '/../temp/';
    if (!file_exists($tempDir)) {
        mkdir($tempDir, 0777, true);
    }
    
    $fileName = 'qrcode_' . md5($data) . '.png';
    $pngAbsoluteFilePath = $tempDir . $fileName;
    
    QRcode::png($data, $pngAbsoluteFilePath, QR_ECLEVEL_L, $size / 25);
    
    return '/temp/' . $fileName;
}
?>
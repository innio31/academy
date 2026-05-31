<?php
// includes/qr_functions.php - QR Code Generation & Image Upload Functions

// Include the PHP QR Code library
require_once __DIR__ . '/phpqrcode_lib/qrlib.php';

function generateStudentQRCode($student_id, $admission_number, $full_name)
{
    // Create QR code data payload
    $qr_data = json_encode([
        'id' => $student_id,
        'admission' => $admission_number,
        'name' => $full_name,
        'school_id' => SCHOOL_ID,
        'type' => 'student',
        'timestamp' => time()
    ]);

    // Return the data that will be encoded in QR
    return $qr_data;
}

function saveStudentQRCode($pdo, $student_id, $qr_data)
{
    // Define the QR code file path - FIXED to include /tbis/
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/tbis/uploads/qrcodes/';

    // Create directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $filename = 'student_' . $student_id . '.png';
    $filepath = $upload_dir . $filename;

    // Generate the QR code image
    QRcode::png($qr_data, $filepath, QR_ECLEVEL_L, 10);

    // Check if file was created
    if (file_exists($filepath)) {
        $qr_url = '/tbis/uploads/qrcodes/' . $filename;
        $stmt = $pdo->prepare("UPDATE students SET qr_code = ?, qr_updated_at = NOW() WHERE id = ?");
        $stmt->execute([$qr_url, $student_id]);
        return true;
    }

    return false;
}

function getStudentQRCode($pdo, $student_id)
{
    $stmt = $pdo->prepare("SELECT qr_code, admission_number, full_name FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    return $stmt->fetch();
}

// Image optimization function
function optimizeImage($source_path, $target_path, $max_size_kb = 100, $max_width = 500, $max_height = 500)
{
    // Get image info
    list($width, $height, $type) = getimagesize($source_path);

    // Calculate new dimensions
    $ratio = min($max_width / $width, $max_height / $height);
    $new_width = round($width * $ratio);
    $new_height = round($height * $ratio);

    // Create image resource based on type
    switch ($type) {
        case IMAGETYPE_JPEG:
            $src = imagecreatefromjpeg($source_path);
            break;
        case IMAGETYPE_PNG:
            $src = imagecreatefrompng($source_path);
            break;
        case IMAGETYPE_GIF:
            $src = imagecreatefromgif($source_path);
            break;
        default:
            return false;
    }

    // Create new image
    $dst = imagecreatetruecolor($new_width, $new_height);

    // Preserve transparency for PNG
    if ($type == IMAGETYPE_PNG) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $new_width, $new_height, $transparent);
    }

    // Resize
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

    // Save with progressive quality adjustment to meet size requirement
    $quality = 85;
    $temp_file = $target_path . '.tmp';

    do {
        if ($type == IMAGETYPE_JPEG) {
            imagejpeg($dst, $temp_file, $quality);
        } elseif ($type == IMAGETYPE_PNG) {
            imagepng($dst, $temp_file, 9);
        } else {
            imagegif($dst, $temp_file);
        }

        $file_size = filesize($temp_file) / 1024; // Size in KB
        $quality -= 10;
    } while ($file_size > $max_size_kb && $quality > 20);

    // Rename temp file to target
    rename($temp_file, $target_path);

    // Clean up
    imagedestroy($src);
    imagedestroy($dst);

    return true;
}

function uploadStudentImage($file, $student_id)
{
    // FIXED: Added /tbis/ to the path
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/tbis/uploads/students/';

    // Create directory if not exists
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    // Generate unique filename
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif'];

    if (!in_array($extension, $allowed)) {
        return ['success' => false, 'error' => 'Invalid file type. Allowed: jpg, jpeg, png, gif'];
    }

    $filename = 'student_' . $student_id . '_' . time() . '.' . $extension;
    $temp_path = $upload_dir . 'temp_' . $filename;
    $final_path = $upload_dir . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $temp_path)) {
        return ['success' => false, 'error' => 'Failed to upload file'];
    }

    // Optimize image
    if (optimizeImage($temp_path, $final_path, 100, 500, 500)) {
        unlink($temp_path); // Remove temp file
        return ['success' => true, 'path' => '/tbis/uploads/students/' . $filename];
    } else {
        unlink($temp_path);
        return ['success' => false, 'error' => 'Failed to optimize image'];
    }
}

// Function to regenerate QR code for existing students
function regenerateStudentQRCode($pdo, $student_id)
{
    $stmt = $pdo->prepare("SELECT admission_number, full_name FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();

    if ($student) {
        $qr_data = generateStudentQRCode($student_id, $student['admission_number'], $student['full_name']);
        return saveStudentQRCode($pdo, $student_id, $qr_data);
    }

    return false;
}

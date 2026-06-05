<?php
// includes/qr_helper.php - QR Code generation helper with custom duration

function regenerateSchoolQRCode($pdo, $school_id, $admin_id, $duration_hours = 24)
{
    // Deactivate all existing active QR codes for this school
    $stmt = $pdo->prepare("
        UPDATE school_qr_codes 
        SET status = 'expired' 
        WHERE school_id = ? AND status = 'active'
    ");
    $stmt->execute([$school_id]);

    // Generate unique token
    $token = bin2hex(random_bytes(32));

    // Calculate expiry time based on custom duration
    $expires_at = date('Y-m-d H:i:s', strtotime("+{$duration_hours} hours"));

    // Generate QR code image
    $qr_data = base64_encode(json_encode([
        'type' => 'school_attendance',
        'school_id' => $school_id,
        'token' => $token,
        'expires_at' => $expires_at
    ]));

    // Create QR code using Google Chart API or similar
    $qr_url = "https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=" . urlencode($qr_data) . "&choe=UTF-8";

    // Save QR code locally (optional)
    $qr_image_path = saveQRCodeImage($qr_url, $school_id, $token);

    // Insert new QR code record
    $stmt = $pdo->prepare("
        INSERT INTO school_qr_codes (school_id, qr_token, qr_image, session_name, generated_at, expires_at, status, generated_by)
        VALUES (?, ?, ?, ?, NOW(), ?, 'active', ?)
    ");
    $stmt->execute([$school_id, $token, $qr_image_path, 'School Attendance QR', $expires_at, $admin_id]);

    return [
        'qr_token' => $token,
        'qr_url' => $qr_image_path,
        'expires_at' => $expires_at,
        'duration_hours' => $duration_hours
    ];
}

function getActiveSchoolQRCode($pdo, $school_id)
{
    $stmt = $pdo->prepare("
        SELECT * FROM school_qr_codes 
        WHERE school_id = ? AND status = 'active' AND expires_at > NOW()
        ORDER BY generated_at DESC LIMIT 1
    ");
    $stmt->execute([$school_id]);
    return $stmt->fetch();
}

function saveQRCodeImage($qr_url, $school_id, $token)
{
    $qr_dir = $_SERVER['DOCUMENT_ROOT'] . "/msv/uploads/qrcodes/";
    if (!file_exists($qr_dir)) {
        mkdir($qr_dir, 0777, true);
    }

    $filename = "school_qr_{$school_id}_{$token}.png";
    $filepath = $qr_dir . $filename;

    $qr_image = file_get_contents($qr_url);
    if ($qr_image) {
        file_put_contents($filepath, $qr_image);
        return "/msv/uploads/qrcodes/" . $filename;
    }

    return $qr_url;
}

function generateClassQRCode($pdo, $school_id, $class_id, $class_name, $admin_id, $expiry_hours = null)
{
    $token = bin2hex(random_bytes(32));

    if ($expiry_hours && is_numeric($expiry_hours)) {
        $expires_at = date('Y-m-d H:i:s', strtotime("+{$expiry_hours} hours"));
        $expires_text = date('M j, Y g:i A', strtotime($expires_at));
    } else {
        $expires_at = 'never';
        $expires_text = 'Never expires';
    }

    $qr_data = base64_encode(json_encode([
        'type' => 'class_attendance',
        'school_id' => $school_id,
        'class_id' => $class_id,
        'class_name' => $class_name,
        'token' => $token,
        'expires_at' => $expires_at
    ]));

    $qr_url = "https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=" . urlencode($qr_data) . "&choe=UTF-8";

    // Save QR code
    $qr_dir = $_SERVER['DOCUMENT_ROOT'] . "/msv/uploads/qrcodes/";
    if (!file_exists($qr_dir)) {
        mkdir($qr_dir, 0777, true);
    }

    $filename = "class_qr_{$school_id}_{$class_id}_{$token}.png";
    $filepath = $qr_dir . $filename;

    $qr_image = file_get_contents($qr_url);
    if ($qr_image) {
        file_put_contents($filepath, $qr_image);
        $qr_path = "/msv/uploads/qrcodes/" . $filename;
    } else {
        $qr_path = $qr_url;
    }

    // Store in database (create table if needed)
    $stmt = $pdo->prepare("
        INSERT INTO class_qr_codes (school_id, class_id, class_name, qr_token, qr_image, expires_at, generated_by, generated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE 
        qr_token = VALUES(qr_token), qr_image = VALUES(qr_image), expires_at = VALUES(expires_at), generated_at = NOW()
    ");
    $stmt->execute([$school_id, $class_id, $class_name, $token, $qr_path, $expires_at === 'never' ? null : $expires_at, $admin_id]);

    return [
        'qr_token' => $token,
        'qr_url' => $qr_path,
        'expires_at' => $expires_text,
        'class_name' => $class_name
    ];
}

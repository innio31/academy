<?php
// includes/qr_helper.php - Extended QR Code Functions for School & Class QR
// Requires phpqrcode_lib/qrlib.php to be present

require_once __DIR__ . '/phpqrcode_lib/qrlib.php';

/**
 * Generate School QR Code (Regeneratable for staff attendance)
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @param int $generated_by Admin/Staff ID who generated
 * @param int $expiry_hours Hours until QR expires (default 24)
 * @return array|false Returns QR data or false on failure
 */
function generateSchoolQRCode($pdo, $school_id, $generated_by, $expiry_hours = 24)
{
    // Generate unique token
    $qr_token = bin2hex(random_bytes(32));
    
    // QR data payload
    $qr_data = json_encode([
        'type' => 'school_attendance',
        'school_id' => $school_id,
        'token' => $qr_token,
        'timestamp' => time(),
        'expires' => time() + ($expiry_hours * 3600)
    ]);
    
    // Create QR code image
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/msv/uploads/qr_codes/school/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $filename = 'school_qr_' . $school_id . '_' . time() . '.png';
    $filepath = $upload_dir . $filename;
    
    QRcode::png($qr_data, $filepath, QR_ECLEVEL_L, 10);
    
    if (!file_exists($filepath)) {
        return false;
    }
    
    $qr_url = '/msv/uploads/qr_codes/school/' . $filename;
    
    // Deactivate all previous active QR codes for this school
    $stmt = $pdo->prepare("UPDATE school_qr_codes SET status = 'expired' WHERE school_id = ? AND status = 'active'");
    $stmt->execute([$school_id]);
    
    // Insert new QR code
    $stmt = $pdo->prepare("
        INSERT INTO school_qr_codes (school_id, qr_token, qr_image, session_name, generated_at, expires_at, status, generated_by, ip_address) 
        VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? HOUR), 'active', ?, ?)
    ");
    
    $expires_at = $expiry_hours;
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    
    $stmt->execute([$school_id, $qr_token, $qr_url, 'Staff Attendance QR', $expires_at, $generated_by, $ip_address]);
    
    return [
        'id' => $pdo->lastInsertId(),
        'token' => $qr_token,
        'qr_url' => $qr_url,
        'expires_at' => date('Y-m-d H:i:s', strtotime("+{$expiry_hours} hours"))
    ];
}

/**
 * Verify School QR Code validity
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @param string $token QR token to verify
 * @return array|false Returns QR data if valid, false otherwise
 */
function verifySchoolQRCode($pdo, $school_id, $token)
{
    $stmt = $pdo->prepare("
        SELECT * FROM school_qr_codes 
        WHERE school_id = ? AND qr_token = ? AND status = 'active' AND expires_at > NOW()
    ");
    $stmt->execute([$school_id, $token]);
    $qr = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($qr) {
        return $qr;
    }
    
    return false;
}

/**
 * Regenerate School QR Code (revokes old ones)
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @param int $generated_by Admin/Staff ID
 * @return array|false
 */
function regenerateSchoolQRCode($pdo, $school_id, $generated_by)
{
    return generateSchoolQRCode($pdo, $school_id, $generated_by, 24);
}

/**
 * Generate Class QR Code for teacher attendance
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @param int $class_id Class ID
 * @param string $class_name Class name
 * @param int $generated_by Admin/Staff ID
 * @param int|null $expiry_hours Hours until expiry (null = never expires)
 * @return array|false
 */
function generateClassQRCode($pdo, $school_id, $class_id, $class_name, $generated_by, $expiry_hours = null)
{
    // Generate unique token
    $qr_token = bin2hex(random_bytes(32));
    
    // QR data payload
    $qr_data = json_encode([
        'type' => 'class_attendance',
        'school_id' => $school_id,
        'class_id' => $class_id,
        'class_name' => $class_name,
        'token' => $qr_token,
        'timestamp' => time()
    ]);
    
    // Create QR code image
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/msv/uploads/qr_codes/classes/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $filename = 'class_' . $class_id . '_' . time() . '.png';
    $filepath = $upload_dir . $filename;
    
    QRcode::png($qr_data, $filepath, QR_ECLEVEL_L, 10);
    
    if (!file_exists($filepath)) {
        return false;
    }
    
    $qr_url = '/msv/uploads/qr_codes/classes/' . $filename;
    
    // Check if class QR already exists
    $stmt = $pdo->prepare("SELECT id FROM class_qr_codes WHERE school_id = ? AND class_id = ?");
    $stmt->execute([$school_id, $class_id]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Update existing
        $expires_sql = $expiry_hours ? "expires_at = DATE_ADD(NOW(), INTERVAL ? HOUR)" : "expires_at = NULL";
        $stmt = $pdo->prepare("
            UPDATE class_qr_codes 
            SET qr_token = ?, qr_image = ?, generated_at = NOW(), $expires_sql, status = 'active', generated_by = ?
            WHERE school_id = ? AND class_id = ?
        ");
        
        if ($expiry_hours) {
            $stmt->execute([$qr_token, $qr_url, $expiry_hours, $generated_by, $school_id, $class_id]);
        } else {
            $stmt->execute([$qr_token, $qr_url, $generated_by, $school_id, $class_id]);
        }
    } else {
        // Insert new
        $expires_sql = $expiry_hours ? "expires_at = DATE_ADD(NOW(), INTERVAL ? HOUR)" : "expires_at = NULL";
        $stmt = $pdo->prepare("
            INSERT INTO class_qr_codes (school_id, class_id, class_name, qr_token, qr_image, generated_at, $expires_sql, status, generated_by)
            VALUES (?, ?, ?, ?, ?, NOW(), ?, 'active', ?)
        ");
        
        if ($expiry_hours) {
            $stmt->execute([$school_id, $class_id, $class_name, $qr_token, $qr_url, $expiry_hours, $generated_by]);
        } else {
            $stmt->execute([$school_id, $class_id, $class_name, $qr_token, $qr_url, $generated_by]);
        }
    }
    
    return [
        'id' => $existing ? $existing['id'] : $pdo->lastInsertId(),
        'token' => $qr_token,
        'qr_url' => $qr_url,
        'class_id' => $class_id,
        'class_name' => $class_name,
        'expires_at' => $expiry_hours ? date('Y-m-d H:i:s', strtotime("+{$expiry_hours} hours")) : 'never'
    ];
}

/**
 * Verify Class QR Code validity
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @param int $class_id Class ID
 * @param string $token QR token
 * @return array|false
 */
function verifyClassQRCode($pdo, $school_id, $class_id, $token)
{
    $stmt = $pdo->prepare("
        SELECT * FROM class_qr_codes 
        WHERE school_id = ? AND class_id = ? AND qr_token = ? AND status = 'active'
        AND (expires_at IS NULL OR expires_at > NOW())
    ");
    $stmt->execute([$school_id, $class_id, $token]);
    $qr = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($qr) {
        return $qr;
    }
    
    return false;
}

/**
 * Get all active class QR codes for a school
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @return array
 */
function getAllClassQRCodes($pdo, $school_id)
{
    $stmt = $pdo->prepare("
        SELECT cq.*, c.class_name 
        FROM class_qr_codes cq
        JOIN classes c ON cq.class_id = c.id
        WHERE cq.school_id = ? AND cq.status = 'active'
        ORDER BY c.class_name
    ");
    $stmt->execute([$school_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get current active school QR code
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @return array|null
 */
function getActiveSchoolQRCode($pdo, $school_id)
{
    $stmt = $pdo->prepare("
        SELECT * FROM school_qr_codes 
        WHERE school_id = ? AND status = 'active' AND expires_at > NOW()
        ORDER BY generated_at DESC LIMIT 1
    ");
    $stmt->execute([$school_id]);
    $qr = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $qr ?: null;
}

/**
 * Revoke a school QR code (make it invalid)
 * 
 * @param PDO $pdo Database connection
 * @param int $qr_id QR code ID
 * @param int $school_id School ID
 * @return bool
 */
function revokeSchoolQRCode($pdo, $qr_id, $school_id)
{
    $stmt = $pdo->prepare("UPDATE school_qr_codes SET status = 'revoked' WHERE id = ? AND school_id = ?");
    return $stmt->execute([$qr_id, $school_id]);
}

/**
 * Generate QR for student (existing function - kept for compatibility)
 * 
 * @param int $student_id Student ID
 * @param string $admission_number Admission number
 * @param string $full_name Student full name
 * @return string QR data payload
 */
function generateStudentQRCode($student_id, $admission_number, $full_name)
{
    return json_encode([
        'id' => $student_id,
        'admission' => $admission_number,
        'name' => $full_name,
        'school_id' => SCHOOL_ID,
        'type' => 'student',
        'timestamp' => time()
    ]);
}

/**
 * Save student QR code image (existing function - kept for compatibility)
 * 
 * @param PDO $pdo Database connection
 * @param int $student_id Student ID
 * @param string $qr_data QR data payload
 * @return bool
 */
function saveStudentQRCode($pdo, $student_id, $qr_data)
{
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/msv/uploads/qrcodes/';
    
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $filename = 'student_' . $student_id . '.png';
    $filepath = $upload_dir . $filename;
    
    QRcode::png($qr_data, $filepath, QR_ECLEVEL_L, 10);
    
    if (file_exists($filepath)) {
        $qr_url = '/msv/uploads/qrcodes/' . $filename;
        $stmt = $pdo->prepare("UPDATE students SET qr_code = ?, qr_updated_at = NOW() WHERE id = ?");
        $stmt->execute([$qr_url, $student_id]);
        return true;
    }
    
    return false;
}

/**
 * Regenerate student QR code (existing function - kept for compatibility)
 * 
 * @param PDO $pdo Database connection
 * @param int $student_id Student ID
 * @return bool
 */
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
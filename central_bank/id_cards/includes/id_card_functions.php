<?php
// /central_bank/id_cards/includes/id_card_functions.php
// Remove the require_once lines at the top since config is already included

// Do NOT add these lines here (they are already in the parent files):
// require_once '../../includes/config.php';
// require_once '../../includes/auth.php';

// Just add the functions directly
use setasign\Fpdi\Fpdi;

/**
 * Generate QR Code for student
 */
function generateStudentQRCode($student_id, $admission_number, $school_id, $school_code)
{
    $qr_data = json_encode([
        'student_id' => $student_id,
        'admission_number' => $admission_number,
        'school_id' => $school_id,
        'school_code' => $school_code,
        'verification_url' => "https://acad.com.ng/verify/$school_code/$admission_number"
    ]);

    // Use Google Charts API for QR code
    $qr_url = "https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=" . urlencode($qr_data) . "&choe=UTF-8";

    return $qr_url;
}

/**
 * Get student full details for ID card
 */
function getStudentIDCardData($student_id, $school_id)
{
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT 
            s.id,
            s.admission_number,
            s.full_name,
            s.class,
            s.profile_picture,
            s.qr_code,
            s.parent_phone,
            s.dob,
            s.gender,
            s.address,
            s.guardian_name,
            sch.school_name,
            sch.school_code,
            sch.motto,
            sch.logo_path,
            sch.primary_color,
            sch.secondary_color
        FROM students s
        JOIN schools sch ON s.school_id = sch.id
        WHERE s.id = ? AND s.school_id = ?
    ");
    $stmt->execute([$student_id, $school_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get ID card settings for a school
 */
function getIDCardSettings($school_id)
{
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT * FROM id_card_settings WHERE school_id = ?
    ");
    $stmt->execute([$school_id]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        // Default settings
        return [
            'card_back_text' => "This ID card is the property of the school. If found, please return to the school administration. Unauthorized use is prohibited.",
            'card_template' => 'modern',
            'primary_color' => '#722F37',
            'secondary_color' => '#d4af7a',
            'show_motto' => 1,
            'show_qr' => 1
        ];
    }

    return $settings;
}

/**
 * Get students by class for bulk generation
 */
function getStudentsByClass($school_id, $class)
{
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT id, admission_number, full_name, class, profile_picture
        FROM students
        WHERE school_id = ? AND class = ? AND status = 'active'
        ORDER BY full_name
    ");
    $stmt->execute([$school_id, $class]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get all classes for a school
 */
function getSchoolClasses($school_id)
{
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT DISTINCT class 
        FROM students 
        WHERE school_id = ? AND class IS NOT NULL AND class != ''
        ORDER BY class
    ");
    $stmt->execute([$school_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Get all schools for developer selection
 */
function getAllSchoolsForIDCards()
{
    global $pdo;

    return $pdo->query("
        SELECT id, school_name, school_code, logo_path, motto 
        FROM schools 
        WHERE status = 'active'
        ORDER BY school_name
    ")->fetchAll();
}

/**
 * Log ID card generation
 */
function logIDCardGeneration($school_id, $student_id, $admin_id, $file_path = null)
{
    global $pdo;

    $stmt = $pdo->prepare("
        INSERT INTO id_card_generation_log (school_id, student_id, generated_by, file_path, ip_address, generated_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $school_id,
        $student_id,
        $admin_id,
        $file_path,
        $_SERVER['REMOTE_ADDR']
    ]);
}

/**
 * Get profile picture path
 */
function getProfilePicturePath($student, $school_code)
{
    if (!empty($student['profile_picture'])) {
        $local_path = "../.." . $student['profile_picture'];
        if (file_exists($local_path)) {
            return $local_path;
        }

        $school_path = "../../uploads/schools/$school_code/students/" . $student['profile_picture'];
        if (file_exists($school_path)) {
            return $school_path;
        }
    }

    return __DIR__ . '/../assets/default-avatar.png';
}

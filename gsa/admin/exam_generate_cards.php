<?php
// gsa/admin/exam_generate_cards.php — Step 4: Generate & View Report Cards
// FIXED: Ordinal numbers, student name centered, reduced spacing, removed grading scale

error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../includes/config.php';

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /gsa/login.php");
    exit();
}
if (isset($_SESSION['admin_id'])) {
    $admin_id   = $_SESSION['admin_id'];
    $admin_name = $_SESSION['admin_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'super_admin';
} else {
    $admin_id   = $_SESSION['user_id'];
    $admin_name = $_SESSION['user_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

$school_id       = SCHOOL_ID;
$school_name     = strtoupper(SCHOOL_NAME);
$primary_color   = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;

// ── Get school details from database ─────────────────────────────────────────
$school_logo = '';
$school_motto = '';
$school_address = '';
$school_phone = '';
$school_email = '';

try {
    $stmt = $pdo->prepare("SELECT logo_path, motto, address, contact_phone, contact_email FROM schools WHERE id = ? LIMIT 1");
    $stmt->execute([$school_id]);
    $db_info = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($db_info) {
        $school_motto = $db_info['motto'] ?? '';
        $school_address = $db_info['address'] ?? '';
        $school_phone = $db_info['contact_phone'] ?? '';
        $school_email = $db_info['contact_email'] ?? '';
        $school_logo = $db_info['logo_path'] ?? '';
    }
} catch (Exception $e) {
    error_log("Failed to load school info: " . $e->getMessage());
}

if (empty($school_motto) && defined('SCHOOL_MOTTO')) $school_motto = SCHOOL_MOTTO;
if (empty($school_address) && defined('SCHOOL_ADDRESS')) $school_address = SCHOOL_ADDRESS;
if (empty($school_phone) && defined('SCHOOL_PHONE')) $school_phone = SCHOOL_PHONE;
if (empty($school_email) && defined('SCHOOL_EMAIL')) $school_email = SCHOOL_EMAIL;

// Fix logo path
if (!empty($school_logo)) {
    $school_logo = str_replace(['../', './', '..\\', '.\\'], '', $school_logo);
    $school_logo = ltrim($school_logo, '/');
    $logo_paths = [
        $_SERVER['DOCUMENT_ROOT'] . '/gsa/' . $school_logo,
        $_SERVER['DOCUMENT_ROOT'] . '/' . $school_logo,
        $_SERVER['DOCUMENT_ROOT'] . '/gsa/assets/logos/logo.png',
        $_SERVER['DOCUMENT_ROOT'] . '/assets/logos/logo.png',
    ];
    $logo_found = false;
    foreach ($logo_paths as $path) {
        if (file_exists($path)) {
            $school_logo = str_replace($_SERVER['DOCUMENT_ROOT'], '', $path);
            $logo_found = true;
            break;
        }
    }
    if (!$logo_found) $school_logo = '';
}

// ── Require record_id ─────────────────────────────────────────────────────────
$record_id = isset($_GET['record_id']) ? (int)$_GET['record_id'] : 0;
if (!$record_id) {
    header("Location: exam_record_setup.php");
    exit();
}

$success_msg = '';
$error_msg   = '';

if (!empty($_SESSION['flash_success'])) {
    $success_msg = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $error_msg = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ── Load exam record ──────────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("SELECT * FROM report_card_settings WHERE id = ? AND school_id = ?");
    $stmt->execute([$record_id, $school_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $record = null;
}

if (!$record || ($record['status'] ?? '') === 'archived') {
    header("Location: exam_record_setup.php");
    exit();
}

$class   = $record['class'];
$session = $record['session'];
$term    = $record['term'];

// ── Display options from record ───────────────────────────────────────────────
$show_class_position   = (int)($record['show_class_position'] ?? 1);
$show_subject_position = (int)($record['show_subject_position'] ?? 1);
$show_promoted_to      = (int)($record['show_promoted_to'] ?? 1);
$show_attendance       = (int)($record['show_attendance'] ?? 1);
$show_lowest_highest_avg = (int)($record['show_lowest_highest_avg'] ?? 1);
$show_affective_traits = (int)($record['show_affective_traits'] ?? 1);
$show_psychomotor      = (int)($record['show_psychomotor'] ?? 1);

// ── Get class_id ──────────────────────────────────────────────────────────────
$class_id = 0;
try {
    $stmt = $pdo->prepare("SELECT id FROM classes WHERE class_name = ? AND school_id = ?");
    $stmt->execute([$class, $school_id]);
    $class_row = $stmt->fetch(PDO::FETCH_ASSOC);
    $class_id = $class_row ? (int)$class_row['id'] : 0;
} catch (Exception $e) {
    error_log("Failed to get class_id: " . $e->getMessage());
}

// ── Decode score types & grading ──────────────────────────────────────────────
$decoded      = json_decode($record['score_types'] ?? '{}', true);
$score_types  = $decoded['score_types']  ?? (is_array($decoded) && isset($decoded[0]['label']) ? $decoded : []);
$grading_scale = $decoded['grading_scale'] ?? [];

if (empty($grading_scale)) {
    $grading_scale = [
        ['grade' => 'A', 'min' => 75,  'max' => 100, 'remark' => 'Excellent'],
        ['grade' => 'B', 'min' => 65,  'max' => 74,  'remark' => 'Very Good'],
        ['grade' => 'C', 'min' => 50,  'max' => 64,  'remark' => 'Good'],
        ['grade' => 'D', 'min' => 40,  'max' => 49,  'remark' => 'Pass'],
        ['grade' => 'F', 'min' => 0,   'max' => 39,  'remark' => 'Fail'],
    ];
}

// ── Helper function for ordinal numbers (FIXED) ───────────────────────────────
function ordinal($number) {
    if ($number <= 0) return '-';
    $number = (int)$number;
    $last_digit = $number % 10;
    $last_two = $number % 100;
    
    // Special cases for 11, 12, 13
    if ($last_two >= 11 && $last_two <= 13) {
        return $number . 'th';
    }
    
    // Normal cases
    switch ($last_digit) {
        case 1: return $number . 'st';
        case 2: return $number . 'nd';
        case 3: return $number . 'rd';
        default: return $number . 'th';
    }
}

function getGradeInfo(float $total, array $scale): array
{
    foreach ($scale as $row) {
        if ($total >= (float)$row['min'] && $total <= (float)$row['max'])
            return ['grade' => $row['grade'], 'remark' => $row['remark']];
    }
    return ['grade' => 'F', 'remark' => 'Fail'];
}

// ── Handle publish actions ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];
    if ($act === 'publish_record') {
        try {
            $pdo->prepare("UPDATE report_card_settings SET status='published', updated_at=NOW() WHERE id=? AND school_id=?")->execute([$record_id, $school_id]);
            $record['status'] = 'published';
            $_SESSION['flash_success'] = "Report cards published.";
        } catch (Exception $e) { $_SESSION['flash_error'] = "Could not publish: " . $e->getMessage(); }
        header("Location: exam_generate_cards.php?record_id={$record_id}");
        exit();
    }
    if ($act === 'unpublish_record') {
        try {
            $pdo->prepare("UPDATE report_card_settings SET status='active', updated_at=NOW() WHERE id=? AND school_id=?")->execute([$record_id, $school_id]);
            $record['status'] = 'active';
            $_SESSION['flash_success'] = "Record unpublished.";
        } catch (Exception $e) { $_SESSION['flash_error'] = "Could not unpublish: " . $e->getMessage(); }
        header("Location: exam_generate_cards.php?record_id={$record_id}");
        exit();
    }
    if ($act === 'archive_record') {
        try {
            $pdo->prepare("UPDATE report_card_settings SET status='archived', updated_at=NOW() WHERE id=? AND school_id=?")->execute([$record_id, $school_id]);
            $_SESSION['flash_success'] = "Record archived.";
        } catch (Exception $e) { $_SESSION['flash_error'] = "Could not archive: " . $e->getMessage(); }
        header("Location: exam_record_setup.php");
        exit();
    }
}

// ── Load students ─────────────────────────────────────────────────────────────
$students = [];
try {
    if ($class_id > 0) {
        $stmt = $pdo->prepare("SELECT id, full_name, admission_number, gender, dob, guardian_name, profile_picture FROM students WHERE school_id = ? AND class_id = ? AND status = 'active' ORDER BY full_name ASC");
        $stmt->execute([$school_id, $class_id]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("SELECT id, full_name, admission_number, gender, dob, guardian_name, profile_picture FROM students WHERE school_id = ? AND class = ? AND status = 'active' ORDER BY full_name ASC");
        $stmt->execute([$school_id, $class]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) { error_log("Failed to load students: " . $e->getMessage()); }
$total_students = count($students);

// ── Load subjects ─────────────────────────────────────────────────────────────
$subjects = [];
try {
    if ($class_id > 0) {
        $stmt = $pdo->prepare("SELECT s.id, s.subject_name FROM subjects s JOIN subject_classes sc ON sc.subject_id = s.id WHERE sc.school_id = ? AND sc.class_id = ? AND (s.school_id = ? OR s.is_central = 1) ORDER BY s.subject_name ASC");
        $stmt->execute([$school_id, $class_id, $school_id]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("SELECT s.id, s.subject_name FROM subjects s JOIN subject_classes sc ON sc.subject_id = s.id WHERE sc.school_id = ? AND sc.class = ? AND (s.school_id = ? OR s.is_central = 1) ORDER BY s.subject_name ASC");
        $stmt->execute([$school_id, $class, $school_id]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) { error_log("Failed to load subjects: " . $e->getMessage()); }

// ── Load scores ───────────────────────────────────────────────────────────────
$scores = [];
if (!empty($students) && !empty($subjects)) {
    try {
        $sub_ids = array_column($subjects, 'id');
        $ph = implode(',', array_fill(0, count($sub_ids), '?'));
        $stmt = $pdo->prepare("SELECT student_id, subject_id, score_data, total_score, grade, subject_position FROM student_scores WHERE school_id=? AND session=? AND term=? AND subject_id IN ($ph)");
        $stmt->execute(array_merge([$school_id, $session, $term], $sub_ids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['score_data'] = json_decode($row['score_data'] ?? '[]', true) ?: [];
            $scores[(int)$row['student_id']][(int)$row['subject_id']] = $row;
        }
    } catch (Exception $e) { error_log("Failed to load scores: " . $e->getMessage()); }
}

// ── Calculate averages ────────────────────────────────────────────────────────
$student_averages = [];
foreach ($students as $student) {
    $sid = (int)$student['id'];
    $total_score_sum = 0;
    $subject_count = 0;
    if (isset($scores[$sid])) {
        foreach ($scores[$sid] as $subject_id => $score_data) {
            $total_score_sum += (float)($score_data['total_score'] ?? 0);
            $subject_count++;
        }
    }
    $student_averages[$sid] = $subject_count > 0 ? round(($total_score_sum / $subject_count), 1) : 0;
}
$class_highest_avg = !empty($student_averages) ? max($student_averages) : 0;
$class_lowest_avg = !empty($student_averages) ? min(array_filter($student_averages, function($v) { return $v > 0; })) : 0;
if ($class_lowest_avg == 0 && !empty($student_averages)) $class_lowest_avg = min($student_averages);

// Calculate class positions
$position_map = [];
$sorted_averages = $student_averages;
arsort($sorted_averages);
$position = 1;
$prev_avg = null;
foreach ($sorted_averages as $sid => $avg) {
    if ($prev_avg !== null && $avg < $prev_avg) $position++;
    $position_map[$sid] = $position;
    $prev_avg = $avg;
}

// ── Load comments ─────────────────────────────────────────────────────────────
$comments = [];
if (!empty($students)) {
    try {
        $sids = array_column($students, 'id');
        $ph = implode(',', array_fill(0, count($sids), '?'));
        $stmt = $pdo->prepare("SELECT student_id, teachers_comment, principals_comment, class_teachers_name, principals_name, days_present FROM student_comments WHERE school_id=? AND session=? AND term=? AND student_id IN ($ph)");
        $stmt->execute(array_merge([$school_id, $session, $term], $sids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $comments[(int)$row['student_id']] = $row;
        }
    } catch (Exception $e) { error_log("Failed to load comments: " . $e->getMessage()); }
}

// ── Load affective traits ─────────────────────────────────────────────────────
$affective = [];
if (!empty($students) && $show_affective_traits) {
    try {
        $sids = array_column($students, 'id');
        $ph = implode(',', array_fill(0, count($sids), '?'));
        $stmt = $pdo->prepare("SELECT student_id, punctuality, attendance, politeness, honesty, neatness, reliability, relationship, self_control FROM affective_traits WHERE student_id IN ($ph) AND session=? AND term=?");
        $stmt->execute(array_merge($sids, [$session, $term]));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $affective[(int)$row['student_id']] = $row;
        }
    } catch (Exception $e) { error_log("Failed to load affective traits: " . $e->getMessage()); }
}

// ── Load psychomotor skills ───────────────────────────────────────────────────
$psychomotor = [];
if (!empty($students) && $show_psychomotor) {
    try {
        $sids = array_column($students, 'id');
        $ph = implode(',', array_fill(0, count($sids), '?'));
        $stmt = $pdo->prepare("SELECT student_id, handwriting, verbal_fluency, sports, handling_tools, drawing_painting, musical_skills FROM psychomotor_skills WHERE school_id=? AND session=? AND term=? AND student_id IN ($ph)");
        $stmt->execute(array_merge([$school_id, $session, $term], $sids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $psychomotor[(int)$row['student_id']] = $row;
        }
    } catch (Exception $e) { error_log("Failed to load psychomotor: " . $e->getMessage()); }
}

// ── Load promoted_to ──────────────────────────────────────────────────────────
$promoted_to_data = [];
if (!empty($students) && $show_promoted_to) {
    try {
        $sids = array_column($students, 'id');
        $ph = implode(',', array_fill(0, count($sids), '?'));
        $stmt = $pdo->prepare("SELECT student_id, promoted_to FROM student_positions WHERE school_id=? AND session=? AND term=? AND student_id IN ($ph)");
        $stmt->execute(array_merge([$school_id, $session, $term], $sids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $promoted_to_data[(int)$row['student_id']] = $row['promoted_to'];
        }
    } catch (Exception $e) { error_log("Failed to load promoted_to: " . $e->getMessage()); }
}

// ── Preview student ───────────────────────────────────────────────────────────
$preview_sid = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
if ($preview_sid === 0 && !empty($students)) $preview_sid = (int)$students[0]['id'];
$preview_student = null;
foreach ($students as $s) {
    if ((int)$s['id'] === $preview_sid) {
        $preview_student = $s;
        break;
    }
}

// ── Check readiness ───────────────────────────────────────────────────────────
$students_with_scores = 0;
$students_with_comments = 0;
foreach ($students as $s) {
    $sid = (int)$s['id'];
    if (!empty($scores[$sid])) $students_with_scores++;
    if (!empty($comments[$sid])) $students_with_comments++;
}
$all_ready = ($students_with_scores >= $total_students && $students_with_comments >= $total_students);

// ── Trait definitions ─────────────────────────────────────────────────────────
$affective_fields = [
    'punctuality' => 'Punctuality',
    'attendance' => 'Attendance',
    'politeness' => 'Politeness',
    'honesty' => 'Honesty',
    'neatness' => 'Neatness',
    'reliability' => 'Reliability',
    'relationship' => 'Relationship',
    'self_control' => 'Self Control',
];
$psychomotor_fields = [
    'handwriting' => 'Handwriting',
    'verbal_fluency' => 'Verbal Fluency',
    'sports' => 'Sports',
    'handling_tools' => 'Handling tools',
    'drawing_painting' => 'Drawing/Painting',
    'musical_skills' => 'Musical Skills',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> — Report Cards</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: <?php echo $primary_color; ?>;
            --secondary: <?php echo $secondary_color; ?>;
            --light: #ecf0f1;
            --dark: #2c3e50;
            --shadow: 0 2px 8px rgba(0,0,0,.08);
            --radius: 10px;
        }
        body { font-family: 'Poppins', sans-serif; background: #f5f6fa; color: #333; }
        
        /* Sidebar */
        .sidebar { position: fixed; top: 0; left: 0; width: 260px; height: 100vh; background: linear-gradient(180deg, var(--primary), var(--dark)); color: white; padding: 20px 0; transition: transform .3s; z-index: 1000; transform: translateX(-100%); }
        .sidebar.open { transform: translateX(0); }
        .mobile-toggle { position: fixed; top: 15px; left: 15px; z-index: 1001; width: 44px; height: 44px; background: var(--primary); color: white; border: none; border-radius: 10px; font-size: 20px; cursor: pointer; }
        .overlay { position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 999; opacity: 0; visibility: hidden; transition: .25s; }
        .overlay.show { opacity: 1; visibility: visible; }
        .main { min-height: 100vh; padding: 20px; }
        
        /* Header */
        .top-header, .publish-bar { background: white; border-radius: var(--radius); padding: 12px 20px; margin-bottom: 20px; box-shadow: var(--shadow); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .layout-grid { display: grid; grid-template-columns: 260px 1fr; gap: 20px; }
        @media(max-width:800px) { .layout-grid { grid-template-columns: 1fr; } }
        
        /* Student Panel */
        .student-panel { background: white; border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; }
        .panel-head { background: var(--primary); color: white; padding: 12px 16px; font-weight: 600; }
        .student-list { list-style: none; max-height: 500px; overflow-y: auto; }
        .student-list li a { display: flex; align-items: center; gap: 10px; padding: 10px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #f0f0f0; }
        .student-list li a.active { background: #eef2ff; border-left: 3px solid var(--primary); }
        .s-avatar { width: 32px; height: 32px; background: var(--light); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; }
        .s-badge { font-size: 0.65rem; padding: 2px 8px; border-radius: 20px; background: #e8f5e9; color: #2e7d32; }
        
        /* REPORT CARD - Optimized spacing */
        .rc-card { background: white; border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; max-width: 100%; }
        .rc-header { padding: 8px 16px; border-bottom: 2px solid var(--secondary); display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .rc-logo { width: 55px; height: 55px; object-fit: contain; }
        .rc-school-details { flex: 1; text-align: center; }
        .rc-school-details h1 { font-size: 1rem; font-weight: 700; color: var(--primary); letter-spacing: 1px; margin: 0; }
        .rc-school-details .motto { font-size: 0.65rem; font-style: italic; color: var(--secondary); }
        .rc-school-details .address { font-size: 0.6rem; color: #555; }
        .rc-school-details .contacts { font-size: 0.55rem; color: #777; }
        .rc-title { background: var(--primary); color: white; display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 0.65rem; font-weight: 600; margin-top: 2px; }
        .rc-photo { width: 55px; height: 55px; object-fit: cover; border-radius: 6px; border: 2px solid var(--light); }
        .rc-photo-placeholder { width: 55px; height: 55px; background: var(--light); border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; color: #aaa; }
        
        /* Student Name - Centered and Distinct */
        .rc-student-name { text-align: center; padding: 12px 16px; background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; border-bottom: 1px solid #e0e0e0; }
        .rc-student-name h2 { font-size: 1.1rem; font-weight: 600; letter-spacing: 1px; margin: 0; text-transform: uppercase; }
        .rc-student-name p { font-size: 0.7rem; opacity: 0.9; margin-top: 4px; }
        
        /* Student Details Grid - Compact */
        .rc-student-details { display: grid; grid-template-columns: repeat(4, 1fr); background: #f8f9fc; padding: 6px 12px; border-bottom: 1px solid #e0e0e0; gap: 6px; font-size: 0.65rem; }
        .rc-student-details .detail-item { display: inline-flex; align-items: baseline; gap: 4px; justify-content: center; }
        .detail-label { font-weight: 600; color: #666; }
        .detail-value { font-weight: 500; color: #222; }
        
        /* Section titles - Compact */
        .rc-section-title { background: var(--primary); color: white; padding: 4px 10px; font-size: 0.7rem; font-weight: 600; }
        .rc-section-title i { margin-right: 5px; }
        
        /* ACADEMIC TABLE - Compact spacing */
        .rc-table { width: 100%; border-collapse: collapse; font-size: 0.72rem; }
        .rc-table th { background: #eef2ff; padding: 5px 3px; text-align: center; border: 1px solid #d0d7de; font-weight: 600; font-size: 0.65rem; }
        .rc-table th:first-child { text-align: left; padding-left: 8px; }
        .rc-table td { padding: 4px 3px; border: 1px solid #e8e8e8; text-align: center; font-size: 0.7rem; }
        .rc-table td:first-child { text-align: left; padding-left: 8px; font-weight: 500; }
        .rc-table .rc-total { font-weight: 700; color: var(--primary); }
        
        /* Grade badges */
        .g-badge { display: inline-block; padding: 1px 6px; border-radius: 10px; font-size: 0.65rem; font-weight: 600; }
        .g-a { background: #d4edda; color: #155724; }
        .g-b { background: #cce5ff; color: #004085; }
        .g-c { background: #fff3cd; color: #856404; }
        .g-d { background: #fce4ec; color: #880e4f; }
        .g-f { background: #f8d7da; color: #721c24; }
        
        /* Position badge */
        .pos-badge { display: inline-block; padding: 1px 5px; border-radius: 10px; font-size: 0.65rem; font-weight: 600; background: #e3f2fd; color: #1565c0; }
        
        /* Summary stats - Compact */
        .rc-summary { display: flex; flex-wrap: wrap; background: #f0f4ff; border-top: 1px solid var(--secondary); border-bottom: 1px solid #e0e0e0; padding: 4px 8px; gap: 12px; justify-content: center; }
        .summary-item { text-align: center; padding: 2px 6px; }
        .summary-item .value { font-size: 0.8rem; font-weight: 700; color: var(--primary); }
        .summary-item .label { font-size: 0.55rem; color: #666; }
        
        /* Traits - Very compact */
        .traits-compact { display: flex; flex-wrap: wrap; border-bottom: 1px solid #e0e0e0; }
        .trait-col { flex: 1; min-width: 140px; }
        .trait-row { display: flex; justify-content: space-between; align-items: center; padding: 2px 10px; border-bottom: 1px solid #f0f0f0; font-size: 0.62rem; }
        .trait-val { padding: 1px 5px; border-radius: 5px; font-size: 0.58rem; font-weight: 600; }
        .tv-a { background: #d4edda; color: #155724; }
        .tv-b { background: #cce5ff; color: #004085; }
        .tv-c { background: #fff3cd; color: #856404; }
        .tv-d { background: #ffe0b2; color: #e65100; }
        .tv-e { background: #f8d7da; color: #721c24; }
        .tv-null { background: #f0f0f0; color: #999; }
        
        /* Comments - Compact */
        .comments-compact { display: flex; border-bottom: 1px solid #e0e0e0; }
        .comment-box { flex: 1; padding: 5px 10px; border-right: 1px solid #e0e0e0; }
        .comment-box:last-child { border-right: none; }
        .comment-box .c-label { font-size: 0.55rem; color: var(--primary); font-weight: 600; margin-bottom: 2px; }
        .comment-box .c-text { font-size: 0.62rem; line-height: 1.25; }
        .comment-box .c-signature { font-size: 0.5rem; color: #888; margin-top: 2px; border-top: 1px dashed #ddd; padding-top: 2px; }
        
        /* Footer */
        .rc-footer { background: linear-gradient(90deg, var(--primary), var(--dark)); color: white; padding: 4px 12px; display: flex; justify-content: space-between; font-size: 0.55rem; }
        
        /* Buttons */
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; font-family: 'Poppins', sans-serif; font-size: 0.8rem; font-weight: 500; cursor: pointer; border: none; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-secondary { background: white; color: var(--primary); border: 1px solid var(--primary); }
        
        /* Alerts */
        .alert-warning { background: #fff3cd; color: #856404; padding: 12px; border-radius: var(--radius); margin-bottom: 15px; border-left: 4px solid #f39c12; }
        .alert-success { background: #d4edda; color: #155724; padding: 12px; border-radius: var(--radius); margin-bottom: 15px; border-left: 4px solid #27ae60; }
        .alert-danger { background: #f8d7da; color: #721c24; padding: 12px; border-radius: var(--radius); margin-bottom: 15px; border-left: 4px solid #e74c3c; }
        .empty-state { text-align: center; padding: 30px; color: #999; }
        
        /* PRINT */
        @media print {
            @page { size: A4 portrait; margin: 5mm; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            .no-print, .sidebar, .mobile-toggle, .overlay, .top-header, .publish-bar, .student-panel, .layout-grid>.student-panel, button, .btn { display: none !important; }
            .layout-grid { display: block !important; }
            .rc-card { margin: 0; box-shadow: none; page-break-inside: avoid; break-inside: avoid; max-width: 100%; }
        }
    </style>
</head>
<body>
    <div class="overlay" id="overlay"></div>
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-header" style="padding:0 20px 15px;">
            <div class="logo" style="display:flex;align-items:center;gap:10px;">
                <div class="logo-icon" style="width:40px;height:40px;background:var(--secondary);border-radius:8px;display:flex;align-items:center;justify-content:center;"><i class="fas fa-graduation-cap"></i></div>
                <div><h3 style="font-size:0.9rem;"><?php echo htmlspecialchars($school_name); ?></h3><p style="font-size:0.7rem;">Admin Portal</p></div>
            </div>
        </div>
        <ul class="nav-links" style="list-style:none;padding:0 15px;">
            <li><a href="index.php" style="display:flex;gap:10px;padding:10px;color:white;text-decoration:none;"><i class="fas fa-home"></i>Dashboard</a></li>
            <li><a href="exam_record_setup.php" style="display:flex;gap:10px;padding:10px;color:white;text-decoration:none;"><i class="fas fa-file-alt"></i>Exam Records</a></li>
            <li><a href="exam_generate_cards.php?record_id=<?php echo $record_id; ?>" style="display:flex;gap:10px;padding:10px;color:white;text-decoration:none;background:rgba(255,255,255,0.2);border-radius:8px;"><i class="fas fa-id-card"></i>Report Cards</a></li>
            <li><a href="logout.php" style="display:flex;gap:10px;padding:10px;color:white;text-decoration:none;"><i class="fas fa-sign-out-alt"></i>Logout</a></li>
        </ul>
    </nav>
    <button class="mobile-toggle" id="menuBtn"><i class="fas fa-bars"></i></button>

    <main class="main">
        <div class="top-header no-print">
            <div><h1 style="font-size:1.1rem;"><i class="fas fa-id-card"></i> Generate Report Cards</h1><p style="font-size:0.7rem;"><?php echo htmlspecialchars($record['record_name'] ?? "{$class} — {$term} Term {$session}"); ?></p></div>
            <a href="exam_traits_comments.php?record_id=<?php echo $record_id; ?>" style="text-decoration:none;padding:6px 12px;background:#eee;border-radius:6px;font-size:0.8rem;">← Back to Step 3</a>
        </div>

        <?php if ($success_msg): ?><div class="alert-success no-print"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?></div><?php endif; ?>
        <?php if ($error_msg): ?><div class="alert-danger no-print"><i class="fas fa-exclamation-triangle"></i> <?php echo $error_msg; ?></div><?php endif; ?>

        <?php if (empty($students)): ?>
            <div class="alert-warning">No active students found for <?php echo htmlspecialchars($class); ?>.</div>
        <?php elseif (empty($subjects)): ?>
            <div class="alert-warning">No subjects found for <?php echo htmlspecialchars($class); ?>.</div>
        <?php else: ?>

            <div class="publish-bar no-print">
                <div><strong><?php echo htmlspecialchars($record['record_name'] ?? "{$class} — {$term} Term"); ?></strong><span style="margin-left:10px;font-size:0.7rem;"><?php echo htmlspecialchars($session); ?> • <?php echo htmlspecialchars($class); ?></span></div>
                <div style="display:flex;gap:8px;">
                    <?php if (($record['status'] ?? '') !== 'published'): ?>
                        <form method="POST" style="display:inline"><input type="hidden" name="action" value="publish_record"><button type="submit" class="btn btn-primary" <?php echo !$all_ready ? 'disabled' : ''; ?>>Publish Cards</button></form>
                    <?php else: ?>
                        <form method="POST" style="display:inline"><input type="hidden" name="action" value="unpublish_record"><button type="submit" class="btn btn-secondary">Unpublish</button></form>
                    <?php endif; ?>
                    <button class="btn btn-secondary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                    <button class="btn btn-primary" onclick="downloadReportCardPDF()"><i class="fas fa-file-pdf"></i> PDF</button>
                </div>
            </div>

            <div class="layout-grid">
                <div class="student-panel no-print">
                    <div class="panel-head">Students (<?php echo $total_students; ?>)</div>
                    <ul class="student-list">
                        <?php foreach ($students as $s):
                            $sid = (int)$s['id'];
                            $has_scores = !empty($scores[$sid]);
                            $has_comm = !empty($comments[$sid]);
                        ?>
                            <li><a href="?record_id=<?php echo $record_id; ?>&student_id=<?php echo $sid; ?>" class="<?php echo ($sid === $preview_sid) ? 'active' : ''; ?>"><div class="s-avatar"><?php echo strtoupper(substr($s['full_name'], 0, 1)); ?></div><div style="flex:1;"><strong style="font-size:0.75rem;"><?php echo htmlspecialchars($s['full_name']); ?></strong><span style="font-size:0.65rem;color:#888;display:block;"><?php echo htmlspecialchars($s['admission_number']); ?></span></div><?php if ($has_scores && $has_comm): ?><span class="s-badge">✓</span><?php else: ?><span class="s-badge" style="background:#fce4ec;color:#c62828;">!</span><?php endif; ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="rc-card" id="reportCard">
                    <?php if ($preview_student):
                        $sid = (int)$preview_student['id'];
                        $s_scores = $scores[$sid] ?? [];
                        $s_comm = $comments[$sid] ?? [];
                        $s_af = $affective[$sid] ?? [];
                        $s_pm = $psychomotor[$sid] ?? [];
                        $s_promoted = $promoted_to_data[$sid] ?? '';
                        
                        $student_avg = $student_averages[$sid] ?? 0;
                        $student_position = $position_map[$sid] ?? 0;
                        $days_opened = (int)($record['days_school_opened'] ?? 90);
                        $days_present = (int)($s_comm['days_present'] ?? 0);
                    ?>
                        <div class="rc-header">
    <?php 
    // Try multiple possible logo paths
    $logo_displayed = false;
    $logo_paths_to_try = [
        $school_logo,
        '/gsa/assets/logos/logo.png',
        '/assets/logos/logo.png',
        '/gsa/admin/assets/logo.png',
        '/gsa/uploads/logo.png',
        '../assets/logos/logo.png',
        'assets/logos/logo.png'
    ];
    
    foreach ($logo_paths_to_try as $logo_path) {
        if (!empty($logo_path) && file_exists($_SERVER['DOCUMENT_ROOT'] . $logo_path)) {
            echo '<img class="rc-logo" src="' . htmlspecialchars($logo_path) . '" alt="School Logo" onerror="this.style.display=\'none\'">';
            $logo_displayed = true;
            break;
        } elseif (!empty($logo_path) && file_exists($_SERVER['DOCUMENT_ROOT'] . '/gsa/' . $logo_path)) {
            echo '<img class="rc-logo" src="/gsa/' . htmlspecialchars($logo_path) . '" alt="School Logo" onerror="this.style.display=\'none\'">';
            $logo_displayed = true;
            break;
        } elseif (!empty($logo_path) && file_exists($logo_path)) {
            echo '<img class="rc-logo" src="' . htmlspecialchars($logo_path) . '" alt="School Logo" onerror="this.style.display=\'none\'">';
            $logo_displayed = true;
            break;
        }
    }
    
    if (!$logo_displayed): 
    ?>
        <div class="rc-photo-placeholder"><i class="fas fa-school"></i></div>
    <?php endif; ?>
                            <div class="rc-school-details">
                                <h1><?php echo htmlspecialchars($school_name); ?></h1>
                                <?php if (!empty($school_motto)): ?><div class="motto"><?php echo htmlspecialchars($school_motto); ?></div><?php endif; ?>
                                <?php if (!empty($school_address)): ?><div class="address"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($school_address); ?></div><?php endif; ?>
                                <?php if (!empty($school_phone) || !empty($school_email)): ?><div class="contacts"><?php if (!empty($school_phone)): ?><i class="fas fa-phone"></i> <?php echo htmlspecialchars($school_phone); ?><?php endif; ?><?php if (!empty($school_email)): ?> | <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($school_email); ?><?php endif; ?></div><?php endif; ?>
                                <div class="rc-title">REPORT CARD — <?php echo strtoupper(htmlspecialchars($term)); ?> TERM <?php echo htmlspecialchars($session); ?></div>
                            </div>
                            <?php if (!empty($preview_student['profile_picture'])): ?>
                                <img class="rc-photo" src="<?php echo htmlspecialchars($preview_student['profile_picture']); ?>" alt="Student Photo">
                            <?php else: ?>
                                <div class="rc-photo-placeholder"><i class="fas fa-user-graduate"></i></div>
                            <?php endif; ?>
                        </div>

                        <!-- Student Name - Centered and Distinct -->
                        <div class="rc-student-name">
                            <h2><?php echo htmlspecialchars($preview_student['full_name']); ?></h2>
                            <p>Admission No: <?php echo htmlspecialchars($preview_student['admission_number']); ?></p>
                        </div>

                        <!-- Student Details - Compact Grid -->
                        <div class="rc-student-details">
                            <div class="detail-item"><span class="detail-label">Class:</span><span class="detail-value"><?php echo htmlspecialchars($class); ?></span></div>
                            <div class="detail-item"><span class="detail-label">Gender:</span><span class="detail-value"><?php echo ucfirst($preview_student['gender'] ?? ''); ?></span></div>
                            <div class="detail-item"><span class="detail-label">Guardian:</span><span class="detail-value"><?php echo htmlspecialchars($preview_student['guardian_name'] ?? '—'); ?></span></div>
                            <?php if ($show_attendance && $days_opened): ?>
                                <div class="detail-item"><span class="detail-label">Attendance:</span><span class="detail-value"><?php echo $days_present; ?>/<?php echo $days_opened; ?> (<?php echo $days_opened > 0 ? round(($days_present / $days_opened) * 100) : 0; ?>%)</span></div>
                            <?php endif; ?>
                            <?php if ($show_promoted_to && !empty($s_promoted)): ?>
                                <div class="detail-item"><span class="detail-label">Promoted to:</span><span class="detail-value"><?php echo htmlspecialchars($s_promoted); ?></span></div>
                            <?php endif; ?>
                        </div>

                        <!-- Academic Performance -->
                        <div class="rc-section-title"><i class="fas fa-chart-line"></i> ACADEMIC PERFORMANCE</div>

                        <?php if (empty($s_scores)): ?>
                            <div class="empty-state"><i class="fas fa-chart-line"></i><p>No scores recorded.</p></div>
                        <?php else: ?>
                            <table class="rc-table">
                                <thead>
                                    <tr>
                                        <th style="width:38%">SUBJECT</th>
                                        <?php foreach ($score_types as $st): ?>
                                            <th><?php echo htmlspecialchars(substr($st['label'] ?? $st['name'] ?? 'CA', 0, 8)); ?></th>
                                        <?php endforeach; ?>
                                        <th>TOTAL</th>
                                        <th>GRADE</th>
                                        <?php if ($show_subject_position): ?><th>POS</th><?php endif; ?>
                                        <th>REMARK</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $total_sum = 0;
                                    $scored_count = 0;
                                    foreach ($subjects as $sub):
                                        $sub_id = (int)$sub['id'];
                                        $row = $s_scores[$sub_id] ?? null;
                                        if (!$row) continue;
                                        $total_sc = (float)$row['total_score'];
                                        $grade_info = getGradeInfo($total_sc, $grading_scale);
                                        $g_cls = strtolower(substr($grade_info['grade'], 0, 1));
                                        $total_sum += $total_sc;
                                        $scored_count++;
                                        $subject_pos = $row['subject_position'] ?? 0;
                                    ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($sub['subject_name']); ?></strong></td>
                                            <?php foreach ($score_types as $st):
                                                $label = $st['label'];
                                                $val = $row['score_data'][$label] ?? '—';
                                            ?>
                                                <td><?php echo is_numeric($val) ? $val : '—'; ?></td>
                                            <?php endforeach; ?>
                                            <td class="rc-total"><?php echo number_format($total_sc, 0); ?></td>
                                            <td><span class="g-badge g-<?php echo $g_cls; ?>"><?php echo $grade_info['grade']; ?></span></td>
                                            <?php if ($show_subject_position): ?>
                                                <td><?php echo $subject_pos > 0 ? ordinal($subject_pos) : '—'; ?></td>
                                            <?php endif; ?>
                                            <td><?php echo $grade_info['remark']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <!-- Summary Stats -->
                            <div class="rc-summary">
                                <div class="summary-item"><div class="value"><?php echo $scored_count; ?></div><div class="label">Subjects</div></div>
                                <div class="summary-item"><div class="value"><?php echo number_format($total_sum, 0); ?></div><div class="label">Total Marks</div></div>
                                <div class="summary-item"><div class="value"><?php echo number_format($student_avg, 1); ?>%</div><div class="label">Average</div></div>
                                <?php if ($show_class_position): ?>
                                    <div class="summary-item"><div class="value"><?php echo $student_position ? ordinal($student_position) : '—'; ?></div><div class="label">Class Position</div></div>
                                <?php endif; ?>
                                <?php if ($show_lowest_highest_avg): ?>
                                    <div class="summary-item"><div class="value"><?php echo number_format($class_highest_avg, 1); ?>%</div><div class="label">Highest in Class</div></div>
                                    <div class="summary-item"><div class="value"><?php echo number_format($class_lowest_avg, 1); ?>%</div><div class="label">Lowest in Class</div></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Affective Traits (only if enabled) - Minimal -->
                        <?php if ($show_affective_traits && !empty($affective_fields)): ?>
                            <div class="rc-section-title" style="background:#5a6268;"><i class="fas fa-heart"></i> AFFECTIVE TRAITS</div>
                            <div class="traits-compact">
                                <div class="trait-col">
                                    <?php $af_keys = array_keys($affective_fields);
                                    $half = ceil(count($af_keys) / 2);
                                    foreach (array_slice($af_keys, 0, $half) as $fld):
                                        $val = $s_af[$fld] ?? null;
                                        $cls = $val ? 'tv-' . strtolower($val) : 'tv-null';
                                    ?>
                                        <div class="trait-row"><span><?php echo htmlspecialchars($affective_fields[$fld]); ?></span><span class="trait-val <?php echo $cls; ?>"><?php echo $val ?: '—'; ?></span></div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="trait-col">
                                    <?php foreach (array_slice($af_keys, $half) as $fld):
                                        $val = $s_af[$fld] ?? null;
                                        $cls = $val ? 'tv-' . strtolower($val) : 'tv-null';
                                    ?>
                                        <div class="trait-row"><span><?php echo htmlspecialchars($affective_fields[$fld]); ?></span><span class="trait-val <?php echo $cls; ?>"><?php echo $val ?: '—'; ?></span></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Psychomotor Skills (only if enabled) - Minimal -->
                        <?php if ($show_psychomotor && !empty($psychomotor_fields)): ?>
                            <div class="rc-section-title" style="background:#5a6268;"><i class="fas fa-futbol"></i> PSYCHOMOTOR SKILLS</div>
                            <div class="traits-compact">
                                <div class="trait-col">
                                    <?php $pm_keys = array_keys($psychomotor_fields);
                                    $half = ceil(count($pm_keys) / 2);
                                    foreach (array_slice($pm_keys, 0, $half) as $fld):
                                        $val = $s_pm[$fld] ?? null;
                                        $cls = $val ? 'tv-' . strtolower($val) : 'tv-null';
                                    ?>
                                        <div class="trait-row"><span><?php echo htmlspecialchars($psychomotor_fields[$fld]); ?></span><span class="trait-val <?php echo $cls; ?>"><?php echo $val ?: '—'; ?></span></div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="trait-col">
                                    <?php foreach (array_slice($pm_keys, $half) as $fld):
                                        $val = $s_pm[$fld] ?? null;
                                        $cls = $val ? 'tv-' . strtolower($val) : 'tv-null';
                                    ?>
                                        <div class="trait-row"><span><?php echo htmlspecialchars($psychomotor_fields[$fld]); ?></span><span class="trait-val <?php echo $cls; ?>"><?php echo $val ?: '—'; ?></span></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Comments -->
                        <div class="rc-section-title" style="background:#5a6268;"><i class="fas fa-comment-dots"></i> COMMENTS</div>
                        <div class="comments-compact">
                            <div class="comment-box">
                                <div class="c-label"><i class="fas fa-chalkboard-teacher"></i> Class Teacher's Comment</div>
                                <div class="c-text"><?php echo nl2br(htmlspecialchars($s_comm['teachers_comment'] ?? '—')); ?></div>
                                <div class="c-signature"><?php echo htmlspecialchars($s_comm['class_teachers_name'] ?? ''); ?></div>
                            </div>
                            <div class="comment-box">
                                <div class="c-label"><i class="fas fa-user-tie"></i> Principal's Comment</div>
                                <div class="c-text"><?php echo nl2br(htmlspecialchars($s_comm['principals_comment'] ?? '—')); ?></div>
                                <div class="c-signature"><?php echo htmlspecialchars($s_comm['principals_name'] ?? ''); ?></div>
                            </div>
                        </div>

                        <!-- Footer -->
                        <div class="rc-footer">
                            <span><?php echo htmlspecialchars($school_name); ?></span>
                            <span>Generated: <?php echo date('d M Y'); ?></span>
                            <span>Status: <?php echo ucfirst($record['status'] ?? 'draft'); ?></span>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">Select a student from the list</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <script>
        const sb = document.getElementById('sidebar');
        const ov = document.getElementById('overlay');
        const btn = document.getElementById('menuBtn');
        if (btn) {
            btn.addEventListener('click', () => { sb.classList.toggle('open'); ov.classList.toggle('show'); });
        }
        if (ov) {
            ov.addEventListener('click', () => { sb.classList.remove('open'); ov.classList.remove('show'); });
        }

        async function downloadReportCardPDF() {
            const card = document.getElementById('reportCard');
            if (!card) { alert('No report card found.'); return; }
            
            let btn = null;
            if (typeof event !== 'undefined' && event && event.target) { btn = event.target.closest('button'); }
            if (!btn) { btn = document.querySelector('.btn-primary:last-child'); }
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...'; }
            
            const hideEls = document.querySelectorAll('.no-print, .sidebar, .mobile-toggle, .overlay, .top-header, .publish-bar, .student-panel, .layout-grid > .student-panel');
            const originalDisplays = [];
            hideEls.forEach((el, i) => { originalDisplays[i] = el.style.display; el.style.display = 'none'; });
            
            try {
                const canvas = await html2canvas(card, { scale: 2.5, useCORS: true, backgroundColor: '#ffffff', logging: false });
                const imgData = canvas.toDataURL('image/jpeg', 0.95);
                const pdf = new jspdf.jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
                const pdfWidth = pdf.internal.pageSize.getWidth();
                const imgWidth = pdfWidth - 10;
                const imgHeight = (canvas.height * imgWidth) / canvas.width;
                pdf.addImage(imgData, 'JPEG', 5, 5, imgWidth, imgHeight);
                const nameEl = card.querySelector('.rc-student-name h2');
                const studentName = nameEl ? nameEl.textContent.trim().replace(/[^a-z0-9]/gi, '_').substring(0, 30) : 'report_card';
                pdf.save(`${studentName}_report_card.pdf`);
            } catch (err) { console.error('PDF error:', err); alert('PDF generation failed: ' + err.message); } 
            finally {
                hideEls.forEach((el, i) => { el.style.display = originalDisplays[i]; });
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-file-pdf"></i> PDF'; }
            }
        }
    </script>
</body>
</html>
<?php
// tbis/admin/exam_generate_cards.php — Step 4: Generate & View Report Cards
// ─────────────────────────────────────────────────────────────────────────────

error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../includes/config.php';

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /tbis/login.php");
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
$school_name     = SCHOOL_NAME;
$primary_color   = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;

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

// ── Helper: grade lookup ──────────────────────────────────────────────────────
function getGradeInfo(float $total, array $scale): array
{
    foreach ($scale as $row) {
        if ($total >= (float)$row['min'] && $total <= (float)$row['max'])
            return ['grade' => $row['grade'], 'remark' => $row['remark']];
    }
    return ['grade' => 'F', 'remark' => 'Fail'];
}

// ── Helper: ordinal suffix ────────────────────────────────────────────────────
function ordinal(int $n): string
{
    if ($n <= 0) return '-';
    $sfx = ['th', 'st', 'nd', 'rd'];
    $v   = $n % 100;
    return $n . ($sfx[($v - 20) % 10] ?? $sfx[min($v, 3)]);
}

// ── Load school info (logo, motto, contact) ───────────────────────────────────
$school_info = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM schools WHERE id = ? LIMIT 1");
    $stmt->execute([$school_id]);
    $school_info = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) { /* non-fatal */
}
$school_logo  = defined('SCHOOL_LOGO') ? SCHOOL_LOGO : ($school_info['logo_path'] ?? '/assets/logos/default.png');
$school_motto = $school_info['motto']        ?? '';
$school_email = $school_info['contact_email'] ?? '';
$school_phone = $school_info['contact_phone'] ?? '';

// ── Handle: publish / unpublish record ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];

    if ($act === 'publish_record') {
        try {
            $pdo->prepare("UPDATE report_card_settings SET status='published', updated_at=NOW() WHERE id=? AND school_id=?")
                ->execute([$record_id, $school_id]);
            $record['status'] = 'published';
            $_SESSION['flash_success'] = "Report cards published. Students & parents can now view them.";
        } catch (Exception $e) {
            $_SESSION['flash_error'] = "Could not publish: " . $e->getMessage();
        }
        header("Location: exam_generate_cards.php?record_id={$record_id}");
        exit();
    }

    if ($act === 'unpublish_record') {
        try {
            $pdo->prepare("UPDATE report_card_settings SET status='active', updated_at=NOW() WHERE id=? AND school_id=?")
                ->execute([$record_id, $school_id]);
            $record['status'] = 'active';
            $_SESSION['flash_success'] = "Record unpublished. Students can no longer view cards.";
        } catch (Exception $e) {
            $_SESSION['flash_error'] = "Could not unpublish: " . $e->getMessage();
        }
        header("Location: exam_generate_cards.php?record_id={$record_id}");
        exit();
    }

    if ($act === 'archive_record') {
        try {
            $pdo->prepare("UPDATE report_card_settings SET status='archived', updated_at=NOW() WHERE id=? AND school_id=?")
                ->execute([$record_id, $school_id]);
            $_SESSION['flash_success'] = "Record archived successfully.";
        } catch (Exception $e) {
            $_SESSION['flash_error'] = "Could not archive: " . $e->getMessage();
        }
        header("Location: exam_record_setup.php");
        exit();
    }
}

// ── Load students ─────────────────────────────────────────────────────────────
$students = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, full_name, admission_number, gender, dob, guardian_name, profile_picture
          FROM students
         WHERE school_id = ? AND class = ? AND status = 'active'
         ORDER BY full_name ASC
    ");
    $stmt->execute([$school_id, $class]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("generate_cards students: " . $e->getMessage());
}
$total_students = count($students);

// ── Load subjects for this class ──────────────────────────────────────────────
$subjects = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.id, s.subject_name
          FROM subjects s
          JOIN subject_classes sc ON sc.subject_id = s.id AND sc.school_id = ?
         WHERE sc.class = ? AND (s.school_id = ? OR s.is_central = 1)
         ORDER BY s.subject_name ASC
    ");
    $stmt->execute([$school_id, $class, $school_id]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("generate_cards subjects: " . $e->getMessage());
}

// ── Load ALL scores for this class/session/term ───────────────────────────────
// Keyed: $scores[$student_id][$subject_id]
$scores = [];
if (!empty($students)) {
    try {
        $sub_ids = array_column($subjects, 'id');
        if (!empty($sub_ids)) {
            $ph = implode(',', array_fill(0, count($sub_ids), '?'));
            $stmt = $pdo->prepare("
                SELECT student_id, subject_id, score_data, total_score, grade, subject_position
                  FROM student_scores
                 WHERE school_id=? AND session=? AND term=? AND subject_id IN ($ph)
            ");
            $stmt->execute(array_merge([$school_id, $session, $term], $sub_ids));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $row['score_data'] = json_decode($row['score_data'] ?? '[]', true) ?: [];
                $scores[(int)$row['student_id']][(int)$row['subject_id']] = $row;
            }
        }
    } catch (Exception $e) {
        error_log("generate_cards scores: " . $e->getMessage());
    }
}

// ── Load positions ────────────────────────────────────────────────────────────
$positions = [];
if (!empty($students)) {
    try {
        $sids = array_column($students, 'id');
        $ph   = implode(',', array_fill(0, count($sids), '?'));
        $stmt = $pdo->prepare("
            SELECT student_id, class_position, total_marks, average, promoted_to
              FROM student_positions
             WHERE school_id=? AND session=? AND term=? AND student_id IN ($ph)
        ");
        $stmt->execute(array_merge([$school_id, $session, $term], $sids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $positions[(int)$row['student_id']] = $row;
        }
    } catch (Exception $e) {
        error_log("generate_cards positions: " . $e->getMessage());
    }
}

// ── Load comments ─────────────────────────────────────────────────────────────
$comments = [];
if (!empty($students)) {
    try {
        $sids = array_column($students, 'id');
        $ph   = implode(',', array_fill(0, count($sids), '?'));
        $stmt = $pdo->prepare("
            SELECT student_id, teachers_comment, principals_comment,
                   class_teachers_name, principals_name, days_present, days_absent
              FROM student_comments
             WHERE school_id=? AND session=? AND term=? AND student_id IN ($ph)
        ");
        $stmt->execute(array_merge([$school_id, $session, $term], $sids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $comments[(int)$row['student_id']] = $row;
        }
    } catch (Exception $e) {
        error_log("generate_cards comments: " . $e->getMessage());
    }
}

// ── Load affective traits ─────────────────────────────────────────────────────
$affective = [];
if (!empty($students)) {
    try {
        $sids = array_column($students, 'id');
        $ph   = implode(',', array_fill(0, count($sids), '?'));
        $stmt = $pdo->prepare("
            SELECT student_id, punctuality, attendance, politeness, honesty,
                   neatness, reliability, relationship, self_control
              FROM affective_traits
             WHERE session=? AND term=? AND student_id IN ($ph)
        ");
        $stmt->execute(array_merge([$session, $term], $sids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $affective[(int)$row['student_id']] = $row;
        }
    } catch (Exception $e) {
        error_log("generate_cards affective: " . $e->getMessage());
    }
}

// ── Load psychomotor ──────────────────────────────────────────────────────────
$psychomotor = [];
if (!empty($students)) {
    try {
        $sids = array_column($students, 'id');
        $ph   = implode(',', array_fill(0, count($sids), '?'));
        $stmt = $pdo->prepare("
            SELECT student_id, handwriting, verbal_fluency, sports,
                   handling_tools, drawing_painting, musical_skills
              FROM psychomotor_skills
             WHERE school_id=? AND session=? AND term=? AND student_id IN ($ph)
        ");
        $stmt->execute(array_merge([$school_id, $session, $term], $sids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $psychomotor[(int)$row['student_id']] = $row;
        }
    } catch (Exception $e) {
        error_log("generate_cards psychomotor: " . $e->getMessage());
    }
}

// ── Compute class-level stats (highest avg, lowest avg, number in class) ──────
$class_averages = [];
foreach ($students as $s) {
    $sid = (int)$s['id'];
    $class_averages[$sid] = isset($positions[$sid]['average'])
        ? (float)$positions[$sid]['average'] : 0.0;
}
$highest_avg  = !empty($class_averages) ? max($class_averages) : 0;
$lowest_avg   = !empty($class_averages) ? min($class_averages) : 0;
$num_in_class = $total_students;

// ── Compute class-level subject highest / lowest ──────────────────────────────
// $subject_stats[$subject_id] = ['highest' => X, 'lowest' => Y]
$subject_stats = [];
foreach ($subjects as $sub) {
    $sid = (int)$sub['id'];
    $totals = [];
    foreach ($students as $s) {
        $stid = (int)$s['id'];
        if (isset($scores[$stid][$sid])) {
            $totals[] = (float)$scores[$stid][$sid]['total_score'];
        }
    }
    $subject_stats[$sid] = [
        'highest' => !empty($totals) ? max($totals) : 0,
        'lowest'  => !empty($totals) ? min($totals) : 0,
    ];
}

// ── Which student to preview ──────────────────────────────────────────────────
$preview_sid = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
if ($preview_sid === 0 && !empty($students)) {
    $preview_sid = (int)$students[0]['id'];
}
$preview_student = null;
foreach ($students as $s) {
    if ((int)$s['id'] === $preview_sid) {
        $preview_student = $s;
        break;
    }
}

// ── Trait display labels ──────────────────────────────────────────────────────
$affective_fields = [
    'punctuality'  => 'Punctuality',
    'attendance'   => 'Attendance',
    'politeness'   => 'Politeness',
    'honesty'      => 'Honesty',
    'neatness'     => 'Neatness',
    'reliability'  => 'Reliability',
    'relationship' => 'Relationship with others',
    'self_control' => 'Self Control',
];
$psychomotor_fields = [
    'handwriting'     => 'Handwriting',
    'verbal_fluency'  => 'Verbal Fluency',
    'sports'          => 'Sports',
    'handling_tools'  => 'Handling of tools',
    'drawing_painting' => 'Drawing / Painting',
    'musical_skills'  => 'Musical Skills',
];
$trait_labels = ['A' => 'Excellent', 'B' => 'Very Good', 'C' => 'Good', 'D' => 'Fair', 'E' => 'Poor'];

// ── Progress / readiness check ────────────────────────────────────────────────
$students_with_scores    = 0;
$students_with_comments  = 0;
foreach ($students as $s) {
    $sid = (int)$s['id'];
    if (!empty($scores[$sid])) $students_with_scores++;
    if (!empty($comments[$sid])) $students_with_comments++;
}
$all_ready = ($students_with_scores >= $total_students && $students_with_comments >= $total_students);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($school_name); ?> — Generate Report Cards</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <style>
        /* ── Reset & root ───────────────────────────────────────────────────── */
        :root {
            --primary: <?php echo $primary_color; ?>;
            --secondary: <?php echo $secondary_color; ?>;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --light: #ecf0f1;
            --dark: #2c3e50;
            --sidebar-w: 260px;
            --shadow: 0 2px 8px rgba(0, 0, 0, .08);
            --radius: 10px;
            --transition: all .25s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            color: #333;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* ── Sidebar ────────────────────────────────────────────────────────── */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-w);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary), var(--dark));
            color: white;
            padding: 20px 0;
            transition: transform .3s;
            z-index: 1000;
            overflow-y: auto;
            transform: translateX(-100%);
        }

        .sidebar.open {
            transform: translateX(0);
        }

        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, .1);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            width: 44px;
            height: 44px;
            background: var(--secondary);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .logo-text h3 {
            font-size: 1rem;
            font-weight: 600;
        }

        .logo-text p {
            font-size: .7rem;
            opacity: .8;
        }

        .admin-info {
            text-align: center;
            padding: 15px;
            background: rgba(255, 255, 255, .1);
            border-radius: 10px;
            margin: 15px;
        }

        .admin-info img {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--secondary);
            margin-bottom: 6px;
        }

        .admin-info h4 {
            font-size: .85rem;
            font-weight: 600;
        }

        .admin-info p {
            font-size: .72rem;
            opacity: .75;
        }

        .nav-section {
            padding: 10px 15px 5px;
            font-size: .68rem;
            text-transform: uppercase;
            color: rgba(255, 255, 255, .5);
            letter-spacing: .8px;
        }

        .nav-links {
            list-style: none;
            padding: 0 15px;
        }

        .nav-links li {
            margin-bottom: 5px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: rgba(255, 255, 255, .9);
            text-decoration: none;
            border-radius: 8px;
            transition: var(--transition);
        }

        .nav-links a:hover {
            background: rgba(255, 255, 255, .15);
        }

        .nav-links a.active {
            background: rgba(255, 255, 255, .2);
            border-left: 3px solid var(--secondary);
        }

        .nav-links i {
            width: 20px;
            text-align: center;
        }

        /* ── Mobile toggle & overlay ─────────────────────────────────────────── */
        .mobile-toggle {
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            width: 44px;
            height: 44px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 20px;
            cursor: pointer;
        }

        .overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .5);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .overlay.show {
            opacity: 1;
            visibility: visible;
        }

        /* ── Main ───────────────────────────────────────────────────────────── */
        .main {
            min-height: 100vh;
            padding: 20px;
            transition: var(--transition);
        }

        /* ── Top header ─────────────────────────────────────────────────────── */
        .top-header {
            background: white;
            padding: 18px 20px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: var(--shadow);
        }

        .top-header h1 {
            color: var(--primary);
            font-size: 1.35rem;
            margin-bottom: 3px;
        }

        .top-header p {
            color: #777;
            font-size: .82rem;
        }

        .back-btn {
            background: white;
            border: 1px solid var(--light);
            color: var(--primary);
            padding: 9px 16px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: .83rem;
            text-decoration: none;
        }

        .back-btn:hover {
            background: var(--light);
        }

        /* ── Step bar ───────────────────────────────────────────────────────── */
        .step-bar {
            display: flex;
            background: white;
            border-radius: var(--radius);
            padding: 14px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            overflow-x: auto;
        }

        .step-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 80px;
            position: relative;
        }

        .step-item:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 14px;
            left: 50%;
            width: 100%;
            height: 2px;
            background: var(--light);
            z-index: 0;
        }

        .step-circle {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
            z-index: 1;
            position: relative;
            border: 2px solid transparent;
        }

        .s-done {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .s-cur {
            background: white;
            color: var(--primary);
            border-color: var(--primary);
        }

        .s-todo {
            background: var(--light);
            color: #999;
            border-color: var(--light);
        }

        .step-lbl {
            font-size: 10px;
            color: #999;
            margin-top: 5px;
            text-align: center;
        }

        /* ── Alerts ─────────────────────────────────────────────────────────── */
        .alert {
            padding: 13px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: .86rem;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid var(--success);
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger);
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid var(--warning);
        }

        .alert i {
            flex-shrink: 0;
            margin-top: 2px;
        }

        /* ── Stats row ──────────────────────────────────────────────────────── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }

        .stat-box {
            background: white;
            border-radius: var(--radius);
            padding: 14px 16px;
            box-shadow: var(--shadow);
            border-top: 3px solid var(--primary);
        }

        .stat-box.green {
            border-top-color: var(--success);
        }

        .stat-box.amber {
            border-top-color: var(--warning);
        }

        .stat-val {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-box.green .stat-val {
            color: var(--success);
        }

        .stat-box.amber .stat-val {
            color: var(--warning);
        }

        .stat-lbl {
            font-size: .74rem;
            color: #777;
            margin-top: 2px;
        }

        /* ── Readiness bar ──────────────────────────────────────────────────── */
        .ready-bar {
            background: white;
            border-radius: var(--radius);
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
        }

        .ready-bar h3 {
            font-size: .9rem;
            color: var(--dark);
            margin-bottom: 12px;
        }

        .ready-checks {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .ready-check {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: .82rem;
            padding: 8px 14px;
            border-radius: 20px;
            background: #f0f0f0;
            color: #666;
        }

        .ready-check.ok {
            background: #d4edda;
            color: #155724;
        }

        .ready-check.warn {
            background: #fff3cd;
            color: #856404;
        }

        .ready-check i {
            font-size: .9rem;
        }

        /* ── Layout grid ────────────────────────────────────────────────────── */
        .layout-grid {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 16px;
        }

        @media(max-width:860px) {
            .layout-grid {
                grid-template-columns: 1fr;
            }
        }

        /* ── Student list panel ──────────────────────────────────────────────── */
        .student-panel {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .panel-head {
            padding: 14px 16px;
            background: var(--primary);
            color: white;
            font-size: .88rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .panel-search {
            padding: 10px 12px;
            border-bottom: 1px solid var(--light);
        }

        .panel-search input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: .82rem;
            font-family: 'Poppins', sans-serif;
        }

        .student-list {
            list-style: none;
            max-height: calc(100vh - 280px);
            overflow-y: auto;
        }

        .student-list li a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            text-decoration: none;
            color: #333;
            border-bottom: 1px solid #f0f0f0;
            transition: background .2s;
            font-size: .83rem;
        }

        .student-list li a:hover {
            background: #f5f6fa;
        }

        .student-list li a.active {
            background: #eef2ff;
            border-left: 3px solid var(--primary);
        }

        .s-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            background: var(--light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .75rem;
            font-weight: 600;
            color: var(--primary);
            flex-shrink: 0;
        }

        .s-avatar img {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
        }

        .s-info {
            flex: 1;
            min-width: 0;
        }

        .s-info strong {
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .s-info span {
            font-size: .72rem;
            color: #888;
        }

        .s-badge {
            font-size: .65rem;
            padding: 2px 7px;
            border-radius: 10px;
            background: #e8f5e9;
            color: #2e7d32;
            font-weight: 500;
            white-space: nowrap;
        }

        .s-badge.missing {
            background: #fce4ec;
            color: #c62828;
        }

        /* ── Report card preview ─────────────────────────────────────────────── */
        .card-wrapper {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .card-toolbar {
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            border-bottom: 1px solid var(--light);
            background: #fafafa;
        }

        .card-toolbar-left {
            font-size: .84rem;
            color: #555;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-toolbar-right {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 18px;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: .83rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            text-decoration: none;
            transition: var(--transition);
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            opacity: .88;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            opacity: .88;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-warning:hover {
            opacity: .88;
        }

        .btn-secondary {
            background: white;
            color: var(--primary);
            border: 1px solid var(--primary);
        }

        .btn-secondary:hover {
            background: var(--primary);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            opacity: .88;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: .78rem;
        }

        .btn:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        /* ═══ REPORT CARD STYLES ═══════════════════════════════════════════════ */
        .rc-wrap {
            padding: 20px;
            font-family: 'Poppins', sans-serif;
            font-size: .82rem;
            color: #1a1a1a;
        }

        .rc-card {
            border: 2px solid var(--primary);
            border-radius: 6px;
            background: white;
            max-width: 780px;
            margin: 0 auto;
            overflow: hidden;
        }

        /* Header */
        .rc-header {
            padding: 18px 24px 14px;
            border-bottom: 3px solid var(--secondary);
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .rc-logo {
            width: 72px;
            height: 72px;
            object-fit: contain;
            flex-shrink: 0;
            align-self: center;
            order: -1;
            /* always leftmost */
        }

        .rc-school-info {
            flex: 1;
            text-align: center;
        }

        .rc-school-info h2 {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .rc-school-info p {
            font-size: .72rem;
            color: #555;
            margin-top: 2px;
        }

        .rc-school-info .rc-title {
            font-size: .95rem;
            font-weight: 600;
            color: var(--dark);
            margin-top: 6px;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        .rc-photo {
            width: 70px;
            height: 70px;
            object-fit: cover;
            border-radius: 4px;
            border: 2px solid var(--light);
            flex-shrink: 0;
        }

        .rc-photo-placeholder {
            width: 70px;
            height: 70px;
            background: var(--light);
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            color: #bbb;
            flex-shrink: 0;
        }

        /* Bio strip */
        .rc-bio {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0;
            border-bottom: 1px solid #ddd;
        }

        .rc-bio-item {
            padding: 7px 16px;
            display: flex;
            gap: 8px;
            border-right: 1px solid #f0f0f0;
            border-bottom: 1px solid #f0f0f0;
            font-size: .78rem;
        }

        .rc-bio-item:nth-child(even) {
            border-right: none;
        }

        .rc-bio-lbl {
            color: #777;
            min-width: 90px;
            font-weight: 500;
        }

        .rc-bio-val {
            color: #222;
            font-weight: 600;
        }

        /* Score table */
        .rc-section-title {
            background: var(--primary);
            color: white;
            padding: 6px 16px;
            font-size: .78rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .rc-table {
            width: 100%;
            border-collapse: collapse;
        }

        .rc-table th {
            background: #f0f4ff;
            color: #444;
            padding: 7px 10px;
            font-size: .73rem;
            font-weight: 600;
            text-align: center;
            border: 1px solid #e0e0e0;
        }

        .rc-table th:first-child {
            text-align: left;
        }

        .rc-table td {
            padding: 6px 10px;
            border: 1px solid #e8e8e8;
            font-size: .76rem;
            text-align: center;
            vertical-align: middle;
        }

        .rc-table td:first-child {
            text-align: left;
            font-weight: 500;
        }

        .rc-table tr:nth-child(even) td {
            background: #fafbff;
        }

        .rc-table tr:hover td {
            background: #f0f4ff;
        }

        .rc-table .rc-total {
            font-weight: 700;
            color: var(--primary);
        }

        /* Grade badges */
        .g-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: .72rem;
            font-weight: 700;
        }

        .g-a {
            background: #d4edda;
            color: #155724;
        }

        .g-b {
            background: #cce5ff;
            color: #004085;
        }

        .g-c {
            background: #fff3cd;
            color: #856404;
        }

        .g-d {
            background: #fce4ec;
            color: #880e4f;
        }

        .g-f {
            background: #f8d7da;
            color: #721c24;
        }

        /* Summary row */
        .rc-summary-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 0;
            border-top: 2px solid var(--secondary);
        }

        .rc-sum-cell {
            padding: 9px 14px;
            text-align: center;
            border-right: 1px solid #e0e0e0;
        }

        .rc-sum-cell:last-child {
            border-right: none;
        }

        .rc-sum-cell .val {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary);
        }

        .rc-sum-cell .lbl {
            font-size: .68rem;
            color: #777;
            margin-top: 2px;
        }

        /* Traits */
        .traits-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
        }

        @media(max-width:600px) {
            .traits-grid {
                grid-template-columns: 1fr;
            }
        }

        .traits-section {
            border-right: 1px solid #e0e0e0;
        }

        .traits-section:last-child {
            border-right: none;
        }

        .trait-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 5px 14px;
            border-bottom: 1px solid #f0f0f0;
            font-size: .76rem;
        }

        .trait-row .t-lbl {
            color: #444;
        }

        .trait-val {
            padding: 2px 9px;
            border-radius: 10px;
            font-size: .7rem;
            font-weight: 600;
        }

        .tv-a {
            background: #d4edda;
            color: #155724;
        }

        .tv-b {
            background: #cce5ff;
            color: #004085;
        }

        .tv-c {
            background: #fff3cd;
            color: #856404;
        }

        .tv-d {
            background: #ffe0b2;
            color: #e65100;
        }

        .tv-e {
            background: #f8d7da;
            color: #721c24;
        }

        .tv-null {
            background: #f0f0f0;
            color: #999;
        }

        /* Comments */
        .rc-comments {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
        }

        @media(max-width:600px) {
            .rc-comments {
                grid-template-columns: 1fr;
            }
        }

        .rc-comment-box {
            padding: 10px 14px;
            border-right: 1px solid #e0e0e0;
        }

        .rc-comment-box:last-child {
            border-right: none;
        }

        .rc-comment-box .c-lbl {
            font-size: .7rem;
            color: var(--primary);
            font-weight: 600;
            margin-bottom: 4px;
        }

        .rc-comment-box .c-text {
            font-size: .78rem;
            color: #333;
            background: #f9f9f9;
            border-radius: 4px;
            padding: 6px 8px;
            min-height: 42px;
            line-height: 1.5;
        }

        .rc-comment-box .c-sig {
            font-size: .7rem;
            color: #888;
            margin-top: 4px;
            border-top: 1px dashed #ddd;
            padding-top: 4px;
        }

        /* Footer strip */
        .rc-footer {
            background: linear-gradient(90deg, var(--primary), var(--dark));
            color: white;
            padding: 8px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: .72rem;
            flex-wrap: wrap;
            gap: 6px;
        }

        .rc-footer strong {
            color: var(--secondary);
        }

        /* Print styles */
        @media print {
            body {
                background: white;
            }

            .sidebar,
            .mobile-toggle,
            .overlay,
            .top-header,
            .step-bar,
            .stats-row,
            .ready-bar,
            .layout-grid .student-panel,
            .card-toolbar,
            .no-print {
                display: none !important;
            }

            .layout-grid {
                grid-template-columns: 1fr !important;
            }

            .card-wrapper {
                box-shadow: none;
                border: none;
            }

            .rc-wrap {
                padding: 0;
            }

            .rc-card {
                border: 1.5px solid #333;
                max-width: 100%;
                page-break-inside: avoid;
            }

            .main {
                padding: 0;
            }
        }

        /* ── Publish action bar ──────────────────────────────────────────────── */
        .publish-bar {
            background: white;
            border-radius: var(--radius);
            padding: 16px 20px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .publish-bar .pb-info strong {
            display: block;
            font-size: .9rem;
            color: var(--dark);
        }

        .publish-bar .pb-info span {
            font-size: .78rem;
            color: #777;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: .75rem;
            font-weight: 600;
        }

        .pill-draft {
            background: #fff3cd;
            color: #856404;
        }

        .pill-active {
            background: #cce5ff;
            color: #004085;
        }

        .pill-published {
            background: #d4edda;
            color: #155724;
        }

        @media(max-width:580px) {
            .rc-bio {
                grid-template-columns: 1fr;
            }

            .rc-bio-item {
                border-right: none;
            }

            .rc-comments {
                grid-template-columns: 1fr;
            }

            .rc-summary-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>

<body>

    <!-- ── Sidebar overlay ─────────────────────────────────────────────────────── -->
    <div class="overlay" id="overlay"></div>

    <!-- ── Sidebar ───────────────────────────────────────────────────────────────── -->
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
                <div class="logo-text">
                    <h3><?php echo htmlspecialchars($school_name); ?></h3>
                    <p>Admin Portal</p>
                </div>
            </div>
        </div>
        <div class="admin-info">
            <div style="width:52px;height:52px;background:var(--secondary);border-radius:50%;
            display:flex;align-items:center;justify-content:center;font-size:1.4rem;margin:0 auto 6px;">
                <i class="fas fa-user-shield"></i>
            </div>
            <h4><?php echo htmlspecialchars($admin_name); ?></h4>
            <p><?php echo ucfirst(str_replace('_', ' ', $admin_role)); ?></p>
        </div>
        <p class="nav-section">Main Menu</p>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-home"></i>Dashboard</a></li>
            <li><a href="students.php"><i class="fas fa-user-graduate"></i>Students</a></li>
            <li><a href="staff.php"><i class="fas fa-chalkboard-teacher"></i>Staff</a></li>
        </ul>
        <p class="nav-section">Exams</p>
        <ul class="nav-links">
            <li><a href="exam_record_setup.php"><i class="fas fa-file-alt"></i>Exam Records</a></li>
            <li><a href="exam_generate_cards.php?record_id=<?php echo $record_id; ?>" class="active">
                    <i class="fas fa-id-card"></i>Report Cards</a></li>
        </ul>
        <p class="nav-section">Settings</p>
        <ul class="nav-links">
            <li><a href="settings.php"><i class="fas fa-cog"></i>Settings</a></li>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i>Logout</a></li>
        </ul>
    </nav>

    <!-- ── Mobile toggle ─────────────────────────────────────────────────────────── -->
    <button class="mobile-toggle" id="menuBtn" aria-label="Menu">
        <i class="fas fa-bars"></i>
    </button>

    <!-- ── Main ──────────────────────────────────────────────────────────────────── -->
    <main class="main">

        <!-- Top header -->
        <div class="top-header no-print">
            <div>
                <h1><i class="fas fa-id-card" style="color:var(--secondary);margin-right:8px"></i>Generate Report Cards</h1>
                <p><?php echo htmlspecialchars($record['record_name'] ?? "{$class} — {$term} Term {$session}"); ?></p>
            </div>
            <a href="exam_traits_comments.php?record_id=<?php echo $record_id; ?>" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Step 3
            </a>
        </div>

        <!-- Step bar -->
        <div class="step-bar no-print">
            <div class="step-item">
                <div class="step-circle s-done"><i class="fas fa-check"></i></div>
                <span class="step-lbl">Setup</span>
            </div>
            <div class="step-item">
                <div class="step-circle s-done"><i class="fas fa-check"></i></div>
                <span class="step-lbl">Scores</span>
            </div>
            <div class="step-item">
                <div class="step-circle s-done"><i class="fas fa-check"></i></div>
                <span class="step-lbl">Traits</span>
            </div>
            <div class="step-item">
                <div class="step-circle s-cur">4</div>
                <span class="step-lbl">Cards</span>
            </div>
        </div>

        <!-- Flash messages -->
        <?php if ($success_msg): ?>
            <div class="alert alert-success no-print">
                <i class="fas fa-check-circle"></i>
                <div><?php echo htmlspecialchars($success_msg); ?></div>
            </div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-danger no-print">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo htmlspecialchars($error_msg); ?></div>
            </div>
        <?php endif; ?>

        <?php if (empty($students)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <div>No active students found for <strong><?php echo htmlspecialchars($class); ?></strong>.
                    Please ensure students are enrolled and active.</div>
            </div>
        <?php else: ?>

            <!-- Stats row -->
            <div class="stats-row no-print">
                <div class="stat-box">
                    <div class="stat-val"><?php echo $total_students; ?></div>
                    <div class="stat-lbl">Students in class</div>
                </div>
                <div class="stat-box green">
                    <div class="stat-val"><?php echo $students_with_scores; ?></div>
                    <div class="stat-lbl">With scores</div>
                </div>
                <div class="stat-box green">
                    <div class="stat-val"><?php echo $students_with_comments; ?></div>
                    <div class="stat-lbl">With comments</div>
                </div>
                <div class="stat-box amber">
                    <div class="stat-val"><?php echo count($subjects); ?></div>
                    <div class="stat-lbl">Subjects</div>
                </div>
                <div class="stat-box">
                    <div class="stat-val"><?php echo number_format($highest_avg, 1); ?>%</div>
                    <div class="stat-lbl">Highest average</div>
                </div>
                <div class="stat-box">
                    <div class="stat-val"><?php echo number_format($lowest_avg, 1); ?>%</div>
                    <div class="stat-lbl">Lowest average</div>
                </div>
            </div>

            <!-- Readiness check -->
            <div class="ready-bar no-print">
                <h3><i class="fas fa-clipboard-check" style="color:var(--primary);margin-right:6px"></i>Readiness Check</h3>
                <div class="ready-checks">
                    <div class="ready-check <?php echo $students_with_scores >= $total_students ? 'ok' : 'warn'; ?>">
                        <i class="fas fa-<?php echo $students_with_scores >= $total_students ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                        Scores: <?php echo $students_with_scores; ?>/<?php echo $total_students; ?> students
                    </div>
                    <div class="ready-check <?php echo $students_with_comments >= $total_students ? 'ok' : 'warn'; ?>">
                        <i class="fas fa-<?php echo $students_with_comments >= $total_students ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                        Comments: <?php echo $students_with_comments; ?>/<?php echo $total_students; ?> students
                    </div>
                    <div class="ready-check <?php echo !empty($affective) ? 'ok' : 'warn'; ?>">
                        <i class="fas fa-<?php echo !empty($affective) ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                        Affective traits: <?php echo count($affective); ?> students
                    </div>
                    <div class="ready-check <?php echo !empty($psychomotor) ? 'ok' : 'warn'; ?>">
                        <i class="fas fa-<?php echo !empty($psychomotor) ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                        Psychomotor: <?php echo count($psychomotor); ?> students
                    </div>
                    <div class="ready-check <?php echo ($record['status'] ?? '') === 'published' ? 'ok' : 'warn'; ?>">
                        <i class="fas fa-<?php echo ($record['status'] ?? '') === 'published' ? 'check-circle' : 'clock'; ?>"></i>
                        Status:
                        <?php
                        $st = $record['status'] ?? 'draft';
                        $pill_cls = ['draft' => 'pill-draft', 'active' => 'pill-active', 'published' => 'pill-published'][$st] ?? 'pill-draft';
                        echo "<span class='status-pill {$pill_cls}'>" . ucfirst($st) . "</span>";
                        ?>
                    </div>
                </div>
            </div>

            <!-- Publish / action bar -->
            <div class="publish-bar no-print">
                <div class="pb-info">
                    <strong>
                        <?php echo htmlspecialchars($record['record_name'] ?? "{$class} — {$term} Term"); ?>
                    </strong>
                    <span><?php echo htmlspecialchars($session); ?> &bull; <?php echo htmlspecialchars($term); ?> Term &bull; <?php echo htmlspecialchars($class); ?></span>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <?php if (($record['status'] ?? '') !== 'published'): ?>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Publish all report cards? Students and parents will be able to view them.')">
                            <input type="hidden" name="action" value="publish_record">
                            <button type="submit" class="btn btn-success btn-sm" <?php echo !$all_ready ? 'title="Some students are missing scores or comments"' : ''; ?>>
                                <i class="fas fa-globe"></i> Publish cards
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Unpublish? Students will no longer be able to view cards.')">
                            <input type="hidden" name="action" value="unpublish_record">
                            <button type="submit" class="btn btn-warning btn-sm">
                                <i class="fas fa-eye-slash"></i> Unpublish
                            </button>
                        </form>
                    <?php endif; ?>
                    <button class="btn btn-secondary btn-sm" onclick="window.print()">
                        <i class="fas fa-print"></i> Print this card
                    </button>
                    <button class="btn btn-primary btn-sm" onclick="downloadReportCardPDF()">
                        <i class="fas fa-file-pdf"></i> Download PDF
                    </button>
                    <a href="exam_record_setup.php" class="btn btn-secondary btn-sm">
                        <i class="fas fa-list"></i> All records
                    </a>
                </div>
            </div>

            <!-- Main layout: student list + card preview -->
            <div class="layout-grid">

                <!-- Student list -->
                <div class="student-panel no-print">
                    <div class="panel-head">
                        <i class="fas fa-users"></i> Students (<?php echo $total_students; ?>)
                    </div>
                    <div class="panel-search">
                        <input type="text" id="studentSearch" placeholder="&#128269; Search student..." oninput="filterStudents()">
                    </div>
                    <ul class="student-list" id="studentList">
                        <?php foreach ($students as $s):
                            $sid        = (int)$s['id'];
                            $has_scores = !empty($scores[$sid]);
                            $has_comm   = !empty($comments[$sid]);
                            $is_active  = ($sid === $preview_sid);
                            $pos        = $positions[$sid] ?? null;
                            $pos_str    = $pos ? ordinal((int)$pos['class_position']) : '—';
                            $avg_str    = $pos ? number_format((float)$pos['average'], 1) . '%' : '—';
                        ?>
                            <li data-name="<?php echo htmlspecialchars(strtolower($s['full_name'])); ?>">
                                <a href="?record_id=<?php echo $record_id; ?>&student_id=<?php echo $sid; ?>"
                                    class="<?php echo $is_active ? 'active' : ''; ?>">
                                    <div class="s-avatar">
                                        <?php if (!empty($s['profile_picture'])): ?>
                                            <img src="<?php echo htmlspecialchars($s['profile_picture']); ?>" alt="">
                                        <?php else: ?>
                                            <?php echo strtoupper(substr($s['full_name'], 0, 1)); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="s-info">
                                        <strong><?php echo htmlspecialchars($s['full_name']); ?></strong>
                                        <span><?php echo htmlspecialchars($s['admission_number']); ?> &bull; Pos: <?php echo $pos_str; ?> &bull; Avg: <?php echo $avg_str; ?></span>
                                    </div>
                                    <?php if ($has_scores && $has_comm): ?>
                                        <span class="s-badge">Ready</span>
                                    <?php else: ?>
                                        <span class="s-badge missing">Incomplete</span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Report card preview -->
                <div class="card-wrapper">
                    <div class="card-toolbar no-print">
                        <div class="card-toolbar-left">
                            <i class="fas fa-eye" style="color:var(--primary)"></i>
                            <?php if ($preview_student): ?>
                                Report Card — <strong><?php echo htmlspecialchars($preview_student['full_name']); ?></strong>
                            <?php else: ?>
                                Select a student
                            <?php endif; ?>
                        </div>
                        <div class="card-toolbar-right">
                            <?php if ($preview_student): ?>
                                <button class="btn btn-secondary btn-sm" onclick="window.print()">
                                    <i class="fas fa-print"></i> Print
                                </button>
                                <button class="btn btn-primary btn-sm" id="downloadPdfBtn" onclick="downloadReportCardPDF()">
                                    <i class="fas fa-file-pdf"></i> Download PDF
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($preview_student):
                        $sid      = (int)$preview_student['id'];
                        $s_scores = $scores[$sid] ?? [];
                        $s_pos    = $positions[$sid] ?? [];
                        $s_comm   = $comments[$sid] ?? [];
                        $s_af     = $affective[$sid] ?? [];
                        $s_pm     = $psychomotor[$sid] ?? [];

                        $total_marks   = (float)($s_pos['total_marks'] ?? 0);
                        $avg           = (float)($s_pos['average']     ?? 0);
                        $class_pos     = (int)($s_pos['class_position'] ?? 0);
                        $promoted_to   = $s_pos['promoted_to'] ?? '';
                        $days_opened   = (int)($record['days_school_opened'] ?? 90);
                        $days_present  = (int)($s_comm['days_present'] ?? 0);
                        $days_absent   = $days_opened - $days_present;

                        // Subject count actually scored
                        $subjects_scored = count($s_scores);
                    ?>
                        <div class="rc-wrap">
                            <div class="rc-card" id="reportCard">

                                <!-- Card header -->
                                <div class="rc-header">
                                    <img class="rc-logo" src="<?php echo htmlspecialchars($school_logo); ?>"
                                        alt="<?php echo htmlspecialchars($school_name); ?>"
                                        onerror="this.style.display='none'">
                                    <div class="rc-school-info">
                                        <h2><?php echo htmlspecialchars($school_name); ?></h2>
                                        <?php if ($school_email || $school_phone): ?>
                                            <p><?php echo htmlspecialchars($school_email); ?>
                                                <?php echo ($school_email && $school_phone) ? ' &bull; ' : ''; ?>
                                                <?php echo htmlspecialchars($school_phone); ?></p>
                                        <?php endif; ?>
                                        <?php if ($school_motto): ?>
                                            <p style="font-style:italic;color:var(--secondary)">
                                                "<?php echo htmlspecialchars($school_motto); ?>"</p>
                                        <?php endif; ?>
                                        <div class="rc-title">Student Report Card &mdash; <?php echo htmlspecialchars($term); ?> Term</div>
                                    </div>
                                    <?php if (!empty($preview_student['profile_picture'])): ?>
                                        <img class="rc-photo" src="<?php echo htmlspecialchars($preview_student['profile_picture']); ?>"
                                            alt="Photo" onerror="this.style.display='none'">
                                    <?php else: ?>
                                        <div class="rc-photo-placeholder"><i class="fas fa-user"></i></div>
                                    <?php endif; ?>
                                </div>

                                <!-- Bio strip -->
                                <div class="rc-bio">
                                    <div class="rc-bio-item">
                                        <span class="rc-bio-lbl">Student Name:</span>
                                        <span class="rc-bio-val"><?php echo htmlspecialchars($preview_student['full_name']); ?></span>
                                    </div>
                                    <div class="rc-bio-item">
                                        <span class="rc-bio-lbl">Admission No:</span>
                                        <span class="rc-bio-val"><?php echo htmlspecialchars($preview_student['admission_number']); ?></span>
                                    </div>
                                    <div class="rc-bio-item">
                                        <span class="rc-bio-lbl">Class:</span>
                                        <span class="rc-bio-val"><?php echo htmlspecialchars($class); ?></span>
                                    </div>
                                    <div class="rc-bio-item">
                                        <span class="rc-bio-lbl">Session:</span>
                                        <span class="rc-bio-val"><?php echo htmlspecialchars($session); ?></span>
                                    </div>
                                    <div class="rc-bio-item">
                                        <span class="rc-bio-lbl">Gender:</span>
                                        <span class="rc-bio-val"><?php echo htmlspecialchars(ucfirst($preview_student['gender'] ?? '')); ?></span>
                                    </div>
                                    <div class="rc-bio-item">
                                        <span class="rc-bio-lbl">Guardian:</span>
                                        <span class="rc-bio-val"><?php echo htmlspecialchars($preview_student['guardian_name'] ?? '—'); ?></span>
                                    </div>
                                    <?php if (!empty($record['current_resumption_date']) || !empty($record['current_closing_date'])): ?>
                                        <div class="rc-bio-item">
                                            <span class="rc-bio-lbl">Term opened:</span>
                                            <span class="rc-bio-val">
                                                <?php echo $record['current_resumption_date']
                                                    ? date('d M Y', strtotime($record['current_resumption_date'])) : '—'; ?>
                                            </span>
                                        </div>
                                        <div class="rc-bio-item">
                                            <span class="rc-bio-lbl">Term closed:</span>
                                            <span class="rc-bio-val">
                                                <?php echo $record['current_closing_date']
                                                    ? date('d M Y', strtotime($record['current_closing_date'])) : '—'; ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Scores table -->
                                <div class="rc-section-title"><i class="fas fa-list-alt"></i>&nbsp; Academic Performance</div>

                                <?php if (empty($s_scores)): ?>
                                    <div style="padding:20px;text-align:center;color:#999;font-size:.82rem;">
                                        <i class="fas fa-info-circle"></i> No scores recorded for this student yet.
                                    </div>
                                <?php else: ?>
                                    <table class="rc-table">
                                        <thead>
                                            <tr>
                                                <th style="width:28%">Subject</th>
                                                <?php foreach ($score_types as $st): ?>
                                                    <th><?php echo htmlspecialchars($st['label'] ?? $st['name'] ?? 'CA'); ?></th>
                                                <?php endforeach; ?>
                                                <th>Total</th>
                                                <?php if ((int)($record['show_lowest_highest_class'] ?? 1)): ?>
                                                    <th>Highest</th>
                                                    <th>Lowest</th>
                                                <?php endif; ?>
                                                <?php if ((int)($record['show_subject_position'] ?? 1)): ?>
                                                    <th>Position</th>
                                                <?php endif; ?>
                                                <th>Grade</th>
                                                <th>Remark</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $total_sum = 0;
                                            $scored_count = 0;
                                            foreach ($subjects as $sub):
                                                $sub_id   = (int)$sub['id'];
                                                $row      = $s_scores[$sub_id] ?? null;
                                                if (!$row) continue;
                                                $score_data = $row['score_data'];
                                                $total_sc   = (float)$row['total_score'];
                                                $grade_info = getGradeInfo($total_sc, $grading_scale);
                                                $grade      = $row['grade'] ?: $grade_info['grade'];
                                                $remark     = $grade_info['remark'];
                                                $g_cls      = strtolower(substr($grade, 0, 1));
                                                if (!in_array($g_cls, ['a', 'b', 'c', 'd'])) $g_cls = 'f';
                                                $sub_stat   = $subject_stats[$sub_id] ?? ['highest' => 0, 'lowest' => 0];
                                                $total_sum += $total_sc;
                                                $scored_count++;
                                            ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($sub['subject_name']); ?></td>
                                                    <?php foreach ($score_types as $st):
                                                        // Try both 'label' and 'name' keys, and build possible keys
                                                        $st_key = strtolower(str_replace([' ', '-'], '_', $st['label'] ?? $st['name'] ?? ''));
                                                        // Score might be stored with key: ca1, ca_1, ca 1, exam, etc.
                                                        $val = $score_data[$st_key] ?? $score_data[$st['label'] ?? ''] ?? $score_data[$st['name'] ?? ''] ?? '—';
                                                    ?>
                                                        <td><?php echo is_numeric($val) ? $val : '—'; ?></td>
                                                    <?php endforeach; ?>
                                                    <td class="rc-total"><?php echo number_format($total_sc, 0); ?></td>
                                                    <?php if ((int)($record['show_lowest_highest_class'] ?? 1)): ?>
                                                        <td><?php echo number_format($sub_stat['highest'], 0); ?></td>
                                                        <td><?php echo number_format($sub_stat['lowest'], 0); ?></td>
                                                    <?php endif; ?>
                                                    <?php if ((int)($record['show_subject_position'] ?? 1)): ?>
                                                        <td><?php echo $row['subject_position'] ? ordinal((int)$row['subject_position']) : '—'; ?></td>
                                                    <?php endif; ?>
                                                    <td><span class="g-badge g-<?php echo $g_cls; ?>"><?php echo htmlspecialchars($grade); ?></span></td>
                                                    <td><?php echo htmlspecialchars($remark); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>

                                    <!-- Summary row -->
                                    <div class="rc-summary-row">
                                        <div class="rc-sum-cell">
                                            <div class="val"><?php echo $scored_count; ?></div>
                                            <div class="lbl">Subjects offered</div>
                                        </div>
                                        <div class="rc-sum-cell">
                                            <div class="val"><?php echo number_format($total_sum, 0); ?></div>
                                            <div class="lbl">Total marks</div>
                                        </div>
                                        <div class="rc-sum-cell">
                                            <div class="val"><?php echo number_format($avg, 1); ?>%</div>
                                            <div class="lbl">Average</div>
                                        </div>
                                        <?php if ((int)($record['show_class_position'] ?? 1)): ?>
                                            <div class="rc-sum-cell">
                                                <div class="val"><?php echo $class_pos > 0 ? ordinal($class_pos) : '—'; ?></div>
                                                <div class="lbl">Class position</div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ((int)($record['show_lowest_highest_avg'] ?? 1)): ?>
                                            <div class="rc-sum-cell">
                                                <div class="val"><?php echo number_format($highest_avg, 1); ?>%</div>
                                                <div class="lbl">Highest in class</div>
                                            </div>
                                            <div class="rc-sum-cell">
                                                <div class="val"><?php echo number_format($lowest_avg, 1); ?>%</div>
                                                <div class="lbl">Lowest in class</div>
                                            </div>
                                        <?php endif; ?>
                                        <div class="rc-sum-cell">
                                            <div class="val"><?php echo $num_in_class; ?></div>
                                            <div class="lbl">No. in class</div>
                                        </div>
                                        <?php if ((int)($record['show_attendance'] ?? 1)): ?>
                                            <div class="rc-sum-cell">
                                                <div class="val"><?php echo $days_present; ?>/<?php echo $days_opened; ?></div>
                                                <div class="lbl">Days present</div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; /* scores */ ?>

                                <!-- Grading key -->
                                <div style="padding:6px 14px 4px;background:#f9f9f9;
                        border-top:1px solid #eee;border-bottom:1px solid #eee;
                        display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                                    <span style="font-size:.68rem;font-weight:600;color:#777;margin-right:4px;">GRADE KEY:</span>
                                    <?php foreach ($grading_scale as $g):
                                        $gc = strtolower(substr($g['grade'], 0, 1));
                                        if (!in_array($gc, ['a', 'b', 'c', 'd'])) $gc = 'f';
                                    ?>
                                        <span class="g-badge g-<?php echo $gc; ?>" style="font-size:.68rem;">
                                            <?php echo htmlspecialchars($g['grade']); ?>
                                            (<?php echo $g['min']; ?>–<?php echo $g['max']; ?>)
                                            <?php echo htmlspecialchars($g['remark']); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Affective traits -->
                                <?php if ((int)($record['show_affective_traits'] ?? 1) && !empty($affective_fields)): ?>
                                    <div class="rc-section-title"><i class="fas fa-heart"></i>&nbsp; Affective Traits</div>
                                    <div class="traits-grid">
                                        <div class="traits-section">
                                            <?php $af_keys = array_keys($affective_fields);
                                            $half = ceil(count($af_keys) / 2);
                                            foreach (array_slice($af_keys, 0, $half) as $fld):
                                                $val = $s_af[$fld] ?? null;
                                                $cls = $val ? 'tv-' . strtolower($val) : 'tv-null';
                                                $label = $val ? ($trait_labels[$val] ?? $val) : '—';
                                            ?>
                                                <div class="trait-row">
                                                    <span class="t-lbl"><?php echo htmlspecialchars($affective_fields[$fld]); ?></span>
                                                    <span class="trait-val <?php echo $cls; ?>"><?php echo $val ? "{$val} — {$label}" : '—'; ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="traits-section" style="border-right:none;">
                                            <?php foreach (array_slice($af_keys, $half) as $fld):
                                                $val = $s_af[$fld] ?? null;
                                                $cls = $val ? 'tv-' . strtolower($val) : 'tv-null';
                                                $label = $val ? ($trait_labels[$val] ?? $val) : '—';
                                            ?>
                                                <div class="trait-row">
                                                    <span class="t-lbl"><?php echo htmlspecialchars($affective_fields[$fld]); ?></span>
                                                    <span class="trait-val <?php echo $cls; ?>"><?php echo $val ? "{$val} — {$label}" : '—'; ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Psychomotor skills -->
                                <?php if ((int)($record['show_psychomotor'] ?? 1) && !empty($psychomotor_fields)): ?>
                                    <div class="rc-section-title"><i class="fas fa-hand-paper"></i>&nbsp; Psychomotor Skills</div>
                                    <div class="traits-grid">
                                        <div class="traits-section">
                                            <?php $pm_keys = array_keys($psychomotor_fields);
                                            $half = ceil(count($pm_keys) / 2);
                                            foreach (array_slice($pm_keys, 0, $half) as $fld):
                                                $val = $s_pm[$fld] ?? null;
                                                $cls = $val ? 'tv-' . strtolower($val) : 'tv-null';
                                                $label = $val ? ($trait_labels[$val] ?? $val) : '—';
                                            ?>
                                                <div class="trait-row">
                                                    <span class="t-lbl"><?php echo htmlspecialchars($psychomotor_fields[$fld]); ?></span>
                                                    <span class="trait-val <?php echo $cls; ?>"><?php echo $val ? "{$val} — {$label}" : '—'; ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="traits-section" style="border-right:none;">
                                            <?php foreach (array_slice($pm_keys, $half) as $fld):
                                                $val = $s_pm[$fld] ?? null;
                                                $cls = $val ? 'tv-' . strtolower($val) : 'tv-null';
                                                $label = $val ? ($trait_labels[$val] ?? $val) : '—';
                                            ?>
                                                <div class="trait-row">
                                                    <span class="t-lbl"><?php echo htmlspecialchars($psychomotor_fields[$fld]); ?></span>
                                                    <span class="trait-val <?php echo $cls; ?>"><?php echo $val ? "{$val} — {$label}" : '—'; ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Comments -->
                                <div class="rc-section-title"><i class="fas fa-comment-dots"></i>&nbsp; Comments</div>
                                <div class="rc-comments">
                                    <div class="rc-comment-box">
                                        <div class="c-lbl"><i class="fas fa-chalkboard-teacher"></i> Class Teacher's Comment</div>
                                        <div class="c-text">
                                            <?php echo htmlspecialchars($s_comm['teachers_comment'] ?? '—'); ?>
                                        </div>
                                        <div class="c-sig">
                                            <?php if (!empty($s_comm['class_teachers_name'])): ?>
                                                Signed: <strong><?php echo htmlspecialchars($s_comm['class_teachers_name']); ?></strong>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="rc-comment-box" style="border-right:none;">
                                        <div class="c-lbl"><i class="fas fa-user-tie"></i> Principal's Comment</div>
                                        <div class="c-text">
                                            <?php echo htmlspecialchars($s_comm['principals_comment'] ?? '—'); ?>
                                        </div>
                                        <div class="c-sig">
                                            <?php if (!empty($s_comm['principals_name'])): ?>
                                                Signed: <strong><?php echo htmlspecialchars($s_comm['principals_name']); ?></strong>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Promoted / next resumption -->
                                <?php if ((int)($record['show_promoted_to'] ?? 1) && $promoted_to): ?>
                                    <div style="padding:8px 16px;background:#e8f5e9;border-top:1px solid #c8e6c9;
                        font-size:.78rem;display:flex;gap:20px;flex-wrap:wrap;align-items:center;">
                                        <span><i class="fas fa-arrow-circle-up" style="color:var(--success)"></i>
                                            <strong style="color:var(--success)">Promoted to:</strong>
                                            <?php echo htmlspecialchars($promoted_to); ?></span>
                                        <?php if (!empty($record['next_resumption_date'])): ?>
                                            <span><i class="fas fa-calendar" style="color:#555"></i>
                                                <strong>Next resumption:</strong>
                                                <?php echo date('d M Y', strtotime($record['next_resumption_date'])); ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Card footer -->
                                <div class="rc-footer">
                                    <span>
                                        <?php echo htmlspecialchars($school_name); ?> &mdash;
                                        <?php echo htmlspecialchars($term); ?> Term,
                                        <?php echo htmlspecialchars($session); ?>
                                    </span>
                                    <span>Generated: <?php echo date('d M Y'); ?></span>
                                    <span>
                                        <strong>Status:</strong>
                                        <?php echo ucfirst($record['status'] ?? 'draft'); ?>
                                    </span>
                                </div>

                            </div><!-- /rc-card -->
                        </div><!-- /rc-wrap -->
                    <?php else: ?>
                        <div style="padding:60px 20px;text-align:center;color:#999;">
                            <i class="fas fa-id-card" style="font-size:3rem;opacity:.3;margin-bottom:14px;display:block"></i>
                            <p>Select a student from the list to preview their report card.</p>
                        </div>
                    <?php endif; ?>
                </div><!-- /card-wrapper -->

            </div><!-- /layout-grid -->

        <?php endif; /* students */ ?>

        <div style="text-align:center;padding:20px;color:#999;font-size:.8rem;
        border-top:1px solid var(--light);margin-top:20px" class="no-print">
            &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> — Online Portal
        </div>
    </main>

    <script>
        // ── Sidebar ───────────────────────────────────────────────────────────────
        const sb = document.getElementById('sidebar');
        const ov = document.getElementById('overlay');
        const btn = document.getElementById('menuBtn');
        btn.addEventListener('click', () => {
            sb.classList.toggle('open');
            ov.classList.toggle('show');
            document.body.style.overflow = sb.classList.contains('open') ? 'hidden' : '';
        });
        ov.addEventListener('click', () => {
            sb.classList.remove('open');
            ov.classList.remove('show');
            document.body.style.overflow = '';
        });

        // ── Student search filter ─────────────────────────────────────────────────
        function filterStudents() {
            const q = document.getElementById('studentSearch').value.toLowerCase().trim();
            const lis = document.querySelectorAll('#studentList li');
            lis.forEach(li => {
                const name = li.dataset.name || '';
                li.style.display = name.includes(q) ? '' : 'none';
            });
        }
        // ── PDF Download (single-page, auto-scaled) ────────────────────────────
        async function downloadReportCardPDF() {
                const card = document.getElementById('reportCard');
                if (!card) return;

                // Buttons to hide during capture
                const btns = document.querySelectorAll('.no-print, .card-toolbar');
                btns.forEach(el => el.style.setProperty('display', 'none', 'important'));

                const pdfBtns = document.querySelectorAll('[onclick*="downloadReportCardPDF"]');
                pdfBtns.forEach(b => {
                    b._oldDisplay = b.style.display;
                    b.style.display = 'none';
                });

                try {
                    // Capture at 2x for sharp output
                    const canvas = await html2canvas(card, {
                        scale: 2,
                        useCORS: true,
                        allowTaint: true,
                        backgroundColor: '#ffffff',
                        logging: false,
                    });

                    const {
                        jsPDF
                    } = window.jspdf;
                    // A4 dimensions in mm
                    const pageW = 210;
                    const pageH = 297;
                    const marginMm = 8;
                    const printW = pageW - marginMm * 2;
                    const printH = pageH - marginMm * 2;

                    const imgW = canvas.width;
                    const imgH = canvas.height;

                    // Scale so image fits entirely within the printable area
                    const scaleX = printW / (imgW / 3.7795); // px → mm at 96dpi
                    const scaleY = printH / (imgH / 3.7795);
                    const scale = Math.min(scaleX, scaleY, 1); // never upscale

                    const finalW = (imgW / 3.7795) * scale;
                    const finalH = (imgH / 3.7795) * scale;

                    // Centre on page
                    const offsetX = marginMm + (printW - finalW) / 2;
                    const offsetY = marginMm + (printH - finalH) / 2;

                    const pdf = new jsPDF({
                        orientation: 'portrait',
                        unit: 'mm',
                        format: 'a4'
                    });
                    pdf.addImage(canvas.toDataURL('image/png'), 'PNG', offsetX, offsetY, finalW, finalH);

                    // Derive filename from student name if available
                    const studentName = card.closest('.rc-wrap')
                        ?.querySelector('.rc-bio-val')?.textContent?.trim()
                        ?.replace(/[^a-z0-9_\-]/gi, '_') || 'report_card';
                    pdf.save(`${studentName}_report_card.pdf`);
                } finally {
                    btns.forEach(el => el.style.removeProperty('display'));
                    pdfBtns.forEach(b => {
                        b.style.display = b._oldDisplay || '';
                    });
                }
            }



            <
            /html>
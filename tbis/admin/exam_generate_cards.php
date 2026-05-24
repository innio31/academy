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

// ── Compute class-level stats ────────────────────────────────────────────────
$class_averages = [];
foreach ($students as $s) {
    $sid = (int)$s['id'];
    $class_averages[$sid] = isset($positions[$sid]['average'])
        ? (float)$positions[$sid]['average'] : 0.0;
}
$highest_avg  = !empty($class_averages) ? max($class_averages) : 0;
$lowest_avg   = !empty($class_averages) ? min($class_averages) : 0;
$num_in_class = $total_students;

// ── Compute class-level subject stats ─────────────────────────────────────────
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
    'relationship' => 'Relationship',
    'self_control' => 'Self Control',
];
$psychomotor_fields = [
    'handwriting'     => 'Handwriting',
    'verbal_fluency'  => 'Verbal Fluency',
    'sports'          => 'Sports',
    'handling_tools'  => 'Handling tools',
    'drawing_painting' => 'Drawing/Painting',
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

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

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            color: #333;
            min-height: 100vh;
        }

        /* ── Sidebar (unchanged, truncated for brevity) ── */
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
            transform: translateX(-100%);
        }

        .sidebar.open {
            transform: translateX(0);
        }

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

        .main {
            min-height: 100vh;
            padding: 20px;
            transition: var(--transition);
        }

        .top-header,
        .step-bar,
        .stats-row,
        .ready-bar,
        .publish-bar {
            background: white;
            border-radius: var(--radius);
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
        }

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

        /* ── COMPACT REPORT CARD STYLES (OPTIMIZED FOR SINGLE PAGE) ── */
        .rc-wrap {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .rc-card {
            font-family: 'Poppins', sans-serif;
            font-size: 0.72rem;
            line-height: 1.3;
            color: #1a1a1a;
        }

        /* Header - compact */
        .rc-header {
            padding: 10px 16px 8px;
            border-bottom: 2px solid var(--secondary);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .rc-logo {
            width: 48px;
            height: 48px;
            object-fit: contain;
            flex-shrink: 0;
        }

        .rc-school-info {
            flex: 1;
            text-align: center;
        }

        .rc-school-info h2 {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
        }

        .rc-school-info p {
            font-size: 0.65rem;
            margin: 0;
            color: #555;
        }

        .rc-school-info .rc-title {
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 3px;
        }

        .rc-photo,
        .rc-photo-placeholder {
            width: 48px;
            height: 48px;
            object-fit: cover;
            border-radius: 4px;
            flex-shrink: 0;
        }

        .rc-photo-placeholder {
            background: var(--light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        /* Bio strip - 2 rows max */
        .rc-bio {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0;
            border-bottom: 1px solid #ddd;
            font-size: 0.68rem;
        }

        .rc-bio-item {
            padding: 4px 12px;
            display: flex;
            gap: 6px;
            border-right: 1px solid #f0f0f0;
            border-bottom: 1px solid #f0f0f0;
        }

        .rc-bio-item:nth-child(4n) {
            border-right: none;
        }

        .rc-bio-lbl {
            color: #777;
            font-weight: 500;
            min-width: 70px;
        }

        .rc-bio-val {
            color: #222;
            font-weight: 600;
        }

        /* Section titles - compact */
        .rc-section-title {
            background: var(--primary);
            color: white;
            padding: 3px 12px;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        /* Score table - compact */
        .rc-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.68rem;
        }

        .rc-table th {
            background: #f0f4ff;
            padding: 4px 6px;
            text-align: center;
            border: 1px solid #e0e0e0;
            font-weight: 600;
        }

        .rc-table th:first-child {
            text-align: left;
        }

        .rc-table td {
            padding: 4px 6px;
            border: 1px solid #e8e8e8;
            text-align: center;
        }

        .rc-table td:first-child {
            text-align: left;
            font-weight: 500;
        }

        .rc-table .rc-total {
            font-weight: 700;
            color: var(--primary);
        }

        /* Grade badges - smaller */
        .g-badge {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 8px;
            font-size: 0.65rem;
            font-weight: 600;
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

        /* Summary row - compact */
        .rc-summary-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(70px, 1fr));
            border-top: 1px solid var(--secondary);
            background: #fafbfe;
        }

        .rc-sum-cell {
            padding: 4px 8px;
            text-align: center;
            border-right: 1px solid #e0e0e0;
        }

        .rc-sum-cell:last-child {
            border-right: none;
        }

        .rc-sum-cell .val {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--primary);
        }

        .rc-sum-cell .lbl {
            font-size: 0.6rem;
            color: #777;
        }

        /* Traits - COMPACT 2-COLUMN layout */
        .traits-compact {
            display: flex;
            flex-wrap: wrap;
            border-bottom: 1px solid #e0e0e0;
        }

        .trait-col {
            flex: 1;
            min-width: 180px;
        }

        .trait-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 3px 12px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 0.68rem;
        }

        .trait-val {
            padding: 1px 6px;
            border-radius: 8px;
            font-size: 0.6rem;
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

        /* Comments - compact side by side */
        .rc-comments-compact {
            display: flex;
            border-bottom: 1px solid #e0e0e0;
        }

        .rc-comment-box {
            flex: 1;
            padding: 6px 12px;
            border-right: 1px solid #e0e0e0;
        }

        .rc-comment-box:last-child {
            border-right: none;
        }

        .rc-comment-box .c-lbl {
            font-size: 0.6rem;
            color: var(--primary);
            font-weight: 600;
            margin-bottom: 2px;
        }

        .rc-comment-box .c-text {
            font-size: 0.65rem;
            line-height: 1.3;
            max-height: 45px;
            overflow: hidden;
        }

        .rc-comment-box .c-sig {
            font-size: 0.58rem;
            color: #888;
            margin-top: 3px;
            border-top: 1px dashed #ddd;
            padding-top: 2px;
        }

        /* Footer - compact */
        .rc-footer {
            background: linear-gradient(90deg, var(--primary), var(--dark));
            color: white;
            padding: 5px 16px;
            display: flex;
            justify-content: space-between;
            font-size: 0.6rem;
        }

        /* Grading key - inline compact */
        .grade-key {
            padding: 3px 12px;
            background: #f9f9f9;
            border-top: 1px solid #eee;
            border-bottom: 1px solid #eee;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            font-size: 0.6rem;
        }

        /* PRINT STYLES - FORCED SINGLE PAGE */
        @media print {
            @page {
                size: A4 portrait;
                margin: 5mm;
            }

            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            body,
            .main {
                background: white;
                margin: 0;
                padding: 0;
            }

            .no-print,
            .sidebar,
            .mobile-toggle,
            .overlay,
            .top-header,
            .step-bar,
            .stats-row,
            .ready-bar,
            .publish-bar,
            .student-panel,
            .card-toolbar,
            button,
            .back-btn,
            .layout-grid .student-panel {
                display: none !important;
            }

            .layout-grid {
                display: block !important;
            }

            .rc-wrap {
                box-shadow: none !important;
                padding: 0 !important;
                margin: 0 auto !important;
            }

            .rc-card {
                max-width: 100% !important;
                page-break-after: avoid;
                page-break-inside: avoid;
                break-inside: avoid;
            }
        }

        /* Student panel styles (truncated) */
        .student-panel {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .panel-head {
            padding: 12px 16px;
            background: var(--primary);
            color: white;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .student-list {
            list-style: none;
            max-height: 500px;
            overflow-y: auto;
        }

        .student-list li a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            text-decoration: none;
            color: #333;
            border-bottom: 1px solid #f0f0f0;
            font-size: 0.8rem;
        }

        .student-list li a.active {
            background: #eef2ff;
            border-left: 3px solid var(--primary);
        }

        .s-badge {
            font-size: 0.6rem;
            padding: 2px 6px;
            border-radius: 10px;
            background: #e8f5e9;
            color: #2e7d32;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-secondary {
            background: white;
            color: var(--primary);
            border: 1px solid var(--primary);
        }

        .alert {
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
        }
    </style>
</head>

<body>

    <div class="overlay" id="overlay"></div>
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-header" style="padding:0 20px 15px;">
            <div class="logo">
                <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
                <div class="logo-text">
                    <h3><?php echo htmlspecialchars($school_name); ?></h3>
                    <p>Admin Portal</p>
                </div>
            </div>
        </div>
        <ul class="nav-links" style="list-style:none;padding:0 15px;">
            <li><a href="index.php"><i class="fas fa-home"></i>Dashboard</a></li>
            <li><a href="students.php"><i class="fas fa-user-graduate"></i>Students</a></li>
            <li><a href="exam_record_setup.php"><i class="fas fa-file-alt"></i>Exam Records</a></li>
            <li><a href="exam_generate_cards.php?record_id=<?php echo $record_id; ?>" class="active"><i class="fas fa-id-card"></i>Report Cards</a></li>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i>Logout</a></li>
        </ul>
    </nav>

    <button class="mobile-toggle" id="menuBtn" aria-label="Menu">
        <i class="fas fa-bars"></i>
    </button>

    <main class="main">
        <div class="top-header no-print">
            <div>
                <h1 style="font-size:1.2rem;"><i class="fas fa-id-card" style="color:var(--secondary);margin-right:8px"></i>Generate Report Cards</h1>
                <p style="font-size:0.75rem;"><?php echo htmlspecialchars($record['record_name'] ?? "{$class} — {$term} Term {$session}"); ?></p>
            </div>
            <a href="exam_traits_comments.php?record_id=<?php echo $record_id; ?>" class="back-btn" style="text-decoration:none;padding:6px 12px;background:#eee;border-radius:6px;">← Back to Step 3</a>
        </div>

        <?php if ($success_msg): ?>
            <div class="alert alert-success no-print"><?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-danger no-print"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <?php if (empty($students)): ?>
            <div class="alert alert-warning">No active students found for <?php echo htmlspecialchars($class); ?>.</div>
        <?php else: ?>

            <!-- Publish bar -->
            <div class="publish-bar no-print" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;">
                <div>
                    <strong><?php echo htmlspecialchars($record['record_name'] ?? "{$class} — {$term} Term"); ?></strong>
                    <span style="margin-left:8px;font-size:0.7rem;"><?php echo htmlspecialchars($session); ?> • <?php echo htmlspecialchars($class); ?></span>
                </div>
                <div style="display:flex;gap:8px;">
                    <?php if (($record['status'] ?? '') !== 'published'): ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="publish_record">
                            <button type="submit" class="btn btn-primary btn-sm" <?php echo !$all_ready ? 'disabled' : ''; ?>>Publish</button>
                        </form>
                    <?php else: ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="unpublish_record">
                            <button type="submit" class="btn btn-secondary btn-sm">Unpublish</button>
                        </form>
                    <?php endif; ?>
                    <button class="btn btn-secondary btn-sm" onclick="window.print()">Print</button>
                    <button class="btn btn-primary btn-sm" onclick="downloadReportCardPDF()">Download PDF</button>
                </div>
            </div>

            <div class="layout-grid">
                <!-- Student list -->
                <div class="student-panel no-print">
                    <div class="panel-head">Students (<?php echo $total_students; ?>)</div>
                    <ul class="student-list" id="studentList">
                        <?php foreach ($students as $s):
                            $sid = (int)$s['id'];
                            $has_scores = !empty($scores[$sid]);
                            $has_comm = !empty($comments[$sid]);
                            $pos = $positions[$sid] ?? null;
                        ?>
                            <li>
                                <a href="?record_id=<?php echo $record_id; ?>&student_id=<?php echo $sid; ?>"
                                    class="<?php echo ($sid === $preview_sid) ? 'active' : ''; ?>">
                                    <div class="s-avatar" style="width:30px;height:30px;background:#eee;border-radius:50%;display:flex;align-items:center;justify-content:center;">
                                        <?php echo strtoupper(substr($s['full_name'], 0, 1)); ?>
                                    </div>
                                    <div style="flex:1;">
                                        <strong style="font-size:0.75rem;"><?php echo htmlspecialchars($s['full_name']); ?></strong>
                                        <span style="font-size:0.65rem;color:#888;display:block;"><?php echo htmlspecialchars($s['admission_number']); ?></span>
                                    </div>
                                    <?php if ($has_scores && $has_comm): ?>
                                        <span class="s-badge">✓</span>
                                    <?php else: ?>
                                        <span class="s-badge" style="background:#fce4ec;color:#c62828;">!</span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Report Card Preview -->
                <div class="rc-wrap">
                    <?php if ($preview_student):
                        $sid = (int)$preview_student['id'];
                        $s_scores = $scores[$sid] ?? [];
                        $s_pos = $positions[$sid] ?? [];
                        $s_comm = $comments[$sid] ?? [];
                        $s_af = $affective[$sid] ?? [];
                        $s_pm = $psychomotor[$sid] ?? [];
                        $avg = (float)($s_pos['average'] ?? 0);
                        $class_pos = (int)($s_pos['class_position'] ?? 0);
                        $days_opened = (int)($record['days_school_opened'] ?? 90);
                        $days_present = (int)($s_comm['days_present'] ?? 0);
                    ?>
                        <div class="rc-card" id="reportCard">
                            <!-- Header -->
                            <div class="rc-header">
                                <img class="rc-logo" src="<?php echo htmlspecialchars($school_logo); ?>" alt="Logo" onerror="this.style.display='none'">
                                <div class="rc-school-info">
                                    <h2><?php echo htmlspecialchars($school_name); ?></h2>
                                    <?php if ($school_motto): ?>
                                        <p style="font-style:italic;">"<?php echo htmlspecialchars($school_motto); ?>"</p>
                                    <?php endif; ?>
                                    <div class="rc-title">REPORT CARD — <?php echo htmlspecialchars($term); ?> TERM</div>
                                </div>
                                <?php if (!empty($preview_student['profile_picture'])): ?>
                                    <img class="rc-photo" src="<?php echo htmlspecialchars($preview_student['profile_picture']); ?>" alt="Photo">
                                <?php else: ?>
                                    <div class="rc-photo-placeholder"><i class="fas fa-user"></i></div>
                                <?php endif; ?>
                            </div>

                            <!-- Bio (compact 4-col) -->
                            <div class="rc-bio">
                                <div class="rc-bio-item"><span class="rc-bio-lbl">Name:</span><span class="rc-bio-val"><?php echo htmlspecialchars($preview_student['full_name']); ?></span></div>
                                <div class="rc-bio-item"><span class="rc-bio-lbl">Admission:</span><span class="rc-bio-val"><?php echo htmlspecialchars($preview_student['admission_number']); ?></span></div>
                                <div class="rc-bio-item"><span class="rc-bio-lbl">Class:</span><span class="rc-bio-val"><?php echo htmlspecialchars($class); ?></span></div>
                                <div class="rc-bio-item"><span class="rc-bio-lbl">Session:</span><span class="rc-bio-val"><?php echo htmlspecialchars($session); ?></span></div>
                                <div class="rc-bio-item"><span class="rc-bio-lbl">Gender:</span><span class="rc-bio-val"><?php echo ucfirst($preview_student['gender'] ?? ''); ?></span></div>
                                <div class="rc-bio-item"><span class="rc-bio-lbl">Guardian:</span><span class="rc-bio-val"><?php echo htmlspecialchars($preview_student['guardian_name'] ?? '—'); ?></span></div>
                                <?php if ($days_opened && (int)($record['show_attendance'] ?? 1)): ?>
                                    <div class="rc-bio-item"><span class="rc-bio-lbl">Attendance:</span><span class="rc-bio-val"><?php echo $days_present; ?>/<?php echo $days_opened; ?> days</span></div>
                                <?php endif; ?>
                                <div class="rc-bio-item"><span class="rc-bio-lbl">Position:</span><span class="rc-bio-val"><?php echo $class_pos ? ordinal($class_pos) : '—'; ?></span></div>
                            </div>

                            <!-- Academic Performance -->
                            <div class="rc-section-title">📊 ACADEMIC PERFORMANCE</div>
                            <?php if (empty($s_scores)): ?>
                                <div style="padding:15px;text-align:center;">No scores recorded.</div>
                            <?php else: ?>
                                <table class="rc-table">
                                    <thead>
                                        <tr>
                                            <th>Subject</th><?php foreach ($score_types as $st): ?><th><?php echo htmlspecialchars($st['label'] ?? $st['name'] ?? 'CA'); ?></th><?php endforeach; ?><th>Total</th>
                                            <th>Grade</th>
                                            <th>Remark</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $total_sum = 0;
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
                                        ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($sub['subject_name']); ?></td>
                                                <?php foreach ($score_types as $st):
                                                    $st_key = strtolower(str_replace([' ', '-'], '_', $st['label'] ?? $st['name'] ?? ''));
                                                    $val = $row['score_data'][$st_key] ?? $row['score_data'][$st['label'] ?? ''] ?? '—';
                                                ?>
                                                    <td><?php echo is_numeric($val) ? $val : '—'; ?></td>
                                                <?php endforeach; ?>
                                                <td class="rc-total"><?php echo number_format($total_sc, 0); ?></td>
                                                <td><span class="g-badge g-<?php echo $g_cls; ?>"><?php echo $grade_info['grade']; ?></span></td>
                                                <td><?php echo $grade_info['remark']; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>

                                <!-- Summary -->
                                <div class="rc-summary-row">
                                    <div class="rc-sum-cell">
                                        <div class="val"><?php echo $scored_count; ?></div>
                                        <div class="lbl">Subjects</div>
                                    </div>
                                    <div class="rc-sum-cell">
                                        <div class="val"><?php echo number_format($total_sum, 0); ?></div>
                                        <div class="lbl">Total</div>
                                    </div>
                                    <div class="rc-sum-cell">
                                        <div class="val"><?php echo number_format($avg, 1); ?>%</div>
                                        <div class="lbl">Average</div>
                                    </div>
                                    <?php if ((int)($record['show_lowest_highest_avg'] ?? 1)): ?>
                                        <div class="rc-sum-cell">
                                            <div class="val"><?php echo number_format($highest_avg, 1); ?>%</div>
                                            <div class="lbl">Highest</div>
                                        </div>
                                        <div class="rc-sum-cell">
                                            <div class="val"><?php echo number_format($lowest_avg, 1); ?>%</div>
                                            <div class="lbl">Lowest</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Grading Key -->
                            <div class="grade-key">
                                <strong>GRADE KEY:</strong>
                                <?php foreach ($grading_scale as $g):
                                    $gc = strtolower(substr($g['grade'], 0, 1));
                                ?>
                                    <span class="g-badge g-<?php echo $gc; ?>"><?php echo $g['grade']; ?> (<?php echo $g['min']; ?>-<?php echo $g['max']; ?>)</span>
                                <?php endforeach; ?>
                            </div>

                            <!-- Affective Traits & Psychomotor (side by side) -->
                            <?php if ((int)($record['show_affective_traits'] ?? 1) && !empty($affective_fields)): ?>
                                <div class="rc-section-title">🌟 AFFECTIVE TRAITS</div>
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

                            <?php if ((int)($record['show_psychomotor'] ?? 1) && !empty($psychomotor_fields)): ?>
                                <div class="rc-section-title">🎨 PSYCHOMOTOR SKILLS</div>
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
                            <div class="rc-section-title">💬 COMMENTS</div>
                            <div class="rc-comments-compact">
                                <div class="rc-comment-box">
                                    <div class="c-lbl">📝 Class Teacher</div>
                                    <div class="c-text"><?php echo htmlspecialchars($s_comm['teachers_comment'] ?? '—'); ?></div>
                                    <div class="c-sig"><?php echo htmlspecialchars($s_comm['class_teachers_name'] ?? ''); ?></div>
                                </div>
                                <div class="rc-comment-box">
                                    <div class="c-lbl">👔 Principal</div>
                                    <div class="c-text"><?php echo htmlspecialchars($s_comm['principals_comment'] ?? '—'); ?></div>
                                    <div class="c-sig"><?php echo htmlspecialchars($s_comm['principals_name'] ?? ''); ?></div>
                                </div>
                            </div>

                            <!-- Footer -->
                            <div class="rc-footer">
                                <span><?php echo htmlspecialchars($school_name); ?> — <?php echo htmlspecialchars($session); ?></span>
                                <span>Generated: <?php echo date('d M Y'); ?></span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="padding:40px;text-align:center;">Select a student from the list</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <script>
        // Sidebar toggle
        const sb = document.getElementById('sidebar');
        const ov = document.getElementById('overlay');
        const btn = document.getElementById('menuBtn');
        btn.addEventListener('click', () => {
            sb.classList.toggle('open');
            ov.classList.toggle('show');
        });
        ov.addEventListener('click', () => {
            sb.classList.remove('open');
            ov.classList.remove('show');
        });

        // PDF Download - FIXED for single page
        async function downloadReportCardPDF() {
            const card = document.getElementById('reportCard');
            if (!card) {
                alert('No report card found.');
                return;
            }

            const btn = event ? event.target.closest('button') : document.querySelector('.btn-primary');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
            }

            // Hide non-print elements
            const hideEls = document.querySelectorAll('.no-print, .card-toolbar, .sidebar, .mobile-toggle, .overlay, .top-header, .step-bar, .stats-row, .ready-bar, .publish-bar, .student-panel, .layout-grid > .student-panel');
            const originalDisplays = [];
            hideEls.forEach((el, i) => {
                originalDisplays[i] = el.style.display;
                el.style.display = 'none';
            });

            // Temporarily adjust card styles for better PDF fit
            const cardOriginalWidth = card.style.width;
            const cardOriginalMargin = card.style.margin;
            card.style.width = '100%';
            card.style.margin = '0 auto';

            try {
                const canvas = await html2canvas(card, {
                    scale: 2.5,
                    useCORS: true,
                    backgroundColor: '#ffffff',
                    logging: false,
                    windowWidth: card.scrollWidth,
                    windowHeight: card.scrollHeight
                });

                const imgData = canvas.toDataURL('image/jpeg', 0.95);

                // A4 dimensions in mm
                const pdf = new jspdf.jsPDF({
                    orientation: 'portrait',
                    unit: 'mm',
                    format: 'a4'
                });

                const pdfWidth = pdf.internal.pageSize.getWidth();
                const pdfHeight = pdf.internal.pageSize.getHeight();

                const imgWidth = pdfWidth - 10; // 5mm margins on each side
                const imgHeight = (canvas.height * imgWidth) / canvas.width;

                // Center vertically if needed
                let yOffset = 0;
                if (imgHeight < pdfHeight - 10) {
                    yOffset = (pdfHeight - imgHeight) / 2;
                }

                pdf.addImage(imgData, 'JPEG', 5, yOffset, imgWidth, imgHeight);

                // Get student name for filename
                const nameEl = card.querySelector('.rc-bio-val');
                const studentName = nameEl ? nameEl.textContent.trim().replace(/[^a-z0-9]/gi, '_').substring(0, 30) : 'report_card';

                pdf.save(`${studentName}_report_card.pdf`);

            } catch (err) {
                console.error('PDF error:', err);
                alert('PDF generation failed: ' + err.message);
            } finally {
                // Restore displays
                hideEls.forEach((el, i) => {
                    el.style.display = originalDisplays[i];
                });
                card.style.width = cardOriginalWidth;
                card.style.margin = cardOriginalMargin;

                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = 'Download PDF';
                }
            }
        }
    </script>
</body>

</html>
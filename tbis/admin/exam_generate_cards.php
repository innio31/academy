<?php
// tbis/admin/exam_generate_cards.php — Step 4: Generate & View Report Cards (Single Page Optimized)
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
        ['grade' => 'A', 'min' => 75,  'max' => 100, 'remark' => 'Excel'],
        ['grade' => 'B', 'min' => 65,  'max' => 74,  'remark' => 'V.Good'],
        ['grade' => 'C', 'min' => 50,  'max' => 64,  'remark' => 'Good'],
        ['grade' => 'D', 'min' => 40,  'max' => 49,  'remark' => 'Pass'],
        ['grade' => 'F', 'min' => 0,   'max' => 39,  'remark' => 'Fail'],
    ];
}

function getGradeInfo(float $total, array $scale): array
{
    foreach ($scale as $row) {
        if ($total >= (float)$row['min'] && $total <= (float)$row['max'])
            return ['grade' => $row['grade'], 'remark' => $row['remark']];
    }
    return ['grade' => 'F', 'remark' => 'Fail'];
}

function ordinal(int $n): string
{
    if ($n <= 0) return '-';
    $sfx = ['th', 'st', 'nd', 'rd'];
    $v   = $n % 100;
    return $n . ($sfx[($v - 20) % 10] ?? $sfx[min($v, 3)]);
}

// ── Load school info ──────────────────────────────────────────────────────────
$school_logo = defined('SCHOOL_LOGO') ? SCHOOL_LOGO : '/assets/logos/default.png';
$school_motto = '';
$school_email = '';
$school_phone = '';
try {
    $stmt = $pdo->prepare("SELECT motto, contact_email, contact_phone FROM schools WHERE id = ? LIMIT 1");
    $stmt->execute([$school_id]);
    $info = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($info) {
        $school_motto = $info['motto'] ?? '';
        $school_email = $info['contact_email'] ?? '';
        $school_phone = $info['contact_phone'] ?? '';
    }
} catch (Exception $e) { /* non-fatal */
}

// ── Load students ─────────────────────────────────────────────────────────────
$students = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, full_name, admission_number, gender, guardian_name, profile_picture
          FROM students
         WHERE school_id = ? AND class = ? AND status = 'active'
         ORDER BY full_name ASC
    ");
    $stmt->execute([$school_id, $class]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("students: " . $e->getMessage());
}
$total_students = count($students);

// ── Load subjects ─────────────────────────────────────────────────────────────
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
    error_log("subjects: " . $e->getMessage());
}

// ── Load scores ───────────────────────────────────────────────────────────────
$scores = [];
if (!empty($students) && !empty($subjects)) {
    try {
        $sub_ids = array_column($subjects, 'id');
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
    } catch (Exception $e) {
        error_log("scores: " . $e->getMessage());
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
        error_log("positions: " . $e->getMessage());
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
        error_log("comments: " . $e->getMessage());
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
        error_log("affective: " . $e->getMessage());
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
        error_log("psychomotor: " . $e->getMessage());
    }
}

// ── Compute stats ─────────────────────────────────────────────────────────────
$class_averages = [];
foreach ($students as $s) {
    $sid = (int)$s['id'];
    $class_averages[$sid] = isset($positions[$sid]['average']) ? (float)$positions[$sid]['average'] : 0;
}
$highest_avg = !empty($class_averages) ? max($class_averages) : 0;
$lowest_avg  = !empty($class_averages) ? min($class_averages) : 0;
$num_in_class = $total_students;

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

// ── Preview student ───────────────────────────────────────────────────────────
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

$students_with_scores = 0;
$students_with_comments = 0;
foreach ($students as $s) {
    $sid = (int)$s['id'];
    if (!empty($scores[$sid])) $students_with_scores++;
    if (!empty($comments[$sid])) $students_with_comments++;
}

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
    'handling_tools' => 'Handling Tools',
    'drawing_painting' => 'Drawing/Painting',
    'musical_skills' => 'Musical Skills',
];
$trait_labels = ['A' => 'Excel', 'B' => 'V.Good', 'C' => 'Good', 'D' => 'Fair', 'E' => 'Poor'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($school_name); ?> — Report Cards</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #e8ecf1;
            padding: 20px;
        }

        /* Main Container */
        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        /* Controls Bar */
        .controls {
            background: white;
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .student-selector {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .student-selector label {
            font-weight: 500;
            font-size: 0.85rem;
        }

        .student-selector select {
            padding: 8px 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.85rem;
            min-width: 200px;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-primary {
            background: <?php echo $primary_color; ?>;
            color: white;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-warning {
            background: #ffc107;
            color: #333;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.75rem;
        }

        .alert {
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 0.8rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 3px solid #28a745;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left: 3px solid #dc3545;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 3px solid #ffc107;
        }

        /* === REPORT CARD - COMPACT SINGLE PAGE === */
        .report-card {
            background: white;
            max-width: 800px;
            margin: 0 auto;
            border-radius: 4px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            font-size: 11px;
        }

        /* Header Section - Compact */
        .rc-header {
            padding: 12px 16px 8px;
            text-align: center;
            border-bottom: 2px solid <?php echo $secondary_color; ?>;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .rc-logo {
            width: 55px;
            height: 55px;
            object-fit: contain;
        }

        .rc-title {
            flex: 1;
        }

        .rc-title h2 {
            font-size: 1rem;
            margin-bottom: 2px;
            color: <?php echo $primary_color; ?>;
        }

        .rc-title .motto {
            font-size: 0.7rem;
            color: #666;
            font-style: italic;
        }

        .rc-title .report-name {
            font-size: 0.8rem;
            font-weight: 600;
            margin-top: 3px;
        }

        .rc-photo {
            width: 55px;
            height: 55px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid <?php echo $secondary_color; ?>;
        }

        /* Student Info - Compact Grid */
        .rc-student-info {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            background: #f8f9fa;
            padding: 6px 12px;
            gap: 8px;
            font-size: 0.75rem;
            border-bottom: 1px solid #e0e0e0;
        }

        .info-item {
            display: flex;
            gap: 5px;
        }

        .info-label {
            font-weight: 600;
            color: #555;
        }

        .info-value {
            color: #222;
        }

        /* Score Table - Compact */
        .rc-section-title {
            background: <?php echo $primary_color; ?>;
            color: white;
            padding: 5px 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .score-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.7rem;
        }

        .score-table th {
            background: #eef2ff;
            padding: 5px 6px;
            text-align: center;
            border: 1px solid #ddd;
            font-weight: 600;
        }

        .score-table th:first-child {
            text-align: left;
        }

        .score-table td {
            padding: 4px 6px;
            border: 1px solid #eee;
            text-align: center;
        }

        .score-table td:first-child {
            text-align: left;
            font-weight: 500;
        }

        .score-table .total-cell {
            font-weight: 700;
            color: <?php echo $primary_color; ?>;
        }

        /* Summary Strip - Compact */
        .summary-strip {
            display: flex;
            flex-wrap: wrap;
            background: #f0f4ff;
            padding: 6px 12px;
            gap: 15px;
            font-size: 0.7rem;
            border-top: 1px solid #ddd;
            border-bottom: 1px solid #ddd;
        }

        .summary-item {
            display: flex;
            gap: 5px;
        }

        .summary-label {
            font-weight: 600;
            color: #555;
        }

        /* Traits - Compact Two Columns */
        .traits-row {
            display: flex;
            border-bottom: 1px solid #eee;
        }

        .traits-col {
            flex: 1;
            padding: 5px 10px;
        }

        .traits-col:first-child {
            border-right: 1px solid #eee;
        }

        .trait-line {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
            font-size: 0.7rem;
        }

        .trait-grade {
            padding: 0 6px;
            border-radius: 10px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .grade-A {
            background: #d4edda;
            color: #155724;
        }

        .grade-B {
            background: #cce5ff;
            color: #004085;
        }

        .grade-C {
            background: #fff3cd;
            color: #856404;
        }

        .grade-D {
            background: #ffe0b2;
            color: #e65100;
        }

        .grade-E {
            background: #f8d7da;
            color: #721c24;
        }

        .grade-none {
            background: #f0f0f0;
            color: #999;
        }

        /* Comments - Compact */
        .comments-row {
            display: flex;
            border-bottom: 1px solid #eee;
        }

        .comment-col {
            flex: 1;
            padding: 6px 10px;
        }

        .comment-col:first-child {
            border-right: 1px solid #eee;
        }

        .comment-label {
            font-weight: 600;
            font-size: 0.65rem;
            color: <?php echo $primary_color; ?>;
            margin-bottom: 3px;
        }

        .comment-text {
            font-size: 0.7rem;
            line-height: 1.3;
        }

        .comment-sign {
            font-size: 0.6rem;
            color: #888;
            margin-top: 3px;
            padding-top: 2px;
            border-top: 1px dashed #ddd;
        }

        /* Footer - Compact */
        .rc-footer {
            background: linear-gradient(90deg, <?php echo $primary_color; ?>, #2c3e50);
            color: white;
            padding: 5px 12px;
            display: flex;
            justify-content: space-between;
            font-size: 0.6rem;
        }

        /* Grade Key */
        .grade-key {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 5px 12px;
            background: #f9f9f9;
            font-size: 0.6rem;
            border-top: 1px solid #eee;
        }

        /* Print / PDF Optimizations */
        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }

            .controls,
            .alert,
            .no-print {
                display: none !important;
            }

            .container {
                max-width: 100%;
                margin: 0;
                padding: 0;
            }

            .report-card {
                box-shadow: none;
                margin: 0;
                max-width: 100%;
            }

            .rc-header,
            .rc-student-info,
            .score-table,
            .summary-strip,
            .traits-row,
            .comments-row,
            .rc-footer {
                break-inside: avoid;
            }
        }
    </style>
</head>

<body>
    <div class="container">

        <!-- Controls Bar (No Sidebar) -->
        <div class="controls no-print">
            <div class="student-selector">
                <label><i class="fas fa-user-graduate"></i> Student:</label>
                <select id="studentSelect" onchange="changeStudent()">
                    <?php foreach ($students as $s):
                        $sid = (int)$s['id'];
                        $hasScores = !empty($scores[$sid]);
                        $hasComments = !empty($comments[$sid]);
                    ?>
                        <option value="<?php echo $sid; ?>" <?php echo $sid === $preview_sid ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s['full_name']); ?>
                            (<?php echo $hasScores && $hasComments ? '✓' : '⚠'; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <?php if (($record['status'] ?? '') !== 'published'): ?>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Publish all report cards?')">
                        <input type="hidden" name="action" value="publish_record">
                        <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-globe"></i> Publish</button>
                    </form>
                <?php else: ?>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Unpublish?')">
                        <input type="hidden" name="action" value="unpublish_record">
                        <button type="submit" class="btn btn-warning btn-sm"><i class="fas fa-eye-slash"></i> Unpublish</button>
                    </form>
                <?php endif; ?>
                <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                <button class="btn btn-secondary btn-sm" id="downloadBtn" onclick="downloadPDF()"><i class="fas fa-file-pdf"></i> Download PDF</button>
                <a href="exam_record_setup.php" class="btn btn-secondary btn-sm"><i class="fas fa-list"></i> Back</a>
            </div>
        </div>

        <!-- Flash Messages -->
        <?php if ($success_msg): ?>
            <div class="alert alert-success no-print"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-danger no-print"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <?php if (empty($students)): ?>
            <div class="alert alert-warning">No active students found in <strong><?php echo htmlspecialchars($class); ?></strong>.</div>
        <?php elseif (!$preview_student): ?>
            <div class="alert alert-warning">Please select a student.</div>
        <?php else:
            $sid = (int)$preview_student['id'];
            $s_scores = $scores[$sid] ?? [];
            $s_pos = $positions[$sid] ?? [];
            $s_comm = $comments[$sid] ?? [];
            $s_af = $affective[$sid] ?? [];
            $s_pm = $psychomotor[$sid] ?? [];

            $avg = (float)($s_pos['average'] ?? 0);
            $class_pos = (int)($s_pos['class_position'] ?? 0);
            $promoted_to = $s_pos['promoted_to'] ?? '';
            $days_opened = (int)($record['days_school_opened'] ?? 90);
            $days_present = (int)($s_comm['days_present'] ?? 0);
            $total_scored = 0;
            $subject_count = 0;
            foreach ($subjects as $sub) {
                if (isset($s_scores[(int)$sub['id']])) {
                    $total_scored += (float)$s_scores[(int)$sub['id']]['total_score'];
                    $subject_count++;
                }
            }
        ?>

            <!-- COMPACT REPORT CARD -->
            <div class="report-card" id="reportCard">

                <!-- Header -->
                <div class="rc-header">
                    <img class="rc-logo" src="<?php echo htmlspecialchars($school_logo); ?>" alt="Logo" onerror="this.style.display='none'">
                    <div class="rc-title">
                        <h2><?php echo htmlspecialchars($school_name); ?></h2>
                        <?php if ($school_motto): ?>
                            <div class="motto">"<?php echo htmlspecialchars($school_motto); ?>"</div>
                        <?php endif; ?>
                        <div class="report-name"><?php echo htmlspecialchars($term); ?> TERM REPORT</div>
                        <div style="font-size:0.65rem;"><?php echo htmlspecialchars($session); ?></div>
                    </div>
                    <div class="rc-photo">
                        <?php if (!empty($preview_student['profile_picture'])): ?>
                            <img src="<?php echo htmlspecialchars($preview_student['profile_picture']); ?>" style="width:55px;height:55px;border-radius:50%;object-fit:cover;">
                        <?php else: ?>
                            <i class="fas fa-user" style="font-size:28px; line-height:55px;"></i>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Student Info - Compact -->
                <div class="rc-student-info">
                    <div class="info-item"><span class="info-label">Name:</span><span class="info-value"><?php echo htmlspecialchars($preview_student['full_name']); ?></span></div>
                    <div class="info-item"><span class="info-label">Adm No:</span><span class="info-value"><?php echo htmlspecialchars($preview_student['admission_number']); ?></span></div>
                    <div class="info-item"><span class="info-label">Class:</span><span class="info-value"><?php echo htmlspecialchars($class); ?></span></div>
                    <div class="info-item"><span class="info-label">Gender:</span><span class="info-value"><?php echo htmlspecialchars(ucfirst($preview_student['gender'] ?? '')); ?></span></div>
                </div>

                <!-- Scores Section -->
                <div class="rc-section-title"><i class="fas fa-list-alt"></i> ACADEMIC PERFORMANCE</div>
                <?php if (empty($s_scores)): ?>
                    <div style="padding:15px;text-align:center;color:#999;">No scores recorded.</div>
                <?php else: ?>
                    <table class="score-table">
                        <thead>
                            <tr>
                                <th>Subject</th><?php foreach ($score_types as $st): ?><th><?php echo htmlspecialchars(substr($st['label'] ?? $st['name'] ?? 'CA', 0, 8)); ?></th><?php endforeach; ?><th>Total</th>
                                <th>Grade</th>
                                <th>Remark</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $sub):
                                $sub_id = (int)$sub['id'];
                                $row = $s_scores[$sub_id] ?? null;
                                if (!$row) continue;
                                $total = (float)$row['total_score'];
                                $grade_info = getGradeInfo($total, $grading_scale);
                                $grade = $row['grade'] ?: $grade_info['grade'];
                                $g_cls = strtolower(substr($grade, 0, 1));
                                if (!in_array($g_cls, ['a', 'b', 'c', 'd'])) $g_cls = 'f';
                            ?>
                                <tr>
                                    <td style="text-align:left"><?php echo htmlspecialchars($sub['subject_name']); ?></td>
                                    <?php foreach ($score_types as $st):
                                        $st_key = strtolower(str_replace([' ', '-'], '_', $st['label'] ?? $st['name'] ?? ''));
                                        $val = $row['score_data'][$st_key] ?? $row['score_data'][$st['label'] ?? ''] ?? $row['score_data'][$st['name'] ?? ''] ?? '—';
                                    ?>
                                        <td><?php echo is_numeric($val) ? $val : '—'; ?></td>
                                    <?php endforeach; ?>
                                    <td class="total-cell"><?php echo number_format($total, 0); ?></td>
                                    <td><span class="trait-grade grade-<?php echo $g_cls === 'f' ? 'F' : strtoupper($g_cls); ?>"><?php echo htmlspecialchars($grade); ?></span></td>
                                    <td><?php echo htmlspecialchars($grade_info['remark']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <!-- Summary Strip -->
                <div class="summary-strip">
                    <div class="summary-item"><span class="summary-label">Subjects:</span><span><?php echo $subject_count; ?></span></div>
                    <div class="summary-item"><span class="summary-label">Total:</span><span><?php echo number_format($total_scored, 0); ?></span></div>
                    <div class="summary-item"><span class="summary-label">Average:</span><span><strong><?php echo number_format($avg, 1); ?>%</strong></span></div>
                    <div class="summary-item"><span class="summary-label">Position:</span><span><?php echo $class_pos > 0 ? ordinal($class_pos) : '—'; ?></span></div>
                    <div class="summary-item"><span class="summary-label">In Class:</span><span><?php echo $num_in_class; ?></span></div>
                    <div class="summary-item"><span class="summary-label">Attendance:</span><span><?php echo $days_present; ?>/<?php echo $days_opened; ?> days</span></div>
                </div>

                <!-- Grade Key -->
                <div class="grade-key">
                    <span style="font-weight:600;">GRADE KEY:</span>
                    <?php foreach ($grading_scale as $g):
                        $gc = strtolower(substr($g['grade'], 0, 1));
                        if (!in_array($gc, ['a', 'b', 'c', 'd'])) $gc = 'f';
                    ?>
                        <span class="trait-grade grade-<?php echo strtoupper($gc); ?>"><?php echo $g['grade']; ?> (<?php echo $g['min']; ?>-<?php echo $g['max']; ?>) <?php echo $g['remark']; ?></span>
                    <?php endforeach; ?>
                </div>

                <!-- Affective Traits & Psychomotor - Side by Side Compact -->
                <?php if ((int)($record['show_affective_traits'] ?? 1) || (int)($record['show_psychomotor'] ?? 1)): ?>
                    <div class="traits-row">
                        <?php if ((int)($record['show_affective_traits'] ?? 1)): ?>
                            <div class="traits-col">
                                <div style="font-weight:600; font-size:0.7rem; margin-bottom:4px; color:<?php echo $primary_color; ?>;"><i class="fas fa-heart"></i> Affective Traits</div>
                                <?php foreach ($affective_fields as $field => $label):
                                    $val = $s_af[$field] ?? null;
                                    $gradeClass = $val ? 'grade-' . $val : 'grade-none';
                                ?>
                                    <div class="trait-line">
                                        <span><?php echo htmlspecialchars($label); ?></span>
                                        <span class="trait-grade <?php echo $gradeClass; ?>"><?php echo $val ? "{$val} - " . ($trait_labels[$val] ?? $val) : '—'; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ((int)($record['show_psychomotor'] ?? 1)): ?>
                            <div class="traits-col">
                                <div style="font-weight:600; font-size:0.7rem; margin-bottom:4px; color:<?php echo $primary_color; ?>;"><i class="fas fa-hand-paper"></i> Psychomotor Skills</div>
                                <?php foreach ($psychomotor_fields as $field => $label):
                                    $val = $s_pm[$field] ?? null;
                                    $gradeClass = $val ? 'grade-' . $val : 'grade-none';
                                ?>
                                    <div class="trait-line">
                                        <span><?php echo htmlspecialchars($label); ?></span>
                                        <span class="trait-grade <?php echo $gradeClass; ?>"><?php echo $val ? "{$val} - " . ($trait_labels[$val] ?? $val) : '—'; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Comments Section -->
                <div class="comments-row">
                    <div class="comment-col">
                        <div class="comment-label"><i class="fas fa-chalkboard-teacher"></i> Class Teacher's Comment</div>
                        <div class="comment-text"><?php echo nl2br(htmlspecialchars($s_comm['teachers_comment'] ?? '—')); ?></div>
                        <?php if (!empty($s_comm['class_teachers_name'])): ?>
                            <div class="comment-sign">Signed: <?php echo htmlspecialchars($s_comm['class_teachers_name']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="comment-col">
                        <div class="comment-label"><i class="fas fa-user-tie"></i> Principal's Comment</div>
                        <div class="comment-text"><?php echo nl2br(htmlspecialchars($s_comm['principals_comment'] ?? '—')); ?></div>
                        <?php if (!empty($s_comm['principals_name'])): ?>
                            <div class="comment-sign">Signed: <?php echo htmlspecialchars($s_comm['principals_name']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Promotion & Footer -->
                <?php if ((int)($record['show_promoted_to'] ?? 1) && $promoted_to): ?>
                    <div style="padding:4px 12px; background:#e8f5e9; font-size:0.65rem;">
                        <i class="fas fa-arrow-up" style="color:#28a745;"></i> <strong>Promoted to:</strong> <?php echo htmlspecialchars($promoted_to); ?>
                    </div>
                <?php endif; ?>

                <div class="rc-footer">
                    <span><?php echo htmlspecialchars($school_name); ?></span>
                    <span>Generated: <?php echo date('d M Y'); ?></span>
                    <span>Status: <?php echo ucfirst($record['status'] ?? 'draft'); ?></span>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <script>
        function changeStudent() {
            const sid = document.getElementById('studentSelect').value;
            window.location.href = 'exam_generate_cards.php?record_id=<?php echo $record_id; ?>&student_id=' + sid;
        }

        async function downloadPDF() {
            const card = document.getElementById('reportCard');
            if (!card) {
                alert('No report card found.');
                return;
            }

            const btn = document.getElementById('downloadBtn');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';

            try {
                const canvas = await html2canvas(card, {
                    scale: 2.5,
                    useCORS: true,
                    backgroundColor: '#ffffff',
                    logging: false
                });

                const {
                    jsPDF
                } = window.jspdf;
                const imgData = canvas.toDataURL('image/jpeg', 0.95);
                const pdf = new jsPDF({
                    orientation: 'portrait',
                    unit: 'mm',
                    format: 'a4'
                });

                const pdfWidth = pdf.internal.pageSize.getWidth();
                const pdfHeight = pdf.internal.pageSize.getHeight();
                const imgWidth = canvas.width;
                const imgHeight = canvas.height;
                const ratio = imgHeight / imgWidth;
                const finalWidth = pdfWidth - 10;
                const finalHeight = finalWidth * ratio;
                const yOffset = (pdfHeight - finalHeight) / 2;

                pdf.addImage(imgData, 'JPEG', 5, yOffset, finalWidth, finalHeight);
                pdf.save('report_card_<?php echo $preview_student ? preg_replace('/[^a-z0-9]/i', '_', $preview_student['full_name']) : 'student'; ?>.pdf');
            } catch (err) {
                console.error(err);
                alert('PDF generation failed: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        }
    </script>
</body>

</html>
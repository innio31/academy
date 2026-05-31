<?php
// msv/student/report-card.php — Student Report Card Viewer
// Checks publish status, loads the student's own record, renders card + PDF download

error_reporting(E_ALL);
ini_set('display_errors', 0);
session_start();
require_once '../includes/config.php';

// ── Auth ─────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header("Location: /msv/login.php");
    exit();
}

$student_id   = (int)$_SESSION['user_id'];
$student_name = $_SESSION['user_name'] ?? 'Student';

$school_id       = SCHOOL_ID;
$school_name     = SCHOOL_NAME;
$primary_color   = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;

$school_logo    = defined('SCHOOL_LOGO')    ? SCHOOL_LOGO    : '';
$school_motto   = defined('SCHOOL_MOTTO')   ? SCHOOL_MOTTO   : '';
$school_address = defined('SCHOOL_ADDRESS') ? SCHOOL_ADDRESS : '';
$school_phone   = defined('SCHOOL_PHONE')   ? SCHOOL_PHONE   : '';
$school_email   = defined('SCHOOL_EMAIL')   ? SCHOOL_EMAIL   : '';

// ── Load Student Record ───────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT id, full_name, admission_number, gender, dob,
                guardian_name, class, class_id, profile_picture
         FROM students
         WHERE id = ? AND school_id = ? AND status = 'active'
         LIMIT 1"
    );
    $stmt->execute([$student_id, $school_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $student = null;
}

if (!$student) {
    header("Location: /msv/login.php");
    exit();
}

$student_class = $student['class'];
$student_class_id = (int)($student['class_id'] ?? 0);

// Pull extra school info from DB if constants are empty
if (empty($school_address) || empty($school_phone)) {
    try {
        $stmt = $pdo->prepare(
            "SELECT motto, address, contact_phone, contact_email, logo_path
             FROM schools WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$school_id]);
        $db_info = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($db_info) {
            if (empty($school_motto))   $school_motto   = $db_info['motto']         ?? '';
            if (empty($school_address)) $school_address = $db_info['address']       ?? '';
            if (empty($school_phone))   $school_phone   = $db_info['contact_phone'] ?? '';
            if (empty($school_email))   $school_email   = $db_info['contact_email'] ?? '';
            if (empty($school_logo))    $school_logo    = $db_info['logo_path']     ?? '';
        }
    } catch (Exception $e) {}
}

// ── Determine requested term/session (or default to latest published) ─────────
$req_session = trim($_GET['session'] ?? '');
$req_term    = trim($_GET['term']    ?? '');

// ── Fetch all PUBLISHED records for this student's class ───────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT id, record_name, session, term, score_types,
                days_school_opened, show_class_position, show_subject_position,
                show_lowest_highest_avg, show_cumulative_avg, show_attendance,
                show_affective_traits, show_psychomotor, show_promoted_to,
                next_resumption_date, status
         FROM report_card_settings
         WHERE school_id = ? AND class = ? AND status = 'published'
         ORDER BY session DESC, FIELD(term,'Third','Second','First')"
    );
    $stmt->execute([$school_id, $student_class]);
    $published_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $published_records = [];
}

// Pick the record to display
$record = null;
foreach ($published_records as $r) {
    if ($req_session && $req_term) {
        if ($r['session'] === $req_session && $r['term'] === $req_term) {
            $record = $r;
            break;
        }
    } else {
        $record = $r; // first = latest
        break;
    }
}

// ── Helper Functions ──────────────────────────────────────────────────────────
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
    if ($n <= 0) return '—';
    $sfx = ['th','st','nd','rd'];
    $v   = $n % 100;
    return $n . ($sfx[($v - 20) % 10] ?? $sfx[min($v, 3)]);
}

// ── Load Card Data (only when a published record is found) ────────────────────
$subjects = $s_scores = $s_pos = $s_comm = $s_af = $s_pm = [];
$grading_scale = $score_types = [];
$class_averages = $subject_stats = [];
$highest_avg = $lowest_avg = 0;
$total_students = 0;

if ($record) {
    $session = $record['session'];
    $term    = $record['term'];

    // Decode score types & grading
    $decoded      = json_decode($record['score_types'] ?? '{}', true);
    $score_types  = $decoded['score_types']  ?? (is_array($decoded) && isset($decoded[0]['label']) ? $decoded : []);
    $grading_scale = $decoded['grading_scale'] ?? [
        ['grade'=>'A','min'=>75, 'max'=>100,'remark'=>'Excellent'],
        ['grade'=>'B','min'=>65, 'max'=>74, 'remark'=>'Very Good'],
        ['grade'=>'C','min'=>50, 'max'=>64, 'remark'=>'Good'],
        ['grade'=>'D','min'=>40, 'max'=>49, 'remark'=>'Pass'],
        ['grade'=>'F','min'=>0,  'max'=>39, 'remark'=>'Fail'],
    ];

    // Subjects for this class
    try {
        $stmt = $pdo->prepare(
            "SELECT s.id, s.subject_name
             FROM subjects s
             JOIN subject_classes sc ON sc.subject_id = s.id AND sc.school_id = ?
             WHERE sc.class = ? AND (s.school_id = ? OR s.is_central = 1)
             ORDER BY s.subject_name ASC"
        );
        $stmt->execute([$school_id, $student_class, $school_id]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // This student's scores
    if (!empty($subjects)) {
        try {
            $sub_ids = array_column($subjects, 'id');
            $ph = implode(',', array_fill(0, count($sub_ids), '?'));
            $stmt = $pdo->prepare(
                "SELECT subject_id, score_data, total_score, grade, subject_position
                 FROM student_scores
                 WHERE school_id=? AND student_id=? AND session=? AND term=?
                 AND subject_id IN ($ph)"
            );
            $stmt->execute(array_merge([$school_id, $student_id, $session, $term], $sub_ids));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $row['score_data'] = json_decode($row['score_data'] ?? '[]', true) ?: [];
                $s_scores[(int)$row['subject_id']] = $row;
            }
        } catch (Exception $e) {}
    }

    // Position
    try {
        $stmt = $pdo->prepare(
            "SELECT class_position, total_marks, average, promoted_to
             FROM student_positions
             WHERE school_id=? AND student_id=? AND session=? AND term=? LIMIT 1"
        );
        $stmt->execute([$school_id, $student_id, $session, $term]);
        $s_pos = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}

    // Comments
    try {
        $stmt = $pdo->prepare(
            "SELECT teachers_comment, principals_comment, class_teachers_name,
                    principals_name, days_present
             FROM student_comments
             WHERE school_id=? AND student_id=? AND session=? AND term=? LIMIT 1"
        );
        $stmt->execute([$school_id, $student_id, $session, $term]);
        $s_comm = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}

    // Affective traits
    try {
        $stmt = $pdo->prepare(
            "SELECT punctuality, attendance, politeness, honesty, neatness,
                    reliability, relationship, self_control
             FROM affective_traits
             WHERE school_id=? AND student_id=? AND session=? AND term=? LIMIT 1"
        );
        $stmt->execute([$school_id, $student_id, $session, $term]);
        $s_af = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}

    // Psychomotor skills
    try {
        $stmt = $pdo->prepare(
            "SELECT handwriting, verbal_fluency, sports, handling_tools,
                    drawing_painting, musical_skills
             FROM psychomotor_skills
             WHERE school_id=? AND student_id=? AND session=? AND term=? LIMIT 1"
        );
        $stmt->execute([$school_id, $student_id, $session, $term]);
        $s_pm = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}

    // Class-wide stats (for highest/lowest average)
    try {
        $stmt = $pdo->prepare(
            "SELECT sp.average
             FROM student_positions sp
             JOIN students st ON st.id = sp.student_id
             WHERE sp.school_id=? AND sp.session=? AND sp.term=?
             AND (st.class=? OR st.class_id=?)"
        );
        $stmt->execute([$school_id, $session, $term, $student_class, $student_class_id]);
        $avgs = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($avgs)) {
            $highest_avg    = max($avgs);
            $lowest_avg     = min($avgs);
            $total_students = count($avgs);
        }
    } catch (Exception $e) {}
}

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
    'handling_tools'  => 'Handling Tools',
    'drawing_painting'=> 'Drawing / Painting',
    'musical_skills'  => 'Musical Skills',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Report Card — <?php echo htmlspecialchars($school_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --primary:   <?php echo $primary_color; ?>;
            --secondary: <?php echo $secondary_color; ?>;
            --success:   #27ae60;
            --warning:   #f39c12;
            --light:     #ecf0f1;
            --dark:      #2c3e50;
            --shadow:    0 2px 8px rgba(0,0,0,.08);
            --radius:    10px;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            color: #333;
        }

        /* ── Layout ── */
        .page-wrapper {
            display: flex;
            min-height: 100vh;
        }

        .content-area {
            flex: 1;
            margin-left: 280px;
            padding: 24px;
            transition: margin .3s;
        }

        @media (max-width: 767px) {
            .content-area { margin-left: 0; }
        }

        /* ── Mobile toggle ── */
        .mobile-toggle {
            display: none;
            position: fixed;
            top: 14px;
            left: 14px;
            z-index: 1002;
            width: 42px;
            height: 42px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 18px;
            cursor: pointer;
        }

        @media (max-width: 767px) {
            .mobile-toggle { display: flex; align-items: center; justify-content: center; }
        }

        /* ── Top bar ── */
        .top-bar {
            background: white;
            border-radius: var(--radius);
            padding: 14px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .top-bar h1 {
            font-size: 1.1rem;
            font-weight: 600;
        }

        .top-bar p {
            font-size: 0.72rem;
            color: #666;
            margin-top: 2px;
        }

        /* ── Term selector ── */
        .term-selector {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 20px;
        }

        .term-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 500;
            text-decoration: none;
            background: white;
            color: #555;
            border: 1px solid #ddd;
            box-shadow: var(--shadow);
            transition: all .2s;
        }

        .term-btn:hover,
        .term-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* ── Action buttons ── */
        .action-bar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 18px;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.82rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: opacity .2s;
        }

        .btn:disabled { opacity: .6; cursor: not-allowed; }

        .btn-primary   { background: var(--primary);   color: white; }
        .btn-secondary { background: white; color: var(--primary); border: 1px solid var(--primary); }

        /* ── Not-published notice ── */
        .notice-card {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 60px 30px;
            text-align: center;
        }

        .notice-icon {
            font-size: 3.5rem;
            color: #ccc;
            margin-bottom: 16px;
        }

        .notice-card h2 { font-size: 1.2rem; margin-bottom: 8px; }
        .notice-card p  { font-size: 0.85rem; color: #666; max-width: 400px; margin: 0 auto; }

        /* ═══════════════════════════════════
           REPORT CARD STYLES
        ═══════════════════════════════════ */
        .rc-card {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            font-family: 'Poppins', sans-serif;
        }

        /* Header */
        .rc-header {
            padding: 14px 20px;
            border-bottom: 3px solid var(--secondary);
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .rc-logo {
            width: 72px; height: 72px;
            object-fit: contain; flex-shrink: 0;
        }

        .rc-school-details { flex: 1; text-align: center; }

        .rc-school-details h1 {
            font-size: 1.15rem; font-weight: 700;
            color: var(--primary); margin: 0 0 4px;
        }

        .rc-school-details .motto   { font-size: .7rem; font-style: italic; color: var(--secondary); margin-bottom: 3px; }
        .rc-school-details .address { font-size: .65rem; color: #555; margin-bottom: 3px; }
        .rc-school-details .contacts{ font-size: .62rem; color: #777; }

        .rc-title {
            display: inline-block;
            background: var(--primary); color: white;
            padding: 3px 16px; border-radius: 20px;
            font-size: .7rem; font-weight: 600; margin-top: 6px;
        }

        .rc-photo {
            width: 72px; height: 72px;
            object-fit: cover; border-radius: 8px;
            border: 2px solid var(--light); flex-shrink: 0;
        }

        .rc-photo-placeholder {
            width: 72px; height: 72px;
            background: var(--light); border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 2rem; color: #aaa; flex-shrink: 0;
        }

        /* Student info strip */
        .rc-student-info {
            display: flex; flex-wrap: wrap;
            background: #f8f9fc;
            padding: 7px 16px;
            border-bottom: 1px solid #e0e0e0;
            font-size: .7rem;
            gap: 4px 0;
        }

        .info-item {
            display: inline-flex; align-items: baseline;
            gap: 5px; margin-right: 22px; padding: 3px 0;
        }

        .info-label { font-weight: 600; color: #666; }
        .info-value { font-weight: 500; color: #222; }

        /* Section title */
        .rc-section-title {
            background: var(--primary); color: white;
            padding: 8px 16px; font-size: .78rem;
            font-weight: 600; letter-spacing: .4px;
        }

        .rc-section-title i { margin-right: 8px; }

        /* Academic table */
        .rc-table { width: 100%; border-collapse: collapse; font-size: .73rem; }

        .rc-table th {
            background: #eef2ff;
            padding: 8px 6px;
            text-align: center;
            border: 1px solid #d0d7de;
            font-weight: 600; font-size: .68rem;
        }

        .rc-table th:first-child { text-align: left; padding-left: 12px; }

        .rc-table td {
            padding: 6px; border: 1px solid #e8e8e8;
            text-align: center; font-size: .71rem;
        }

        .rc-table td:first-child { text-align: left; padding-left: 12px; font-weight: 500; }

        .rc-table tr:nth-child(even) td { background: #fafbff; }

        .rc-total { font-weight: 700; color: var(--primary); font-size: .77rem; }

        /* Grade badges */
        .g-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: .68rem; font-weight: 700; }
        .g-a { background: #d4edda; color: #155724; }
        .g-b { background: #cce5ff; color: #004085; }
        .g-c { background: #fff3cd; color: #856404; }
        .g-d { background: #fce4ec; color: #880e4f; }
        .g-f { background: #f8d7da; color: #721c24; }

        /* Summary stats */
        .rc-summary {
            display: flex; flex-wrap: wrap;
            background: #f0f4ff;
            border-top: 1px solid var(--secondary);
            border-bottom: 1px solid #e0e0e0;
            padding: 8px 12px; gap: 12px;
            justify-content: space-around;
        }

        .summary-item { text-align: center; padding: 4px 8px; }
        .summary-item .value { font-size: .9rem; font-weight: 700; color: var(--primary); }
        .summary-item .label { font-size: .6rem; color: #666; }

        /* Grading key */
        .grade-key {
            padding: 5px 12px;
            background: #f9f9f9;
            border-bottom: 1px solid #eee;
            display: flex; flex-wrap: wrap; gap: 8px;
            align-items: center; font-size: .6rem;
        }

        .grade-key strong { font-size: .64rem; color: #555; }

        /* Traits */
        .traits-section {
            display: flex; flex-wrap: wrap;
            border-bottom: 1px solid #e0e0e0;
        }

        .trait-col { flex: 1; min-width: 180px; }

        .trait-row {
            display: flex; justify-content: space-between;
            align-items: center; padding: 4px 12px;
            border-bottom: 1px solid #f0f0f0; font-size: .68rem;
        }

        .trait-val { padding: 2px 7px; border-radius: 8px; font-size: .62rem; font-weight: 600; }
        .tv-a { background: #d4edda; color: #155724; }
        .tv-b { background: #cce5ff; color: #004085; }
        .tv-c { background: #fff3cd; color: #856404; }
        .tv-d { background: #ffe0b2; color: #e65100; }
        .tv-e { background: #f8d7da; color: #721c24; }
        .tv-null { background: #f0f0f0; color: #999; }

        /* Comments */
        .comments-row {
            display: flex;
            border-bottom: 1px solid #e0e0e0;
        }

        .comment-box {
            flex: 1; padding: 8px 14px;
            border-right: 1px solid #e0e0e0;
        }

        .comment-box:last-child { border-right: none; }

        .comment-box .c-label {
            font-size: .6rem; color: var(--primary);
            font-weight: 600; margin-bottom: 3px;
        }

        .comment-box .c-text { font-size: .68rem; line-height: 1.4; }

        .comment-box .c-signature {
            font-size: .58rem; color: #888;
            margin-top: 4px; border-top: 1px dashed #ddd;
            padding-top: 3px;
        }

        /* Resumption notice */
        .rc-resumption {
            padding: 6px 16px;
            background: #fffbe6;
            border-bottom: 1px solid #ffe082;
            font-size: .7rem;
            color: #6d4c00;
        }

        .rc-resumption i { margin-right: 6px; }

        /* Footer */
        .rc-footer {
            background: linear-gradient(90deg, var(--primary), var(--dark));
            color: white; padding: 6px 16px;
            display: flex; justify-content: space-between;
            font-size: .6rem;
        }

        /* ── Print / PDF ── */
        @media print {
            @page { size: A4 portrait; margin: 5mm; }

            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .no-print,
            .mobile-toggle,
            .top-bar,
            .term-selector,
            .action-bar,
            button { display: none !important; }

            .content-area { margin-left: 0 !important; padding: 0 !important; }
            .student-sidebar, .sidebar-overlay { display: none !important; }
            .rc-card { box-shadow: none; border-radius: 0; }
        }
    </style>
</head>
<body>
<div class="page-wrapper">

    <!-- Sidebar -->
    <?php
    // Pass required variables to the sidebar include
    require_once 'includes/student_sidebar.php';
    ?>

    <!-- Mobile toggle -->
    <button class="mobile-toggle no-print" id="mobileMenuBtn" aria-label="Open menu">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Main content -->
    <main class="content-area">

        <!-- Top bar -->
        <div class="top-bar no-print">
            <div>
                <h1><i class="fas fa-id-card" style="color:var(--primary);margin-right:8px;"></i>My Report Card</h1>
                <p><?php echo htmlspecialchars($student_class); ?> &bull; <?php echo htmlspecialchars($student_name); ?></p>
            </div>
        </div>

        <?php if (empty($published_records)): ?>
        <!-- ══ NOT PUBLISHED ══ -->
        <div class="notice-card">
            <div class="notice-icon"><i class="fas fa-clock"></i></div>
            <h2>Report Card Not Available Yet</h2>
            <p>Your report card hasn't been published by the school administration. Please check back later or contact your class teacher.</p>
        </div>

        <?php else: ?>
        <!-- ══ TERM SELECTOR ══ -->
        <div class="term-selector no-print">
            <?php foreach ($published_records as $r):
                $is_active = $record && $r['id'] === $record['id'];
            ?>
                <a href="?session=<?php echo urlencode($r['session']); ?>&term=<?php echo urlencode($r['term']); ?>"
                   class="term-btn <?php echo $is_active ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-alt"></i>
                    <?php echo htmlspecialchars($r['term']); ?> Term &bull; <?php echo htmlspecialchars($r['session']); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- ══ ACTION BAR ══ -->
        <div class="action-bar no-print">
            <button class="btn btn-primary" onclick="downloadReportCardPDF()">
                <i class="fas fa-file-pdf"></i> Download PDF
            </button>
            <button class="btn btn-secondary" onclick="window.print()">
                <i class="fas fa-print"></i> Print
            </button>
        </div>

        <?php if (!$record): ?>
        <div class="notice-card">
            <div class="notice-icon"><i class="fas fa-search"></i></div>
            <h2>Record Not Found</h2>
            <p>The requested term record could not be found. Please select a term from the options above.</p>
        </div>

        <?php elseif (empty($s_scores)): ?>
        <div class="notice-card">
            <div class="notice-icon"><i class="fas fa-exclamation-circle"></i></div>
            <h2>Scores Not Available</h2>
            <p>Your scores for <strong><?php echo htmlspecialchars($record['term']); ?> Term <?php echo htmlspecialchars($record['session']); ?></strong> have not been entered yet. Please contact your teacher.</p>
        </div>

        <?php else:
            $avg          = (float)($s_pos['average'] ?? 0);
            $class_pos    = (int)($s_pos['class_position'] ?? 0);
            $days_opened  = (int)($record['days_school_opened'] ?? 90);
            $days_present = (int)($s_comm['days_present'] ?? 0);
            $promoted_to  = $s_pos['promoted_to'] ?? '';
            $resumption   = $record['next_resumption_date'] ?? '';

            // Compute totals
            $total_sum    = 0;
            $scored_count = 0;
            foreach ($subjects as $sub) {
                $sub_id = (int)$sub['id'];
                if (isset($s_scores[$sub_id])) {
                    $total_sum += (float)$s_scores[$sub_id]['total_score'];
                    $scored_count++;
                }
            }
        ?>

        <!-- ══ REPORT CARD ══ -->
        <div class="rc-card" id="reportCard">

            <!-- Header -->
            <div class="rc-header">
                <?php if (!empty($school_logo) && (file_exists($_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($school_logo,'/')) || file_exists($school_logo))): ?>
                    <img class="rc-logo" src="<?php echo htmlspecialchars($school_logo); ?>" alt="School Logo" onerror="this.style.display='none'">
                <?php endif; ?>

                <div class="rc-school-details">
                    <h1><?php echo htmlspecialchars($school_name); ?></h1>
                    <?php if (!empty($school_motto)): ?>
                        <div class="motto">"<?php echo htmlspecialchars($school_motto); ?>"</div>
                    <?php endif; ?>
                    <?php if (!empty($school_address)): ?>
                        <div class="address"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($school_address); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($school_phone) || !empty($school_email)): ?>
                        <div class="contacts">
                            <?php if (!empty($school_phone)): ?>
                                <i class="fas fa-phone"></i> <?php echo htmlspecialchars($school_phone); ?>
                            <?php endif; ?>
                            <?php if (!empty($school_email)): ?>
                                &nbsp;|&nbsp; <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($school_email); ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="rc-title">
                        REPORT CARD &mdash; <?php echo strtoupper(htmlspecialchars($record['term'])); ?> TERM <?php echo htmlspecialchars($record['session']); ?>
                    </div>
                </div>

                <?php if (!empty($student['profile_picture'])): ?>
                    <img class="rc-photo" src="<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="Student Photo" onerror="this.classList.add('d-none')">
                <?php else: ?>
                    <div class="rc-photo-placeholder"><i class="fas fa-user-graduate"></i></div>
                <?php endif; ?>
            </div>

            <!-- Student info strip -->
            <div class="rc-student-info">
                <div class="info-item">
                    <span class="info-label">Name:</span>
                    <span class="info-value"><?php echo htmlspecialchars($student['full_name']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Admission No:</span>
                    <span class="info-value"><?php echo htmlspecialchars($student['admission_number']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Class:</span>
                    <span class="info-value"><?php echo htmlspecialchars($student_class); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Gender:</span>
                    <span class="info-value"><?php echo ucfirst(strtolower($student['gender'] ?? '')); ?></span>
                </div>
                <?php if (!empty($student['guardian_name'])): ?>
                <div class="info-item">
                    <span class="info-label">Guardian:</span>
                    <span class="info-value"><?php echo htmlspecialchars($student['guardian_name']); ?></span>
                </div>
                <?php endif; ?>
                <?php if ((int)($record['show_attendance'] ?? 1) && $days_opened > 0): ?>
                <div class="info-item">
                    <span class="info-label">Attendance:</span>
                    <span class="info-value">
                        <?php echo $days_present; ?>/<?php echo $days_opened; ?> days
                        (<?php echo round(($days_present / $days_opened) * 100); ?>%)
                    </span>
                </div>
                <?php endif; ?>
                <?php if ((int)($record['show_promoted_to'] ?? 1) && !empty($promoted_to)): ?>
                <div class="info-item">
                    <span class="info-label">Promoted To:</span>
                    <span class="info-value" style="color:var(--success);font-weight:600;">
                        <i class="fas fa-arrow-up" style="font-size:.65rem;"></i> <?php echo htmlspecialchars($promoted_to); ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Academic Performance -->
            <div class="rc-section-title">
                <i class="fas fa-chart-line"></i> ACADEMIC PERFORMANCE
            </div>

            <table class="rc-table">
                <thead>
                    <tr>
                        <th style="width:30%">SUBJECT</th>
                        <?php foreach ($score_types as $st): ?>
                            <th><?php echo htmlspecialchars($st['label'] ?? $st['name'] ?? 'CA'); ?></th>
                        <?php endforeach; ?>
                        <th>TOTAL</th>
                        <th>GRADE</th>
                        <th>REMARK</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subjects as $sub):
                        $sub_id = (int)$sub['id'];
                        $row    = $s_scores[$sub_id] ?? null;
                        if (!$row) continue;
                        $total_sc   = (float)$row['total_score'];
                        $grade_info = getGradeInfo($total_sc, $grading_scale);
                        $g_cls      = strtolower(substr($grade_info['grade'], 0, 1));
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($sub['subject_name']); ?></strong></td>
                        <?php foreach ($score_types as $st):
                            $st_key = strtolower(str_replace([' ','-'], '_', $st['label'] ?? $st['name'] ?? ''));
                            $val    = $row['score_data'][$st_key]
                                   ?? $row['score_data'][$st['label'] ?? '']
                                   ?? '—';
                        ?>
                            <td><?php echo is_numeric($val) ? $val : '—'; ?></td>
                        <?php endforeach; ?>
                        <td class="rc-total"><?php echo number_format($total_sc, 0); ?></td>
                        <td><span class="g-badge g-<?php echo $g_cls; ?>"><?php echo $grade_info['grade']; ?></span></td>
                        <td><?php echo htmlspecialchars($grade_info['remark']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Summary stats -->
            <div class="rc-summary">
                <div class="summary-item">
                    <div class="value"><?php echo $scored_count; ?></div>
                    <div class="label">Subjects</div>
                </div>
                <div class="summary-item">
                    <div class="value"><?php echo number_format($total_sum, 0); ?></div>
                    <div class="label">Total Marks</div>
                </div>
                <div class="summary-item">
                    <div class="value"><?php echo number_format($avg, 1); ?>%</div>
                    <div class="label">Average</div>
                </div>
                <?php if ((int)($record['show_class_position'] ?? 1)): ?>
                <div class="summary-item">
                    <div class="value"><?php echo $class_pos ? ordinal($class_pos) : '—'; ?></div>
                    <div class="label">Class Position</div>
                </div>
                <?php endif; ?>
                <?php if ($total_students): ?>
                <div class="summary-item">
                    <div class="value"><?php echo $total_students; ?></div>
                    <div class="label">In Class</div>
                </div>
                <?php endif; ?>
                <?php if ((int)($record['show_lowest_highest_avg'] ?? 1) && $total_students): ?>
                <div class="summary-item">
                    <div class="value"><?php echo number_format($highest_avg, 1); ?>%</div>
                    <div class="label">Highest Avg</div>
                </div>
                <div class="summary-item">
                    <div class="value"><?php echo number_format($lowest_avg, 1); ?>%</div>
                    <div class="label">Lowest Avg</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Grading scale key -->
            <div class="grade-key">
                <strong>GRADING SCALE:</strong>
                <?php foreach ($grading_scale as $g):
                    $gc = strtolower(substr($g['grade'], 0, 1));
                ?>
                    <span class="g-badge g-<?php echo $gc; ?>">
                        <?php echo htmlspecialchars($g['grade']); ?>
                        (<?php echo $g['min']; ?>–<?php echo $g['max']; ?>%)
                        &mdash; <?php echo htmlspecialchars($g['remark']); ?>
                    </span>
                <?php endforeach; ?>
            </div>

            <!-- Affective Traits -->
            <?php if ((int)($record['show_affective_traits'] ?? 1)): ?>
            <div class="rc-section-title" style="background:#5a6268;">
                <i class="fas fa-heart"></i> AFFECTIVE TRAITS
            </div>
            <div class="traits-section">
                <div class="trait-col">
                    <?php
                    $af_keys = array_keys($affective_fields);
                    $half = (int)ceil(count($af_keys) / 2);
                    foreach (array_slice($af_keys, 0, $half) as $fld):
                        $val = $s_af[$fld] ?? null;
                        $cls = $val ? 'tv-' . strtolower($val) : 'tv-null';
                    ?>
                    <div class="trait-row">
                        <span><?php echo htmlspecialchars($affective_fields[$fld]); ?></span>
                        <span class="trait-val <?php echo $cls; ?>"><?php echo $val ?: '—'; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="trait-col">
                    <?php foreach (array_slice($af_keys, $half) as $fld):
                        $val = $s_af[$fld] ?? null;
                        $cls = $val ? 'tv-' . strtolower($val) : 'tv-null';
                    ?>
                    <div class="trait-row">
                        <span><?php echo htmlspecialchars($affective_fields[$fld]); ?></span>
                        <span class="trait-val <?php echo $cls; ?>"><?php echo $val ?: '—'; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Psychomotor Skills -->
            <?php if ((int)($record['show_psychomotor'] ?? 1)): ?>
            <div class="rc-section-title" style="background:#5a6268;">
                <i class="fas fa-futbol"></i> PSYCHOMOTOR SKILLS
            </div>
            <div class="traits-section">
                <div class="trait-col">
                    <?php
                    $pm_keys = array_keys($psychomotor_fields);
                    $half = (int)ceil(count($pm_keys) / 2);
                    foreach (array_slice($pm_keys, 0, $half) as $fld):
                        $val = $s_pm[$fld] ?? null;
                        $cls = $val ? 'tv-' . strtolower($val) : 'tv-null';
                    ?>
                    <div class="trait-row">
                        <span><?php echo htmlspecialchars($psychomotor_fields[$fld]); ?></span>
                        <span class="trait-val <?php echo $cls; ?>"><?php echo $val ?: '—'; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="trait-col">
                    <?php foreach (array_slice($pm_keys, $half) as $fld):
                        $val = $s_pm[$fld] ?? null;
                        $cls = $val ? 'tv-' . strtolower($val) : 'tv-null';
                    ?>
                    <div class="trait-row">
                        <span><?php echo htmlspecialchars($psychomotor_fields[$fld]); ?></span>
                        <span class="trait-val <?php echo $cls; ?>"><?php echo $val ?: '—'; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Comments -->
            <div class="rc-section-title" style="background:#5a6268;">
                <i class="fas fa-comment-dots"></i> COMMENTS
            </div>
            <div class="comments-row">
                <div class="comment-box">
                    <div class="c-label"><i class="fas fa-chalkboard-teacher"></i> Class Teacher's Comment</div>
                    <div class="c-text"><?php echo nl2br(htmlspecialchars($s_comm['teachers_comment'] ?? '—')); ?></div>
                    <?php if (!empty($s_comm['class_teachers_name'])): ?>
                    <div class="c-signature"><?php echo htmlspecialchars($s_comm['class_teachers_name']); ?></div>
                    <?php endif; ?>
                </div>
                <div class="comment-box">
                    <div class="c-label"><i class="fas fa-user-tie"></i> Principal's Comment</div>
                    <div class="c-text"><?php echo nl2br(htmlspecialchars($s_comm['principals_comment'] ?? '—')); ?></div>
                    <?php if (!empty($s_comm['principals_name'])): ?>
                    <div class="c-signature"><?php echo htmlspecialchars($s_comm['principals_name']); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Resumption notice -->
            <?php if (!empty($resumption)): ?>
            <div class="rc-resumption">
                <i class="fas fa-calendar-check"></i>
                <strong>Next Resumption Date:</strong>
                <?php echo htmlspecialchars(date('l, d F Y', strtotime($resumption))); ?>
            </div>
            <?php endif; ?>

            <!-- Footer -->
            <div class="rc-footer">
                <span><?php echo htmlspecialchars($school_name); ?></span>
                <span>Generated: <?php echo date('d M Y'); ?></span>
                <span><?php echo htmlspecialchars($record['term']); ?> Term &bull; <?php echo htmlspecialchars($record['session']); ?></span>
            </div>

        </div><!-- /.rc-card -->

        <?php endif; // end: has scores
        endif; // end: record found
        endif; // end: has published records
        ?>

    </main>
</div>

<script>
/* ── PDF Download ────────────────────────────────────────────────────────── */
async function downloadReportCardPDF() {
    const card = document.getElementById('reportCard');
    if (!card) {
        alert('No report card to download.');
        return;
    }

    const btn = document.querySelector('.btn-primary');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating PDF…';
    }

    // Temporarily hide non-print elements
    const hideSels = ['.no-print', '.student-sidebar', '.sidebar-overlay', '.mobile-toggle'];
    const hidden   = [];
    hideSels.forEach(sel => {
        document.querySelectorAll(sel).forEach(el => {
            hidden.push([el, el.style.display]);
            el.style.display = 'none';
        });
    });

    // Temporarily expand content area to full width
    const contentArea = document.querySelector('.content-area');
    const originalMargin = contentArea ? contentArea.style.marginLeft : '';
    if (contentArea) contentArea.style.marginLeft = '0';

    try {
        const canvas = await html2canvas(card, {
            scale: 2.5,
            useCORS: true,
            allowTaint: true,
            backgroundColor: '#ffffff',
            logging: false,
            windowWidth: 794   // A4 width in px at 96dpi
        });

        const imgData  = canvas.toDataURL('image/jpeg', 0.95);
        const pdf      = new jspdf.jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
        const pdfW     = pdf.internal.pageSize.getWidth();
        const pdfH     = pdf.internal.pageSize.getHeight();
        const imgW     = pdfW - 10;
        const imgH     = (canvas.height * imgW) / canvas.width;

        let posY = 5;
        pdf.addImage(imgData, 'JPEG', 5, posY, imgW, imgH);

        // Add extra pages if content is taller than A4
        if (imgH > pdfH - 10) {
            let remainingH = imgH - (pdfH - posY - 5);
            let pageNum    = 1;
            while (remainingH > 0) {
                pdf.addPage();
                pdf.addImage(imgData, 'JPEG', 5, -(pageNum * (pdfH - 10)) + posY, imgW, imgH);
                remainingH -= (pdfH - 10);
                pageNum++;
            }
        }

        // Compose filename
        const nameParts = '<?php echo addslashes(preg_replace('/[^a-z0-9 ]/i', '', $student['full_name'] ?? 'Student')); ?>';
        const term      = '<?php echo addslashes($record['term'] ?? ''); ?>';
        const session   = '<?php echo addslashes(str_replace('/', '-', $record['session'] ?? '')); ?>';
        const filename  = `${nameParts}_${term}_Term_${session}_ReportCard.pdf`.replace(/\s+/g, '_');

        pdf.save(filename);
    } catch (err) {
        console.error('PDF generation error:', err);
        alert('Failed to generate PDF: ' + err.message);
    } finally {
        // Restore visibility
        hidden.forEach(([el, disp]) => el.style.display = disp);
        if (contentArea) contentArea.style.marginLeft = originalMargin;
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-file-pdf"></i> Download PDF';
        }
    }
}
</script>
</body>
</html>
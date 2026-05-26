<?php
// ida/admin/exam_traits_comments.php — Step 3: Traits, Comments & Attendance (FIXED)
// ─────────────────────────────────────────────────────────────────────────────

error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../includes/config.php';

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /ida/login.php");
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
    $error_msg   = $_SESSION['flash_error'];
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

// ── Load principal comments per grade and default class teacher name ──────────
$principal_comments_map = [];
if (!empty($record['principal_comments_per_grade'])) {
    $principal_comments_map = json_decode($record['principal_comments_per_grade'], true) ?: [];
}
$default_class_teacher = $record['default_class_teacher_name'] ?? '';

// ── Load students ─────────────────────────────────────────────────────────────
$students = [];
try {
    // First get the class_id from the class name
    $stmt = $pdo->prepare("SELECT id FROM classes WHERE class_name = ? AND school_id = ?");
    $stmt->execute([$class, $school_id]);
    $class_row = $stmt->fetch(PDO::FETCH_ASSOC);
    $class_id = $class_row ? $class_row['id'] : 0;
    
    if ($class_id > 0) {
        $stmt = $pdo->prepare("
            SELECT id, full_name, admission_number, gender, dob, guardian_name
              FROM students
             WHERE school_id = ? AND class_id = ? AND status = 'active'
             ORDER BY full_name ASC
        ");
        $stmt->execute([$school_id, $class_id]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Fallback to class name if class_id not found
        $stmt = $pdo->prepare("
            SELECT id, full_name, admission_number, gender, dob, guardian_name
              FROM students
             WHERE school_id = ? AND class = ? AND status = 'active'
             ORDER BY full_name ASC
        ");
        $stmt->execute([$school_id, $class]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("traits students: " . $e->getMessage());
}

$total_students = count($students);

// ── Active student ─────────────────────────────────────────────────────────────
$student_ids    = array_column($students, 'id');
$active_idx     = 0;

if (isset($_GET['student_id'])) {
    $req_id = (int)$_GET['student_id'];
    foreach ($students as $i => $s) {
        if ((int)$s['id'] === $req_id) {
            $active_idx = $i;
            break;
        }
    }
}

$active_student = $students[$active_idx] ?? null;
$active_sid     = $active_student ? (int)$active_student['id'] : 0;
$prev_student   = $students[$active_idx - 1] ?? null;
$next_student   = $students[$active_idx + 1] ?? null;

// ── Completion tracking: which students have comments saved ───────────────────
$completed_ids = [];
if (!empty($student_ids)) {
    try {
        $ph   = implode(',', array_fill(0, count($student_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT DISTINCT student_id FROM student_comments
             WHERE school_id = ? AND session = ? AND term = ? AND student_id IN ($ph)
        ");
        $stmt->execute(array_merge([$school_id, $session, $term], $student_ids));
        $completed_ids = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) { /* non-fatal */
    }
}
$completed_count = count($completed_ids);

// ── Load saved data for active student ───────────────────────────────────────
$saved_affective  = [];
$saved_psychomotor = [];
$saved_comments   = [];
$saved_position   = [];

// Calculate student's overall grade for auto principal comment
$student_grade = '';
$student_average = 0;
$auto_principal_comment = '';

if ($active_sid) {
    try {
        // Affective traits
        $stmt = $pdo->prepare("
            SELECT * FROM affective_traits
             WHERE student_id=? AND session=? AND term=? LIMIT 1
        ");
        $stmt->execute([$active_sid, $session, $term]);
        $saved_affective = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Psychomotor
        $stmt = $pdo->prepare("
            SELECT * FROM psychomotor_skills
             WHERE school_id=? AND student_id=? AND session=? AND term=? LIMIT 1
        ");
        $stmt->execute([$school_id, $active_sid, $session, $term]);
        $saved_psychomotor = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Comments & attendance
        $stmt = $pdo->prepare("
            SELECT * FROM student_comments
             WHERE school_id=? AND student_id=? AND session=? AND term=? LIMIT 1
        ");
        $stmt->execute([$school_id, $active_sid, $session, $term]);
        $saved_comments = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Position (for promoted_to)
        $stmt = $pdo->prepare("
            SELECT * FROM student_positions
             WHERE school_id=? AND student_id=? AND session=? AND term=? LIMIT 1
        ");
        $stmt->execute([$school_id, $active_sid, $session, $term]);
        $saved_position = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Get student's average for auto principal comment
        if ($saved_position && $saved_position['average'] > 0) {
            $student_average = floatval($saved_position['average']);
        } else {
            // Calculate from student_scores if not in positions
            $stmt = $pdo->prepare("
                SELECT AVG(percentage) as avg_score FROM student_scores 
                WHERE school_id=? AND student_id=? AND session=? AND term=?
            ");
            $stmt->execute([$school_id, $active_sid, $session, $term]);
            $avg_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $student_average = floatval($avg_data['avg_score'] ?? 0);
        }

        // Get grading scale from record
        $grading_data = [];
        if (!empty($record['score_types'])) {
            $decoded = json_decode($record['score_types'], true);
            $grading_data = $decoded['grading_scale'] ?? [];
        }

        // Fallback grading if none found
        if (empty($grading_data)) {
            $grading_data = [
                ['grade' => 'A', 'min' => 75, 'max' => 100],
                ['grade' => 'B', 'min' => 65, 'max' => 74],
                ['grade' => 'C', 'min' => 50, 'max' => 64],
                ['grade' => 'D', 'min' => 40, 'max' => 49],
                ['grade' => 'F', 'min' => 0, 'max' => 39],
            ];
        }

        // Determine grade based on average
        foreach ($grading_data as $grade_range) {
            if ($student_average >= $grade_range['min'] && $student_average <= $grade_range['max']) {
                $student_grade = $grade_range['grade'];
                break;
            }
        }

        // Get auto principal comment based on grade
        if ($student_grade && isset($principal_comments_map[$student_grade])) {
            $auto_principal_comment = $principal_comments_map[$student_grade];
        }
    } catch (Exception $e) {
        error_log("traits load: " . $e->getMessage());
    }
}

// ── Trait definitions ─────────────────────────────────────────────────────────
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

$rating_labels = ['A' => 'Excellent', 'B' => 'Very Good', 'C' => 'Good', 'D' => 'Fair', 'E' => 'Poor'];

// ── Next class options (for promoted_to) ──────────────────────────────────────
$classes_list = [];
try {
    $stmt = $pdo->prepare("SELECT class_name FROM classes WHERE school_id=? AND status='active' ORDER BY sort_order, class_name");
    $stmt->execute([$school_id]);
    $classes_list = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { /* non-fatal */
}

// ── Handle POST: save traits, comments, attendance, promoted_to ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_traits') {

    $post_sid = (int)($_POST['student_id'] ?? 0);
    if (!$post_sid) {
        $error_msg = "Invalid student.";
        goto render;
    }

    $pdo->beginTransaction();
    try {
        // ── 1. Affective traits ───────────────────────────────────────────────
        $af = [];
        foreach (array_keys($affective_fields) as $f) {
            $val = $_POST['affective'][$f] ?? null;
            $af[$f] = in_array($val, ['A', 'B', 'C', 'D', 'E']) ? $val : null;
        }

        $chk = $pdo->prepare("SELECT id FROM affective_traits WHERE student_id=? AND session=? AND term=? LIMIT 1");
        $chk->execute([$post_sid, $session, $term]);
        $af_id = $chk->fetchColumn();

        if ($af_id) {
            $pdo->prepare("
                UPDATE affective_traits SET
                    punctuality=?,attendance=?,politeness=?,honesty=?,
                    neatness=?,reliability=?,relationship=?,self_control=?,
                    updated_at=NOW()
                WHERE id=?
            ")->execute([
                $af['punctuality'],
                $af['attendance'],
                $af['politeness'],
                $af['honesty'],
                $af['neatness'],
                $af['reliability'],
                $af['relationship'],
                $af['self_control'],
                $af_id
            ]);
        } else {
            $pdo->prepare("
                INSERT INTO affective_traits
                    (school_id,student_id,session,term,punctuality,attendance,politeness,honesty,
                     neatness,reliability,relationship,self_control,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
            ")->execute([
                $school_id,
                $post_sid,
                $session,
                $term,
                $af['punctuality'],
                $af['attendance'],
                $af['politeness'],
                $af['honesty'],
                $af['neatness'],
                $af['reliability'],
                $af['relationship'],
                $af['self_control'],
            ]);
        }

        // ── 2. Psychomotor skills ─────────────────────────────────────────────
        $pm = [];
        foreach (array_keys($psychomotor_fields) as $f) {
            $val = $_POST['psychomotor'][$f] ?? null;
            $pm[$f] = in_array($val, ['A', 'B', 'C', 'D', 'E']) ? $val : null;
        }

        $chk2 = $pdo->prepare("SELECT id FROM psychomotor_skills WHERE school_id=? AND student_id=? AND session=? AND term=? LIMIT 1");
        $chk2->execute([$school_id, $post_sid, $session, $term]);
        $pm_id = $chk2->fetchColumn();

        if ($pm_id) {
            $pdo->prepare("
                UPDATE psychomotor_skills SET
                    handwriting=?,verbal_fluency=?,sports=?,
                    handling_tools=?,drawing_painting=?,musical_skills=?,
                    updated_at=NOW()
                WHERE id=?
            ")->execute([
                $pm['handwriting'],
                $pm['verbal_fluency'],
                $pm['sports'],
                $pm['handling_tools'],
                $pm['drawing_painting'],
                $pm['musical_skills'],
                $pm_id
            ]);
        } else {
            $pdo->prepare("
                INSERT INTO psychomotor_skills
                    (school_id,student_id,session,term,handwriting,verbal_fluency,sports,
                     handling_tools,drawing_painting,musical_skills,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
            ")->execute([
                $school_id,
                $post_sid,
                $session,
                $term,
                $pm['handwriting'],
                $pm['verbal_fluency'],
                $pm['sports'],
                $pm['handling_tools'],
                $pm['drawing_painting'],
                $pm['musical_skills'],
            ]);
        }

        // ── 3. Comments & attendance (FIXED - correct number of parameters) ──────────────────────────────────────────
        $days_opened = (int)($record['days_school_opened'] ?? 90);
        $days_present = min((int)($_POST['days_present'] ?? 0), $days_opened);
        $days_absent  = $days_opened - $days_present;

        $tc   = trim($_POST['teachers_comment']    ?? '');
        $pc   = trim($_POST['principals_comment']  ?? '');
        $tcn  = trim($_POST['class_teachers_name'] ?? '');
        $pcn  = trim($_POST['principals_name']     ?? '');

        $chk3 = $pdo->prepare("SELECT id FROM student_comments WHERE school_id=? AND student_id=? AND session=? AND term=? LIMIT 1");
        $chk3->execute([$school_id, $post_sid, $session, $term]);
        $cm_id = $chk3->fetchColumn();

        if ($cm_id) {
            // UPDATE query - 8 parameters
            $updateComments = $pdo->prepare("
                UPDATE student_comments SET
                    teachers_comment=?,
                    principals_comment=?,
                    class_teachers_name=?,
                    principals_name=?,
                    days_present=?,
                    days_absent=?,
                    updated_at=NOW()
                WHERE id=?
            ");
            $updateComments->execute([
                $tc,
                $pc,
                $tcn,
                $pcn,
                $days_present,
                $days_absent,
                $cm_id
            ]);
        } else {
            // INSERT query - 10 parameters exactly matching columns
            $insertComments = $pdo->prepare("
                INSERT INTO student_comments
                    (school_id, student_id, session, term,
                     teachers_comment, principals_comment,
                     class_teachers_name, principals_name,
                     days_present, days_absent, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $insertComments->execute([
                $school_id,     // 1
                $post_sid,      // 2
                $session,       // 3
                $term,          // 4
                $tc,            // 5
                $pc,            // 6
                $tcn,           // 7
                $pcn,           // 8
                $days_present,  // 9
                $days_absent    // 10
            ]);
        }

        // ── 4. Promoted to (stored in student_positions) (FIXED) ──────────────────────
        $promoted_to = trim($_POST['promoted_to'] ?? '');

        $chk4 = $pdo->prepare("SELECT id FROM student_positions WHERE school_id=? AND student_id=? AND session=? AND term=? LIMIT 1");
        $chk4->execute([$school_id, $post_sid, $session, $term]);
        $sp_id = $chk4->fetchColumn();

        if ($sp_id) {
            $updatePositions = $pdo->prepare("
                UPDATE student_positions SET promoted_to=?, updated_at=NOW() WHERE id=?
            ");
            $updatePositions->execute([$promoted_to ?: null, $sp_id]);
        } else {
            $insertPositions = $pdo->prepare("
                INSERT INTO student_positions
                    (school_id, student_id, session, term, promoted_to, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $insertPositions->execute([
                $school_id,
                $post_sid,
                $session,
                $term,
                $promoted_to ?: null
            ]);
        }

        // ── Mark exam record active if still draft ────────────────────────────
        if (($record['status'] ?? 'draft') === 'draft') {
            $pdo->prepare("UPDATE report_card_settings SET status='active' WHERE id=?")->execute([$record_id]);
            $record['status'] = 'active';
        }

        $pdo->commit();

        // Activity log
        try {
            $sname = $active_student['full_name'] ?? "student #{$post_sid}";
            $pdo->prepare("INSERT INTO activity_logs (user_id,user_type,activity,school_id) VALUES (?,?,?,?)")
                ->execute([$admin_id, 'admin', "Saved traits & comments for {$sname} — {$class} {$term} Term {$session}", $school_id]);
        } catch (Exception $e) { /* skip */
        }

        // Redirect to next student automatically, or stay if last
        if ($next_student) {
            $_SESSION['flash_success'] = "Saved for " . ($active_student['full_name'] ?? '') . ". Now viewing next student.";
            header("Location: exam_traits_comments.php?record_id={$record_id}&student_id={$next_student['id']}");
        } else {
            $_SESSION['flash_success'] = "All done! Traits & comments saved for " . ($active_student['full_name'] ?? '') . ".";
            header("Location: exam_traits_comments.php?record_id={$record_id}&student_id={$active_sid}");
        }
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("traits save error: " . $e->getMessage());
        error_log("traits save trace: " . $e->getTraceAsString());
        $error_msg = "Error saving: " . htmlspecialchars($e->getMessage());
    }
}

render:
$progress_pct = $total_students > 0 ? round(($completed_count / $total_students) * 100) : 0;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($school_name); ?> — Traits &amp; Comments</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: <?php echo $primary_color; ?>;
            --secondary: <?php echo $secondary_color; ?>;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --light: #ecf0f1;
            --dark: #2c3e50;
            --sidebar-w: 260px;
            --shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            --radius: 10px;
            --transition: all 0.25s ease;
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

        /* Mobile Menu Toggle - Consistent with sidebar.php */
        .mobile-menu-toggle {
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
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .sidebar-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .main {
            min-height: 100vh;
            padding: 20px;
            transition: var(--transition);
        }

        /* Top header */
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

        /* Step bar */
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

        /* Alerts */
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

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid #17a2b8;
        }

        .alert i {
            flex-shrink: 0;
            margin-top: 2px;
        }

        /* Stat bar */
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

        /* Progress */
        .progress-wrap {
            background: white;
            border-radius: var(--radius);
            padding: 14px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: .82rem;
            color: #555;
            margin-bottom: 8px;
        }

        .progress-bar {
            height: 8px;
            background: var(--light);
            border-radius: 20px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 20px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            transition: width .4s;
        }

        /* Two-col layout */
        .grid {
            display: grid;
            grid-template-columns: 230px 1fr;
            gap: 20px;
            align-items: start;
        }

        /* Student list panel */
        .student-panel {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            position: sticky;
            top: 20px;
        }

        .student-panel-hdr {
            background: var(--primary);
            color: white;
            padding: 14px 16px;
            font-size: .88rem;
            font-weight: 600;
        }

        .student-list {
            list-style: none;
            padding: 6px 0;
            max-height: 68vh;
            overflow-y: auto;
        }

        .student-list li a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 16px;
            color: #333;
            text-decoration: none;
            font-size: .82rem;
            transition: background .15s;
        }

        .student-list li a:hover {
            background: #f5f6fa;
        }

        .student-list li a.active {
            background: #eef3ff;
            color: var(--primary);
            border-left: 3px solid var(--primary);
            font-weight: 600;
        }

        .s-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            font-size: 11px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .s-done-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--success);
            flex-shrink: 0;
            margin-left: auto;
        }

        .s-pend-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--light);
            border: 1px solid #ccc;
            flex-shrink: 0;
            margin-left: auto;
        }

        .panel-footer {
            font-size: .72rem;
            color: #888;
            padding: 8px 16px;
            border-top: 1px solid var(--light);
        }

        /* Form card */
        .form-card {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 16px;
        }

        .card-hdr {
            padding: 14px 20px;
            border-bottom: 2px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .card-hdr h2 {
            color: var(--primary);
            font-size: .95rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-hdr h2 i {
            font-size: .9rem;
        }

        .card-body {
            padding: 20px;
        }

        /* Student quick info */
        .stu-info-bar {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 14px 20px;
            background: #f9f9f9;
            border-bottom: 1px solid var(--light);
        }

        .stu-big-avatar {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            font-size: 18px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .stu-meta h3 {
            font-size: .95rem;
            font-weight: 600;
            color: #333;
        }

        .stu-meta p {
            font-size: .78rem;
            color: #888;
        }

        .nav-btns {
            margin-left: auto;
            display: flex;
            gap: 8px;
        }

        /* Trait rating buttons */
        .traits-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .trait-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 9px 12px;
            background: #f9f9f9;
            border-radius: 8px;
        }

        .trait-name {
            font-size: .82rem;
            color: #444;
            flex: 1;
        }

        .rating-group {
            display: flex;
            gap: 4px;
        }

        .rating-btn {
            width: 32px;
            height: 28px;
            border: 1.5px solid #ddd;
            background: white;
            border-radius: 6px;
            font-size: .78rem;
            font-weight: 600;
            cursor: pointer;
            color: #999;
            transition: var(--transition);
            font-family: 'Poppins', sans-serif;
        }

        .rating-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .rating-btn.sel-A {
            background: #d4edda;
            border-color: #27ae60;
            color: #155724;
        }

        .rating-btn.sel-B {
            background: #cce5ff;
            border-color: #3498db;
            color: #004085;
        }

        .rating-btn.sel-C {
            background: #fff3cd;
            border-color: #f39c12;
            color: #856404;
        }

        .rating-btn.sel-D {
            background: #ffe5cc;
            border-color: #e67e22;
            color: #7d4000;
        }

        .rating-btn.sel-E {
            background: #f8d7da;
            border-color: #e74c3c;
            color: #721c24;
        }

        .rating-group input[type="radio"] {
            display: none;
        }

        .rating-legend {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            padding: 10px 20px;
            background: #f9f9f9;
            border-top: 1px solid var(--light);
            font-size: .74rem;
            color: #777;
        }

        .rating-legend span {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .leg-dot {
            width: 10px;
            height: 10px;
            border-radius: 3px;
        }

        /* Form elements */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 14px;
        }

        .form-row.full {
            grid-template-columns: 1fr;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        label.fg-lbl {
            font-size: .8rem;
            font-weight: 500;
            color: #555;
        }

        input[type="text"],
        input[type="number"],
        select,
        textarea {
            padding: 9px 12px;
            border: 1.5px solid #e0e0e0;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: .86rem;
            color: #333;
            background: #fafafa;
            width: 100%;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
        }

        textarea {
            resize: vertical;
            min-height: 70px;
        }

        .attend-hint {
            font-size: .76rem;
            color: var(--warning);
            margin-top: 4px;
        }

        .attend-hint.ok {
            color: var(--success);
        }

        /* Buttons */
        .btn {
            padding: 9px 18px;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: .85rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none;
            transition: var(--transition);
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            opacity: .9;
        }

        .btn-secondary {
            background: white;
            color: var(--primary);
            border: 1.5px solid var(--primary);
        }

        .btn-secondary:hover {
            background: var(--primary);
            color: white;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: .78rem;
        }

        /* Action bar */
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            padding: 14px 20px;
            border-top: 2px solid var(--light);
            background: white;
            border-radius: 0 0 var(--radius) var(--radius);
        }

        .action-bar .left {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .action-bar .right {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 14px;
            opacity: .3;
            display: block;
        }

        .suggestion-box {
            padding: 10px 12px;
            margin-bottom: 10px;
            background: #e8f4f8;
            border-radius: 8px;
            border-left: 3px solid #17a2b8;
            font-size: .85rem;
        }

        .suggestion-box i {
            color: #17a2b8;
            margin-right: 6px;
        }

        /* Desktop Styles */
        @media (min-width: 768px) {

            .mobile-menu-toggle,
            .sidebar-overlay {
                display: none;
            }

            .main {
                margin-left: var(--sidebar-w);
            }
        }

        @media (max-width: 960px) {
            .grid {
                grid-template-columns: 1fr;
            }

            .student-panel {
                position: static;
            }

            .student-list {
                max-height: none;
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            }
        }

        @media (max-width: 767px) {
            .main {
                padding-top: 70px;
            }

            .traits-grid {
                grid-template-columns: 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .stats-row {
                grid-template-columns: 1fr 1fr;
            }
        }
    </style>
</head>

<body>

    <!-- Mobile Menu Toggle - Consistent ID with sidebar.php -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <?php
    // Include sidebar (it will be positioned fixed)
    require_once 'includes/sidebar.php';
    ?>

    <!-- Main -->
    <div class="main" id="mainContent">

        <div class="top-header">
            <div>
                <h1>Traits, Comments &amp; Attendance</h1>
                <p>
                    <strong><?php echo htmlspecialchars($record['record_name'] ?? "{$session} {$term} Term"); ?></strong>
                    &nbsp;·&nbsp; <?php echo htmlspecialchars($class); ?>
                    &nbsp;·&nbsp; <?php echo htmlspecialchars($session); ?>
                    &nbsp;·&nbsp; <?php echo htmlspecialchars($term); ?> Term
                </p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="exam_score_entry.php?record_id=<?php echo $record_id; ?>" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to scores
                </a>
                <a href="exam_record_setup.php" class="back-btn">
                    <i class="fas fa-list"></i> All records
                </a>
            </div>
        </div>

        <?php if ($success_msg): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars($success_msg); ?></span></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i><span><?php echo $error_msg; ?></span></div>
        <?php endif; ?>

        <!-- Step bar -->
        <div class="step-bar">
            <div class="step-item">
                <div class="step-circle s-done"><i class="fas fa-check" style="font-size:10px"></i></div>
                <div class="step-lbl">Setup record</div>
            </div>
            <div class="step-item">
                <div class="step-circle s-done"><i class="fas fa-check" style="font-size:10px"></i></div>
                <div class="step-lbl">Enter scores</div>
            </div>
            <div class="step-item">
                <div class="step-circle s-cur">3</div>
                <div class="step-lbl">Traits &amp; comments</div>
            </div>
            <div class="step-item">
                <div class="step-circle s-todo">4</div>
                <div class="step-lbl">Generate cards</div>
            </div>
            <div class="step-item">
                <div class="step-circle s-todo">5</div>
                <div class="step-lbl">Publish</div>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-box">
                <div class="stat-val"><?php echo $total_students; ?></div>
                <div class="stat-lbl">Total students</div>
            </div>
            <div class="stat-box green">
                <div class="stat-val"><?php echo $completed_count; ?></div>
                <div class="stat-lbl">Records completed</div>
            </div>
            <div class="stat-box amber">
                <div class="stat-val"><?php echo $total_students - $completed_count; ?></div>
                <div class="stat-lbl">Pending</div>
            </div>
            <div class="stat-box">
                <div class="stat-val"><?php echo (int)($record['days_school_opened'] ?? 0); ?></div>
                <div class="stat-lbl">School days (term)</div>
            </div>
        </div>

        <!-- Progress -->
        <div class="progress-wrap">
            <div class="progress-label"><span>Student records progress</span><span><?php echo $completed_count; ?> / <?php echo $total_students; ?> completed &nbsp;·&nbsp; <?php echo $progress_pct; ?>%</span></div>
            <div class="progress-bar">
                <div class="progress-fill" style="width:<?php echo $progress_pct; ?>%"></div>
            </div>
        </div>

        <?php if (empty($students)): ?>
            <div class="form-card">
                <div class="empty-state"><i class="fas fa-user-graduate"></i>
                    <h3>No active students in <?php echo htmlspecialchars($class); ?></h3>
                </div>
            </div>
        <?php else: ?>

            <div class="grid">

                <!-- Student list panel -->
                <div class="student-panel">
                    <div class="student-panel-hdr"><i class="fas fa-users"></i> Students <span style="font-weight:400;font-size:.78rem;opacity:.8;margin-left:6px">(<?php echo $completed_count; ?>/<?php echo $total_students; ?>)</span></div>
                    <ul class="student-list">
                        <?php foreach ($students as $i => $s):
                            $s_id    = (int)$s['id'];
                            $is_cur  = $s_id === $active_sid;
                            $is_done = isset($completed_ids[$s_id]);
                            $words   = array_filter(explode(' ', $s['full_name']));
                            $inits   = strtoupper(implode('', array_map(fn($w) => $w[0], array_slice($words, 0, 2))));
                        ?>
                            <li><a href="exam_traits_comments.php?record_id=<?php echo $record_id; ?>&student_id=<?php echo $s_id; ?>" class="<?php echo $is_cur ? 'active' : ''; ?>">
                                    <div class="s-avatar"><?php echo $inits; ?></div><span style="flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($s['full_name']); ?></span><span class="<?php echo $is_done ? 's-done-dot' : 's-pend-dot'; ?>" title="<?php echo $is_done ? 'Done' : 'Pending'; ?>"></span>
                                </a></li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="panel-footer"><span style="color:var(--success)">● <?php echo $completed_count; ?> done</span> &nbsp;&nbsp; <span style="color:#ccc">● <?php echo $total_students - $completed_count; ?> pending</span></div>
                </div>

                <!-- Right: form for active student -->
                <div>
                    <?php if ($active_student): ?>

                        <form method="POST" id="traitsForm">
                            <input type="hidden" name="action" value="save_traits">
                            <input type="hidden" name="student_id" value="<?php echo $active_sid; ?>">

                            <!-- Student info bar -->
                            <div class="form-card">
                                <div class="stu-info-bar">
                                    <?php
                                    $words = array_filter(explode(' ', $active_student['full_name']));
                                    $inits = strtoupper(implode('', array_map(fn($w) => $w[0], array_slice($words, 0, 2))));
                                    ?>
                                    <div class="stu-big-avatar"><?php echo $inits; ?></div>
                                    <div class="stu-meta">
                                        <h3><?php echo htmlspecialchars($active_student['full_name']); ?></h3>
                                        <p><?php echo htmlspecialchars($active_student['admission_number']); ?> &nbsp;·&nbsp; <?php echo htmlspecialchars($class); ?><?php if ($active_student['gender']): ?>&nbsp;·&nbsp; <?php echo htmlspecialchars($active_student['gender']); ?><?php endif; ?> &nbsp;·&nbsp; Student <?php echo $active_idx + 1; ?> of <?php echo $total_students; ?></p>
                                    </div>
                                    <div class="nav-btns">
                                        <?php if ($prev_student): ?><a href="exam_traits_comments.php?record_id=<?php echo $record_id; ?>&student_id=<?php echo $prev_student['id']; ?>" class="btn btn-secondary btn-sm"><i class="fas fa-chevron-left"></i> Prev</a><?php endif; ?>
                                        <?php if ($next_student): ?><a href="exam_traits_comments.php?record_id=<?php echo $record_id; ?>&student_id=<?php echo $next_student['id']; ?>" class="btn btn-secondary btn-sm">Next <i class="fas fa-chevron-right"></i></a><?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- ① Affective traits -->
                            <div class="form-card">
                                <div class="card-hdr">
                                    <h2><i class="fas fa-heart"></i> Affective Traits</h2><button type="button" class="btn btn-secondary btn-sm" onclick="clearRatings('affective')"><i class="fas fa-undo"></i> Clear</button>
                                </div>
                                <div class="card-body">
                                    <div class="traits-grid">
                                        <?php foreach ($affective_fields as $field => $label):
                                            $current_val = $saved_affective[$field] ?? null;
                                        ?>
                                            <div class="trait-row">
                                                <span class="trait-name"><?php echo htmlspecialchars($label); ?></span>
                                                <div class="rating-group" data-field="affective[<?php echo $field; ?>]">
                                                    <?php foreach ($rating_labels as $grade => $desc): ?>
                                                        <input type="radio" name="affective[<?php echo $field; ?>]" id="af_<?php echo $field; ?>_<?php echo $grade; ?>" value="<?php echo $grade; ?>" <?php echo $current_val === $grade ? 'checked' : ''; ?>>
                                                        <button type="button" class="rating-btn <?php echo $current_val === $grade ? "sel-{$grade}" : ''; ?>" onclick="selectRating(this, 'af_<?php echo $field; ?>_<?php echo $grade; ?>')" title="<?php echo $desc; ?>" data-grade="<?php echo $grade; ?>"><?php echo $grade; ?></button>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="rating-legend">
                                    <?php foreach ($rating_labels as $g => $d): ?><span><span class="leg-dot" style="background:<?php echo ['A' => '#27ae60', 'B' => '#3498db', 'C' => '#f39c12', 'D' => '#e67e22', 'E' => '#e74c3c'][$g]; ?>"></span><?php echo $g . ': ' . $d; ?></span><?php endforeach; ?>
                                </div>
                            </div>

                            <!-- ② Psychomotor skills -->
                            <div class="form-card">
                                <div class="card-hdr">
                                    <h2><i class="fas fa-running"></i> Psychomotor Skills</h2><button type="button" class="btn btn-secondary btn-sm" onclick="clearRatings('psychomotor')"><i class="fas fa-undo"></i> Clear</button>
                                </div>
                                <div class="card-body">
                                    <div class="traits-grid">
                                        <?php foreach ($psychomotor_fields as $field => $label):
                                            $current_val = $saved_psychomotor[$field] ?? null;
                                        ?>
                                            <div class="trait-row">
                                                <span class="trait-name"><?php echo htmlspecialchars($label); ?></span>
                                                <div class="rating-group" data-field="psychomotor[<?php echo $field; ?>]">
                                                    <?php foreach ($rating_labels as $grade => $desc): ?>
                                                        <input type="radio" name="psychomotor[<?php echo $field; ?>]" id="pm_<?php echo $field; ?>_<?php echo $grade; ?>" value="<?php echo $grade; ?>" <?php echo $current_val === $grade ? 'checked' : ''; ?>>
                                                        <button type="button" class="rating-btn <?php echo $current_val === $grade ? "sel-{$grade}" : ''; ?>" onclick="selectRating(this, 'pm_<?php echo $field; ?>_<?php echo $grade; ?>')" title="<?php echo $desc; ?>" data-grade="<?php echo $grade; ?>"><?php echo $grade; ?></button>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="rating-legend">
                                    <?php foreach ($rating_labels as $g => $d): ?><span><span class="leg-dot" style="background:<?php echo ['A' => '#27ae60', 'B' => '#3498db', 'C' => '#f39c12', 'D' => '#e67e22', 'E' => '#e74c3c'][$g]; ?>"></span><?php echo $g . ': ' . $d; ?></span><?php endforeach; ?>
                                </div>
                            </div>

                            <!-- ③ Attendance -->
                            <div class="form-card">
                                <div class="card-hdr">
                                    <h2><i class="fas fa-calendar-check"></i> Attendance</h2>
                                </div>
                                <div class="card-body">
                                    <div class="form-row">
                                        <div class="form-group"><label class="fg-lbl">Days school opened (term total)</label><input type="number" value="<?php echo (int)($record['days_school_opened'] ?? 0); ?>" readonly style="background:#f0f0f0;color:#888;"></div>
                                        <div class="form-group"><label class="fg-lbl">Days student was present <span style="color:red">*</span></label><input type="number" name="days_present" id="daysPresent" min="0" max="<?php echo (int)($record['days_school_opened'] ?? 365); ?>" value="<?php echo (int)($saved_comments['days_present'] ?? 0); ?>" oninput="calcAbsent(this)" required>
                                            <div class="attend-hint" id="attendHint"><?php $dp = (int)($saved_comments['days_present'] ?? 0);
                                                                                        $da = (int)($record['days_school_opened'] ?? 0) - $dp;
                                                                                        echo "Days absent: <strong>{$da}</strong>"; ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ④ Comments & promotions -->
                            <div class="form-card">
                                <div class="card-hdr">
                                    <h2><i class="fas fa-comment-dots"></i> Comments &amp; Next Class</h2>
                                </div>
                                <div class="card-body">
                                    <div class="form-row">
                                        <div class="form-group"><label class="fg-lbl">Class teacher's name</label><input type="text" name="class_teachers_name" placeholder="e.g. Mrs. Okonkwo Ngozi" value="<?php echo htmlspecialchars($saved_comments['class_teachers_name'] ?? $default_class_teacher); ?>"><?php if ($default_class_teacher): ?><small style="color:#666; font-size:0.7rem;">Default from setup: <?php echo htmlspecialchars($default_class_teacher); ?></small><?php endif; ?></div>
                                        <div class="form-group"><label class="fg-lbl">Principal's name</label><input type="text" name="principals_name" placeholder="e.g. Mr. Adamu Bello" value="<?php echo htmlspecialchars($saved_comments['principals_name'] ?? ''); ?>"></div>
                                    </div>
                                    <div class="form-row full" style="margin-bottom:14px;">
                                        <div class="form-group"><label class="fg-lbl">Class teacher's comment</label><textarea name="teachers_comment" placeholder="Write a personalised comment about this student's performance and conduct this term..."><?php echo htmlspecialchars($saved_comments['teachers_comment'] ?? ''); ?></textarea></div>
                                    </div>
                                    <div class="form-row full" style="margin-bottom:14px;">
                                        <div class="form-group"><label class="fg-lbl">Principal's comment</label><?php if ($student_grade && $auto_principal_comment): ?><div class="suggestion-box"><i class="fas fa-magic"></i><strong>Auto-suggested for grade <?php echo htmlspecialchars($student_grade); ?></strong> (Avg: <?php echo number_format($student_average, 1); ?>%): <em>"<?php echo htmlspecialchars($auto_principal_comment); ?>"</em><button type="button" onclick="useSuggestedComment()" class="btn btn-secondary btn-sm" style="margin-left:8px; padding:3px 10px;"><i class="fas fa-check"></i> Use this</button></div><?php endif; ?><textarea name="principals_comment" id="principalsComment" placeholder="Principal's comment will be auto-generated based on student's grade..." rows="3"><?php echo htmlspecialchars($saved_comments['principals_comment'] ?? $auto_principal_comment); ?></textarea><small style="color:#666; font-size:0.7rem;"><i class="fas fa-info-circle"></i> The system suggests a comment based on the student's grade. You can edit or keep it.</small></div>
                                    </div>
                                    <?php if ((int)($record['show_promoted_to'] ?? 1)): ?><div class="form-row">
                                            <div class="form-group"><label class="fg-lbl">Promoted to (next class)</label><select name="promoted_to">
                                                    <option value="">— Select next class —</option>
                                                    <option value="Repeat" <?php echo ($saved_position['promoted_to'] ?? '') === 'Repeat' ? 'selected' : ''; ?>>Repeat current class</option><?php foreach ($classes_list as $cl): ?><option value="<?php echo htmlspecialchars($cl); ?>" <?php echo ($saved_position['promoted_to'] ?? '') === $cl ? 'selected' : ''; ?>><?php echo htmlspecialchars($cl); ?></option><?php endforeach; ?>
                                                </select></div>
                                            <div class="form-group" style="align-self:end;">
                                                <div style="padding:10px 12px;background:#f0fff4;border-radius:8px;font-size:.82rem;color:var(--success);border:1px solid #b2dfdb;"><i class="fas fa-info-circle"></i> This will appear on the student's report card.</div>
                                            </div>
                                        </div><?php endif; ?>
                                </div>
                                <div class="action-bar">
                                    <div class="left"><?php if ($prev_student): ?><a href="exam_traits_comments.php?record_id=<?php echo $record_id; ?>&student_id=<?php echo $prev_student['id']; ?>" class="btn btn-secondary btn-sm"><i class="fas fa-chevron-left"></i> Previous student</a><?php endif; ?></div>
                                    <div class="right">
                                        <?php if ($next_student): ?>
                                            <a href="exam_traits_comments.php?record_id=<?php echo $record_id; ?>&student_id=<?php echo $next_student['id']; ?>" class="btn btn-secondary" title="Skip this student without saving"><i class="fas fa-forward"></i> Skip</a>
                                        <?php else: ?>
                                            <a href="exam_traits_comments.php?record_id=<?php echo $record_id; ?>&student_id=<?php echo $active_sid; ?>" class="btn btn-secondary" title="Skip without saving"><i class="fas fa-forward"></i> Skip</a>
                                        <?php endif; ?>
                                        <button type="submit" class="btn btn-primary" id="saveNextBtn"><?php if ($next_student): ?><i class="fas fa-save"></i> Save &amp; next student <i class="fas fa-chevron-right"></i><?php else: ?><i class="fas fa-check-circle"></i> Save &amp; finish<?php endif; ?></button>
                                    </div>
                                </div>
                            </div>
                        </form>

                        <?php if ($completed_count >= $total_students): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i>
                                <div><strong>All <?php echo $total_students; ?> students done!</strong> Proceed to generate report cards.<br><a href="exam_generate_cards.php?record_id=<?php echo $record_id; ?>" class="btn btn-success btn-sm" style="margin-top:8px"><i class="fas fa-file-alt"></i> Generate report cards</a></div>
                            </div><?php endif; ?>

                    <?php else: ?>
                        <div class="form-card">
                            <div class="empty-state"><i class="fas fa-hand-point-left"></i>
                                <h3>Select a student from the list</h3>
                            </div>
                        </div>
                    <?php endif; ?>
                </div><!-- /right -->
            </div><!-- /grid -->
        <?php endif; ?>

        <div style="text-align:center;padding:20px;color:#999;font-size:.8rem;border-top:1px solid var(--light);margin-top:20px">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> — Online Portal</div>
    </div><!-- /main -->

    <script>
        // Rating button click
        function selectRating(btn, radioId) {
            const group = btn.closest('.rating-group');
            const grade = btn.dataset.grade;
            const radio = document.getElementById(radioId);
            if (radio.checked) {
                radio.checked = false;
                group.querySelectorAll('.rating-btn').forEach(b => b.className = 'rating-btn');
                return;
            }
            radio.checked = true;
            group.querySelectorAll('.rating-btn').forEach(b => b.className = 'rating-btn');
            btn.className = `rating-btn sel-${grade}`;
        }

        // Clear all ratings in a section
        function clearRatings(section) {
            document.querySelectorAll(`input[name^="${section}["]`).forEach(r => r.checked = false);
            document.querySelectorAll(`.rating-group[data-field^="${section}"] .rating-btn`).forEach(b => b.className = 'rating-btn');
        }

        // Attendance: compute absent
        const daysOpened = <?php echo (int)($record['days_school_opened'] ?? 0); ?>;

        function calcAbsent(inp) {
            const present = parseInt(inp.value) || 0;
            const absent = daysOpened - present;
            const hint = document.getElementById('attendHint');
            hint.innerHTML = `Days absent: <strong>${absent < 0 ? '⚠ Exceeds school days!' : absent}</strong>`;
            hint.className = `attend-hint${absent < 0 ? '' : ' ok'}`;
        }

        // Auto-fill principal's suggested comment
        function useSuggestedComment() {
            const suggestionBox = document.querySelector('.suggestion-box');
            if (suggestionBox) {
                const em = suggestionBox.querySelector('em');
                if (em) {
                    const suggestedText = em.innerText.replace(/"/g, '');
                    document.getElementById('principalsComment').value = suggestedText;
                }
            }
        }

        // Form submit guard
        document.getElementById('traitsForm')?.addEventListener('submit', function(e) {
            const dp = parseInt(document.getElementById('daysPresent')?.value) || 0;
            if (dp > daysOpened) {
                e.preventDefault();
                alert(`Days present (${dp}) cannot exceed days school opened (${daysOpened}).`);
                return;
            }
            const submitBtn = document.getElementById('saveNextBtn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            }
        });

        // ── Mobile Menu Toggle (Fixed) ──────────────────────────────────────────
        (function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const toggleBtn = document.getElementById('mobileMenuToggle');

            if (toggleBtn && sidebar) {
                toggleBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    sidebar.classList.toggle('active');
                    if (overlay) overlay.classList.toggle('active');
                    document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
                });
            }

            if (overlay) {
                overlay.addEventListener('click', function() {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                    document.body.style.overflow = '';
                });
            }

            // Close sidebar when clicking nav links on mobile
            document.querySelectorAll('.nav-item, .nav-group-toggle, .nav-group-items a').forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 768 && sidebar) {
                        sidebar.classList.remove('active');
                        if (overlay) overlay.classList.remove('active');
                        document.body.style.overflow = '';
                    }
                });
            });
        })();
    </script>

</body>

</html>
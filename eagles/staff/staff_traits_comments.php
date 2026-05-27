<?php
// eagles/staff/staff_traits_comments.php - Staff Traits, Comments & Attendance (Only assigned subjects)
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: /eagles/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id       = SCHOOL_ID;
$school_name     = SCHOOL_NAME;
$primary_color   = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;
$staff_id        = $_SESSION['user_id'];
$staff_name      = $_SESSION['user_name'] ?? 'Staff Member';
$staff_role      = $_SESSION['staff_role'] ?? 'staff';

// Get staff_id string from staff table
$stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
$stmt->execute([$staff_id, $school_id]);
$staff_id_string = $stmt->fetchColumn();

if (!$staff_id_string) {
    die("Staff record not found. Please contact administrator.");
}

// ── Require record_id ─────────────────────────────────────────────────────────
$record_id = isset($_GET['record_id']) ? (int)$_GET['record_id'] : 0;
if (!$record_id) {
    header("Location: index.php");
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
    header("Location: index.php");
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

// ── Get subjects assigned to this staff for this class ────────────────────────
$assigned_subjects = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.id, s.subject_name
          FROM subjects s
          JOIN staff_subjects ss ON ss.subject_id = s.id AND ss.school_id = ?
          JOIN subject_classes sc ON sc.subject_id = s.id AND sc.school_id = ?
         WHERE ss.staff_id = ? AND sc.class = ? AND (s.school_id = ? OR s.is_central = 1)
         ORDER BY s.subject_name ASC
    ");
    $stmt->execute([$school_id, $school_id, $staff_id_string, $class, $school_id]);
    $assigned_subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("staff_traits subjects: " . $e->getMessage());
}

// ── Load students ─────────────────────────────────────────────────────────────
$students = [];
try {
    // Get class_id from classes table
    $stmt = $pdo->prepare("SELECT id FROM classes WHERE class_name = ? AND school_id = ?");
    $stmt->execute([$class, $school_id]);
    $class_row = $stmt->fetch();
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
        error_log("Class not found: " . $class);
        $students = [];
    }
} catch (Exception $e) {
    error_log("staff_traits students: " . $e->getMessage());
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

// ── Completion tracking ───────────────────────────────────────────────────────
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
    } catch (Exception $e) { /* non-fatal */ }
}
$completed_count = count($completed_ids);
$progress_pct = $total_students > 0 ? round(($completed_count / $total_students) * 100) : 0;

// ── Load saved data for active student ───────────────────────────────────────
$saved_affective   = [];
$saved_psychomotor = [];
$saved_comments    = [];
$saved_position    = [];
$student_grade     = '';
$student_average   = 0;
$auto_principal_comment = '';

if ($active_sid) {
    try {
        // Affective traits
        $stmt = $pdo->prepare("SELECT * FROM affective_traits WHERE student_id=? AND session=? AND term=? LIMIT 1");
        $stmt->execute([$active_sid, $session, $term]);
        $saved_affective = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Psychomotor
        $stmt = $pdo->prepare("SELECT * FROM psychomotor_skills WHERE school_id=? AND student_id=? AND session=? AND term=? LIMIT 1");
        $stmt->execute([$school_id, $active_sid, $session, $term]);
        $saved_psychomotor = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Comments & attendance
        $stmt = $pdo->prepare("SELECT * FROM student_comments WHERE school_id=? AND student_id=? AND session=? AND term=? LIMIT 1");
        $stmt->execute([$school_id, $active_sid, $session, $term]);
        $saved_comments = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Position
        $stmt = $pdo->prepare("SELECT * FROM student_positions WHERE school_id=? AND student_id=? AND session=? AND term=? LIMIT 1");
        $stmt->execute([$school_id, $active_sid, $session, $term]);
        $saved_position = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Get student's average
        if ($saved_position && $saved_position['average'] > 0) {
            $student_average = floatval($saved_position['average']);
        } else {
            $stmt = $pdo->prepare("SELECT AVG(percentage) as avg_score FROM student_scores 
                                   WHERE school_id=? AND student_id=? AND session=? AND term=?");
            $stmt->execute([$school_id, $active_sid, $session, $term]);
            $avg_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $student_average = floatval($avg_data['avg_score'] ?? 0);
        }

        // Get grading scale
        $grading_data = [];
        if (!empty($record['score_types'])) {
            $decoded = json_decode($record['score_types'], true);
            $grading_data = $decoded['grading_scale'] ?? [];
        }
        if (empty($grading_data)) {
            $grading_data = [
                ['grade' => 'A', 'min' => 75, 'max' => 100],
                ['grade' => 'B', 'min' => 65, 'max' => 74],
                ['grade' => 'C', 'min' => 50, 'max' => 64],
                ['grade' => 'D', 'min' => 40, 'max' => 49],
                ['grade' => 'F', 'min' => 0, 'max' => 39],
            ];
        }

        foreach ($grading_data as $grade_range) {
            if ($student_average >= $grade_range['min'] && $student_average <= $grade_range['max']) {
                $student_grade = $grade_range['grade'];
                break;
            }
        }

        if ($student_grade && isset($principal_comments_map[$student_grade])) {
            $auto_principal_comment = $principal_comments_map[$student_grade];
        }
    } catch (Exception $e) {
        error_log("staff_traits load: " . $e->getMessage());
    }
}

// Trait definitions
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

// Next class options
$classes_list = [];
try {
    $stmt = $pdo->prepare("SELECT class_name FROM classes WHERE school_id=? AND status='active' ORDER BY sort_order, class_name");
    $stmt->execute([$school_id]);
    $classes_list = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { /* non-fatal */ }

// ── Handle POST: save traits, comments, attendance ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_traits') {
    $post_sid = (int)($_POST['student_id'] ?? 0);
    if (!$post_sid) {
        $error_msg = "Invalid student.";
        goto render;
    }

    $pdo->beginTransaction();
    try {
        // Affective traits
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
                $af['punctuality'], $af['attendance'], $af['politeness'], $af['honesty'],
                $af['neatness'], $af['reliability'], $af['relationship'], $af['self_control'],
                $af_id
            ]);
        } else {
            $pdo->prepare("
                INSERT INTO affective_traits
                    (school_id,student_id,session,term,punctuality,attendance,politeness,honesty,
                     neatness,reliability,relationship,self_control,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
            ")->execute([
                $school_id, $post_sid, $session, $term,
                $af['punctuality'], $af['attendance'], $af['politeness'], $af['honesty'],
                $af['neatness'], $af['reliability'], $af['relationship'], $af['self_control']
            ]);
        }

        // Psychomotor skills
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
                $pm['handwriting'], $pm['verbal_fluency'], $pm['sports'],
                $pm['handling_tools'], $pm['drawing_painting'], $pm['musical_skills'],
                $pm_id
            ]);
        } else {
            $pdo->prepare("
                INSERT INTO psychomotor_skills
                    (school_id,student_id,session,term,handwriting,verbal_fluency,sports,
                     handling_tools,drawing_painting,musical_skills,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
            ")->execute([
                $school_id, $post_sid, $session, $term,
                $pm['handwriting'], $pm['verbal_fluency'], $pm['sports'],
                $pm['handling_tools'], $pm['drawing_painting'], $pm['musical_skills']
            ]);
        }

        // Comments & attendance
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
            $pdo->prepare("
                UPDATE student_comments SET
                    teachers_comment=?, principals_comment=?,
                    class_teachers_name=?, principals_name=?,
                    days_present=?, days_absent=?, updated_at=NOW()
                WHERE id=?
            ")->execute([$tc, $pc, $tcn, $pcn, $days_present, $days_absent, $cm_id]);
        } else {
            $pdo->prepare("
                INSERT INTO student_comments
                    (school_id, student_id, session, term,
                     teachers_comment, principals_comment,
                     class_teachers_name, principals_name,
                     days_present, days_absent, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ")->execute([
                $school_id, $post_sid, $session, $term,
                $tc, $pc, $tcn, $pcn, $days_present, $days_absent
            ]);
        }

        // Promoted to
        $promoted_to = trim($_POST['promoted_to'] ?? '');
        $chk4 = $pdo->prepare("SELECT id FROM student_positions WHERE school_id=? AND student_id=? AND session=? AND term=? LIMIT 1");
        $chk4->execute([$school_id, $post_sid, $session, $term]);
        $sp_id = $chk4->fetchColumn();

        if ($sp_id) {
            $pdo->prepare("UPDATE student_positions SET promoted_to=?, updated_at=NOW() WHERE id=?")->execute([$promoted_to ?: null, $sp_id]);
        } else {
            $pdo->prepare("
                INSERT INTO student_positions
                    (school_id, student_id, session, term, promoted_to, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ")->execute([$school_id, $post_sid, $session, $term, $promoted_to ?: null]);
        }

        $pdo->commit();

        if ($next_student) {
            $_SESSION['flash_success'] = "Saved for " . ($active_student['full_name'] ?? '') . ". Now viewing next student.";
            header("Location: staff_traits_comments.php?record_id={$record_id}&student_id={$next_student['id']}");
        } else {
            $_SESSION['flash_success'] = "All done! Traits & comments saved.";
            header("Location: staff_traits_comments.php?record_id={$record_id}&student_id={$active_sid}");
        }
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("staff_traits save error: " . $e->getMessage());
        $error_msg = "Error saving: " . htmlspecialchars($e->getMessage());
    }
}

render:
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($school_name); ?> — Traits & Comments (Staff)</title>
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
            --sidebar-width: 280px;
            --shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            --radius: 10px;
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
        }

        .main-content {
            margin-left: 0;
            padding: 20px;
            min-height: 100vh;
            transition: margin-left 0.28s ease;
        }

        .top-header {
            background: white;
            padding: 20px 25px;
            border-radius: var(--radius);
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: var(--shadow);
        }

        .header-title h1 {
            color: var(--primary);
            font-size: 1.4rem;
            margin-bottom: 5px;
            font-weight: 700;
        }

        .header-title p {
            color: #666;
            font-size: 0.8rem;
        }

        .info-item {
            background: var(--light);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            border-radius: var(--radius);
            padding: 16px;
            text-align: center;
            box-shadow: var(--shadow);
            border-top: 3px solid var(--primary);
        }

        .stat-card.green { border-top-color: var(--success); }
        .stat-card.amber { border-top-color: var(--warning); }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary);
        }
        .stat-card.green .stat-value { color: var(--success); }
        .stat-card.amber .stat-value { color: var(--warning); }
        .stat-label { font-size: 0.75rem; color: #777; margin-top: 4px; }

        .progress-wrap {
            background: white;
            border-radius: var(--radius);
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            margin-bottom: 8px;
        }

        .progress-bar {
            height: 6px;
            background: var(--light);
            border-radius: 20px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            transition: width 0.3s;
        }

        .student-list {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 20px;
        }

        .student-list-header {
            background: var(--primary);
            color: white;
            padding: 12px 16px;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .student-items {
            list-style: none;
            padding: 0;
            max-height: 400px;
            overflow-y: auto;
        }

        .student-items li a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            text-decoration: none;
            color: #333;
            border-bottom: 1px solid var(--light);
            font-size: 0.82rem;
        }

        .student-items li a:hover { background: #f5f6fa; }
        .student-items li a.active { background: #eef3ff; color: var(--primary); font-weight: 600; }

        .s-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 600;
        }

        .s-done-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--success);
            margin-left: auto;
        }
        .s-pend-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--light);
            border: 1px solid #ccc;
            margin-left: auto;
        }

        .form-card {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 20px;
        }

        .card-hdr {
            padding: 14px 20px;
            border-bottom: 2px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-hdr h2 { font-size: 0.95rem; color: var(--primary); font-weight: 600; }

        .card-body { padding: 20px; }

        .traits-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .trait-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            background: #f9f9f9;
            border-radius: 8px;
        }

        .trait-name { font-size: 0.8rem; flex: 1; }

        .rating-group {
            display: flex;
            gap: 4px;
        }

        .rating-btn {
            width: 32px;
            padding: 4px 0;
            border: 1.5px solid #ddd;
            background: white;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
        }
        .rating-btn.sel-A { background: #d4edda; border-color: #27ae60; color: #155724; }
        .rating-btn.sel-B { background: #cce5ff; border-color: #3498db; color: #004085; }
        .rating-btn.sel-C { background: #fff3cd; border-color: #f39c12; color: #856404; }
        .rating-btn.sel-D { background: #ffe5cc; border-color: #e67e22; color: #7d4000; }
        .rating-btn.sel-E { background: #f8d7da; border-color: #e74c3c; color: #721c24; }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 14px;
        }
        .form-row.full { grid-template-columns: 1fr; }

        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-group label { font-size: 0.75rem; font-weight: 500; color: #555; }

        input, select, textarea {
            padding: 9px 12px;
            border: 1.5px solid #e0e0e0;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.85rem;
            width: 100%;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary);
        }

        .action-bar {
            display: flex;
            justify-content: space-between;
            padding: 16px 20px;
            border-top: 2px solid var(--light);
            flex-wrap: wrap;
            gap: 10px;
        }

        .btn {
            padding: 9px 18px;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-secondary { background: white; color: var(--primary); border: 1.5px solid var(--primary); }
        .btn-sm { padding: 6px 12px; font-size: 0.75rem; }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
        }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid var(--success); }
        .alert-danger { background: #f8d7da; color: #721c24; border-left: 4px solid var(--danger); }

        .empty-state { text-align: center; padding: 60px 20px; color: #999; }
        .empty-state i { font-size: 48px; margin-bottom: 16px; opacity: 0.3; }

        @media (min-width: 768px) {
            .main-content { margin-left: var(--sidebar-width); }
            .grid { display: grid; grid-template-columns: 260px 1fr; gap: 20px; }
        }
        @media (max-width: 767px) {
            .main-content { padding-top: 70px; }
            .traits-grid { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>

    <?php include_once 'includes/staff_sidebar.php'; ?>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-heart"></i> Traits & Comments</h1>
                <p><i class="fas fa-chevron-right"></i> <?php echo htmlspecialchars($record['record_name'] ?? "{$session} {$term} Term"); ?> · <?php echo htmlspecialchars($class); ?></p>
            </div>
            <div>
                <span class="info-item"><i class="fas fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></span>
            </div>
        </div>

        <?php if ($success_msg): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo $error_msg; ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_students; ?></div>
                <div class="stat-label">Total Students</div>
            </div>
            <div class="stat-card green">
                <div class="stat-value"><?php echo $completed_count; ?></div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="stat-card amber">
                <div class="stat-value"><?php echo $total_students - $completed_count; ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>

        <div class="progress-wrap">
            <div class="progress-label">
                <span>Student records progress</span>
                <span><?php echo $completed_count; ?> / <?php echo $total_students; ?> · <?php echo $progress_pct; ?>%</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo $progress_pct; ?>%"></div>
            </div>
        </div>

        <?php if (empty($students)): ?>
            <div class="empty-state"><i class="fas fa-user-graduate"></i><h3>No students in <?php echo htmlspecialchars($class); ?></h3></div>
        <?php else: ?>

        <div class="grid">
            <!-- Student List -->
            <div class="student-list">
                <div class="student-list-header"><i class="fas fa-users"></i> Students (<?php echo $completed_count; ?>/<?php echo $total_students; ?>)</div>
                <ul class="student-items">
                    <?php foreach ($students as $i => $s):
                        $s_id = (int)$s['id'];
                        $is_cur = $s_id === $active_sid;
                        $is_done = isset($completed_ids[$s_id]);
                        $inits = strtoupper(substr($s['full_name'], 0, 2));
                    ?>
                        <li>
                            <a href="staff_traits_comments.php?record_id=<?php echo $record_id; ?>&student_id=<?php echo $s_id; ?>" class="<?php echo $is_cur ? 'active' : ''; ?>">
                                <div class="s-avatar"><?php echo $inits; ?></div>
                                <span style="flex:1;"><?php echo htmlspecialchars($s['full_name']); ?></span>
                                <span class="<?php echo $is_done ? 's-done-dot' : 's-pend-dot'; ?>"></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Form -->
            <?php if ($active_student): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="save_traits">
                    <input type="hidden" name="student_id" value="<?php echo $active_sid; ?>">

                    <!-- Affective Traits -->
                    <div class="form-card">
                        <div class="card-hdr">
                            <h2><i class="fas fa-heart"></i> Affective Traits</h2>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="clearRatings('affective')">Clear</button>
                        </div>
                        <div class="card-body">
                            <div class="traits-grid">
                                <?php foreach ($affective_fields as $field => $label):
                                    $current_val = $saved_affective[$field] ?? null;
                                ?>
                                    <div class="trait-row">
                                        <span class="trait-name"><?php echo htmlspecialchars($label); ?></span>
                                        <div class="rating-group">
                                            <?php foreach ($rating_labels as $grade => $desc): ?>
                                                <input type="radio" name="affective[<?php echo $field; ?>]" id="af_<?php echo $field; ?>_<?php echo $grade; ?>" value="<?php echo $grade; ?>" <?php echo $current_val === $grade ? 'checked' : ''; ?> style="display:none;">
                                                <button type="button" class="rating-btn <?php echo $current_val === $grade ? "sel-{$grade}" : ''; ?>" onclick="selectRating(this, 'af_<?php echo $field; ?>_<?php echo $grade; ?>')" title="<?php echo $desc; ?>" data-grade="<?php echo $grade; ?>"><?php echo $grade; ?></button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Psychomotor Skills -->
                    <div class="form-card">
                        <div class="card-hdr">
                            <h2><i class="fas fa-running"></i> Psychomotor Skills</h2>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="clearRatings('psychomotor')">Clear</button>
                        </div>
                        <div class="card-body">
                            <div class="traits-grid">
                                <?php foreach ($psychomotor_fields as $field => $label):
                                    $current_val = $saved_psychomotor[$field] ?? null;
                                ?>
                                    <div class="trait-row">
                                        <span class="trait-name"><?php echo htmlspecialchars($label); ?></span>
                                        <div class="rating-group">
                                            <?php foreach ($rating_labels as $grade => $desc): ?>
                                                <input type="radio" name="psychomotor[<?php echo $field; ?>]" id="pm_<?php echo $field; ?>_<?php echo $grade; ?>" value="<?php echo $grade; ?>" <?php echo $current_val === $grade ? 'checked' : ''; ?> style="display:none;">
                                                <button type="button" class="rating-btn <?php echo $current_val === $grade ? "sel-{$grade}" : ''; ?>" onclick="selectRating(this, 'pm_<?php echo $field; ?>_<?php echo $grade; ?>')" title="<?php echo $desc; ?>" data-grade="<?php echo $grade; ?>"><?php echo $grade; ?></button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Attendance -->
                    <div class="form-card">
                        <div class="card-hdr"><h2><i class="fas fa-calendar-check"></i> Attendance</h2></div>
                        <div class="card-body">
                            <div class="form-row">
                                <div class="form-group"><label>Days School Opened</label><input type="number" value="<?php echo (int)($record['days_school_opened'] ?? 0); ?>" readonly style="background:#f0f0f0;"></div>
                                <div class="form-group"><label>Days Present *</label><input type="number" name="days_present" id="daysPresent" min="0" max="<?php echo (int)($record['days_school_opened'] ?? 365); ?>" value="<?php echo (int)($saved_comments['days_present'] ?? 0); ?>" oninput="updateAbsent()" required></div>
                            </div>
                            <div id="absentDisplay" style="font-size:0.75rem; color:var(--warning); margin-top:8px;">
                                Days absent: <strong><?php echo (int)($record['days_school_opened'] ?? 0) - (int)($saved_comments['days_present'] ?? 0); ?></strong>
                            </div>
                        </div>
                    </div>

                    <!-- Comments -->
                    <div class="form-card">
                        <div class="card-hdr"><h2><i class="fas fa-comment-dots"></i> Comments</h2></div>
                        <div class="card-body">
                            <div class="form-row">
                                <div class="form-group"><label>Class Teacher Name</label><input type="text" name="class_teachers_name" value="<?php echo htmlspecialchars($saved_comments['class_teachers_name'] ?? $default_class_teacher); ?>"></div>
                                <div class="form-group"><label>Principal Name</label><input type="text" name="principals_name" value="<?php echo htmlspecialchars($saved_comments['principals_name'] ?? ''); ?>"></div>
                            </div>
                            <div class="form-row full">
                                <div class="form-group"><label>Class Teacher's Comment</label><textarea name="teachers_comment" rows="2"><?php echo htmlspecialchars($saved_comments['teachers_comment'] ?? ''); ?></textarea></div>
                            </div>
                            <div class="form-row full">
                                <div class="form-group">
                                    <label>Principal's Comment</label>
                                    <?php if ($student_grade && $auto_principal_comment): ?>
                                        <div style="background:#e8f4f8; padding:8px 12px; border-radius:8px; margin-bottom:10px; font-size:0.8rem;">
                                            <i class="fas fa-magic"></i> Suggested for grade <?php echo $student_grade; ?> (Avg: <?php echo number_format($student_average, 1); ?>%):
                                            <em>"<?php echo htmlspecialchars($auto_principal_comment); ?>"</em>
                                            <button type="button" onclick="useSuggestedComment()" class="btn btn-secondary btn-sm" style="margin-left:10px;">Use</button>
                                        </div>
                                    <?php endif; ?>
                                    <textarea name="principals_comment" id="principalsComment" rows="2"><?php echo htmlspecialchars($saved_comments['principals_comment'] ?? $auto_principal_comment); ?></textarea>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group"><label>Promoted To</label><select name="promoted_to">
                                    <option value="">— Select —</option>
                                    <option value="Repeat" <?php echo ($saved_position['promoted_to'] ?? '') === 'Repeat' ? 'selected' : ''; ?>>Repeat current class</option>
                                    <?php foreach ($classes_list as $cl): ?>
                                        <option value="<?php echo htmlspecialchars($cl); ?>" <?php echo ($saved_position['promoted_to'] ?? '') === $cl ? 'selected' : ''; ?>><?php echo htmlspecialchars($cl); ?></option>
                                    <?php endforeach; ?>
                                </select></div>
                            </div>
                        </div>
                        <div class="action-bar">
                            <?php if ($prev_student): ?>
                                <a href="staff_traits_comments.php?record_id=<?php echo $record_id; ?>&student_id=<?php echo $prev_student['id']; ?>" class="btn btn-secondary"><i class="fas fa-chevron-left"></i> Prev</a>
                            <?php else: ?>
                                <div></div>
                            <?php endif; ?>
                            <div style="display:flex; gap:8px;">
                                <?php if ($next_student): ?>
                                    <a href="staff_traits_comments.php?record_id=<?php echo $record_id; ?>&student_id=<?php echo $next_student['id']; ?>" class="btn btn-secondary">Skip <i class="fas fa-forward"></i></a>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save & <?php echo $next_student ? 'Next' : 'Finish'; ?></button>
                            </div>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
        const daysOpened = <?php echo (int)($record['days_school_opened'] ?? 0); ?>;

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

        function clearRatings(section) {
            document.querySelectorAll(`input[name^="${section}["]`).forEach(r => r.checked = false);
            document.querySelectorAll(`.rating-group input[name^="${section}["] + button`).forEach(b => b.className = 'rating-btn');
        }

        function updateAbsent() {
            const present = parseInt(document.getElementById('daysPresent')?.value) || 0;
            const absent = daysOpened - present;
            const display = document.getElementById('absentDisplay');
            if (display) display.innerHTML = `Days absent: <strong>${absent < 0 ? '⚠ Exceeds school days!' : absent}</strong>`;
        }

        function useSuggestedComment() {
            const suggestionDiv = document.querySelector('.suggestion-box');
            if (suggestionDiv) {
                const em = suggestionDiv.querySelector('em');
                if (em) {
                    const text = em.innerText.replace(/"/g, '');
                    document.getElementById('principalsComment').value = text;
                }
            } else {
                const commentText = document.querySelector('div[style*="background:#e8f4f8"] em');
                if (commentText) {
                    document.getElementById('principalsComment').value = commentText.innerText.replace(/"/g, '');
                }
            }
        }

        updateAbsent();
    </script>
</body>

</html>
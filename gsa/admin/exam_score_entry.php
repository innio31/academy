<?php
// gsa/admin/exam_score_entry.php — Enter exam scores per subject (Mobile Friendly)
// ─────────────────────────────────────────────────────────────────────────────

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
$school_name     = SCHOOL_NAME;
$primary_color   = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;

// ── Require a valid record_id ─────────────────────────────────────────────────
$record_id = isset($_GET['record_id']) ? (int)$_GET['record_id'] : 0;
if ($record_id === 0) {
    header("Location: exam_record_setup.php");
    exit();
}

$success_message = '';
$error_message   = '';

// Flash from redirect
if (!empty($_SESSION['flash_success'])) {
    $success_message = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// ── Load the exam record ──────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("SELECT * FROM report_card_settings WHERE id = ? AND school_id = ?");
    $stmt->execute([$record_id, $school_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("exam_score_entry load: " . $e->getMessage());
    $record = null;
}

if (!$record) {
    header("Location: exam_record_setup.php");
    exit();
}

if (($record['status'] ?? 'draft') === 'archived') {
    header("Location: exam_record_setup.php");
    exit();
}

// ── Decode score types & grading from score_types JSON ───────────────────────
$decoded      = json_decode($record['score_types'] ?? '{}', true);
$score_types  = $decoded['score_types']   ?? (is_array($decoded) && isset($decoded[0]['label']) ? $decoded : []);
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

$class   = $record['class'];
$session = $record['session'];
$term    = $record['term'];

// ── Helpers ───────────────────────────────────────────────────────────────────
function getGradeInfo(float $total, array $scale): array
{
    foreach ($scale as $row) {
        if ($total >= (float)$row['min'] && $total <= (float)$row['max'])
            return ['grade' => $row['grade'], 'remark' => $row['remark']];
    }
    return ['grade' => 'F', 'remark' => 'Fail'];
}

// ── Load subjects for this class ──────────────────────────────────────────────
$subjects = [];
try {
    // Resolve class_id from class name first
    $stmtCls = $pdo->prepare("SELECT id FROM classes WHERE class_name = ? AND school_id = ? LIMIT 1");
    $stmtCls->execute([$class, $school_id]);
    $class_row_for_subjects = $stmtCls->fetch();
    $class_id_for_subjects  = $class_row_for_subjects ? (int)$class_row_for_subjects['id'] : 0;

    if ($class_id_for_subjects > 0) {
        $stmt = $pdo->prepare("
            SELECT s.id, s.subject_name
              FROM subjects s
              JOIN subject_classes sc ON sc.subject_id = s.id AND sc.school_id = ?
             WHERE sc.class_id = ? AND (s.school_id = ? OR s.is_central = 1)
             ORDER BY s.subject_name ASC
        ");
        $stmt->execute([$school_id, $class_id_for_subjects, $school_id]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("score_entry subjects: " . $e->getMessage());
}

// ── Load students ─────────────────────────────────────────────────────────────
$students = [];
try {
    // First, get the class_id from the classes table
    $stmt = $pdo->prepare("SELECT id FROM classes WHERE class_name = ? AND school_id = ?");
    $stmt->execute([$class, $school_id]);
    $class_row = $stmt->fetch();
    $class_id = $class_row ? $class_row['id'] : 0;

    if ($class_id > 0) {
        $stmt = $pdo->prepare("
            SELECT id, full_name, admission_number, gender
              FROM students
             WHERE school_id = ? AND class_id = ? AND status = 'active'
             ORDER BY full_name ASC
        ");
        $stmt->execute([$school_id, $class_id]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Fallback: use class name
        $stmt = $pdo->prepare("
            SELECT id, full_name, admission_number, gender
              FROM students
             WHERE school_id = ? AND class = ? AND status = 'active'
             ORDER BY full_name ASC
        ");
        $stmt->execute([$school_id, $class]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("score_entry students: " . $e->getMessage());
}

// ── Load existing scores for active subject ───────────────────────────────────
$existing_scores = [];
$active_subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;

if ($active_subject_id > 0 && !empty($students)) {
    try {
        // Get class_id for the class
        $stmt = $pdo->prepare("SELECT id FROM classes WHERE class_name = ? AND school_id = ?");
        $stmt->execute([$class, $school_id]);
        $class_row = $stmt->fetch();
        $class_id = $class_row ? $class_row['id'] : 0;

        if ($class_id > 0) {
            $stmt = $pdo->prepare("
                SELECT ss.student_id, ss.score_data, ss.total_score, ss.grade, ss.subject_position
                  FROM student_scores ss
                  JOIN students st ON st.id = ss.student_id AND st.school_id = ss.school_id
                 WHERE ss.school_id=? AND ss.subject_id=? AND ss.session=? AND ss.term=?
                   AND st.class_id=?
            ");
            $stmt->execute([$school_id, $active_subject_id, $session, $term, $class_id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT ss.student_id, ss.score_data, ss.total_score, ss.grade, ss.subject_position
                  FROM student_scores ss
                  JOIN students st ON st.id = ss.student_id AND st.school_id = ss.school_id
                 WHERE ss.school_id=? AND ss.subject_id=? AND ss.session=? AND ss.term=?
                   AND st.class=?
            ");
            $stmt->execute([$school_id, $active_subject_id, $session, $term, $class]);
        }

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['score_data'] = json_decode($row['score_data'] ?? '[]', true) ?: [];
            $existing_scores[(int)$row['student_id']] = $row;
        }
    } catch (Exception $e) {
        error_log("score_entry existing: " . $e->getMessage());
    }
}

// ── Subjects that already have scores ─────────────────────────────────────────
$subjects_with_scores = [];
if (!empty($subjects)) {
    try {
        $sub_ids = array_column($subjects, 'id');
        $ph = implode(',', array_fill(0, count($sub_ids), '?'));

        // Get class_id
        $stmt = $pdo->prepare("SELECT id FROM classes WHERE class_name = ? AND school_id = ?");
        $stmt->execute([$class, $school_id]);
        $class_row = $stmt->fetch();
        $class_id = $class_row ? $class_row['id'] : 0;

        if ($class_id > 0) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT ss.subject_id FROM student_scores ss
                  JOIN students st ON st.id = ss.student_id AND st.school_id = ss.school_id
                 WHERE ss.school_id=? AND ss.session=? AND ss.term=?
                   AND st.class_id=? AND ss.subject_id IN ($ph)
            ");
            $stmt->execute(array_merge([$school_id, $session, $term, $class_id], $sub_ids));
        } else {
            $stmt = $pdo->prepare("
                SELECT DISTINCT ss.subject_id FROM student_scores ss
                  JOIN students st ON st.id = ss.student_id AND st.school_id = ss.school_id
                 WHERE ss.school_id=? AND ss.session=? AND ss.term=?
                   AND st.class=? AND ss.subject_id IN ($ph)
            ");
            $stmt->execute(array_merge([$school_id, $session, $term, $class], $sub_ids));
        }
        $subjects_with_scores = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) { /* non-fatal */
    }
}

// ── Load staff ────────────────────────────────────────────────────────────────
$staff_list = [];
try {
    $stmt = $pdo->prepare("SELECT staff_id, full_name FROM staff WHERE school_id=? AND is_active=1 ORDER BY full_name");
    $stmt->execute([$school_id]);
    $staff_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* non-fatal */
}

// ── Assigned staff for active subject ────────────────────────────────────────
$assigned_staff_id = null;
if ($active_subject_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT staff_id FROM staff_subjects WHERE school_id=? AND subject_id=? LIMIT 1");
        $stmt->execute([$school_id, $active_subject_id]);
        $assigned_staff_id = $stmt->fetchColumn() ?: null;
    } catch (Exception $e) { /* non-fatal */
    }
}

// ── Active subject name ───────────────────────────────────────────────────────
$active_subject_name = '';
$next_subject        = null;
$found_active        = false;
foreach ($subjects as $sub) {
    if ($found_active && $next_subject === null) {
        $next_subject = $sub;
    }
    if ((int)$sub['id'] === $active_subject_id) {
        $active_subject_name = $sub['subject_name'];
        $found_active = true;
    }
}

// ── Stats ─────────────────────────────────────────────────────────────────────
$total_subjects     = count($subjects);
$total_students     = count($students);
$completed_subjects = count($subjects_with_scores);
$entered_count      = count($existing_scores);
$progress_pct       = $total_subjects > 0 ? round(($completed_subjects / $total_subjects) * 100) : 0;
$class_avg          = 0;
if (!empty($existing_scores)) {
    $totals    = array_column($existing_scores, 'total_score');
    $class_avg = round(array_sum($totals) / count($totals), 1);
}

// ── Handle POST: save scores ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_scores') {

    $post_subject_id  = (int)($_POST['subject_id'] ?? 0);
    $scores_post      = $_POST['scores'] ?? [];

    if ($post_subject_id < 1 || empty($students)) {
        $error_message = "Invalid subject or no students found.";
    } else {
        // Resolve subject name
        $subj_name_val = '';
        foreach ($subjects as $sub) {
            if ((int)$sub['id'] === $post_subject_id) {
                $subj_name_val = $sub['subject_name'];
                break;
            }
        }

        $pdo->beginTransaction();
        try {
            foreach ($students as $stu) {
                $sid    = (int)$stu['id'];
                $raw    = $scores_post[$sid] ?? [];
                $sdata  = [];
                $total  = 0.0;
                $hasAny = false;

                foreach ($score_types as $st) {
                    $label  = $st['label'];
                    $maxVal = (float)($st['max'] ?? 0);
                    if (isset($raw[$label]) && trim((string)$raw[$label]) !== '') {
                        $val     = min((float)$raw[$label], $maxVal);
                        $val     = max(0, $val);
                        $total  += $val;
                        $hasAny  = true;
                        $sdata[$label] = $val;
                    } else {
                        $sdata[$label] = null;
                    }
                }

                if (!$hasAny) continue;

                $graded = getGradeInfo($total, $grading_scale);
                $pct    = $record['max_score'] > 0 ? round(($total / $record['max_score']) * 100, 2) : 0;

                // SELECT then INSERT/UPDATE
                $chk = $pdo->prepare("
                    SELECT id FROM student_scores
                     WHERE school_id=? AND student_id=? AND subject_id=? AND session=? AND term=?
                     LIMIT 1
                ");
                $chk->execute([$school_id, $sid, $post_subject_id, $session, $term]);
                $eid = $chk->fetchColumn();

                if ($eid) {
                    $pdo->prepare("
                        UPDATE student_scores
                           SET score_data=?, total_score=?, percentage=?, grade=?, subject_name=?
                         WHERE id=?
                    ")->execute([json_encode($sdata), $total, $pct, $graded['grade'], $subj_name_val, $eid]);
                } else {
                    $pdo->prepare("
                        INSERT INTO student_scores
                            (school_id,student_id,subject_id,subject_name,session,term,score_data,total_score,percentage,grade)
                        VALUES (?,?,?,?,?,?,?,?,?,?)
                    ")->execute([$school_id, $sid, $post_subject_id, $subj_name_val, $session, $term, json_encode($sdata), $total, $pct, $graded['grade']]);
                }
            }

            // ── Recalculate subject positions ─────────────────────────────────────────────────
            $stmt = $pdo->prepare("
                SELECT id, student_id, total_score FROM student_scores
                 WHERE school_id=? AND subject_id=? AND session=? AND term=?
                 ORDER BY total_score DESC
            ");
            $stmt->execute([$school_id, $post_subject_id, $session, $term]);
            $ranked       = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $pos          = 1;
            $prev_score   = null;

            foreach ($ranked as $r) {
                $cur_score = (float)$r['total_score'];
                $display_pos = $pos;

                $pdo->prepare("UPDATE student_scores SET subject_position=? WHERE id=?")->execute([$display_pos, $r['id']]);

                // Sync student_subject_positions
                $chk2 = $pdo->prepare("
                    SELECT id FROM student_subject_positions
                     WHERE school_id=? AND student_id=? AND subject_id=? AND session=? AND term=? LIMIT 1
                ");
                $chk2->execute([$school_id, $r['student_id'], $post_subject_id, $session, $term]);
                $spid = $chk2->fetchColumn();
                if ($spid) {
                    $pdo->prepare("UPDATE student_subject_positions SET subject_position=?,updated_at=NOW() WHERE id=?")
                        ->execute([$display_pos, $spid]);
                } else {
                    $pdo->prepare("
                        INSERT INTO student_subject_positions
                            (school_id,student_id,subject_id,session,term,subject_position,created_at,updated_at)
                        VALUES (?,?,?,?,?,?,NOW(),NOW())
                    ")->execute([$school_id, $r['student_id'], $post_subject_id, $session, $term, $display_pos]);
                }
                $prev_score = $cur_score;
                $pos++;
            }

            // Mark record active if still draft
            if (($record['status'] ?? 'draft') === 'draft') {
                $pdo->prepare("UPDATE report_card_settings SET status='active' WHERE id=?")->execute([$record_id]);
                $record['status'] = 'active';
            }

            $pdo->commit();

            // Activity log
            try {
                $pdo->prepare("INSERT INTO activity_logs (user_id,user_type,activity,school_id) VALUES (?,?,?,?)")
                    ->execute([$admin_id, 'admin', "Saved scores: {$subj_name_val} — {$class} {$term} Term {$session}", $school_id]);
            } catch (Exception $e) { /* skip */
            }

            $_SESSION['flash_success'] = "Scores saved for {$subj_name_val}. Subject positions recalculated.";
            header("Location: exam_score_entry.php?record_id={$record_id}&subject_id={$post_subject_id}");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("score_entry save: " . $e->getMessage());
            $error_message = "Error saving scores: " . htmlspecialchars($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title><?php echo htmlspecialchars($school_name); ?> — Score Entry</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: <?php echo $secondary_color; ?>;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --sidebar-width: 260px;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
            --radius-sm: 8px;
            --radius-md: 12px;
            --transition: all 0.3s ease;
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

        /* Sidebar - Mobile First */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-color), var(--dark-color));
            color: white;
            padding: 20px 0;
            transition: transform 0.3s ease;
            z-index: 1000;
            overflow-y: auto;
            transform: translateX(-100%);
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            width: 44px;
            height: 44px;
            background: var(--secondary-color);
            border-radius: var(--radius-sm);
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
            font-size: 0.7rem;
            opacity: 0.8;
        }

        .admin-info {
            text-align: center;
            padding: 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            margin: 15px;
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
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            border-radius: 8px;
            transition: var(--transition);
        }

        .nav-links a:hover {
            background: rgba(255, 255, 255, 0.15);
        }

        .nav-links a.active {
            background: rgba(255, 255, 255, 0.2);
            border-left: 3px solid var(--secondary-color);
        }

        .nav-links i {
            width: 20px;
            text-align: center;
        }

        /* Mobile Menu */
        .mobile-menu-toggle {
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            width: 44px;
            height: 44px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 20px;
            cursor: pointer;
            box-shadow: var(--shadow-sm);
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

        /* Main Content */
        .main-content {
            min-height: 100vh;
            padding: 70px 16px 20px;
            transition: var(--transition);
        }

        /* Top Header */
        .top-header {
            background: white;
            padding: 16px;
            border-radius: var(--radius-md);
            margin-bottom: 16px;
            box-shadow: var(--shadow-sm);
        }

        .top-header h1 {
            color: var(--primary-color);
            font-size: 1.25rem;
            margin-bottom: 4px;
        }

        .top-header p {
            color: #666;
            font-size: 0.75rem;
            line-height: 1.4;
        }

        .header-buttons {
            display: flex;
            gap: 10px;
            margin-top: 12px;
            flex-wrap: wrap;
        }

        /* Buttons */
        .btn {
            padding: 10px 16px;
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: var(--transition);
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-secondary {
            background: white;
            color: var(--primary-color);
            border: 1.5px solid var(--primary-color);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.7rem;
        }

        .back-btn {
            background: var(--light-color);
            color: var(--dark-color);
            padding: 8px 14px;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        /* Alerts */
        .alert {
            padding: 12px;
            border-radius: var(--radius-sm);
            margin-bottom: 16px;
            font-size: 0.8rem;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid var(--success-color);
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger-color);
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid var(--warning-color);
        }

        /* Stats Row - Mobile Optimized */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }

        .stat-box {
            background: white;
            border-radius: var(--radius-md);
            padding: 12px;
            text-align: center;
            box-shadow: var(--shadow-sm);
            border-top: 3px solid var(--primary-color);
        }

        .stat-box.green {
            border-top-color: var(--success-color);
        }

        .stat-box.amber {
            border-top-color: var(--warning-color);
        }

        .stat-val {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .stat-box.green .stat-val {
            color: var(--success-color);
        }

        .stat-box.amber .stat-val {
            color: var(--warning-color);
        }

        .stat-lbl {
            font-size: 0.7rem;
            color: #777;
            margin-top: 4px;
        }

        /* Progress Bar */
        .progress-wrap {
            background: white;
            border-radius: var(--radius-md);
            padding: 12px 16px;
            margin-bottom: 16px;
            box-shadow: var(--shadow-sm);
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: #555;
            margin-bottom: 8px;
        }

        .progress-bar {
            height: 6px;
            background: var(--light-color);
            border-radius: 20px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 20px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            transition: width 0.4s ease;
        }

        /* Step Bar - Mobile Optimized */
        .step-bar {
            background: white;
            border-radius: var(--radius-md);
            padding: 12px 8px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            overflow-x: auto;
            gap: 8px;
            box-shadow: var(--shadow-sm);
        }

        .step-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 55px;
        }

        .step-circle {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 600;
            background: var(--light-color);
            color: #999;
            border: 2px solid transparent;
        }

        .step-done {
            background: var(--primary-color);
            color: white;
        }

        .step-current {
            background: white;
            color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .step-label {
            font-size: 9px;
            color: #999;
            margin-top: 4px;
            text-align: center;
        }

        /* Mobile Subject Selector */
        .mobile-subject-selector {
            background: white;
            border-radius: var(--radius-md);
            margin-bottom: 16px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .selector-header {
            padding: 12px 16px;
            background: var(--primary-color);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }

        .selector-header span {
            font-size: 0.85rem;
            font-weight: 500;
        }

        .subject-dropdown-list {
            display: none;
            padding: 8px 0;
            max-height: 250px;
            overflow-y: auto;
        }

        .subject-dropdown-list.show {
            display: block;
        }

        .subject-dropdown-item {
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            text-decoration: none;
            border-bottom: 1px solid var(--light-color);
        }

        .subject-dropdown-item.active {
            background: #eef3ff;
            color: var(--primary-color);
            font-weight: 600;
        }

        .subject-dropdown-item span:first-child {
            font-size: 0.85rem;
        }

        .done-dot,
        .pending-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        .done-dot {
            background: var(--success-color);
        }

        .pending-dot {
            background: var(--light-color);
            border: 1px solid #ccc;
        }

        /* Score Card */
        .score-card {
            background: white;
            border-radius: var(--radius-md);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            margin-bottom: 16px;
        }

        .score-card-header {
            padding: 14px 16px;
            background: white;
            border-bottom: 1px solid var(--light-color);
        }

        .score-card-header h2 {
            color: var(--primary-color);
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .score-card-header .meta {
            font-size: 0.7rem;
            color: #888;
        }

        /* Mobile Score Table - Card Style */
        .mobile-score-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding: 12px;
        }

        .score-student-card {
            background: #f9f9f9;
            border-radius: var(--radius-md);
            padding: 12px;
            border: 1px solid var(--light-color);
        }

        .student-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--light-color);
        }

        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .student-details {
            flex: 1;
        }

        .student-name {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .student-adm {
            font-size: 0.7rem;
            color: #888;
        }

        .score-fields {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 10px;
        }

        .score-field {
            flex: 1;
            min-width: 70px;
        }

        .score-field label {
            display: block;
            font-size: 0.7rem;
            color: #666;
            margin-bottom: 4px;
        }

        .score-input {
            width: 100%;
            padding: 8px;
            text-align: center;
            border: 1.5px solid #ddd;
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 0.85rem;
            background: white;
        }

        .score-input:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .score-input.over {
            border-color: var(--danger-color);
            background: #fff5f5;
        }

        .result-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 8px;
            border-top: 1px solid var(--light-color);
        }

        .total-score {
            font-weight: 700;
            font-size: 1rem;
            color: var(--primary-color);
        }

        .grade-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
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
            background: #ffe5cc;
            color: #7d4000;
        }

        .g-f {
            background: #f8d7da;
            color: #721c24;
        }

        .remark-text {
            font-size: 0.7rem;
            color: #888;
        }

        /* Staff Assignment */
        .assign-bar {
            padding: 12px 16px;
            background: #f9f9f9;
            border-bottom: 1px solid var(--light-color);
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            font-size: 0.75rem;
        }

        .assign-bar select {
            padding: 6px 10px;
            border: 1.5px solid #ddd;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-family: 'Poppins', sans-serif;
            background: white;
            flex: 1;
            min-width: 140px;
        }

        /* Score Footer */
        .score-footer {
            padding: 12px 16px;
            border-top: 1px solid var(--light-color);
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .footer-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .footer-buttons .btn {
            flex: 1;
            justify-content: center;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 12px;
            opacity: 0.3;
        }

        /* Desktop Styles */
        @media (min-width: 768px) {

            .mobile-menu-toggle,
            .sidebar-overlay {
                display: none;
            }

            .sidebar {
                transform: translateX(0);
            }

            .main-content {
                margin-left: var(--sidebar-width);
                padding: 20px 24px;
            }

            .mobile-subject-selector {
                display: none;
            }

            .stats-row {
                grid-template-columns: repeat(6, 1fr);
            }

            .score-footer {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }

            .footer-buttons {
                flex: 1;
                justify-content: flex-end;
            }
        }

        /* Tablet Styles */
        @media (min-width: 768px) and (max-width: 1024px) {
            .stats-row {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        /* Desktop Subject Panel */
        .desktop-layout {
            display: block;
        }

        @media (min-width: 768px) {
            .desktop-layout {
                display: grid;
                grid-template-columns: 240px 1fr;
                gap: 20px;
                align-items: start;
            }

            .desktop-subject-panel {
                background: white;
                border-radius: var(--radius-md);
                box-shadow: var(--shadow-sm);
                overflow: hidden;
                position: sticky;
                top: 20px;
            }

            .desktop-subject-panel .panel-header {
                padding: 12px 16px;
                background: var(--primary-color);
                color: white;
                font-size: 0.85rem;
                font-weight: 600;
            }

            .desktop-subject-panel .panel-list {
                max-height: 70vh;
                overflow-y: auto;
            }

            .desktop-subject-panel .panel-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 12px 16px;
                font-size: 0.82rem;
                text-decoration: none;
                color: #333;
                border-bottom: 1px solid var(--light-color);
                transition: var(--transition);
            }

            .desktop-subject-panel .panel-item:hover {
                background: #f5f6fa;
            }

            .desktop-subject-panel .panel-item.active {
                background: #eef3ff;
                color: var(--primary-color);
                font-weight: 600;
                border-left: 3px solid var(--primary-color);
            }
        }

        @media (max-width: 767px) {
            .desktop-subject-panel {
                display: none;
            }
        }
    </style>
</head>

<body>

    <button class="mobile-menu-toggle" id="mobileMenuToggle"><i class="fas fa-bars"></i></button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <?php
    // Include sidebar (it will be positioned fixed)
    require_once 'includes/sidebar.php';
    ?>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">

        <div class="top-header">
            <div>
                <h1>Enter Exam Scores</h1>
                <p>
                    <strong><?php echo htmlspecialchars($record['record_name'] ?? "{$session} {$term} Term"); ?></strong>
                    · <?php echo htmlspecialchars($class); ?>
                    · <?php echo htmlspecialchars($session); ?>
                    · <?php echo htmlspecialchars($term); ?> Term
                    · <span style="color:<?php echo ($record['status'] ?? 'draft') === 'published' ? 'var(--success-color)' : 'var(--warning-color)'; ?>">
                        <?php echo ucfirst($record['status'] ?? 'draft'); ?>
                    </span>
                </p>
            </div>
            <div class="header-buttons">
                <a href="exam_record_setup.php?edit=<?php echo $record_id; ?>" class="back-btn">
                    <i class="fas fa-cog"></i> Settings
                </a>
                <a href="exam_record_setup.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> All records
                </a>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars($success_message); ?></span></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i><span><?php echo $error_message; ?></span></div>
        <?php endif; ?>

        <!-- Step Bar -->
        <div class="step-bar">
            <div class="step-item">
                <div class="step-circle step-done"><i class="fas fa-check" style="font-size: 9px;"></i></div>
                <div class="step-label">Setup</div>
            </div>
            <div class="step-item">
                <div class="step-circle step-current">2</div>
                <div class="step-label">Scores</div>
            </div>
            <div class="step-item">
                <div class="step-circle step-todo">3</div>
                <div class="step-label">Traits</div>
            </div>
            <div class="step-item">
                <div class="step-circle step-todo">4</div>
                <div class="step-label">Generate</div>
            </div>
            <div class="step-item">
                <div class="step-circle step-todo">5</div>
                <div class="step-label">Publish</div>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-box">
                <div class="stat-val"><?php echo $total_students; ?></div>
                <div class="stat-lbl">Students</div>
            </div>
            <div class="stat-box">
                <div class="stat-val"><?php echo $total_subjects; ?></div>
                <div class="stat-lbl">Subjects</div>
            </div>
            <div class="stat-box green">
                <div class="stat-val"><?php echo $completed_subjects; ?></div>
                <div class="stat-lbl">Done</div>
            </div>
            <div class="stat-box amber">
                <div class="stat-val"><?php echo $total_subjects - $completed_subjects; ?></div>
                <div class="stat-lbl">Pending</div>
            </div>
            <?php if ($active_subject_id > 0): ?>
                <div class="stat-box">
                    <div class="stat-val"><?php echo $entered_count; ?>/<?php echo $total_students; ?></div>
                    <div class="stat-lbl">Entered</div>
                </div>
                <div class="stat-box">
                    <div class="stat-val"><?php echo $class_avg > 0 ? $class_avg . '%' : '—'; ?></div>
                    <div class="stat-lbl">Class Avg</div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Progress -->
        <div class="progress-wrap">
            <div class="progress-label"><span>Score entry progress</span><span><?php echo $completed_subjects; ?> / <?php echo $total_subjects; ?> subjects · <?php echo $progress_pct; ?>%</span></div>
            <div class="progress-bar">
                <div class="progress-fill" style="width:<?php echo $progress_pct; ?>%"></div>
            </div>
        </div>

        <?php if (empty($subjects)): ?>
            <div class="score-card">
                <div class="empty-state">
                    <i class="fas fa-book-open"></i>
                    <h3>No subjects found for <?php echo htmlspecialchars($class); ?></h3>
                    <p>Assign subjects to this class in Manage Subjects first.</p>
                    <br><a href="manage-subjects.php" class="btn btn-primary"><i class="fas fa-book"></i> Manage Subjects</a>
                </div>
            </div>
        <?php elseif (empty($students)): ?>
            <div class="score-card">
                <div class="empty-state">
                    <i class="fas fa-user-graduate"></i>
                    <h3>No active students in <?php echo htmlspecialchars($class); ?></h3>
                    <p>Enrol students and set their class to <strong><?php echo htmlspecialchars($class); ?></strong>.</p>
                    <br><a href="manage-students.php" class="btn btn-primary"><i class="fas fa-users"></i> Manage Students</a>
                </div>
            </div>
        <?php else: ?>

            <!-- Desktop Layout Wrapper -->
            <div class="desktop-layout">

                <!-- Desktop Subject Panel (hidden on mobile) -->
                <div class="desktop-subject-panel">
                    <div class="panel-header"><i class="fas fa-book-open"></i> Subjects</div>
                    <div class="panel-list">
                        <?php foreach ($subjects as $sub):
                            $is_done = isset($subjects_with_scores[(int)$sub['id']]);
                        ?>
                            <a href="exam_score_entry.php?record_id=<?php echo $record_id; ?>&subject_id=<?php echo $sub['id']; ?>"
                                class="panel-item <?php echo (int)$sub['id'] === $active_subject_id ? 'active' : ''; ?>">
                                <span><?php echo htmlspecialchars($sub['subject_name']); ?></span>
                                <span class="<?php echo $is_done ? 'done-dot' : 'pending-dot'; ?>"></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Right side: mobile dropdown + score form -->
                <div>

                    <!-- Mobile Subject Selector (Dropdown) -->
                    <div class="mobile-subject-selector">
                        <div class="selector-header" onclick="toggleSubjectDropdown()">
                            <span><i class="fas fa-book-open"></i> <?php echo $active_subject_name ?: 'Select Subject'; ?></span>
                            <i class="fas fa-chevron-down" id="dropdownIcon"></i>
                        </div>
                        <div class="subject-dropdown-list" id="subjectDropdownList">
                            <?php foreach ($subjects as $sub):
                                $is_done = isset($subjects_with_scores[(int)$sub['id']]);
                            ?>
                                <a href="exam_score_entry.php?record_id=<?php echo $record_id; ?>&subject_id=<?php echo $sub['id']; ?>"
                                    class="subject-dropdown-item <?php echo (int)$sub['id'] === $active_subject_id ? 'active' : ''; ?>">
                                    <span><?php echo htmlspecialchars($sub['subject_name']); ?></span>
                                    <span class="<?php echo $is_done ? 'done-dot' : 'pending-dot'; ?>"></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if ($active_subject_id > 0 && $active_subject_name !== ''): ?>
                        <form method="POST" id="scoreForm">
                            <input type="hidden" name="action" value="save_scores">
                            <input type="hidden" name="subject_id" value="<?php echo $active_subject_id; ?>">

                            <div class="score-card">
                                <div class="score-card-header">
                                    <h2><i class="fas fa-pencil-alt"></i> <?php echo htmlspecialchars($active_subject_name); ?></h2>
                                    <div class="meta">
                                        <?php echo htmlspecialchars($class); ?> · <?php echo htmlspecialchars($term); ?> Term · <?php echo htmlspecialchars($session); ?>
                                        · Max: <?php echo (int)$record['max_score']; ?> marks
                                        (<?php echo implode(' + ', array_map(fn($s) => htmlspecialchars($s['label']) . '/' . (int)$s['max'], $score_types)); ?>)
                                    </div>
                                </div>

                                <!-- Staff Assignment -->
                                <div class="assign-bar">
                                    <i class="fas fa-chalkboard-teacher"></i>
                                    <select id="staffAssign" onchange="saveStaffAssignment(this.value)">
                                        <option value="">— Not assigned —</option>
                                        <?php foreach ($staff_list as $sf): ?>
                                            <option value="<?php echo htmlspecialchars($sf['staff_id']); ?>" <?php echo $sf['staff_id'] === $assigned_staff_id ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($sf['full_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span id="assignMsg" style="font-size:0.7rem;color:var(--success-color);display:none"><i class="fas fa-check"></i> Saved</span>
                                </div>

                                <!-- Score Cards -->
                                <div class="mobile-score-list" id="mobileScoreList">
                                    <?php foreach ($students as $idx => $stu):
                                        $stu_id = (int)$stu['id'];
                                        $saved = $existing_scores[$stu_id] ?? null;
                                        $initials = strtoupper(substr($stu['full_name'], 0, 2));
                                        $graded = $saved ? getGradeInfo((float)$saved['total_score'], $grading_scale) : null;
                                        $gc = $graded ? 'g-' . strtolower($graded['grade'][0]) : '';
                                    ?>
                                        <div class="score-student-card" id="studentCard_<?php echo $stu_id; ?>">
                                            <div class="student-header">
                                                <div class="student-avatar"><?php echo htmlspecialchars($initials); ?></div>
                                                <div class="student-details">
                                                    <div class="student-name"><?php echo htmlspecialchars($stu['full_name']); ?></div>
                                                    <div class="student-adm">Adm: <?php echo htmlspecialchars($stu['admission_number']); ?></div>
                                                </div>
                                            </div>
                                            <div class="score-fields">
                                                <?php foreach ($score_types as $st):
                                                    $lbl = $st['label'];
                                                    $maxVal = (int)$st['max'];
                                                    $val = $saved ? ($saved['score_data'][$lbl] ?? '') : '';
                                                    $filled = ($val !== '' && $val !== null);
                                                ?>
                                                    <div class="score-field">
                                                        <label><?php echo htmlspecialchars($lbl); ?> / <?php echo $maxVal; ?></label>
                                                        <input type="number"
                                                            name="scores[<?php echo $stu_id; ?>][<?php echo htmlspecialchars($lbl); ?>]"
                                                            class="score-input <?php echo $filled ? 'filled' : ''; ?>"
                                                            value="<?php echo $filled ? htmlspecialchars((string)$val) : ''; ?>"
                                                            min="0" max="<?php echo $maxVal; ?>" step="0.5"
                                                            data-max="<?php echo $maxVal; ?>"
                                                            data-student="<?php echo $stu_id; ?>"
                                                            data-field="<?php echo htmlspecialchars($lbl); ?>"
                                                            oninput="onScoreInput(this, <?php echo $stu_id; ?>)"
                                                            inputmode="decimal">
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="result-row">
                                                <div>
                                                    <span class="total-score" id="total_<?php echo $stu_id; ?>">
                                                        <?php echo $saved ? number_format((float)$saved['total_score'], 1) : '—'; ?>
                                                    </span>
                                                    <span class="remark-text" id="remark_<?php echo $stu_id; ?>">
                                                        <?php echo $graded ? $graded['remark'] : ''; ?>
                                                    </span>
                                                </div>
                                                <div id="grade_<?php echo $stu_id; ?>">
                                                    <?php if ($graded): ?>
                                                        <span class="grade-badge <?php echo $gc; ?>"><?php echo htmlspecialchars($graded['grade']); ?></span>
                                                    <?php else: ?>
                                                        <span class="grade-badge g-f">—</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Footer -->
                                <div class="score-footer">
                                    <div class="footer-buttons">
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="fillZeros()">
                                            <i class="fas fa-fill-drip"></i> Fill zeros
                                        </button>
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="clearAllInputs()">
                                            <i class="fas fa-undo"></i> Clear
                                        </button>
                                        <button type="submit" class="btn btn-primary" id="saveBtn">
                                            <i class="fas fa-save"></i> Save
                                        </button>
                                    </div>
                                    <?php if ($next_subject): ?>
                                        <a href="exam_score_entry.php?record_id=<?php echo $record_id; ?>&subject_id=<?php echo $next_subject['id']; ?>" class="btn btn-secondary btn-sm">
                                            Next: <?php echo htmlspecialchars($next_subject['subject_name']); ?> <i class="fas fa-arrow-right"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>

                        <!-- Completion Alert -->
                        <?php if ($completed_subjects >= $total_subjects): ?>
                            <div class="alert alert-success" style="margin-top: 12px;">
                                <i class="fas fa-check-circle"></i>
                                <div>
                                    <strong>All <?php echo $total_subjects; ?> subjects complete!</strong>
                                    <div style="margin-top: 8px;">
                                        <a href="exam_traits_comments.php?record_id=<?php echo $record_id; ?>" class="btn btn-success btn-sm">
                                            <i class="fas fa-arrow-right"></i> Traits &amp; comments
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="score-card">
                            <div class="empty-state">
                                <i class="fas fa-hand-point-left"></i>
                                <h3>Select a subject</h3>
                                <p>Choose a subject from the list above to start entering scores.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div style="text-align: center; padding: 20px; color: #999; font-size: 0.7rem; border-top: 1px solid var(--light-color); margin-top: 20px">
            &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> — Online Portal
        </div>
    </div>

    <script>
        // Grading presets (if needed for other functions)
        const GRADING_PRESETS = <?php echo json_encode($grading_presets ?? []); ?>;
        const scoreTypes = <?php echo json_encode($score_types); ?>;
        
        // ── MOBILE SUBJECT DROPDOWN TOGGLE (FIXED) ──────────────────────────────
        function toggleSubjectDropdown() {
            const dropdown = document.getElementById('subjectDropdownList');
            const icon = document.getElementById('dropdownIcon');
            
            if (dropdown) {
                dropdown.classList.toggle('show');
                if (icon) {
                    icon.classList.toggle('fa-chevron-down');
                    icon.classList.toggle('fa-chevron-up');
                }
            }
        }
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const selector = document.querySelector('.mobile-subject-selector');
            const dropdown = document.getElementById('subjectDropdownList');
            
            if (selector && dropdown && !selector.contains(event.target)) {
                dropdown.classList.remove('show');
                const icon = document.getElementById('dropdownIcon');
                if (icon) {
                    icon.classList.add('fa-chevron-down');
                    icon.classList.remove('fa-chevron-up');
                }
            }
        });
        
        // ── SCORE INPUT HANDLING ────────────────────────────────────────────────
        function onScoreInput(input, studentId) {
            const maxVal = parseFloat(input.getAttribute('data-max') || input.max);
            let val = parseFloat(input.value) || 0;
            
            if (val > maxVal) {
                input.classList.add('over');
                input.value = maxVal;
                val = maxVal;
            } else {
                input.classList.remove('over');
            }
            
            // Recalculate total for this student
            const studentCard = document.getElementById(`studentCard_${studentId}`);
            const scoreInputs = studentCard.querySelectorAll('.score-input');
            let total = 0;
            
            scoreInputs.forEach(inp => {
                let v = parseFloat(inp.value) || 0;
                total += v;
            });
            
            // Update total display
            const totalSpan = document.getElementById(`total_${studentId}`);
            if (totalSpan) {
                totalSpan.textContent = total.toFixed(1);
            }
            
            // Calculate grade based on total
            const gradeInfo = getGradeInfo(total);
            const gradeSpan = document.getElementById(`grade_${studentId}`);
            const remarkSpan = document.getElementById(`remark_${studentId}`);
            
            if (gradeSpan) {
                let gradeClass = 'grade-badge ';
                const gradeLetter = gradeInfo.grade.charAt(0).toLowerCase();
                if (gradeLetter === 'a') gradeClass += 'g-a';
                else if (gradeLetter === 'b') gradeClass += 'g-b';
                else if (gradeLetter === 'c') gradeClass += 'g-c';
                else if (gradeLetter === 'd') gradeClass += 'g-d';
                else gradeClass += 'g-f';
                
                gradeSpan.innerHTML = `<span class="${gradeClass}">${gradeInfo.grade}</span>`;
            }
            
            if (remarkSpan) {
                remarkSpan.textContent = gradeInfo.remark;
            }
        }
        
        function getGradeInfo(totalScore) {
            // Use the grading scale from PHP
            const gradingScale = <?php echo json_encode($grading_scale); ?>;
            
            for (const grade of gradingScale) {
                if (totalScore >= grade.min && totalScore <= grade.max) {
                    return { grade: grade.grade, remark: grade.remark };
                }
            }
            return { grade: 'F', remark: 'Fail' };
        }
        
        function fillZeros() {
            const allInputs = document.querySelectorAll('.score-input');
            allInputs.forEach(input => {
                if (input.value === '') {
                    input.value = 0;
                    const studentId = input.getAttribute('data-student');
                    if (studentId) onScoreInput(input, parseInt(studentId));
                }
            });
            showToast('All empty scores filled with 0', 'info');
        }
        
        function clearAllInputs() {
            if (confirm('Clear all scores for this subject? This cannot be undone.')) {
                const allInputs = document.querySelectorAll('.score-input');
                allInputs.forEach(input => {
                    input.value = '';
                    const studentId = input.getAttribute('data-student');
                    if (studentId) {
                        const totalSpan = document.getElementById(`total_${studentId}`);
                        if (totalSpan) totalSpan.textContent = '—';
                        const gradeSpan = document.getElementById(`grade_${studentId}`);
                        if (gradeSpan) gradeSpan.innerHTML = '<span class="grade-badge g-f">—</span>';
                        const remarkSpan = document.getElementById(`remark_${studentId}`);
                        if (remarkSpan) remarkSpan.textContent = '';
                    }
                });
                showToast('All scores cleared', 'warning');
            }
        }
        
        function saveStaffAssignment(staffId) {
            const subjectId = <?php echo $active_subject_id; ?>;
            const recordId = <?php echo $record_id; ?>;
            
            fetch('exam_assign_staff.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=assign&subject_id=${subjectId}&staff_id=${staffId}&record_id=${recordId}`
            })
            .then(res => res.json())
            .then(data => {
                const msgSpan = document.getElementById('assignMsg');
                if (msgSpan) {
                    msgSpan.style.display = 'inline';
                    setTimeout(() => {
                        msgSpan.style.display = 'none';
                    }, 2000);
                }
            })
            .catch(err => console.error('Error:', err));
        }
        
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
                background: ${type === 'success' ? '#27ae60' : type === 'warning' ? '#f39c12' : '#3498db'};
                color: white; padding: 10px 20px; border-radius: 8px; z-index: 10000;
                font-size: 14px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                white-space: nowrap; max-width: 90%; white-space: normal; text-align: center;
            `;
            toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle'}"></i> ${message}`;
            document.body.appendChild(toast);
            setTimeout(() => { toast.remove(); }, 3000);
        }
        
        // ── SAVE CONFIRMATION ───────────────────────────────────────────────────
        const scoreForm = document.getElementById('scoreForm');
        if (scoreForm) {
            scoreForm.addEventListener('submit', function(e) {
                const saveBtn = document.getElementById('saveBtn');
                if (saveBtn) {
                    saveBtn.disabled = true;
                    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                }
            });
        }
        
        // ── MOBILE MENU TOGGLE ─────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', function() {
            const toggleBtn = document.getElementById('mobileMenuToggle');
            const overlay = document.getElementById('sidebarOverlay');
            
            let sidebar = document.getElementById('sidebar');
            if (!sidebar) {
                sidebar = document.querySelector('.sidebar');
            }
            if (!sidebar) {
                sidebar = document.querySelector('[class*="sidebar"]');
            }
            
            if (toggleBtn && sidebar) {
                if (window.innerWidth <= 768) {
                    sidebar.style.position = 'fixed';
                    sidebar.style.top = '0';
                    sidebar.style.left = '0';
                    sidebar.style.width = '260px';
                    sidebar.style.height = '100%';
                    sidebar.style.zIndex = '1000';
                    sidebar.style.transform = 'translateX(-100%)';
                    sidebar.style.transition = 'transform 0.3s ease';
                    sidebar.style.overflowY = 'auto';
                }
                
                function toggleSidebar(show) {
                    if (window.innerWidth <= 768) {
                        if (show === undefined) {
                            const isVisible = sidebar.style.transform === 'translateX(0)';
                            show = !isVisible;
                        }
                        
                        if (show) {
                            sidebar.style.transform = 'translateX(0)';
                            if (overlay) overlay.classList.add('active');
                            document.body.style.overflow = 'hidden';
                        } else {
                            sidebar.style.transform = 'translateX(-100%)';
                            if (overlay) overlay.classList.remove('active');
                            document.body.style.overflow = '';
                        }
                    }
                }
                
                toggleBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    toggleSidebar();
                });
                
                if (overlay) {
                    overlay.addEventListener('click', function() {
                        toggleSidebar(false);
                    });
                }
                
                window.addEventListener('resize', function() {
                    if (window.innerWidth > 768) {
                        sidebar.style.transform = '';
                        sidebar.style.position = '';
                        if (overlay) overlay.classList.remove('active');
                        document.body.style.overflow = '';
                    } else {
                        if (sidebar.style.transform !== 'translateX(0)') {
                            sidebar.style.position = 'fixed';
                            sidebar.style.transform = 'translateX(-100%)';
                        }
                    }
                });
                
                const navLinks = sidebar.querySelectorAll('a, button, .nav-item, .nav-link');
                navLinks.forEach(link => {
                    link.addEventListener('click', function() {
                        if (window.innerWidth <= 768) {
                            setTimeout(() => toggleSidebar(false), 150);
                        }
                    });
                });
            }
        });
    </script>
</body>

</html>
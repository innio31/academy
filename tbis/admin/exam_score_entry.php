<?php
// tbis/admin/exam_score_entry.php — Enter exam scores per subject
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

// ── Active subject from GET ───────────────────────────────────────────────────
$active_subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;

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
    error_log("score_entry subjects: " . $e->getMessage());
}

if ($active_subject_id === 0 && !empty($subjects)) {
    $active_subject_id = (int)$subjects[0]['id'];
}

// ── Load students ─────────────────────────────────────────────────────────────
$students = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, full_name, admission_number, gender
          FROM students
         WHERE school_id = ? AND class = ? AND status = 'active'
         ORDER BY full_name ASC
    ");
    $stmt->execute([$school_id, $class]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("score_entry students: " . $e->getMessage());
}

// ── Load existing scores for active subject ───────────────────────────────────
$existing_scores = [];
if ($active_subject_id > 0 && !empty($students)) {
    try {
        $stmt = $pdo->prepare("
            SELECT student_id, score_data, total_score, grade, subject_position
              FROM student_scores
             WHERE school_id=? AND subject_id=? AND session=? AND term=?
        ");
        $stmt->execute([$school_id, $active_subject_id, $session, $term]);
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
        $stmt = $pdo->prepare("
            SELECT DISTINCT subject_id FROM student_scores
             WHERE school_id=? AND session=? AND term=? AND subject_id IN ($ph)
        ");
        $stmt->execute(array_merge([$school_id, $session, $term], $sub_ids));
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

                // SELECT then INSERT/UPDATE (no UNIQUE key on table)
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

            // ── Recalculate subject positions ─────────────────────────────────
            $stmt = $pdo->prepare("
                SELECT id, student_id, total_score FROM student_scores
                 WHERE school_id=? AND subject_id=? AND session=? AND term=?
                 ORDER BY total_score DESC
            ");
            $stmt->execute([$school_id, $post_subject_id, $session, $term]);
            $ranked       = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $sequential   = (int)($record['sequential_positions'] ?? 0);
            $pos          = 1;
            $prev_score   = null;
            $display_pos  = 1;

            foreach ($ranked as $r) {
                $cur_score = (float)$r['total_score'];
                if ($prev_score !== null && $cur_score < $prev_score) {
                    $display_pos = $sequential ? $pos : $pos;
                }
                // Both sequential and dense ranking resolve the same here;
                // for dense (default): same score = same position, next is +1 from tied group count
                if ($prev_score !== null && $cur_score < $prev_score) {
                    $display_pos = $pos; // always position = rank for sequential
                }

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

            // Activity log (non-fatal)
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
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

        /* Sidebar */
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

        /* Layout */
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
        }

        .sidebar-overlay {
            position: fixed;
            inset: 0;
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

        .main-content {
            min-height: 100vh;
            padding: 20px;
            transition: var(--transition);
        }

        /* Top header */
        .top-header {
            background: white;
            padding: 18px 20px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: var(--shadow-sm);
        }

        .top-header h1 {
            color: var(--primary-color);
            font-size: 1.35rem;
            margin-bottom: 3px;
        }

        .top-header p {
            color: #666;
            font-size: 0.82rem;
        }

        .back-btn {
            background: white;
            border: 1px solid var(--light-color);
            color: var(--primary-color);
            padding: 9px 16px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.83rem;
            text-decoration: none;
        }

        /* Step bar */
        .step-bar {
            display: flex;
            background: white;
            border-radius: var(--radius-md);
            padding: 14px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
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
            background: var(--light-color);
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

        .step-done {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .step-current {
            background: #fff;
            color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .step-todo {
            background: var(--light-color);
            color: #999;
            border-color: var(--light-color);
        }

        .step-label {
            font-size: 10px;
            color: #999;
            margin-top: 5px;
            text-align: center;
        }

        /* Alerts */
        .alert {
            padding: 13px 16px;
            border-radius: var(--radius-sm);
            margin-bottom: 18px;
            font-size: 0.86rem;
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

        .alert i {
            flex-shrink: 0;
            margin-top: 2px;
        }

        /* Stat cards */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }

        .stat-box {
            background: white;
            border-radius: var(--radius-md);
            padding: 14px 16px;
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
            font-size: 1.5rem;
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
            font-size: 0.74rem;
            color: #777;
            margin-top: 2px;
        }

        /* Progress bar */
        .progress-wrap {
            background: white;
            border-radius: var(--radius-md);
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 0.82rem;
            color: #555;
            margin-bottom: 8px;
        }

        .progress-bar {
            height: 8px;
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

        /* Two-col layout */
        .content-grid {
            display: grid;
            grid-template-columns: 230px 1fr;
            gap: 20px;
            align-items: start;
        }

        /* Subject sidebar */
        .subject-panel {
            background: white;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            position: sticky;
            top: 20px;
        }

        .subject-panel-header {
            background: var(--primary-color);
            color: white;
            padding: 14px 16px;
            font-size: 0.88rem;
            font-weight: 600;
        }

        .subject-list {
            list-style: none;
            padding: 8px 0;
            max-height: 65vh;
            overflow-y: auto;
        }

        .subject-item a {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 16px;
            color: #333;
            text-decoration: none;
            font-size: 0.83rem;
            transition: background 0.15s;
            gap: 8px;
        }

        .subject-item a:hover {
            background: #f5f6fa;
        }

        .subject-item a.active {
            background: #eef3ff;
            color: var(--primary-color);
            border-left: 3px solid var(--primary-color);
            font-weight: 600;
        }

        .done-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--success-color);
            flex-shrink: 0;
        }

        .pending-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--light-color);
            border: 1px solid #ccc;
            flex-shrink: 0;
        }

        .subject-count {
            font-size: 0.72rem;
            color: #888;
            padding: 6px 16px;
            border-top: 1px solid var(--light-color);
        }

        /* Score card */
        .score-card {
            background: white;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .score-card-header {
            padding: 16px 20px;
            border-bottom: 2px solid var(--light-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .score-card-header h2 {
            color: var(--primary-color);
            font-size: 1rem;
            font-weight: 600;
        }

        .score-card-header .meta {
            font-size: 0.78rem;
            color: #888;
            margin-top: 2px;
        }

        .header-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        /* Score table */
        .table-wrap {
            overflow-x: auto;
        }

        table.score-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.84rem;
            min-width: 520px;
        }

        .score-table th {
            background: var(--light-color);
            padding: 10px 12px;
            font-weight: 600;
            text-align: left;
            border-bottom: 2px solid #ddd;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .score-table th.center,
        .score-table td.center {
            text-align: center;
        }

        .score-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }

        .score-table tr:hover td {
            background: #fafafa;
        }

        .score-table tr.changed td {
            background: #fffbe6;
        }

        .student-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .score-input {
            width: 62px;
            padding: 6px 6px;
            text-align: center;
            border: 1.5px solid #ddd;
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 0.83rem;
            background: #fafafa;
            transition: border-color 0.2s;
        }

        .score-input:focus {
            outline: none;
            border-color: var(--primary-color);
            background: #fff;
        }

        .score-input.over {
            border-color: var(--danger-color);
            background: #fff5f5;
        }

        .score-input.filled {
            background: #f0fff4;
            border-color: #b2dfdb;
        }

        .total-cell {
            font-weight: 700;
            font-size: 0.9rem;
        }

        .grade-badge {
            display: inline-block;
            padding: 2px 10px;
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

        .max-label {
            font-size: 0.72rem;
            color: #999;
            font-weight: 400;
        }

        /* Staff assign bar */
        .assign-bar {
            padding: 10px 20px;
            background: #f9f9f9;
            border-bottom: 1px solid var(--light-color);
            display: flex;
            align-items: center;
            gap: 14px;
            font-size: 0.82rem;
            color: #555;
            flex-wrap: wrap;
        }

        .assign-bar label {
            font-weight: 500;
            white-space: nowrap;
        }

        .assign-bar select {
            padding: 5px 10px;
            border: 1.5px solid #ddd;
            border-radius: var(--radius-sm);
            font-size: 0.82rem;
            font-family: 'Poppins', sans-serif;
            background: white;
        }

        /* Buttons */
        .btn {
            padding: 9px 18px;
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 0.85rem;
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
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            opacity: 0.9;
        }

        .btn-secondary {
            background: white;
            color: var(--primary-color);
            border: 1.5px solid var(--primary-color);
        }

        .btn-secondary:hover {
            background: var(--primary-color);
            color: white;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.78rem;
        }

        /* Score footer */
        .score-footer {
            padding: 14px 20px;
            border-top: 2px solid var(--light-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .footer-left {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .footer-right {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 14px;
            opacity: 0.3;
            display: block;
        }

        .empty-state h3 {
            font-size: 1rem;
            font-weight: 500;
            margin-bottom: 8px;
        }

        .empty-state p {
            font-size: 0.84rem;
        }

        @media(min-width:768px) {

            .mobile-menu-toggle,
            .sidebar-overlay {
                display: none;
            }

            .sidebar {
                transform: translateX(0);
            }

            .main-content {
                margin-left: var(--sidebar-width);
            }
        }

        @media(max-width:960px) {
            .content-grid {
                grid-template-columns: 1fr;
            }

            .subject-panel {
                position: static;
            }

            .subject-list {
                max-height: none;
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            }
        }

        @media(max-width:767px) {
            .main-content {
                padding-top: 70px;
            }

            .stats-row {
                grid-template-columns: 1fr 1fr;
            }
        }
    </style>
</head>

<body>

    <button class="mobile-menu-toggle" id="mobileMenuToggle"><i class="fas fa-bars"></i></button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
                <div class="logo-text">
                    <h3><?php echo htmlspecialchars($school_name); ?></h3>
                    <p>Admin Panel</p>
                </div>
            </div>
        </div>
        <div class="admin-info">
            <h4><?php echo htmlspecialchars($admin_name); ?></h4>
            <p><?php echo ucfirst(str_replace('_', ' ', $admin_role)); ?></p>
        </div>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-students.php"><i class="fas fa-users"></i> Manage Students</a></li>
            <li><a href="manage-staff.php"><i class="fas fa-chalkboard-teacher"></i> Manage Staff</a></li>
            <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Manage Subjects</a></li>
            <li><a href="manage-classes.php"><i class="fas fa-chalkboard"></i> Manage Classes</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="attendance.php"><i class="fas fa-calendar-check"></i> Attendance</a></li>
            <li><a href="report_card_dashboard.php" class="active"><i class="fas fa-file-invoice"></i> Process Results</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="sync.php"><i class="fas fa-sync-alt"></i> Sync</a></li>
            <li><a href="../tbis/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <!-- Main content -->
    <div class="main-content" id="mainContent">

        <div class="top-header">
            <div>
                <h1>Enter Exam Scores</h1>
                <p>
                    <strong><?php echo htmlspecialchars($record['record_name'] ?? "{$session} {$term} Term"); ?></strong>
                    &nbsp;·&nbsp; <?php echo htmlspecialchars($class); ?>
                    &nbsp;·&nbsp; <?php echo htmlspecialchars($session); ?>
                    &nbsp;·&nbsp; <?php echo htmlspecialchars($term); ?> Term
                    &nbsp;·&nbsp;
                    <span style="color:<?php echo ($record['status'] ?? 'draft') === 'published' ? 'var(--success-color)' : 'var(--warning-color)'; ?>">
                        <?php echo ucfirst($record['status'] ?? 'draft'); ?>
                    </span>
                </p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <a href="exam_record_setup.php?edit=<?php echo $record_id; ?>" class="back-btn">
                    <i class="fas fa-cog"></i> Record settings
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

        <!-- Step bar -->
        <div class="step-bar">
            <div class="step-item">
                <div class="step-circle step-done"><i class="fas fa-check" style="font-size:11px"></i></div>
                <div class="step-label">Setup record</div>
            </div>
            <div class="step-item">
                <div class="step-circle step-current">2</div>
                <div class="step-label">Enter scores</div>
            </div>
            <div class="step-item">
                <div class="step-circle step-todo">3</div>
                <div class="step-label">Traits &amp; comments</div>
            </div>
            <div class="step-item">
                <div class="step-circle step-todo">4</div>
                <div class="step-label">Generate cards</div>
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
                <div class="stat-lbl">Students in class</div>
            </div>
            <div class="stat-box">
                <div class="stat-val"><?php echo $total_subjects; ?></div>
                <div class="stat-lbl">Total subjects</div>
            </div>
            <div class="stat-box green">
                <div class="stat-val"><?php echo $completed_subjects; ?></div>
                <div class="stat-lbl">Subjects done</div>
            </div>
            <div class="stat-box amber">
                <div class="stat-val"><?php echo $total_subjects - $completed_subjects; ?></div>
                <div class="stat-lbl">Subjects pending</div>
            </div>
            <?php if ($active_subject_id > 0): ?>
                <div class="stat-box">
                    <div class="stat-val"><?php echo $entered_count; ?>/<?php echo $total_students; ?></div>
                    <div class="stat-lbl">Entered (this subject)</div>
                </div>
                <div class="stat-box">
                    <div class="stat-val"><?php echo $class_avg > 0 ? $class_avg . '%' : '—'; ?></div>
                    <div class="stat-lbl">Class avg (this subject)</div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Progress -->
        <div class="progress-wrap">
            <div class="progress-label">
                <span>Score entry progress</span>
                <span><?php echo $completed_subjects; ?> / <?php echo $total_subjects; ?> subjects &nbsp;·&nbsp; <?php echo $progress_pct; ?>%</span>
            </div>
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

            <div class="content-grid">
                <!-- Subject list panel -->
                <div class="subject-panel">
                    <div class="subject-panel-header">
                        <i class="fas fa-book-open"></i> Subjects
                        <span style="font-weight:400;font-size:0.78rem;opacity:0.8;margin-left:6px">(<?php echo $completed_subjects; ?>/<?php echo $total_subjects; ?>)</span>
                    </div>
                    <ul class="subject-list">
                        <?php foreach ($subjects as $sub):
                            $is_active = (int)$sub['id'] === $active_subject_id;
                            $is_done   = isset($subjects_with_scores[(int)$sub['id']]);
                        ?>
                            <li class="subject-item">
                                <a href="exam_score_entry.php?record_id=<?php echo $record_id; ?>&subject_id=<?php echo $sub['id']; ?>"
                                    class="<?php echo $is_active ? 'active' : ''; ?>">
                                    <span><?php echo htmlspecialchars($sub['subject_name']); ?></span>
                                    <span class="<?php echo $is_done ? 'done-dot' : 'pending-dot'; ?>"
                                        title="<?php echo $is_done ? 'Scores entered' : 'Pending'; ?>"></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="subject-count">
                        <span style="color:var(--success-color)">● <?php echo count($subjects_with_scores); ?> done</span>
                        &nbsp;&nbsp;
                        <span style="color:#ccc">● <?php echo $total_subjects - count($subjects_with_scores); ?> pending</span>
                    </div>
                </div>

                <!-- Score entry -->
                <div>
                    <?php if ($active_subject_id > 0 && $active_subject_name !== ''): ?>

                        <form method="POST" id="scoreForm">
                            <input type="hidden" name="action" value="save_scores">
                            <input type="hidden" name="subject_id" value="<?php echo $active_subject_id; ?>">

                            <div class="score-card">
                                <div class="score-card-header">
                                    <div>
                                        <h2><i class="fas fa-pencil-alt" style="font-size:0.9rem;margin-right:6px"></i><?php echo htmlspecialchars($active_subject_name); ?></h2>
                                        <div class="meta">
                                            <?php echo htmlspecialchars($class); ?> &nbsp;·&nbsp;
                                            <?php echo htmlspecialchars($term); ?> Term &nbsp;·&nbsp;
                                            <?php echo htmlspecialchars($session); ?> &nbsp;·&nbsp;
                                            Max: <?php echo (int)$record['max_score']; ?> marks
                                            &nbsp;(<?php echo implode(' + ', array_map(fn($s) => htmlspecialchars($s['label']) . '/' . (int)$s['max'], $score_types)); ?>)
                                        </div>
                                    </div>
                                    <div class="header-actions">
                                        <?php if ($next_subject): ?>
                                            <a href="exam_score_entry.php?record_id=<?php echo $record_id; ?>&subject_id=<?php echo $next_subject['id']; ?>" class="btn btn-secondary btn-sm">
                                                Next: <?php echo htmlspecialchars($next_subject['subject_name']); ?> <i class="fas fa-arrow-right"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Staff assignment -->
                                <div class="assign-bar">
                                    <label><i class="fas fa-chalkboard-teacher"></i> Assigned staff:</label>
                                    <select id="staffAssign" onchange="saveStaffAssignment(this.value)">
                                        <option value="">— Not assigned —</option>
                                        <?php foreach ($staff_list as $sf): ?>
                                            <option value="<?php echo htmlspecialchars($sf['staff_id']); ?>"
                                                <?php echo $sf['staff_id'] === $assigned_staff_id ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($sf['full_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span id="assignMsg" style="font-size:0.78rem;color:var(--success-color);display:none"><i class="fas fa-check"></i> Saved</span>
                                    <span style="margin-left:auto;font-size:0.76rem;color:#bbb"><i class="fas fa-keyboard"></i> Tab / Enter to navigate · ↑↓ to move between students</span>
                                </div>

                                <!-- Table -->
                                <div class="table-wrap">
                                    <table class="score-table" id="scoreTable">
                                        <thead>
                                            <tr>
                                                <th style="width:36px">#</th>
                                                <th style="min-width:160px">Student</th>
                                                <th style="width:90px">Adm. No.</th>
                                                <?php foreach ($score_types as $st): ?>
                                                    <th class="center">
                                                        <?php echo htmlspecialchars($st['label']); ?>
                                                        <br><span class="max-label">/ <?php echo (int)$st['max']; ?></span>
                                                    </th>
                                                <?php endforeach; ?>
                                                <th class="center">Total<br><span class="max-label">/ <?php echo (int)$record['max_score']; ?></span></th>
                                                <th class="center">Grade</th>
                                                <th class="center">Remark</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($students as $idx => $stu):
                                                $stu_id   = (int)$stu['id'];
                                                $saved    = $existing_scores[$stu_id] ?? null;
                                                $words    = array_filter(explode(' ', $stu['full_name']));
                                                $initials = strtoupper(implode('', array_map(fn($w) => $w[0], array_slice($words, 0, 2))));
                                                $graded   = $saved ? getGradeInfo((float)$saved['total_score'], $grading_scale) : null;
                                                $gc       = $graded ? 'g-' . strtolower($graded['grade'][0]) : '';
                                            ?>
                                                <tr id="row_<?php echo $stu_id; ?>" data-student="<?php echo $stu_id; ?>">
                                                    <td style="color:#aaa;font-size:0.78rem"><?php echo $idx + 1; ?></td>
                                                    <td>
                                                        <div class="student-cell">
                                                            <div class="avatar"><?php echo htmlspecialchars($initials); ?></div>
                                                            <div>
                                                                <div style="font-size:0.85rem;font-weight:500"><?php echo htmlspecialchars($stu['full_name']); ?></div>
                                                                <?php if ($stu['gender']): ?><div style="font-size:0.72rem;color:#aaa"><?php echo htmlspecialchars($stu['gender']); ?></div><?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td style="font-size:0.78rem;color:#888"><?php echo htmlspecialchars($stu['admission_number']); ?></td>

                                                    <?php foreach ($score_types as $st):
                                                        $lbl    = $st['label'];
                                                        $maxVal = (int)$st['max'];
                                                        $val    = $saved ? ($saved['score_data'][$lbl] ?? '') : '';
                                                        $filled = ($val !== '' && $val !== null);
                                                    ?>
                                                        <td class="center">
                                                            <input type="number"
                                                                name="scores[<?php echo $stu_id; ?>][<?php echo htmlspecialchars($lbl); ?>]"
                                                                class="score-input<?php echo $filled ? ' filled' : ''; ?>"
                                                                value="<?php echo $filled ? htmlspecialchars((string)$val) : ''; ?>"
                                                                min="0" max="<?php echo $maxVal; ?>" step="0.5"
                                                                placeholder="—"
                                                                data-max="<?php echo $maxVal; ?>"
                                                                data-student="<?php echo $stu_id; ?>"
                                                                oninput="onScoreInput(this,<?php echo $stu_id; ?>)"
                                                                onkeydown="handleKey(event,this)">
                                                        </td>
                                                    <?php endforeach; ?>

                                                    <td class="center total-cell" id="total_<?php echo $stu_id; ?>">
                                                        <?php echo $saved ? number_format((float)$saved['total_score'], 1) : '—'; ?>
                                                    </td>
                                                    <td class="center" id="grade_<?php echo $stu_id; ?>">
                                                        <?php if ($graded): ?>
                                                            <span class="grade-badge <?php echo $gc; ?>"><?php echo htmlspecialchars($graded['grade']); ?></span>
                                                            <?php else: ?>—<?php endif; ?>
                                                    </td>
                                                    <td id="remark_<?php echo $stu_id; ?>" style="font-size:0.78rem;color:#888">
                                                        <?php echo $graded ? htmlspecialchars($graded['remark']) : '—'; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Footer -->
                                <div class="score-footer">
                                    <div class="footer-left">
                                        <span style="font-size:0.78rem;color:#888"><?php echo $entered_count; ?> of <?php echo $total_students; ?> students have scores</span>
                                    </div>
                                    <div class="footer-right">
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="fillZeros()">
                                            <i class="fas fa-fill-drip"></i> Fill empty with 0
                                        </button>
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="clearAllInputs()">
                                            <i class="fas fa-undo"></i> Clear inputs
                                        </button>
                                        <button type="submit" class="btn btn-primary" id="saveBtn">
                                            <i class="fas fa-save"></i> Save &amp; recalculate positions
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>

                        <!-- All done CTA -->
                        <?php if ($completed_subjects >= $total_subjects): ?>
                            <div class="alert alert-success" style="margin-top:16px">
                                <i class="fas fa-check-circle"></i>
                                <div>
                                    <strong>All <?php echo $total_subjects; ?> subjects complete!</strong>
                                    Proceed to enter traits &amp; comments for each student.
                                    <br>
                                    <a href="exam_traits_comments.php?record_id=<?php echo $record_id; ?>" class="btn btn-success btn-sm" style="margin-top:8px">
                                        <i class="fas fa-arrow-right"></i> Traits &amp; comments
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <div class="score-card">
                            <div class="empty-state">
                                <i class="fas fa-hand-point-left"></i>
                                <h3>Select a subject</h3>
                                <p>Choose a subject from the list to start entering scores.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div><!-- /content-grid -->
        <?php endif; ?>

        <div style="text-align:center;padding:20px;color:#999;font-size:0.8rem;border-top:1px solid var(--light-color);margin-top:20px">
            &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> — Online Portal
        </div>
    </div>

    <script>
        const GRADE_SCALE = <?php echo json_encode($grading_scale); ?>;
        const COL_COUNT = <?php echo count($score_types); ?>;

        function getGrade(total) {
            for (const r of GRADE_SCALE)
                if (total >= r.min && total <= r.max) return r;
            return {
                grade: 'F',
                remark: 'Fail'
            };
        }

        function gradeCls(g) {
            return ['a', 'b', 'c', 'd'].includes(g[0].toLowerCase()) ? 'g-' + g[0].toLowerCase() : 'g-f';
        }

        function onScoreInput(inp, studentId) {
            const max = parseFloat(inp.dataset.max);
            const v = parseFloat(inp.value);
            inp.classList.toggle('over', !isNaN(v) && v > max);
            inp.classList.toggle('filled', inp.value.trim() !== '' && !isNaN(v) && v <= max);
            recalcRow(studentId);
        }

        function recalcRow(sid) {
            const inputs = document.querySelectorAll(`input[data-student="${sid}"]`);
            let total = 0,
                hasAny = false;
            inputs.forEach(i => {
                const v = parseFloat(i.value);
                if (!isNaN(v)) {
                    total += v;
                    hasAny = true;
                }
            });

            const tEl = document.getElementById('total_' + sid);
            const gEl = document.getElementById('grade_' + sid);
            const rEl = document.getElementById('remark_' + sid);

            if (!hasAny) {
                tEl.textContent = '—';
                gEl.innerHTML = '—';
                rEl.textContent = '—';
                return;
            }
            const g = getGrade(total);
            tEl.textContent = Number.isInteger(total) ? total : total.toFixed(1);
            gEl.innerHTML = `<span class="grade-badge ${gradeCls(g.grade)}">${g.grade}</span>`;
            rEl.textContent = g.remark;
            document.getElementById('row_' + sid)?.classList.add('changed');
        }

        function handleKey(e, inp) {
            if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
            e.preventDefault();
            const all = Array.from(document.querySelectorAll('.score-input'));
            const idx = all.indexOf(inp);
            const next = e.key === 'ArrowDown' ? all[idx + COL_COUNT] : all[idx - COL_COUNT];
            next?.focus();
        }

        function fillZeros() {
            document.querySelectorAll('.score-input').forEach(i => {
                if (i.value.trim() === '') {
                    i.value = 0;
                    i.classList.add('filled');
                }
            });
            <?php foreach ($students as $s): ?>recalcRow(<?php echo (int)$s['id']; ?>);
        <?php endforeach; ?>
        }

        function clearAllInputs() {
            if (!confirm('Clear all visible inputs? Saved DB values are unchanged until you save.')) return;
            document.querySelectorAll('.score-input').forEach(i => {
                i.value = '';
                i.classList.remove('filled', 'over');
            });
            <?php foreach ($students as $s): ?>recalcRow(<?php echo (int)$s['id']; ?>);
        <?php endforeach; ?>
        }

        function saveStaffAssignment(staffId) {
            const msg = document.getElementById('assignMsg');
            fetch('ajax_assign_staff.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    subject_id: <?php echo $active_subject_id; ?>,
                    staff_id: staffId,
                    school_id: <?php echo $school_id; ?>
                })
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    msg.style.display = 'inline';
                    setTimeout(() => msg.style.display = 'none', 2500);
                }
            }).catch(() => {});
        }

        document.getElementById('scoreForm')?.addEventListener('submit', function(e) {
            const bad = document.querySelectorAll('.score-input.over');
            if (bad.length) {
                e.preventDefault();
                alert(`${bad.length} score(s) exceed the allowed maximum. Please fix them before saving.`);
                bad[0].focus();
                return;
            }
            const btn = document.getElementById('saveBtn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            }
        });

        // Sidebar
        const sb = document.getElementById('sidebar');
        const ov = document.getElementById('sidebarOverlay');
        const tog = document.getElementById('mobileMenuToggle');
        tog.addEventListener('click', () => {
            sb.classList.toggle('active');
            ov.classList.toggle('active');
            document.body.style.overflow = sb.classList.contains('active') ? 'hidden' : '';
        });
        ov.addEventListener('click', () => {
            sb.classList.remove('active');
            ov.classList.remove('active');
            document.body.style.overflow = '';
        });
    </script>

</body>

</html>
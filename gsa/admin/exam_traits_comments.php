<?php
// gsa/admin/exam_traits_comments.php — Step 3: Traits, Comments & Attendance (MODAL-BASED UX)
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
$student_ids    = array_column($students, 'id');

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

// ── Grading scale from record ─────────────────────────────────────────────────
$grading_data = [];
if (!empty($record['score_types'])) {
    $decoded = json_decode($record['score_types'], true);
    $grading_data = $decoded['grading_scale'] ?? [];
}
if (empty($grading_data)) {
    $grading_data = [
        ['grade' => 'A', 'min' => 75, 'max' => 100, 'remark' => 'Excellent'],
        ['grade' => 'B', 'min' => 65, 'max' => 74, 'remark' => 'Very Good'],
        ['grade' => 'C', 'min' => 50, 'max' => 64, 'remark' => 'Good'],
        ['grade' => 'D', 'min' => 40, 'max' => 49, 'remark' => 'Pass'],
        ['grade' => 'F', 'min' => 0, 'max' => 39, 'remark' => 'Fail'],
    ];
}

// ── Next class options ──────────────────────────────────────────────────────
$classes_list = [];
try {
    $stmt = $pdo->prepare("SELECT class_name FROM classes WHERE school_id=? AND status='active' ORDER BY sort_order, class_name");
    $stmt->execute([$school_id]);
    $classes_list = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { /* non-fatal */
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

// ── Handle AJAX: load student data for modal ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'load_student') {
        $student_id = (int)$_POST['student_id'];
        $response = ['success' => false, 'data' => []];
        
        try {
            // Get student info
            $stmt = $pdo->prepare("SELECT id, full_name, admission_number, gender FROM students WHERE id = ? AND school_id = ?");
            $stmt->execute([$student_id, $school_id]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($student) {
                // Load affective traits
                $stmt = $pdo->prepare("SELECT * FROM affective_traits WHERE student_id=? AND session=? AND term=? LIMIT 1");
                $stmt->execute([$student_id, $session, $term]);
                $affective = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                
                // Load psychomotor
                $stmt = $pdo->prepare("SELECT * FROM psychomotor_skills WHERE school_id=? AND student_id=? AND session=? AND term=? LIMIT 1");
                $stmt->execute([$school_id, $student_id, $session, $term]);
                $psychomotor = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                
                // Load comments
                $stmt = $pdo->prepare("SELECT * FROM student_comments WHERE school_id=? AND student_id=? AND session=? AND term=? LIMIT 1");
                $stmt->execute([$school_id, $student_id, $session, $term]);
                $comments = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                
                // Load position (promoted_to)
                $stmt = $pdo->prepare("SELECT * FROM student_positions WHERE school_id=? AND student_id=? AND session=? AND term=? LIMIT 1");
                $stmt->execute([$school_id, $student_id, $session, $term]);
                $position = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                
                // Calculate student's average for auto comment
                $stmt = $pdo->prepare("SELECT AVG(percentage) as avg_score FROM student_scores 
                    WHERE school_id=? AND student_id=? AND session=? AND term=?");
                $stmt->execute([$school_id, $student_id, $session, $term]);
                $avg_data = $stmt->fetch(PDO::FETCH_ASSOC);
                $student_average = floatval($avg_data['avg_score'] ?? 0);
                
                $student_grade = '';
                foreach ($grading_data as $grade_range) {
                    if ($student_average >= $grade_range['min'] && $student_average <= $grade_range['max']) {
                        $student_grade = $grade_range['grade'];
                        break;
                    }
                }
                
                $auto_principal_comment = '';
                if ($student_grade && isset($principal_comments_map[$student_grade])) {
                    $auto_principal_comment = $principal_comments_map[$student_grade];
                }
                
                $response['success'] = true;
                $response['data'] = [
                    'student' => $student,
                    'affective' => $affective,
                    'psychomotor' => $psychomotor,
                    'comments' => $comments,
                    'position' => $position,
                    'student_average' => $student_average,
                    'student_grade' => $student_grade,
                    'auto_principal_comment' => $auto_principal_comment,
                    'days_school_opened' => (int)($record['days_school_opened'] ?? 0)
                ];
            }
        } catch (Exception $e) {
            $response['message'] = $e->getMessage();
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    if ($action === 'save_traits') {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $response = ['success' => false, 'message' => ''];
        
        if (!$student_id) {
            $response['message'] = 'Invalid student ID';
            echo json_encode($response);
            exit();
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
            $chk->execute([$student_id, $session, $term]);
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
                    $school_id, $student_id, $session, $term,
                    $af['punctuality'], $af['attendance'], $af['politeness'], $af['honesty'],
                    $af['neatness'], $af['reliability'], $af['relationship'], $af['self_control']
                ]);
            }
            
            // ── 2. Psychomotor skills ─────────────────────────────────────────────
            $pm = [];
            foreach (array_keys($psychomotor_fields) as $f) {
                $val = $_POST['psychomotor'][$f] ?? null;
                $pm[$f] = in_array($val, ['A', 'B', 'C', 'D', 'E']) ? $val : null;
            }
            
            $chk2 = $pdo->prepare("SELECT id FROM psychomotor_skills WHERE school_id=? AND student_id=? AND session=? AND term=? LIMIT 1");
            $chk2->execute([$school_id, $student_id, $session, $term]);
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
                    $school_id, $student_id, $session, $term,
                    $pm['handwriting'], $pm['verbal_fluency'], $pm['sports'],
                    $pm['handling_tools'], $pm['drawing_painting'], $pm['musical_skills']
                ]);
            }
            
            // ── 3. Comments & attendance ──────────────────────────────────────────
            $days_opened = (int)($record['days_school_opened'] ?? 90);
            $days_present = min((int)($_POST['days_present'] ?? 0), $days_opened);
            $days_absent  = $days_opened - $days_present;
            
            $tc   = trim($_POST['teachers_comment'] ?? '');
            $pc   = trim($_POST['principals_comment'] ?? '');
            $tcn  = trim($_POST['class_teachers_name'] ?? '');
            $pcn  = trim($_POST['principals_name'] ?? '');
            
            $chk3 = $pdo->prepare("SELECT id FROM student_comments WHERE school_id=? AND student_id=? AND session=? AND term=? LIMIT 1");
            $chk3->execute([$school_id, $student_id, $session, $term]);
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
                    $school_id, $student_id, $session, $term,
                    $tc, $pc, $tcn, $pcn, $days_present, $days_absent
                ]);
            }
            
            // ── 4. Promoted to ────────────────────────────────────────────────────
            $promoted_to = trim($_POST['promoted_to'] ?? '');
            $chk4 = $pdo->prepare("SELECT id FROM student_positions WHERE school_id=? AND student_id=? AND session=? AND term=? LIMIT 1");
            $chk4->execute([$school_id, $student_id, $session, $term]);
            $sp_id = $chk4->fetchColumn();
            
            if ($sp_id) {
                $pdo->prepare("UPDATE student_positions SET promoted_to=?, updated_at=NOW() WHERE id=?")->execute([$promoted_to ?: null, $sp_id]);
            } else {
                $pdo->prepare("
                    INSERT INTO student_positions
                        (school_id, student_id, session, term, promoted_to, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                ")->execute([$school_id, $student_id, $session, $term, $promoted_to ?: null]);
            }
            
            // Mark exam record active if still draft
            if (($record['status'] ?? 'draft') === 'draft') {
                $pdo->prepare("UPDATE report_card_settings SET status='active' WHERE id=?")->execute([$record_id]);
            }
            
            $pdo->commit();
            
            // Activity log
            try {
                $sname = $_POST['student_name'] ?? "student #{$student_id}";
                $pdo->prepare("INSERT INTO activity_logs (user_id,user_type,activity,school_id) VALUES (?,?,?,?)")
                    ->execute([$admin_id, 'admin', "Saved traits & comments for {$sname} — {$class} {$term} Term {$session}", $school_id]);
            } catch (Exception $e) { /* skip */
            }
            
            $response['success'] = true;
            $response['message'] = 'Student data saved successfully!';
            $response['completed_count'] = $completed_count + 1;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $response['message'] = 'Error saving: ' . $e->getMessage();
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
}

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
            --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.12);
            --radius: 10px;
            --radius-lg: 16px;
            --transition: all 0.25s ease;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            color: #333;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Mobile Menu Toggle */
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

        .sidebar-overlay.active { opacity: 1; visibility: visible; }

        .main {
            min-height: 100vh;
            padding: 20px;
            transition: var(--transition);
        }

        /* Desktop Styles */
        @media (min-width: 768px) {
            .mobile-menu-toggle, .sidebar-overlay { display: none; }
            .main { margin-left: var(--sidebar-w); }
        }
        
        @media (max-width: 767px) {
            .main { padding-top: 70px; }
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

        .s-done { background: var(--primary); color: white; border-color: var(--primary); }
        .s-cur { background: white; color: var(--primary); border-color: var(--primary); }
        .s-todo { background: var(--light); color: #999; border-color: var(--light); }

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

        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid var(--success); }
        .alert-danger { background: #f8d7da; color: #721c24; border-left: 4px solid var(--danger); }
        .alert-warning { background: #fff3cd; color: #856404; border-left: 4px solid var(--warning); }

        /* Stats Row */
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

        .stat-box.green { border-top-color: var(--success); }
        .stat-box.amber { border-top-color: var(--warning); }

        .stat-val {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-box.green .stat-val { color: var(--success); }
        .stat-box.amber .stat-val { color: var(--warning); }

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

        /* Student Grid Cards - Like Dashboard */
        .students-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .student-card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            overflow: hidden;
            transition: var(--transition);
            cursor: pointer;
            border: 1px solid transparent;
        }

        .student-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary);
        }

        .student-card.completed {
            border-left: 4px solid var(--success);
        }

        .student-card-header {
            padding: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid var(--light);
            background: #fafafa;
        }

        .student-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 18px;
            flex-shrink: 0;
        }

        .student-info h3 {
            font-size: 0.95rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 4px;
        }

        .student-info p {
            font-size: 0.7rem;
            color: #888;
        }

        .student-card-body {
            padding: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .status-done {
            background: #d4edda;
            color: #155724;
        }

        .status-pending {
            background: #e2e3e5;
            color: #383d41;
        }

        .edit-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }

        .student-card:hover .edit-icon {
            transform: scale(1.1);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal.active { display: flex; }

        .modal-container {
            background: white;
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 800px;
            max-height: 85vh;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .modal-header {
            padding: 16px 20px;
            background: var(--primary);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 { font-size: 1rem; font-weight: 500; }
        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
        }

        .modal-body {
            padding: 20px;
            max-height: calc(85vh - 130px);
            overflow-y: auto;
        }

        .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--light);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            background: #fafafa;
        }

        /* Form elements inside modal */
        .form-section {
            margin-bottom: 24px;
            border: 1px solid var(--light);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .form-section-header {
            padding: 12px 16px;
            background: #f5f6fa;
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--primary);
            border-bottom: 1px solid var(--light);
        }

        .form-section-body { padding: 16px; }

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
            padding: 8px 12px;
            background: #f9f9f9;
            border-radius: 8px;
        }

        .trait-name { font-size: 0.8rem; color: #444; flex: 1; }

        .rating-group { display: flex; gap: 4px; }

        .rating-btn {
            width: 32px;
            height: 28px;
            border: 1.5px solid #ddd;
            background: white;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            color: #999;
            transition: var(--transition);
        }

        .rating-btn:hover { border-color: var(--primary); color: var(--primary); }
        .rating-btn.sel-A { background: #d4edda; border-color: #27ae60; color: #155724; }
        .rating-btn.sel-B { background: #cce5ff; border-color: #3498db; color: #004085; }
        .rating-btn.sel-C { background: #fff3cd; border-color: #f39c12; color: #856404; }
        .rating-btn.sel-D { background: #ffe5cc; border-color: #e67e22; color: #7d4000; }
        .rating-btn.sel-E { background: #f8d7da; border-color: #e74c3c; color: #721c24; }

        .rating-group input[type="radio"] { display: none; }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 14px;
        }

        .form-row.full { grid-template-columns: 1fr; }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        label { font-size: 0.75rem; font-weight: 500; color: #555; }

        input, select, textarea {
            padding: 8px 12px;
            border: 1.5px solid #e0e0e0;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.8rem;
            width: 100%;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary);
        }

        textarea { resize: vertical; min-height: 60px; }

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
            gap: 6px;
            transition: var(--transition);
        }

        .btn-primary { background: var(--primary); color: white; }
        .btn-secondary { background: white; color: var(--primary); border: 1px solid var(--primary); }
        .btn-success { background: var(--success); color: white; }
        .btn-sm { padding: 6px 12px; font-size: 0.7rem; }

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

        .suggestion-box {
            padding: 10px 12px;
            margin-bottom: 10px;
            background: #e8f4f8;
            border-radius: 8px;
            border-left: 3px solid #17a2b8;
            font-size: 0.8rem;
        }

        .suggestion-box i { color: #17a2b8; margin-right: 6px; }

        .attend-hint {
            font-size: 0.7rem;
            margin-top: 4px;
            color: var(--warning);
        }

        .attend-hint.ok { color: var(--success); }

        @media (max-width: 600px) {
            .traits-grid { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; }
            .students-grid { grid-template-columns: 1fr; }
            .stats-row { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>

<body>

    <button class="mobile-menu-toggle" id="mobileMenuToggle"><i class="fas fa-bars"></i></button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <?php require_once 'includes/sidebar.php'; ?>

    <div class="main" id="mainContent">

        <div class="top-header">
            <div>
                <h1>Traits, Comments &amp; Attendance</h1>
                <p>
                    <strong><?php echo htmlspecialchars($record['record_name'] ?? "{$session} {$term} Term"); ?></strong>
                    · <?php echo htmlspecialchars($class); ?>
                    · <?php echo htmlspecialchars($session); ?>
                    · <?php echo htmlspecialchars($term); ?> Term
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
            <div class="step-item"><div class="step-circle s-done"><i class="fas fa-check" style="font-size:10px"></i></div><div class="step-lbl">Setup record</div></div>
            <div class="step-item"><div class="step-circle s-done"><i class="fas fa-check" style="font-size:10px"></i></div><div class="step-lbl">Enter scores</div></div>
            <div class="step-item"><div class="step-circle s-cur">3</div><div class="step-lbl">Traits &amp; comments</div></div>
            <div class="step-item"><div class="step-circle s-todo">4</div><div class="step-lbl">Generate cards</div></div>
            <div class="step-item"><div class="step-circle s-todo">5</div><div class="step-lbl">Publish</div></div>
        </div>

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-box"><div class="stat-val"><?php echo $total_students; ?></div><div class="stat-lbl">Total students</div></div>
            <div class="stat-box green"><div class="stat-val"><?php echo $completed_count; ?></div><div class="stat-lbl">Records completed</div></div>
            <div class="stat-box amber"><div class="stat-val"><?php echo $total_students - $completed_count; ?></div><div class="stat-lbl">Pending</div></div>
            <div class="stat-box"><div class="stat-val"><?php echo (int)($record['days_school_opened'] ?? 0); ?></div><div class="stat-lbl">School days</div></div>
        </div>

        <!-- Progress -->
        <div class="progress-wrap">
            <div class="progress-label"><span>Student records progress</span><span><?php echo $completed_count; ?> / <?php echo $total_students; ?> completed · <?php echo $progress_pct; ?>%</span></div>
            <div class="progress-bar"><div class="progress-fill" style="width:<?php echo $progress_pct; ?>%"></div></div>
        </div>

        <?php if (empty($students)): ?>
            <div class="empty-state">
                <i class="fas fa-user-graduate"></i>
                <h3>No active students in <?php echo htmlspecialchars($class); ?></h3>
                <p>Please add students to this class first.</p>
            </div>
        <?php else: ?>

            <!-- Student Grid Cards -->
            <div class="students-grid" id="studentsGrid">
                <?php foreach ($students as $s):
                    $s_id = (int)$s['id'];
                    $is_done = isset($completed_ids[$s_id]);
                    $initials = strtoupper(substr($s['full_name'], 0, 2));
                    if (strpos($s['full_name'], ' ') !== false) {
                        $parts = explode(' ', $s['full_name']);
                        $initials = strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
                    }
                ?>
                    <div class="student-card <?php echo $is_done ? 'completed' : ''; ?>" onclick="openStudentModal(<?php echo $s_id; ?>, '<?php echo htmlspecialchars(addslashes($s['full_name'])); ?>')">
                        <div class="student-card-header">
                            <div class="student-avatar"><?php echo $initials; ?></div>
                            <div class="student-info">
                                <h3><?php echo htmlspecialchars($s['full_name']); ?></h3>
                                <p><?php echo htmlspecialchars($s['admission_number']); ?></p>
                            </div>
                        </div>
                        <div class="student-card-body">
                            <span class="status-badge <?php echo $is_done ? 'status-done' : 'status-pending'; ?>">
                                <i class="fas <?php echo $is_done ? 'fa-check-circle' : 'fa-clock'; ?>"></i>
                                <?php echo $is_done ? 'Completed' : 'Pending'; ?>
                            </span>
                            <div class="edit-icon">
                                <i class="fas fa-edit"></i>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($completed_count >= $total_students && $total_students > 0): ?>
                <div class="alert alert-success" style="margin-top: 20px;">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <strong>All <?php echo $total_students; ?> students done!</strong> Proceed to generate report cards.
                        <div style="margin-top: 8px;">
                            <a href="exam_generate_cards.php?record_id=<?php echo $record_id; ?>" class="btn btn-success btn-sm">
                                <i class="fas fa-file-alt"></i> Generate report cards
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        <?php endif; ?>

        <div style="text-align:center;padding:20px;color:#999;font-size:.8rem;border-top:1px solid var(--light);margin-top:20px">
            &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> — Online Portal
        </div>
    </div>

    <!-- Modal for Student Data Entry -->
    <div id="studentModal" class="modal">
        <div class="modal-container">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-user-graduate"></i> Student Details</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody">
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i> Loading...
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveModalBtn" onclick="saveStudentData()">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </div>
    </div>

    <script>
        let currentStudentId = null;
        let currentStudentName = '';
        const ratingLabels = <?php echo json_encode($rating_labels); ?>;
        const affectiveFields = <?php echo json_encode($affective_fields); ?>;
        const psychomotorFields = <?php echo json_encode($psychomotor_fields); ?>;
        const classesList = <?php echo json_encode($classes_list); ?>;
        const defaultClassTeacher = '<?php echo addslashes($default_class_teacher); ?>';
        const daysOpened = <?php echo (int)($record['days_school_opened'] ?? 0); ?>;

        function openStudentModal(studentId, studentName) {
            currentStudentId = studentId;
            currentStudentName = studentName;
            document.getElementById('modalTitle').innerHTML = `<i class="fas fa-user-graduate"></i> ${studentName}`;
            document.getElementById('modalBody').innerHTML = '<div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i> Loading...</div>';
            document.getElementById('studentModal').classList.add('active');
            
            // Load student data via AJAX
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: `action=load_student&student_id=${studentId}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    renderModalForm(data.data);
                } else {
                    document.getElementById('modalBody').innerHTML = `<div class="alert alert-danger">Error loading student data: ${data.message || 'Unknown error'}</div>`;
                }
            })
            .catch(err => {
                document.getElementById('modalBody').innerHTML = `<div class="alert alert-danger">Error: ${err.message}</div>`;
            });
        }
        
        function renderModalForm(studentData) {
            const student = studentData.student;
            const affective = studentData.affective || {};
            const psychomotor = studentData.psychomotor || {};
            const comments = studentData.comments || {};
            const position = studentData.position || {};
            const autoComment = studentData.auto_principal_comment || '';
            const studentGrade = studentData.student_grade || '';
            const studentAvg = studentData.student_average || 0;
            
            // Build affective traits HTML
            let affectiveHtml = '';
            for (const [field, label] of Object.entries(affectiveFields)) {
                const currentVal = affective[field] || '';
                affectiveHtml += `
                    <div class="trait-row">
                        <span class="trait-name">${label}</span>
                        <div class="rating-group" data-field="affective[${field}]">
                            ${['A','B','C','D','E'].map(grade => `
                                <input type="radio" name="affective[${field}]" id="af_${field}_${grade}" value="${grade}" ${currentVal === grade ? 'checked' : ''}>
                                <button type="button" class="rating-btn ${currentVal === grade ? `sel-${grade}` : ''}" onclick="selectRating(this, 'af_${field}_${grade}')" title="${ratingLabels[grade]}" data-grade="${grade}">${grade}</button>
                            `).join('')}
                        </div>
                    </div>
                `;
            }
            
            // Build psychomotor HTML
            let psychomotorHtml = '';
            for (const [field, label] of Object.entries(psychomotorFields)) {
                const currentVal = psychomotor[field] || '';
                psychomotorHtml += `
                    <div class="trait-row">
                        <span class="trait-name">${label}</span>
                        <div class="rating-group" data-field="psychomotor[${field}]">
                            ${['A','B','C','D','E'].map(grade => `
                                <input type="radio" name="psychomotor[${field}]" id="pm_${field}_${grade}" value="${grade}" ${currentVal === grade ? 'checked' : ''}>
                                <button type="button" class="rating-btn ${currentVal === grade ? `sel-${grade}` : ''}" onclick="selectRating(this, 'pm_${field}_${grade}')" title="${ratingLabels[grade]}" data-grade="${grade}">${grade}</button>
                            `).join('')}
                        </div>
                    </div>
                `;
            }
            
            // Build class options for promoted_to
            let classOptions = '<option value="">— Select next class —</option><option value="Repeat" ' + (position.promoted_to === 'Repeat' ? 'selected' : '') + '>Repeat current class</option>';
            classesList.forEach(cls => {
                classOptions += `<option value="${cls.replace(/"/g, '&quot;')}" ${position.promoted_to === cls ? 'selected' : ''}>${cls}</option>`;
            });
            
            const daysPresent = comments.days_present || 0;
            const daysAbsent = daysOpened - daysPresent;
            
            const modalHtml = `
                <form id="studentDataForm">
                    <input type="hidden" name="student_id" value="${student.id}">
                    <input type="hidden" name="student_name" value="${student.full_name.replace(/"/g, '&quot;')}">
                    
                    <!-- Affective Traits -->
                    <div class="form-section">
                        <div class="form-section-header"><i class="fas fa-heart"></i> Affective Traits</div>
                        <div class="form-section-body">
                            <div class="traits-grid">${affectiveHtml}</div>
                            <div style="margin-top: 10px; text-align: right;">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="clearRatings('affective')"><i class="fas fa-undo"></i> Clear all</button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Psychomotor Skills -->
                    <div class="form-section">
                        <div class="form-section-header"><i class="fas fa-running"></i> Psychomotor Skills</div>
                        <div class="form-section-body">
                            <div class="traits-grid">${psychomotorHtml}</div>
                            <div style="margin-top: 10px; text-align: right;">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="clearRatings('psychomotor')"><i class="fas fa-undo"></i> Clear all</button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Attendance -->
                    <div class="form-section">
                        <div class="form-section-header"><i class="fas fa-calendar-check"></i> Attendance</div>
                        <div class="form-section-body">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Days school opened (total)</label>
                                    <input type="number" value="${daysOpened}" readonly style="background:#f0f0f0;">
                                </div>
                                <div class="form-group">
                                    <label>Days present</label>
                                    <input type="number" name="days_present" id="daysPresentInput" min="0" max="${daysOpened}" value="${daysPresent}" oninput="updateAbsent()">
                                    <div class="attend-hint" id="attendHint">Days absent: <strong>${daysAbsent}</strong></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Comments & Promotions -->
                    <div class="form-section">
                        <div class="form-section-header"><i class="fas fa-comment-dots"></i> Comments & Next Class</div>
                        <div class="form-section-body">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Class Teacher's Name</label>
                                    <input type="text" name="class_teachers_name" value="${(comments.class_teachers_name || defaultClassTeacher).replace(/"/g, '&quot;')}" placeholder="e.g. Mrs. Okonkwo Ngozi">
                                    ${defaultClassTeacher ? `<small style="color:#666;">Default: ${defaultClassTeacher}</small>` : ''}
                                </div>
                                <div class="form-group">
                                    <label>Principal's Name</label>
                                    <input type="text" name="principals_name" value="${(comments.principals_name || '').replace(/"/g, '&quot;')}" placeholder="e.g. Mr. Adamu Bello">
                                </div>
                            </div>
                            <div class="form-group full">
                                <label>Class Teacher's Comment</label>
                                <textarea name="teachers_comment" placeholder="Write a personalised comment about this student...">${(comments.teachers_comment || '').replace(/"/g, '&quot;')}</textarea>
                            </div>
                            <div class="form-group full">
                                <label>Principal's Comment</label>
                                ${studentGrade && autoComment ? `
                                <div class="suggestion-box">
                                    <i class="fas fa-magic"></i>
                                    <strong>Auto-suggested for grade ${studentGrade}</strong> (Avg: ${studentAvg.toFixed(1)}%): 
                                    <em>"${autoComment.replace(/"/g, '&quot;')}"</em>
                                    <button type="button" onclick="useSuggestedComment()" class="btn btn-secondary btn-sm" style="margin-left:8px;">
                                        <i class="fas fa-check"></i> Use
                                    </button>
                                </div>
                                ` : ''}
                                <textarea name="principals_comment" id="principalsCommentInput" rows="3" placeholder="Principal's comment...">${(comments.principals_comment || autoComment || '').replace(/"/g, '&quot;')}</textarea>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Promoted to (next class)</label>
                                    <select name="promoted_to">${classOptions}</select>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            `;
            
            document.getElementById('modalBody').innerHTML = modalHtml;
        }
        
        function selectRating(btn, radioId) {
            const group = btn.closest('.rating-group');
            const radio = document.getElementById(radioId);
            if (radio.checked) {
                radio.checked = false;
                group.querySelectorAll('.rating-btn').forEach(b => b.className = 'rating-btn');
                return;
            }
            radio.checked = true;
            group.querySelectorAll('.rating-btn').forEach(b => b.className = 'rating-btn');
            btn.className = `rating-btn sel-${btn.dataset.grade}`;
        }
        
        function clearRatings(section) {
            document.querySelectorAll(`input[name^="${section}["]`).forEach(r => r.checked = false);
            document.querySelectorAll(`.rating-group[data-field^="${section}"] .rating-btn`).forEach(b => b.className = 'rating-btn');
        }
        
        function updateAbsent() {
            const present = parseInt(document.getElementById('daysPresentInput')?.value) || 0;
            const absent = daysOpened - present;
            const hint = document.getElementById('attendHint');
            if (hint) {
                hint.innerHTML = `Days absent: <strong>${absent < 0 ? '⚠ Exceeds school days!' : absent}</strong>`;
                hint.className = `attend-hint ${absent < 0 ? '' : 'ok'}`;
            }
        }
        
        function useSuggestedComment() {
            const suggestionBox = document.querySelector('.suggestion-box');
            if (suggestionBox) {
                const em = suggestionBox.querySelector('em');
                if (em) {
                    const suggestedText = em.innerText.replace(/"/g, '');
                    const commentInput = document.getElementById('principalsCommentInput');
                    if (commentInput) commentInput.value = suggestedText;
                }
            }
        }
        
        function saveStudentData() {
            const form = document.getElementById('studentDataForm');
            if (!form) return;
            
            const formData = new FormData(form);
            formData.append('action', 'save_traits');
            
            // Validate days present
            const daysPresent = parseInt(formData.get('days_present')) || 0;
            if (daysPresent > daysOpened) {
                alert(`Days present (${daysPresent}) cannot exceed days school opened (${daysOpened}).`);
                return;
            }
            
            const saveBtn = document.getElementById('saveModalBtn');
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // Update the student card status
                    const cards = document.querySelectorAll('.student-card');
                    for (let card of cards) {
                        if (card.innerHTML.includes(currentStudentName)) {
                            card.classList.add('completed');
                            const statusSpan = card.querySelector('.status-badge');
                            if (statusSpan) {
                                statusSpan.className = 'status-badge status-done';
                                statusSpan.innerHTML = '<i class="fas fa-check-circle"></i> Completed';
                            }
                            break;
                        }
                    }
                    
                    // Update stats
                    const completedCount = <?php echo $completed_count; ?>;
                    const totalStudents = <?php echo $total_students; ?>;
                    const newCompleted = completedCount + 1;
                    document.querySelector('.stat-box.green .stat-val').textContent = newCompleted;
                    document.querySelector('.stat-box.amber .stat-val').textContent = totalStudents - newCompleted;
                    const newPct = Math.round((newCompleted / totalStudents) * 100);
                    document.querySelector('.progress-fill').style.width = newPct + '%';
                    document.querySelector('.progress-label span:last-child').innerHTML = `${newCompleted} / ${totalStudents} completed · ${newPct}%`;
                    
                    showToast(data.message, 'success');
                    closeModal();
                } else {
                    showToast(data.message || 'Error saving data', 'error');
                }
            })
            .catch(err => {
                showToast('Error: ' + err.message, 'error');
            })
            .finally(() => {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
            });
        }
        
        function closeModal() {
            document.getElementById('studentModal').classList.remove('active');
            currentStudentId = null;
        }
        
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed; bottom: 20px; right: 20px; background: ${type === 'success' ? '#27ae60' : '#e74c3c'};
                color: white; padding: 12px 20px; border-radius: 8px; z-index: 10000;
                font-size: 14px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                animation: slideIn 0.3s ease;
            `;
            toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${message}`;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }
        
        // Add animation style
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
        `;
        document.head.appendChild(style);
        
        // Close modal when clicking outside
        document.getElementById('studentModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
        
        // Mobile menu toggle
        (function() {
            const sidebar = document.getElementById('sidebar') || document.querySelector('.sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const toggleBtn = document.getElementById('mobileMenuToggle');
            
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
                }
                
                function toggleSidebar(show) {
                    if (window.innerWidth <= 768) {
                        if (show === undefined) {
                            const isVisible = sidebar.style.transform === 'translateX(0)';
                            show = !isVisible;
                        }
                        sidebar.style.transform = show ? 'translateX(0)' : 'translateX(-100%)';
                        if (overlay) overlay.classList.toggle('active', show);
                        document.body.style.overflow = show ? 'hidden' : '';
                    }
                }
                
                toggleBtn.addEventListener('click', (e) => { e.preventDefault(); toggleSidebar(); });
                if (overlay) overlay.addEventListener('click', () => toggleSidebar(false));
                
                window.addEventListener('resize', () => {
                    if (window.innerWidth > 768) {
                        sidebar.style.transform = '';
                        sidebar.style.position = '';
                        if (overlay) overlay.classList.remove('active');
                        document.body.style.overflow = '';
                    } else if (sidebar.style.transform !== 'translateX(0)') {
                        sidebar.style.position = 'fixed';
                        sidebar.style.transform = 'translateX(-100%)';
                    }
                });
            }
        })();
    </script>
</body>

</html>
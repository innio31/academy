<?php
// admin/manage-exams.php - Complete Exam Management with Card Layout & Modal Actions
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /msv/login.php");
    exit();
}

// Get admin info
if (isset($_SESSION['admin_id'])) {
    $admin_id = $_SESSION['admin_id'];
    $admin_name = $_SESSION['admin_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'super_admin';
} else {
    $admin_id = $_SESSION['user_id'];
    $admin_name = $_SESSION['user_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

// Check permission
if ($admin_role !== 'super_admin' && $admin_role !== 'admin') {
    header("Location: index.php?message=Access+denied&type=error");
    exit();
}

require_once '../includes/config.php';
check_page_access(['admin', 'super_admin']);

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$page_title = "Manage Exams";

// ============================================
// HANDLE FORM SUBMISSIONS
// ============================================

$message = '';
$message_type = '';

// Add new exam
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_exam'])) {
    try {
        $exam_name = trim($_POST['exam_name']);
        $class_name = trim($_POST['class_name']);
        $class_id = !empty($_POST['class_id']) ? intval($_POST['class_id']) : null;
        $subject_id = !empty($_POST['subject_id']) ? intval($_POST['subject_id']) : null;
        $topics = isset($_POST['topics']) ? json_encode(array_map('intval', $_POST['topics'])) : '[]';
        $duration_minutes = intval($_POST['duration_minutes']);
        $objective_count = isset($_POST['objective_count']) ? intval($_POST['objective_count']) : 0;
        $subjective_count = isset($_POST['subjective_count']) ? intval($_POST['subjective_count']) : 0;
        $theory_count = isset($_POST['theory_count']) ? intval($_POST['theory_count']) : 0;
        $exam_type = trim($_POST['exam_type']);
        $instructions = trim($_POST['instructions']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (empty($exam_name)) throw new Exception("Exam name is required");
        if (empty($class_name)) throw new Exception("Class is required");
        if (empty($duration_minutes)) throw new Exception("Duration is required");

        if (!$class_id && !empty($class_name)) {
            $stmt = $pdo->prepare("SELECT id FROM classes WHERE class_name = ? AND school_id = ? LIMIT 1");
            $stmt->execute([$class_name, $school_id]);
            $class_row = $stmt->fetch();
            if ($class_row) $class_id = $class_row['id'];
        }

        $sql = "INSERT INTO exams (exam_name, class, class_id, subject_id, topics, duration_minutes,
                objective_count, subjective_count, theory_count, exam_type, instructions, is_active, school_id, created_at)
                VALUES (:exam_name, :class, :class_id, :subject_id, :topics, :duration_minutes,
                :objective_count, :subjective_count, :theory_count, :exam_type, :instructions, :is_active, :school_id, NOW())";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':exam_name' => $exam_name,
            ':class' => $class_name,
            ':class_id' => $class_id,
            ':subject_id' => $subject_id,
            ':topics' => $topics,
            ':duration_minutes' => $duration_minutes,
            ':objective_count' => $objective_count,
            ':subjective_count' => $subjective_count,
            ':theory_count' => $theory_count,
            ':exam_type' => $exam_type,
            ':instructions' => $instructions,
            ':is_active' => $is_active,
            ':school_id' => $school_id
        ]);

        $message = "Exam added successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// Update exam
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_exam'])) {
    try {
        $exam_id = intval($_POST['exam_id']);
        $exam_name = trim($_POST['exam_name']);
        $class_name = trim($_POST['class_name']);
        $class_id = !empty($_POST['class_id']) ? intval($_POST['class_id']) : null;
        $subject_id = !empty($_POST['subject_id']) ? intval($_POST['subject_id']) : null;
        $topics = isset($_POST['topics']) ? json_encode(array_map('intval', $_POST['topics'])) : '[]';
        $duration_minutes = intval($_POST['duration_minutes']);
        $objective_count = isset($_POST['objective_count']) ? intval($_POST['objective_count']) : 0;
        $subjective_count = isset($_POST['subjective_count']) ? intval($_POST['subjective_count']) : 0;
        $theory_count = isset($_POST['theory_count']) ? intval($_POST['theory_count']) : 0;
        $exam_type = trim($_POST['exam_type']);
        $instructions = trim($_POST['instructions']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        $stmt = $pdo->prepare("SELECT id FROM exams WHERE id = ? AND school_id = ?");
        $stmt->execute([$exam_id, $school_id]);
        if (!$stmt->fetch()) throw new Exception("Exam not found");

        if (!$class_id && !empty($class_name)) {
            $stmt = $pdo->prepare("SELECT id FROM classes WHERE class_name = ? AND school_id = ? LIMIT 1");
            $stmt->execute([$class_name, $school_id]);
            $class_row = $stmt->fetch();
            if ($class_row) $class_id = $class_row['id'];
        }

        $sql = "UPDATE exams SET exam_name = :exam_name, class = :class, class_id = :class_id,
                subject_id = :subject_id, topics = :topics, duration_minutes = :duration_minutes,
                objective_count = :objective_count, subjective_count = :subjective_count,
                theory_count = :theory_count, exam_type = :exam_type, instructions = :instructions,
                is_active = :is_active, updated_at = NOW()
                WHERE id = :exam_id AND school_id = :school_id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':exam_name' => $exam_name,
            ':class' => $class_name,
            ':class_id' => $class_id,
            ':subject_id' => $subject_id,
            ':topics' => $topics,
            ':duration_minutes' => $duration_minutes,
            ':objective_count' => $objective_count,
            ':subjective_count' => $subjective_count,
            ':theory_count' => $theory_count,
            ':exam_type' => $exam_type,
            ':instructions' => $instructions,
            ':is_active' => $is_active,
            ':exam_id' => $exam_id,
            ':school_id' => $school_id
        ]);

        $message = "Exam updated successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// Delete exam
if (isset($_GET['delete_exam'])) {
    try {
        $exam_id = intval($_GET['delete_exam']);
        $stmt = $pdo->prepare("SELECT exam_name FROM exams WHERE id = ? AND school_id = ?");
        $stmt->execute([$exam_id, $school_id]);
        $exam = $stmt->fetch();
        if (!$exam) throw new Exception("Exam not found");

        $pdo->prepare("DELETE FROM exams WHERE id = ? AND school_id = ?")->execute([$exam_id, $school_id]);
        $message = "Exam '{$exam['exam_name']}' deleted successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// Toggle exam status
if (isset($_GET['toggle_status'])) {
    try {
        $exam_id = intval($_GET['toggle_status']);
        $stmt = $pdo->prepare("SELECT is_active FROM exams WHERE id = ? AND school_id = ?");
        $stmt->execute([$exam_id, $school_id]);
        $exam = $stmt->fetch();
        if ($exam) {
            $new_status = $exam['is_active'] ? 0 : 1;
            $pdo->prepare("UPDATE exams SET is_active = ? WHERE id = ? AND school_id = ?")->execute([$new_status, $exam_id, $school_id]);
            $message = "Exam " . ($new_status ? "activated" : "deactivated") . " successfully!";
            $message_type = "success";
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// Clone exam
if (isset($_GET['clone_exam'])) {
    try {
        $exam_id = intval($_GET['clone_exam']);
        $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ? AND school_id = ?");
        $stmt->execute([$exam_id, $school_id]);
        $original = $stmt->fetch();
        if ($original) {
            $stmt = $pdo->prepare("INSERT INTO exams (exam_name, class, class_id, subject_id, topics, duration_minutes,
                objective_count, subjective_count, theory_count, exam_type, instructions, is_active, school_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $original['exam_name'] . " (Copy)",
                $original['class'],
                $original['class_id'],
                $original['subject_id'],
                $original['topics'],
                $original['duration_minutes'],
                $original['objective_count'],
                $original['subjective_count'],
                $original['theory_count'],
                $original['exam_type'],
                $original['instructions'],
                $original['is_active'],
                $school_id
            ]);
            $message = "Exam cloned successfully!";
            $message_type = "success";
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// ============================================
// AJAX ENDPOINTS
// ============================================

// Get single exam for editing
if (isset($_GET['get_exam'])) {
    header('Content-Type: application/json');
    try {
        $exam_id = intval($_GET['get_exam']);
        $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ? AND school_id = ?");
        $stmt->execute([$exam_id, $school_id]);
        $exam = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($exam) {
            $topics = json_decode($exam['topics'], true);
            $exam['topics'] = is_array($topics) ? $topics : [];
            echo json_encode(['success' => true, 'exam' => $exam]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Exam not found']);
        }
        exit();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
}

// Get exam details with sessions
if (isset($_GET['get_exam_details'])) {
    header('Content-Type: application/json');
    try {
        $exam_id = intval($_GET['get_exam_details']);
        $chk = $pdo->prepare("SELECT id, exam_name, class, exam_type FROM exams WHERE id = ? AND school_id = ?");
        $chk->execute([$exam_id, $school_id]);
        $exam_info = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$exam_info) throw new Exception("Exam not found");

        $stmt = $pdo->prepare("
            SELECT es.id, es.student_id, es.exam_type, es.status, es.start_time, es.end_time,
                   es.submitted_at, es.score, es.correct_answers, es.total_questions,
                   es.percentage, es.grade, es.sync_status,
                   CONCAT(st.first_name, ' ', st.last_name) AS student_name,
                   st.admission_number, st.class AS student_class
            FROM exam_sessions es
            LEFT JOIN students st ON es.student_id = st.id AND st.school_id = ?
            WHERE es.exam_id = ? AND es.school_id = ?
            ORDER BY es.start_time DESC
        ");
        $stmt->execute([$school_id, $exam_id, $school_id]);
        $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = count($sessions);
        $completed = count(array_filter($sessions, fn($s) => $s['status'] === 'completed'));
        $in_progress = $total - $completed;

        echo json_encode([
            'success' => true,
            'exam' => $exam_info,
            'sessions' => $sessions,
            'summary' => ['total' => $total, 'completed' => $completed, 'in_progress' => $in_progress]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Reset a single session
if (isset($_GET['reset_session']) && isset($_GET['exam_id'])) {
    header('Content-Type: application/json');
    try {
        $session_id = intval($_GET['reset_session']);
        $exam_id = intval($_GET['exam_id']);
        $chk = $pdo->prepare("SELECT es.id FROM exam_sessions es JOIN exams e ON es.exam_id = e.id WHERE es.id = ? AND es.exam_id = ? AND e.school_id = ?");
        $chk->execute([$session_id, $exam_id, $school_id]);
        if (!$chk->fetch()) throw new Exception("Session not found");

        $pdo->prepare("DELETE FROM exam_session_questions WHERE session_id = ? AND school_id = ?")->execute([$session_id, $school_id]);
        $pdo->prepare("DELETE FROM exam_sessions WHERE id = ? AND school_id = ?")->execute([$session_id, $school_id]);

        echo json_encode(['success' => true, 'message' => 'Session reset successfully']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Reset all sessions for an exam
if (isset($_GET['reset_all_sessions']) && isset($_GET['exam_id'])) {
    header('Content-Type: application/json');
    try {
        $exam_id = intval($_GET['exam_id']);
        $chk = $pdo->prepare("SELECT id FROM exams WHERE id = ? AND school_id = ?");
        $chk->execute([$exam_id, $school_id]);
        if (!$chk->fetch()) throw new Exception("Exam not found");

        $ids_stmt = $pdo->prepare("SELECT id FROM exam_sessions WHERE exam_id = ? AND school_id = ?");
        $ids_stmt->execute([$exam_id, $school_id]);
        $session_ids = array_column($ids_stmt->fetchAll(PDO::FETCH_ASSOC), 'id');

        if (!empty($session_ids)) {
            $placeholders = implode(',', array_fill(0, count($session_ids), '?'));
            $pdo->prepare("DELETE FROM exam_session_questions WHERE session_id IN ($placeholders) AND school_id = ?")
                ->execute([...$session_ids, $school_id]);
        }
        $pdo->prepare("DELETE FROM exam_sessions WHERE exam_id = ? AND school_id = ?")->execute([$exam_id, $school_id]);

        echo json_encode(['success' => true, 'message' => 'All sessions reset successfully']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ============================================
// FETCH DATA
// ============================================

$search = $_GET['search'] ?? '';
$class_filter = $_GET['class'] ?? '';
$subject_filter = $_GET['subject'] ?? '';
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';

$query = "
    SELECT e.*, s.subject_name,
           COUNT(DISTINCT oq.id) as objective_questions_count,
           COUNT(DISTINCT sq.id) as subjective_questions_count,
           COUNT(DISTINCT tq.id) as theory_questions_count,
           COUNT(DISTINCT es.id) as exam_sessions_count
    FROM exams e
    LEFT JOIN subjects s ON e.subject_id = s.id AND s.school_id = e.school_id
    LEFT JOIN objective_questions oq ON e.subject_id = oq.subject_id AND (e.class = oq.class OR oq.class IS NULL OR oq.class = '')
    LEFT JOIN subjective_questions sq ON e.subject_id = sq.subject_id AND (e.class = sq.class OR sq.class IS NULL OR sq.class = '')
    LEFT JOIN theory_questions tq ON e.subject_id = tq.subject_id AND (e.class = tq.class OR tq.class IS NULL OR tq.class = '')
    LEFT JOIN exam_sessions es ON e.id = es.exam_id
    WHERE e.school_id = ?
";
$params = [$school_id];

if (!empty($search)) {
    $query .= " AND (e.exam_name LIKE ? OR e.class LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if (!empty($class_filter)) {
    $query .= " AND e.class = ?";
    $params[] = $class_filter;
}
if (!empty($subject_filter)) {
    $query .= " AND e.subject_id = ?";
    $params[] = $subject_filter;
}
if ($status_filter === 'active') $query .= " AND e.is_active = 1";
elseif ($status_filter === 'inactive') $query .= " AND e.is_active = 0";
if (!empty($type_filter)) {
    $query .= " AND e.exam_type = ?";
    $params[] = $type_filter;
}

$query .= " GROUP BY e.id ORDER BY e.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$exams = $stmt->fetchAll();

// Fetch subjects for dropdown
$subjects = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE school_id = ? ORDER BY subject_name");
$subjects->execute([$school_id]);
$subjects = $subjects->fetchAll();

// Fetch classes
$classes_list = [];
try {
    $stmt = $pdo->prepare("SELECT id, class_name, class_code FROM classes WHERE school_id = ? AND status = 'active' ORDER BY sort_order, class_name");
    $stmt->execute([$school_id]);
    $classes_list = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error fetching classes: " . $e->getMessage());
}

// Fetch topics
$topics = $pdo->prepare("SELECT id, topic_name, subject_id FROM topics WHERE school_id = ? ORDER BY topic_name");
$topics->execute([$school_id]);
$topics = $topics->fetchAll();

$exam_types = [
    'objective' => 'Objective Only',
    'subjective' => 'Subjective Only',
    'theory' => 'Theory Only',
    'comprehensive' => 'Comprehensive'
];

// Include sidebar
require_once 'includes/sidebar.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($school_name); ?> - Manage Exams</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --primary-dark: #1a5a8a;
            --secondary-color: #d4af7a;
            --success: #27ae60;
            --success-light: #d5f4e6;
            --warning: #f39c12;
            --warning-light: #fef5e7;
            --danger: #e74c3c;
            --danger-light: #fbe9e7;
            --info: #3498db;
            --info-light: #eaf6ff;
            --purple: #9b59b6;
            --gray-50: #f9fafb;
            --gray-100: #f0f2f5;
            --gray-200: #e4e7eb;
            --gray-300: #d1d5db;
            --gray-600: #6b7280;
            --gray-800: #1f2937;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 16px;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--gray-100);
            color: var(--gray-800);
            min-height: 100vh;
        }

        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        /* Top Header */
        .top-header {
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            box-shadow: var(--shadow-sm);
        }

        .header-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 4px;
        }

        .header-title p {
            color: var(--gray-600);
            font-size: 0.8rem;
        }

        /* Buttons */
        .btn {
            padding: 10px 18px;
            border-radius: var(--radius-md);
            border: none;
            font-weight: 500;
            font-size: 0.8rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.7rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--gray-300);
            color: var(--gray-800);
        }

        /* Filter Bar */
        .filter-bar {
            background: white;
            border-radius: var(--radius-lg);
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .filter-group {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-group select,
        .filter-group input {
            padding: 10px 14px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 0.85rem;
            background: white;
        }

        .search-box {
            position: relative;
            display: inline-block;
        }

        .search-box input {
            padding: 10px 15px 10px 40px;
            width: 250px;
        }

        .search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-600);
        }

        /* Stats Row */
        .stats-row {
            display: flex;
            gap: 16px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .stat-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 15px 20px;
            flex: 1;
            min-width: 120px;
            text-align: center;
            box-shadow: var(--shadow-sm);
        }

        .stat-card .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .stat-card .stat-label {
            font-size: 0.75rem;
            color: var(--gray-600);
        }

        /* Exams Grid - Mobile First */
        .exams-grid {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .exam-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 18px;
            box-shadow: var(--shadow-sm);
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid var(--gray-200);
        }

        .exam-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary-color);
        }

        .exam-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }

        .exam-name {
            font-size: 1rem;
            font-weight: 700;
            color: var(--gray-800);
            background: var(--info-light);
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-block;
        }

        .exam-name i {
            margin-right: 6px;
            color: var(--primary-color);
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-active {
            background: var(--success-light);
            color: var(--success);
        }

        .status-inactive {
            background: var(--danger-light);
            color: var(--danger);
        }

        .type-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .type-objective {
            background: #e3f2fd;
            color: #1976d2;
        }

        .type-subjective {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .type-theory {
            background: #e8f5e9;
            color: #388e3c;
        }

        .type-comprehensive {
            background: #fff3e0;
            color: #f57c00;
        }

        .exam-details {
            margin: 12px 0;
        }

        .exam-detail-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-right: 16px;
            margin-bottom: 8px;
            font-size: 0.75rem;
            color: var(--gray-600);
        }

        .exam-detail-item i {
            width: 16px;
            color: var(--primary-color);
        }

        .questions-stats {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--gray-200);
        }

        .q-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .q-obj {
            background: #e3f2fd;
            color: #1976d2;
        }

        .q-sub {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .q-thy {
            background: #e8f5e9;
            color: #388e3c;
        }

        .q-session {
            background: #fff3e0;
            color: #f57c00;
        }

        /* Modal */
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

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 550px;
            max-height: 85vh;
            overflow-y: auto;
        }

        .modal-content.large {
            max-width: 900px;
        }

        .modal-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
        }

        .modal-header h3 {
            font-size: 1.1rem;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--gray-600);
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        /* Info rows */
        .info-row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid var(--gray-200);
        }

        .info-label {
            width: 120px;
            font-weight: 600;
            color: var(--gray-600);
            font-size: 0.8rem;
        }

        .info-value {
            flex: 1;
            color: var(--gray-800);
            font-size: 0.85rem;
        }

        .modal-action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid var(--gray-200);
        }

        .modal-action-btn {
            flex: 1;
            min-width: 100px;
            justify-content: center;
        }

        /* Form */
        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            font-size: 0.8rem;
        }

        .form-control,
        .form-select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 0.85rem;
        }

        .form-row {
            grid-column: 1 / -1;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .topics-container {
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            padding: 15px;
            max-height: 200px;
            overflow-y: auto;
        }

        .topics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 10px;
        }

        .topic-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .topic-checkbox label {
            font-size: 0.8rem;
            cursor: pointer;
        }

        /* Details modal extras */
        .summary-cards {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .summary-card {
            flex: 1;
            min-width: 100px;
            background: var(--gray-50);
            border-radius: var(--radius-md);
            padding: 12px 16px;
            text-align: center;
            border-top: 3px solid #ccc;
        }

        .summary-card.total {
            border-color: var(--info);
        }

        .summary-card.completed {
            border-color: var(--success);
        }

        .summary-card.progress {
            border-color: var(--warning);
        }

        .summary-card .sc-num {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .summary-card .sc-label {
            font-size: 0.7rem;
            color: var(--gray-600);
        }

        .details-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        .details-table th {
            background: var(--primary-color);
            color: white;
            padding: 10px;
            text-align: left;
        }

        .details-table td {
            padding: 10px;
            border-bottom: 1px solid var(--gray-200);
        }

        .details-table tr:hover td {
            background: var(--gray-50);
        }

        .reset-btn {
            background: var(--danger);
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 0.7rem;
        }

        .alert {
            padding: 14px 18px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.85rem;
        }

        .alert-success {
            background: var(--success-light);
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .alert-error {
            background: var(--danger-light);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }

        .alert-warning {
            background: var(--warning-light);
            color: #856404;
            border-left: 4px solid var(--warning);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: var(--radius-lg);
        }

        .empty-state i {
            font-size: 48px;
            color: var(--gray-300);
            margin-bottom: 16px;
        }

        .loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }

            .top-header {
                flex-direction: column;
                text-align: center;
            }

            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-group {
                justify-content: space-between;
            }

            .search-box input {
                width: 100%;
            }

            .info-row {
                flex-direction: column;
            }

            .info-label {
                width: 100%;
                margin-bottom: 5px;
            }

            .modal-action-buttons {
                flex-direction: column;
            }

            .modal-action-btn {
                width: 100%;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .topics-grid {
                grid-template-columns: 1fr;
            }

            .details-table {
                font-size: 0.7rem;
            }

            .details-table th,
            .details-table td {
                padding: 6px;
            }

            .reset-btn {
                padding: 3px 6px;
                font-size: 0.65rem;
            }
        }
    </style>
</head>

<body>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-file-alt"></i> Manage Exams</h1>
                <p>Click any exam to view details, sessions, and actions</p>
            </div>
            <button class="btn btn-primary" id="openAddExamBtn">
                <i class="fas fa-plus-circle"></i> Add New Exam
            </button>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Stats Row -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-value"><?php echo count($exams); ?></div>
                <div class="stat-label">Total Exams</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count(array_filter($exams, fn($e) => $e['is_active'])); ?></div>
                <div class="stat-label">Active Exams</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo array_sum(array_column($exams, 'exam_sessions_count')); ?></div>
                <div class="stat-label">Total Sessions</div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <form method="GET" style="display: flex; flex-wrap: wrap; gap: 12px; flex: 1;">
                <div class="filter-group">
                    <select name="class">
                        <option value="">All Classes</option>
                        <?php foreach ($classes_list as $class): ?>
                            <option value="<?php echo htmlspecialchars($class['class_name']); ?>" <?php echo $class_filter === $class['class_name'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="subject">
                        <option value="">All Subjects</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo $subject['id']; ?>" <?php echo $subject_filter == $subject['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                    <select name="type">
                        <option value="">All Types</option>
                        <?php foreach ($exam_types as $key => $value): ?>
                            <option value="<?php echo $key; ?>" <?php echo $type_filter === $key ? 'selected' : ''; ?>><?php echo $value; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Search exams..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filter</button>
                <?php if ($search || $class_filter || $subject_filter || $status_filter || $type_filter): ?>
                    <a href="manage-exams.php" class="btn btn-outline btn-sm"><i class="fas fa-times"></i> Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Exams Grid -->
        <?php if (empty($exams)): ?>
            <div class="empty-state">
                <i class="fas fa-file-alt"></i>
                <h3>No Exams Found</h3>
                <p>Click "Add New Exam" to create your first exam.</p>
                <button class="btn btn-primary" onclick="openAddExamModal()" style="margin-top: 15px;">
                    <i class="fas fa-plus-circle"></i> Add New Exam
                </button>
            </div>
        <?php else: ?>
            <div class="exams-grid" id="examsGrid">
                <?php foreach ($exams as $exam): ?>
                    <div class="exam-card" data-exam-id="<?php echo $exam['id']; ?>" data-exam-name="<?php echo htmlspecialchars($exam['exam_name']); ?>">
                        <div class="exam-card-header">
                            <span class="exam-name">
                                <i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($exam['exam_name']); ?>
                            </span>
                            <span class="status-badge <?php echo $exam['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                <?php echo $exam['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                        <div class="exam-details">
                            <span class="exam-detail-item"><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($exam['class']); ?></span>
                            <span class="exam-detail-item"><i class="fas fa-book"></i> <?php echo htmlspecialchars($exam['subject_name'] ?? 'No Subject'); ?></span>
                            <span class="exam-detail-item"><i class="fas fa-clock"></i> <?php echo $exam['duration_minutes']; ?> min</span>
                            <span class="type-badge type-<?php echo $exam['exam_type'] ?? 'objective'; ?>">
                                <?php echo $exam_types[$exam['exam_type']] ?? ucfirst($exam['exam_type']); ?>
                            </span>
                        </div>
                        <div class="questions-stats">
                            <span class="q-badge q-obj"><i class="fas fa-check-circle"></i> O: <?php echo $exam['objective_questions_count'] ?? 0; ?></span>
                            <span class="q-badge q-sub"><i class="fas fa-pencil-alt"></i> S: <?php echo $exam['subjective_questions_count'] ?? 0; ?></span>
                            <span class="q-badge q-thy"><i class="fas fa-file-alt"></i> T: <?php echo $exam['theory_questions_count'] ?? 0; ?></span>
                            <span class="q-badge q-session"><i class="fas fa-users"></i> Sessions: <?php echo $exam['exam_sessions_count'] ?? 0; ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add/Edit Exam Modal -->
    <div class="modal" id="examModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Add New Exam</h3>
                <button class="close-modal" onclick="closeModal('examModal')">&times;</button>
            </div>
            <form method="POST" id="examForm">
                <input type="hidden" name="exam_id" id="exam_id">
                <input type="hidden" name="class_id" id="class_id">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Exam Name *</label>
                            <input type="text" name="exam_name" id="exam_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Class *</label>
                            <select name="class_name" id="class_name" class="form-select" required onchange="updateClassId()">
                                <option value="">Select Class</option>
                                <?php foreach ($classes_list as $class): ?>
                                    <option value="<?php echo htmlspecialchars($class['class_name']); ?>" data-id="<?php echo $class['id']; ?>">
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Subject</label>
                            <select name="subject_id" id="subject_id" class="form-select" onchange="filterTopicsBySubject()">
                                <option value="">Select Subject</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['subject_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Exam Type *</label>
                            <select name="exam_type" id="exam_type" class="form-select" onchange="updateQuestionCounts()">
                                <?php foreach ($exam_types as $key => $value): ?>
                                    <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Duration (minutes) *</label>
                            <input type="number" name="duration_minutes" id="duration_minutes" class="form-control" min="1" value="60" required>
                        </div>
                        <div class="form-group">
                            <label>Objective Questions</label>
                            <input type="number" name="objective_count" id="objective_count" class="form-control" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label>Subjective Questions</label>
                            <input type="number" name="subjective_count" id="subjective_count" class="form-control" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label>Theory Questions</label>
                            <input type="number" name="theory_count" id="theory_count" class="form-control" min="0" value="0">
                        </div>
                        <div class="form-row">
                            <label>Topics Covered</label>
                            <div class="topics-container">
                                <div class="topics-grid" id="topicsGrid">
                                    <?php if (!empty($topics)): ?>
                                        <?php foreach ($topics as $topic): ?>
                                            <div class="topic-checkbox" data-subject="<?php echo $topic['subject_id']; ?>">
                                                <input type="checkbox" name="topics[]" value="<?php echo $topic['id']; ?>" id="topic_<?php echo $topic['id']; ?>">
                                                <label for="topic_<?php echo $topic['id']; ?>"><?php echo htmlspecialchars($topic['topic_name']); ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p style="color: #666;">No topics available.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="form-row">
                            <label>Instructions</label>
                            <textarea name="instructions" id="instructions" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="form-row">
                            <label><input type="checkbox" name="is_active" value="1" checked> Active (Students can take this exam)</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('examModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn"><i class="fas fa-save"></i> Save Exam</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Exam Detail Modal -->
    <div class="modal" id="examDetailModal">
        <div class="modal-content large">
            <div class="modal-header">
                <div>
                    <h3 id="detailModalTitle">Exam Details</h3>
                    <p id="detailModalSubtitle" style="font-size: 0.8rem; color: var(--gray-600); margin-top: 4px;"></p>
                </div>
                <button class="close-modal" onclick="closeModal('examDetailModal')">&times;</button>
            </div>
            <div class="modal-body" id="detailModalBody">
                <div style="text-align: center; padding: 40px;">
                    <div class="loading" style="width: 32px; height: 32px;"></div>
                    <p>Loading...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('examDetailModal')">Close</button>
                <button class="btn btn-danger" id="resetAllBtn" style="display: none;" onclick="resetAllSessions()">
                    <i class="fas fa-trash-alt"></i> Reset All Sessions
                </button>
            </div>
        </div>
    </div>

    <script>
        // Variables
        let currentExamId = null;
        let allSessions = [];

        // Modal functions
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        // Update class_id when class is selected
        function updateClassId() {
            const classSelect = document.getElementById('class_name');
            if (classSelect && classSelect.selectedIndex >= 0) {
                const selectedOption = classSelect.options[classSelect.selectedIndex];
                const classId = selectedOption ? selectedOption.getAttribute('data-id') : '';
                document.getElementById('class_id').value = classId;
            }
        }

        // Update question counts based on exam type
        function updateQuestionCounts() {
            const type = document.getElementById('exam_type').value;
            const obj = document.getElementById('objective_count');
            const sub = document.getElementById('subjective_count');
            const thy = document.getElementById('theory_count');

            if (type === 'objective') {
                obj.disabled = false;
                sub.disabled = true;
                thy.disabled = true;
                sub.value = 0;
                thy.value = 0;
            } else if (type === 'subjective') {
                obj.disabled = true;
                sub.disabled = false;
                thy.disabled = true;
                obj.value = 0;
                thy.value = 0;
            } else if (type === 'theory') {
                obj.disabled = true;
                sub.disabled = true;
                thy.disabled = false;
                obj.value = 0;
                sub.value = 0;
            } else {
                obj.disabled = false;
                sub.disabled = false;
                thy.disabled = false;
            }
        }

        // Filter topics by subject
        function filterTopicsBySubject() {
            const subjectId = document.getElementById('subject_id').value;
            const topicDivs = document.querySelectorAll('#topicsGrid .topic-checkbox');
            topicDivs.forEach(div => {
                div.style.display = (!subjectId || div.dataset.subject == subjectId) ? 'flex' : 'none';
                if (div.style.display === 'none') {
                    const checkbox = div.querySelector('input');
                    if (checkbox) checkbox.checked = false;
                }
            });
        }

        function resetForm() {
            document.getElementById('exam_id').value = '';
            document.getElementById('exam_name').value = '';
            document.getElementById('class_name').value = '';
            document.getElementById('class_id').value = '';
            document.getElementById('subject_id').value = '';
            document.getElementById('duration_minutes').value = '60';
            document.getElementById('objective_count').value = '0';
            document.getElementById('subjective_count').value = '0';
            document.getElementById('theory_count').value = '0';
            document.getElementById('instructions').value = '';

            // Fix: Use querySelector for checkbox without an ID
            const activeCheckbox = document.querySelector('input[name="is_active"]');
            if (activeCheckbox) {
                activeCheckbox.checked = true;
            }

            document.getElementById('exam_type').value = 'objective';
            updateQuestionCounts();

            const checkboxes = document.querySelectorAll('input[name="topics[]"]');
            checkboxes.forEach(cb => cb.checked = false);
        }

        // Open add exam modal
        function openAddExamModal() {
            console.log('Opening add exam modal');
            resetForm();
            document.getElementById('modalTitle').textContent = 'Add New Exam';
            document.getElementById('submitBtn').name = 'add_exam';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save"></i> Save Exam';
            openModal('examModal');
        }

        // Edit exam
        async function editExam(examId) {
            try {
                const response = await fetch(`?get_exam=${examId}`);
                const data = await response.json();

                if (data.success && data.exam) {
                    const e = data.exam;
                    resetForm();

                    document.getElementById('exam_id').value = e.id;
                    document.getElementById('exam_name').value = e.exam_name || '';

                    // Set class
                    if (e.class) {
                        const classSelect = document.getElementById('class_name');
                        for (let i = 0; i < classSelect.options.length; i++) {
                            if (classSelect.options[i].value === e.class) {
                                classSelect.selectedIndex = i;
                                break;
                            }
                        }
                        updateClassId();
                    }

                    document.getElementById('subject_id').value = e.subject_id || '';
                    document.getElementById('exam_type').value = e.exam_type || 'objective';
                    document.getElementById('duration_minutes').value = e.duration_minutes || 60;
                    document.getElementById('objective_count').value = e.objective_count || 0;
                    document.getElementById('subjective_count').value = e.subjective_count || 0;
                    document.getElementById('theory_count').value = e.theory_count || 0;
                    document.getElementById('instructions').value = e.instructions || '';
                    document.getElementById('is_active').checked = e.is_active == 1;

                    updateQuestionCounts();
                    filterTopicsBySubject();

                    // Set topics checkboxes
                    let topicsArray = [];
                    if (e.topics) {
                        topicsArray = typeof e.topics === 'string' ? JSON.parse(e.topics) : e.topics;
                    }
                    const checkboxes = document.querySelectorAll('input[name="topics[]"]');
                    checkboxes.forEach(cb => cb.checked = false);
                    topicsArray.forEach(topicId => {
                        checkboxes.forEach(cb => {
                            if (parseInt(cb.value) === parseInt(topicId)) cb.checked = true;
                        });
                    });

                    document.getElementById('modalTitle').textContent = 'Edit Exam';
                    document.getElementById('submitBtn').name = 'update_exam';
                    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-edit"></i> Update Exam';
                    openModal('examModal');
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error loading exam details');
            }
        }

        // View exam details (click on card)
        function viewExamDetails(examId, examName) {
            currentExamId = examId;
            document.getElementById('detailModalTitle').textContent = examName;
            document.getElementById('detailModalSubtitle').textContent = 'Loading...';
            document.getElementById('detailModalBody').innerHTML = '<div style="text-align: center; padding: 40px;"><div class="loading"></div><p>Loading sessions...</p></div>';
            document.getElementById('resetAllBtn').style.display = 'none';
            openModal('examDetailModal');

            fetch(`?get_exam_details=${examId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        allSessions = data.sessions;
                        renderExamDetails(data);
                    } else {
                        throw new Error(data.error || 'Failed to load');
                    }
                })
                .catch(error => {
                    document.getElementById('detailModalBody').innerHTML = `<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> ${error.message}</div>`;
                });
        }

        function renderExamDetails(data) {
            const {
                exam,
                sessions,
                summary
            } = data;
            document.getElementById('detailModalSubtitle').textContent = `Class: ${exam.class || '—'} | Type: ${exam.exam_type}`;

            if (summary.total > 0) {
                document.getElementById('resetAllBtn').style.display = 'inline-flex';
            }

            document.getElementById('detailModalBody').innerHTML = `
                <div class="summary-cards">
                    <div class="summary-card total">
                        <div class="sc-num">${summary.total}</div>
                        <div class="sc-label">Total Sessions</div>
                    </div>
                    <div class="summary-card completed">
                        <div class="sc-num">${summary.completed}</div>
                        <div class="sc-label">Completed</div>
                    </div>
                    <div class="summary-card progress">
                        <div class="sc-num">${summary.in_progress}</div>
                        <div class="sc-label">In Progress</div>
                    </div>
                </div>
                <div style="overflow-x: auto;">
                    <table class="details-table">
                        <thead>
                            <tr><th>#</th><th>Student</th><th>Admission</th><th>Status</th><th>Started</th><th>Score</th><th>%</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            ${sessions.length === 0 ? '<tr><td colspan="8" style="text-align: center;">No sessions found</td></tr>' : 
                                sessions.map((s, i) => `
                                    <tr>
                                        <td>${i+1}</td>
                                        <td><strong>${escapeHtml(s.student_name || 'Unknown')}</strong></td>
                                        <td>${escapeHtml(s.admission_number || '—')}</td>
                                        <td><span class="status-badge ${s.status === 'completed' ? 'status-active' : 'status-warning'}" style="${s.status !== 'completed' ? 'background:#fff3cd;color:#856404;' : ''}">${s.status === 'completed' ? 'Completed' : 'In Progress'}</span></td>
                                        <td>${formatDate(s.start_time)}</td>
                                        <td>${s.score != null ? `${s.correct_answers || 0}/${s.total_questions || 0}` : '—'}</td>
                                        <td>${s.percentage != null ? parseFloat(s.percentage).toFixed(1) + '%' : '—'}</td>
                                        <td><button class="reset-btn" onclick="resetSession(${s.id}, '${escapeHtml(s.student_name || 'this student')}')"><i class="fas fa-undo"></i> Reset</button></td>
                                    </table>
                                `).join('')
                            }
                        </tbody>
                    </table>
                </div>
            `;
        }

        async function resetSession(sessionId, studentName) {
            if (!confirm(`Reset ${studentName}'s session? This will delete their answers and allow them to retake.`)) return;
            try {
                const response = await fetch(`?reset_session=${sessionId}&exam_id=${currentExamId}`);
                const data = await response.json();
                if (data.success) {
                    const refresh = await fetch(`?get_exam_details=${currentExamId}`);
                    const refreshData = await refresh.json();
                    if (refreshData.success) {
                        allSessions = refreshData.sessions;
                        renderExamDetails(refreshData);
                    }
                } else {
                    alert('Reset failed: ' + data.error);
                }
            } catch (error) {
                alert('Error resetting session');
            }
        }

        async function resetAllSessions() {
            if (!confirm(`Reset ALL sessions for this exam? This cannot be undone.`)) return;
            try {
                const response = await fetch(`?reset_all_sessions=1&exam_id=${currentExamId}`);
                const data = await response.json();
                if (data.success) {
                    const refresh = await fetch(`?get_exam_details=${currentExamId}`);
                    const refreshData = await refresh.json();
                    if (refreshData.success) {
                        allSessions = refreshData.sessions;
                        renderExamDetails(refreshData);
                    }
                } else {
                    alert('Reset failed: ' + data.error);
                }
            } catch (error) {
                alert('Error resetting sessions');
            }
        }

        // Helper functions
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatDate(dt) {
            if (!dt) return '—';
            const d = new Date(dt);
            if (isNaN(d)) return dt;
            return d.toLocaleString('en-GB', {
                day: '2-digit',
                month: 'short',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        // Event listeners - FIXED: Make sure the button works
        document.addEventListener('DOMContentLoaded', function() {
            updateQuestionCounts();
            console.log('Page loaded - exam cards clickable');

            // FIX: Ensure the Add New Exam button works
            const addBtn = document.getElementById('openAddExamBtn');
            if (addBtn) {
                // Remove any existing listeners to prevent duplicates
                const newBtn = addBtn.cloneNode(true);
                addBtn.parentNode.replaceChild(newBtn, addBtn);
                newBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    openAddExamModal();
                });
                console.log('Add button listener attached');
            } else {
                console.log('Add button not found!');
            }
        });

        // Also add fallback for exam cards
        setTimeout(function() {
            const examCards = document.querySelectorAll('.exam-card');
            console.log('Found exam cards:', examCards.length);
            examCards.forEach(card => {
                card.addEventListener('click', function(e) {
                    if (e.target.closest('.btn') || e.target.closest('a')) return;
                    const examId = this.getAttribute('data-exam-id');
                    const examName = this.getAttribute('data-exam-name');
                    viewExamDetails(examId, examName);
                });
            });
        }, 100);

        // Form submission
        document.getElementById('examForm')?.addEventListener('submit', function(e) {
            const examName = document.getElementById('exam_name').value.trim();
            const className = document.getElementById('class_name').value;
            if (!examName) {
                e.preventDefault();
                alert('Please enter exam name');
                return false;
            }
            if (!className) {
                e.preventDefault();
                alert('Please select a class');
                return false;
            }
            updateClassId();
        });

        // Close modals on outside click
        window.onclick = function(event) {
            if (event.target.classList && event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
                document.body.style.overflow = '';
            }
        };
    </script>
</body>

</html>
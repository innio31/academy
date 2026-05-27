<?php
// admin/manage-exams.php - Complete Exam Management with Multi-School Support
session_start();

// Check if admin is logged in (support both session styles)
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /tbis/login.php");
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

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;

// ============================================
// HANDLE FORM SUBMISSIONS
// ============================================

$message = '';
$message_type = '';

// Add new exam - WITH class_id
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

        // Validate required fields
        if (empty($exam_name)) throw new Exception("Exam name is required");
        if (empty($class_name)) throw new Exception("Class is required");
        if (empty($duration_minutes)) throw new Exception("Duration is required");

        // If class_id is not provided, try to get it from class_name
        if (!$class_id && !empty($class_name)) {
            $stmt = $pdo->prepare("SELECT id FROM classes WHERE class_name = ? AND school_id = ? LIMIT 1");
            $stmt->execute([$class_name, $school_id]);
            $class_row = $stmt->fetch();
            if ($class_row) {
                $class_id = $class_row['id'];
            }
        }

        $sql = "INSERT INTO exams (
                    exam_name, class, class_id, subject_id, topics, duration_minutes,
                    objective_count, subjective_count, theory_count, exam_type,
                    instructions, is_active, school_id, created_at
                ) VALUES (
                    :exam_name, :class, :class_id, :subject_id, :topics, :duration_minutes,
                    :objective_count, :subjective_count, :theory_count, :exam_type,
                    :instructions, :is_active, :school_id, NOW()
                )";

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
        $message = "Error adding exam: " . $e->getMessage();
        $message_type = "error";
        error_log("Add exam error: " . $e->getMessage());
    }
}

// Update exam - WITH class_id
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

        // Verify exam belongs to this school
        $stmt = $pdo->prepare("SELECT id FROM exams WHERE id = ? AND school_id = ?");
        $stmt->execute([$exam_id, $school_id]);
        if (!$stmt->fetch()) {
            throw new Exception("Exam not found or access denied");
        }

        // If class_id is not provided, try to get it from class_name
        if (!$class_id && !empty($class_name)) {
            $stmt = $pdo->prepare("SELECT id FROM classes WHERE class_name = ? AND school_id = ? LIMIT 1");
            $stmt->execute([$class_name, $school_id]);
            $class_row = $stmt->fetch();
            if ($class_row) {
                $class_id = $class_row['id'];
            }
        }

        $sql = "UPDATE exams SET 
                    exam_name = :exam_name,
                    class = :class,
                    class_id = :class_id,
                    subject_id = :subject_id,
                    topics = :topics,
                    duration_minutes = :duration_minutes,
                    objective_count = :objective_count,
                    subjective_count = :subjective_count,
                    theory_count = :theory_count,
                    exam_type = :exam_type,
                    instructions = :instructions,
                    is_active = :is_active,
                    updated_at = NOW()
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
        $message = "Error updating exam: " . $e->getMessage();
        $message_type = "error";
        error_log("Update exam error: " . $e->getMessage());
    }
}

// Delete exam
if (isset($_GET['delete_exam'])) {
    try {
        $exam_id = intval($_GET['delete_exam']);

        // Verify exam belongs to this school
        $stmt = $pdo->prepare("SELECT exam_name FROM exams WHERE id = ? AND school_id = ?");
        $stmt->execute([$exam_id, $school_id]);
        $exam = $stmt->fetch();

        if (!$exam) {
            throw new Exception("Exam not found or access denied");
        }

        $stmt = $pdo->prepare("DELETE FROM exams WHERE id = ? AND school_id = ?");
        $stmt->execute([$exam_id, $school_id]);

        $message = "Exam '" . $exam['exam_name'] . "' deleted successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = "Error deleting exam: " . $e->getMessage();
        $message_type = "error";
    }
}

// Toggle exam status
if (isset($_GET['toggle_status'])) {
    try {
        $exam_id = intval($_GET['toggle_status']);

        $stmt = $pdo->prepare("SELECT is_active, exam_name FROM exams WHERE id = ? AND school_id = ?");
        $stmt->execute([$exam_id, $school_id]);
        $exam = $stmt->fetch();

        if ($exam) {
            $new_status = $exam['is_active'] ? 0 : 1;
            $stmt = $pdo->prepare("UPDATE exams SET is_active = ? WHERE id = ? AND school_id = ?");
            $stmt->execute([$new_status, $exam_id, $school_id]);

            $status_text = $new_status ? "activated" : "deactivated";
            $message = "Exam {$status_text} successfully!";
            $message_type = "success";
        } else {
            throw new Exception("Exam not found");
        }
    } catch (Exception $e) {
        $message = "Error toggling exam status: " . $e->getMessage();
        $message_type = "error";
    }
}

// Clone exam
if (isset($_GET['clone_exam'])) {
    try {
        $exam_id = intval($_GET['clone_exam']);

        // Get original exam
        $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ? AND school_id = ?");
        $stmt->execute([$exam_id, $school_id]);
        $original = $stmt->fetch();

        if ($original) {
            // Insert cloned exam
            $stmt = $pdo->prepare("
                INSERT INTO exams (
                    exam_name, class, class_id, subject_id, topics, duration_minutes,
                    objective_count, subjective_count, theory_count, exam_type,
                    instructions, is_active, school_id, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $new_name = $original['exam_name'] . " (Copy)";
            $stmt->execute([
                $new_name,
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
        } else {
            throw new Exception("Exam not found");
        }
    } catch (Exception $e) {
        $message = "Error cloning exam: " . $e->getMessage();
        $message_type = "error";
    }
}

// ============================================
// FETCH DATA FOR DISPLAY
// ============================================

// Get filter parameters
$search = $_GET['search'] ?? '';
$class_filter = $_GET['class'] ?? '';
$subject_filter = $_GET['subject'] ?? '';
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';

// Build query with filters
$query = "
    SELECT e.*, s.subject_name,
           COUNT(DISTINCT oq.id) as objective_questions_count,
           COUNT(DISTINCT sq.id) as subjective_questions_count,
           COUNT(DISTINCT tq.id) as theory_questions_count,
           COUNT(DISTINCT es.id) as exam_sessions_count
    FROM exams e
    LEFT JOIN subjects s ON e.subject_id = s.id AND s.school_id = e.school_id
    LEFT JOIN objective_questions oq ON e.subject_id = oq.subject_id 
        AND (e.class = oq.class OR oq.class IS NULL OR oq.class = '')
    LEFT JOIN subjective_questions sq ON e.subject_id = sq.subject_id 
        AND (e.class = sq.class OR sq.class IS NULL OR sq.class = '')
    LEFT JOIN theory_questions tq ON e.subject_id = tq.subject_id 
        AND (e.class = tq.class OR tq.class IS NULL OR tq.class = '')
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
if ($status_filter === 'active') {
    $query .= " AND e.is_active = 1";
} elseif ($status_filter === 'inactive') {
    $query .= " AND e.is_active = 0";
}
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

// Fetch classes from CLASSES TABLE (with id and class_name)
$classes_list = [];
try {
    $stmt = $pdo->prepare("SELECT id, class_name, class_code, class_category, sort_order FROM classes WHERE school_id = ? AND status = 'active' ORDER BY sort_order, class_name");
    $stmt->execute([$school_id]);
    $classes_list = $stmt->fetchAll();
    error_log("Classes found for school $school_id: " . count($classes_list));
} catch (Exception $e) {
    error_log("Error fetching classes: " . $e->getMessage());
}

// Also get distinct classes from exams for filter dropdown
$classes_for_filter = [];
try {
    $stmt = $pdo->prepare("SELECT DISTINCT class FROM exams WHERE school_id = ? AND class IS NOT NULL AND class != '' ORDER BY class");
    $stmt->execute([$school_id]);
    $classes_for_filter = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error fetching exam classes: " . $e->getMessage());
}

// Fetch topics for selection
$topics = $pdo->prepare("SELECT id, topic_name, subject_id FROM topics WHERE school_id = ? ORDER BY topic_name");
$topics->execute([$school_id]);
$topics = $topics->fetchAll();

$exam_types = [
    'objective' => 'Objective Only',
    'subjective' => 'Subjective Only',
    'theory' => 'Theory Only',
    'comprehensive' => 'Comprehensive (All Types)'
];

// ── AJAX: Get exam details (sessions) ──────────────────────────────────────
if (isset($_GET['get_exam_details'])) {
    header('Content-Type: application/json');
    try {
        $exam_id = intval($_GET['get_exam_details']);

        // Verify exam belongs to school
        $chk = $pdo->prepare("SELECT id, exam_name, class, exam_type FROM exams WHERE id = ? AND school_id = ?");
        $chk->execute([$exam_id, $school_id]);
        $exam_info = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$exam_info) throw new Exception("Exam not found");

        // Fetch sessions with student info
        $stmt = $pdo->prepare("
            SELECT
                es.id,
                es.student_id,
                es.exam_type,
                es.status,
                es.start_time,
                es.end_time,
                es.submitted_at,
                es.score,
                es.correct_answers,
                es.total_questions,
                es.percentage,
                es.grade,
                es.sync_status,
                CONCAT(st.first_name, ' ', st.last_name) AS student_name,
                st.admission_number,
                st.class AS student_class,
                COUNT(esq.id) AS questions_answered
            FROM exam_sessions es
            LEFT JOIN students st ON es.student_id = st.id AND st.school_id = ?
            LEFT JOIN exam_session_questions esq ON esq.session_id = es.id AND esq.school_id = ?
            WHERE es.exam_id = ? AND es.school_id = ?
            GROUP BY es.id
            ORDER BY es.start_time DESC
        ");
        $stmt->execute([$school_id, $school_id, $exam_id, $school_id]);
        $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Summary counts
        $total      = count($sessions);
        $completed  = count(array_filter($sessions, fn($s) => $s['status'] === 'completed'));
        $in_progress = $total - $completed;

        echo json_encode([
            'success'     => true,
            'exam'        => $exam_info,
            'sessions'    => $sessions,
            'summary'     => [
                'total'       => $total,
                'completed'   => $completed,
                'in_progress' => $in_progress,
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── AJAX: Reset a single session ────────────────────────────────────────────
if (isset($_GET['reset_session']) && isset($_GET['exam_id'])) {
    header('Content-Type: application/json');
    try {
        $session_id = intval($_GET['reset_session']);
        $exam_id    = intval($_GET['exam_id']);

        // Verify the session belongs to this school's exam
        $chk = $pdo->prepare("
            SELECT es.id FROM exam_sessions es
            JOIN exams e ON es.exam_id = e.id
            WHERE es.id = ? AND es.exam_id = ? AND e.school_id = ?
        ");
        $chk->execute([$session_id, $exam_id, $school_id]);
        if (!$chk->fetch()) throw new Exception("Session not found or access denied");

        // Delete session questions first (FK child)
        $pdo->prepare("DELETE FROM exam_session_questions WHERE session_id = ? AND school_id = ?")
            ->execute([$session_id, $school_id]);

        // Delete the session itself
        $pdo->prepare("DELETE FROM exam_sessions WHERE id = ? AND school_id = ?")
            ->execute([$session_id, $school_id]);

        echo json_encode(['success' => true, 'message' => 'Session reset successfully']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── AJAX: Reset ALL sessions for an exam ────────────────────────────────────
if (isset($_GET['reset_all_sessions']) && isset($_GET['exam_id'])) {
    header('Content-Type: application/json');
    try {
        $exam_id = intval($_GET['exam_id']);

        // Verify exam belongs to school
        $chk = $pdo->prepare("SELECT id FROM exams WHERE id = ? AND school_id = ?");
        $chk->execute([$exam_id, $school_id]);
        if (!$chk->fetch()) throw new Exception("Exam not found or access denied");

        // Get all session IDs for this exam
        $ids_stmt = $pdo->prepare("SELECT id FROM exam_sessions WHERE exam_id = ? AND school_id = ?");
        $ids_stmt->execute([$exam_id, $school_id]);
        $session_ids = array_column($ids_stmt->fetchAll(PDO::FETCH_ASSOC), 'id');

        if (!empty($session_ids)) {
            $placeholders = implode(',', array_fill(0, count($session_ids), '?'));
            $pdo->prepare("DELETE FROM exam_session_questions WHERE session_id IN ($placeholders) AND school_id = ?")
                ->execute([...$session_ids, $school_id]);
        }

        $pdo->prepare("DELETE FROM exam_sessions WHERE exam_id = ? AND school_id = ?")
            ->execute([$exam_id, $school_id]);

        echo json_encode(['success' => true, 'message' => 'All sessions reset successfully']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Get single exam for editing
if (isset($_GET['get_exam'])) {
    header('Content-Type: application/json');
    try {
        $exam_id = intval($_GET['get_exam']);
        
        $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ? AND school_id = ?");
        $stmt->execute([$exam_id, $school_id]);
        $exam = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($exam) {
            // Parse topics JSON - ensure it's always an array
            $topics = json_decode($exam['topics'], true);
            if (!is_array($topics)) {
                $topics = [];
            }
            $exam['topics'] = $topics;
            
            // Ensure all numeric values are integers
            $exam['objective_count'] = (int)$exam['objective_count'];
            $exam['subjective_count'] = (int)$exam['subjective_count'];
            $exam['theory_count'] = (int)$exam['theory_count'];
            $exam['duration_minutes'] = (int)$exam['duration_minutes'];
            $exam['is_active'] = (int)$exam['is_active'];
            
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
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Manage Exams</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: #d4af7a;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #3498db;
            --purple-color: #9b59b6;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --sidebar-width: 260px;
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
            transition: all 0.3s ease;
            z-index: 100;
            overflow-y: auto;
            transform: translateX(-100%);
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 20px;
            margin-bottom: 15px;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: var(--secondary-color);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .admin-info {
            text-align: center;
            padding: 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            margin: 0 15px 20px;
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
            transition: all 0.3s ease;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255, 255, 255, 0.2);
        }

        .nav-links a i {
            width: 20px;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            margin-left: 0;
            padding: 20px;
            min-height: 100vh;
        }

        .mobile-menu-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 101;
            background: var(--primary-color);
            color: white;
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 10px;
            font-size: 20px;
            cursor: pointer;
        }

        .top-header {
            background: white;
            padding: 15px 25px;
            border-radius: 10px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.8rem;
            margin-bottom: 5px;
        }

        .logout-btn {
            background: var(--danger-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Buttons */
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.85rem;
        }

        /* Filter Section */
        .filter-section {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--primary-color);
            font-size: 0.85rem;
        }

        .form-control,
        .form-select {
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem;
            width: 100%;
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        /* Table */
        .table-container {
            background: white;
            border-radius: 10px;
            overflow-x: auto;
            margin-bottom: 20px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        .data-table th {
            background: var(--primary-color);
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .data-table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
            font-size: 0.85rem;
        }

        .data-table tr:hover {
            background: #f9f9f9;
        }

        /* Badges */
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            display: inline-block;
        }

        .status-active {
            background: #d5f4e6;
            color: var(--success-color);
        }

        .status-inactive {
            background: #f8d7da;
            color: var(--danger-color);
        }

        .type-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            display: inline-block;
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

        .action-icons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .action-icon {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            color: white;
            text-decoration: none;
            font-size: 0.8rem;
            border: none;
        }

        .action-icon.edit {
            background: var(--info-color);
        }

        .action-icon.toggle {
            background: var(--warning-color);
        }

        .action-icon.view {
            background: var(--success-color);
        }

        .action-icon.delete {
            background: var(--danger-color);
        }

        .action-icon.clone {
            background: var(--purple-color);
        }

        .action-icon.details {
            background: #16a085;
        }

        .action-icon:hover {
            transform: translateY(-2px);
            opacity: 0.9;
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
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            width: 100%;
            max-width: 750px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 15px 20px;
            border-bottom: 2px solid var(--light-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
        }

        .modal-header h3 {
            color: var(--primary-color);
            font-size: 1.3rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
        }

        .modal-close:hover {
            color: var(--danger-color);
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 15px 20px;
            border-top: 2px solid var(--light-color);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .form-row {
            grid-column: 1 / -1;
        }

        .topics-container {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            max-height: 200px;
            overflow-y: auto;
        }

        .topics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
        }

        .topic-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .topic-checkbox label {
            cursor: pointer;
        }

        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d5f4e6;
            color: #155724;
            border-left: 4px solid var(--success-color);
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger-color);
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid var(--warning-color);
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid var(--info-color);
        }

        .empty-state {
            text-align: center;
            padding: 50px;
            color: #999;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #ccc;
        }

        .loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, .3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        @media (min-width: 769px) {
            .sidebar {
                transform: translateX(0);
            }

            .main-content {
                margin-left: var(--sidebar-width);
            }

            .mobile-menu-btn {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .filter-form {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>

    <?php
    // Include sidebar at the end (it will be positioned fixed)
    require_once 'includes/sidebar.php';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1>Manage Exams</h1>
                <p>Create, edit, and manage examination schedules</p>
            </div>
            <button class="logout-btn" onclick="window.location.href='../tbis/logout.php'">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Class Info Alert -->
        <?php if (empty($classes_list)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                No classes found. Please <a href="manage-classes.php" style="color: var(--primary-color); text-decoration: underline;">add classes</a> first.
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                <?php echo count($classes_list); ?> class(es) available. Select a class for the exam.
            </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div style="margin-bottom: 20px;">
            <button type="button" class="btn btn-primary" onclick="openAddExamModal()">
                <i class="fas fa-plus"></i> Add New Exam
            </button>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" class="filter-form">
                <div class="form-group">
                    <label>Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Exam name, class..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="form-group">
                    <label>Class</label>
                    <select name="class" class="form-select">
                        <option value="">All Classes</option>
                        <?php foreach ($classes_list as $class): ?>
                            <option value="<?php echo htmlspecialchars($class['class_name']); ?>" <?php echo $class_filter === $class['class_name'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Subject</label>
                    <select name="subject" class="form-select">
                        <option value="">All Subjects</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo $subject['id']; ?>" <?php echo $subject_filter == $subject['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" class="form-select">
                        <option value="">All</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select name="type" class="form-select">
                        <option value="">All Types</option>
                        <?php foreach ($exam_types as $key => $value): ?>
                            <option value="<?php echo $key; ?>" <?php echo $type_filter === $key ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($value); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary" style="width: 100%;"><i class="fas fa-search"></i> Filter</button>
                </div>
            </form>
        </div>

        <!-- Exams Table -->
        <div class="table-container">
            <?php if (empty($exams)): ?>
                <div class="empty-state">
                    <i class="fas fa-file-alt"></i>
                    <h3>No Exams Found</h3>
                    <p>Click "Add New Exam" to create your first exam.</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Exam Name</th>
                            <th>Class</th>
                            <th>Subject</th>
                            <th>Type</th>
                            <th>Duration</th>
                            <th>Questions</th>
                            <th>Sessions</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($exams as $exam): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($exam['exam_name']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($exam['class']); ?></td>
                                <td><?php echo htmlspecialchars($exam['subject_name'] ?? '—'); ?></td>
                                <td>
                                    <?php $type_class = 'type-' . ($exam['exam_type'] ?? 'objective');
                                    $type_display = $exam_types[$exam['exam_type']] ?? ucfirst($exam['exam_type']);
                                    ?>
                                    <span class="type-badge <?php echo $type_class; ?>"><?php echo htmlspecialchars($type_display); ?></span>
                                </td>
                                <td><?php echo $exam['duration_minutes']; ?> min</td>
                                <td>
                                    <small>Obj: <?php echo $exam['objective_questions_count'] ?? 0; ?><br>
                                        Sub: <?php echo $exam['subjective_questions_count'] ?? 0; ?><br>
                                        Thy: <?php echo $exam['theory_questions_count'] ?? 0; ?></small>
                                </td>
                                <td><?php echo $exam['exam_sessions_count'] ?? 0; ?></td>
                                <td>
                                    <span class="status-badge <?php echo $exam['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                        <?php echo $exam['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-icons">
                                        <button onclick="editExam(<?php echo $exam['id']; ?>)" class="action-icon edit" title="Edit"><i class="fas fa-edit"></i></button>
                                        <button onclick="viewExamDetails(<?php echo $exam['id']; ?>, <?php echo htmlspecialchars(json_encode($exam['exam_name'])); ?>)" class="action-icon details" title="Exam Details"><i class="fas fa-users"></i></button>
                                        <a href="?toggle_status=<?php echo $exam['id']; ?>" class="action-icon toggle" title="<?php echo $exam['is_active'] ? 'Deactivate' : 'Activate'; ?>" onclick="return confirm('Toggle exam status?')"><i class="fas fa-toggle-<?php echo $exam['is_active'] ? 'on' : 'off'; ?>"></i></a>
                                        <a href="?clone_exam=<?php echo $exam['id']; ?>" class="action-icon clone" title="Clone Exam" onclick="return confirm('Clone this exam?')"><i class="fas fa-copy"></i></a>
                                        <a href="exam-results.php?exam_id=<?php echo $exam['id']; ?>" class="action-icon view" title="View Results"><i class="fas fa-chart-bar"></i></a>
                                        <a href="?delete_exam=<?php echo $exam['id']; ?>" class="action-icon delete" title="Delete" onclick="return confirm('Delete this exam? This cannot be undone.')"><i class="fas fa-trash"></i></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add/Edit Exam Modal -->
    <div class="modal" id="examModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Add New Exam</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" id="examForm">
                <input type="hidden" name="exam_id" id="exam_id">
                <input type="hidden" name="class_id" id="class_id">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Exam Name *</label>
                            <input type="text" name="exam_name" id="exam_name" class="form-control" required placeholder="e.g., First Term Examination">
                        </div>
                        <div class="form-group">
                            <label>Class *</label>
                            <select name="class_name" id="class_name" class="form-select" required onchange="updateClassId()">
                                <option value="">Select Class</option>
                                <?php if (!empty($classes_list)): ?>
                                    <?php foreach ($classes_list as $class): ?>
                                        <option value="<?php echo htmlspecialchars($class['class_name']); ?>" data-id="<?php echo $class['id']; ?>">
                                            <?php echo htmlspecialchars($class['class_name']); ?>
                                            <?php if (!empty($class['class_code'])): ?>
                                                (<?php echo htmlspecialchars($class['class_code']); ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" disabled>No classes available. Please add classes first.</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Subject</label>
                            <select name="subject_id" id="subject_id" class="form-select">
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
                                    <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($value); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Duration (minutes) *</label>
                            <input type="number" name="duration_minutes" id="duration_minutes" class="form-control" min="1" max="360" value="60" required>
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
                            <textarea name="instructions" id="instructions" class="form-control" rows="3" placeholder="Enter exam instructions..."></textarea>
                        </div>
                        <div class="form-row">
                            <label><input type="checkbox" name="is_active" value="1" checked> Active (Students can take this exam)</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn"><i class="fas fa-save"></i> Save Exam</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Mobile menu
        const mobileBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        if (mobileBtn) {
            mobileBtn.onclick = function(e) {
                e.stopPropagation();
                sidebar.classList.toggle('active');
            }
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768 && sidebar && mobileBtn) {
                if (!sidebar.contains(e.target) && !mobileBtn.contains(e.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // Update class_id hidden field when class is selected
        function updateClassId() {
            const classSelect = document.getElementById('class_name');
            if (classSelect && classSelect.selectedIndex >= 0) {
                const selectedOption = classSelect.options[classSelect.selectedIndex];
                const classId = selectedOption ? selectedOption.getAttribute('data-id') : '';
                document.getElementById('class_id').value = classId;
            }
        }

        // Modal functions
        function openModal() {
            const modal = document.getElementById('examModal');
            if (modal) modal.classList.add('active');
        }

        function closeModal() {
            const modal = document.getElementById('examModal');
            if (modal) modal.classList.remove('active');
            resetForm();
        }

        function resetForm() {
            const form = document.getElementById('examForm');
            if (form) form.reset();
            const examId = document.getElementById('exam_id');
            if (examId) examId.value = '';
            const classId = document.getElementById('class_id');
            if (classId) classId.value = '';
            const modalTitle = document.getElementById('modalTitle');
            if (modalTitle) modalTitle.textContent = 'Add New Exam';
            const submitBtn = document.getElementById('submitBtn');
            if (submitBtn) {
                submitBtn.name = 'add_exam';
                submitBtn.innerHTML = '<i class="fas fa-save"></i> Save Exam';
            }
            const checkboxes = document.querySelectorAll('input[name="topics[]"]');
            checkboxes.forEach(cb => cb.checked = false);
            const durationInput = document.getElementById('duration_minutes');
            if (durationInput) durationInput.value = '60';
            const activeCheckbox = document.getElementById('is_active');
            if (activeCheckbox) activeCheckbox.checked = true;
            const classSelect = document.getElementById('class_name');
            if (classSelect) classSelect.value = '';
            updateQuestionCounts();
        }

        function openAddExamModal() {
            console.log('Opening add exam modal');
            resetForm();
            openModal();
        }

        function updateQuestionCounts() {
            const typeSelect = document.getElementById('exam_type');
            if (!typeSelect) return;
            const type = typeSelect.value;
            const obj = document.getElementById('objective_count');
            const sub = document.getElementById('subjective_count');
            const thy = document.getElementById('theory_count');

            if (!obj || !sub || !thy) return;

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
        const subjectSelect = document.getElementById('subject_id');
        if (subjectSelect) {
            subjectSelect.addEventListener('change', function() {
                const subjectId = this.value;
                const topicDivs = document.querySelectorAll('.topic-checkbox');
                topicDivs.forEach(div => {
                    div.style.display = (!subjectId || div.dataset.subject == subjectId) ? 'flex' : 'none';
                    if (div.style.display === 'none') {
                        const checkbox = div.querySelector('input');
                        if (checkbox) checkbox.checked = false;
                    }
                });
            });
        }

        // Function to safely set checkboxes based on topics array
        function setTopicsCheckboxes(topicsArray) {
            const checkboxes = document.querySelectorAll('input[name="topics[]"]');
            
            if (checkboxes.length === 0) {
                console.warn('No checkboxes found. Topics container might be empty.');
                return false;
            }
            
            console.log('Setting checkboxes for topics:', topicsArray);
            console.log('Found checkboxes count:', checkboxes.length);
            
            // First uncheck all
            checkboxes.forEach(cb => {
                cb.checked = false;
            });
            
            // Then check the ones that match
            if (topicsArray && topicsArray.length > 0) {
                topicsArray.forEach(topicId => {
                    checkboxes.forEach(cb => {
                        if (parseInt(cb.value) === parseInt(topicId)) {
                            cb.checked = true;
                            console.log('Checked topic:', topicId);
                        }
                    });
                });
            }
            
            return true;
        }

        // FIXED: editExam function with safer checkbox handling
        async function editExam(examId) {
            try {
                const btn = event.currentTarget;
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<div class="loading"></div>';
                btn.disabled = true;

                console.log('Fetching exam ID:', examId);
                
                const response = await fetch(`?get_exam=${examId}`);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                console.log('Response data:', data);

                btn.innerHTML = originalHTML;
                btn.disabled = false;

                if (data.success && data.exam) {
                    const e = data.exam;
                    
                    // Set basic fields
                    document.getElementById('exam_id').value = e.id;
                    document.getElementById('exam_name').value = e.exam_name || '';
                    
                    // Set class dropdown
                    if (e.class) {
                        const classSelect = document.getElementById('class_name');
                        let classFound = false;
                        for (let i = 0; i < classSelect.options.length; i++) {
                            if (classSelect.options[i].value === e.class) {
                                classSelect.selectedIndex = i;
                                classFound = true;
                                break;
                            }
                        }
                        if (!classFound && e.class_id) {
                            for (let i = 0; i < classSelect.options.length; i++) {
                                const optId = classSelect.options[i].getAttribute('data-id');
                                if (optId && parseInt(optId) === parseInt(e.class_id)) {
                                    classSelect.selectedIndex = i;
                                    break;
                                }
                            }
                        }
                        updateClassId();
                    }
                    
                    // Set subject
                    if (e.subject_id) {
                        document.getElementById('subject_id').value = e.subject_id;
                        if (subjectSelect) {
                            subjectSelect.dispatchEvent(new Event('change'));
                        }
                    } else {
                        document.getElementById('subject_id').value = '';
                    }
                    
                    // Set exam type and other fields
                    document.getElementById('exam_type').value = e.exam_type || 'objective';
                    document.getElementById('duration_minutes').value = e.duration_minutes || 60;
                    document.getElementById('objective_count').value = e.objective_count || 0;
                    document.getElementById('subjective_count').value = e.subjective_count || 0;
                    document.getElementById('theory_count').value = e.theory_count || 0;
                    document.getElementById('instructions').value = e.instructions || '';
                    document.getElementById('is_active').checked = e.is_active == 1;

                    // Parse topics
                    let topicsArray = [];
                    if (e.topics) {
                        try {
                            topicsArray = typeof e.topics === 'string' ? JSON.parse(e.topics) : e.topics;
                            if (!Array.isArray(topicsArray)) {
                                topicsArray = [];
                            }
                        } catch (parseErr) {
                            console.error('Error parsing topics:', parseErr);
                            topicsArray = [];
                        }
                    }
                    
                    // Update modal title and button
                    document.getElementById('modalTitle').textContent = 'Edit Exam';
                    const submitBtn = document.getElementById('submitBtn');
                    submitBtn.name = 'update_exam';
                    submitBtn.innerHTML = '<i class="fas fa-edit"></i> Update Exam';
                    
                    // Update question counts based on exam type
                    updateQuestionCounts();
                    
                    // Open the modal first
                    openModal();
                    
                    // Wait for modal to be fully visible and topics to be rendered
                    // Use multiple attempts to set checkboxes
                    setTimeout(() => {
                        const success = setTopicsCheckboxes(topicsArray);
                        if (!success) {
                            // If failed, try again after a longer delay
                            setTimeout(() => {
                                setTopicsCheckboxes(topicsArray);
                            }, 500);
                        }
                    }, 200);
                    
                } else {
                    alert('Error: ' + (data.error || 'Unknown error occurred'));
                }
            } catch (error) {
                console.error('Error loading exam:', error);
                alert('Error loading exam details. Please refresh and try again.\n\nError: ' + error.message);
                
                if (event && event.currentTarget) {
                    const btn = event.currentTarget;
                    btn.innerHTML = '<i class="fas fa-edit"></i>';
                    btn.disabled = false;
                }
            }
        }

        // Form validation
        const examForm = document.getElementById('examForm');
        if (examForm) {
            examForm.addEventListener('submit', function(e) {
                const examName = document.getElementById('exam_name');
                const classSelect = document.getElementById('class_name');

                if (!examName.value.trim()) {
                    e.preventDefault();
                    alert('Please enter exam name');
                    examName.focus();
                    return false;
                }
                if (!classSelect.value) {
                    e.preventDefault();
                    alert('Please select a class');
                    classSelect.focus();
                    return false;
                }
                
                updateClassId();
            });
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateQuestionCounts();
            console.log('Page loaded - modal functionality ready');
            
            // Debug: Check if topics container has checkboxes
            const checkboxes = document.querySelectorAll('input[name="topics[]"]');
            console.log('Total topics checkboxes found:', checkboxes.length);
        });
    </script>
    <!-- ═══════════════════════════════════════════════
         EXAM DETAILS MODAL
    ═══════════════════════════════════════════════ -->
    <div class="modal" id="detailsModal">
        <div class="modal-content" style="max-width:900px;">
            <div class="modal-header">
                <div>
                    <h3 id="detailsModalTitle" style="color:var(--primary-color);">Exam Details</h3>
                    <p id="detailsModalSubtitle" style="font-size:.82rem;color:#666;margin-top:3px;"></p>
                </div>
                <button class="modal-close" onclick="closeDetailsModal()">&times;</button>
            </div>
            <div class="modal-body" id="detailsModalBody">
                <!-- filled by JS -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeDetailsModal()">Close</button>
                <button class="btn btn-danger" id="resetAllBtn" onclick="resetAllSessions()" style="display:none;">
                    <i class="fas fa-trash-alt"></i> Reset All Sessions
                </button>
            </div>
        </div>
    </div>

    <style>
        /* Details modal extras */
        .summary-cards { display:flex; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
        .summary-card  { flex:1; min-width:110px; background:#f8f9fa; border-radius:10px;
            padding:14px 18px; text-align:center; border-top:3px solid #ccc; }
        .summary-card.total     { border-color:var(--info-color); }
        .summary-card.completed { border-color:var(--success-color); }
        .summary-card.progress  { border-color:var(--warning-color); }
        .summary-card .sc-num   { font-size:1.8rem; font-weight:700; line-height:1; }
        .summary-card .sc-label { font-size:.75rem; color:#666; margin-top:4px; }
        .summary-card.total     .sc-num { color:var(--info-color); }
        .summary-card.completed .sc-num { color:var(--success-color); }
        .summary-card.progress  .sc-num { color:var(--warning-color); }

        .details-table { width:100%; border-collapse:collapse; font-size:.84rem; }
        .details-table th { background:var(--primary-color); color:white; padding:10px 12px;
            text-align:left; font-weight:600; white-space:nowrap; }
        .details-table td { padding:10px 12px; border-bottom:1px solid #eee; vertical-align:middle; }
        .details-table tr:hover td { background:#f9f9f9; }
        .details-table .status-pill { padding:3px 10px; border-radius:20px; font-size:.72rem; font-weight:600; }
        .status-pill.completed  { background:#d5f4e6; color:#155724; }
        .status-pill.in_progress { background:#fff3cd; color:#856404; }
        .grade-pill { display:inline-block; padding:2px 9px; border-radius:12px; font-size:.75rem;
            font-weight:700; background:#e3f2fd; color:#1565c0; }
        .reset-btn { background:var(--danger-color); color:white; border:none; padding:5px 10px;
            border-radius:6px; cursor:pointer; font-size:.78rem; display:inline-flex; align-items:center; gap:5px; }
        .reset-btn:hover { opacity:.85; }
        .no-sessions { text-align:center; padding:40px; color:#aaa; }
        .no-sessions i { font-size:36px; margin-bottom:10px; }

        .details-filters { display:flex; gap:10px; margin-bottom:14px; flex-wrap:wrap; align-items:center; }
        .details-filters select { padding:7px 12px; border:2px solid #e0e0e0; border-radius:8px; font-size:.85rem; font-family:'Poppins',sans-serif; }
        .details-filters input  { padding:7px 12px; border:2px solid #e0e0e0; border-radius:8px; font-size:.85rem; font-family:'Poppins',sans-serif; flex:1; min-width:160px; }
    </style>

    <script>
        // ── Exam Details ────────────────────────────────────────────────────
        let currentExamId   = null;
        let allSessions     = [];

        async function viewExamDetails(examId, examName) {
            currentExamId = examId;
            document.getElementById('detailsModalTitle').textContent   = examName;
            document.getElementById('detailsModalSubtitle').textContent = 'Loading sessions…';
            document.getElementById('detailsModalBody').innerHTML =
                '<div style="text-align:center;padding:40px;"><div class="loading" style="width:32px;height:32px;border-width:3px;border-color:rgba(0,0,0,.1);border-top-color:var(--primary-color);display:inline-block;"></div><p style="margin-top:12px;color:#666;">Fetching session data…</p></div>';
            document.getElementById('resetAllBtn').style.display = 'none';
            document.getElementById('detailsModal').classList.add('active');

            try {
                const res  = await fetch(`?get_exam_details=${examId}`);
                const data = await res.json();

                if (!data.success) throw new Error(data.error || 'Failed to load details');

                allSessions = data.sessions;
                renderDetailsModal(data);
            } catch (err) {
                document.getElementById('detailsModalBody').innerHTML =
                    `<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> ${escHtml(err.message)}</div>`;
            }
        }

        function renderDetailsModal(data) {
            const { exam, sessions, summary } = data;
            const body = document.getElementById('detailsModalBody');

            document.getElementById('detailsModalSubtitle').textContent =
                `Class: ${exam.class || '—'}  ·  Type: ${exam.exam_type}`;
            if (summary.total > 0)
                document.getElementById('resetAllBtn').style.display = 'inline-flex';

            body.innerHTML = `
                <!-- Summary cards -->
                <div class="summary-cards">
                    <div class="summary-card total">
                        <div class="sc-num">${summary.total}</div>
                        <div class="sc-label">Total</div>
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

                <!-- Filters -->
                <div class="details-filters">
                    <input type="text" id="detailsSearch" placeholder="Search student name / admission no…" oninput="filterSessions()">
                    <select id="detailsStatusFilter" onchange="filterSessions()">
                        <option value="">All Statuses</option>
                        <option value="completed">Completed</option>
                        <option value="in_progress">In Progress</option>
                    </select>
                </div>

                <!-- Table -->
                <div style="overflow-x:auto;">
                    <table class="details-table" id="sessionsTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Student</th>
                                <th>Admission No.</th>
                                <th>Status</th>
                                <th>Started</th>
                                <th>Submitted</th>
                                <th>Score</th>
                                <th>%</th>
                                <th>Grade</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="sessionsTableBody">
                            ${buildSessionRows(sessions)}
                        </tbody>
                    </table>
                </div>
                ${sessions.length === 0 ? `
                    <div class="no-sessions">
                        <i class="fas fa-users-slash"></i>
                        <p>No sessions found for this exam yet.</p>
                    </div>` : ''}
            `;
        }

        function buildSessionRows(sessions) {
            if (!sessions.length) return '';
            return sessions.map((s, i) => {
                const started   = s.start_time   ? fmtDate(s.start_time)   : '—';
                const submitted = s.submitted_at  ? fmtDate(s.submitted_at) : (s.end_time ? fmtDate(s.end_time) : '—');
                const score     = s.score != null ? `${s.correct_answers ?? 0}/${s.total_questions ?? 0}` : '—';
                const pct       = s.percentage   != null ? parseFloat(s.percentage).toFixed(1) + '%' : '—';
                const grade     = s.grade         ? `<span class="grade-pill">${escHtml(s.grade)}</span>` : '—';
                const pillCls   = s.status === 'completed' ? 'completed' : 'in_progress';
                const pillTxt   = s.status === 'completed' ? 'Completed' : 'In Progress';
                return `
                    <tr data-name="${escAttr((s.student_name||'').toLowerCase())}"
                        data-adm="${escAttr((s.admission_number||'').toLowerCase())}"
                        data-status="${escAttr(s.status)}">
                        <td>${i + 1}</td>
                        <td><strong>${escHtml(s.student_name || 'Unknown')}</strong></td>
                        <td>${escHtml(s.admission_number || '—')}</td>
                        <td><span class="status-pill ${pillCls}">${pillTxt}</span></td>
                        <td>${started}</td>
                        <td>${submitted}</td>
                        <td>${score}</td>
                        <td>${pct}</td>
                        <td>${grade}</td>
                        <td>
                            <button class="reset-btn" onclick="resetSession(${s.id}, '${escAttr(s.student_name || 'this student')}')">
                                <i class="fas fa-undo"></i> Reset
                            </button>
                        </td>
                    </tr>`;
            }).join('');
        }

        function filterSessions() {
            const search = (document.getElementById('detailsSearch')?.value || '').toLowerCase();
            const status = document.getElementById('detailsStatusFilter')?.value || '';
            const rows   = document.querySelectorAll('#sessionsTableBody tr');
            rows.forEach(row => {
                const nameMatch   = !search || row.dataset.name?.includes(search) || row.dataset.adm?.includes(search);
                const statusMatch = !status || row.dataset.status === status;
                row.style.display = nameMatch && statusMatch ? '' : 'none';
            });
        }

        async function resetSession(sessionId, studentName) {
            if (!confirm(`Reset ${studentName}'s session? This will permanently delete their answers and allow them to retake the exam.`)) return;

            try {
                const res  = await fetch(`?reset_session=${sessionId}&exam_id=${currentExamId}`);
                const data = await res.json();
                if (!data.success) throw new Error(data.error);

                // Refresh the modal
                const refreshRes  = await fetch(`?get_exam_details=${currentExamId}`);
                const refreshData = await refreshRes.json();
                if (refreshData.success) {
                    allSessions = refreshData.sessions;
                    renderDetailsModal(refreshData);
                }
            } catch (err) {
                alert('Reset failed: ' + err.message);
            }
        }

        async function resetAllSessions() {
            const count = allSessions.length;
            if (!confirm(`Reset ALL ${count} session(s) for this exam? Every student's answers will be permanently deleted and they can retake it. This cannot be undone.`)) return;

            try {
                const res  = await fetch(`?reset_all_sessions=1&exam_id=${currentExamId}`);
                const data = await res.json();
                if (!data.success) throw new Error(data.error);

                // Refresh the modal
                const refreshRes  = await fetch(`?get_exam_details=${currentExamId}`);
                const refreshData = await refreshRes.json();
                if (refreshData.success) {
                    allSessions = refreshData.sessions;
                    renderDetailsModal(refreshData);
                }
            } catch (err) {
                alert('Reset failed: ' + err.message);
            }
        }

        function closeDetailsModal() {
            document.getElementById('detailsModal').classList.remove('active');
            currentExamId = null;
            allSessions   = [];
        }

        // ── Utilities ───────────────────────────────────────────────────────
        function escHtml(str) {
            const d = document.createElement('div');
            d.textContent = String(str || '');
            return d.innerHTML;
        }
        function escAttr(str) {
            return String(str || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
        function fmtDate(dt) {
            if (!dt) return '—';
            const d = new Date(dt);
            if (isNaN(d)) return dt;
            return d.toLocaleString('en-GB', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' });
        }
    </script>

</body>

</html>
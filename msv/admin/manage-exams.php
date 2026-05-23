<?php
// admin/manage-exams.php - Complete Exam Management with Multi-School Support
session_start();

// Check if admin is logged in (support both session styles)
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

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;

// ============================================
// HANDLE FORM SUBMISSIONS
// ============================================

$message = '';
$message_type = '';

// Add new exam
// Add new exam - CORRECTED
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_exam'])) {
    try {
        $exam_name = trim($_POST['exam_name']);
        $class = trim($_POST['class']);
        $subject_id = !empty($_POST['subject_id']) ? intval($_POST['subject_id']) : null;
        $topics = isset($_POST['topics']) ? json_encode($_POST['topics']) : '[]';
        $duration_minutes = intval($_POST['duration_minutes']);
        $objective_count = isset($_POST['objective_count']) ? intval($_POST['objective_count']) : 0;
        $subjective_count = isset($_POST['subjective_count']) ? intval($_POST['subjective_count']) : 0;
        $theory_count = isset($_POST['theory_count']) ? intval($_POST['theory_count']) : 0;
        $exam_type = trim($_POST['exam_type']);
        $instructions = trim($_POST['instructions']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Validate required fields
        if (empty($exam_name)) throw new Exception("Exam name is required");
        if (empty($class)) throw new Exception("Class is required");
        if (empty($duration_minutes)) throw new Exception("Duration is required");

        // CORRECTED: 11 placeholders for 11 values
        $stmt = $pdo->prepare("
            INSERT INTO exams (
                exam_name, class, subject_id, topics, duration_minutes,
                objective_count, subjective_count, theory_count, exam_type,
                instructions, is_active, school_id, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $result = $stmt->execute([
            $exam_name,
            $class,
            $subject_id,
            $topics,
            $duration_minutes,
            $objective_count,
            $subjective_count,
            $theory_count,
            $exam_type,
            $instructions,
            $is_active,
            $school_id  // 11 values
        ]);

        if ($result) {
            $message = "Exam added successfully!";
            $message_type = "success";
        } else {
            throw new Exception("Failed to insert exam");
        }
    } catch (Exception $e) {
        $message = "Error adding exam: " . $e->getMessage();
        $message_type = "error";
        error_log("Add exam error: " . $e->getMessage());
    }
}

// Update exam - CORRECTED
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_exam'])) {
    try {
        $exam_id = intval($_POST['exam_id']);
        $exam_name = trim($_POST['exam_name']);
        $class = trim($_POST['class']);
        $subject_id = !empty($_POST['subject_id']) ? intval($_POST['subject_id']) : null;
        $topics = isset($_POST['topics']) ? json_encode($_POST['topics']) : '[]';
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

        // CORRECTED: 12 placeholders for 12 values (including WHERE clause values)
        $stmt = $pdo->prepare("
            UPDATE exams SET
                exam_name = ?, class = ?, subject_id = ?, topics = ?,
                duration_minutes = ?, objective_count = ?, subjective_count = ?,
                theory_count = ?, exam_type = ?, instructions = ?, is_active = ?,
                updated_at = NOW()
            WHERE id = ? AND school_id = ?
        ");

        $result = $stmt->execute([
            $exam_name,
            $class,
            $subject_id,
            $topics,
            $duration_minutes,
            $objective_count,
            $subjective_count,
            $theory_count,
            $exam_type,
            $instructions,
            $is_active,
            $exam_id,
            $school_id  // 13 values for 13 placeholders
        ]);

        if ($result) {
            $message = "Exam updated successfully!";
            $message_type = "success";
        } else {
            throw new Exception("Failed to update exam");
        }
    } catch (Exception $e) {
        $message = "Error updating exam: " . $e->getMessage();
        $message_type = "error";
        error_log("Update exam error: " . $e->getMessage());
    }
}

// Clone exam - CORRECTED
if (isset($_GET['clone_exam'])) {
    try {
        $exam_id = intval($_GET['clone_exam']);

        // Get original exam
        $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ? AND school_id = ?");
        $stmt->execute([$exam_id, $school_id]);
        $original = $stmt->fetch();

        if ($original) {
            // CORRECTED: 11 placeholders for 11 values
            $stmt = $pdo->prepare("
                INSERT INTO exams (
                    exam_name, class, subject_id, topics, duration_minutes,
                    objective_count, subjective_count, theory_count, exam_type,
                    instructions, is_active, school_id, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $new_name = $original['exam_name'] . " (Copy)";
            $stmt->execute([
                $new_name,
                $original['class'],
                $original['subject_id'],
                $original['topics'],
                $original['duration_minutes'],
                $original['objective_count'],
                $original['subjective_count'],
                $original['theory_count'],
                $original['exam_type'],
                $original['instructions'],
                $school_id  // 11 values
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

// Update exam
// Add new exam - CORRECTED
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_exam'])) {
    try {
        $exam_name = trim($_POST['exam_name']);
        $class = trim($_POST['class']);
        $subject_id = !empty($_POST['subject_id']) ? intval($_POST['subject_id']) : null;
        $topics = isset($_POST['topics']) ? json_encode($_POST['topics']) : '[]';
        $duration_minutes = intval($_POST['duration_minutes']);
        $objective_count = isset($_POST['objective_count']) ? intval($_POST['objective_count']) : 0;
        $subjective_count = isset($_POST['subjective_count']) ? intval($_POST['subjective_count']) : 0;
        $theory_count = isset($_POST['theory_count']) ? intval($_POST['theory_count']) : 0;
        $exam_type = trim($_POST['exam_type']);
        $instructions = trim($_POST['instructions']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Validate required fields
        if (empty($exam_name)) throw new Exception("Exam name is required");
        if (empty($class)) throw new Exception("Class is required");
        if (empty($duration_minutes)) throw new Exception("Duration is required");

        // CORRECTED: 11 placeholders for 11 values
        $stmt = $pdo->prepare("
            INSERT INTO exams (
                exam_name, class, subject_id, topics, duration_minutes,
                objective_count, subjective_count, theory_count, exam_type,
                instructions, is_active, school_id, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $result = $stmt->execute([
            $exam_name,
            $class,
            $subject_id,
            $topics,
            $duration_minutes,
            $objective_count,
            $subjective_count,
            $theory_count,
            $exam_type,
            $instructions,
            $is_active,
            $school_id  // 11 values
        ]);

        if ($result) {
            $message = "Exam added successfully!";
            $message_type = "success";
        } else {
            throw new Exception("Failed to insert exam");
        }
    } catch (Exception $e) {
        $message = "Error adding exam: " . $e->getMessage();
        $message_type = "error";
        error_log("Add exam error: " . $e->getMessage());
    }
}

// Add new exam - COMPLETELY REWRITTEN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_exam'])) {
    try {
        $exam_name = trim($_POST['exam_name']);
        $class = trim($_POST['class']);
        $subject_id = !empty($_POST['subject_id']) ? intval($_POST['subject_id']) : null;
        $topics = isset($_POST['topics']) ? json_encode($_POST['topics']) : '[]';
        $duration_minutes = intval($_POST['duration_minutes']);
        $objective_count = isset($_POST['objective_count']) ? intval($_POST['objective_count']) : 0;
        $subjective_count = isset($_POST['subjective_count']) ? intval($_POST['subjective_count']) : 0;
        $theory_count = isset($_POST['theory_count']) ? intval($_POST['theory_count']) : 0;
        $exam_type = trim($_POST['exam_type']);
        $instructions = trim($_POST['instructions']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Validate required fields
        if (empty($exam_name)) throw new Exception("Exam name is required");
        if (empty($class)) throw new Exception("Class is required");
        if (empty($duration_minutes)) throw new Exception("Duration is required");

        // Using named placeholders for clarity and to avoid count mismatch
        $sql = "INSERT INTO exams (
                    exam_name, 
                    class, 
                    subject_id, 
                    topics, 
                    duration_minutes,
                    objective_count, 
                    subjective_count, 
                    theory_count, 
                    exam_type,
                    instructions, 
                    is_active, 
                    school_id, 
                    created_at
                ) VALUES (
                    :exam_name,
                    :class,
                    :subject_id,
                    :topics,
                    :duration_minutes,
                    :objective_count,
                    :subjective_count,
                    :theory_count,
                    :exam_type,
                    :instructions,
                    :is_active,
                    :school_id,
                    NOW()
                )";

        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            ':exam_name' => $exam_name,
            ':class' => $class,
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

        if ($result) {
            $message = "Exam added successfully!";
            $message_type = "success";
        } else {
            throw new Exception("Failed to insert exam");
        }
    } catch (Exception $e) {
        $message = "Error adding exam: " . $e->getMessage();
        $message_type = "error";
        error_log("Add exam error: " . $e->getMessage());
    }
}

// Update exam - COMPLETELY REWRITTEN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_exam'])) {
    try {
        $exam_id = intval($_POST['exam_id']);
        $exam_name = trim($_POST['exam_name']);
        $class = trim($_POST['class']);
        $subject_id = !empty($_POST['subject_id']) ? intval($_POST['subject_id']) : null;
        $topics = isset($_POST['topics']) ? json_encode($_POST['topics']) : '[]';
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

        // Using named placeholders for clarity
        $sql = "UPDATE exams SET 
                    exam_name = :exam_name,
                    class = :class,
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
        $result = $stmt->execute([
            ':exam_name' => $exam_name,
            ':class' => $class,
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

        if ($result) {
            $message = "Exam updated successfully!";
            $message_type = "success";
        } else {
            throw new Exception("Failed to update exam");
        }
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
                    exam_name, class, subject_id, topics, duration_minutes,
                    objective_count, subjective_count, theory_count, exam_type,
                    instructions, is_active, school_id, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $new_name = $original['exam_name'] . " (Copy)";
            $stmt->execute([
                $new_name,
                $original['class'],
                $original['subject_id'],
                $original['topics'],
                $original['duration_minutes'],
                $original['objective_count'],
                $original['subjective_count'],
                $original['theory_count'],
                $original['exam_type'],
                $original['instructions'],
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

// FETCH CLASSES FROM THE CLASSES TABLE
$classes_list = [];
try {
    // Query the 'classes' table (not school_classes)
    $stmt = $pdo->prepare("SELECT id, class_name, class_code, class_category FROM classes WHERE school_id = ? AND status = 'active' ORDER BY sort_order, class_name");
    $stmt->execute([$school_id]);
    $classes_list = $stmt->fetchAll();

    // Debug: Log the number of classes found
    error_log("Classes found: " . count($classes_list));
} catch (Exception $e) {
    error_log("Error fetching classes: " . $e->getMessage());
}

// Also get distinct classes from exams for filter
$classes = $pdo->prepare("SELECT DISTINCT class FROM exams WHERE school_id = ? AND class IS NOT NULL AND class != '' ORDER BY class");
$classes->execute([$school_id]);
$classes = $classes->fetchAll();

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

// Get single exam for editing via AJAX
if (isset($_GET['get_exam'])) {
    try {
        $exam_id = intval($_GET['get_exam']);
        $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ? AND school_id = ?");
        $stmt->execute([$exam_id, $school_id]);
        $exam = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($exam) {
            $exam['topics'] = json_decode($exam['topics'], true);
            header('Content-Type: application/json');
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

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
            <div class="logo-text">
                <h3><?php echo htmlspecialchars($school_name); ?></h3>
                <p>Admin Panel</p>
            </div>
        </div>
        <div class="admin-info">
            <h4><?php echo htmlspecialchars($admin_name); ?></h4>
            <p><?php echo ucfirst($admin_role); ?></p>
        </div>
        <div class="sidebar-content">
            <ul class="nav-links">
                <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="manage-students.php"><i class="fas fa-users"></i> Manage Students</a></li>
                <li><a href="manage-staff.php"><i class="fas fa-chalkboard-teacher"></i> Manage Staff</a></li>
                <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Manage Subjects</a></li>
                <li><a href="manage-classes.php"><i class="fas fa-building"></i> Manage Classes</a></li>
                <li><a href="manage-exams.php" class="active"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
                <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
                <li><a href="attendance.php"><i class="fas fa-calendar-check"></i> Attendance Reports</a></li>
                <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
                <li><a href="sync.php"><i class="fas fa-sync-alt"></i> Sync to Cloud</a></li>
                <li><a href="../msv/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1>Manage Exams</h1>
                <p>Create, edit, and manage examination schedules</p>
            </div>
            <button class="logout-btn" onclick="window.location.href='../msv/logout.php'">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

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
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo htmlspecialchars($class['class']); ?>" <?php echo $class_filter === $class['class'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class']); ?>
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
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Exam Name *</label>
                            <input type="text" name="exam_name" id="exam_name" class="form-control" required placeholder="e.g., First Term Examination">
                        </div>
                        <div class="form-group">
                            <label>Class *</label>
                            <select name="class" id="class_name" class="form-select" required>
                                <option value="">Select Class</option>
                                <?php if (!empty($classes_list)): ?>
                                    <?php foreach ($classes_list as $class): ?>
                                        <option value="<?php echo htmlspecialchars($class['class_name']); ?>">
                                            <?php echo htmlspecialchars($class['class_name']); ?>
                                            <?php if (!empty($class['class_code'])): ?>
                                                (<?php echo htmlspecialchars($class['class_code']); ?>)
                                            <?php endif; ?>
                                            <?php if (!empty($class['class_category'])): ?>
                                                - <?php echo htmlspecialchars($class['class_category']); ?>
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
                    <button type="submit" class="btn btn-primary" name="add_exam" id="submitBtn"><i class="fas fa-save"></i> Save Exam</button>
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
                if (obj.value == 0) obj.value = 0;
                sub.value = 0;
                thy.value = 0;
            } else if (type === 'subjective') {
                obj.disabled = true;
                sub.disabled = false;
                thy.disabled = true;
                obj.value = 0;
                if (sub.value == 0) sub.value = 0;
                thy.value = 0;
            } else if (type === 'theory') {
                obj.disabled = true;
                sub.disabled = true;
                thy.disabled = false;
                obj.value = 0;
                sub.value = 0;
                if (thy.value == 0) thy.value = 0;
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

        async function editExam(examId) {
            try {
                const btn = event.currentTarget;
                const original = btn.innerHTML;
                btn.innerHTML = '<div class="loading"></div>';
                btn.disabled = true;

                const response = await fetch(`?get_exam=${examId}`);
                const data = await response.json();

                btn.innerHTML = original;
                btn.disabled = false;

                if (data.success) {
                    const e = data.exam;
                    document.getElementById('exam_id').value = e.id;
                    document.getElementById('exam_name').value = e.exam_name || '';
                    document.getElementById('class_name').value = e.class || '';
                    document.getElementById('subject_id').value = e.subject_id || '';
                    document.getElementById('exam_type').value = e.exam_type || 'objective';
                    document.getElementById('duration_minutes').value = e.duration_minutes || 60;
                    document.getElementById('objective_count').value = e.objective_count || 0;
                    document.getElementById('subjective_count').value = e.subjective_count || 0;
                    document.getElementById('theory_count').value = e.theory_count || 0;
                    document.getElementById('instructions').value = e.instructions || '';
                    document.getElementById('is_active').checked = e.is_active == 1;

                    if (e.topics) {
                        const topics = typeof e.topics === 'string' ? JSON.parse(e.topics) : e.topics;
                        document.querySelectorAll('input[name="topics[]"]').forEach(cb => {
                            cb.checked = topics.includes(parseInt(cb.value));
                        });
                    }

                    document.getElementById('modalTitle').textContent = 'Edit Exam';
                    document.getElementById('submitBtn').name = 'update_exam';
                    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-edit"></i> Update Exam';
                    updateQuestionCounts();

                    // Trigger subject filter
                    if (subjectSelect) subjectSelect.dispatchEvent(new Event('change'));

                    openModal();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            } catch (e) {
                console.error('Error loading exam:', e);
                alert('Error loading exam details');
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
        });
    </script>
</body>

</html>
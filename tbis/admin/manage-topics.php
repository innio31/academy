<?php
// admin/manage-topics.php - Manage Topics with Manual Addition Support
session_start();

// Check if admin is logged in
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
$page_title = "Manage Topics";

$message = '';
$message_type = '';

// ============================================
// ENSURE TOPICS TABLE HAS CENTRAL SUPPORT
// ============================================

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM topics LIKE 'is_central'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE topics ADD COLUMN is_central TINYINT(1) DEFAULT 0");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM topics LIKE 'school_id'");
    $col = $stmt->fetch();
    if ($col && $col['Null'] === 'NO') {
        $pdo->exec("ALTER TABLE topics MODIFY COLUMN school_id INT NULL");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM topics LIKE 'term'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE topics ADD COLUMN term ENUM('First','Second','Third') NULL AFTER topic_name");
    }
} catch (Exception $e) {
    error_log("Table check error: " . $e->getMessage());
}

// ============================================
// HANDLE FORM SUBMISSIONS
// ============================================

// Add manual topic (NEW)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_manual_topic'])) {
    try {
        $subject_id_post = (int)$_POST['subject_id'];
        $topic_name = trim($_POST['topic_name']);
        $term = $_POST['term'] ?? 'First';
        $description = trim($_POST['description'] ?? '');

        if (empty($subject_id_post)) {
            throw new Exception("Please select a subject");
        }

        if (empty($topic_name)) {
            throw new Exception("Please enter a topic name");
        }

        // Verify subject belongs to this school
        $stmt = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE id = ? AND school_id = ?");
        $stmt->execute([$subject_id_post, $school_id]);
        $school_subject = $stmt->fetch();

        if (!$school_subject) {
            throw new Exception("Invalid subject selected");
        }

        // Check if topic already exists for this subject
        $stmt = $pdo->prepare("SELECT id FROM topics WHERE topic_name = ? AND subject_id = ? AND school_id = ?");
        $stmt->execute([$topic_name, $subject_id_post, $school_id]);
        if ($stmt->fetch()) {
            throw new Exception("Topic '{$topic_name}' already exists for this subject");
        }

        // Insert manual topic
        $stmt = $pdo->prepare("INSERT INTO topics (topic_name, term, subject_id, description, school_id, is_central) VALUES (?, ?, ?, ?, ?, 0)");
        $stmt->execute([$topic_name, $term, $subject_id_post, $description, $school_id]);

        $message = "Topic '{$topic_name}' added successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Add multiple topics from central list
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_multiple_topics'])) {
    try {
        $selected_topic_ids = $_POST['central_topic_ids'] ?? [];
        $subject_id_post = (int)$_POST['subject_id'];

        if (empty($selected_topic_ids)) {
            throw new Exception("Please select at least one topic to add");
        }

        if (empty($subject_id_post)) {
            throw new Exception("Please select a subject");
        }

        $stmt = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE id = ? AND school_id = ?");
        $stmt->execute([$subject_id_post, $school_id]);
        $school_subject = $stmt->fetch();

        if (!$school_subject) {
            throw new Exception("Invalid subject selected");
        }

        $added_count = 0;
        $skipped_count = 0;

        foreach ($selected_topic_ids as $central_topic_id) {
            $stmt = $pdo->prepare("SELECT topic_name, description, term FROM topics WHERE id = ? AND school_id IS NULL AND is_central = 1");
            $stmt->execute([$central_topic_id]);
            $central_topic = $stmt->fetch();

            if (!$central_topic) continue;

            $stmt = $pdo->prepare("SELECT id FROM topics WHERE topic_name = ? AND subject_id = ? AND school_id = ?");
            $stmt->execute([$central_topic['topic_name'], $subject_id_post, $school_id]);
            if ($stmt->fetch()) {
                $skipped_count++;
                continue;
            }

            $stmt = $pdo->prepare("INSERT INTO topics (topic_name, term, subject_id, description, school_id, is_central) VALUES (?, ?, ?, ?, ?, 0)");
            $stmt->execute([
                $central_topic['topic_name'],
                $central_topic['term'],
                $subject_id_post,
                $central_topic['description'],
                $school_id
            ]);
            $added_count++;
        }

        if ($added_count > 0) {
            $message = "$added_count topic(s) added successfully to {$school_subject['subject_name']}";
            if ($skipped_count > 0) {
                $message .= ". $skipped_count topic(s) already existed.";
            }
            $message_type = "success";
        } else {
            throw new Exception("No topics were added. " . ($skipped_count > 0 ? "Selected topics already exist." : "Please select topics."));
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Edit topic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_topic'])) {
    $topic_id_edit = (int)$_POST['edit_topic_id'];
    $description = trim($_POST['edit_description']);

    try {
        $stmt = $pdo->prepare("SELECT topic_name FROM topics WHERE id = ? AND school_id = ? AND is_central = 0");
        $stmt->execute([$topic_id_edit, $school_id]);
        $topic_info = $stmt->fetch();

        if (!$topic_info) {
            throw new Exception("Topic not found");
        }

        $stmt = $pdo->prepare("UPDATE topics SET description = ? WHERE id = ? AND school_id = ?");
        $stmt->execute([$description, $topic_id_edit, $school_id]);

        $message = "Topic updated successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Delete topic
if (isset($_POST['delete_topic'])) {
    $topic_id_del = (int)$_POST['topic_id'];

    try {
        $stmt = $pdo->prepare("SELECT topic_name FROM topics WHERE id = ? AND school_id = ? AND is_central = 0");
        $stmt->execute([$topic_id_del, $school_id]);
        $topic_info = $stmt->fetch();

        if (!$topic_info) {
            throw new Exception("Topic not found");
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM objective_questions WHERE topic_id = ? AND school_id = ?");
        $stmt->execute([$topic_id_del, $school_id]);
        $objective_count = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM subjective_questions WHERE topic_id = ? AND school_id = ?");
        $stmt->execute([$topic_id_del, $school_id]);
        $subjective_count = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM theory_questions WHERE topic_id = ? AND school_id = ?");
        $stmt->execute([$topic_id_del, $school_id]);
        $theory_count = $stmt->fetchColumn();

        $total_questions = $objective_count + $subjective_count + $theory_count;

        if ($total_questions > 0) {
            throw new Exception("Cannot delete topic. It has $total_questions question(s).");
        }

        $pdo->prepare("DELETE FROM topics WHERE id = ? AND school_id = ?")->execute([$topic_id_del, $school_id]);

        $message = "Topic deleted successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Delete individual question
if (isset($_POST['delete_question'])) {
    $question_id = (int)$_POST['question_id'];
    $question_type = $_POST['question_type'];
    $topic_id_q = (int)$_POST['topic_id'];

    try {
        $table = '';
        switch ($question_type) {
            case 'objective':
                $table = 'objective_questions';
                break;
            case 'subjective':
                $table = 'subjective_questions';
                break;
            case 'theory':
                $table = 'theory_questions';
                break;
            default:
                throw new Exception("Invalid type");
        }

        $stmt = $pdo->prepare("DELETE FROM $table WHERE id = ? AND school_id = ? AND topic_id = ?");
        $stmt->execute([$question_id, $school_id, $topic_id_q]);

        $message = "Question deleted successfully!";
        $message_type = "success";

        header("Location: manage-topics.php?view_topic=$topic_id_q");
        exit();
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Get parameters
$subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
$view_topic_id = isset($_GET['view_topic']) ? (int)$_GET['view_topic'] : 0;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Get selected subject details
$selected_subject = null;
if ($subject_id) {
    try {
        $stmt = $pdo->prepare("SELECT s.*, GROUP_CONCAT(DISTINCT c.class_name) as assigned_classes FROM subjects s LEFT JOIN subject_classes sc ON s.id = sc.subject_id LEFT JOIN classes c ON sc.class_id = c.id WHERE s.id = ? AND s.school_id = ? GROUP BY s.id");
        $stmt->execute([$subject_id, $school_id]);
        $selected_subject = $stmt->fetch();
    } catch (Exception $e) {
        error_log("Error loading subject: " . $e->getMessage());
    }
}

// Get topic details and questions if viewing
$view_topic = null;
$objective_questions = [];
$subjective_questions = [];
$theory_questions = [];

if ($view_topic_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT t.*, s.subject_name 
            FROM topics t 
            JOIN subjects s ON t.subject_id = s.id 
            WHERE t.id = ? AND t.school_id = ?
        ");
        $stmt->execute([$view_topic_id, $school_id]);
        $view_topic = $stmt->fetch();

        if ($view_topic) {
            $obj_stmt = $pdo->prepare("SELECT * FROM objective_questions WHERE topic_id = ? AND school_id = ? ORDER BY id DESC");
            $obj_stmt->execute([$view_topic_id, $school_id]);
            $objective_questions = $obj_stmt->fetchAll();

            $sub_stmt = $pdo->prepare("SELECT * FROM subjective_questions WHERE topic_id = ? AND school_id = ? ORDER BY id DESC");
            $sub_stmt->execute([$view_topic_id, $school_id]);
            $subjective_questions = $sub_stmt->fetchAll();

            $theory_stmt = $pdo->prepare("SELECT * FROM theory_questions WHERE topic_id = ? AND school_id = ? ORDER BY id DESC");
            $theory_stmt->execute([$view_topic_id, $school_id]);
            $theory_questions = $theory_stmt->fetchAll();
        }
    } catch (Exception $e) {
        $message = "Error loading topic: " . $e->getMessage();
        $message_type = "error";
    }
}

// ============================================
// FETCH DATA
// ============================================

// Get all subjects for filter
$subjects = $pdo->prepare("SELECT * FROM subjects WHERE school_id = ? ORDER BY subject_name");
$subjects->execute([$school_id]);
$subjects = $subjects->fetchAll();

// Get available central topics
$available_central_topics = [];
if ($subject_id) {
    $stmt = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ? AND school_id = ?");
    $stmt->execute([$subject_id, $school_id]);
    $current_subject = $stmt->fetch();

    if ($current_subject) {
        $stmt = $pdo->prepare("
            SELECT * FROM topics 
            WHERE school_id IS NULL AND is_central = 1 
            AND subject_id = (SELECT id FROM subjects WHERE subject_name = ? AND school_id IS NULL AND is_central = 1)
            AND topic_name NOT IN (SELECT topic_name FROM topics WHERE subject_id = ? AND school_id = ?)
            ORDER BY 
                CASE 
                    WHEN term = 'First' THEN 1
                    WHEN term = 'Second' THEN 2
                    WHEN term = 'Third' THEN 3
                    ELSE 4
                END,
                topic_name
        ");
        $stmt->execute([$current_subject['subject_name'], $subject_id, $school_id]);
        $available_central_topics = $stmt->fetchAll();
    }
}

$topics_by_term = ['First' => [], 'Second' => [], 'Third' => []];
foreach ($available_central_topics as $topic) {
    $term = $topic['term'] ?? 'First';
    if (isset($topics_by_term[$term])) {
        $topics_by_term[$term][] = $topic;
    }
}

// Build topics query
$query = "
    SELECT t.*, s.subject_name,
           (SELECT COUNT(*) FROM objective_questions oq WHERE oq.topic_id = t.id AND oq.school_id = t.school_id) as objective_count,
           (SELECT COUNT(*) FROM subjective_questions sq WHERE sq.topic_id = t.id AND sq.school_id = t.school_id) as subjective_count,
           (SELECT COUNT(*) FROM theory_questions tq WHERE tq.topic_id = t.id AND tq.school_id = t.school_id) as theory_count
    FROM topics t
    JOIN subjects s ON t.subject_id = s.id
    WHERE t.school_id = ? AND t.is_central = 0
";

$params = [$school_id];

if ($subject_id) {
    $query .= " AND t.subject_id = ?";
    $params[] = $subject_id;
}

if ($search_query) {
    $query .= " AND (t.topic_name LIKE ? OR t.description LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

$query .= " ORDER BY s.subject_name, 
    CASE 
        WHEN t.term = 'First' THEN 1
        WHEN t.term = 'Second' THEN 2
        WHEN t.term = 'Third' THEN 3
        ELSE 4
    END,
    t.topic_name";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$topics = $stmt->fetchAll();

// Include sidebar
require_once 'includes/sidebar.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($school_name); ?> - Manage Topics</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --primary-dark: #1a5a8a;
            --secondary-color: #3498db;
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

        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

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

        .filter-group select {
            padding: 10px 14px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 0.85rem;
            background: white;
            cursor: pointer;
        }

        .search-box {
            position: relative;
            display: inline-block;
        }

        .search-box input {
            padding: 10px 15px 10px 40px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            width: 250px;
            font-size: 0.85rem;
        }

        .search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-600);
        }

        .subject-info-card {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            padding: 20px 25px;
            border-radius: var(--radius-lg);
            margin-bottom: 24px;
        }

        .subject-info-card h2 {
            margin-bottom: 8px;
        }

        .subject-info-card .class-tags {
            margin-top: 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .subject-info-card .class-tag {
            background: rgba(255, 255, 255, 0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
        }

        .warning-note {
            background: var(--warning-light);
            border-left: 4px solid var(--warning);
            padding: 12px 15px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.85rem;
            color: #856404;
        }

        .add-section {
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--gray-200);
            flex-wrap: wrap;
            gap: 12px;
        }

        .section-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
        }

        .tab-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .tab-btn {
            padding: 10px 20px;
            background: var(--gray-100);
            border: none;
            border-radius: var(--radius-md);
            cursor: pointer;
            font-weight: 500;
        }

        .tab-btn.active {
            background: var(--primary-color);
            color: white;
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            font-size: 0.85rem;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 0.85rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        .topics-list {
            max-height: 400px;
            overflow-y: auto;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
        }

        .topic-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 16px;
            border-bottom: 1px solid var(--gray-200);
        }

        .topic-item input {
            margin-top: 2px;
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary-color);
        }

        .topic-item label {
            flex: 1;
            cursor: pointer;
            font-size: 0.85rem;
        }

        .topic-desc {
            font-size: 0.7rem;
            color: var(--gray-600);
            display: block;
            margin-top: 2px;
        }

        .selected-count {
            background: var(--primary-color);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
        }

        .topics-grid {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .topic-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 18px;
            box-shadow: var(--shadow-sm);
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid var(--gray-200);
        }

        .topic-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary-color);
        }

        .topic-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
            flex-wrap: wrap;
            gap: 8px;
        }

        .topic-name {
            font-size: 1rem;
            font-weight: 700;
            color: var(--gray-800);
            background: var(--info-light);
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-block;
        }

        .topic-name i {
            margin-right: 6px;
            color: var(--primary-color);
        }

        .term-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .term-first {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .term-second {
            background: #fff3e0;
            color: #ef6c00;
        }

        .term-third {
            background: #e3f2fd;
            color: #1565c0;
        }

        .topic-description {
            font-size: 0.8rem;
            color: var(--gray-600);
            margin-bottom: 12px;
            line-height: 1.4;
        }

        .stats-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid var(--gray-200);
        }

        .stat-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .stat-badge.objective {
            background: #e3f2fd;
            color: #1976d2;
        }

        .stat-badge.subjective {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .stat-badge.theory {
            background: #e8f5e9;
            color: #388e3c;
        }

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

        .info-row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid var(--gray-200);
        }

        .info-label {
            width: 110px;
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

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray-600);
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
        }
    </style>
</head>

<body>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-tags"></i> Manage Topics</h1>
                <p><?php echo $selected_subject ? 'Subject: ' . htmlspecialchars($selected_subject['subject_name']) : 'Select a subject to manage topics'; ?></p>
            </div>
            <?php if (!$view_topic && $selected_subject): ?>
                <button class="btn btn-primary" id="openAddTopicsBtn">
                    <i class="fas fa-plus-circle"></i> Add Topics
                </button>
            <?php endif; ?>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($view_topic): ?>
            <!-- QUESTIONS VIEW MODE -->
            <a href="manage-topics.php?subject_id=<?php echo $subject_id; ?>" class="btn btn-primary" style="margin-bottom: 20px;">
                <i class="fas fa-arrow-left"></i> Back to Topics
            </a>

            <div class="subject-info-card">
                <h2><i class="fas fa-bookmark"></i> <?php echo htmlspecialchars($view_topic['topic_name']); ?></h2>
                <p><i class="fas fa-book"></i> Subject: <?php echo htmlspecialchars($view_topic['subject_name']); ?></p>
                <?php if ($view_topic['description']): ?>
                    <p><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($view_topic['description']); ?></p>
                <?php endif; ?>
                <div style="margin-top: 20px;">
                    <a href="manage-questions.php?topic_id=<?php echo $view_topic_id; ?>" class="btn btn-success">
                        <i class="fas fa-plus-circle"></i> Add New Question
                    </a>
                </div>
            </div>

            <div class="add-section">
                <div class="tab-buttons">
                    <button class="tab-btn active" onclick="showQuestionType('objective')">Objective (<?php echo count($objective_questions); ?>)</button>
                    <button class="tab-btn" onclick="showQuestionType('subjective')">Subjective (<?php echo count($subjective_questions); ?>)</button>
                    <button class="tab-btn" onclick="showQuestionType('theory')">Theory (<?php echo count($theory_questions); ?>)</button>
                </div>

                <div id="objective-panel" class="tab-pane active">
                    <?php if (empty($objective_questions)): ?>
                        <div class="empty-state"><i class="fas fa-check-circle" style="font-size: 48px; color: var(--gray-300);"></i>
                            <h3>No Objective Questions</h3>
                            <p>Click "Add New Question" to get started.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($objective_questions as $q): ?>
                            <div class="topic-card" style="cursor: default;">
                                <div class="topic-card-header"><span class="topic-name"><i class="fas fa-question-circle"></i> Q<?php echo $q['id']; ?></span></div>
                                <div class="topic-description"><?php echo htmlspecialchars(substr($q['question_text'], 0, 150)); ?></div>
                                <div class="stats-row">
                                    <span class="stat-badge objective">A: <?php echo htmlspecialchars(substr($q['option_a'], 0, 30)); ?></span>
                                    <span class="stat-badge">✓ Correct: <?php echo $q['correct_answer']; ?></span>
                                </div>
                                <div class="modal-action-buttons" style="margin-top: 12px; border-top: none; padding-top: 0;">
                                    <form method="POST" onsubmit="return confirm('Delete this question?')" style="width: 100%;">
                                        <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                        <input type="hidden" name="question_type" value="objective">
                                        <input type="hidden" name="topic_id" value="<?php echo $view_topic_id; ?>">
                                        <button type="submit" name="delete_question" class="btn btn-danger modal-action-btn"><i class="fas fa-trash"></i> Delete</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div id="subjective-panel" class="tab-pane">
                    <?php if (empty($subjective_questions)): ?>
                        <div class="empty-state"><i class="fas fa-edit" style="font-size: 48px; color: var(--gray-300);"></i>
                            <h3>No Subjective Questions</h3>
                        </div>
                    <?php else: ?>
                        <?php foreach ($subjective_questions as $q): ?>
                            <div class="topic-card" style="cursor: default;">
                                <div class="topic-card-header"><span class="topic-name"><i class="fas fa-pencil-alt"></i> Q<?php echo $q['id']; ?></span></div>
                                <div class="topic-description"><?php echo htmlspecialchars(substr($q['question_text'], 0, 150)); ?></div>
                                <div class="modal-action-buttons" style="margin-top: 12px; border-top: none; padding-top: 0;">
                                    <form method="POST" onsubmit="return confirm('Delete this question?')" style="width: 100%;">
                                        <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                        <input type="hidden" name="question_type" value="subjective">
                                        <input type="hidden" name="topic_id" value="<?php echo $view_topic_id; ?>">
                                        <button type="submit" name="delete_question" class="btn btn-danger modal-action-btn"><i class="fas fa-trash"></i> Delete</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div id="theory-panel" class="tab-pane">
                    <?php if (empty($theory_questions)): ?>
                        <div class="empty-state"><i class="fas fa-file-alt" style="font-size: 48px; color: var(--gray-300);"></i>
                            <h3>No Theory Questions</h3>
                        </div>
                    <?php else: ?>
                        <?php foreach ($theory_questions as $q): ?>
                            <div class="topic-card" style="cursor: default;">
                                <div class="topic-card-header"><span class="topic-name"><i class="fas fa-file-alt"></i> Q<?php echo $q['id']; ?></span></div>
                                <div class="topic-description"><?php echo htmlspecialchars(substr($q['question_text'] ?? '', 0, 150)); ?></div>
                                <div class="modal-action-buttons" style="margin-top: 12px; border-top: none; padding-top: 0;">
                                    <form method="POST" onsubmit="return confirm('Delete this question?')" style="width: 100%;">
                                        <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                        <input type="hidden" name="question_type" value="theory">
                                        <input type="hidden" name="topic_id" value="<?php echo $view_topic_id; ?>">
                                        <button type="submit" name="delete_question" class="btn btn-danger modal-action-btn"><i class="fas fa-trash"></i> Delete</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>
            <!-- TOPICS MANAGEMENT MODE -->

            <?php if ($selected_subject): ?>
                <div class="subject-info-card">
                    <h2><i class="fas fa-book"></i> <?php echo htmlspecialchars($selected_subject['subject_name']); ?></h2>
                    <?php if ($selected_subject['description']): ?>
                        <p><?php echo htmlspecialchars($selected_subject['description']); ?></p>
                    <?php endif; ?>
                    <?php if ($selected_subject['assigned_classes']): ?>
                        <div class="class-tags">
                            <span><i class="fas fa-users"></i> Classes offering this subject:</span>
                            <?php foreach (explode(',', $selected_subject['assigned_classes']) as $class): ?>
                                <span class="class-tag"><?php echo htmlspecialchars($class); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <p style="margin-top: 10px;"><i class="fas fa-list"></i> <?php echo count($topics); ?> Topics added</p>
                </div>

                <div class="warning-note">
                    <i class="fas fa-info-circle" style="font-size: 1.2rem;"></i>
                    <span><strong>Important:</strong> Each topic is created once per subject. The topic will be available for ALL classes that offer this subject.</span>
                </div>
            <?php endif; ?>

            <!-- Filter Bar -->
            <div class="filter-bar">
                <div class="filter-group">
                    <label>Filter by Subject:</label>
                    <select id="subjectFilter" onchange="filterBySubject()">
                        <option value="">All Subjects</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo $subject['id']; ?>" <?php echo ($subject_id == $subject['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <form method="GET" id="searchForm" style="display: inline;">
                        <?php if ($subject_id): ?>
                            <input type="hidden" name="subject_id" value="<?php echo $subject_id; ?>">
                        <?php endif; ?>
                        <input type="text" name="search" id="searchInput" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search topics..." onkeyup="handleSearch(event)">
                    </form>
                    <?php if ($search_query): ?>
                        <button class="search-clear-btn" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer;" onclick="clearSearch()"><i class="fas fa-times"></i></button>
                    <?php endif; ?>
                </div>
                <?php if ($subject_id || $search_query): ?>
                    <a href="manage-topics.php" class="btn btn-outline btn-sm">Clear Filters</a>
                <?php endif; ?>
            </div>

            <!-- Topics Grid -->
            <?php if (empty($topics)): ?>
                <div class="empty-state">
                    <i class="fas fa-list" style="font-size: 48px; color: var(--gray-300);"></i>
                    <h3>No Topics Found</h3>
                    <p><?php echo $search_query ? "No topics match '$search_query'" : ($selected_subject ? "Click 'Add Topics' to add topics" : "Select a subject to view topics"); ?></p>
                </div>
            <?php else: ?>
                <div class="topics-grid" id="topicsGrid">
                    <?php foreach ($topics as $topic): ?>
                        <div class="topic-card" data-topic-id="<?php echo $topic['id']; ?>">
                            <div class="topic-card-header">
                                <span class="topic-name"><i class="fas fa-bookmark"></i> <?php echo htmlspecialchars($topic['topic_name']); ?></span>
                                <?php
                                $term_class = '';
                                $term_icon = '';
                                switch ($topic['term']) {
                                    case 'First':
                                        $term_class = 'term-first';
                                        $term_icon = 'fa-leaf';
                                        break;
                                    case 'Second':
                                        $term_class = 'term-second';
                                        $term_icon = 'fa-sun';
                                        break;
                                    case 'Third':
                                        $term_class = 'term-third';
                                        $term_icon = 'fa-snowflake';
                                        break;
                                    default:
                                        $term_class = 'term-first';
                                        $term_icon = 'fa-bookmark';
                                }
                                ?>
                                <span class="term-badge <?php echo $term_class; ?>"><i class="fas <?php echo $term_icon; ?>"></i> <?php echo $topic['term'] ?? 'General'; ?></span>
                            </div>
                            <?php if (!$subject_id): ?>
                                <div style="margin-bottom: 8px;"><span class="stat-badge" style="background: var(--info-light); color: var(--info);"><i class="fas fa-book"></i> <?php echo htmlspecialchars($topic['subject_name']); ?></span></div>
                            <?php endif; ?>
                            <?php if ($topic['description']): ?>
                                <div class="topic-description"><?php echo htmlspecialchars(substr($topic['description'], 0, 100)) . (strlen($topic['description'] ?? '') > 100 ? '...' : ''); ?></div>
                            <?php endif; ?>
                            <div class="stats-row">
                                <span class="stat-badge objective"><i class="fas fa-check-circle"></i> Objective: <?php echo $topic['objective_count']; ?></span>
                                <span class="stat-badge subjective"><i class="fas fa-pencil-alt"></i> Subjective: <?php echo $topic['subjective_count']; ?></span>
                                <span class="stat-badge theory"><i class="fas fa-file-alt"></i> Theory: <?php echo $topic['theory_count']; ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Add Topics Modal (with Manual & Central tabs) -->
    <div class="modal" id="addTopicsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Add Topics</h3>
                <button class="close-modal" onclick="closeModal('addTopicsModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="tab-buttons">
                    <button class="tab-btn active" onclick="switchAddTab('manual')">Manual Entry</button>
                    <button class="tab-btn" onclick="switchAddTab('central')">From Curriculum</button>
                </div>

                <!-- Manual Entry Tab -->
                <div id="manual-tab" class="tab-pane active">
                    <form method="POST">
                        <input type="hidden" name="subject_id" value="<?php echo $subject_id; ?>">
                        <div class="form-group">
                            <label>Topic Name *</label>
                            <input type="text" name="topic_name" class="form-control" required placeholder="e.g., Introduction to Algebra">
                        </div>
                        <div class="form-group">
                            <label>Term</label>
                            <select name="term" class="form-control">
                                <option value="First">First Term</option>
                                <option value="Second">Second Term</option>
                                <option value="Third">Third Term</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Description <span class="optional">(optional)</span></label>
                            <textarea name="description" class="form-control" rows="3" placeholder="Brief description of the topic"></textarea>
                        </div>
                        <div class="modal-footer" style="padding: 0; margin-top: 20px;">
                            <button type="button" class="btn btn-outline" onclick="closeModal('addTopicsModal')">Cancel</button>
                            <button type="submit" name="add_manual_topic" class="btn btn-primary"><i class="fas fa-plus"></i> Add Topic</button>
                        </div>
                    </form>
                </div>

                <!-- Central Curriculum Tab -->
                <div id="central-tab" class="tab-pane">
                    <form method="POST" id="addTopicsForm">
                        <input type="hidden" name="subject_id" value="<?php echo $subject_id; ?>">
                        <?php if (empty($available_central_topics)): ?>
                            <div class="empty-state">
                                <i class="fas fa-check-circle" style="font-size: 48px; color: var(--success);"></i>
                                <h3>All Topics Added!</h3>
                                <p>All curriculum topics have been added to this subject.</p>
                            </div>
                        <?php else: ?>
                            <div style="display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap;">
                                <button type="button" class="btn btn-sm btn-primary" onclick="selectAllTopics()"><i class="fas fa-check-double"></i> Select All</button>
                                <button type="button" class="btn btn-sm btn-outline" onclick="deselectAllTopics()"><i class="fas fa-times"></i> Deselect All</button>
                            </div>
                            <div class="topics-list">
                                <?php foreach (['First', 'Second', 'Third'] as $term): ?>
                                    <?php if (!empty($topics_by_term[$term])): ?>
                                        <div style="background: var(--gray-100); padding: 8px 12px; font-weight: 600;"><?php echo $term; ?> Term</div>
                                        <?php foreach ($topics_by_term[$term] as $topic): ?>
                                            <div class="topic-item">
                                                <input type="checkbox" name="central_topic_ids[]" value="<?php echo $topic['id']; ?>" id="topic_<?php echo $topic['id']; ?>" class="topic-checkbox" onchange="updateTopicCount()">
                                                <label for="topic_<?php echo $topic['id']; ?>">
                                                    <?php echo htmlspecialchars($topic['topic_name']); ?>
                                                    <?php if ($topic['description']): ?>
                                                        <span class="topic-desc"><?php echo htmlspecialchars(substr($topic['description'], 0, 80)); ?>...</span>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <div style="margin-top: 16px;">
                                <span id="selectedTopicsCount" class="selected-count">0 selected</span>
                            </div>
                            <div class="modal-footer" style="padding: 0; margin-top: 20px;">
                                <button type="button" class="btn btn-outline" onclick="closeModal('addTopicsModal')">Cancel</button>
                                <button type="submit" name="add_multiple_topics" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Add Selected</button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Topic Detail Modal -->
    <div class="modal" id="topicModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTopicTitle">Topic Details</h3>
                <button class="close-modal" onclick="closeModal('topicModal')">&times;</button>
            </div>
            <div class="modal-body" id="topicModalBody">
                <div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-pulse fa-2x"></i>
                    <p>Loading...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Topic Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Topic Description</h3>
                <button class="close-modal" onclick="closeModal('editModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" id="edit_topic_id" name="edit_topic_id">
                    <div class="form-group">
                        <label>Topic Name</label>
                        <input type="text" id="edit_topic_name" class="form-control" readonly disabled>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea id="edit_description" name="edit_description" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">Cancel</button>
                    <button type="submit" name="edit_topic" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <h3>Confirm Delete</h3>
                <button class="close-modal" onclick="closeModal('deleteModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="deleteTopicName"></strong>?</p>
                <p style="color: var(--danger); margin-top: 10px;"><i class="fas fa-exclamation-triangle"></i> This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('deleteModal')">Cancel</button>
                <button class="btn btn-danger" id="confirmDeleteBtn">Delete Topic</button>
            </div>
        </div>
    </div>

    <script>
        let deleteId = null;

        function filterBySubject() {
            const subjectId = document.getElementById('subjectFilter').value;
            const searchVal = document.getElementById('searchInput')?.value || '';
            let url = 'manage-topics.php?';
            if (subjectId) url += `subject_id=${subjectId}`;
            if (searchVal) url += `${subjectId ? '&' : ''}search=${encodeURIComponent(searchVal)}`;
            window.location.href = url;
        }

        let searchTimeout;

        function handleSearch(event) {
            clearTimeout(searchTimeout);
            if (event.key === 'Enter') {
                document.getElementById('searchForm').submit();
                return;
            }
            searchTimeout = setTimeout(() => {
                document.getElementById('searchForm').submit();
            }, 500);
        }

        function clearSearch() {
            document.getElementById('searchInput').value = '';
            const form = document.getElementById('searchForm');
            const hiddenInput = form.querySelector('input[name="search"]');
            if (hiddenInput) hiddenInput.remove();
            form.submit();
        }

        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = '';
        }

        // Add topics modal
        const openAddBtn = document.getElementById('openAddTopicsBtn');
        if (openAddBtn) openAddBtn.onclick = () => openModal('addTopicsModal');

        function switchAddTab(tab) {
            document.getElementById('manual-tab').classList.remove('active');
            document.getElementById('central-tab').classList.remove('active');
            document.querySelectorAll('#addTopicsModal .tab-btn').forEach(btn => btn.classList.remove('active'));
            if (tab === 'manual') {
                document.getElementById('manual-tab').classList.add('active');
                event.target.classList.add('active');
            } else {
                document.getElementById('central-tab').classList.add('active');
                event.target.classList.add('active');
            }
        }

        function showQuestionType(type) {
            document.querySelectorAll('#objective-panel, #subjective-panel, #theory-panel').forEach(panel => panel.classList.remove('active'));
            document.querySelectorAll('.tab-buttons .tab-btn').forEach(btn => btn.classList.remove('active'));
            document.getElementById(type + '-panel').classList.add('active');
            event.target.classList.add('active');
        }

        // Topic card click - open detail modal
        const topicCards = document.querySelectorAll('.topic-card');
        topicCards.forEach(card => {
            card.addEventListener('click', function(e) {
                if (e.target.closest('.btn') || e.target.closest('a') || e.target.closest('form')) return;
                const topicId = this.getAttribute('data-topic-id');
                openTopicModal(topicId);
            });
        });

        function openTopicModal(topicId) {
            const modalBody = document.getElementById('topicModalBody');
            const modalTitle = document.getElementById('modalTopicTitle');
            modalBody.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-pulse fa-2x"></i><p>Loading...</p></div>';
            openModal('topicModal');

            fetch(`manage-topics_ajax.php?topic_id=${topicId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const topic = data.topic;
                        modalTitle.innerHTML = `<i class="fas fa-bookmark"></i> ${escapeHtml(topic.topic_name)}`;
                        const hasQuestions = (topic.objective_count + topic.subjective_count + topic.theory_count) > 0;
                        modalBody.innerHTML = `
                            <div class="info-row"><div class="info-label">Topic Name:</div><div class="info-value"><strong>${escapeHtml(topic.topic_name)}</strong></div></div>
                            <div class="info-row"><div class="info-label">Subject:</div><div class="info-value">${escapeHtml(topic.subject_name)}</div></div>
                            <div class="info-row"><div class="info-label">Term:</div><div class="info-value">${escapeHtml(topic.term || 'General')}</div></div>
                            <div class="info-row"><div class="info-label">Description:</div><div class="info-value">${escapeHtml(topic.description) || '<span style="color: #999;">No description</span>'}</div></div>
                            <div class="info-row"><div class="info-label">Questions:</div><div class="info-value"><div style="display: flex; flex-wrap: wrap; gap: 8px;"><span class="stat-badge objective">Objective: ${topic.objective_count}</span><span class="stat-badge subjective">Subjective: ${topic.subjective_count}</span><span class="stat-badge theory">Theory: ${topic.theory_count}</span></div></div></div>
                            <div class="modal-action-buttons">
                                <a href="manage-topics.php?view_topic=${topic.id}&subject_id=${topic.subject_id}" class="btn btn-info modal-action-btn"><i class="fas fa-eye"></i> View Questions</a>
                                <button class="btn btn-warning modal-action-btn" onclick="closeModal('topicModal'); editTopic(${topic.id}, '${escapeHtml(topic.topic_name)}', '${escapeHtml(topic.description || '')}')"><i class="fas fa-edit"></i> Edit</button>
                                <a href="manage-questions.php?topic_id=${topic.id}" class="btn btn-success modal-action-btn"><i class="fas fa-plus-circle"></i> Add Questions</a>
                                ${!hasQuestions ? `<button class="btn btn-danger modal-action-btn" onclick="closeModal('topicModal'); confirmDeleteTopic(${topic.id}, '${escapeHtml(topic.topic_name)}')"><i class="fas fa-trash"></i> Delete</button>` : `<button class="btn btn-danger modal-action-btn" disabled style="opacity:0.5;"><i class="fas fa-lock"></i> Delete (Has Qs)</button>`}
                            </div>
                        `;
                    } else {
                        modalBody.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-triangle" style="font-size: 48px; color: var(--danger);"></i><p>${escapeHtml(data.message)}</p><button class="btn btn-outline" onclick="closeModal('topicModal')">Close</button></div>`;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    modalBody.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-triangle" style="font-size: 48px; color: var(--danger);"></i><p>Failed to load topic details.</p><button class="btn btn-outline" onclick="closeModal('topicModal')">Close</button></div>`;
                });
        }

        function editTopic(id, name, description) {
            document.getElementById('edit_topic_id').value = id;
            document.getElementById('edit_topic_name').value = name;
            document.getElementById('edit_description').value = description;
            openModal('editModal');
        }

        function confirmDeleteTopic(id, name) {
            deleteId = id;
            document.getElementById('deleteTopicName').textContent = name;
            openModal('deleteModal');
        }

        const confirmBtn = document.getElementById('confirmDeleteBtn');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', () => {
                if (deleteId) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `<input type="hidden" name="topic_id" value="${deleteId}"><input type="hidden" name="delete_topic" value="1">`;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        function updateTopicCount() {
            const checkboxes = document.querySelectorAll('#central-tab .topic-checkbox:checked');
            const count = checkboxes.length;
            const countSpan = document.getElementById('selectedTopicsCount');
            if (countSpan) {
                countSpan.textContent = count + ' selected';
                countSpan.style.background = count > 0 ? 'var(--success)' : 'var(--primary-color)';
            }
        }

        function selectAllTopics() {
            document.querySelectorAll('#central-tab .topic-checkbox').forEach(cb => cb.checked = true);
            updateTopicCount();
        }

        function deselectAllTopics() {
            document.querySelectorAll('#central-tab .topic-checkbox').forEach(cb => cb.checked = false);
            updateTopicCount();
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        document.addEventListener('DOMContentLoaded', function() {
            updateTopicCount();
        });

        window.onclick = function(event) {
            if (event.target.classList && event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
                document.body.style.overflow = '';
            }
        };

        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>

</html>
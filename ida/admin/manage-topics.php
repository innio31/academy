<?php
// admin/manage-topics.php - Manage Topics with Multi-School Support (Online Version)
// For use with multi-school portal system

session_start();

// Check if admin is logged in (support both session styles)
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /ida/login.php");
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

$message = '';
$message_type = '';

// Get parameters
$subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
$view_topic_id = isset($_GET['view_topic']) ? (int)$_GET['view_topic'] : 0;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Get selected subject details
$selected_subject = null;
if ($subject_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM subjects WHERE id = ? AND school_id = ?");
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add topic
    if (isset($_POST['add_topic'])) {
        $topic_name = trim($_POST['topic_name']);
        $subject_id_post = (int)$_POST['subject_id'];
        $description = trim($_POST['description'] ?? '');

        if (empty($topic_name) || empty($subject_id_post)) {
            $message = "Topic name and subject are required!";
            $message_type = "error";
        } else {
            try {
                // Verify subject belongs to this school
                $stmt = $pdo->prepare("SELECT id FROM subjects WHERE id = ? AND school_id = ?");
                $stmt->execute([$subject_id_post, $school_id]);
                if (!$stmt->fetch()) {
                    throw new Exception("Invalid subject selected");
                }

                // Check for duplicate
                $stmt = $pdo->prepare("SELECT id FROM topics WHERE topic_name = ? AND subject_id = ? AND school_id = ?");
                $stmt->execute([$topic_name, $subject_id_post, $school_id]);
                if ($stmt->fetch()) {
                    throw new Exception("Topic already exists for this subject!");
                }

                $stmt = $pdo->prepare("INSERT INTO topics (topic_name, subject_id, description, school_id) VALUES (?, ?, ?, ?)");
                $stmt->execute([$topic_name, $subject_id_post, $description, $school_id]);

                $message = "Topic added successfully!";
                $message_type = "success";

                // Log activity
                $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, user_type, activity, ip_address) VALUES (?, ?, ?, ?)");
                $log_stmt->execute([$admin_id, 'admin', "Added topic: $topic_name", $_SERVER['REMOTE_ADDR']]);
            } catch (Exception $e) {
                $message = $e->getMessage();
                $message_type = "error";
            }
        }
    }

    // Edit topic
    if (isset($_POST['edit_topic'])) {
        $topic_id_edit = (int)$_POST['edit_topic_id'];
        $topic_name = trim($_POST['edit_topic_name']);
        $description = trim($_POST['edit_description']);
        $subject_id_edit = (int)$_POST['edit_subject_id'];

        if (empty($topic_name)) {
            $message = "Topic name is required!";
            $message_type = "error";
        } else {
            try {
                // Verify topic belongs to this school
                $stmt = $pdo->prepare("SELECT id FROM topics WHERE id = ? AND school_id = ?");
                $stmt->execute([$topic_id_edit, $school_id]);
                if (!$stmt->fetch()) {
                    throw new Exception("Topic not found or access denied");
                }

                // Check duplicate
                $stmt = $pdo->prepare("SELECT id FROM topics WHERE topic_name = ? AND subject_id = ? AND school_id = ? AND id != ?");
                $stmt->execute([$topic_name, $subject_id_edit, $school_id, $topic_id_edit]);
                if ($stmt->fetch()) {
                    throw new Exception("Another topic with this name already exists!");
                }

                $stmt = $pdo->prepare("UPDATE topics SET topic_name = ?, description = ? WHERE id = ? AND school_id = ?");
                $stmt->execute([$topic_name, $description, $topic_id_edit, $school_id]);

                $message = "Topic updated successfully!";
                $message_type = "success";

                $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, user_type, activity, ip_address) VALUES (?, ?, ?, ?)");
                $log_stmt->execute([$admin_id, 'admin', "Updated topic: $topic_name", $_SERVER['REMOTE_ADDR']]);
            } catch (Exception $e) {
                $message = $e->getMessage();
                $message_type = "error";
            }
        }
    }

    // Delete topic
    if (isset($_POST['delete_topic'])) {
        $topic_id_del = (int)$_POST['topic_id'];

        try {
            // Verify topic belongs to this school
            $stmt = $pdo->prepare("SELECT topic_name FROM topics WHERE id = ? AND school_id = ?");
            $stmt->execute([$topic_id_del, $school_id]);
            $topic_info = $stmt->fetch();

            if (!$topic_info) {
                throw new Exception("Topic not found or access denied");
            }

            // Delete associated questions
            $pdo->prepare("DELETE FROM objective_questions WHERE topic_id = ? AND school_id = ?")->execute([$topic_id_del, $school_id]);
            $pdo->prepare("DELETE FROM subjective_questions WHERE topic_id = ? AND school_id = ?")->execute([$topic_id_del, $school_id]);
            $pdo->prepare("DELETE FROM theory_questions WHERE topic_id = ? AND school_id = ?")->execute([$topic_id_del, $school_id]);

            // Delete topic
            $pdo->prepare("DELETE FROM topics WHERE id = ? AND school_id = ?")->execute([$topic_id_del, $school_id]);

            $message = "Topic and all associated questions deleted successfully!";
            $message_type = "success";

            $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, user_type, activity, ip_address) VALUES (?, ?, ?, ?)");
            $log_stmt->execute([$admin_id, 'admin', "Deleted topic: {$topic_info['topic_name']}", $_SERVER['REMOTE_ADDR']]);

            // Redirect if viewing deleted topic
            if ($view_topic_id == $topic_id_del) {
                header("Location: manage-topics.php?subject_id=$subject_id&message=Topic+deleted");
                exit();
            }
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

            header("Location: manage-topics.php?view_topic=$topic_id_q&subject_id=$subject_id");
            exit();
        } catch (Exception $e) {
            $message = $e->getMessage();
            $message_type = "error";
        }
    }
}

// Get all subjects for dropdown
try {
    $stmt = $pdo->prepare("SELECT * FROM subjects WHERE school_id = ? ORDER BY subject_name");
    $stmt->execute([$school_id]);
    $subjects = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error loading subjects: " . $e->getMessage());
    $subjects = [];
}

// Build topics query
$query = "
    SELECT t.*, 
           COALESCE(sc.class, 'N/A') as class,
           (SELECT COUNT(*) FROM objective_questions oq WHERE oq.topic_id = t.id AND oq.school_id = t.school_id) as objective_count,
           (SELECT COUNT(*) FROM subjective_questions sq WHERE sq.topic_id = t.id AND sq.school_id = t.school_id) as subjective_count,
           (SELECT COUNT(*) FROM theory_questions tq WHERE tq.topic_id = t.id AND tq.school_id = t.school_id) as theory_count
    FROM topics t
    LEFT JOIN subject_classes sc ON t.subject_id = sc.subject_id AND sc.school_id = t.school_id
    WHERE t.school_id = ?
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

$query .= " ORDER BY t.topic_name";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $topics = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error loading topics: " . $e->getMessage());
    $topics = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Manage Topics</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: #3498db;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
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
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255, 255, 255, 0.2);
        }

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

        .breadcrumb {
            background: white;
            padding: 12px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
        }

        .form-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--primary-color);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .table-container {
            background: white;
            border-radius: 15px;
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .data-table th {
            background: var(--light-color);
            font-weight: 600;
        }

        .data-table tr:hover {
            background: #f9f9f9;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .badge-primary {
            background: #e3f2fd;
            color: #1976d2;
        }

        .badge-success {
            background: #e8f5e9;
            color: #388e3c;
        }

        .badge-warning {
            background: #fff3e0;
            color: #f57c00;
        }

        .badge-info {
            background: #e3f2fd;
            color: #0288d1;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 8px 15px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.85rem;
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

        .btn-info {
            background: var(--secondary-color);
            color: white;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.75rem;
        }

        .btn-icon {
            padding: 6px 10px;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
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

        .empty-state {
            text-align: center;
            padding: 50px;
            color: #999;
        }

        .filter-section {
            background: #f8f9fa;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
        }

        .search-box {
            position: relative;
            display: inline-block;
        }

        .search-box input {
            padding: 10px 35px 10px 40px;
            border: 2px solid #ddd;
            border-radius: 8px;
            width: 250px;
        }

        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
        }

        .search-clear-btn {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #999;
        }

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
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
        }

        .modal-header,
        .modal-footer {
            padding: 15px 20px;
        }

        .modal-header {
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
        }

        .modal-body {
            padding: 20px;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
        }

        .topic-info-card {
            background: linear-gradient(135deg, var(--primary-color), var(--dark-color));
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
        }

        .question-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 10px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 500;
            color: #666;
            border-radius: 5px;
        }

        .tab-btn.active {
            background: var(--primary-color);
            color: white;
        }

        .question-panel {
            display: none;
        }

        .question-panel.active {
            display: block;
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
            .filter-section {
                flex-direction: column;
                align-items: stretch;
            }

            .search-box input {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>

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
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-students.php"><i class="fas fa-users"></i> Manage Students</a></li>
            <li><a href="manage-staff.php"><i class="fas fa-chalkboard-teacher"></i> Manage Staff</a></li>
            <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Manage Subjects</a></li>
            <li><a href="manage-topics.php" class="active"><i class="fas fa-list"></i> Manage Topics</a></li>
            <li><a href="manage-questions.php"><i class="fas fa-question-circle"></i> Manage Questions</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="attendance.php"><i class="fas fa-calendar-check"></i> Attendance Reports</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="sync.php"><i class="fas fa-sync-alt"></i> Sync to Cloud</a></li>
            <li><a href="/ida/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><?php echo $view_topic ? 'View Questions: ' . htmlspecialchars($view_topic['topic_name']) : 'Manage Topics'; ?></h1>
                <p><?php echo $view_topic ? htmlspecialchars($view_topic['subject_name']) . ' - ' . count($objective_questions) + count($subjective_questions) + count($theory_questions) . ' total questions' : 'Add, edit, and manage topics for subjects'; ?></p>
            </div>
            <button class="logout-btn" onclick="window.location.href='/ida/logout.php'"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>

        <div class="breadcrumb">
            <a href="manage-subjects.php">Subjects</a>
            <?php if ($selected_subject): ?>
                &rsaquo; <a href="manage-topics.php?subject_id=<?php echo $subject_id; ?>"><?php echo htmlspecialchars($selected_subject['subject_name']); ?></a>
            <?php endif; ?>
            <?php if ($view_topic): ?>
                &rsaquo; <a href="manage-topics.php?view_topic=<?php echo $view_topic_id; ?>&subject_id=<?php echo $subject_id; ?>"><?php echo htmlspecialchars($view_topic['topic_name']); ?></a>
            <?php endif; ?>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($view_topic): ?>
            <!-- Questions View Mode -->
            <a href="manage-topics.php?subject_id=<?php echo $subject_id; ?>" class="btn btn-primary" style="margin-bottom: 20px;">
                <i class="fas fa-arrow-left"></i> Back to Topics
            </a>

            <div class="topic-info-card">
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

            <div class="form-container">
                <div class="question-tabs">
                    <button class="tab-btn active" onclick="showQuestionPanel('objective')">
                        <i class="fas fa-check-circle"></i> Objective (<?php echo count($objective_questions); ?>)
                    </button>
                    <button class="tab-btn" onclick="showQuestionPanel('subjective')">
                        <i class="fas fa-edit"></i> Subjective (<?php echo count($subjective_questions); ?>)
                    </button>
                    <button class="tab-btn" onclick="showQuestionPanel('theory')">
                        <i class="fas fa-file-alt"></i> Theory (<?php echo count($theory_questions); ?>)
                    </button>
                </div>

                <!-- Objective Panel -->
                <div id="objective-panel" class="question-panel active">
                    <div class="table-container">
                        <?php if (empty($objective_questions)): ?>
                            <div class="empty-state"><i class="fas fa-check-circle" style="font-size: 48px; color: #ccc;"></i>
                                <h3>No Objective Questions</h3>
                                <p>Click "Add New Question" to get started.</p>
                            </div>
                        <?php else: ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Question</th>
                                        <th>Options</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($objective_questions as $q): ?>
                                        <tr>
                                            <td><?php echo $q['id']; ?></td>
                                            <td><?php echo htmlspecialchars(substr($q['question_text'], 0, 80)) . (strlen($q['question_text']) > 80 ? '...' : ''); ?></td>
                                            <td><span class="badge badge-info">A: <?php echo htmlspecialchars(substr($q['option_a'], 0, 20)); ?></span> <span class="badge badge-success">✓ <?php echo $q['correct_answer']; ?></span></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="manage-questions.php?topic_id=<?php echo $view_topic_id; ?>&type=objective" class="btn btn-primary btn-sm btn-icon" title="Add More"><i class="fas fa-plus"></i></a>
                                                    <form method="POST" onsubmit="return confirm('Delete this question?')" style="display: inline;">
                                                        <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                                        <input type="hidden" name="question_type" value="objective">
                                                        <input type="hidden" name="topic_id" value="<?php echo $view_topic_id; ?>">
                                                        <button type="submit" name="delete_question" class="btn btn-danger btn-sm btn-icon"><i class="fas fa-trash"></i></button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Subjective Panel -->
                <div id="subjective-panel" class="question-panel">
                    <div class="table-container">
                        <?php if (empty($subjective_questions)): ?>
                            <div class="empty-state"><i class="fas fa-edit" style="font-size: 48px; color: #ccc;"></i>
                                <h3>No Subjective Questions</h3>
                            </div>
                        <?php else: ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Question</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($subjective_questions as $q): ?>
                                        <tr>
                                            <td><?php echo $q['id']; ?></td>
                                            <td><?php echo htmlspecialchars(substr($q['question_text'], 0, 80)) . (strlen($q['question_text']) > 80 ? '...' : ''); ?></td>
                                            <td>
                                                <form method="POST" onsubmit="return confirm('Delete this question?')" style="display: inline;">
                                                    <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                                    <input type="hidden" name="question_type" value="subjective">
                                                    <input type="hidden" name="topic_id" value="<?php echo $view_topic_id; ?>">
                                                    <button type="submit" name="delete_question" class="btn btn-danger btn-sm btn-icon"><i class="fas fa-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Theory Panel -->
                <div id="theory-panel" class="question-panel">
                    <div class="table-container">
                        <?php if (empty($theory_questions)): ?>
                            <div class="empty-state"><i class="fas fa-file-alt" style="font-size: 48px; color: #ccc;"></i>
                                <h3>No Theory Questions</h3>
                            </div>
                        <?php else: ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Question</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($theory_questions as $q): ?>
                                        <tr>
                                            <td><?php echo $q['id']; ?></td>
                                            <td><?php echo htmlspecialchars(substr($q['question_text'] ?? '', 0, 80)) . ((strlen($q['question_text'] ?? '') > 80) ? '...' : ''); ?></td>
                                            <td>
                                                <form method="POST" onsubmit="return confirm('Delete this question?')" style="display: inline;">
                                                    <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                                    <input type="hidden" name="question_type" value="theory">
                                                    <input type="hidden" name="topic_id" value="<?php echo $view_topic_id; ?>">
                                                    <button type="submit" name="delete_question" class="btn btn-danger btn-sm btn-icon"><i class="fas fa-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- Topics Management Mode -->

            <?php if ($selected_subject): ?>
                <div class="form-container" style="background: linear-gradient(135deg, var(--primary-color), var(--dark-color)); color: white;">
                    <h2><?php echo htmlspecialchars($selected_subject['subject_name']); ?></h2>
                    <?php if ($selected_subject['description']): ?>
                        <p><?php echo htmlspecialchars($selected_subject['description']); ?></p>
                    <?php endif; ?>
                    <p style="margin-top: 10px;"><i class="fas fa-list"></i> <?php echo count($topics); ?> Topics</p>
                </div>
            <?php endif; ?>

            <!-- Add Topic Form -->
            <div class="form-container">
                <h3 style="margin-bottom: 20px; color: var(--primary-color);"><i class="fas fa-plus"></i> Add New Topic</h3>
                <form method="POST" onsubmit="return validateTopicForm()">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                        <div class="form-group">
                            <label for="topic_name">Topic Name *</label>
                            <input type="text" id="topic_name" name="topic_name" class="form-control" required placeholder="e.g., Algebra, Trigonometry">
                        </div>
                        <div class="form-group">
                            <label for="subject_id">Subject *</label>
                            <select id="subject_id" name="subject_id" class="form-control" required>
                                <option value="">-- Select Subject --</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>" <?php echo ($subject_id == $subject['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="description">Description (Optional)</label>
                        <textarea id="description" name="description" class="form-control" placeholder="Brief description of the topic"></textarea>
                    </div>
                    <button type="submit" name="add_topic" class="btn btn-success"><i class="fas fa-plus"></i> Add Topic</button>
                    <button type="reset" class="btn btn-secondary" style="background: #95a5a6; color: white;">Clear</button>
                </form>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                    <label for="subjectFilter">Filter by Subject:</label>
                    <select id="subjectFilter" class="form-control" style="width: auto; display: inline-block;" onchange="filterTopics()">
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
                        <button class="search-clear-btn" onclick="clearSearch()"><i class="fas fa-times"></i></button>
                    <?php endif; ?>
                </div>
                <?php if ($subject_id || $search_query): ?>
                    <a href="manage-topics.php" class="btn btn-primary btn-sm">Clear Filters</a>
                <?php endif; ?>
            </div>

            <!-- Topics Table -->
            <div class="table-container">
                <?php if (empty($topics)): ?>
                    <div class="empty-state">
                        <i class="fas fa-list" style="font-size: 48px; color: #ccc;"></i>
                        <h3>No Topics Found</h3>
                        <p><?php echo $search_query ? "No topics match '$search_query'" : "Add your first topic using the form above"; ?></p>
                    </div>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <?php if (!$subject_id): ?><th>Subject</th><?php endif; ?>
                                <th>Topic Name</th>
                                <th>Questions</th>
                                <th>Description</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topics as $topic): ?>
                                <tr>
                                    <?php if (!$subject_id): ?>
                                        <td><span class="badge badge-primary"><?php echo htmlspecialchars($topic['subject_name']); ?></span></td>
                                    <?php endif; ?>
                                    <td><strong><?php echo htmlspecialchars($topic['topic_name']); ?></strong></td>
                                    <td>
                                        <span class="badge badge-info">O: <?php echo $topic['objective_count']; ?></span>
                                        <span class="badge badge-warning">S: <?php echo $topic['subjective_count']; ?></span>
                                        <?php if ($topic['theory_count'] > 0): ?>
                                            <span class="badge badge-success">T: <?php echo $topic['theory_count']; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($topic['description'] ?: '—'); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="manage-topics.php?view_topic=<?php echo $topic['id']; ?>&subject_id=<?php echo $topic['subject_id']; ?>" class="btn btn-info btn-sm" title="View Questions"><i class="fas fa-eye"></i> View</a>
                                            <button class="btn btn-primary btn-sm btn-icon" onclick="editTopic(<?php echo $topic['id']; ?>, '<?php echo addslashes($topic['topic_name']); ?>', <?php echo $topic['subject_id']; ?>, '<?php echo addslashes($topic['description'] ?? ''); ?>')" title="Edit"><i class="fas fa-edit"></i></button>
                                            <a href="manage-questions.php?topic_id=<?php echo $topic['id']; ?>" class="btn btn-success btn-sm btn-icon" title="Add Questions"><i class="fas fa-plus-circle"></i></a>
                                            <form method="POST" onsubmit="return confirmDeleteTopic()" style="display: inline;">
                                                <input type="hidden" name="topic_id" value="<?php echo $topic['id']; ?>">
                                                <button type="submit" name="delete_topic" class="btn btn-danger btn-sm btn-icon" title="Delete Topic"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Edit Topic Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Topic</h3>
                <button class="close-modal" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" id="edit_topic_id" name="edit_topic_id">
                    <input type="hidden" id="edit_subject_id" name="edit_subject_id">
                    <div class="form-group">
                        <label>Topic Name *</label>
                        <input type="text" id="edit_topic_name" name="edit_topic_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea id="edit_description" name="edit_description" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px; padding: 15px 20px; border-top: 1px solid #eee;">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" name="edit_topic" class="btn btn-success">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const mobileBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        if (mobileBtn) {
            mobileBtn.onclick = () => sidebar.classList.toggle('active');
        }

        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768 && sidebar && mobileBtn) {
                if (!sidebar.contains(e.target) && !mobileBtn.contains(e.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

        function filterTopics() {
            const subjectFilter = document.getElementById('subjectFilter').value;
            const searchVal = document.getElementById('searchInput')?.value || '';
            let url = 'manage-topics.php?';
            if (subjectFilter) url += `subject_id=${subjectFilter}`;
            if (searchVal) url += `${subjectFilter ? '&' : ''}search=${encodeURIComponent(searchVal)}`;
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

        function editTopic(id, name, subjectId, description) {
            document.getElementById('edit_topic_id').value = id;
            document.getElementById('edit_subject_id').value = subjectId;
            document.getElementById('edit_topic_name').value = name;
            document.getElementById('edit_description').value = description;
            document.getElementById('editModal').classList.add('active');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        function validateTopicForm() {
            const name = document.getElementById('topic_name').value.trim();
            const subject = document.getElementById('subject_id').value;
            if (!name) {
                alert('Please enter a topic name.');
                return false;
            }
            if (!subject) {
                alert('Please select a subject.');
                return false;
            }
            return true;
        }

        function confirmDeleteTopic() {
            return confirm('Are you sure you want to delete this topic and all its questions? This action cannot be undone.');
        }

        function showQuestionPanel(type) {
            document.querySelectorAll('.question-panel').forEach(panel => panel.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.getElementById(type + '-panel').classList.add('active');
            event.target.classList.add('active');
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeEditModal();
        });

        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        document.addEventListener('DOMContentLoaded', () => {
            const subjectFilter = document.getElementById('subjectFilter');
            if (subjectFilter && <?php echo $subject_id ?: 0; ?>) {
                subjectFilter.value = <?php echo $subject_id ?: '""'; ?>;
            }
        });
    </script>
</body>

</html>
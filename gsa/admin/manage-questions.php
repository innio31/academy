<?php
// admin/manage-questions.php - Manage Questions with Subject → Topic Selection
// Topics are based on subject, not classes

session_start();

// Check if admin is logged in (support both session styles)
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /gsa/login.php");
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
$current_tab = $_GET['type'] ?? 'objective';

// Get parameters
$selected_subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
$topic_id = isset($_GET['topic_id']) ? (int)$_GET['topic_id'] : 0;
$selected_topic = null;
$selected_subject = null;

// Get selected subject details
if ($selected_subject_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM subjects WHERE id = ? AND school_id = ?");
        $stmt->execute([$selected_subject_id, $school_id]);
        $selected_subject = $stmt->fetch();
    } catch (Exception $e) {
        $message = "Error loading subject: " . $e->getMessage();
        $message_type = "error";
    }
}

// Get selected topic and details
if ($topic_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT t.*, s.subject_name, s.id as subject_id 
            FROM topics t 
            JOIN subjects s ON t.subject_id = s.id 
            WHERE t.id = ? AND t.school_id = ?
        ");
        $stmt->execute([$topic_id, $school_id]);
        $selected_topic = $stmt->fetch();

        if ($selected_topic) {
            $selected_subject_id = $selected_topic['subject_id'];
            // Reload subject details
            $stmt = $pdo->prepare("SELECT * FROM subjects WHERE id = ? AND school_id = ?");
            $stmt->execute([$selected_subject_id, $school_id]);
            $selected_subject = $stmt->fetch();
        }
    } catch (Exception $e) {
        $message = "Error loading topic: " . $e->getMessage();
        $message_type = "error";
    }
}

// Handle Question Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_question'])) {
    $question_id = (int)$_POST['question_id'];
    $question_type = $_POST['question_type'];

    try {
        $table_name = '';
        $file_column = '';

        switch ($question_type) {
            case 'objective':
                $table_name = 'objective_questions';
                $file_column = 'question_image';
                break;
            case 'subjective':
                $table_name = 'subjective_questions';
                $file_column = '';
                break;
            case 'theory':
                $table_name = 'theory_questions';
                $file_column = 'question_file';
                break;
            default:
                throw new Exception("Invalid question type");
        }

        // Verify ownership and get file path
        $sql = "SELECT $file_column FROM $table_name WHERE id = ? AND school_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$question_id, $school_id]);
        $question = $stmt->fetch();

        if (!$question) {
            throw new Exception("Question not found or access denied");
        }

        // Delete associated file if exists
        if ($file_column && !empty($question[$file_column])) {
            $file_path = '../' . $question[$file_column];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }

        // Delete the question
        $delete_sql = "DELETE FROM $table_name WHERE id = ? AND school_id = ?";
        $stmt = $pdo->prepare($delete_sql);
        $stmt->execute([$question_id, $school_id]);

        // Log activity
        $log_sql = "INSERT INTO activity_logs (user_id, user_type, activity, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)";
        $log_stmt = $pdo->prepare($log_sql);
        $log_stmt->execute([$admin_id, 'admin', "Deleted $question_type question ID: $question_id", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? null]);

        $message = "Question deleted successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = "Error deleting question: " . $e->getMessage();
        $message_type = "error";
    }
}

// Get questions for selected topic
$objective_questions = [];
$subjective_questions = [];
$theory_questions = [];

if ($selected_topic) {
    try {
        // Get objective questions
        $stmt = $pdo->prepare("SELECT * FROM objective_questions WHERE topic_id = ? AND school_id = ? ORDER BY id DESC");
        $stmt->execute([$topic_id, $school_id]);
        $objective_questions = $stmt->fetchAll();

        // Get subjective questions
        $stmt = $pdo->prepare("SELECT * FROM subjective_questions WHERE topic_id = ? AND school_id = ? ORDER BY id DESC");
        $stmt->execute([$topic_id, $school_id]);
        $subjective_questions = $stmt->fetchAll();

        // Get theory questions
        $stmt = $pdo->prepare("SELECT * FROM theory_questions WHERE topic_id = ? AND school_id = ? ORDER BY id DESC");
        $stmt->execute([$topic_id, $school_id]);
        $theory_questions = $stmt->fetchAll();
    } catch (Exception $e) {
        $message = "Error loading questions: " . $e->getMessage();
        $message_type = "error";
    }
}

// Get all subjects for dropdown
try {
    $stmt = $pdo->prepare("
        SELECT s.*, 
               GROUP_CONCAT(DISTINCT sc.class) as assigned_classes,
               (SELECT COUNT(*) FROM topics WHERE subject_id = s.id AND school_id = s.school_id) as topic_count
        FROM subjects s
        LEFT JOIN subject_classes sc ON s.id = sc.subject_id AND sc.school_id = s.school_id
        WHERE s.school_id = ?
        GROUP BY s.id
        ORDER BY s.subject_name
    ");
    $stmt->execute([$school_id]);
    $subjects = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error loading subjects: " . $e->getMessage());
    $subjects = [];
}

// Get topics for selected subject (only if a subject is selected)
$topics = [];
if ($selected_subject_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT t.* 
            FROM topics t
            WHERE t.subject_id = ? AND t.school_id = ?
            ORDER BY 
                CASE 
                    WHEN t.term = 'First' THEN 1
                    WHEN t.term = 'Second' THEN 2
                    WHEN t.term = 'Third' THEN 3
                    ELSE 4
                END,
                t.topic_name
        ");
        $stmt->execute([$selected_subject_id, $school_id]);
        $topics = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error loading topics: " . $e->getMessage());
        $topics = [];
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Manage Questions</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
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
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255, 255, 255, 0.2);
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

        .add-questions-btn {
            background: var(--success-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        /* Breadcrumb */
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

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        /* Form Styles */
        .form-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
        }

        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-color);
        }

        .form-header h3 {
            color: var(--primary-color);
            font-size: 1.3rem;
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

        select.form-control {
            cursor: pointer;
        }

        /* Selection Cards */
        .selection-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .selection-row {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .selection-item {
            flex: 1;
            min-width: 200px;
        }

        /* Tabs */
        .tabs-navigation {
            background: white;
            border-radius: 15px;
            margin-bottom: 30px;
            overflow: hidden;
        }

        .tab-buttons {
            display: flex;
            background: #f8f9fa;
            border-bottom: 2px solid #e0e0e0;
        }

        .tab-button {
            flex: 1;
            padding: 15px 20px;
            border: none;
            background: none;
            font-size: 1rem;
            font-weight: 500;
            color: #666;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s;
        }

        .tab-button:hover {
            background: #e9ecef;
        }

        .tab-button.active {
            color: var(--primary-color);
            border-bottom: 3px solid var(--primary-color);
            background: white;
        }

        .tab-content {
            display: none;
            padding: 25px;
        }

        .tab-content.active {
            display: block;
        }

        /* Table */
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

        .badge-danger {
            background: #ffebee;
            color: #c62828;
        }

        .badge-info {
            background: #e3f2fd;
            color: #0288d1;
        }

        .badge-first {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .badge-second {
            background: #fff3e0;
            color: #ef6c00;
        }

        .badge-third {
            background: #e3f2fd;
            color: #1565c0;
        }

        /* Stats Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stats-card {
            background: white;
            padding: 20px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .stats-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .stats-label {
            color: #666;
            font-size: 0.85rem;
            margin-top: 5px;
        }

        .topic-info-card {
            background: linear-gradient(135deg, var(--primary-color), var(--dark-color));
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
        }

        .topic-meta {
            display: flex;
            gap: 20px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        .meta-item {
            background: rgba(255, 255, 255, 0.1);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
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

        .btn-danger {
            background: var(--danger-color);
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
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
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

        .question-text {
            line-height: 1.5;
            margin-bottom: 5px;
        }

        .option-text {
            display: block;
            font-size: 0.85rem;
            padding: 2px 0;
        }

        .info-note {
            background: #e8f4fd;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #0066cc;
            font-size: 0.9rem;
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
            .tab-buttons {
                flex-direction: column;
            }

            .stats-cards {
                grid-template-columns: 1fr;
            }

            .selection-row {
                flex-direction: column;
            }

            .selection-item {
                width: 100%;
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
                <h1>Manage Questions</h1>
                <p>Create and manage questions for topics</p>
            </div>
            <div class="header-actions" style="display: flex; gap: 10px;">
                <?php if ($selected_topic): ?>
                    <a href="add_questions.php?topic_id=<?php echo $topic_id; ?>" class="add-questions-btn">
                        <i class="fas fa-plus-circle"></i> Add Questions
                    </a>
                <?php endif; ?>
                <button class="logout-btn" onclick="window.location.href='/gsa/logout.php'">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </div>

        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="manage-topics.php">Topics</a>
            <?php if ($selected_subject): ?>
                &rsaquo; <a href="manage-questions.php?subject_id=<?php echo $selected_subject_id; ?>">
                    <?php echo htmlspecialchars($selected_subject['subject_name']); ?>
                </a>
            <?php endif; ?>
            <?php if ($selected_topic): ?>
                &rsaquo; <a href="manage-questions.php?topic_id=<?php echo $topic_id; ?>">
                    <?php echo htmlspecialchars($selected_topic['topic_name']); ?>
                </a>
            <?php endif; ?>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Subject and Topic Selection -->
        <div class="form-container">
            <div class="form-header">
                <h3><i class="fas fa-filter"></i> Select Subject & Topic</h3>
            </div>

            <form method="GET" action="" id="selectionForm">
                <div class="selection-row">
                    <div class="selection-item">
                        <label for="subject_id"><i class="fas fa-book"></i> Select Subject</label>
                        <select id="subject_id" name="subject_id" class="form-control" onchange="this.form.submit()">
                            <option value="">-- Select a subject --</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>"
                                    <?php echo ($selected_subject_id == $subject['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    <?php if ($subject['topic_count'] > 0): ?>
                                        (<?php echo $subject['topic_count']; ?> topics)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="selection-item">
                        <label for="topic_id"><i class="fas fa-list"></i> Select Topic</label>
                        <select id="topic_id" name="topic_id" class="form-control" onchange="this.form.submit()" <?php echo empty($topics) ? 'disabled' : ''; ?>>
                            <option value="">-- Select a topic --</option>
                            <?php foreach ($topics as $topic): ?>
                                <option value="<?php echo $topic['id']; ?>"
                                    <?php echo ($topic_id == $topic['id']) ? 'selected' : ''; ?>>
                                    <?php
                                    $term_icon = '';
                                    switch ($topic['term']) {
                                        case 'First':
                                            $term_icon = '🌱';
                                            break;
                                        case 'Second':
                                            $term_icon = '☀️';
                                            break;
                                        case 'Third':
                                            $term_icon = '❄️';
                                            break;
                                        default:
                                            $term_icon = '📚';
                                    }
                                    echo $term_icon . ' ' . htmlspecialchars($topic['topic_name']);
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($selected_subject_id && empty($topics)): ?>
                            <small style="color: var(--warning-color); display: block; margin-top: 5px;">
                                <i class="fas fa-exclamation-triangle"></i> No topics found for this subject.
                                <a href="manage-topics.php?subject_id=<?php echo $selected_subject_id; ?>">Add topics first</a>
                            </small>
                        <?php endif; ?>
                    </div>

                    <?php if ($selected_topic): ?>
                        <div class="selection-item">
                            <a href="manage-questions.php" class="btn btn-secondary" style="background: #95a5a6; color: white; margin-top: 28px;">
                                <i class="fas fa-times"></i> Clear Selection
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php if ($selected_topic): ?>
            <!-- Topic Info Card -->
            <div class="topic-info-card">
                <h2><i class="fas fa-bookmark"></i> <?php echo htmlspecialchars($selected_topic['topic_name']); ?></h2>
                <p><i class="fas fa-book"></i> Subject: <?php echo htmlspecialchars($selected_topic['subject_name']); ?></p>
                <?php if ($selected_topic['term']): ?>
                    <p><i class="fas fa-calendar-alt"></i> Term:
                        <span class="badge <?php
                                            echo $selected_topic['term'] == 'First' ? 'badge-first' : ($selected_topic['term'] == 'Second' ? 'badge-second' : 'badge-third');
                                            ?>">
                            <?php echo $selected_topic['term']; ?> Term
                        </span>
                    </p>
                <?php endif; ?>
                <?php if ($selected_topic['description']): ?>
                    <p><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($selected_topic['description']); ?></p>
                <?php endif; ?>
                <div class="topic-meta">
                    <span class="meta-item"><i class="fas fa-check-circle"></i> Objective: <?php echo count($objective_questions); ?></span>
                    <span class="meta-item"><i class="fas fa-edit"></i> Subjective: <?php echo count($subjective_questions); ?></span>
                    <span class="meta-item"><i class="fas fa-file-alt"></i> Theory: <?php echo count($theory_questions); ?></span>
                </div>
            </div>

            <!-- Info Note -->
            <div class="info-note">
                <i class="fas fa-info-circle" style="font-size: 1.2rem;"></i>
                <span>Questions are organized by topic. Each topic can have multiple questions of different types. Click "Add Questions" to create new questions for this topic.</span>
            </div>

            <!-- Stats Cards -->
            <div class="stats-cards">
                <div class="stats-card">
                    <div class="stats-value"><?php echo count($objective_questions); ?></div>
                    <div class="stats-label">Objective Questions</div>
                </div>
                <div class="stats-card">
                    <div class="stats-value"><?php echo count($subjective_questions); ?></div>
                    <div class="stats-label">Subjective Questions</div>
                </div>
                <div class="stats-card">
                    <div class="stats-value"><?php echo count($theory_questions); ?></div>
                    <div class="stats-label">Theory Questions</div>
                </div>
                <div class="stats-card">
                    <div class="stats-value"><?php echo count($objective_questions) + count($subjective_questions) + count($theory_questions); ?></div>
                    <div class="stats-label">Total Questions</div>
                </div>
            </div>

            <!-- Tabs Navigation -->
            <div class="tabs-navigation">
                <div class="tab-buttons">
                    <button class="tab-button <?php echo $current_tab === 'objective' ? 'active' : ''; ?>" onclick="switchTab('objective')">
                        <i class="fas fa-check-circle"></i> Objective Questions (<?php echo count($objective_questions); ?>)
                    </button>
                    <button class="tab-button <?php echo $current_tab === 'subjective' ? 'active' : ''; ?>" onclick="switchTab('subjective')">
                        <i class="fas fa-edit"></i> Subjective Questions (<?php echo count($subjective_questions); ?>)
                    </button>
                    <button class="tab-button <?php echo $current_tab === 'theory' ? 'active' : ''; ?>" onclick="switchTab('theory')">
                        <i class="fas fa-file-alt"></i> Theory Questions (<?php echo count($theory_questions); ?>)
                    </button>
                </div>

                <!-- Objective Questions Tab -->
                <div class="tab-content <?php echo $current_tab === 'objective' ? 'active' : ''; ?>" id="objectiveTab">
                    <div style="margin-bottom: 20px; text-align: right;">
                        <a href="add_questions.php?topic_id=<?php echo $topic_id; ?>&type=objective" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add Objective Question
                        </a>
                    </div>

                    <div class="table-container">
                        <?php if (empty($objective_questions)): ?>
                            <div class="empty-state">
                                <i class="fas fa-check-circle" style="font-size: 48px; color: #ccc;"></i>
                                <h3>No Objective Questions</h3>
                                <p>Click the button above to add your first objective question.</p>
                            </div>
                        <?php else: ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th style="width: 50px;">ID</th>
                                        <th>Question</th>
                                        <th style="width: 200px;">Options</th>
                                        <th style="width: 80px;">Correct</th>
                                        <th style="width: 80px;">Marks</th>
                                        <th style="width: 100px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($objective_questions as $q): ?>
                                        <tr>
                                            <td><?php echo $q['id']; ?></td>
                                            <td class="question-text"><?php echo htmlspecialchars(substr($q['question_text'], 0, 80)) . (strlen($q['question_text']) > 80 ? '...' : ''); ?></td>
                                            <td>
                                                <span class="option-text"><strong>A:</strong> <?php echo htmlspecialchars(substr($q['option_a'], 0, 25)); ?></span>
                                                <span class="option-text"><strong>B:</strong> <?php echo htmlspecialchars(substr($q['option_b'], 0, 25)); ?></span>
                                                <span class="option-text"><strong>C:</strong> <?php echo htmlspecialchars(substr($q['option_c'], 0, 25)); ?></span>
                                                <span class="option-text"><strong>D:</strong> <?php echo htmlspecialchars(substr($q['option_d'], 0, 25)); ?></span>
                    </div>
                    <td><span class="badge badge-success"><?php echo $q['correct_answer']; ?></span></td>
                    <td><?php echo $q['marks']; ?></td>
                    <td>
                        <div class="action-buttons">
                            <button class="btn btn-primary btn-sm btn-icon" onclick="viewQuestion(<?php echo $q['id']; ?>, 'objective')" title="View"><i class="fas fa-eye"></i></button>
                            <form method="POST" onsubmit="return confirm('Delete this question?')" style="display: inline;">
                                <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                <input type="hidden" name="question_type" value="objective">
                                <button type="submit" name="delete_question" class="btn btn-danger btn-sm btn-icon" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                </div>
                </tr>
            <?php endforeach; ?>
            </tbody>
            </div>
        <?php endif; ?>
    </div>
    </div>

    <!-- Subjective Questions Tab -->
    <div class="tab-content <?php echo $current_tab === 'subjective' ? 'active' : ''; ?>" id="subjectiveTab">
        <div style="margin-bottom: 20px; text-align: right;">
            <a href="add_questions.php?topic_id=<?php echo $topic_id; ?>&type=subjective" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add Subjective Question
            </a>
        </div>

        <div class="table-container">
            <?php if (empty($subjective_questions)): ?>
                <div class="empty-state">
                    <i class="fas fa-edit" style="font-size: 48px; color: #ccc;"></i>
                    <h3>No Subjective Questions</h3>
                    <p>Click the button above to add your first subjective question.</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">ID</th>
                            <th>Question</th>
                            <th style="width: 200px;">Answer Guide</th>
                            <th style="width: 80px;">Marks</th>
                            <th style="width: 100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subjective_questions as $q): ?>
                            <tr>
                                <td><?php echo $q['id']; ?></td>
                                <td class="question-text"><?php echo htmlspecialchars(substr($q['question_text'], 0, 80)) . (strlen($q['question_text']) > 80 ? '...' : ''); ?></td>
                                <td><?php echo htmlspecialchars(substr($q['correct_answer'] ?? '', 0, 50)) . (strlen($q['correct_answer'] ?? '') > 50 ? '...' : ''); ?></td>
                                <td><?php echo $q['marks']; ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-primary btn-sm btn-icon" onclick="viewQuestion(<?php echo $q['id']; ?>, 'subjective')" title="View"><i class="fas fa-eye"></i></button>
                                        <form method="POST" onsubmit="return confirm('Delete this question?')" style="display: inline;">
                                            <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                            <input type="hidden" name="question_type" value="subjective">
                                            <button type="submit" name="delete_question" class="btn btn-danger btn-sm btn-icon"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
        </div>
        </tr>
    <?php endforeach; ?>
    </tbody>
    </div>
<?php endif; ?>
</div>
</div>

<!-- Theory Questions Tab -->
<div class="tab-content <?php echo $current_tab === 'theory' ? 'active' : ''; ?>" id="theoryTab">
    <div style="margin-bottom: 20px; text-align: right;">
        <a href="add_questions.php?topic_id=<?php echo $topic_id; ?>&type=theory" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add Theory Question
        </a>
    </div>

    <div class="table-container">
        <?php if (empty($theory_questions)): ?>
            <div class="empty-state">
                <i class="fas fa-file-alt" style="font-size: 48px; color: #ccc;"></i>
                <h3>No Theory Questions</h3>
                <p>Click the button above to add your first theory question.</p>
            </div>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 50px;">ID</th>
                        <th>Question</th>
                        <th style="width: 100px;">File</th>
                        <th style="width: 80px;">Marks</th>
                        <th style="width: 100px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($theory_questions as $q): ?>
                        <tr>
                            <td><?php echo $q['id']; ?></td>
                            <td class="question-text"><?php echo htmlspecialchars(substr($q['question_text'] ?? '', 0, 80)) . ((strlen($q['question_text'] ?? '') > 80) ? '...' : ''); ?></td>
                            <td>
                                <?php if ($q['question_file']): ?>
                                    <span class="badge badge-info"><i class="fas fa-file"></i> Attached</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">No file</span>
                                <?php endif; ?>
    </div>
    <td><?php echo $q['marks']; ?></td>
    <td>
        <div class="action-buttons">
            <?php if ($q['question_file']): ?>
                <a href="../<?php echo $q['question_file']; ?>" class="btn btn-primary btn-sm btn-icon" target="_blank" title="View File"><i class="fas fa-eye"></i></a>
            <?php endif; ?>
            <form method="POST" onsubmit="return confirm('Delete this question?')" style="display: inline;">
                <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                <input type="hidden" name="question_type" value="theory">
                <button type="submit" name="delete_question" class="btn btn-danger btn-sm btn-icon"><i class="fas fa-trash"></i></button>
            </form>
        </div>
</div>
</tr>
<?php endforeach; ?>
</tbody>
</div>
<?php endif; ?>
</div>
</div>
</div>
<?php elseif ($selected_subject_id && empty($topics)): ?>
    <!-- No Topics Message -->
    <div class="form-container" style="text-align: center; padding: 50px;">
        <i class="fas fa-list" style="font-size: 64px; color: #ccc; margin-bottom: 20px;"></i>
        <h3>No Topics Found</h3>
        <p>This subject doesn't have any topics yet.</p>
        <a href="manage-topics.php?subject_id=<?php echo $selected_subject_id; ?>" class="btn btn-primary" style="margin-top: 15px;">
            <i class="fas fa-plus"></i> Add Topics to <?php echo htmlspecialchars($selected_subject['subject_name']); ?>
        </a>
    </div>
<?php elseif (!$selected_subject_id): ?>
    <!-- No Selection Message -->
    <div class="form-container" style="text-align: center; padding: 50px;">
        <i class="fas fa-question-circle" style="font-size: 64px; color: #ccc; margin-bottom: 20px;"></i>
        <h3>Select a Subject & Topic</h3>
        <p>Use the dropdown above to select a subject, then choose a topic to manage its questions.</p>
    </div>
<?php endif; ?>
</div>

<!-- View Question Modal -->
<div class="modal" id="viewModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-eye"></i> Question Details</h3>
            <button class="close-modal" onclick="closeViewModal()">&times;</button>
        </div>
        <div class="modal-body" id="viewModalBody">
            <p>Loading...</p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeViewModal()">Close</button>
        </div>
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

    function switchTab(tabName) {
        const url = new URL(window.location);
        url.searchParams.set('type', tabName);
        window.history.pushState({}, '', url);

        document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

        document.querySelector(`.tab-button[onclick="switchTab('${tabName}')"]`).classList.add('active');
        document.getElementById(tabName + 'Tab').classList.add('active');
    }

    function closeViewModal() {
        document.getElementById('viewModal').classList.remove('active');
    }

    async function viewQuestion(id, type) {
        try {
            const response = await fetch(`ajax/get_question.php?id=${id}&type=${type}`);
            const data = await response.json();

            if (data.success) {
                let html = '';
                if (type === 'objective') {
                    const q = data.question;
                    html = `
                            <div style="margin-bottom: 20px;">
                                <h4>Question:</h4>
                                <p style="background: #f8f9fa; padding: 15px; border-radius: 8px;">${escapeHtml(q.question_text)}</p>
                                ${q.question_image ? `<img src="../${q.question_image}" style="max-width: 100%; margin: 10px 0; border-radius: 8px;">` : ''}
                                <h4 style="margin-top: 20px;">Options:</h4>
                                <ul style="list-style: none; padding: 0;">
                                    <li style="padding: 8px; background: ${q.correct_answer === 'A' ? '#e8f5e9' : '#f8f9fa'}; margin: 5px 0; border-radius: 5px;"><strong>A:</strong> ${escapeHtml(q.option_a)} ${q.correct_answer === 'A' ? ' ✓' : ''}</li>
                                    <li style="padding: 8px; background: ${q.correct_answer === 'B' ? '#e8f5e9' : '#f8f9fa'}; margin: 5px 0; border-radius: 5px;"><strong>B:</strong> ${escapeHtml(q.option_b)} ${q.correct_answer === 'B' ? ' ✓' : ''}</li>
                                    <li style="padding: 8px; background: ${q.correct_answer === 'C' ? '#e8f5e9' : '#f8f9fa'}; margin: 5px 0; border-radius: 5px;"><strong>C:</strong> ${escapeHtml(q.option_c)} ${q.correct_answer === 'C' ? ' ✓' : ''}</li>
                                    <li style="padding: 8px; background: ${q.correct_answer === 'D' ? '#e8f5e9' : '#f8f9fa'}; margin: 5px 0; border-radius: 5px;"><strong>D:</strong> ${escapeHtml(q.option_d)} ${q.correct_answer === 'D' ? ' ✓' : ''}</li>
                                </ul>
                                <p><strong>Marks:</strong> ${q.marks} | <strong>Difficulty:</strong> ${q.difficulty_level}</p>
                            </div>
                        `;
                } else if (type === 'subjective') {
                    const q = data.question;
                    html = `
                            <div>
                                <h4>Question:</h4>
                                <p style="background: #f8f9fa; padding: 15px; border-radius: 8px;">${escapeHtml(q.question_text)}</p>
                                <h4>Model Answer:</h4>
                                <div style="background: #e8f5e9; padding: 15px; border-radius: 8px;">${escapeHtml(q.correct_answer || 'No answer guide provided')}</div>
                                <p style="margin-top: 15px;"><strong>Marks:</strong> ${q.marks} | <strong>Difficulty:</strong> ${q.difficulty_level}</p>
                            </div>
                        `;
                } else if (type === 'theory') {
                    const q = data.question;
                    html = `
                            <div>
                                <h4>Question:</h4>
                                <p style="background: #f8f9fa; padding: 15px; border-radius: 8px;">${escapeHtml(q.question_text || 'Question content in file')}</p>
                                ${q.question_file ? `<p><a href="../${q.question_file}" target="_blank" class="btn btn-primary"><i class="fas fa-download"></i> Download Question File</a></p>` : ''}
                                <p><strong>Marks:</strong> ${q.marks} | <strong>Difficulty:</strong> ${q.difficulty_level}</p>
                            </div>
                        `;
                }
                document.getElementById('viewModalBody').innerHTML = html;
                document.getElementById('viewModal').classList.add('active');
            } else {
                alert('Error loading question: ' + data.message);
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Error loading question');
        }
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeViewModal();
        }
    });

    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(alert => {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity 0.5s';
            setTimeout(() => alert.remove(), 500);
        });
    }, 5000);
</script>
</body>

</html>
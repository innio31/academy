<?php
// admin/manage-questions.php - Manage Questions (Online Version)
// For use with multi-school portal system

session_start();

// Check if admin is logged in (support both session styles)
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /gos/login.php");
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

// Get topic ID from URL
$topic_id = isset($_GET['topic_id']) ? (int)$_GET['topic_id'] : 0;
$selected_topic = null;
$selected_subject = null;

// Get selected topic and subject details
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
            // Get the class for this subject from subject_classes table
            $class_stmt = $pdo->prepare("
                SELECT class 
                FROM subject_classes 
                WHERE subject_id = ? AND school_id = ?
                LIMIT 1
            ");
            $class_stmt->execute([$selected_topic['subject_id'], $school_id]);
            $class_row = $class_stmt->fetch();

            $selected_subject = [
                'id' => $selected_topic['subject_id'],
                'subject_name' => $selected_topic['subject_name'],
                'class' => $class_row['class'] ?? 'N/A'
            ];

            $selected_topic['class'] = $selected_subject['class'];
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
        $stmt = $pdo->prepare("SELECT * FROM objective_questions WHERE topic_id = ? AND school_id = ? ORDER BY created_at DESC");
        $stmt->execute([$topic_id, $school_id]);
        $objective_questions = $stmt->fetchAll();

        // Get subjective questions
        $stmt = $pdo->prepare("SELECT * FROM subjective_questions WHERE topic_id = ? AND school_id = ? ORDER BY created_at DESC");
        $stmt->execute([$topic_id, $school_id]);
        $subjective_questions = $stmt->fetchAll();

        // Get theory questions
        $stmt = $pdo->prepare("SELECT * FROM theory_questions WHERE topic_id = ? AND school_id = ? ORDER BY created_at DESC");
        $stmt->execute([$topic_id, $school_id]);
        $theory_questions = $stmt->fetchAll();
    } catch (Exception $e) {
        $message = "Error loading questions: " . $e->getMessage();
        $message_type = "error";
    }
}

// Get all topics for dropdown (with class info)
try {
    $stmt = $pdo->prepare("
        SELECT 
            t.id,
            t.topic_name,
            t.description,
            s.subject_name,
            s.id as subject_id,
            COALESCE(sc.class, 'N/A') as class
        FROM topics t 
        JOIN subjects s ON t.subject_id = s.id 
        LEFT JOIN subject_classes sc ON s.id = sc.subject_id AND sc.school_id = s.school_id
        WHERE t.school_id = ?
        ORDER BY 
            sc.class, 
            s.subject_name, 
            t.topic_name
    ");
    $stmt->execute([$school_id]);
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

        * { margin: 0; padding: 0; box-sizing: border-box; }
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
        .sidebar.active { transform: translateX(0); }

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
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
            margin: 0 15px 20px;
        }
        .nav-links { list-style: none; padding: 0 15px; }
        .nav-links li { margin-bottom: 5px; }
        .nav-links a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            border-radius: 8px;
        }
        .nav-links a:hover, .nav-links a.active { background: rgba(255,255,255,0.2); }

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
        .header-title h1 { color: var(--primary-color); font-size: 1.8rem; margin-bottom: 5px; }
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
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }

        /* Form Styles */
        .form-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
        }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: var(--primary-color); }
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
        }
        .form-control:focus { outline: none; border-color: var(--primary-color); }

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
        .tab-button:hover { background: #e9ecef; }
        .tab-button.active {
            color: var(--primary-color);
            border-bottom: 3px solid var(--primary-color);
            background: white;
        }
        .tab-content { display: none; padding: 25px; }
        .tab-content.active { display: block; }

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
        .data-table th, .data-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .data-table th {
            background: var(--light-color);
            font-weight: 600;
        }
        .data-table tr:hover { background: #f9f9f9; }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .badge-primary { background: #e3f2fd; color: #1976d2; }
        .badge-success { background: #e8f5e9; color: #388e3c; }
        .badge-warning { background: #fff3e0; color: #f57c00; }
        .badge-danger { background: #ffebee; color: #c62828; }
        .badge-info { background: #e3f2fd; color: #0288d1; }
        .badge-easy { background: #e8f5e9; color: #2e7d32; }
        .badge-medium { background: #fff3e0; color: #ef6c00; }
        .badge-hard { background: #ffebee; color: #c62828; }

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
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .stats-value { font-size: 2rem; font-weight: 700; color: var(--primary-color); }
        .stats-label { color: #666; font-size: 0.85rem; margin-top: 5px; }

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
            background: rgba(255,255,255,0.1);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
        }

        .action-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
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
        .btn-primary { background: var(--primary-color); color: white; }
        .btn-danger { background: var(--danger-color); color: white; }
        .btn-sm { padding: 5px 10px; font-size: 0.75rem; }
        .btn-icon { padding: 6px 10px; }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success { background: #d5f4e6; color: #155724; border-left: 4px solid var(--success-color); }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid var(--danger-color); }

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
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal.active { display: flex; }
        .modal-content {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header, .modal-footer { padding: 15px 20px; }
        .modal-header { border-bottom: 1px solid #eee; display: flex; justify-content: space-between; }
        .modal-body { padding: 20px; }
        .close-modal { background: none; border: none; font-size: 1.5rem; cursor: pointer; }

        .question-text { line-height: 1.5; margin-bottom: 5px; }
        .option-text { display: block; font-size: 0.85rem; padding: 2px 0; }

        @media (min-width: 769px) {
            .sidebar { transform: translateX(0); }
            .main-content { margin-left: var(--sidebar-width); }
            .mobile-menu-btn { display: none; }
        }
        @media (max-width: 768px) {
            .tab-buttons { flex-direction: column; }
            .stats-cards { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
            <div class="logo-text"><h3><?php echo htmlspecialchars($school_name); ?></h3><p>Admin Panel</p></div>
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
            <li><a href="manage-topics.php"><i class="fas fa-list"></i> Manage Topics</a></li>
            <li><a href="manage-questions.php" class="active"><i class="fas fa-question-circle"></i> Manage Questions</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="attendance.php"><i class="fas fa-calendar-check"></i> Attendance Reports</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="sync.php"><i class="fas fa-sync-alt"></i> Sync to Cloud</a></li>
            <li><a href="/gos/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1>Manage Questions</h1>
                <p>View and manage questions for topics</p>
            </div>
            <div class="header-actions" style="display: flex; gap: 10px;">
                <?php if ($selected_topic): ?>
                    <a href="add_questions.php?topic_id=<?php echo $topic_id; ?>" class="add-questions-btn">
                        <i class="fas fa-plus-circle"></i> Add Questions
                    </a>
                <?php endif; ?>
                <button class="logout-btn" onclick="window.location.href='/gos/logout.php'">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </div>

        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="manage-topics.php">Topics</a>
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

        <!-- Topic Selection -->
        <div class="form-container">
            <h3 style="margin-bottom: 20px; color: var(--primary-color);">Select Topic</h3>
            <form method="GET" action="">
                <div class="form-group">
                    <label for="topic_select">Choose Topic:</label>
                    <select id="topic_select" name="topic_id" class="form-control" onchange="this.form.submit()">
                        <option value="">-- Select a topic to manage questions --</option>
                        <?php foreach ($topics as $topic): ?>
                            <option value="<?php echo $topic['id']; ?>" <?php echo ($topic_id == $topic['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($topic['subject_name']); ?> (<?php echo htmlspecialchars($topic['class']); ?>) - <?php echo htmlspecialchars($topic['topic_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>

        <?php if ($selected_topic): ?>
            <!-- Topic Info Card -->
            <div class="topic-info-card">
                <h2><i class="fas fa-bookmark"></i> <?php echo htmlspecialchars($selected_topic['topic_name']); ?></h2>
                <p><i class="fas fa-book"></i> Subject: <?php echo htmlspecialchars($selected_topic['subject_name']); ?></p>
                <p><i class="fas fa-graduation-cap"></i> Class: <?php echo htmlspecialchars($selected_topic['class']); ?></p>
                <?php if ($selected_topic['description']): ?>
                    <p><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($selected_topic['description']); ?></p>
                <?php endif; ?>
                <div class="topic-meta">
                    <span class="meta-item"><i class="fas fa-check-circle"></i> Objective: <?php echo count($objective_questions); ?></span>
                    <span class="meta-item"><i class="fas fa-edit"></i> Subjective: <?php echo count($subjective_questions); ?></span>
                    <span class="meta-item"><i class="fas fa-file-alt"></i> Theory: <?php echo count($theory_questions); ?></span>
                </div>
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
                        <i class="fas fa-check-circle"></i> Objective Questions
                    </button>
                    <button class="tab-button <?php echo $current_tab === 'subjective' ? 'active' : ''; ?>" onclick="switchTab('subjective')">
                        <i class="fas fa-edit"></i> Subjective Questions
                    </button>
                    <button class="tab-button <?php echo $current_tab === 'theory' ? 'active' : ''; ?>" onclick="switchTab('theory')">
                        <i class="fas fa-file-alt"></i> Theory Questions
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
                                    <tr><th>ID</th><th>Question</th><th>Options</th><th>Correct</th><th>Marks</th><th>Actions</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($objective_questions as $q): ?>
                                    <tr>
                                        <td><?php echo $q['id']; ?></td>
                                        <td class="question-text"><?php echo htmlspecialchars(substr($q['question_text'], 0, 80)) . (strlen($q['question_text']) > 80 ? '...' : ''); ?></td>
                                        <td>
                                            <span class="option-text"><strong>A:</strong> <?php echo htmlspecialchars(substr($q['option_a'], 0, 30)); ?></span>
                                            <span class="option-text"><strong>B:</strong> <?php echo htmlspecialchars(substr($q['option_b'], 0, 30)); ?></span>
                                            <span class="option-text"><strong>C:</strong> <?php echo htmlspecialchars(substr($q['option_c'], 0, 30)); ?></span>
                                            <span class="option-text"><strong>D:</strong> <?php echo htmlspecialchars(substr($q['option_d'], 0, 30)); ?></span>
                                        </td>
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
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
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
                                <thead><tr><th>ID</th><th>Question</th><th>Answer Guide</th><th>Marks</th><th>Actions</th></tr></thead>
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
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
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
                                <thead><tr><th>ID</th><th>Question</th><th>File</th><th>Marks</th><th>Actions</th></tr></thead>
                                <tbody>
                                    <?php foreach ($theory_questions as $q): ?>
                                    <tr>
                                        <td><?php echo $q['id']; ?></td>
                                        <td class="question-text"><?php echo htmlspecialchars(substr($q['question_text'] ?? '', 0, 80)) . ((strlen($q['question_text'] ?? '') > 80) ? '...' : ''); ?></td>
                                        <td><?php echo $q['question_file'] ? '<span class="badge badge-info"><i class="fas fa-file"></i> Attached</span>' : '<span class="badge badge-warning">No file</span>'; ?></td>
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
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
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
        if(mobileBtn) {
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
                                <p style="background: #f8f9fa; padding: 15px; border-radius: 8px;">${q.question_text}</p>
                                ${q.question_image ? `<img src="../${q.question_image}" style="max-width: 100%; margin: 10px 0; border-radius: 8px;">` : ''}
                                <h4 style="margin-top: 20px;">Options:</h4>
                                <ul style="list-style: none; padding: 0;">
                                    <li style="padding: 8px; background: ${q.correct_answer === 'A' ? '#e8f5e9' : '#f8f9fa'}; margin: 5px 0; border-radius: 5px;"><strong>A:</strong> ${q.option_a} ${q.correct_answer === 'A' ? ' ✓' : ''}</li>
                                    <li style="padding: 8px; background: ${q.correct_answer === 'B' ? '#e8f5e9' : '#f8f9fa'}; margin: 5px 0; border-radius: 5px;"><strong>B:</strong> ${q.option_b} ${q.correct_answer === 'B' ? ' ✓' : ''}</li>
                                    <li style="padding: 8px; background: ${q.correct_answer === 'C' ? '#e8f5e9' : '#f8f9fa'}; margin: 5px 0; border-radius: 5px;"><strong>C:</strong> ${q.option_c} ${q.correct_answer === 'C' ? ' ✓' : ''}</li>
                                    <li style="padding: 8px; background: ${q.correct_answer === 'D' ? '#e8f5e9' : '#f8f9fa'}; margin: 5px 0; border-radius: 5px;"><strong>D:</strong> ${q.option_d} ${q.correct_answer === 'D' ? ' ✓' : ''}</li>
                                </ul>
                                <p><strong>Marks:</strong> ${q.marks} | <strong>Difficulty:</strong> ${q.difficulty_level}</p>
                            </div>
                        `;
                    } else if (type === 'subjective') {
                        const q = data.question;
                        html = `
                            <div>
                                <h4>Question:</h4>
                                <p style="background: #f8f9fa; padding: 15px; border-radius: 8px;">${q.question_text}</p>
                                <h4>Model Answer:</h4>
                                <div style="background: #e8f5e9; padding: 15px; border-radius: 8px;">${q.correct_answer || 'No answer guide provided'}</div>
                                <p style="margin-top: 15px;"><strong>Marks:</strong> ${q.marks} | <strong>Difficulty:</strong> ${q.difficulty_level}</p>
                            </div>
                        `;
                    } else if (type === 'theory') {
                        const q = data.question;
                        html = `
                            <div>
                                <h4>Question:</h4>
                                <p style="background: #f8f9fa; padding: 15px; border-radius: 8px;">${q.question_text || 'Question content in file'}</p>
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
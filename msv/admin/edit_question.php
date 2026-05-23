<?php
// admin/edit_question.php - Edit Questions (Online Version with Multi-School Support)

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

// Get parameters from URL
$question_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$question_type = isset($_GET['type']) ? $_GET['type'] : '';
$topic_id = isset($_GET['topic_id']) ? (int)$_GET['topic_id'] : 0;
$return_topic_id = isset($_GET['return_topic']) ? (int)$_GET['return_topic'] : $topic_id;

$message = '';
$message_type = '';
$question_data = null;
$topics = [];

// Validate required parameters
if (!$question_id || !$question_type || !$topic_id) {
    header("Location: manage-questions.php?error=missing_parameters");
    exit();
}

// Validate question type
if (!in_array($question_type, ['objective', 'subjective', 'theory'])) {
    header("Location: manage-questions.php?error=invalid_type");
    exit();
}

// Get topic and subject information with school isolation
try {
    $topic_stmt = $pdo->prepare("
        SELECT t.*, s.subject_name, s.id as subject_id 
        FROM topics t 
        JOIN subjects s ON t.subject_id = s.id 
        WHERE t.id = ? AND t.school_id = ?
    ");
    $topic_stmt->execute([$topic_id, $school_id]);
    $topic_info = $topic_stmt->fetch();

    if (!$topic_info) {
        header("Location: manage-questions.php?error=topic_not_found");
        exit();
    }
} catch (Exception $e) {
    error_log("Error loading topic: " . $e->getMessage());
    header("Location: manage-questions.php?error=database_error");
    exit();
}

// Get all topics for this subject (for reassigning questions)
try {
    $topics_stmt = $pdo->prepare("
        SELECT id, topic_name 
        FROM topics 
        WHERE subject_id = ? AND school_id = ? 
        ORDER BY topic_name
    ");
    $topics_stmt->execute([$topic_info['subject_id'], $school_id]);
    $topics = $topics_stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error loading topics: " . $e->getMessage());
}

// Fetch question data based on type with school isolation
try {
    if ($question_type == 'objective') {
        $stmt = $pdo->prepare("
            SELECT * FROM objective_questions 
            WHERE id = ? AND school_id = ?
        ");
    } elseif ($question_type == 'subjective') {
        $stmt = $pdo->prepare("
            SELECT * FROM subjective_questions 
            WHERE id = ? AND school_id = ?
        ");
    } elseif ($question_type == 'theory') {
        $stmt = $pdo->prepare("
            SELECT * FROM theory_questions 
            WHERE id = ? AND school_id = ?
        ");
    } else {
        header("Location: manage-questions.php?error=invalid_question_type");
        exit();
    }

    $stmt->execute([$question_id, $school_id]);
    $question_data = $stmt->fetch();

    if (!$question_data) {
        header("Location: manage-questions.php?view_topic=$topic_id&error=question_not_found");
        exit();
    }
} catch (Exception $e) {
    error_log("Error loading question: " . $e->getMessage());
    header("Location: manage-questions.php?error=load_failed");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_question'])) {
    try {
        $pdo->beginTransaction();

        if ($question_type == 'objective') {
            // Update objective question
            $question_text = trim($_POST['question_text'] ?? '');
            $option_a = trim($_POST['option_a'] ?? '');
            $option_b = trim($_POST['option_b'] ?? '');
            $option_c = trim($_POST['option_c'] ?? '');
            $option_d = trim($_POST['option_d'] ?? '');
            $correct_answer = strtoupper(trim($_POST['correct_answer'] ?? ''));
            $marks = (int)($_POST['marks'] ?? 1);
            $difficulty_level = $_POST['difficulty_level'] ?? 'medium';
            $new_topic_id = (int)($_POST['topic_id'] ?? $topic_id);
            $class = trim($_POST['class'] ?? $topic_info['class'] ?? '');

            // Validate required fields
            if (empty($question_text) || empty($option_a) || empty($option_b) || empty($correct_answer)) {
                throw new Exception("Please fill in all required fields");
            }

            if (!in_array($correct_answer, ['A', 'B', 'C', 'D'])) {
                throw new Exception("Correct answer must be A, B, C, or D");
            }

            // Verify new topic belongs to this school
            if ($new_topic_id != $topic_id) {
                $verify_stmt = $pdo->prepare("
                    SELECT id FROM topics WHERE id = ? AND school_id = ?
                ");
                $verify_stmt->execute([$new_topic_id, $school_id]);
                if (!$verify_stmt->fetch()) {
                    throw new Exception("Invalid topic selected");
                }
            }

            // Handle image upload
            $question_image = $question_data['question_image']; // Keep existing image
            if (isset($_FILES['question_image']) && $_FILES['question_image']['error'] == UPLOAD_ERR_OK) {
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $filename = $_FILES['question_image']['name'];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                if (in_array($ext, $allowed)) {
                    $upload_dir = '../uploads/questions/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }

                    $new_filename = 'question_' . time() . '_' . $question_id . '.' . $ext;
                    $upload_path = $upload_dir . $new_filename;

                    if (move_uploaded_file($_FILES['question_image']['tmp_name'], $upload_path)) {
                        // Delete old image if exists
                        if ($question_image && file_exists('../' . $question_image)) {
                            unlink('../' . $question_image);
                        }
                        $question_image = 'uploads/questions/' . $new_filename;
                    }
                }
            }

            // Remove image if checkbox checked
            if (isset($_POST['remove_image']) && $_POST['remove_image'] == '1') {
                if ($question_image && file_exists('../' . $question_image)) {
                    unlink('../' . $question_image);
                }
                $question_image = null;
            }

            $update_stmt = $pdo->prepare("
                UPDATE objective_questions SET
                    question_text = ?,
                    option_a = ?,
                    option_b = ?,
                    option_c = ?,
                    option_d = ?,
                    correct_answer = ?,
                    marks = ?,
                    difficulty_level = ?,
                    topic_id = ?,
                    class = ?,
                    question_image = ?
                WHERE id = ? AND school_id = ?
            ");

            $update_stmt->execute([
                $question_text,
                $option_a,
                $option_b,
                $option_c,
                $option_d,
                $correct_answer,
                $marks,
                $difficulty_level,
                $new_topic_id,
                $class,
                $question_image,
                $question_id,
                $school_id
            ]);
        } elseif ($question_type == 'subjective') {
            // Update subjective question
            $question_text = trim($_POST['question_text'] ?? '');
            $correct_answer = trim($_POST['correct_answer'] ?? '');
            $marks = (int)($_POST['marks'] ?? 1);
            $difficulty_level = $_POST['difficulty_level'] ?? 'medium';
            $new_topic_id = (int)($_POST['topic_id'] ?? $topic_id);
            $class = trim($_POST['class'] ?? $topic_info['class'] ?? '');

            if (empty($question_text)) {
                throw new Exception("Question text is required");
            }

            // Verify new topic belongs to this school
            if ($new_topic_id != $topic_id) {
                $verify_stmt = $pdo->prepare("
                    SELECT id FROM topics WHERE id = ? AND school_id = ?
                ");
                $verify_stmt->execute([$new_topic_id, $school_id]);
                if (!$verify_stmt->fetch()) {
                    throw new Exception("Invalid topic selected");
                }
            }

            $update_stmt = $pdo->prepare("
                UPDATE subjective_questions SET
                    question_text = ?,
                    correct_answer = ?,
                    marks = ?,
                    difficulty_level = ?,
                    topic_id = ?,
                    class = ?
                WHERE id = ? AND school_id = ?
            ");

            $update_stmt->execute([
                $question_text,
                $correct_answer,
                $marks,
                $difficulty_level,
                $new_topic_id,
                $class,
                $question_id,
                $school_id
            ]);
        } elseif ($question_type == 'theory') {
            // Update theory question
            $question_text = trim($_POST['question_text'] ?? '');
            $marks = (int)($_POST['marks'] ?? 5);
            $difficulty_level = $_POST['difficulty_level'] ?? 'medium';
            $new_topic_id = (int)($_POST['topic_id'] ?? $topic_id);
            $class = trim($_POST['class'] ?? $topic_info['class'] ?? '');

            if (empty($question_text)) {
                throw new Exception("Question text is required");
            }

            // Verify new topic belongs to this school
            if ($new_topic_id != $topic_id) {
                $verify_stmt = $pdo->prepare("
                    SELECT id FROM topics WHERE id = ? AND school_id = ?
                ");
                $verify_stmt->execute([$new_topic_id, $school_id]);
                if (!$verify_stmt->fetch()) {
                    throw new Exception("Invalid topic selected");
                }
            }

            // Handle file upload
            $question_file = $question_data['question_file']; // Keep existing file
            if (isset($_FILES['question_file']) && $_FILES['question_file']['error'] == UPLOAD_ERR_OK) {
                $allowed = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png'];
                $filename = $_FILES['question_file']['name'];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                if (in_array($ext, $allowed)) {
                    $upload_dir = '../uploads/theory/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }

                    $new_filename = 'theory_' . time() . '_' . $question_id . '.' . $ext;
                    $upload_path = $upload_dir . $new_filename;

                    if (move_uploaded_file($_FILES['question_file']['tmp_name'], $upload_path)) {
                        // Delete old file if exists
                        if ($question_file && file_exists('../' . $question_file)) {
                            unlink('../' . $question_file);
                        }
                        $question_file = 'uploads/theory/' . $new_filename;
                    }
                }
            }

            // Remove file if checkbox checked
            if (isset($_POST['remove_file']) && $_POST['remove_file'] == '1') {
                if ($question_file && file_exists('../' . $question_file)) {
                    unlink('../' . $question_file);
                }
                $question_file = null;
            }

            $update_stmt = $pdo->prepare("
                UPDATE theory_questions SET
                    question_text = ?,
                    question_file = ?,
                    marks = ?,
                    topic_id = ?,
                    class = ?
                WHERE id = ? AND school_id = ?
            ");

            $update_stmt->execute([
                $question_text,
                $question_file,
                $marks,
                $new_topic_id,
                $class,
                $question_id,
                $school_id
            ]);
        }

        // Log activity
        $log_stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, user_type, activity, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $log_stmt->execute([
            $admin_id,
            'admin',
            "Updated {$question_type} question ID: {$question_id}",
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);

        $pdo->commit();

        $message = "Question updated successfully!";
        $message_type = "success";

        // Refresh question data
        $stmt->execute([$question_id, $school_id]);
        $question_data = $stmt->fetch();

        // If topic changed, update return_topic_id
        if ($new_topic_id != $topic_id) {
            $return_topic_id = $new_topic_id;
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Error updating question: " . $e->getMessage();
        $message_type = "error";
    }
}

// Get class name for display
$class_name = $topic_info['class'] ?? $question_data['class'] ?? 'N/A';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Edit <?php echo ucfirst($question_type); ?> Question</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- TinyMCE Rich Text Editor -->
    <script src="https://cdn.tiny.cloud/1/jjo6cr24xrrberxg1cezfwb80fq1xhkghq4pyu9eudbhg87j/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>

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

        .header-title p {
            color: #666;
            font-size: 0.9rem;
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

        /* Content Card */
        .content-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-color);
            flex-wrap: wrap;
            gap: 15px;
        }

        .card-header h2 {
            color: var(--primary-color);
            font-size: 1.5rem;
        }

        /* Form Styles */
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
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            font-family: 'Poppins', sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .options-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .option-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }

        .option-item label {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            cursor: pointer;
        }

        .option-item input[type="radio"] {
            width: auto;
            margin: 0;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 0.9rem;
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

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
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

        /* Preview Section */
        .preview-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
        }

        .preview-section h3 {
            color: var(--primary-color);
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--light-color);
        }

        .question-preview {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid var(--primary-color);
        }

        /* File Upload */
        .file-upload {
            border: 2px dashed #ddd;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
        }

        .file-upload:hover {
            border-color: var(--primary-color);
            background: #f8f9fa;
        }

        .file-upload input[type="file"] {
            display: none;
        }

        .file-upload i {
            font-size: 1.5rem;
            color: #999;
            margin-bottom: 8px;
        }

        .current-file {
            margin-top: 10px;
            padding: 10px;
            background: #e8f5e9;
            border-radius: 6px;
            font-size: 0.85rem;
        }

        .current-file a {
            color: var(--primary-color);
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-top: 10px;
        }

        .checkbox-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: normal;
            cursor: pointer;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .badge-info {
            background: #e3f2fd;
            color: #1976d2;
        }

        .badge-success {
            background: #e8f5e9;
            color: #388e3c;
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
            .options-grid {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
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
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-students.php"><i class="fas fa-users"></i> Manage Students</a></li>
            <li><a href="manage-staff.php"><i class="fas fa-chalkboard-teacher"></i> Manage Staff</a></li>
            <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Manage Subjects</a></li>
            <li><a href="manage-topics.php"><i class="fas fa-list"></i> Manage Topics</a></li>
            <li><a href="manage-questions.php"><i class="fas fa-question-circle"></i> Manage Questions</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="attendance.php"><i class="fas fa-calendar-check"></i> Attendance Reports</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="sync.php"><i class="fas fa-sync-alt"></i> Sync to Cloud</a></li>
            <li><a href="../msv/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1>Edit <?php echo ucfirst($question_type); ?> Question</h1>
                <p><?php echo htmlspecialchars($topic_info['topic_name']); ?> - <?php echo htmlspecialchars($topic_info['subject_name']); ?></p>
            </div>
            <button class="logout-btn" onclick="window.location.href='../msv/logout.php'">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </div>

        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="manage-subjects.php">Subjects</a> &rsaquo;
            <a href="manage-topics.php?subject_id=<?php echo $topic_info['subject_id']; ?>">
                <?php echo htmlspecialchars($topic_info['subject_name']); ?>
            </a> &rsaquo;
            <a href="manage-questions.php?topic_id=<?php echo $return_topic_id; ?>">
                <?php echo htmlspecialchars($topic_info['topic_name']); ?>
            </a> &rsaquo;
            <span>Edit Question</span>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Edit Form -->
        <div class="content-card">
            <div class="card-header">
                <h2><i class="fas fa-edit"></i> Edit Question Details</h2>
                <a href="manage-questions.php?topic_id=<?php echo $return_topic_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Questions
                </a>
            </div>

            <form method="POST" action="" enctype="multipart/form-data">
                <?php if ($question_type == 'objective'): ?>
                    <!-- Objective Question Form -->
                    <div class="form-group">
                        <label>Question Text *</label>
                        <textarea name="question_text" class="form-control tinymce-objective" rows="5" required><?php echo htmlspecialchars($question_data['question_text']); ?></textarea>
                    </div>

                    <div class="options-grid">
                        <div class="option-item">
                            <label>
                                <input type="radio" name="correct_answer" value="A" <?php echo $question_data['correct_answer'] == 'A' ? 'checked' : ''; ?> required>
                                <strong>Option A *</strong>
                            </label>
                            <textarea name="option_a" class="form-control tinymce-small" rows="2" required><?php echo htmlspecialchars($question_data['option_a']); ?></textarea>
                        </div>
                        <div class="option-item">
                            <label>
                                <input type="radio" name="correct_answer" value="B" <?php echo $question_data['correct_answer'] == 'B' ? 'checked' : ''; ?> required>
                                <strong>Option B *</strong>
                            </label>
                            <textarea name="option_b" class="form-control tinymce-small" rows="2" required><?php echo htmlspecialchars($question_data['option_b']); ?></textarea>
                        </div>
                        <div class="option-item">
                            <label>
                                <input type="radio" name="correct_answer" value="C" <?php echo $question_data['correct_answer'] == 'C' ? 'checked' : ''; ?> required>
                                <strong>Option C</strong>
                            </label>
                            <textarea name="option_c" class="form-control tinymce-small" rows="2"><?php echo htmlspecialchars($question_data['option_c']); ?></textarea>
                        </div>
                        <div class="option-item">
                            <label>
                                <input type="radio" name="correct_answer" value="D" <?php echo $question_data['correct_answer'] == 'D' ? 'checked' : ''; ?> required>
                                <strong>Option D</strong>
                            </label>
                            <textarea name="option_d" class="form-control tinymce-small" rows="2"><?php echo htmlspecialchars($question_data['option_d']); ?></textarea>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Topic *</label>
                            <select name="topic_id" class="form-control" required>
                                <?php foreach ($topics as $topic): ?>
                                    <option value="<?php echo $topic['id']; ?>"
                                        <?php echo ($topic['id'] == $question_data['topic_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($topic['topic_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Marks *</label>
                            <input type="number" name="marks" class="form-control" value="<?php echo $question_data['marks']; ?>" min="1" required>
                        </div>

                        <div class="form-group">
                            <label>Difficulty Level</label>
                            <select name="difficulty_level" class="form-control">
                                <option value="easy" <?php echo ($question_data['difficulty_level'] ?? 'medium') == 'easy' ? 'selected' : ''; ?>>Easy</option>
                                <option value="medium" <?php echo ($question_data['difficulty_level'] ?? 'medium') == 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="hard" <?php echo ($question_data['difficulty_level'] ?? 'medium') == 'hard' ? 'selected' : ''; ?>>Hard</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Class</label>
                            <input type="text" name="class" class="form-control" value="<?php echo htmlspecialchars($question_data['class'] ?? $class_name); ?>" placeholder="e.g., SS1, JSS2">
                        </div>
                    </div>

                    <!-- Image Upload -->
                    <div class="form-group">
                        <label>Question Image (Optional)</label>
                        <div class="file-upload" onclick="document.getElementById('question_image').click()">
                            <i class="fas fa-image"></i>
                            <p>Click to upload or change image</p>
                            <input type="file" id="question_image" name="question_image" accept="image/*" style="display: none;">
                            <span id="image_name" class="file-info" style="display: block; margin-top: 8px;"></span>
                        </div>
                        <?php if (!empty($question_data['question_image'])): ?>
                            <div class="current-file">
                                <i class="fas fa-image"></i> Current image:
                                <a href="../<?php echo $question_data['question_image']; ?>" target="_blank">View</a>
                                <div class="checkbox-group" style="margin-top: 8px;">
                                    <label>
                                        <input type="checkbox" name="remove_image" value="1">
                                        Remove current image
                                    </label>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($question_type == 'subjective'): ?>
                    <!-- Subjective Question Form -->
                    <div class="form-group">
                        <label>Question Text *</label>
                        <textarea name="question_text" class="form-control tinymce-subjective" rows="5" required><?php echo htmlspecialchars($question_data['question_text']); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>Model Answer / Answer Guide</label>
                        <textarea name="correct_answer" class="form-control tinymce-answer" rows="4"><?php echo htmlspecialchars($question_data['correct_answer'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Topic *</label>
                            <select name="topic_id" class="form-control" required>
                                <?php foreach ($topics as $topic): ?>
                                    <option value="<?php echo $topic['id']; ?>"
                                        <?php echo ($topic['id'] == $question_data['topic_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($topic['topic_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Marks *</label>
                            <input type="number" name="marks" class="form-control" value="<?php echo $question_data['marks']; ?>" min="1" required>
                        </div>

                        <div class="form-group">
                            <label>Difficulty Level</label>
                            <select name="difficulty_level" class="form-control">
                                <option value="easy" <?php echo ($question_data['difficulty_level'] ?? 'medium') == 'easy' ? 'selected' : ''; ?>>Easy</option>
                                <option value="medium" <?php echo ($question_data['difficulty_level'] ?? 'medium') == 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="hard" <?php echo ($question_data['difficulty_level'] ?? 'medium') == 'hard' ? 'selected' : ''; ?>>Hard</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Class</label>
                            <input type="text" name="class" class="form-control" value="<?php echo htmlspecialchars($question_data['class'] ?? $class_name); ?>" placeholder="e.g., SS1, JSS2">
                        </div>
                    </div>

                <?php elseif ($question_type == 'theory'): ?>
                    <!-- Theory Question Form -->
                    <div class="form-group">
                        <label>Question Text *</label>
                        <textarea name="question_text" class="form-control tinymce-theory" rows="5" required><?php echo htmlspecialchars($question_data['question_text'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Topic *</label>
                            <select name="topic_id" class="form-control" required>
                                <?php foreach ($topics as $topic): ?>
                                    <option value="<?php echo $topic['id']; ?>"
                                        <?php echo ($topic['id'] == $question_data['topic_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($topic['topic_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Marks *</label>
                            <input type="number" name="marks" class="form-control" value="<?php echo $question_data['marks']; ?>" min="1" required>
                        </div>

                        <div class="form-group">
                            <label>Class</label>
                            <input type="text" name="class" class="form-control" value="<?php echo htmlspecialchars($question_data['class'] ?? $class_name); ?>" placeholder="e.g., SS1, JSS2">
                        </div>
                    </div>

                    <!-- File Upload -->
                    <div class="form-group">
                        <label>Attach File (Optional)</label>
                        <div class="file-upload" onclick="document.getElementById('question_file').click()">
                            <i class="fas fa-file-pdf"></i>
                            <p>Click to upload or change file (PDF, DOC, DOCX, TXT, Image)</p>
                            <input type="file" id="question_file" name="question_file" accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png" style="display: none;">
                            <span id="file_name" class="file-info" style="display: block; margin-top: 8px;"></span>
                        </div>
                        <?php if (!empty($question_data['question_file'])): ?>
                            <div class="current-file">
                                <i class="fas fa-file"></i> Current file:
                                <a href="../<?php echo $question_data['question_file']; ?>" target="_blank">View</a>
                                <div class="checkbox-group" style="margin-top: 8px;">
                                    <label>
                                        <input type="checkbox" name="remove_file" value="1">
                                        Remove current file
                                    </label>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="form-actions">
                    <a href="manage-questions.php?topic_id=<?php echo $return_topic_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" name="update_question" class="btn btn-success">
                        <i class="fas fa-save"></i> Update Question
                    </button>
                </div>
            </form>
        </div>

        <!-- Preview Section -->
        <div class="preview-section">
            <h3><i class="fas fa-eye"></i> Current Question Preview</h3>
            <div class="question-preview">
                <?php if ($question_type == 'objective'): ?>
                    <div style="margin-bottom: 15px;">
                        <strong>Question:</strong><br>
                        <?php echo nl2br(htmlspecialchars($question_data['question_text'])); ?>
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 15px;">
                        <div class="<?php echo $question_data['correct_answer'] == 'A' ? 'badge-success' : ''; ?>" style="padding: 8px; background: <?php echo $question_data['correct_answer'] == 'A' ? '#e8f5e9' : '#f8f9fa'; ?>; border-radius: 6px;">
                            <strong>A.</strong> <?php echo htmlspecialchars(substr($question_data['option_a'], 0, 100)); ?>
                        </div>
                        <div class="<?php echo $question_data['correct_answer'] == 'B' ? 'badge-success' : ''; ?>" style="padding: 8px; background: <?php echo $question_data['correct_answer'] == 'B' ? '#e8f5e9' : '#f8f9fa'; ?>; border-radius: 6px;">
                            <strong>B.</strong> <?php echo htmlspecialchars(substr($question_data['option_b'], 0, 100)); ?>
                        </div>
                        <div class="<?php echo $question_data['correct_answer'] == 'C' ? 'badge-success' : ''; ?>" style="padding: 8px; background: <?php echo $question_data['correct_answer'] == 'C' ? '#e8f5e9' : '#f8f9fa'; ?>; border-radius: 6px;">
                            <strong>C.</strong> <?php echo htmlspecialchars(substr($question_data['option_c'], 0, 100)); ?>
                        </div>
                        <div class="<?php echo $question_data['correct_answer'] == 'D' ? 'badge-success' : ''; ?>" style="padding: 8px; background: <?php echo $question_data['correct_answer'] == 'D' ? '#e8f5e9' : '#f8f9fa'; ?>; border-radius: 6px;">
                            <strong>D.</strong> <?php echo htmlspecialchars(substr($question_data['option_d'], 0, 100)); ?>
                        </div>
                    </div>
                    <div>
                        <span class="badge badge-info">Correct: <?php echo $question_data['correct_answer']; ?></span>
                        <span class="badge badge-info">Marks: <?php echo $question_data['marks']; ?></span>
                        <span class="badge badge-info">Difficulty: <?php echo ucfirst($question_data['difficulty_level'] ?? 'medium'); ?></span>
                    </div>
                <?php elseif ($question_type == 'subjective'): ?>
                    <div style="margin-bottom: 15px;">
                        <strong>Question:</strong><br>
                        <?php echo nl2br(htmlspecialchars($question_data['question_text'])); ?>
                    </div>
                    <div style="margin-bottom: 15px; background: #e8f5e9; padding: 12px; border-radius: 6px;">
                        <strong>Answer Guide:</strong><br>
                        <?php echo nl2br(htmlspecialchars($question_data['correct_answer'] ?? 'No answer guide provided.')); ?>
                    </div>
                    <div>
                        <span class="badge badge-info">Marks: <?php echo $question_data['marks']; ?></span>
                        <span class="badge badge-info">Difficulty: <?php echo ucfirst($question_data['difficulty_level'] ?? 'medium'); ?></span>
                    </div>
                <?php elseif ($question_type == 'theory'): ?>
                    <div style="margin-bottom: 15px;">
                        <strong>Question:</strong><br>
                        <?php echo nl2br(htmlspecialchars($question_data['question_text'] ?? '')); ?>
                    </div>
                    <?php if (!empty($question_data['question_file'])): ?>
                        <div style="margin-bottom: 15px;">
                            <i class="fas fa-file"></i>
                            <a href="../<?php echo $question_data['question_file']; ?>" target="_blank">View attached file</a>
                        </div>
                    <?php endif; ?>
                    <div>
                        <span class="badge badge-info">Marks: <?php echo $question_data['marks']; ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu toggle
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

        // File name display
        function setupFileInput(inputId, displayId) {
            const input = document.getElementById(inputId);
            if (input) {
                input.addEventListener('change', function() {
                    const display = document.getElementById(displayId);
                    if (this.files && this.files[0]) {
                        display.innerHTML = `<i class="fas fa-check-circle" style="color: var(--success-color);"></i> ${this.files[0].name}`;
                    } else {
                        display.innerHTML = '';
                    }
                });
            }
        }

        setupFileInput('question_image', 'image_name');
        setupFileInput('question_file', 'file_name');

        // Initialize TinyMCE
        tinymce.init({
            selector: '.tinymce-objective, .tinymce-subjective, .tinymce-theory, .tinymce-answer, .tinymce-small',
            height: 200,
            menubar: false,
            plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen insertdatetime media table help wordcount',
            toolbar: 'undo redo | blocks | bold italic | alignleft aligncenter alignright | bullist numlist | removeformat',
            content_style: 'body { font-family:Helvetica,Arial,sans-serif; font-size:14px }'
        });

        // For smaller textareas (options)
        tinymce.init({
            selector: '.tinymce-small',
            height: 80,
            menubar: false,
            plugins: 'advlist autolink lists link charmap preview anchor searchreplace visualblocks code',
            toolbar: 'bold italic | bullist numlist',
            content_style: 'body { font-family:Helvetica,Arial,sans-serif; font-size:13px }'
        });

        // Auto-hide alert after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Confirm before leaving with unsaved changes
        let formChanged = false;
        const form = document.querySelector('form');

        if (form) {
            form.querySelectorAll('input, textarea, select').forEach(element => {
                element.addEventListener('change', () => {
                    formChanged = true;
                });
                element.addEventListener('input', () => {
                    formChanged = true;
                });
            });
        }

        window.addEventListener('beforeunload', (e) => {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            }
        });

        form?.addEventListener('submit', () => {
            formChanged = false;
        });
    </script>
</body>

</html>
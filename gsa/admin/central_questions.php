<?php
// admin/central_questions.php - Developer Panel for Managing Central Question Bank
// ONLY accessible to super_admin users

session_start();

// Check if admin is logged in
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

// ONLY super_admin can access central question bank
if ($admin_role !== 'super_admin') {
    header("Location: index.php?message=Access+denied&type=error");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;

$message = '';
$message_type = '';
$active_tab = $_GET['tab'] ?? 'objective';

// ============================================
// HANDLE FORM SUBMISSIONS
// ============================================

// Add Objective Question to Central Bank
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_central_objective'])) {
    try {
        $question_text = trim($_POST['question_text'] ?? '');
        $option_a = trim($_POST['option_a'] ?? '');
        $option_b = trim($_POST['option_b'] ?? '');
        $option_c = trim($_POST['option_c'] ?? '');
        $option_d = trim($_POST['option_d'] ?? '');
        $correct_answer = strtoupper(trim($_POST['correct_answer'] ?? ''));
        $difficulty_level = $_POST['difficulty_level'] ?? 'medium';
        $marks = (int)($_POST['marks'] ?? 1);
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $topic_id = (int)($_POST['topic_id'] ?? 0);

        if (empty($question_text) || empty($option_a) || empty($option_b) || empty($correct_answer)) {
            throw new Exception("Please fill in all required fields");
        }

        if ($subject_id <= 0) {
            throw new Exception("Please select a subject");
        }

        // Handle image upload
        $question_image = null;
        if (isset($_FILES['question_image']) && $_FILES['question_image']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $ext = strtolower(pathinfo($_FILES['question_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $upload_dir = '../uploads/central_questions/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                $new_filename = 'central_q_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                if (move_uploaded_file($_FILES['question_image']['tmp_name'], $upload_dir . $new_filename)) {
                    $question_image = 'uploads/central_questions/' . $new_filename;
                }
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO objective_questions 
            (question_text, option_a, option_b, option_c, option_d, correct_answer, 
             difficulty_level, marks, subject_id, topic_id, question_image, 
             is_central, school_id, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NULL, NOW())
        ");
        $stmt->execute([
            $question_text,
            $option_a,
            $option_b,
            $option_c,
            $option_d,
            $correct_answer,
            $difficulty_level,
            $marks,
            $subject_id,
            $topic_id,
            $question_image
        ]);

        $message = "Central objective question added successfully!";
        $message_type = "success";

        if (!isset($_POST['stay_here'])) {
            header("Location: central_questions.php?tab=objective&success=1");
            exit();
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Add Subjective Question
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_central_subjective'])) {
    try {
        $question_text = trim($_POST['question_text'] ?? '');
        $correct_answer = trim($_POST['correct_answer'] ?? '');
        $difficulty_level = $_POST['difficulty_level'] ?? 'medium';
        $marks = (int)($_POST['marks'] ?? 1);
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $topic_id = (int)($_POST['topic_id'] ?? 0);

        if (empty($question_text) || $subject_id <= 0) {
            throw new Exception("Please fill in all required fields");
        }

        $stmt = $pdo->prepare("
            INSERT INTO subjective_questions 
            (question_text, correct_answer, difficulty_level, marks, subject_id, topic_id, 
             is_central, school_id, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, 1, NULL, NOW())
        ");
        $stmt->execute([
            $question_text,
            $correct_answer,
            $difficulty_level,
            $marks,
            $subject_id,
            $topic_id
        ]);

        $message = "Central subjective question added successfully!";
        $message_type = "success";

        if (!isset($_POST['stay_here'])) {
            header("Location: central_questions.php?tab=subjective&success=1");
            exit();
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Add Theory Question
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_central_theory'])) {
    try {
        $question_text = trim($_POST['question_text'] ?? '');
        $marks = (int)($_POST['marks'] ?? 5);
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $topic_id = (int)($_POST['topic_id'] ?? 0);

        if (empty($question_text) || $subject_id <= 0) {
            throw new Exception("Please fill in all required fields");
        }

        // Handle file upload
        $question_file = null;
        if (isset($_FILES['question_file']) && $_FILES['question_file']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png'];
            $ext = strtolower(pathinfo($_FILES['question_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $upload_dir = '../uploads/central_theory/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                $new_filename = 'central_theory_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                if (move_uploaded_file($_FILES['question_file']['tmp_name'], $upload_dir . $new_filename)) {
                    $question_file = 'uploads/central_theory/' . $new_filename;
                }
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO theory_questions 
            (question_text, question_file, marks, subject_id, topic_id, 
             is_central, school_id, created_at) 
            VALUES (?, ?, ?, ?, ?, 1, NULL, NOW())
        ");
        $stmt->execute([
            $question_text,
            $question_file,
            $marks,
            $subject_id,
            $topic_id
        ]);

        $message = "Central theory question added successfully!";
        $message_type = "success";

        if (!isset($_POST['stay_here'])) {
            header("Location: central_questions.php?tab=theory&success=1");
            exit();
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Delete Central Question
if (isset($_GET['delete']) && isset($_GET['type']) && isset($_GET['id'])) {
    $q_type = $_GET['type'];
    $q_id = (int)$_GET['id'];

    try {
        $table = $q_type . '_questions';
        $stmt = $pdo->prepare("DELETE FROM $table WHERE id = ? AND is_central = 1 AND school_id IS NULL");
        $stmt->execute([$q_id]);

        $message = "Central question deleted successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = "Error deleting: " . $e->getMessage();
        $message_type = "error";
    }
}

// ============================================
// FETCH DATA
// ============================================

// Get all subjects (for dropdown)
$subjects = $pdo->query("SELECT id, subject_name FROM subjects WHERE school_id IS NULL OR school_id = 0 ORDER BY subject_name")->fetchAll();

// Get topics by subject via AJAX or preload
$topics = [];
if (isset($_GET['subject_id'])) {
    $subj_id = (int)$_GET['subject_id'];
    $stmt = $pdo->prepare("SELECT id, topic_name FROM topics WHERE subject_id = ? AND (school_id IS NULL OR school_id = 0) ORDER BY topic_name");
    $stmt->execute([$subj_id]);
    $topics = $stmt->fetchAll();
}

// Get central questions
$objective_questions = $pdo->query("
    SELECT oq.*, s.subject_name, t.topic_name 
    FROM objective_questions oq
    LEFT JOIN subjects s ON oq.subject_id = s.id
    LEFT JOIN topics t ON oq.topic_id = t.id
    WHERE oq.is_central = 1 AND oq.school_id IS NULL
    ORDER BY oq.id DESC
")->fetchAll();

$subjective_questions = $pdo->query("
    SELECT sq.*, s.subject_name, t.topic_name 
    FROM subjective_questions sq
    LEFT JOIN subjects s ON sq.subject_id = s.id
    LEFT JOIN topics t ON sq.topic_id = t.id
    WHERE sq.is_central = 1 AND sq.school_id IS NULL
    ORDER BY sq.id DESC
")->fetchAll();

$theory_questions = $pdo->query("
    SELECT tq.*, s.subject_name, t.topic_name 
    FROM theory_questions tq
    LEFT JOIN subjects s ON tq.subject_id = s.id
    LEFT JOIN topics t ON tq.topic_id = t.id
    WHERE tq.is_central = 1 AND tq.school_id IS NULL
    ORDER BY tq.id DESC
")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Central Question Bank - Developer Panel</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: #7b1fa2;
            --secondary-color: #9c27b0;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #4a148c;
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

        .central-badge {
            background: var(--primary-color);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
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
            flex-wrap: wrap;
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

        /* Form Styles */
        .form-section,
        .list-section {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
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
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            font-family: monospace;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 120px;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .options-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .option-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .option-label {
            font-weight: bold;
            color: var(--primary-color);
            min-width: 35px;
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

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.8rem;
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

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 12px;
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
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .badge-subject {
            background: #e3f2fd;
            color: #1565c0;
        }

        .badge-topic {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
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

            .tab-buttons {
                flex-direction: column;
            }

            .data-table {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>

    <div class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-database"></i></div>
            <div class="logo-text">
                <h3>Central Bank</h3>
                <p>Developer Panel</p>
            </div>
        </div>
        <div class="admin-info">
            <h4><?php echo htmlspecialchars($admin_name); ?></h4>
            <p>Super Admin</p>
        </div>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="central_questions.php" class="active"><i class="fas fa-database"></i> Central Question Bank</a></li>
            <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Manage Subjects</a></li>
            <li><a href="manage-topics.php"><i class="fas fa-list"></i> Manage Topics</a></li>
            <li><a href="manage-questions.php"><i class="fas fa-question-circle"></i> School Questions</a></li>
            <li><a href="../gsa/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-database"></i> Central Question Bank</h1>
                <p>Manage verified questions that schools can import into their databases</p>
            </div>
            <span class="central-badge"><i class="fas fa-check-circle"></i> Developer Mode</span>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="tabs-navigation">
            <div class="tab-buttons">
                <button class="tab-button <?php echo $active_tab === 'objective' ? 'active' : ''; ?>" onclick="switchTab('objective')">
                    <i class="fas fa-check-circle"></i> Objective Questions
                </button>
                <button class="tab-button <?php echo $active_tab === 'subjective' ? 'active' : ''; ?>" onclick="switchTab('subjective')">
                    <i class="fas fa-edit"></i> Subjective Questions
                </button>
                <button class="tab-button <?php echo $active_tab === 'theory' ? 'active' : ''; ?>" onclick="switchTab('theory')">
                    <i class="fas fa-file-alt"></i> Theory Questions
                </button>
            </div>

            <!-- OBJECTIVE TAB -->
            <div class="tab-content <?php echo $active_tab === 'objective' ? 'active' : ''; ?>" id="objectiveTab">
                <!-- Add Form -->
                <div class="form-section">
                    <h3 style="margin-bottom: 20px;"><i class="fas fa-plus-circle"></i> Add Central Objective Question</h3>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label>Question Text *</label>
                            <textarea name="question_text" class="form-control" rows="4" required placeholder="Enter the question..."></textarea>
                        </div>
                        <div class="options-grid">
                            <div class="option-group"><span class="option-label">A)</span><input type="text" name="option_a" class="form-control" placeholder="Option A" required></div>
                            <div class="option-group"><span class="option-label">B)</span><input type="text" name="option_b" class="form-control" placeholder="Option B" required></div>
                            <div class="option-group"><span class="option-label">C)</span><input type="text" name="option_c" class="form-control" placeholder="Option C (Optional)"></div>
                            <div class="option-group"><span class="option-label">D)</span><input type="text" name="option_d" class="form-control" placeholder="Option D (Optional)"></div>
                        </div>
                        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px;">
                            <div class="form-group"><label>Correct Answer *</label><select name="correct_answer" class="form-control" required>
                                    <option value="">Select</option>
                                    <option value="A">A</option>
                                    <option value="B">B</option>
                                    <option value="C">C</option>
                                    <option value="D">D</option>
                                </select></div>
                            <div class="form-group"><label>Subject *</label><select name="subject_id" class="form-control" required onchange="loadTopicsForSubject(this.value, 'objective_topic')">
                                    <option value="">Select</option><?php foreach ($subjects as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['subject_name']); ?></option><?php endforeach; ?>
                                </select></div>
                            <div class="form-group"><label>Topic</label><select name="topic_id" id="objective_topic" class="form-control">
                                    <option value="">Select Topic</option>
                                </select></div>
                            <div class="form-group"><label>Difficulty</label><select name="difficulty_level" class="form-control">
                                    <option value="easy">Easy</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="hard">Hard</option>
                                </select></div>
                        </div>
                        <div class="form-group"><label>Marks</label><input type="number" name="marks" class="form-control" value="1" min="1"></div>
                        <div class="form-group"><label>Image (Optional)</label><input type="file" name="question_image" class="form-control" accept="image/*"></div>
                        <div class="checkbox-group" style="margin: 15px 0;"><label><input type="checkbox" name="stay_here" value="1"> Stay here after adding (add another)</label></div>
                        <div class="form-actions"><button type="submit" name="add_central_objective" class="btn btn-success"><i class="fas fa-save"></i> Add to Central Bank</button></div>
                    </form>
                </div>

                <!-- List -->
                <div class="list-section">
                    <h3><i class="fas fa-list"></i> Existing Central Objective Questions</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Question</th>
                                <th>Subject/Topic</th>
                                <th>Correct</th>
                                <th>Marks</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($objective_questions as $q): ?>
                                <tr>
                                    <td><?php echo $q['id']; ?></td>
                                    <td><?php echo htmlspecialchars(substr($q['question_text'], 0, 80)) . (strlen($q['question_text']) > 80 ? '...' : ''); ?></td>
                                    <td><span class="badge badge-subject"><?php echo htmlspecialchars($q['subject_name'] ?? 'N/A'); ?></span><br><span class="badge badge-topic"><?php echo htmlspecialchars($q['topic_name'] ?? 'No topic'); ?></span></td>
                                    <td><strong><?php echo $q['correct_answer']; ?></strong></td>
                                    <td><?php echo $q['marks']; ?></td>
                                    <td class="action-buttons"><a href="?delete=1&type=objective&id=<?php echo $q['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this central question? Schools will no longer be able to import it.')"><i class="fas fa-trash"></i> Delete</a></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($objective_questions)): ?><tr>
                                    <td colspan="6" style="text-align: center;">No central objective questions yet. Add one above.</td>
                                </tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- SUBJECTIVE TAB -->
            <div class="tab-content <?php echo $active_tab === 'subjective' ? 'active' : ''; ?>" id="subjectiveTab">
                <div class="form-section">
                    <h3 style="margin-bottom: 20px;"><i class="fas fa-plus-circle"></i> Add Central Subjective Question</h3>
                    <form method="POST">
                        <div class="form-group"><label>Question Text *</label><textarea name="question_text" class="form-control" rows="4" required></textarea></div>
                        <div class="form-group"><label>Model Answer / Answer Guide</label><textarea name="correct_answer" class="form-control" rows="3" placeholder="Enter the expected answer..."></textarea></div>
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                            <div class="form-group"><label>Subject *</label><select name="subject_id" class="form-control" required onchange="loadTopicsForSubject(this.value, 'subjective_topic')">
                                    <option value="">Select</option><?php foreach ($subjects as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['subject_name']); ?></option><?php endforeach; ?>
                                </select></div>
                            <div class="form-group"><label>Topic</label><select name="topic_id" id="subjective_topic" class="form-control">
                                    <option value="">Select Topic</option>
                                </select></div>
                            <div class="form-group"><label>Marks</label><input type="number" name="marks" class="form-control" value="1" min="1"></div>
                        </div>
                        <div class="checkbox-group" style="margin: 15px 0;"><label><input type="checkbox" name="stay_here" value="1"> Stay here after adding</label></div>
                        <div class="form-actions"><button type="submit" name="add_central_subjective" class="btn btn-success"><i class="fas fa-save"></i> Add to Central Bank</button></div>
                    </form>
                </div>
                <div class="list-section">
                    <h3>Existing Central Subjective Questions</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Question</th>
                                <th>Subject/Topic</th>
                                <th>Marks</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjective_questions as $q): ?>
                                <tr>
                                    <td><?php echo $q['id']; ?></td>
                                    <td><?php echo htmlspecialchars(substr($q['question_text'], 0, 80)) . (strlen($q['question_text']) > 80 ? '...' : ''); ?></td>
                                    <td><span class="badge badge-subject"><?php echo htmlspecialchars($q['subject_name'] ?? 'N/A'); ?></span><br><span class="badge badge-topic"><?php echo htmlspecialchars($q['topic_name'] ?? 'No topic'); ?></span></td>
                                    <td><?php echo $q['marks']; ?></td>
                                    <td><a href="?delete=1&type=subjective&id=<?php echo $q['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i> Delete</a></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($subjective_questions)): ?><tr>
                                    <td colspan="5" style="text-align: center;">No central subjective questions yet.</td>
                                </tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- THEORY TAB -->
            <div class="tab-content <?php echo $active_tab === 'theory' ? 'active' : ''; ?>" id="theoryTab">
                <div class="form-section">
                    <h3 style="margin-bottom: 20px;"><i class="fas fa-plus-circle"></i> Add Central Theory Question</h3>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="form-group"><label>Question Text *</label><textarea name="question_text" class="form-control" rows="4" required></textarea></div>
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                            <div class="form-group"><label>Subject *</label><select name="subject_id" class="form-control" required onchange="loadTopicsForSubject(this.value, 'theory_topic')">
                                    <option value="">Select</option><?php foreach ($subjects as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['subject_name']); ?></option><?php endforeach; ?>
                                </select></div>
                            <div class="form-group"><label>Topic</label><select name="topic_id" id="theory_topic" class="form-control">
                                    <option value="">Select Topic</option>
                                </select></div>
                            <div class="form-group"><label>Marks</label><input type="number" name="marks" class="form-control" value="5" min="1"></div>
                        </div>
                        <div class="form-group"><label>Attach File (Optional)</label><input type="file" name="question_file" class="form-control" accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png"></div>
                        <div class="checkbox-group" style="margin: 15px 0;"><label><input type="checkbox" name="stay_here" value="1"> Stay here after adding</label></div>
                        <div class="form-actions"><button type="submit" name="add_central_theory" class="btn btn-success"><i class="fas fa-save"></i> Add to Central Bank</button></div>
                    </form>
                </div>
                <div class="list-section">
                    <h3>Existing Central Theory Questions</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Question</th>
                                <th>Subject/Topic</th>
                                <th>Marks</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($theory_questions as $q): ?>
                                <tr>
                                    <td><?php echo $q['id']; ?></td>
                                    <td><?php echo htmlspecialchars(substr($q['question_text'] ?? '', 0, 80)) . (strlen($q['question_text'] ?? '') > 80 ? '...' : ''); ?></td>
                                    <td><span class="badge badge-subject"><?php echo htmlspecialchars($q['subject_name'] ?? 'N/A'); ?></span><br><span class="badge badge-topic"><?php echo htmlspecialchars($q['topic_name'] ?? 'No topic'); ?></span></td>
                                    <td><?php echo $q['marks']; ?></td>
                                    <td><a href="?delete=1&type=theory&id=<?php echo $q['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i> Delete</a>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($theory_questions)): ?><tr>
                                    <td colspan="5" style="text-align: center;">No central theory questions yet.</td>
                                </tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        function switchTab(tabName) {
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
            document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            document.querySelector(`.tab-button[onclick="switchTab('${tabName}')"]`).classList.add('active');
            document.getElementById(tabName + 'Tab').classList.add('active');
        }

        async function loadTopicsForSubject(subjectId, targetSelectId) {
            if (!subjectId) return;
            const select = document.getElementById(targetSelectId);
            select.innerHTML = '<option value="">Loading...</option>';
            try {
                const response = await fetch(`../api/get_topics.php?subject_id=${subjectId}&central=1`);
                const data = await response.json();
                if (data.success && data.topics) {
                    select.innerHTML = '<option value="">-- Select Topic --</option>';
                    data.topics.forEach(topic => {
                        select.innerHTML += `<option value="${topic.id}">${escapeHtml(topic.topic_name)}</option>`;
                    });
                } else {
                    select.innerHTML = '<option value="">No topics found</option>';
                }
            } catch (error) {
                select.innerHTML = '<option value="">Error loading topics</option>';
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        const mobileBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        if (mobileBtn) mobileBtn.onclick = () => sidebar.classList.toggle('active');

        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768 && sidebar && mobileBtn) {
                if (!sidebar.contains(e.target) && !mobileBtn.contains(e.target)) {
                    sidebar.classList.remove('active');
                }
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
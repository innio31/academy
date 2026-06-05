<?php
// admin/manage-subjects.php - Complete Subject Management with Card Layout & Modal Actions
session_start();

// Check if admin is logged in
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
$page_title = "Manage Subjects";

// ============================================
// AJAX HANDLER FOR GETTING SUBJECT DETAILS
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_subject' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $subject_id = intval($_GET['id']);

        $stmt = $pdo->prepare("
            SELECT s.*, 
                   GROUP_CONCAT(DISTINCT c.id ORDER BY c.class_name) as assigned_class_ids,
                   GROUP_CONCAT(DISTINCT c.class_name ORDER BY c.class_name) as assigned_class_names
            FROM subjects s
            LEFT JOIN subject_classes sc ON s.id = sc.subject_id AND sc.school_id = s.school_id
            LEFT JOIN classes c ON sc.class_id = c.id
            WHERE s.id = ? AND s.school_id = ? AND s.is_central = 0
            GROUP BY s.id
        ");
        $stmt->execute([$subject_id, $school_id]);
        $subject = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($subject) {
            echo json_encode([
                'success' => true,
                'subject' => [
                    'id' => $subject['id'],
                    'subject_name' => $subject['subject_name'],
                    'description' => $subject['description'],
                    'assigned_class_ids' => $subject['assigned_class_ids'],
                    'assigned_class_names' => $subject['assigned_class_names'],
                    'objective_count' => $subject['objective_count'] ?? 0,
                    'subjective_count' => $subject['subjective_count'] ?? 0,
                    'theory_count' => $subject['theory_count'] ?? 0,
                    'exam_count' => $subject['exam_count'] ?? 0
                ]
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Subject not found'
            ]);
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
    exit();
}

// ============================================
// ENSURE TABLES EXIST WITH UPDATED STRUCTURE
// ============================================

try {
    // Check if subject_classes table exists with class_id column
    $stmt = $pdo->query("SHOW TABLES LIKE 'subject_classes'");
    if (!$stmt->fetch()) {
        $pdo->exec("CREATE TABLE subject_classes (
            id INT PRIMARY KEY AUTO_INCREMENT,
            subject_id INT NOT NULL,
            class_id INT NOT NULL,
            school_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_subject_class (subject_id, class_id),
            INDEX idx_school_id (school_id),
            INDEX idx_class_id (class_id)
        )");
    } else {
        // Check if class column exists and migrate if needed
        $stmt = $pdo->query("SHOW COLUMNS FROM subject_classes LIKE 'class'");
        if ($stmt->fetch()) {
            // Migrate from class to class_id
            $pdo->exec("ALTER TABLE subject_classes ADD COLUMN class_id INT NULL AFTER class");
            $pdo->exec("
                UPDATE subject_classes sc
                JOIN classes c ON sc.class = c.class_name AND sc.school_id = c.school_id
                SET sc.class_id = c.id
                WHERE sc.class IS NOT NULL
            ");
            $pdo->exec("ALTER TABLE subject_classes MODIFY COLUMN class_id INT NOT NULL");
            $pdo->exec("ALTER TABLE subject_classes ADD INDEX idx_class_id (class_id)");
            $pdo->exec("ALTER TABLE subject_classes DROP COLUMN class");
        }
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM subjects LIKE 'is_central'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN is_central TINYINT(1) DEFAULT 0");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM subjects LIKE 'school_id'");
    $col = $stmt->fetch();
    if ($col && $col['Null'] === 'NO') {
        $pdo->exec("ALTER TABLE subjects MODIFY COLUMN school_id INT NULL");
    }
} catch (Exception $e) {
    error_log("Table check error: " . $e->getMessage());
}

// ============================================
// HANDLE FORM SUBMISSIONS
// ============================================

$message = '';
$message_type = '';

// Add multiple subjects
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_multiple_subjects'])) {
    try {
        $selected_subject_ids = $_POST['central_subject_ids'] ?? [];
        $selected_class_ids = $_POST['class_ids'] ?? [];

        if (empty($selected_subject_ids)) {
            throw new Exception("Please select at least one subject to add");
        }

        if (empty($selected_class_ids)) {
            throw new Exception("Please select at least one class to assign these subjects to");
        }

        $added_count = 0;
        $skipped_count = 0;

        foreach ($selected_subject_ids as $central_subject_id) {
            $stmt = $pdo->prepare("SELECT subject_name, description FROM subjects WHERE id = ? AND school_id IS NULL AND is_central = 1");
            $stmt->execute([$central_subject_id]);
            $central_subject = $stmt->fetch();

            if (!$central_subject) {
                continue;
            }

            $stmt = $pdo->prepare("SELECT id FROM subjects WHERE subject_name = ? AND school_id = ?");
            $stmt->execute([$central_subject['subject_name'], $school_id]);
            if ($stmt->fetch()) {
                $skipped_count++;
                continue;
            }

            $stmt = $pdo->prepare("INSERT INTO subjects (subject_name, description, school_id, is_central, created_at) VALUES (?, ?, ?, 0, NOW())");
            $stmt->execute([$central_subject['subject_name'], $central_subject['description'], $school_id]);
            $new_subject_id = $pdo->lastInsertId();

            foreach ($selected_class_ids as $class_id) {
                if (!empty($class_id)) {
                    $stmt = $pdo->prepare("INSERT INTO subject_classes (subject_id, class_id, school_id) VALUES (?, ?, ?)");
                    $stmt->execute([$new_subject_id, $class_id, $school_id]);
                }
            }
            $added_count++;
        }

        if ($added_count > 0) {
            $message = "$added_count subject(s) added successfully";
            if ($skipped_count > 0) {
                $message .= ". $skipped_count subject(s) already existed.";
            }
            $message_type = "success";
        } else {
            throw new Exception("No subjects were added. " . ($skipped_count > 0 ? "Selected subjects already exist." : "Please select subjects."));
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Update subject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_subject'])) {
    try {
        $subject_id = $_POST['subject_id'];
        $description = trim($_POST['description'] ?? '');
        $selected_class_ids = $_POST['class_ids'] ?? [];

        $stmt = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ? AND school_id = ? AND is_central = 0");
        $stmt->execute([$subject_id, $school_id]);
        if (!$stmt->fetch()) {
            throw new Exception("Subject not found");
        }

        $stmt = $pdo->prepare("UPDATE subjects SET description = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$description, $subject_id]);

        // Delete existing class assignments
        $stmt = $pdo->prepare("DELETE FROM subject_classes WHERE subject_id = ? AND school_id = ?");
        $stmt->execute([$subject_id, $school_id]);

        // Insert new class assignments
        foreach ($selected_class_ids as $class_id) {
            if (!empty($class_id)) {
                $stmt = $pdo->prepare("INSERT INTO subject_classes (subject_id, class_id, school_id) VALUES (?, ?, ?)");
                $stmt->execute([$subject_id, $class_id, $school_id]);
            }
        }

        $message = "Subject updated successfully";
        $message_type = "success";

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => $message]);
            exit();
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $message]);
            exit();
        }
    }
}

// Delete subject
if (isset($_GET['delete'])) {
    try {
        $subject_id = $_GET['delete'];

        $stmt = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ? AND school_id = ? AND is_central = 0");
        $stmt->execute([$subject_id, $school_id]);
        $subject = $stmt->fetch();
        if (!$subject) {
            throw new Exception("Subject not found");
        }

        // Check dependencies
        $checks = [
            'objective_questions' => 'objective_count',
            'subjective_questions' => 'subjective_count',
            'theory_questions' => 'theory_count',
            'exams' => 'exam_count',
            'topics' => 'topic_count',
            'student_scores' => 'score_count'
        ];
        $total = 0;
        foreach ($checks as $table => $field) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE subject_id = ?");
            $stmt->execute([$subject_id]);
            $total += $stmt->fetchColumn();
        }

        if ($total > 0) {
            throw new Exception("Cannot delete subject. It has associated questions, exams, or scores.");
        }

        // Delete subject classes first
        $stmt = $pdo->prepare("DELETE FROM subject_classes WHERE subject_id = ?");
        $stmt->execute([$subject_id]);

        $stmt = $pdo->prepare("DELETE FROM subjects WHERE id = ?");
        $stmt->execute([$subject_id]);

        $message = "Subject '{$subject['subject_name']}' deleted successfully";
        $message_type = "success";
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// ============================================
// FETCH DATA
// ============================================

// Fetch available central subjects
$stmt = $pdo->prepare("
    SELECT s.* 
    FROM subjects s
    WHERE s.school_id IS NULL AND s.is_central = 1
    AND s.subject_name NOT IN (SELECT subject_name FROM subjects WHERE school_id = ?)
    ORDER BY s.subject_name
");
$stmt->execute([$school_id]);
$available_subjects = $stmt->fetchAll();

// Fetch all subjects for this school with class names
$stmt = $pdo->prepare("
    SELECT s.*, 
           GROUP_CONCAT(DISTINCT c.class_name ORDER BY c.class_name) as assigned_classes,
           (SELECT COUNT(*) FROM objective_questions WHERE subject_id = s.id) as objective_count,
           (SELECT COUNT(*) FROM subjective_questions WHERE subject_id = s.id) as subjective_count,
           (SELECT COUNT(*) FROM theory_questions WHERE subject_id = s.id) as theory_count,
           (SELECT COUNT(*) FROM exams WHERE subject_id = s.id) as exam_count,
           (SELECT COUNT(*) FROM topics WHERE subject_id = s.id) as topic_count,
           (SELECT COUNT(*) FROM student_scores WHERE subject_id = s.id) as score_count
    FROM subjects s
    LEFT JOIN subject_classes sc ON s.id = sc.subject_id AND sc.school_id = s.school_id
    LEFT JOIN classes c ON sc.class_id = c.id
    WHERE s.school_id = ?
    GROUP BY s.id
    ORDER BY s.subject_name
");
$stmt->execute([$school_id]);
$subjects = $stmt->fetchAll();

// Fetch available classes
$stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? AND status = 'active' ORDER BY sort_order, class_name");
$stmt->execute([$school_id]);
$available_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fallback if no classes exist
if (empty($available_classes)) {
    $stmt = $pdo->prepare("SELECT DISTINCT class FROM students WHERE school_id = ? AND class IS NOT NULL AND class != '' ORDER BY class");
    $stmt->execute([$school_id]);
    $student_classes = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($student_classes)) {
        foreach ($student_classes as $class_name) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO classes (school_id, class_name, status) VALUES (?, ?, 'active')");
            $stmt->execute([$school_id, $class_name]);
        }
        $stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? AND status = 'active' ORDER BY sort_order, class_name");
        $stmt->execute([$school_id]);
        $available_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Include sidebar
require_once 'includes/sidebar.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($school_name); ?> - Manage Subjects</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
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

        /* Main Content - pushed by sidebar */
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

        .btn-purple {
            background: var(--purple);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--gray-300);
            color: var(--gray-800);
        }

        /* Search Bar */
        .search-bar {
            background: white;
            border-radius: var(--radius-lg);
            padding: 12px 20px;
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            box-shadow: var(--shadow-sm);
        }

        .search-bar i {
            color: var(--gray-600);
        }

        .search-bar input {
            flex: 1;
            min-width: 200px;
            padding: 10px 15px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 0.85rem;
        }

        .search-bar input:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        /* Subject Cards - Mobile First, No Horizontal Scroll */
        .subjects-grid {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .subject-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 18px;
            box-shadow: var(--shadow-sm);
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid var(--gray-200);
        }

        .subject-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary-color);
        }

        .subject-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }

        .subject-name {
            font-size: 1rem;
            font-weight: 700;
            color: var(--gray-800);
            background: var(--info-light);
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-block;
        }

        .subject-name i {
            margin-right: 6px;
            color: var(--primary-color);
        }

        .subject-description {
            font-size: 0.8rem;
            color: var(--gray-600);
            margin-bottom: 12px;
            line-height: 1.4;
        }

        .subject-classes {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 12px;
        }

        .class-tag {
            background: var(--gray-100);
            color: var(--gray-600);
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.7rem;
        }

        .stats-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
            padding-top: 12px;
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

        .stat-badge.exam {
            background: #fff3e0;
            color: #f57c00;
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
            position: sticky;
            bottom: 0;
            background: white;
        }

        /* Info rows in modal */
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

        /* Action Buttons in Modal */
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

        /* Checkbox Group */
        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 12px;
            margin-top: 10px;
            max-height: 200px;
            overflow-y: auto;
            padding: 10px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .checkbox-item input {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary-color);
        }

        /* Alert */
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

        /* Empty State */
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

        .empty-state h3 {
            margin-bottom: 8px;
        }

        .empty-state p {
            color: var(--gray-600);
            margin-bottom: 20px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }

            .top-header {
                flex-direction: column;
                text-align: center;
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

            .checkbox-group {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-book"></i> Manage Subjects</h1>
                <p>Click any subject to view details and actions</p>
            </div>
            <button class="btn btn-primary" id="openSubjectModalBtn">
                <i class="fas fa-plus-circle"></i> Add Subjects
            </button>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>" id="alertMessage">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Search Bar -->
        <div class="search-bar">
            <i class="fas fa-search"></i>
            <input type="text" id="searchInput" placeholder="Search subjects by name, description, or class...">
        </div>

        <!-- Subjects Grid -->
        <?php if (empty($subjects)): ?>
            <div class="empty-state">
                <i class="fas fa-book-open"></i>
                <h3>No Subjects Found</h3>
                <p>Click "Add Subjects" to add subjects from the national curriculum.</p>
                <button class="btn btn-primary" onclick="document.getElementById('openSubjectModalBtn').click()">
                    <i class="fas fa-plus-circle"></i> Add Subjects
                </button>
            </div>
        <?php else: ?>
            <div class="subjects-grid" id="subjectsGrid">
                <?php foreach ($subjects as $subject): ?>
                    <div class="subject-card" data-subject-id="<?php echo $subject['id']; ?>" data-subject-name="<?php echo htmlspecialchars(strtolower($subject['subject_name'])); ?>" data-subject-desc="<?php echo htmlspecialchars(strtolower($subject['description'] ?? '')); ?>" data-subject-classes="<?php echo htmlspecialchars(strtolower($subject['assigned_classes'] ?? '')); ?>">
                        <div class="subject-card-header">
                            <span class="subject-name">
                                <i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($subject['subject_name']); ?>
                            </span>
                        </div>
                        <?php if ($subject['description']): ?>
                            <div class="subject-description">
                                <?php echo htmlspecialchars(substr($subject['description'], 0, 100)) . (strlen($subject['description'] ?? '') > 100 ? '...' : ''); ?>
                            </div>
                        <?php endif; ?>
                        <div class="subject-classes">
                            <?php if ($subject['assigned_classes']): ?>
                                <?php foreach (explode(',', $subject['assigned_classes']) as $class): ?>
                                    <span class="class-tag"><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($class); ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="class-tag" style="background: var(--warning-light); color: var(--warning);">Not assigned to any class</span>
                            <?php endif; ?>
                        </div>
                        <div class="stats-row">
                            <span class="stat-badge objective" title="Objective Questions"><i class="fas fa-check-circle"></i> O: <?php echo $subject['objective_count']; ?></span>
                            <span class="stat-badge subjective" title="Subjective Questions"><i class="fas fa-pencil-alt"></i> S: <?php echo $subject['subjective_count']; ?></span>
                            <span class="stat-badge theory" title="Theory Questions"><i class="fas fa-file-alt"></i> T: <?php echo $subject['theory_count']; ?></span>
                            <span class="stat-badge exam" title="Exams"><i class="fas fa-calendar-alt"></i> E: <?php echo $subject['exam_count']; ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Subject Detail Modal -->
    <div class="modal" id="subjectModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalSubjectTitle">Subject Details</h3>
                <button class="close-modal" onclick="closeModal('subjectModal')">&times;</button>
            </div>
            <div class="modal-body" id="subjectModalBody">
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-pulse fa-2x"></i>
                    <p>Loading subject details...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Subjects Modal -->
    <div class="modal" id="addSubjectsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Add Subjects from National Curriculum</h3>
                <button class="close-modal" onclick="closeModal('addSubjectsModal')">&times;</button>
            </div>
            <form method="POST" id="addSubjectsForm">
                <div class="modal-body">
                    <div class="search-bar" style="margin-bottom: 16px; padding: 0;">
                        <i class="fas fa-search"></i>
                        <input type="text" id="subjectSearchInput" placeholder="Search subjects...">
                    </div>
                    <div class="subjects-list" id="subjectsList" style="max-height: 300px; overflow-y: auto; border: 2px solid var(--gray-200); border-radius: var(--radius-md);">
                        <?php foreach ($available_subjects as $subject): ?>
                            <div class="subject-item" data-subject-name="<?php echo htmlspecialchars(strtolower($subject['subject_name'])); ?>" style="display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-bottom: 1px solid var(--gray-200);">
                                <input type="checkbox" name="central_subject_ids[]" value="<?php echo $subject['id']; ?>" id="subj_<?php echo $subject['id']; ?>" class="subject-checkbox" onchange="updateSelectedCount()">
                                <div class="subject-info">
                                    <div class="subject-name"><?php echo htmlspecialchars($subject['subject_name']); ?></div>
                                    <?php if ($subject['description']): ?>
                                        <div class="subject-desc" style="font-size: 0.7rem; color: var(--gray-600);"><?php echo htmlspecialchars(substr($subject['description'], 0, 80)); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($available_subjects)): ?>
                            <div style="padding: 40px; text-align: center; color: var(--gray-600);">
                                <i class="fas fa-check-circle" style="font-size: 32px; margin-bottom: 10px; color: var(--success);"></i>
                                <p>All subjects have been added to your school!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div style="margin: 16px 0; display: flex; gap: 10px; flex-wrap: wrap;">
                        <button type="button" class="btn btn-sm btn-primary" onclick="selectAllModalSubjects()"><i class="fas fa-check-double"></i> Select All</button>
                        <button type="button" class="btn btn-sm btn-outline" onclick="deselectAllModalSubjects()"><i class="fas fa-times"></i> Deselect All</button>
                        <span id="modalSelectedCount" class="selected-count" style="background: var(--primary-color); color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem;">0 selected</span>
                    </div>

                    <div class="form-group" style="margin-top: 20px;">
                        <label class="form-label" style="display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 8px;">Assign to Classes</label>
                        <div class="checkbox-group" id="modalClassCheckboxGroup">
                            <?php if (!empty($available_classes)): ?>
                                <?php foreach ($available_classes as $class): ?>
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="class_ids[]" value="<?php echo $class['id']; ?>" id="modal_class_<?php echo $class['id']; ?>">
                                        <label for="modal_class_<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></label>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p style="color: var(--gray-600); grid-column: span 2;">No classes found. Please add classes first.</p>
                            <?php endif; ?>
                        </div>
                        <div style="margin-top: 10px;">
                            <button type="button" class="btn btn-sm btn-outline" onclick="selectAllModalClasses()"><i class="fas fa-check-double"></i> Select All Classes</button>
                            <button type="button" class="btn btn-sm btn-outline" onclick="deselectAllModalClasses()"><i class="fas fa-times"></i> Deselect All Classes</button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addSubjectsModal')">Cancel</button>
                    <button type="submit" name="add_multiple_subjects" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Add Selected Subjects</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Subject Modal (inside main modal) -->
    <div class="modal" id="editSubjectModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Subject</h3>
                <button class="close-modal" onclick="closeModal('editSubjectModal')">&times;</button>
            </div>
            <form method="POST" id="editSubjectForm">
                <input type="hidden" name="subject_id" id="edit_subject_id" value="">
                <div class="modal-body" id="editModalBody">
                    <div style="text-align: center; padding: 40px;">
                        <i class="fas fa-spinner fa-pulse fa-2x"></i>
                        <p>Loading...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('editSubjectModal')">Cancel</button>
                    <button type="submit" name="update_subject" class="btn btn-primary"><i class="fas fa-save"></i> Update Subject</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <h3>Confirm Delete</h3>
                <button class="close-modal" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="deleteSubjectName"></strong>?</p>
                <p style="color: var(--danger); margin-top: 10px;"><i class="fas fa-exclamation-triangle"></i> This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
                <button class="btn btn-danger" id="confirmDeleteBtn">Delete Subject</button>
            </div>
        </div>
    </div>

    <script>
        // Available classes
        const availableClasses = <?php echo json_encode($available_classes); ?>;

        // Search functionality
        const searchInput = document.getElementById('searchInput');
        const subjectCards = document.querySelectorAll('.subject-card');

        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                const term = this.value.toLowerCase();
                subjectCards.forEach(card => {
                    const name = card.getAttribute('data-subject-name') || '';
                    const desc = card.getAttribute('data-subject-desc') || '';
                    const classes = card.getAttribute('data-subject-classes') || '';
                    const matches = name.includes(term) || desc.includes(term) || classes.includes(term);
                    card.style.display = matches ? 'block' : 'none';
                });
            });
        }

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = '';
        }

        // Open subject detail modal when clicking a card
        const cards = document.querySelectorAll('.subject-card');
        cards.forEach(card => {
            card.addEventListener('click', function(e) {
                // Don't open if clicking on a link inside
                if (e.target.closest('a')) return;
                const subjectId = this.getAttribute('data-subject-id');
                openSubjectModal(subjectId);
            });
        });

        function openSubjectModal(subjectId) {
            const modal = document.getElementById('subjectModal');
            const modalBody = document.getElementById('subjectModalBody');
            const modalTitle = document.getElementById('modalSubjectTitle');

            modalBody.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-pulse fa-2x"></i><p>Loading subject details...</p></div>';
            openModal('subjectModal');

            fetch(`manage-subjects.php?ajax=get_subject&id=${subjectId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const subject = data.subject;
                        modalTitle.innerHTML = `<i class="fas fa-book"></i> ${escapeHtml(subject.subject_name)}`;

                        let assignedClassesHtml = '';
                        if (subject.assigned_class_names) {
                            const classNames = subject.assigned_class_names.split(',');
                            classNames.forEach(className => {
                                assignedClassesHtml += `<span class="class-tag" style="background: var(--info-light);"><i class="fas fa-chalkboard"></i> ${escapeHtml(className)}</span>`;
                            });
                        } else {
                            assignedClassesHtml = '<span class="class-tag" style="background: var(--warning-light);">Not assigned to any class</span>';
                        }

                        const hasDependencies = (parseInt(subject.objective_count) + parseInt(subject.subjective_count) + parseInt(subject.theory_count) + parseInt(subject.exam_count)) > 0;

                        modalBody.innerHTML = `
                            <div class="info-row">
                                <div class="info-label">Subject Name:</div>
                                <div class="info-value"><strong>${escapeHtml(subject.subject_name)}</strong></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Description:</div>
                                <div class="info-value">${escapeHtml(subject.description) || '<span style="color: #999;">No description</span>'}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Assigned Classes:</div>
                                <div class="info-value"><div style="display: flex; flex-wrap: wrap; gap: 6px;">${assignedClassesHtml}</div></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Usage Stats:</div>
                                <div class="info-value">
                                    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                        <span class="stat-badge objective"><i class="fas fa-check-circle"></i> Objective: ${subject.objective_count}</span>
                                        <span class="stat-badge subjective"><i class="fas fa-pencil-alt"></i> Subjective: ${subject.subjective_count}</span>
                                        <span class="stat-badge theory"><i class="fas fa-file-alt"></i> Theory: ${subject.theory_count}</span>
                                        <span class="stat-badge exam"><i class="fas fa-calendar-alt"></i> Exams: ${subject.exam_count}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-action-buttons">
                                <button class="btn btn-info modal-action-btn" onclick="closeModal('subjectModal'); window.location.href='manage-topics.php?subject_id=${subject.id}'">
                                    <i class="fas fa-tags"></i> Topics
                                </button>
                                <button class="btn btn-success modal-action-btn" onclick="closeModal('subjectModal'); window.location.href='manage-questions.php?subject_id=${subject.id}'">
                                    <i class="fas fa-question-circle"></i> Questions
                                </button>
                                <button class="btn btn-warning modal-action-btn" onclick="closeModal('subjectModal'); openEditModal(${subject.id})">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                ${!hasDependencies ? `
                                    <button class="btn btn-danger modal-action-btn" onclick="closeModal('subjectModal'); confirmDelete(${subject.id}, '${escapeHtml(subject.subject_name)}')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                ` : `
                                    <button class="btn btn-danger modal-action-btn" disabled style="opacity:0.5; cursor:not-allowed;" title="Cannot delete - has dependencies">
                                        <i class="fas fa-trash"></i> Delete (Has Dependencies)
                                    </button>
                                `}
                            </div>
                        `;
                    } else {
                        modalBody.innerHTML = `<div style="text-align: center; padding: 40px; color: var(--danger);"><i class="fas fa-exclamation-triangle fa-2x"></i><p>${escapeHtml(data.message)}</p><button class="btn btn-outline" onclick="closeModal('subjectModal')">Close</button></div>`;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    modalBody.innerHTML = `<div style="text-align: center; padding: 40px; color: var(--danger);"><i class="fas fa-exclamation-triangle fa-2x"></i><p>Failed to load subject details. Please try again.</p><button class="btn btn-outline" onclick="closeModal('subjectModal')">Close</button></div>`;
                });
        }

        function openEditModal(subjectId) {
            const modal = document.getElementById('editSubjectModal');
            const modalBody = document.getElementById('editModalBody');
            modalBody.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-pulse fa-2x"></i><p>Loading...</p></div>';
            openModal('editSubjectModal');

            fetch(`manage-subjects.php?ajax=get_subject&id=${subjectId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const subject = data.subject;
                        const assignedClassIds = subject.assigned_class_ids ? subject.assigned_class_ids.split(',') : [];
                        let classesHtml = '';

                        if (availableClasses.length > 0) {
                            availableClasses.forEach(classObj => {
                                const isChecked = assignedClassIds.includes(String(classObj.id));
                                classesHtml += `
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="class_ids[]" value="${classObj.id}" id="edit_class_${classObj.id}" ${isChecked ? 'checked' : ''}>
                                        <label for="edit_class_${classObj.id}">${escapeHtml(classObj.class_name)}</label>
                                    </div>
                                `;
                            });
                        } else {
                            classesHtml = '<p style="color: var(--gray-600); grid-column: span 2;">No classes available.</p>';
                        }

                        modalBody.innerHTML = `
                            <input type="hidden" name="subject_id" id="edit_subject_id" value="${subject.id}">
                            <div class="form-group" style="margin-bottom: 20px;">
                                <label class="form-label">Subject Name</label>
                                <input type="text" class="form-control" value="${escapeHtml(subject.subject_name)}" readonly disabled style="background: var(--gray-50);">
                                <small style="color: var(--gray-600);">Subject names are fixed from the national curriculum.</small>
                            </div>
                            <div class="form-group" style="margin-bottom: 20px;">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control" rows="3" placeholder="Optional subject description">${escapeHtml(subject.description || '')}</textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Assign to Classes</label>
                                <div class="checkbox-group" id="editClassCheckboxGroup">
                                    ${classesHtml}
                                </div>
                                <div style="margin-top: 12px;">
                                    <button type="button" class="btn btn-sm btn-outline" onclick="selectAllEditClasses()"><i class="fas fa-check-double"></i> Select All</button>
                                    <button type="button" class="btn btn-sm btn-outline" onclick="deselectAllEditClasses()"><i class="fas fa-times"></i> Deselect All</button>
                                </div>
                            </div>
                        `;
                        document.getElementById('edit_subject_id').value = subject.id;
                    } else {
                        modalBody.innerHTML = `<div style="text-align: center; padding: 40px; color: var(--danger);"><i class="fas fa-exclamation-triangle fa-2x"></i><p>${escapeHtml(data.message)}</p><button class="btn btn-outline" onclick="closeModal('editSubjectModal')">Close</button></div>`;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    modalBody.innerHTML = `<div style="text-align: center; padding: 40px; color: var(--danger);"><i class="fas fa-exclamation-triangle fa-2x"></i><p>Failed to load. Please try again.</p><button class="btn btn-outline" onclick="closeModal('editSubjectModal')">Close</button></div>`;
                });
        }

        function selectAllEditClasses() {
            const checkboxes = document.querySelectorAll('#editClassCheckboxGroup input[type="checkbox"]');
            checkboxes.forEach(cb => cb.checked = true);
        }

        function deselectAllEditClasses() {
            const checkboxes = document.querySelectorAll('#editClassCheckboxGroup input[type="checkbox"]');
            checkboxes.forEach(cb => cb.checked = false);
        }

        // Edit form submission
        const editForm = document.getElementById('editSubjectForm');
        if (editForm) {
            editForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                formData.append('update_subject', '1');

                fetch('manage-subjects.php', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showAlert(data.message, 'success');
                            closeModal('editSubjectModal');
                            setTimeout(() => window.location.reload(), 1000);
                        } else {
                            showAlert(data.message, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showAlert('An error occurred while updating.', 'error');
                    });
            });
        }

        // Add subject modal functions
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('#subjectsList .subject-checkbox:checked');
            const count = checkboxes.length;
            const countSpan = document.getElementById('modalSelectedCount');
            if (countSpan) {
                countSpan.textContent = count + ' selected';
                countSpan.style.background = count > 0 ? 'var(--success)' : 'var(--primary-color)';
            }
        }

        function selectAllModalSubjects() {
            const checkboxes = document.querySelectorAll('#subjectsList .subject-checkbox');
            checkboxes.forEach(cb => cb.checked = true);
            updateSelectedCount();
        }

        function deselectAllModalSubjects() {
            const checkboxes = document.querySelectorAll('#subjectsList .subject-checkbox');
            checkboxes.forEach(cb => cb.checked = false);
            updateSelectedCount();
        }

        function selectAllModalClasses() {
            const checkboxes = document.querySelectorAll('#modalClassCheckboxGroup input[type="checkbox"]');
            checkboxes.forEach(cb => cb.checked = true);
        }

        function deselectAllModalClasses() {
            const checkboxes = document.querySelectorAll('#modalClassCheckboxGroup input[type="checkbox"]');
            checkboxes.forEach(cb => cb.checked = false);
        }

        // Subject search in add modal
        const subjectSearch = document.getElementById('subjectSearchInput');
        const subjectItems = document.querySelectorAll('#subjectsList .subject-item');

        if (subjectSearch) {
            subjectSearch.addEventListener('keyup', function() {
                const term = this.value.toLowerCase();
                subjectItems.forEach(item => {
                    const name = item.getAttribute('data-subject-name') || '';
                    item.style.display = name.includes(term) ? 'flex' : 'none';
                });
            });
        }

        // Delete modal
        let deleteId = null;

        function confirmDelete(id, name) {
            deleteId = id;
            document.getElementById('deleteSubjectName').textContent = name;
            openModal('deleteModal');
        }

        function closeDeleteModal() {
            closeModal('deleteModal');
            deleteId = null;
        }

        const confirmBtn = document.getElementById('confirmDeleteBtn');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', () => {
                if (deleteId) window.location.href = `manage-subjects.php?delete=${deleteId}`;
            });
        }

        // Helper functions
        function showAlert(message, type) {
            const existingAlerts = document.querySelectorAll('.alert');
            existingAlerts.forEach(alert => alert.remove());

            const mainContent = document.querySelector('.main-content');
            const topHeader = document.querySelector('.top-header');
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.innerHTML = `<i class="fas fa-${type === 'error' ? 'exclamation-triangle' : 'check-circle'}"></i> ${escapeHtml(message)}`;

            if (topHeader && topHeader.nextSibling) {
                mainContent.insertBefore(alertDiv, topHeader.nextSibling);
            } else {
                mainContent.insertBefore(alertDiv, mainContent.firstChild);
            }

            setTimeout(() => {
                alertDiv.style.transition = 'opacity 0.5s';
                alertDiv.style.opacity = '0';
                setTimeout(() => alertDiv.remove(), 500);
            }, 5000);
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Open add modal
        const openAddBtn = document.getElementById('openSubjectModalBtn');
        if (openAddBtn) {
            openAddBtn.onclick = () => openModal('addSubjectsModal');
        }

        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Initialize selected count
        updateSelectedCount();

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList && event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
                document.body.style.overflow = '';
            }
        };
    </script>
</body>

</html>
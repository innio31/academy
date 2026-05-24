<?php
// admin/manage-subjects.php - Complete Subject Management with Modal Selection
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

// ============================================
// ENSURE TABLES EXIST
// ============================================

try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'subject_classes'");
    if (!$stmt->fetch()) {
        $pdo->exec("CREATE TABLE subject_classes (
            id INT PRIMARY KEY AUTO_INCREMENT,
            subject_id INT NOT NULL,
            class VARCHAR(50) NOT NULL,
            school_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_subject_class (subject_id, class),
            FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
            INDEX idx_school_id (school_id)
        )");
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
        $classes = $_POST['classes'] ?? [];

        if (empty($selected_subject_ids)) {
            throw new Exception("Please select at least one subject to add");
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

            foreach ($classes as $class) {
                if (!empty($class)) {
                    $stmt = $pdo->prepare("INSERT INTO subject_classes (subject_id, class, school_id) VALUES (?, ?, ?)");
                    $stmt->execute([$new_subject_id, $class, $school_id]);
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
        $classes = $_POST['classes'] ?? [];

        $stmt = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ? AND school_id = ? AND is_central = 0");
        $stmt->execute([$subject_id, $school_id]);
        if (!$stmt->fetch()) {
            throw new Exception("Subject not found");
        }

        $stmt = $pdo->prepare("UPDATE subjects SET description = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$description, $subject_id]);

        $stmt = $pdo->prepare("DELETE FROM subject_classes WHERE subject_id = ? AND school_id = ?");
        $stmt->execute([$subject_id, $school_id]);

        foreach ($classes as $class) {
            if (!empty($class)) {
                $stmt = $pdo->prepare("INSERT INTO subject_classes (subject_id, class, school_id) VALUES (?, ?, ?)");
                $stmt->execute([$subject_id, $class, $school_id]);
            }
        }

        $message = "Subject updated successfully";
        $message_type = "success";
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
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

// Fetch all subjects for this school
$stmt = $pdo->prepare("
    SELECT s.*, 
           GROUP_CONCAT(DISTINCT sc.class ORDER BY sc.class) as assigned_classes,
           (SELECT COUNT(*) FROM objective_questions WHERE subject_id = s.id) as objective_count,
           (SELECT COUNT(*) FROM subjective_questions WHERE subject_id = s.id) as subjective_count,
           (SELECT COUNT(*) FROM theory_questions WHERE subject_id = s.id) as theory_count,
           (SELECT COUNT(*) FROM exams WHERE subject_id = s.id) as exam_count,
           (SELECT COUNT(*) FROM topics WHERE subject_id = s.id) as topic_count,
           (SELECT COUNT(*) FROM student_scores WHERE subject_id = s.id) as score_count
    FROM subjects s
    LEFT JOIN subject_classes sc ON s.id = sc.subject_id AND sc.school_id = s.school_id
    WHERE s.school_id = ?
    GROUP BY s.id
    ORDER BY s.subject_name
");
$stmt->execute([$school_id]);
$subjects = $stmt->fetchAll();

// Fetch available classes
$stmt = $pdo->prepare("SELECT DISTINCT class FROM students WHERE school_id = ? AND class IS NOT NULL AND class != '' UNION SELECT DISTINCT class FROM exams WHERE school_id = ? AND class IS NOT NULL AND class != '' ORDER BY class");
$stmt->execute([$school_id, $school_id]);
$available_classes = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($available_classes)) {
    $available_classes = ['JSS 1', 'JSS 2', 'JSS 3', 'SS 1', 'SS 2', 'SS 3'];
}

// Fetch subject for editing
$edit_subject = null;
if (isset($_GET['edit'])) {
    $subject_id = $_GET['edit'];
    $stmt = $pdo->prepare("
        SELECT s.*, GROUP_CONCAT(DISTINCT sc.class ORDER BY sc.class) as assigned_classes
        FROM subjects s
        LEFT JOIN subject_classes sc ON s.id = sc.subject_id AND sc.school_id = s.school_id
        WHERE s.id = ? AND s.school_id = ?
        GROUP BY s.id
    ");
    $stmt->execute([$subject_id, $school_id]);
    $edit_subject = $stmt->fetch();
    if ($edit_subject) {
        $edit_subject['assigned_classes_array'] = $edit_subject['assigned_classes'] ? explode(',', $edit_subject['assigned_classes']) : [];
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
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
            --sidebar-width: 280px;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 16px;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.12);
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

        /* Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(165deg, var(--primary-color), #1a3a5c);
            color: white;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
            overflow-y: auto;
            transform: translateX(-100%);
            box-shadow: var(--shadow-lg);
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .sidebar-header {
            padding: 24px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            width: 44px;
            height: 44px;
            background: var(--secondary-color);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .logo-text h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .logo-text p {
            font-size: 0.7rem;
            opacity: 0.8;
        }

        .admin-info {
            background: rgba(255, 255, 255, 0.08);
            margin: 16px;
            padding: 16px;
            border-radius: var(--radius-lg);
            text-align: center;
        }

        .admin-info h4 {
            font-size: 0.9rem;
            margin-bottom: 4px;
        }

        .admin-info p {
            font-size: 0.7rem;
            opacity: 0.8;
        }

        .nav-links {
            list-style: none;
            padding: 12px;
        }

        .nav-links li {
            margin-bottom: 4px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.2s;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
        }

        .nav-links i {
            width: 22px;
        }

        /* Main Content */
        .main-content {
            min-height: 100vh;
            padding: 20px;
            transition: margin-left 0.3s;
        }

        .mobile-menu-btn {
            position: fixed;
            top: 16px;
            left: 16px;
            z-index: 1001;
            width: 44px;
            height: 44px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 20px;
            cursor: pointer;
            box-shadow: var(--shadow-md);
        }

        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }

        .sidebar-overlay.active {
            opacity: 1;
            visibility: visible;
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

        .btn-outline:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        /* Cards */
        .card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--gray-200);
        }

        .card-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--gray-800);
        }

        /* Filter Form */
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 160px;
        }

        .form-label {
            display: block;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-600);
            margin-bottom: 6px;
        }

        .form-control,
        .form-select {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            transition: all 0.2s;
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .form-control[readonly] {
            background: var(--gray-50);
            cursor: not-allowed;
        }

        /* Table */
        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        .data-table th {
            text-align: left;
            padding: 12px 16px;
            background: var(--gray-50);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-600);
            border-bottom: 2px solid var(--gray-200);
        }

        .data-table td {
            padding: 14px 16px;
            font-size: 0.8rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .data-table tr:hover {
            background: var(--gray-50);
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .class-tag {
            display: inline-block;
            background: var(--info-light);
            color: var(--info);
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.7rem;
            margin: 2px;
        }

        .count-badge {
            display: inline-flex;
            align-items: center;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            margin: 2px;
        }

        .count-badge.objective {
            background: #e3f2fd;
            color: #1976d2;
        }

        .count-badge.subjective {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .count-badge.theory {
            background: #e8f5e9;
            color: #388e3c;
        }

        .count-badge.exam {
            background: #fff3e0;
            color: #f57c00;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 6px 10px;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .edit-btn {
            background: var(--info);
            color: white;
        }

        .topics-btn {
            background: var(--purple);
            color: white;
        }

        .questions-btn {
            background: var(--success);
            color: white;
        }

        .delete-btn {
            background: var(--danger);
            color: white;
        }

        .disabled-btn {
            background: var(--gray-300);
            cursor: not-allowed;
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
            z-index: 1100;
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
            max-width: 700px;
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
            z-index: 10;
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

        /* Subject Selection List */
        .subjects-list {
            max-height: 400px;
            overflow-y: auto;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
        }

        .subject-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-bottom: 1px solid var(--gray-200);
            cursor: pointer;
            transition: background 0.2s;
        }

        .subject-item:hover {
            background: var(--gray-50);
        }

        .subject-item.selected {
            background: var(--info-light);
        }

        .subject-item input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: var(--primary-color);
        }

        .subject-info {
            flex: 1;
        }

        .subject-name {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .subject-desc {
            font-size: 0.7rem;
            color: var(--gray-600);
            margin-top: 2px;
        }

        /* Search Box */
        .search-box {
            position: relative;
            margin-bottom: 16px;
        }

        .search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-600);
        }

        .search-box input {
            width: 100%;
            padding: 12px 12px 12px 40px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-size: 0.85rem;
        }

        /* Checkbox Group */
        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
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

        /* Selected Count Badge */
        .selected-count {
            background: var(--primary-color);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            margin-left: 12px;
        }

        @media (min-width: 768px) {
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

        @media (max-width: 767px) {
            .main-content {
                padding-top: 70px;
            }

            .checkbox-group {
                grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            }

            .action-buttons {
                flex-wrap: wrap;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
                <div class="logo-text">
                    <h3><?php echo htmlspecialchars($school_name); ?></h3>
                    <p>Admin Panel</p>
                </div>
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
            <li><a href="manage-subjects.php" class="active"><i class="fas fa-book"></i> Manage Subjects</a></li>
            <li><a href="manage-classes.php"><i class="fas fa-layer-group"></i> Manage Classes</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="attendance.php"><i class="fas fa-calendar-check"></i> Attendance Reports</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="sync.php"><i class="fas fa-cloud-upload-alt"></i> Sync to Cloud</a></li>
            <li><a href="../gos/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1>Manage Subjects</h1>
                <p>Add subjects from the national curriculum or manage existing ones</p>
            </div>
            <button class="btn btn-primary" id="openSubjectModalBtn"><i class="fas fa-plus-circle"></i> Add Subjects</button>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Subjects List -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-book"></i> Your School Subjects (<?php echo count($subjects); ?>)</h2>
                <div class="search-box" style="width: 250px; margin: 0;">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Search subjects...">
                </div>
            </div>

            <?php if (empty($subjects)): ?>
                <div style="text-align: center; padding: 50px; color: var(--gray-600);">
                    <i class="fas fa-book-open" style="font-size: 48px; margin-bottom: 15px; color: var(--gray-300);"></i>
                    <h3>No Subjects Found</h3>
                    <p>Click "Add Subjects" to add subjects from the national curriculum.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="data-table" id="subjectsTable">
                        <thead>
                            <tr>
                                <th>Subject Name</th>
                                <th>Description</th>
                                <th>Assigned Classes</th>
                                <th>Usage Stats</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $subject): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($subject['subject_name']); ?></strong></td>
                                    <td><?php echo $subject['description'] ? htmlspecialchars(substr($subject['description'], 0, 60)) . (strlen($subject['description']) > 60 ? '...' : '') : '<span style="color: #999;">—</span>'; ?></td>
                                    <td>
                                        <?php if ($subject['assigned_classes']): ?>
                                            <?php foreach (explode(',', $subject['assigned_classes']) as $class): ?>
                                                <span class="class-tag"><?php echo htmlspecialchars($class); ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span style="color: #999;">Not assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                            <span class="count-badge objective" title="Objective Questions">📝 O: <?php echo $subject['objective_count']; ?></span>
                                            <span class="count-badge subjective" title="Subjective Questions">✍️ S: <?php echo $subject['subjective_count']; ?></span>
                                            <span class="count-badge theory" title="Theory Questions">📄 T: <?php echo $subject['theory_count']; ?></span>
                                            <span class="count-badge exam" title="Exams">📚 E: <?php echo $subject['exam_count']; ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="?edit=<?php echo $subject['id']; ?>" class="action-btn edit-btn" title="Edit"><i class="fas fa-edit"></i></a>
                                            <a href="manage-topics.php?subject_id=<?php echo $subject['id']; ?>" class="action-btn topics-btn" title="Topics"><i class="fas fa-tags"></i></a>
                                            <a href="manage-questions.php?subject_id=<?php echo $subject['id']; ?>" class="action-btn questions-btn" title="Questions"><i class="fas fa-question-circle"></i></a>
                                            <?php $has_deps = ($subject['objective_count'] + $subject['subjective_count'] + $subject['theory_count'] + $subject['exam_count']) > 0; ?>
                                            <?php if ($has_deps): ?>
                                                <button class="action-btn delete-btn disabled-btn" disabled title="Has dependencies"><i class="fas fa-trash"></i></button>
                                            <?php else: ?>
                                                <button class="action-btn delete-btn" onclick="confirmDelete(<?php echo $subject['id']; ?>, '<?php echo addslashes($subject['subject_name']); ?>')" title="Delete"><i class="fas fa-trash"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Edit Subject Section -->
        <?php if ($edit_subject): ?>
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-edit"></i> Edit Subject: <?php echo htmlspecialchars($edit_subject['subject_name']); ?></h2>
                    <a href="manage-subjects.php" class="btn btn-outline"><i class="fas fa-times"></i> Cancel</a>
                </div>
                <form method="POST">
                    <input type="hidden" name="subject_id" value="<?php echo $edit_subject['id']; ?>">
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label class="form-label">Subject Name</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($edit_subject['subject_name']); ?>" readonly disabled>
                        <small style="color: var(--gray-600);">Subject names are fixed from the national curriculum.</small>
                    </div>
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Optional subject description"><?php echo htmlspecialchars($edit_subject['description'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Assign to Classes</label>
                        <div class="checkbox-group" id="editClassCheckboxGroup">
                            <?php foreach ($available_classes as $class): ?>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="classes[]" value="<?php echo htmlspecialchars($class); ?>" id="edit_class_<?php echo preg_replace('/[^a-zA-Z0-9]/', '_', $class); ?>" <?php echo in_array($class, $edit_subject['assigned_classes_array']) ? 'checked' : ''; ?>>
                                    <label for="edit_class_<?php echo preg_replace('/[^a-zA-Z0-9]/', '_', $class); ?>"><?php echo htmlspecialchars($class); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top: 12px;">
                            <button type="button" class="btn btn-sm btn-outline" onclick="selectAllEditClasses()"><i class="fas fa-check-double"></i> Select All</button>
                            <button type="button" class="btn btn-sm btn-outline" onclick="deselectAllEditClasses()"><i class="fas fa-times"></i> Deselect All</button>
                        </div>
                    </div>
                    <div style="margin-top: 24px; display: flex; gap: 12px;">
                        <button type="submit" name="update_subject" class="btn btn-primary"><i class="fas fa-save"></i> Update Subject</button>
                        <a href="manage-subjects.php" class="btn btn-outline">Cancel</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add Subjects Modal -->
    <div class="modal" id="addSubjectsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Add Subjects from National Curriculum</h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" id="addSubjectsForm">
                <div class="modal-body">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="subjectSearchInput" placeholder="Search subjects...">
                    </div>
                    <div class="subjects-list" id="subjectsList">
                        <?php foreach ($available_subjects as $subject): ?>
                            <div class="subject-item" data-subject-id="<?php echo $subject['id']; ?>" data-subject-name="<?php echo htmlspecialchars(strtolower($subject['subject_name'])); ?>">
                                <input type="checkbox" name="central_subject_ids[]" value="<?php echo $subject['id']; ?>" id="subj_<?php echo $subject['id']; ?>" class="subject-checkbox" onchange="updateSelectedCount()">
                                <div class="subject-info">
                                    <div class="subject-name"><?php echo htmlspecialchars($subject['subject_name']); ?></div>
                                    <?php if ($subject['description']): ?>
                                        <div class="subject-desc"><?php echo htmlspecialchars(substr($subject['description'], 0, 100)); ?></div>
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
                        <span id="modalSelectedCount" class="selected-count">0 selected</span>
                    </div>

                    <div class="form-group" style="margin-top: 20px;">
                        <label class="form-label">Assign to Classes <span style="font-weight: normal; color: var(--gray-600);">(Select which classes will offer these subjects)</span></label>
                        <div class="checkbox-group" id="modalClassCheckboxGroup">
                            <?php if (!empty($available_classes)): ?>
                                <?php foreach ($available_classes as $class): ?>
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="classes[]" value="<?php echo htmlspecialchars($class); ?>" id="modal_class_<?php echo preg_replace('/[^a-zA-Z0-9]/', '_', $class); ?>">
                                        <label for="modal_class_<?php echo preg_replace('/[^a-zA-Z0-9]/', '_', $class); ?>"><?php echo htmlspecialchars($class); ?></label>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p style="color: var(--gray-600); grid-column: span 2;">No classes found. Add students first.</p>
                            <?php endif; ?>
                        </div>
                        <div style="margin-top: 10px;">
                            <button type="button" class="btn btn-sm btn-outline" onclick="selectAllModalClasses()"><i class="fas fa-check-double"></i> Select All Classes</button>
                            <button type="button" class="btn btn-sm btn-outline" onclick="deselectAllModalClasses()"><i class="fas fa-times"></i> Deselect All Classes</button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                    <button type="submit" name="add_multiple_subjects" class="btn btn-primary" id="addSubjectsBtn"><i class="fas fa-plus-circle"></i> Add Selected Subjects</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content" style="max-width: 450px;">
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
        // Mobile menu
        const mobileBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');

        function toggleSidebar() {
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        }
        if (mobileBtn) mobileBtn.onclick = toggleSidebar;
        if (overlay) overlay.onclick = toggleSidebar;

        window.addEventListener('resize', () => {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });

        // Table search
        const searchInput = document.getElementById('searchInput');
        const subjectsTable = document.getElementById('subjectsTable');
        if (searchInput && subjectsTable) {
            searchInput.addEventListener('keyup', function() {
                const term = this.value.toLowerCase();
                const rows = subjectsTable.querySelectorAll('tbody tr');
                rows.forEach(row => {
                    row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
                });
            });
        }

        // Modal handling
        const modal = document.getElementById('addSubjectsModal');
        const openBtn = document.getElementById('openSubjectModalBtn');
        const addForm = document.getElementById('addSubjectsForm');

        if (openBtn) {
            openBtn.onclick = () => modal.classList.add('active');
        }

        function closeModal() {
            modal.classList.remove('active');
        }

        // Close on outside click
        window.onclick = (e) => {
            if (e.target === modal) closeModal();
            if (e.target === deleteModal) closeDeleteModal();
        };

        // Subject search in modal
        const subjectSearch = document.getElementById('subjectSearchInput');
        const subjectItems = document.querySelectorAll('.subject-item');

        if (subjectSearch) {
            subjectSearch.addEventListener('keyup', function() {
                const term = this.value.toLowerCase();
                subjectItems.forEach(item => {
                    const name = item.getAttribute('data-subject-name') || '';
                    item.style.display = name.includes(term) ? 'flex' : 'none';
                });
            });
        }

        // Selection functions
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

        function selectAllEditClasses() {
            const checkboxes = document.querySelectorAll('#editClassCheckboxGroup input[type="checkbox"]');
            checkboxes.forEach(cb => cb.checked = true);
        }

        function deselectAllEditClasses() {
            const checkboxes = document.querySelectorAll('#editClassCheckboxGroup input[type="checkbox"]');
            checkboxes.forEach(cb => cb.checked = false);
        }

        // Delete modal
        let deleteId = null;
        const deleteModal = document.getElementById('deleteModal');

        function confirmDelete(id, name) {
            deleteId = id;
            document.getElementById('deleteSubjectName').textContent = name;
            deleteModal.classList.add('active');
        }

        function closeDeleteModal() {
            deleteModal.classList.remove('active');
            deleteId = null;
        }

        const confirmBtn = document.getElementById('confirmDeleteBtn');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', () => {
                if (deleteId) window.location.href = `manage-subjects.php?delete=${deleteId}`;
            });
        }

        // Initialize selected count
        updateSelectedCount();

        // Auto-hide alerts
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
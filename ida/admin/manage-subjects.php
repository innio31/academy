<?php
// admin/manage-subjects.php - Complete Subject Management with Multi-School Support
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

// ============================================
// ENSURE TABLES EXIST
// ============================================

try {
    // Check if subject_classes table exists
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
} catch (Exception $e) {
    error_log("Table check error: " . $e->getMessage());
}

// ============================================
// HANDLE FORM SUBMISSIONS
// ============================================

$message = '';
$message_type = '';

// Add new subject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_subject'])) {
    try {
        $subject_name = trim($_POST['subject_name']);
        $description = trim($_POST['description'] ?? '');
        $classes = $_POST['classes'] ?? [];

        if (empty($subject_name)) {
            throw new Exception("Subject name is required");
        }

        // Check if subject already exists for this school
        $stmt = $pdo->prepare("SELECT id FROM subjects WHERE subject_name = ? AND school_id = ?");
        $stmt->execute([$subject_name, $school_id]);
        if ($stmt->fetch()) {
            throw new Exception("Subject already exists for your school");
        }

        // Insert subject
        $stmt = $pdo->prepare("INSERT INTO subjects (subject_name, description, school_id, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$subject_name, $description, $school_id]);
        $subject_id = $pdo->lastInsertId();

        // Insert class associations
        foreach ($classes as $class) {
            if (!empty($class)) {
                $stmt = $pdo->prepare("INSERT INTO subject_classes (subject_id, class, school_id) VALUES (?, ?, ?)");
                $stmt->execute([$subject_id, $class, $school_id]);
            }
        }

        $message = "Subject added successfully";
        $message_type = "success";
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Update subject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_subject'])) {
    try {
        $subject_id = $_POST['subject_id'];
        $subject_name = trim($_POST['subject_name']);
        $description = trim($_POST['description'] ?? '');
        $classes = $_POST['classes'] ?? [];

        if (empty($subject_name)) {
            throw new Exception("Subject name is required");
        }

        // Verify subject belongs to this school
        $stmt = $pdo->prepare("SELECT id FROM subjects WHERE id = ? AND school_id = ?");
        $stmt->execute([$subject_id, $school_id]);
        if (!$stmt->fetch()) {
            throw new Exception("Subject not found or access denied");
        }

        // Check if subject name already exists (excluding current)
        $stmt = $pdo->prepare("SELECT id FROM subjects WHERE subject_name = ? AND school_id = ? AND id != ?");
        $stmt->execute([$subject_name, $school_id, $subject_id]);
        if ($stmt->fetch()) {
            throw new Exception("Subject name already exists");
        }

        // Update subject
        $stmt = $pdo->prepare("UPDATE subjects SET subject_name = ?, description = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$subject_name, $description, $subject_id]);

        // Update class associations
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

        // Verify subject belongs to this school
        $stmt = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ? AND school_id = ?");
        $stmt->execute([$subject_id, $school_id]);
        $subject = $stmt->fetch();

        if (!$subject) {
            throw new Exception("Subject not found or access denied");
        }

        // Check for dependencies
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM objective_questions WHERE subject_id = ?");
        $stmt->execute([$subject_id]);
        $objective_count = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM subjective_questions WHERE subject_id = ?");
        $stmt->execute([$subject_id]);
        $subjective_count = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM theory_questions WHERE subject_id = ?");
        $stmt->execute([$subject_id]);
        $theory_count = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM topics WHERE subject_id = ?");
        $stmt->execute([$subject_id]);
        $topic_count = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM exams WHERE subject_id = ?");
        $stmt->execute([$subject_id]);
        $exam_count = $stmt->fetchColumn();

        $total_questions = $objective_count + $subjective_count + $theory_count;

        if ($total_questions > 0 || $topic_count > 0 || $exam_count > 0) {
            throw new Exception("Cannot delete subject. It has $objective_count objective, $subjective_count subjective, $theory_count theory questions, $topic_count topics, and $exam_count exams.");
        }

        // Delete subject (cascade will handle subject_classes)
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

// Fetch all subjects for this school
$stmt = $pdo->prepare("
    SELECT s.*, 
           GROUP_CONCAT(DISTINCT sc.class ORDER BY sc.class) as assigned_classes,
           (SELECT COUNT(*) FROM objective_questions WHERE subject_id = s.id) as objective_count,
           (SELECT COUNT(*) FROM subjective_questions WHERE subject_id = s.id) as subjective_count,
           (SELECT COUNT(*) FROM theory_questions WHERE subject_id = s.id) as theory_count,
           (SELECT COUNT(*) FROM exams WHERE subject_id = s.id) as exam_count,
           (SELECT COUNT(*) FROM topics WHERE subject_id = s.id) as topic_count
    FROM subjects s
    LEFT JOIN subject_classes sc ON s.id = sc.subject_id AND sc.school_id = s.school_id
    WHERE s.school_id = ?
    GROUP BY s.id
    ORDER BY s.subject_name
");
$stmt->execute([$school_id]);
$subjects = $stmt->fetchAll();

// Fetch all classes from students (for assignment)
$stmt = $pdo->prepare("SELECT DISTINCT class FROM students WHERE school_id = ? AND class IS NOT NULL AND class != '' ORDER BY class");
$stmt->execute([$school_id]);
$classes_from_students = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Also get classes from exams
$stmt = $pdo->prepare("SELECT DISTINCT class FROM exams WHERE school_id = ? AND class IS NOT NULL AND class != '' ORDER BY class");
$stmt->execute([$school_id]);
$classes_from_exams = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Merge unique classes
$available_classes = array_unique(array_merge($classes_from_students, $classes_from_exams));

// If still empty, provide default classes
if (empty($available_classes)) {
    $available_classes = ['Nursery 1', 'Nursery 2', 'Basic 1', 'Basic 2', 'Basic 3', 'Basic 4', 'Basic 5', 'JSS 1', 'JSS 2', 'JSS 3', 'SS 1', 'SS 2', 'SS 3'];
}

// Fetch subject for editing
$edit_subject = null;
if (isset($_GET['edit'])) {
    $subject_id = $_GET['edit'];
    $stmt = $pdo->prepare("
        SELECT s.*, 
               GROUP_CONCAT(DISTINCT sc.class) as assigned_classes
        FROM subjects s
        LEFT JOIN subject_classes sc ON s.id = sc.subject_id AND sc.school_id = s.school_id
        WHERE s.id = ? AND s.school_id = ?
        GROUP BY s.id
    ");
    $stmt->execute([$subject_id, $school_id]);
    $edit_subject = $stmt->fetch();

    if ($edit_subject && $edit_subject['assigned_classes']) {
        $edit_subject['assigned_classes_array'] = explode(',', $edit_subject['assigned_classes']);
    } else {
        $edit_subject['assigned_classes_array'] = [];
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Manage Subjects</title>

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
            font-family: 'Poppins', sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        /* Checkbox Group */
        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 12px;
            margin-top: 10px;
            max-height: 250px;
            overflow-y: auto;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary-color);
        }

        .checkbox-item label {
            margin: 0;
            cursor: pointer;
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

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.85rem;
        }

        /* Table */
        .table-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            overflow-x: auto;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .search-box {
            position: relative;
            width: 250px;
        }

        .search-box input {
            width: 100%;
            padding: 10px 15px 10px 40px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
        }

        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
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

        .count-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            padding: 0 8px;
            border-radius: 20px;
            font-size: 0.75rem;
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

        .count-badge.topic {
            background: #fce4ec;
            color: #c2185b;
        }

        .class-tag {
            display: inline-block;
            background: #e3f2fd;
            color: #1976d2;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
            margin: 2px;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .edit-btn {
            background: #3498db;
            color: white;
        }

        .delete-btn {
            background: #e74c3c;
            color: white;
        }

        .topics-btn {
            background: #9b59b6;
            color: white;
        }

        .questions-btn {
            background: #27ae60;
            color: white;
        }

        .disabled-btn {
            background: #bdc3c7;
            cursor: not-allowed;
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
            max-width: 450px;
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

        .empty-state {
            text-align: center;
            padding: 50px;
            color: #999;
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
            .checkbox-group {
                grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            }

            .table-header {
                flex-direction: column;
                align-items: stretch;
            }

            .search-box {
                width: 100%;
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
            <li><a href="manage-subjects.php" class="active"><i class="fas fa-book"></i> Manage Subjects</a></li>
            <li><a href="manage-classes.php"><i class="fas fa-book"></i> Manage Classes</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="attendance.php"><i class="fas fa-calendar-check"></i> Attendance Reports</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="sync.php"><i class="fas fa-sync-alt"></i> Sync to Cloud</a></li>
            <li><a href="/ida/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1>Manage Subjects</h1>
                <p>Add, edit, and manage subjects for your school</p>
            </div>
            <button class="logout-btn" onclick="window.location.href='/ida/logout.php'">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Add/Edit Subject Form -->
        <div class="form-container">
            <div class="form-header">
                <h3><?php echo $edit_subject ? 'Edit Subject' : 'Add New Subject'; ?></h3>
                <?php if ($edit_subject): ?>
                    <a href="manage-subjects.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel Edit</a>
                <?php endif; ?>
            </div>

            <form method="POST">
                <?php if ($edit_subject): ?>
                    <input type="hidden" name="subject_id" value="<?php echo $edit_subject['id']; ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label>Subject Name *</label>
                    <input type="text" name="subject_name" class="form-control"
                        value="<?php echo $edit_subject ? htmlspecialchars($edit_subject['subject_name']) : ''; ?>"
                        placeholder="e.g., Mathematics, English, Basic Science" required>
                </div>

                <div class="form-group">
                    <label>Description (Optional)</label>
                    <textarea name="description" class="form-control"
                        placeholder="Brief description of the subject"><?php echo $edit_subject ? htmlspecialchars($edit_subject['description'] ?? '') : ''; ?></textarea>
                </div>

                <div class="form-group">
                    <label>Assign to Classes <span style="font-weight: normal; color: #666;">(Select which classes offer this subject)</span></label>
                    <div class="checkbox-group">
                        <?php if (!empty($available_classes)): ?>
                            <?php foreach ($available_classes as $class): ?>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="classes[]" value="<?php echo htmlspecialchars($class); ?>"
                                        id="class_<?php echo preg_replace('/[^a-zA-Z0-9]/', '_', $class); ?>"
                                        <?php echo $edit_subject && in_array($class, $edit_subject['assigned_classes_array']) ? 'checked' : ''; ?>>
                                    <label for="class_<?php echo preg_replace('/[^a-zA-Z0-9]/', '_', $class); ?>">
                                        <?php echo htmlspecialchars($class); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="color: #999; grid-column: span 2;">No classes found. Add students or create exams with classes first.</p>
                        <?php endif; ?>
                    </div>
                    <small style="color: #666; display: block; margin-top: 8px;">
                        <i class="fas fa-info-circle"></i> Selecting classes helps organize subjects by class level
                    </small>
                </div>

                <div class="form-actions" style="margin-top: 20px;">
                    <?php if ($edit_subject): ?>
                        <button type="submit" name="update_subject" class="btn btn-success">
                            <i class="fas fa-save"></i> Update Subject
                        </button>
                    <?php else: ?>
                        <button type="submit" name="add_subject" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add Subject
                        </button>
                    <?php endif; ?>
                    <button type="reset" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> Reset
                    </button>
                </div>
            </form>
        </div>

        <!-- Subjects List -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-book"></i> All Subjects (<?php echo count($subjects); ?>)</h3>
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Search subjects...">
                </div>
            </div>

            <?php if (empty($subjects)): ?>
                <div class="empty-state">
                    <i class="fas fa-book-open" style="font-size: 48px; margin-bottom: 15px; color: #ccc;"></i>
                    <h3>No Subjects Found</h3>
                    <p>Add your first subject using the form above.</p>
                </div>
            <?php else: ?>
                <table class="data-table" id="subjectsTable">
                    <thead>
                        <tr>
                            <th>Subject Name</th>
                            <th>Description</th>
                            <th>Assigned Classes</th>
                            <th>Usage Stats</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subjects as $subject): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($subject['subject_name']); ?></strong>
                                    <?php if ($edit_subject && $edit_subject['id'] == $subject['id']): ?>
                                        <span style="color: var(--primary-color); font-size: 0.7rem;">(Editing)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $desc = $subject['description'] ?? '';
                                    echo $desc ? htmlspecialchars(substr($desc, 0, 60)) . (strlen($desc) > 60 ? '...' : '') : '<span style="color: #999;">—</span>';
                                    ?>
                                </td>
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
                                        <span class="count-badge topic" title="Topics">🏷️ TP: <?php echo $subject['topic_count']; ?></span>
                                    </div>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($subject['created_at'])); ?> </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?edit=<?php echo $subject['id']; ?>" class="action-btn edit-btn" title="Edit Subject">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="manage-topics.php?subject_id=<?php echo $subject['id']; ?>" class="action-btn topics-btn" title="Manage Topics">
                                            <i class="fas fa-tags"></i>
                                        </a>
                                        <a href="manage_questions.php?subject_id=<?php echo $subject['id']; ?>" class="action-btn questions-btn" title="Manage Questions">
                                            <i class="fas fa-question-circle"></i>
                                        </a>
                                        <?php
                                        $has_dependencies = ($subject['objective_count'] + $subject['subjective_count'] + $subject['theory_count'] + $subject['exam_count'] + $subject['topic_count']) > 0;
                                        ?>
                                        <?php if ($has_dependencies): ?>
                                            <button class="action-btn delete-btn disabled-btn" disabled title="Cannot delete - has associated questions/exams/topics">
                                                <i class="fas fa-trash"></i> Locked
                                            </button>
                                        <?php else: ?>
                                            <button class="action-btn delete-btn" onclick="confirmDelete(<?php echo $subject['id']; ?>, '<?php echo addslashes($subject['subject_name']); ?>')" title="Delete Subject">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirm Delete</h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="deleteSubjectName"></strong>?</p>
                <p style="color: var(--danger-color); margin-top: 10px;">
                    <i class="fas fa-exclamation-triangle"></i> This action cannot be undone.
                </p>
            </div>
            <div class="modal-footer" style="padding: 15px 20px; display: flex; justify-content: flex-end; gap: 10px;">
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btn btn-danger" id="confirmDeleteBtn">Delete Subject</button>
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

        // Close sidebar on outside click
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768 && sidebar && mobileBtn) {
                if (!sidebar.contains(e.target) && !mobileBtn.contains(e.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // Search functionality
        const searchInput = document.getElementById('searchInput');
        const subjectsTable = document.getElementById('subjectsTable');
        if (searchInput && subjectsTable) {
            searchInput.addEventListener('keyup', function() {
                const term = this.value.toLowerCase();
                const rows = subjectsTable.querySelectorAll('tbody tr');
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(term) ? '' : 'none';
                });
            });
        }

        // Delete modal
        let deleteId = null;
        let deleteName = '';

        function confirmDelete(id, name) {
            deleteId = id;
            deleteName = name;
            document.getElementById('deleteSubjectName').textContent = name;
            document.getElementById('deleteModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('deleteModal').classList.remove('active');
            deleteId = null;
        }

        document.getElementById('confirmDeleteBtn')?.addEventListener('click', () => {
            if (deleteId) {
                window.location.href = `manage-subjects.php?delete=${deleteId}`;
            }
        });

        // Close modal with Escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        // Auto-hide alerts
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
<?php
// admin/manage-staff.php - Complete Staff Management with Attendance, Performance, and QR Tracking
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
// ADD MISSING TABLES IF NOT EXISTS
// ============================================

$pdo->exec("
CREATE TABLE IF NOT EXISTS staff_attendance (
    id INT PRIMARY KEY AUTO_INCREMENT,
    staff_id VARCHAR(50) NOT NULL,
    school_id INT NOT NULL,
    date DATE NOT NULL,
    clock_in TIME,
    clock_out TIME,
    status ENUM('present', 'absent', 'late', 'half_day') DEFAULT 'present',
    late_minutes INT DEFAULT 0,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_staff_date (staff_id, date),
    INDEX idx_school_id (school_id)
);

CREATE TABLE IF NOT EXISTS staff_performance (
    id INT PRIMARY KEY AUTO_INCREMENT,
    staff_id VARCHAR(50) NOT NULL,
    school_id INT NOT NULL,
    month VARCHAR(7) NOT NULL,
    punctuality_score DECIMAL(3,2) DEFAULT 0,
    task_completion_score DECIMAL(3,2) DEFAULT 0,
    student_feedback_score DECIMAL(3,2) DEFAULT 0,
    overall_rating DECIMAL(3,2) DEFAULT 0,
    reviewer_id INT,
    comments TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_staff_month (staff_id, month)
);

CREATE TABLE IF NOT EXISTS class_qr_codes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    class_name VARCHAR(50) NOT NULL,
    school_id INT NOT NULL,
    qr_code TEXT,
    session_scan VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_class_school (class_name, school_id)
);

CREATE TABLE IF NOT EXISTS class_attendance (
    id INT PRIMARY KEY AUTO_INCREMENT,
    class_name VARCHAR(50) NOT NULL,
    school_id INT NOT NULL,
    staff_id VARCHAR(50) NOT NULL,
    date DATE NOT NULL,
    time_in TIME,
    time_out TIME,
    duration_minutes INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_class_date (class_name, date)
);
");

// ============================================
// STAFF ATTENDANCE FUNCTIONS
// ============================================

// Clock In/Out for Staff
if (isset($_POST['clock_action'])) {
    $staff_id = $_POST['staff_id'];
    $action = $_POST['clock_action'];
    $current_time = date('H:i:s');
    $current_date = date('Y-m-d');

    if ($action === 'clock_in') {
        $stmt = $pdo->prepare("INSERT INTO staff_attendance (staff_id, school_id, date, clock_in, status) VALUES (?, ?, ?, ?, 'present')");
        $stmt->execute([$staff_id, $school_id, $current_date, $current_time]);
        header("Location: manage-staff.php?action=attendance&message=Clocked+in+successfully&type=success");
    } elseif ($action === 'clock_out') {
        $stmt = $pdo->prepare("UPDATE staff_attendance SET clock_out = ? WHERE staff_id = ? AND date = ? AND school_id = ?");
        $stmt->execute([$current_time, $staff_id, $current_date, $school_id]);
        header("Location: manage-staff.php?action=attendance&message=Clocked+out+successfully&type=success");
    }
    exit();
}

// Mark Late
if (isset($_POST['mark_late'])) {
    $staff_id = $_POST['staff_id'];
    $late_minutes = $_POST['late_minutes'];
    $current_date = date('Y-m-d');

    $stmt = $pdo->prepare("UPDATE staff_attendance SET status = 'late', late_minutes = ? WHERE staff_id = ? AND date = ? AND school_id = ?");
    $stmt->execute([$late_minutes, $staff_id, $current_date, $school_id]);
    header("Location: manage-staff.php?action=attendance&message=Marked+as+late&type=success");
    exit();
}

// Generate QR for Class
if (isset($_POST['generate_class_qr'])) {
    $class_name = $_POST['class_name'];
    $session_scan = $_POST['session_scan'];

    $qr_data = json_encode([
        'class' => $class_name,
        'school_id' => $school_id,
        'type' => 'class_attendance',
        'timestamp' => time()
    ]);
    $qr_url = "https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=" . urlencode($qr_data);

    $stmt = $pdo->prepare("INSERT INTO class_qr_codes (class_name, school_id, qr_code, session_scan) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE qr_code = ?, session_scan = ?");
    $stmt->execute([$class_name, $school_id, $qr_url, $session_scan, $qr_url, $session_scan]);

    header("Location: manage-staff.php?action=class_attendance&message=QR+generated+for+$class_name&type=success");
    exit();
}

// Record Class Attendance from QR Scan
if (isset($_POST['record_class_attendance'])) {
    $class_name = $_POST['class_name'];
    $staff_id = $_POST['staff_id'];
    $time_in = date('H:i:s');
    $current_date = date('Y-m-d');

    $stmt = $pdo->prepare("INSERT INTO class_attendance (class_name, school_id, staff_id, date, time_in) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$class_name, $school_id, $staff_id, $current_date, $time_in]);

    header("Location: manage-staff.php?action=class_attendance&message=Class+attendance+recorded&type=success");
    exit();
}

// Rate Staff Performance
if (isset($_POST['rate_staff'])) {
    $staff_id = $_POST['staff_id'];
    $month = $_POST['month'];
    $punctuality = $_POST['punctuality_score'];
    $task_completion = $_POST['task_completion_score'];
    $student_feedback = $_POST['student_feedback_score'];
    $comments = $_POST['comments'];

    $overall = ($punctuality + $task_completion + $student_feedback) / 3;

    $stmt = $pdo->prepare("INSERT INTO staff_performance (staff_id, school_id, month, punctuality_score, task_completion_score, student_feedback_score, overall_rating, reviewer_id, comments) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) 
                           ON DUPLICATE KEY UPDATE 
                           punctuality_score = ?, task_completion_score = ?, student_feedback_score = ?, overall_rating = ?, comments = ?");
    $stmt->execute([
        $staff_id,
        $school_id,
        $month,
        $punctuality,
        $task_completion,
        $student_feedback,
        $overall,
        $admin_id,
        $comments,
        $punctuality,
        $task_completion,
        $student_feedback,
        $overall,
        $comments
    ]);

    header("Location: manage-staff.php?action=performance&message=Performance+rated+successfully&type=success");
    exit();
}

// ============================================
// STAFF CRUD OPERATIONS
// ============================================

// Add Staff
if (isset($_POST['add_staff'])) {
    $staff_id_num = trim($_POST['staff_id']);
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']) ?: null;
    $role = $_POST['role'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $password = $_POST['password'];

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("INSERT INTO staff (staff_id, password, full_name, email, role, is_active, school_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$staff_id_num, $hashed_password, $full_name, $email, $role, $is_active, $school_id]);

    header("Location: manage-staff.php?action=list&message=Staff+added+successfully&type=success");
    exit();
}

// Update Staff
if (isset($_POST['edit_staff'])) {
    $staff_id = $_POST['id'];
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']) ?: null;
    $role = $_POST['role'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    $sql = "UPDATE staff SET full_name = ?, email = ?, role = ?, is_active = ? WHERE id = ? AND school_id = ?";
    $params = [$full_name, $email, $role, $is_active, $staff_id, $school_id];

    if (isset($_POST['change_password']) && !empty($_POST['password'])) {
        $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $sql = "UPDATE staff SET full_name = ?, email = ?, role = ?, is_active = ?, password = ? WHERE id = ? AND school_id = ?";
        $params = [$full_name, $email, $role, $is_active, $hashed_password, $staff_id, $school_id];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    header("Location: manage-staff.php?action=list&message=Staff+updated+successfully&type=success");
    exit();
}

// Delete Staff
if (isset($_GET['delete']) && $admin_role === 'super_admin') {
    $staff_id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM staff WHERE id = ? AND school_id = ?");
    $stmt->execute([$staff_id, $school_id]);
    header("Location: manage-staff.php?action=list&message=Staff+deleted+successfully&type=success");
    exit();
}

// Assign Subjects
if (isset($_POST['assign_subjects'])) {
    $staff_id_num = $_POST['staff_id'];
    $subjects = $_POST['subjects'] ?? [];

    // Get staff_id string
    $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ?");
    $stmt->execute([$staff_id_num]);
    $staff_id_string = $stmt->fetchColumn();

    // Remove existing
    $pdo->prepare("DELETE FROM staff_subjects WHERE staff_id = ? AND school_id = ?")->execute([$staff_id_string, $school_id]);

    // Add new
    foreach ($subjects as $subject_id) {
        $stmt = $pdo->prepare("INSERT INTO staff_subjects (staff_id, subject_id, school_id, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$staff_id_string, $subject_id, $school_id]);
    }

    header("Location: manage-staff.php?action=assign_subjects&id=$staff_id_num&message=Subjects+assigned+successfully&type=success");
    exit();
}

// Assign Classes
if (isset($_POST['assign_classes'])) {
    $staff_id_num = $_POST['staff_id'];
    $classes = $_POST['classes'] ?? [];

    $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ?");
    $stmt->execute([$staff_id_num]);
    $staff_id_string = $stmt->fetchColumn();

    $pdo->prepare("DELETE FROM staff_classes WHERE staff_id = ? AND school_id = ?")->execute([$staff_id_string, $school_id]);

    foreach ($classes as $class) {
        $stmt = $pdo->prepare("INSERT INTO staff_classes (staff_id, class, school_id, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$staff_id_string, $class, $school_id]);
    }

    header("Location: manage-staff.php?action=assign_classes&id=$staff_id_num&message=Classes+assigned+successfully&type=success");
    exit();
}

// Get data for editing
$action = $_GET['action'] ?? 'list';
$staff = null;
$assigned_subjects = [];
$assigned_classes = [];

if (in_array($action, ['edit', 'assign_subjects', 'assign_classes', 'view', 'attendance', 'performance', 'class_attendance']) && isset($_GET['id'])) {
    $staff_id_num = $_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM staff WHERE id = ? AND school_id = ?");
    $stmt->execute([$staff_id_num, $school_id]);
    $staff = $stmt->fetch();

    if ($staff) {
        $stmt = $pdo->prepare("SELECT subject_id FROM staff_subjects WHERE staff_id = ?");
        $stmt->execute([$staff['staff_id']]);
        $assigned_subject_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $stmt = $pdo->prepare("SELECT class FROM staff_classes WHERE staff_id = ?");
        $stmt->execute([$staff['staff_id']]);
        $assigned_class_names = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

// Get all subjects and classes
$all_subjects = $pdo->query("SELECT * FROM subjects ORDER BY subject_name")->fetchAll();
$all_classes = $pdo->query("SELECT DISTINCT class FROM students WHERE class != '' ORDER BY class")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $school_name; ?> - Manage Staff</title>

    <!-- QR Scanner Library -->
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

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

        /* Sidebar - Mobile First */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-color), var(--dark-color));
            color: white;
            padding: 20px 0;
            transition: transform 0.3s ease;
            z-index: 1000;
            overflow-y: auto;
            transform: translateX(-100%);
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }

        .sidebar.active {
            transform: translateX(0);
        }

        /* Sidebar overlay for mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        .sidebar-overlay.active {
            display: block;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 20px;
            margin-bottom: 15px;
            flex-wrap: wrap;
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

        .logo-text h3 {
            font-size: 1rem;
            margin-bottom: 2px;
        }

        .logo-text p {
            font-size: 0.7rem;
            opacity: 0.8;
        }

        .admin-info {
            text-align: center;
            padding: 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            margin: 0 15px 20px;
        }

        .admin-info h4 {
            font-size: 0.9rem;
            margin-bottom: 5px;
        }

        .admin-info p {
            font-size: 0.75rem;
            opacity: 0.8;
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
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255, 255, 255, 0.2);
        }

        .nav-links a i {
            width: 20px;
            font-size: 1rem;
        }

        /* Main Content - Mobile First */
        .main-content {
            margin-left: 0;
            padding: 15px;
            min-height: 100vh;
            padding-bottom: 30px;
        }

        /* Mobile Menu Button */
        .mobile-menu-btn {
            position: fixed;
            top: 15px;
            right: 15px;
            z-index: 1001;
            background: var(--primary-color);
            color: white;
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 10px;
            font-size: 20px;
            cursor: pointer;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Top Header - Mobile Responsive */
        .top-header {
            background: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .top-header>div:first-child {
            order: 2;
        }

        .top-header>div:last-child {
            order: 1;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: space-between;
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .header-title p {
            font-size: 0.8rem;
            color: #666;
        }

        /* Buttons - Mobile Optimized */
        .btn {
            padding: 10px 15px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }

        .btn-sm {
            padding: 6px 10px;
            font-size: 0.75rem;
        }

        .btn-block {
            width: 100%;
            justify-content: center;
        }

        /* Cards and Containers */
        .form-container,
        .filter-container,
        .table-container {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            overflow-x: auto;
        }

        /* Filter Form - Mobile Friendly */
        .filter-form {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .filter-group {
            width: 100%;
        }

        .filter-group .btn {
            width: 100%;
            justify-content: center;
            margin-top: 5px;
        }

        /* Form Groups */
        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--primary-color);
            font-size: 0.85rem;
        }

        .form-control,
        .form-select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.9rem;
        }

        /* Table - Horizontal Scroll on Mobile */
        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        .data-table th,
        .data-table td {
            padding: 10px 8px;
            text-align: left;
            border-bottom: 1px solid #eee;
            font-size: 0.8rem;
        }

        .data-table th {
            background: var(--light-color);
            font-weight: 600;
            white-space: nowrap;
        }

        /* Stats Grid - Mobile Responsive */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-top: 20px;
        }

        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .stat-card div:last-child {
            font-size: 0.8rem;
            color: #666;
        }

        /* Status Badges */
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
            display: inline-block;
            white-space: nowrap;
        }

        /* Alert Messages */
        .alert {
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
        }

        /* Modal - Mobile Optimized */
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
            padding: 15px;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header,
        .modal-footer {
            padding: 15px;
        }

        .modal-body {
            padding: 15px;
        }

        /* Action Buttons Group */
        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        .action-buttons .btn-sm {
            padding: 4px 8px;
            font-size: 0.7rem;
        }

        /* Desktop Styles */
        @media (min-width: 769px) {
            .sidebar {
                transform: translateX(0);
            }

            .main-content {
                margin-left: var(--sidebar-width);
                padding: 20px 30px;
            }

            .mobile-menu-btn {
                display: none;
            }

            .top-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }

            .top-header>div:first-child {
                order: 1;
            }

            .top-header>div:last-child {
                order: 2;
            }

            .filter-form {
                flex-direction: row;
                align-items: flex-end;
            }

            .filter-group {
                flex: 1;
            }

            .filter-group .btn {
                width: auto;
                margin-top: 0;
            }

            .data-table th,
            .data-table td {
                padding: 12px;
                font-size: 0.85rem;
            }

            .stat-value {
                font-size: 2rem;
            }
        }

        /* Small Mobile Adjustments */
        @media (max-width: 480px) {
            .main-content {
                padding: 10px;
            }

            .btn {
                padding: 8px 12px;
                font-size: 0.75rem;
            }

            .header-title h1 {
                font-size: 1.2rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
                gap: 3px;
            }

            .action-buttons .btn-sm {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 768px) {
            .hide-mobile {
                display: none;
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
                <h3><?php echo $school_name; ?></h3>
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
            <li><a href="manage-staff.php" class="active"><i class="fas fa-chalkboard-teacher"></i> Manage Staff</a></li>
            <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Manage Subjects</a></li>
            <li><a href="manage-classes.php"><i class="fas fa-book"></i> Manage Classes</a></li>
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
                <h1>Manage Staff</h1>
                <p>Manage staff, track attendance, assign subjects/classes, and rate performance</p>
            </div>
            <div>
                <a href="manage-staff.php?action=add" class="btn btn-primary"><i class="fas fa-user-plus"></i> Add Staff</a>
                <a href="manage-staff.php?action=attendance" class="btn btn-info"><i class="fas fa-calendar-check"></i> Attendance</a>
                <a href="manage-staff.php?action=class_attendance" class="btn btn-purple"><i class="fas fa-qrcode"></i> Class QR</a>
                <a href="manage-staff.php?action=performance" class="btn btn-warning"><i class="fas fa-star"></i> Performance</a>
            </div>
        </div>

        <?php if (isset($_GET['message'])): ?>
            <div class="alert alert-<?php echo $_GET['type'] ?? 'success'; ?>">
                <i class="fas fa-<?php echo $_GET['type'] === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($_GET['message']); ?>
            </div>
        <?php endif; ?>

        <!-- STAFF LIST VIEW -->
        <?php if ($action === 'list'): ?>
            <div class="filter-container">
                <form method="GET" class="filter-form">
                    <input type="hidden" name="action" value="list">
                    <div class="filter-group"><label class="form-label">Search</label><input type="text" name="search" class="form-control" placeholder="Name, ID, Email" value="<?php echo $_GET['search'] ?? ''; ?>"></div>
                    <div class="filter-group"><label class="form-label">Role</label><select name="role" class="form-select">
                            <option value="">All</option>
                            <option value="staff">Staff</option>
                            <option value="admin">Admin</option>
                        </select></div>
                    <div class="filter-group"><label class="form-label">Status</label><select name="status" class="form-select">
                            <option value="">All</option>
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select></div>
                    <div class="filter-group"><button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button><a href="manage-staff.php?action=list" class="btn btn-warning"><i class="fas fa-redo"></i> Reset</a></div>
                </form>
            </div>

            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $query = "SELECT * FROM staff WHERE school_id = ?";
                        $params = [$school_id];
                        if (!empty($_GET['search'])) {
                            $query .= " AND (full_name LIKE ? OR staff_id LIKE ? OR email LIKE ?)";
                            $s = "%{$_GET['search']}%";
                            array_push($params, $s, $s, $s);
                        }
                        if ($_GET['role'] ?? '') {
                            $query .= " AND role = ?";
                            $params[] = $_GET['role'];
                        }
                        if (isset($_GET['status']) && $_GET['status'] !== '') {
                            $query .= " AND is_active = ?";
                            $params[] = $_GET['status'];
                        }
                        $query .= " ORDER BY created_at DESC";
                        $stmt = $pdo->prepare($query);
                        $stmt->execute($params);
                        $staff_list = $stmt->fetchAll();
                        ?>
                        <?php foreach ($staff_list as $s): ?>
                            <tr>
                                <!-- Replace the actions column in the staff table with this -->
                                <td>
                                    <div class="action-buttons">
                                        <a href="manage-staff.php?action=view&id=<?php echo $s['id']; ?>" class="btn btn-info btn-sm"><i class="fas fa-eye"></i> <span class="hide-mobile">View</span></a>
                                        <a href="manage-staff.php?action=edit&id=<?php echo $s['id']; ?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i> <span class="hide-mobile">Edit</span></a>
                                        <a href="manage-staff.php?action=assign_subjects&id=<?php echo $s['id']; ?>" class="btn btn-primary btn-sm"><i class="fas fa-book"></i> <span class="hide-mobile">Subjects</span></a>
                                        <a href="manage-staff.php?action=assign_classes&id=<?php echo $s['id']; ?>" class="btn btn-purple btn-sm"><i class="fas fa-chalkboard"></i> <span class="hide-mobile">Classes</span></a>
                                        <?php if ($admin_role === 'super_admin'): ?>
                                            <a href="manage-staff.php?delete=<?php echo $s['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this staff?')"><i class="fas fa-trash"></i> <span class="hide-mobile">Delete</span></a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="stats-grid">
                <?php
                $total = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE school_id = ?");
                $total->execute([$school_id]);
                $active = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE school_id = ? AND is_active = 1");
                $active->execute([$school_id]);
                $admin_count = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE school_id = ? AND role = 'admin'");
                $admin_count->execute([$school_id]);
                ?>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $total->fetchColumn(); ?></div>
                    <div>Total Staff</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $active->fetchColumn(); ?></div>
                    <div>Active Staff</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $admin_count->fetchColumn(); ?></div>
                    <div>Administrators</div>
                </div>
            </div>

            <!-- ADD/EDIT STAFF -->
        <?php elseif ($action === 'add' || $action === 'edit'): ?>
            <div class="form-container">
                <h2><?php echo $action === 'add' ? 'Add New Staff' : 'Edit Staff'; ?></h2>
                <form method="POST">
                    <?php if ($action === 'edit'): ?>
                        <input type="hidden" name="id" value="<?php echo $staff['id']; ?>">
                        <input type="hidden" name="edit_staff" value="1">
                    <?php else: ?>
                        <input type="hidden" name="add_staff" value="1">
                    <?php endif; ?>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px,1fr)); gap: 20px;">
                        <div class="form-group"><label class="form-label">Staff ID *</label><input type="text" name="staff_id" class="form-control" value="<?php echo $staff['staff_id'] ?? ''; ?>" <?php echo $action === 'edit' ? 'readonly' : 'required'; ?>></div>
                        <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="full_name" class="form-control" value="<?php echo $staff['full_name'] ?? ''; ?>" required></div>
                        <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?php echo $staff['email'] ?? ''; ?>"></div>
                        <div class="form-group"><label class="form-label">Role</label><select name="role" class="form-select">
                                <option value="staff" <?php echo ($staff['role'] ?? '') === 'staff' ? 'selected' : ''; ?>>Staff</option>
                                <option value="admin" <?php echo ($staff['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Administrator</option>
                            </select></div>
                        <div class="form-group"><label class="form-check"><input type="checkbox" name="is_active" <?php echo ($staff['is_active'] ?? 1) ? 'checked' : ''; ?>> Active Account</label></div>
                    </div>

                    <div style="margin-top: 20px; padding: 20px; background: #f8f9fa; border-radius: 10px;">
                        <h3>Password</h3>
                        <?php if ($action === 'edit'): ?>
                            <label><input type="checkbox" id="change_password" onchange="togglePassword()"> Change Password</label>
                        <?php endif; ?>
                        <div id="password_fields" style="<?php echo $action === 'edit' ? 'display:none;' : ''; ?> margin-top: 15px;">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                <input type="password" name="password" class="form-control" placeholder="Password" <?php echo $action === 'add' ? 'required' : ''; ?>>
                                <input type="password" name="confirm_password" class="form-control" placeholder="Confirm Password" <?php echo $action === 'add' ? 'required' : ''; ?>>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 20px;"><button type="submit" class="btn btn-primary">Save Staff</button> <a href="manage-staff.php?action=list" class="btn btn-warning">Cancel</a></div>
                </form>
            </div>

            <!-- ASSIGN SUBJECTS -->
        <?php elseif ($action === 'assign_subjects' && $staff): ?>
            <div class="form-container">
                <h2>Assign Subjects to <?php echo htmlspecialchars($staff['full_name']); ?></h2>
                <form method="POST">
                    <input type="hidden" name="staff_id" value="<?php echo $staff['id']; ?>">
                    <input type="hidden" name="assign_subjects" value="1">
                    <div class="form-group">
                        <?php foreach ($all_subjects as $subject): ?>
                            <label class="form-check"><input type="checkbox" name="subjects[]" value="<?php echo $subject['id']; ?>" <?php echo in_array($subject['id'], $assigned_subject_ids) ? 'checked' : ''; ?>> <?php echo htmlspecialchars($subject['subject_name']); ?></label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Subjects</button>
                    <a href="manage-staff.php?action=list" class="btn btn-warning">Back</a>
                </form>
            </div>

            <!-- ASSIGN CLASSES -->
        <?php elseif ($action === 'assign_classes' && $staff): ?>
            <div class="form-container">
                <h2>Assign Classes to <?php echo htmlspecialchars($staff['full_name']); ?></h2>
                <form method="POST">
                    <input type="hidden" name="staff_id" value="<?php echo $staff['id']; ?>">
                    <input type="hidden" name="assign_classes" value="1">
                    <div class="form-group">
                        <?php foreach ($all_classes as $class): ?>
                            <label class="form-check"><input type="checkbox" name="classes[]" value="<?php echo htmlspecialchars($class['class']); ?>" <?php echo in_array($class['class'], $assigned_class_names) ? 'checked' : ''; ?>> <?php echo htmlspecialchars($class['class']); ?></label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Classes</button>
                    <a href="manage-staff.php?action=list" class="btn btn-warning">Back</a>
                </form>
            </div>

            <!-- STAFF ATTENDANCE VIEW -->
        <?php elseif ($action === 'attendance'): ?>
            <div class="form-container">
                <h2>Staff Attendance</h2>
                <form method="GET" class="filter-form" style="margin-bottom: 20px;">
                    <input type="hidden" name="action" value="attendance">
                    <div class="filter-group"><label>Select Date</label><input type="date" name="att_date" class="form-control" value="<?php echo $_GET['att_date'] ?? date('Y-m-d'); ?>"></div>
                    <div class="filter-group"><button type="submit" class="btn btn-primary">View</button></div>
                </form>

                <?php
                $att_date = $_GET['att_date'] ?? date('Y-m-d');
                $stmt = $pdo->prepare("
                    SELECT s.*, sa.clock_in, sa.clock_out, sa.status, sa.late_minutes 
                    FROM staff s 
                    LEFT JOIN staff_attendance sa ON s.staff_id = sa.staff_id AND sa.date = ? AND sa.school_id = ?
                    WHERE s.school_id = ?
                ");
                $stmt->execute([$att_date, $school_id, $school_id]);
                $staff_attendance = $stmt->fetchAll();
                ?>

                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Staff ID</th>
                                <th>Name</th>
                                <th>Clock In</th>
                                <th>Clock Out</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($staff_attendance as $sa): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($sa['staff_id']); ?></td>
                                    <td><?php echo htmlspecialchars($sa['full_name']); ?></td>
                                    <td><?php echo $sa['clock_in'] ?? '—'; ?></td>
                                    <td><?php echo $sa['clock_out'] ?? '—'; ?></td>
                                    <td><span class="status-badge status-<?php echo $sa['status'] ?? 'absent'; ?>"><?php echo ucfirst($sa['status'] ?? 'Absent'); ?></span></td>
                                    <td>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="staff_id" value="<?php echo $sa['staff_id']; ?>">
                                            <input type="hidden" name="clock_action" value="clock_in">
                                            <button type="submit" class="btn btn-success btn-sm">Clock In</button>
                                        </form>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="staff_id" value="<?php echo $sa['staff_id']; ?>">
                                            <input type="hidden" name="clock_action" value="clock_out">
                                            <button type="submit" class="btn btn-danger btn-sm">Clock Out</button>
                                        </form>
                                        <button onclick="markLate('<?php echo $sa['staff_id']; ?>')" class="btn btn-warning btn-sm">Late</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- CLASS ATTENDANCE QR -->
        <?php elseif ($action === 'class_attendance'): ?>
            <div class="form-container">
                <h2>Class Attendance with QR Codes</h2>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div>
                        <h3>Generate QR for Class</h3>
                        <form method="POST">
                            <div class="form-group"><label>Class Name</label><input type="text" name="class_name" class="form-control" required></div>
                            <div class="form-group"><label>Session</label><select name="session_scan" class="form-select">
                                    <option value="morning">Morning</option>
                                    <option value="afternoon">Afternoon</option>
                                </select></div>
                            <button type="submit" name="generate_class_qr" class="btn btn-primary">Generate QR</button>
                        </form>
                    </div>

                    <div>
                        <h3>Scan QR to Record Attendance</h3>
                        <button onclick="openClassScanner()" class="btn btn-purple"><i class="fas fa-camera"></i> Scan QR Code</button>
                        <div id="classScanner" style="margin-top: 15px;"></div>
                    </div>
                </div>

                <h3 style="margin-top: 30px;">Today's Class Attendance</h3>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Class</th>
                                <th>Staff</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $today = date('Y-m-d');
                            $stmt = $pdo->prepare("SELECT ca.*, s.full_name as staff_name FROM class_attendance ca JOIN staff s ON ca.staff_id = s.staff_id WHERE ca.date = ? AND ca.school_id = ?");
                            $stmt->execute([$today, $school_id]);
                            foreach ($stmt->fetchAll() as $ca): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($ca['class_name']); ?></td>
                                    <td><?php echo htmlspecialchars($ca['staff_name']); ?></td>
                                    <td><?php echo $ca['time_in']; ?></td>
                                    <td><?php echo $ca['time_out'] ?? '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- PERFORMANCE RATING -->
        <?php elseif ($action === 'performance'): ?>
            <div class="form-container">
                <h2>Staff Performance Ratings</h2>
                <form method="GET" class="filter-form" style="margin-bottom: 20px;">
                    <input type="hidden" name="action" value="performance">
                    <div class="filter-group"><label>Select Month</label><input type="month" name="perf_month" class="form-control" value="<?php echo $_GET['perf_month'] ?? date('Y-m'); ?>"></div>
                    <div class="filter-group"><button type="submit" class="btn btn-primary">View</button></div>
                </form>

                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Staff Name</th>
                                <th>Punctuality</th>
                                <th>Task Completion</th>
                                <th>Student Feedback</th>
                                <th>Overall Rating</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $perf_month = $_GET['perf_month'] ?? date('Y-m');
                            $stmt = $pdo->prepare("SELECT s.id, s.full_name, sp.* FROM staff s LEFT JOIN staff_performance sp ON s.staff_id = sp.staff_id AND sp.month = ? WHERE s.school_id = ?");
                            $stmt->execute([$perf_month, $school_id]);
                            foreach ($stmt->fetchAll() as $perf): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($perf['full_name']); ?></td>
                                    <td><?php echo $perf['punctuality_score'] ?? '—'; ?>/5</td>
                                    <td><?php echo $perf['task_completion_score'] ?? '—'; ?>/5</td>
                                    <td><?php echo $perf['student_feedback_score'] ?? '—'; ?>/5</td>
                                    <td><strong><?php echo $perf['overall_rating'] ?? '—'; ?></strong></td>
                                    <td><button onclick="rateStaff(<?php echo $perf['id']; ?>, '<?php echo addslashes($perf['full_name']); ?>', '<?php echo $perf_month; ?>')" class="btn btn-primary btn-sm">Rate</button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- VIEW STAFF DETAILS -->
        <?php elseif ($action === 'view' && $staff): ?>
            <div class="form-container">
                <h2>Staff Details: <?php echo htmlspecialchars($staff['full_name']); ?></h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px,1fr)); gap: 20px;">
                    <div><strong>Staff ID:</strong> <?php echo htmlspecialchars($staff['staff_id']); ?></div>
                    <div><strong>Email:</strong> <?php echo htmlspecialchars($staff['email'] ?? '—'); ?></div>
                    <div><strong>Role:</strong> <?php echo ucfirst($staff['role']); ?></div>
                    <div><strong>Status:</strong> <span class="status-badge <?php echo $staff['is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $staff['is_active'] ? 'Active' : 'Inactive'; ?></span></div>
                    <div><strong>Joined:</strong> <?php echo date('F j, Y', strtotime($staff['created_at'])); ?></div>
                </div>
                <div style="margin-top: 20px;">
                    <a href="manage-staff.php?action=assign_subjects&id=<?php echo $staff['id']; ?>" class="btn btn-primary">Assign Subjects</a>
                    <a href="manage-staff.php?action=assign_classes&id=<?php echo $staff['id']; ?>" class="btn btn-purple">Assign Classes</a>
                    <a href="manage-staff.php?action=edit&id=<?php echo $staff['id']; ?>" class="btn btn-warning">Edit</a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Rate Staff Modal -->
    <div class="modal" id="rateModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Rate Staff Performance</h3><button class="close-modal" onclick="closeRateModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="staff_id" id="rate_staff_id">
                <input type="hidden" name="month" id="rate_month">
                <input type="hidden" name="rate_staff" value="1">
                <div class="modal-body">
                    <p><strong id="rate_staff_name"></strong></p>
                    <div class="form-group"><label>Punctuality (0-5)</label><input type="number" name="punctuality_score" step="0.5" min="0" max="5" class="form-control" required></div>
                    <div class="form-group"><label>Task Completion (0-5)</label><input type="number" name="task_completion_score" step="0.5" min="0" max="5" class="form-control" required></div>
                    <div class="form-group"><label>Student Feedback (0-5)</label><input type="number" name="student_feedback_score" step="0.5" min="0" max="5" class="form-control" required></div>
                    <div class="form-group"><label>Comments</label><textarea name="comments" class="form-control" rows="3"></textarea></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-warning" onclick="closeRateModal()">Cancel</button><button type="submit" class="btn btn-primary">Save Rating</button></div>
            </form>
        </div>
    </div>

    <script>
        // Mobile menu functionality
        const mobileBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');

        // Create overlay element
        const overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        document.body.appendChild(overlay);

        function toggleSidebar() {
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        }

        if (mobileBtn) {
            mobileBtn.onclick = toggleSidebar;
        }

        overlay.onclick = toggleSidebar;

        // Close sidebar on window resize if switching to desktop
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });

        // Password toggle function
        function togglePassword() {
            const chk = document.getElementById('change_password');
            const passwordFields = document.getElementById('password_fields');
            if (passwordFields) {
                passwordFields.style.display = chk && chk.checked ? 'block' : 'none';
            }
        }

        // Mark late function
        function markLate(staffId) {
            let minutes = prompt('Enter late minutes:', '5');
            if (minutes && !isNaN(minutes)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="staff_id" value="${staffId}">
                             <input type="hidden" name="late_minutes" value="${minutes}">
                             <input type="hidden" name="mark_late" value="1">`;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // QR Scanner
        let html5QrcodeScanner = null;

        function openClassScanner() {
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.style.display = 'flex';
            modal.innerHTML = `
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Scan Class QR Code</h3>
                    <button class="close-modal" onclick="this.closest('.modal').remove()">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="classReader"></div>
                    <p style="margin-top: 10px; font-size: 0.8rem; color: #666;">
                        <i class="fas fa-info-circle"></i> Point camera at class QR code
                    </p>
                </div>
            </div>
        `;
            document.body.appendChild(modal);

            html5QrcodeScanner = new Html5Qrcode("classReader");
            html5QrcodeScanner.start({
                    facingMode: "environment"
                }, {
                    fps: 10,
                    qrbox: 250
                },
                (decodedText) => {
                    try {
                        const data = JSON.parse(decodeURIComponent(decodedText));
                        if (data.type === 'class_attendance') {
                            html5QrcodeScanner.stop();
                            modal.remove();
                            const staffId = prompt('Enter your Staff ID:');
                            if (staffId && staffId.trim()) {
                                const form = document.createElement('form');
                                form.method = 'POST';
                                form.innerHTML = `<input type="hidden" name="class_name" value="${data.class}">
                                             <input type="hidden" name="staff_id" value="${staffId.trim()}">
                                             <input type="hidden" name="record_class_attendance" value="1">`;
                                document.body.appendChild(form);
                                form.submit();
                            }
                        } else {
                            alert('Invalid QR Code for class attendance');
                        }
                    } catch (e) {
                        alert('Invalid QR Code format');
                    }
                },
                (error) => {
                    console.log(error);
                }
            );
        }

        // Rate staff function
        function rateStaff(id, name, month) {
            document.getElementById('rate_staff_id').value = id;
            document.getElementById('rate_staff_name').innerText = name;
            document.getElementById('rate_month').value = month;
            document.getElementById('rateModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeRateModal() {
            document.getElementById('rateModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('rateModal');
            if (event.target === modal) {
                closeRateModal();
            }
        }

        // Touch-friendly improvements
        document.querySelectorAll('.btn, .nav-links a').forEach(el => {
            el.addEventListener('touchstart', function() {
                this.style.opacity = '0.7';
            });
            el.addEventListener('touchend', function() {
                this.style.opacity = '1';
            });
        });

        // Improve form submission on mobile
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                }
            });
        });
    </script>
</body>

</html>
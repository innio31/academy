<?php
// admin/manage-staff.php - Complete Staff Management with Attendance, Performance, and QR Tracking
session_start();

// Check if admin is logged in
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

// Add Staff (with password confirmation validation)
if (isset($_POST['add_staff'])) {
    $staff_id_num = trim($_POST['staff_id']);
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']) ?: null;
    $role = $_POST['role'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Validate password match
    if ($password !== $confirm_password) {
        $error_message = "Passwords do not match!";
        session_start();
        $_SESSION['staff_error'] = $error_message;
        header("Location: manage-staff.php?action=add&error=password_mismatch");
        exit();
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("INSERT INTO staff (staff_id, password, full_name, email, role, is_active, school_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$staff_id_num, $hashed_password, $full_name, $email, $role, $is_active, $school_id]);

    header("Location: manage-staff.php?action=list&message=Staff+added+successfully&type=success");
    exit();
}

// Update Staff (with proper password update handling)
if (isset($_POST['edit_staff'])) {
    $staff_id = $_POST['id'];
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']) ?: null;
    $role = $_POST['role'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // Check if we need to update password
    $update_password = isset($_POST['change_password']) && !empty($_POST['password']);

    if ($update_password) {
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];

        // Validate password match
        if ($password !== $confirm_password) {
            $error_message = "Passwords do not match!";
            session_start();
            $_SESSION['staff_error'] = $error_message;
            header("Location: manage-staff.php?action=edit&id=$staff_id&error=password_mismatch");
            exit();
        }

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $sql = "UPDATE staff SET full_name = ?, email = ?, role = ?, is_active = ?, password = ? WHERE id = ? AND school_id = ?";
        $params = [$full_name, $email, $role, $is_active, $hashed_password, $staff_id, $school_id];
    } else {
        $sql = "UPDATE staff SET full_name = ?, email = ?, role = ?, is_active = ? WHERE id = ? AND school_id = ?";
        $params = [$full_name, $email, $role, $is_active, $staff_id, $school_id];
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

    $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ?");
    $stmt->execute([$staff_id_num]);
    $staff_id_string = $stmt->fetchColumn();

    $pdo->prepare("DELETE FROM staff_subjects WHERE staff_id = ? AND school_id = ?")->execute([$staff_id_string, $school_id]);

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
$password_error = isset($_SESSION['staff_error']) ? $_SESSION['staff_error'] : null;
unset($_SESSION['staff_error']); // Clear error after retrieving

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

// Get all subjects for this school (only school-specific subjects, not central subjects)
$stmt = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE school_id = ? AND is_central = 0 ORDER BY subject_name");
$stmt->execute([$school_id]);
$all_subjects = $stmt->fetchAll();

// Get all classes for this school from the classes table
$stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? AND status = 'active' ORDER BY sort_order, class_name");
$stmt->execute([$school_id]);
$all_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If no classes found in classes table, try to get from students table as fallback
if (empty($all_classes)) {
    $stmt = $pdo->prepare("SELECT DISTINCT class FROM students WHERE school_id = ? AND class != '' ORDER BY class");
    $stmt->execute([$school_id]);
    $all_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $school_name; ?> - Manage Staff</title>

    <!-- QR Scanner Library -->
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --primary-dark: #1a5a8a;
            --primary-light: #e8f4fd;
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
            --purple-light: #f3e8ff;
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
            backdrop-filter: blur(10px);
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
            font-size: 1rem;
        }

        /* Main Content */
        .main-content {
            min-height: 100vh;
            padding: 20px;
            transition: margin-left 0.3s;
        }

        /* Mobile Menu Button */
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

        /* Action Buttons Group */
        .action-buttons-group {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
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

        .btn-success:hover {
            background: #219a52;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-warning:hover {
            background: #e67e22;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #c0392b;
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-info:hover {
            background: #2980b9;
        }

        .btn-purple {
            background: var(--purple);
            color: white;
        }

        .btn-purple:hover {
            background: #8e44ad;
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

        /* Password Input Group */
        .password-group {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--gray-600);
            background: white;
            padding: 0 5px;
        }

        .password-toggle:hover {
            color: var(--primary-color);
        }

        .form-group {
            margin-bottom: 16px;
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

        .form-control.password-input {
            padding-right: 40px;
        }

        /* Table */
        .table-container {
            overflow-x: auto;
            border-radius: var(--radius-lg);
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

        .status-active {
            background: var(--success-light);
            color: var(--success);
        }

        .status-inactive {
            background: var(--danger-light);
            color: var(--danger);
        }

        .status-present {
            background: var(--success-light);
            color: var(--success);
        }

        .status-absent {
            background: var(--danger-light);
            color: var(--danger);
        }

        .status-late {
            background: var(--warning-light);
            color: var(--warning);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-top: 24px;
        }

        .stat-card {
            background: linear-gradient(135deg, white, var(--gray-50));
            padding: 20px;
            border-radius: var(--radius-lg);
            text-align: center;
            border: 1px solid var(--gray-200);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary-color);
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--gray-600);
            margin-top: 4px;
        }

        /* Action Buttons Group in Table */
        .table-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        /* Grid Layout for Forms */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }

        /* Checkbox Group */
        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            max-height: 300px;
            overflow-y: auto;
            padding: 12px;
            background: var(--gray-50);
            border-radius: var(--radius-md);
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 140px;
        }

        .checkbox-item input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .checkbox-item label {
            font-size: 0.85rem;
            cursor: pointer;
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

        .modal-content {
            background: white;
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
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

        /* Alert Messages */
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

        /* Split Layout */
        .split-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        /* Responsive */
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

            .split-layout {
                grid-template-columns: 1fr;
            }

            .filter-form {
                flex-direction: column;
            }

            .filter-group .btn {
                width: 100%;
                justify-content: center;
            }

            .action-buttons-group {
                width: 100%;
            }

            .action-buttons-group .btn {
                flex: 1;
                justify-content: center;
            }
        }

        /* Make staff name display bigger */
        .staff-name-display,
        .student-name-display,
        .student-name,
        .student-info .student-name,
        .staff-info .staff-name,
        .data-table td strong,
        .data-table td:first-child+td strong,
        .class-item .class-name,
        .view-name,
        .profile-name,
        .name-display {
            font-size: 1rem !important;
            font-weight: 600 !important;
        }

        /* For the staff directory table - make name column larger */
        .data-table td:nth-child(2) strong,
        .data-table td:nth-child(2) {
            font-size: 0.95rem !important;
            font-weight: 600 !important;
        }

        /* For student list items */
        .student-name {
            font-size: 1rem !important;
            font-weight: 600 !important;
        }

        /* For staff cards or detail views */
        .info-value strong,
        .info-value .staff-name {
            font-size: 1rem !important;
        }

        /* For attendance table staff names */
        .table-container .data-table td strong {
            font-size: 0.95rem !important;
        }

        /* For performance table staff names */
        .data-table td:first-child strong {
            font-size: 0.95rem !important;
        }

        /* For class list item names */
        .class-name {
            font-size: 1rem !important;
            font-weight: 500 !important;
        }

        /* For modal headers and student details */
        .modal-body .info-value {
            font-size: 0.95rem !important;
        }

        /* For dropdown and select options */
        .class-dropdown select option {
            font-size: 0.9rem;
        }

        /* For bulk bar selected count text */
        .selected-count {
            font-size: 0.8rem;
        }

        /* Make table headers more readable */
        .data-table th {
            font-size: 0.8rem !important;
            font-weight: 700 !important;
        }

        /* For student admission number */
        .student-admission {
            font-size: 0.75rem !important;
        }

        /* For status badges text */
        .status-badge {
            font-size: 0.7rem !important;
            font-weight: 600 !important;
        }

        /* For the header title */
        .header-title h1 {
            font-size: 1.6rem !important;
        }

        /* For dashboard stats */
        .stat-value {
            font-size: 2rem !important;
        }

        /* For any name displays in action buttons */
        .btn .name {
            font-size: 0.85rem;
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>

    <?php
    // Include sidebar at the end (it will be positioned fixed)
    require_once 'includes/sidebar.php';
    ?>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1>Manage Staff</h1>
                <p><i class="fas fa-chevron-right" style="font-size: 10px;"></i> Manage staff, track attendance, assign subjects/classes, and rate performance</p>
            </div>
            <div class="action-buttons-group">
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

        <?php if ($password_error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($password_error); ?>
            </div>
        <?php endif; ?>

        <!-- STAFF LIST VIEW -->
        <?php if ($action === 'list'): ?>
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-filter"></i> Filter Staff</h2>
                </div>
                <form method="GET" class="filter-form">
                    <input type="hidden" name="action" value="list">
                    <div class="filter-group">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Name, ID, Email" value="<?php echo $_GET['search'] ?? ''; ?>">
                    </div>
                    <div class="filter-group">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select">
                            <option value="">All Roles</option>
                            <option value="staff" <?php echo ($_GET['role'] ?? '') === 'staff' ? 'selected' : ''; ?>>Staff</option>
                            <option value="admin" <?php echo ($_GET['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All</option>
                            <option value="1" <?php echo ($_GET['status'] ?? '') === '1' ? 'selected' : ''; ?>>Active</option>
                            <option value="0" <?php echo ($_GET['status'] ?? '') === '0' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
                        <a href="manage-staff.php?action=list" class="btn btn-outline"><i class="fas fa-redo"></i> Reset</a>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-users"></i> Staff Directory</h2>
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
                                    <td><code><?php echo htmlspecialchars($s['staff_id']); ?></code></td>
                                    <td><strong><?php echo htmlspecialchars($s['full_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($s['email'] ?? '—'); ?></td>
                                    <td><span class="status-badge <?php echo $s['role'] === 'admin' ? 'status-warning' : 'status-info'; ?>" style="background:<?php echo $s['role'] === 'admin' ? '#fef5e7' : '#eaf6ff'; ?>; color:<?php echo $s['role'] === 'admin' ? '#f39c12' : '#3498db'; ?>"><?php echo ucfirst($s['role']); ?></span></td>
                                    <td><span class="status-badge <?php echo $s['is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $s['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                    <td><?php echo date('M d, Y', strtotime($s['created_at'])); ?></td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="manage-staff.php?action=view&id=<?php echo $s['id']; ?>" class="btn btn-info btn-sm"><i class="fas fa-eye"></i></a>
                                            <a href="manage-staff.php?action=edit&id=<?php echo $s['id']; ?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></a>
                                            <a href="manage-staff.php?action=assign_subjects&id=<?php echo $s['id']; ?>" class="btn btn-primary btn-sm"><i class="fas fa-book"></i></a>
                                            <a href="manage-staff.php?action=assign_classes&id=<?php echo $s['id']; ?>" class="btn btn-purple btn-sm"><i class="fas fa-chalkboard"></i></a>
                                            <?php if ($admin_role === 'super_admin'): ?>
                                                <a href="manage-staff.php?delete=<?php echo $s['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this staff?')"><i class="fas fa-trash"></i></a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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
                    <div class="stat-label">Total Staff</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $active->fetchColumn(); ?></div>
                    <div class="stat-label">Active Staff</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $admin_count->fetchColumn(); ?></div>
                    <div class="stat-label">Administrators</div>
                </div>
            </div>

            <!-- ADD/EDIT STAFF -->
        <?php elseif ($action === 'add' || $action === 'edit'): ?>
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-<?php echo $action === 'add' ? 'user-plus' : 'user-edit'; ?>"></i> <?php echo $action === 'add' ? 'Add New Staff' : 'Edit Staff'; ?></h2>
                </div>
                <form method="POST" onsubmit="return validatePasswords()">
                    <?php if ($action === 'edit'): ?>
                        <input type="hidden" name="id" value="<?php echo $staff['id']; ?>">
                        <input type="hidden" name="edit_staff" value="1">
                    <?php else: ?>
                        <input type="hidden" name="add_staff" value="1">
                    <?php endif; ?>
                    <div class="form-grid">
                        <div class="form-group"><label class="form-label">Staff ID *</label><input type="text" name="staff_id" class="form-control" value="<?php echo $staff['staff_id'] ?? ''; ?>" <?php echo $action === 'edit' ? 'readonly' : 'required'; ?>></div>
                        <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="full_name" class="form-control" value="<?php echo $staff['full_name'] ?? ''; ?>" required></div>
                        <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?php echo $staff['email'] ?? ''; ?>"></div>
                        <div class="form-group"><label class="form-label">Role</label><select name="role" class="form-select">
                                <option value="staff" <?php echo ($staff['role'] ?? '') === 'staff' ? 'selected' : ''; ?>>Staff</option>
                                <option value="admin" <?php echo ($staff['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Administrator</option>
                            </select></div>
                        <div class="form-group"><label class="checkbox-item"><input type="checkbox" name="is_active" <?php echo ($staff['is_active'] ?? 1) ? 'checked' : ''; ?>> Active Account</label></div>
                    </div>

                    <div style="margin-top: 20px; background: var(--gray-50); border-radius: var(--radius-md); padding: 20px;">
                        <h3 style="font-size: 0.9rem; margin-bottom: 12px;"><i class="fas fa-lock"></i> Password</h3>
                        <?php if ($action === 'edit'): ?>
                            <label class="checkbox-item">
                                <input type="checkbox" name="change_password" id="change_password" value="1" onchange="togglePasswordFields()">
                                <span>Change Password</span>
                            </label>
                        <?php endif; ?>
                        <div id="password_fields" style="<?php echo $action === 'edit' ? 'display:none;' : 'display:block;'; ?> margin-top: 15px;">
                            <div class="form-grid">
                                <div class="form-group password-group">
                                    <label class="form-label">Password <?php echo $action === 'add' ? '*' : ''; ?></label>
                                    <div style="position: relative;">
                                        <input type="password" name="password" id="password" class="form-control password-input" placeholder="Enter password" <?php echo $action === 'add' ? 'required' : ''; ?>>
                                        <span class="password-toggle" onclick="togglePasswordVisibility('password')">
                                            <i class="far fa-eye" id="password-icon"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="form-group password-group">
                                    <label class="form-label">Confirm Password <?php echo $action === 'add' ? '*' : ''; ?></label>
                                    <div style="position: relative;">
                                        <input type="password" name="confirm_password" id="confirm_password" class="form-control password-input" placeholder="Confirm password" <?php echo $action === 'add' ? 'required' : ''; ?>>
                                        <span class="password-toggle" onclick="togglePasswordVisibility('confirm_password')">
                                            <i class="far fa-eye" id="confirm_password-icon"></i>
                                        </span>
                                    </div>
                                    <small id="password-match-error" style="color: var(--danger); display: none; font-size: 0.7rem; margin-top: 4px;">
                                        <i class="fas fa-exclamation-circle"></i> Passwords do not match
                                    </small>
                                </div>
                            </div>
                        </div>
                        <?php if ($action === 'edit'): ?>
                            <small style="color: var(--gray-600); font-size: 0.7rem; display: block; margin-top: 8px;">
                                <i class="fas fa-info-circle"></i> Leave password fields empty to keep current password.
                            </small>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top: 24px; display: flex; gap: 12px;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Staff</button>
                        <a href="manage-staff.php?action=list" class="btn btn-outline"><i class="fas fa-times"></i> Cancel</a>
                    </div>
                </form>
            </div>

            <script>
                // Toggle password fields visibility for edit mode
                function togglePasswordFields() {
                    const chk = document.getElementById('change_password');
                    const passwordFields = document.getElementById('password_fields');
                    const passwordInput = document.getElementById('password');
                    const confirmInput = document.getElementById('confirm_password');

                    if (passwordFields) {
                        if (chk && chk.checked) {
                            passwordFields.style.display = 'block';
                            // Make fields required when checkbox is checked
                            if (passwordInput) passwordInput.required = true;
                            if (confirmInput) confirmInput.required = true;
                        } else {
                            passwordFields.style.display = 'none';
                            // Remove required when checkbox is unchecked
                            if (passwordInput) {
                                passwordInput.required = false;
                                passwordInput.value = '';
                            }
                            if (confirmInput) {
                                confirmInput.required = false;
                                confirmInput.value = '';
                            }
                            // Hide error message
                            const errorSpan = document.getElementById('password-match-error');
                            if (errorSpan) errorSpan.style.display = 'none';
                        }
                    }
                }

                // Toggle password visibility
                function togglePasswordVisibility(fieldId) {
                    const field = document.getElementById(fieldId);
                    const icon = document.getElementById(fieldId + '-icon');
                    if (field && icon) {
                        if (field.type === 'password') {
                            field.type = 'text';
                            icon.classList.remove('fa-eye');
                            icon.classList.add('fa-eye-slash');
                        } else {
                            field.type = 'password';
                            icon.classList.remove('fa-eye-slash');
                            icon.classList.add('fa-eye');
                        }
                    }
                }

                // Validate passwords match on form submit
                function validatePasswords() {
                    // For edit mode, check if change password checkbox is checked
                    const changePwdCheckbox = document.getElementById('change_password');
                    const isEditMode = <?php echo $action === 'edit' ? 'true' : 'false'; ?>;

                    // If in edit mode and checkbox is not checked, skip validation
                    if (isEditMode && changePwdCheckbox && !changePwdCheckbox.checked) {
                        return true;
                    }

                    // Check if password fields are visible
                    const passwordFields = document.getElementById('password_fields');
                    if (passwordFields && passwordFields.style.display !== 'none') {
                        const password = document.getElementById('password').value;
                        const confirmPassword = document.getElementById('confirm_password').value;
                        const errorSpan = document.getElementById('password-match-error');

                        if (password !== confirmPassword) {
                            errorSpan.style.display = 'block';
                            document.getElementById('confirm_password').focus();
                            return false;
                        } else {
                            errorSpan.style.display = 'none';
                        }

                        // Check if password is empty in edit mode when checkbox is checked
                        if (isEditMode && changePwdCheckbox && changePwdCheckbox.checked && password === '') {
                            alert('Please enter a new password or uncheck "Change Password"');
                            document.getElementById('password').focus();
                            return false;
                        }
                    }
                    return true;
                }

                // Real-time password match validation
                document.addEventListener('DOMContentLoaded', function() {
                    const password = document.getElementById('password');
                    const confirmPassword = document.getElementById('confirm_password');
                    const errorSpan = document.getElementById('password-match-error');

                    if (password && confirmPassword) {
                        function checkMatch() {
                            if (password.value !== confirmPassword.value && confirmPassword.value !== '') {
                                errorSpan.style.display = 'block';
                            } else {
                                errorSpan.style.display = 'none';
                            }
                        }

                        password.addEventListener('keyup', checkMatch);
                        confirmPassword.addEventListener('keyup', checkMatch);
                    }
                });
            </script>

            <!-- ASSIGN SUBJECTS -->
        <?php elseif ($action === 'assign_subjects' && $staff): ?>
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-book"></i> Assign Subjects to <?php echo htmlspecialchars($staff['full_name']); ?></h2>
                </div>
                <form method="POST">
                    <input type="hidden" name="staff_id" value="<?php echo $staff['id']; ?>">
                    <input type="hidden" name="assign_subjects" value="1">
                    <div class="checkbox-group">
                        <?php foreach ($all_subjects as $subject): ?>
                            <label class="checkbox-item">
                                <input type="checkbox" name="subjects[]" value="<?php echo $subject['id']; ?>" <?php echo in_array($subject['id'], $assigned_subject_ids) ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars($subject['subject_name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top: 20px; display: flex; gap: 12px;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Subjects</button>
                        <a href="manage-staff.php?action=list" class="btn btn-outline">Back</a>
                    </div>
                </form>
            </div>

            <!-- ASSIGN CLASSES -->
        <?php elseif ($action === 'assign_classes' && $staff): ?>
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-chalkboard"></i> Assign Classes to <?php echo htmlspecialchars($staff['full_name']); ?></h2>
                </div>
                <form method="POST">
                    <input type="hidden" name="staff_id" value="<?php echo $staff['id']; ?>">
                    <input type="hidden" name="assign_classes" value="1">
                    <div class="checkbox-group">
                        <?php if (!empty($all_classes)): ?>
                            <?php foreach ($all_classes as $class): ?>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="classes[]" value="<?php echo htmlspecialchars($class['class_name']); ?>" <?php echo in_array($class['class_name'], $assigned_class_names) ? 'checked' : ''; ?>>
                                    <span><?php echo htmlspecialchars($class['class_name']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="padding: 20px; text-align: center; color: var(--gray-600);">
                                <i class="fas fa-info-circle"></i> No classes found. Please add classes first.
                            </div>
                        <?php endif; ?>
                    </div>
                    <div style="margin-top: 20px; display: flex; gap: 12px;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Classes</button>
                        <a href="manage-staff.php?action=list" class="btn btn-outline">Back</a>
                    </div>
                </form>
            </div>

            <!-- STAFF ATTENDANCE VIEW -->
        <?php elseif ($action === 'attendance'): ?>
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-calendar-check"></i> Staff Attendance</h2>
                </div>
                <form method="GET" class="filter-form" style="margin-bottom: 20px;">
                    <input type="hidden" name="action" value="attendance">
                    <div class="filter-group"><label class="form-label">Select Date</label><input type="date" name="att_date" class="form-control" value="<?php echo $_GET['att_date'] ?? date('Y-m-d'); ?>"></div>
                    <div class="filter-group"><button type="submit" class="btn btn-primary"><i class="fas fa-eye"></i> View</button></div>
                </form>

                <?php
                $att_date = $_GET['att_date'] ?? date('Y-m-d');
                $stmt = $pdo->prepare("SELECT s.*, sa.clock_in, sa.clock_out, sa.status, sa.late_minutes FROM staff s LEFT JOIN staff_attendance sa ON s.staff_id = sa.staff_id AND sa.date = ? AND sa.school_id = ? WHERE s.school_id = ?");
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
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($staff_attendance as $sa): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($sa['staff_id']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($sa['full_name']); ?></strong></td>
                                    <td><?php echo $sa['clock_in'] ?? '—'; ?></td>
                                    <td><?php echo $sa['clock_out'] ?? '—'; ?></td>
                                    <td><span class="status-badge status-<?php echo $sa['status'] ?? 'absent'; ?>"><?php echo ucfirst($sa['status'] ?? 'Absent'); ?></span></td>
                                    <td>
                                        <div class="table-actions">
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="staff_id" value="<?php echo $sa['staff_id']; ?>">
                                                <input type="hidden" name="clock_action" value="clock_in">
                                                <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-sign-in-alt"></i> In</button>
                                            </form>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="staff_id" value="<?php echo $sa['staff_id']; ?>">
                                                <input type="hidden" name="clock_action" value="clock_out">
                                                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-sign-out-alt"></i> Out</button>
                                            </form>
                                            <button onclick="markLate('<?php echo $sa['staff_id']; ?>')" class="btn btn-warning btn-sm"><i class="fas fa-clock"></i> Late</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- CLASS ATTENDANCE QR -->
        <?php elseif ($action === 'class_attendance'): ?>
            <div class="split-layout">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-qrcode"></i> Generate QR Code</h2>
                    </div>
                    <form method="POST">
                        <div class="form-group"><label class="form-label">Class Name</label><input type="text" name="class_name" class="form-control" placeholder="e.g., Grade 10-A" required></div>
                        <div class="form-group"><label class="form-label">Session</label><select name="session_scan" class="form-select">
                                <option value="morning">Morning</option>
                                <option value="afternoon">Afternoon</option>
                            </select></div>
                        <button type="submit" name="generate_class_qr" class="btn btn-primary"><i class="fas fa-qrcode"></i> Generate QR</button>
                    </form>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-camera"></i> Scan QR Code</h2>
                    </div>
                    <button onclick="openClassScanner()" class="btn btn-purple" style="width: 100%;"><i class="fas fa-camera"></i> Open Scanner</button>
                    <div id="classScanner" style="margin-top: 16px;"></div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-list"></i> Today's Class Attendance</h2>
                </div>
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
                                    <td><strong><?php echo htmlspecialchars($ca['class_name']); ?></strong></td>
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
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-star"></i> Staff Performance Ratings</h2>
                </div>
                <form method="GET" class="filter-form" style="margin-bottom: 20px;">
                    <input type="hidden" name="action" value="performance">
                    <div class="filter-group"><label class="form-label">Select Month</label><input type="month" name="perf_month" class="form-control" value="<?php echo $_GET['perf_month'] ?? date('Y-m'); ?>"></div>
                    <div class="filter-group"><button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button></div>
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
                                    <td><strong><?php echo htmlspecialchars($perf['full_name']); ?></strong></td>
                                    <td><?php echo $perf['punctuality_score'] ?? '—'; ?> / 5</td>
                                    <td><?php echo $perf['task_completion_score'] ?? '—'; ?> / 5</td>
                                    <td><?php echo $perf['student_feedback_score'] ?? '—'; ?> / 5</td>
                                    <td><span class="status-badge" style="background:<?php echo $perf['overall_rating'] ? '#e8f4fd' : '#f0f2f5'; ?>; font-weight:600;"><?php echo $perf['overall_rating'] ?? 'Not Rated'; ?></span></td>
                                    <td><button onclick="rateStaff(<?php echo $perf['id']; ?>, '<?php echo addslashes($perf['full_name']); ?>', '<?php echo $perf_month; ?>')" class="btn btn-primary btn-sm"><i class="fas fa-star"></i> Rate</button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- VIEW STAFF DETAILS -->
        <?php elseif ($action === 'view' && $staff): ?>
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-id-card"></i> Staff Details</h2>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px,1fr)); gap: 20px;">
                    <div><strong>Staff ID:</strong><br><?php echo htmlspecialchars($staff['staff_id']); ?></div>
                    <div><strong>Full Name:</strong><br><?php echo htmlspecialchars($staff['full_name']); ?></div>
                    <div><strong>Email:</strong><br><?php echo htmlspecialchars($staff['email'] ?? '—'); ?></div>
                    <div><strong>Role:</strong><br><span class="status-badge" style="background:<?php echo $staff['role'] === 'admin' ? '#fef5e7' : '#eaf6ff'; ?>"><?php echo ucfirst($staff['role']); ?></span></div>
                    <div><strong>Status:</strong><br><span class="status-badge <?php echo $staff['is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $staff['is_active'] ? 'Active' : 'Inactive'; ?></span></div>
                    <div><strong>Joined:</strong><br><?php echo date('F j, Y', strtotime($staff['created_at'])); ?></div>
                </div>
                <div style="margin-top: 24px; display: flex; gap: 12px; flex-wrap: wrap;">
                    <a href="manage-staff.php?action=assign_subjects&id=<?php echo $staff['id']; ?>" class="btn btn-primary"><i class="fas fa-book"></i> Assign Subjects</a>
                    <a href="manage-staff.php?action=assign_classes&id=<?php echo $staff['id']; ?>" class="btn btn-purple"><i class="fas fa-chalkboard"></i> Assign Classes</a>
                    <a href="manage-staff.php?action=edit&id=<?php echo $staff['id']; ?>" class="btn btn-warning"><i class="fas fa-edit"></i> Edit</a>
                    <a href="manage-staff.php?action=list" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Rate Staff Modal -->
    <div class="modal" id="rateModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-star" style="color: var(--warning);"></i> Rate Staff Performance</h3>
                <button class="close-modal" onclick="closeRateModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="staff_id" id="rate_staff_id">
                <input type="hidden" name="month" id="rate_month">
                <input type="hidden" name="rate_staff" value="1">
                <div class="modal-body">
                    <p style="margin-bottom: 16px;"><strong id="rate_staff_name"></strong></p>
                    <div class="form-group"><label class="form-label">Punctuality (0-5)</label><input type="number" name="punctuality_score" step="0.5" min="0" max="5" class="form-control" required></div>
                    <div class="form-group"><label class="form-label">Task Completion (0-5)</label><input type="number" name="task_completion_score" step="0.5" min="0" max="5" class="form-control" required></div>
                    <div class="form-group"><label class="form-label">Student Feedback (0-5)</label><input type="number" name="student_feedback_score" step="0.5" min="0" max="5" class="form-control" required></div>
                    <div class="form-group"><label class="form-label">Comments</label><textarea name="comments" class="form-control" rows="3" placeholder="Optional feedback..."></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeRateModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Rating</button>
                </div>
            </form>
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

        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });

        // Password toggle (global function for any password fields)
        function togglePasswordVisibility(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = document.getElementById(fieldId + '-icon');
            if (field && icon) {
                if (field.type === 'password') {
                    field.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    field.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            }
        }

        // Mark late
        function markLate(staffId) {
            let minutes = prompt('Enter late minutes:', '5');
            if (minutes && !isNaN(minutes)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="staff_id" value="${staffId}"><input type="hidden" name="late_minutes" value="${minutes}"><input type="hidden" name="mark_late" value="1">`;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // QR Scanner
        let html5QrcodeScanner = null;

        function openClassScanner() {
            const modalDiv = document.createElement('div');
            modalDiv.className = 'modal';
            modalDiv.style.display = 'flex';
            modalDiv.innerHTML = `
                <div class="modal-content">
                    <div class="modal-header">
                        <h3><i class="fas fa-camera"></i> Scan QR Code</h3>
                        <button class="close-modal" onclick="this.closest('.modal').remove()">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div id="classReader"></div>
                        <p style="margin-top: 12px; font-size: 0.75rem; color: var(--gray-600); text-align: center;">
                            <i class="fas fa-info-circle"></i> Position the QR code within the frame
                        </p>
                    </div>
                </div>
            `;
            document.body.appendChild(modalDiv);

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
                            modalDiv.remove();
                            const staffId = prompt('Enter your Staff ID:');
                            if (staffId && staffId.trim()) {
                                const form = document.createElement('form');
                                form.method = 'POST';
                                form.innerHTML = `<input type="hidden" name="class_name" value="${data.class}"><input type="hidden" name="staff_id" value="${staffId.trim()}"><input type="hidden" name="record_class_attendance" value="1">`;
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
                (error) => console.log(error)
            );
        }

        // Rate staff modal
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

        window.onclick = function(event) {
            const modal = document.getElementById('rateModal');
            if (event.target === modal) closeRateModal();
        }
    </script>
</body>

</html>
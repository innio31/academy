<?php
// admin/manage-students.php - Complete Student Management with Auto QR & Image Upload
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /gos/login.php");
    exit();
}

if (isset($_SESSION['admin_id'])) {
    $admin_id = $_SESSION['admin_id'];
    $admin_name = $_SESSION['admin_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'super_admin';
} else {
    $admin_id = $_SESSION['user_id'];
    $admin_name = $_SESSION['user_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

if ($admin_role !== 'super_admin' && $admin_role !== 'admin') {
    header("Location: index.php?message=Access denied&type=error");
    exit();
}

require_once '../includes/config.php';
require_once '../includes/qr_functions.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;

// ============================================
// HANDLE POST REQUESTS
// ============================================

// Add new student (with auto QR generation)
if (isset($_POST['action']) && $_POST['action'] === 'add_student') {
    $admission_number = trim($_POST['admission_number']);
    $full_name = trim($_POST['full_name']);
    $class_id = $_POST['class_id'];
    $status = 'active';
    $parent_phone = trim($_POST['parent_phone'] ?? '');
    $parent_email = trim($_POST['parent_email'] ?? '');
    
    // Get class name from class_id
    $stmt = $pdo->prepare("SELECT class_name FROM classes WHERE id = ? AND school_id = ?");
    $stmt->execute([$class_id, $school_id]);
    $class = $stmt->fetch();
    $class_name = $class ? $class['class_name'] : '';
    
    $surname = explode(' ', $full_name)[0];
    $password = password_hash(strtolower($surname), PASSWORD_DEFAULT);
    
    try {
        $stmt = $pdo->prepare("INSERT INTO students (admission_number, password, full_name, class, class_id, status, parent_phone, parent_email, school_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$admission_number, $password, $full_name, $class_name, $class_id, $status, $parent_phone, $parent_email, $school_id]);
        
        $student_id = $pdo->lastInsertId();
        
        // Auto-generate QR code
        $qr_url = generateStudentQRCode($student_id, $admission_number, $full_name);
        saveStudentQRCode($pdo, $student_id, $qr_url);
        
        // Handle image upload if provided
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $upload_result = uploadStudentImage($_FILES['profile_picture'], $student_id);
            if ($upload_result['success']) {
                $stmt = $pdo->prepare("UPDATE students SET profile_picture = ? WHERE id = ?");
                $stmt->execute([$upload_result['path'], $student_id]);
            }
        }
        
        header("Location: manage-students.php?class_id=" . $class_id . "&message=Student added successfully&type=success");
        exit();
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            header("Location: manage-students.php?class_id=" . $class_id . "&message=Admission number already exists&type=error");
        } else {
            header("Location: manage-students.php?class_id=" . $class_id . "&message=Error adding student&type=error");
        }
        exit();
    }
}

// Update student
if (isset($_POST['action']) && $_POST['action'] === 'update_student') {
    $student_id = $_POST['student_id'];
    $admission_number = trim($_POST['admission_number']);
    $full_name = trim($_POST['full_name']);
    $class_id = $_POST['class_id'];
    $status = $_POST['status'] ?? 'active';
    $parent_phone = trim($_POST['parent_phone'] ?? '');
    $parent_email = trim($_POST['parent_email'] ?? '');
    
    // Get class name
    $stmt = $pdo->prepare("SELECT class_name FROM classes WHERE id = ? AND school_id = ?");
    $stmt->execute([$class_id, $school_id]);
    $class = $stmt->fetch();
    $class_name = $class ? $class['class_name'] : '';
    
    try {
        $stmt = $pdo->prepare("UPDATE students SET admission_number = ?, full_name = ?, class = ?, class_id = ?, status = ?, parent_phone = ?, parent_email = ? WHERE id = ? AND school_id = ?");
        $stmt->execute([$admission_number, $full_name, $class_name, $class_id, $status, $parent_phone, $parent_email, $student_id, $school_id]);
        
        // Handle image upload if provided
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $upload_result = uploadStudentImage($_FILES['profile_picture'], $student_id);
            if ($upload_result['success']) {
                // Delete old picture if exists
                $stmt = $pdo->prepare("SELECT profile_picture FROM students WHERE id = ?");
                $stmt->execute([$student_id]);
                $old_pic = $stmt->fetchColumn();
                if ($old_pic && file_exists($_SERVER['DOCUMENT_ROOT'] . $old_pic)) {
                    unlink($_SERVER['DOCUMENT_ROOT'] . $old_pic);
                }
                
                $stmt = $pdo->prepare("UPDATE students SET profile_picture = ? WHERE id = ?");
                $stmt->execute([$upload_result['path'], $student_id]);
            }
        }
        
        header("Location: manage-students.php?class_id=" . $class_id . "&message=Student updated successfully&type=success");
        exit();
    } catch (Exception $e) {
        header("Location: manage-students.php?class_id=" . $class_id . "&message=Error updating student&type=error");
        exit();
    }
}

// Delete student
if (isset($_POST['action']) && $_POST['action'] === 'delete_student') {
    $student_id = $_POST['student_id'];
    $class_id = $_POST['class_id'] ?? 0;
    
    // Get profile picture to delete
    $stmt = $pdo->prepare("SELECT profile_picture FROM students WHERE id = ? AND school_id = ?");
    $stmt->execute([$student_id, $school_id]);
    $profile_pic = $stmt->fetchColumn();
    
    try {
        $stmt = $pdo->prepare("DELETE FROM students WHERE id = ? AND school_id = ?");
        $stmt->execute([$student_id, $school_id]);
        
        // Delete profile picture file
        if ($profile_pic && file_exists($_SERVER['DOCUMENT_ROOT'] . $profile_pic)) {
            unlink($_SERVER['DOCUMENT_ROOT'] . $profile_pic);
        }
        
        header("Location: manage-students.php?class_id=" . $class_id . "&message=Student deleted successfully&type=success");
        exit();
    } catch (Exception $e) {
        header("Location: manage-students.php?class_id=" . $class_id . "&message=Error deleting student&type=error");
        exit();
    }
}

// Bulk transfer/promote students
if (isset($_POST['action']) && $_POST['action'] === 'transfer_students') {
    $selected_students = explode(',', $_POST['selected_students'] ?? '');
    $target_class_id = $_POST['target_class_id'] ?? '';
    $current_class_id = $_POST['current_class_id'] ?? 0;
    
    if (!empty($selected_students) && !empty($target_class_id)) {
        // Get target class name
        $stmt = $pdo->prepare("SELECT class_name FROM classes WHERE id = ? AND school_id = ?");
        $stmt->execute([$target_class_id, $school_id]);
        $target_class = $stmt->fetch();
        $target_class_name = $target_class ? $target_class['class_name'] : '';
        
        $placeholders = str_repeat('?,', count($selected_students) - 1) . '?';
        $stmt = $pdo->prepare("UPDATE students SET class_id = ?, class = ? WHERE id IN ($placeholders) AND school_id = ?");
        $params = array_merge([$target_class_id, $target_class_name], $selected_students, [$school_id]);
        $stmt->execute($params);
        
        header("Location: manage-students.php?class_id=" . $current_class_id . "&message=" . count($selected_students) . " student(s) transferred successfully&type=success");
        exit();
    }
}

// Remove profile picture
if (isset($_POST['action']) && $_POST['action'] === 'remove_picture') {
    $student_id = $_POST['student_id'];
    $class_id = $_POST['class_id'] ?? 0;
    
    // Get and delete the file
    $stmt = $pdo->prepare("SELECT profile_picture FROM students WHERE id = ? AND school_id = ?");
    $stmt->execute([$student_id, $school_id]);
    $profile_pic = $stmt->fetchColumn();
    
    if ($profile_pic && file_exists($_SERVER['DOCUMENT_ROOT'] . $profile_pic)) {
        unlink($_SERVER['DOCUMENT_ROOT'] . $profile_pic);
    }
    
    $stmt = $pdo->prepare("UPDATE students SET profile_picture = NULL WHERE id = ? AND school_id = ?");
    $stmt->execute([$student_id, $school_id]);
    
    header("Location: manage-students.php?class_id=" . $class_id . "&message=Profile picture removed&type=success");
    exit();
}

// ============================================
// GET STUDENTS (ONLY WHEN CLASS SELECTED)
// ============================================

$selected_class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

$students = [];
$class_info = null;

if ($selected_class_id > 0) {
    // Get class info
    $stmt = $pdo->prepare("SELECT * FROM classes WHERE id = ? AND school_id = ?");
    $stmt->execute([$selected_class_id, $school_id]);
    $class_info = $stmt->fetch();
    
    if ($class_info) {
        // Build query
        $sql = "SELECT s.*, 
                (SELECT SUM(amount) FROM bills WHERE student_id = s.id AND status != 'paid') as total_owed
                FROM students s 
                WHERE s.school_id = ? AND s.class_id = ?";
        $params = [$school_id, $selected_class_id];
        
        if (!empty($search_query)) {
            $sql .= " AND (s.full_name LIKE ? OR s.admission_number LIKE ?)";
            $params[] = "%$search_query%";
            $params[] = "%$search_query%";
        }
        
        $sql .= " ORDER BY s.full_name";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $students = $stmt->fetchAll();
    }
}

// Get all classes for dropdown
$classes = $pdo->prepare("SELECT * FROM classes WHERE school_id = ? AND status = 'active' ORDER BY sort_order, class_name");
$classes->execute([$school_id]);
$classes = $classes->fetchAll();

// Get student for modal (AJAX)
if (isset($_GET['get_student'])) {
    $student_id = $_GET['get_student'];
    $stmt = $pdo->prepare("
        SELECT s.*, c.class_name as class_name_formatted
        FROM students s 
        LEFT JOIN classes c ON s.class_id = c.id
        WHERE s.id = ? AND s.school_id = ?
    ");
    $stmt->execute([$student_id, $school_id]);
    $student = $stmt->fetch();
    
    header('Content-Type: application/json');
    echo json_encode($student);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $school_name; ?> - Manage Students</title>
    
    <!-- QR Scanner Library -->
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: #d4af7a;
            --success-color: #27ae60;
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
            --info-color: #3498db;
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
        
        .admin-info {
            text-align: center;
            padding: 15px;
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
            margin: 0 15px 20px;
        }
        
        .nav-links {
            list-style: none;
            padding: 0 15px;
        }
        
        .nav-links li { margin-bottom: 5px; }
        
        .nav-links a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .nav-links a:hover, .nav-links a.active { background: rgba(255,255,255,0.2); }
        
        .main-content {
            margin-left: 0;
            padding: 20px;
            min-height: 100vh;
            transition: all 0.3s ease;
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
        
        .btn-primary { background: var(--primary-color); color: white; }
        .btn-success { background: var(--success-color); color: white; }
        .btn-danger { background: var(--danger-color); color: white; }
        .btn-warning { background: var(--warning-color); color: white; }
        .btn-info { background: var(--info-color); color: white; }
        .btn-small { padding: 6px 12px; font-size: 0.8rem; }
        
        .class-selector {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .class-selector-form {
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        
        .class-selector-form .form-group {
            flex: 1;
            min-width: 200px;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.9rem;
        }
        
        select.form-control, input[type="file"].form-control {
            cursor: pointer;
            padding: 8px 15px;
        }
        
        .students-list {
            background: white;
            border-radius: 10px;
            overflow: hidden;
        }
        
        .student-item {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: background 0.3s ease;
        }
        
        .student-item:hover {
            background: #f8f9fa;
        }
        
        .student-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.2rem;
            margin-right: 15px;
            flex-shrink: 0;
            overflow: hidden;
        }
        
        .student-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .student-info {
            flex: 1;
        }
        
        .student-name {
            font-weight: 600;
            font-size: 1rem;
            color: #333;
        }
        
        .student-admission {
            font-size: 0.8rem;
            color: #666;
        }
        
        .student-checkbox {
            margin-right: 15px;
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
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
        
        .modal-content {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 650px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
        }
        
        .modal-body { padding: 20px; }
        .modal-footer { padding: 20px; border-top: 1px solid #eee; display: flex; justify-content: flex-end; gap: 10px; position: sticky; bottom: 0; background: white; }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        .alert {
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
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
        
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid var(--info-color);
        }
        
        .info-row {
            display: flex;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }
        
        .info-label {
            width: 140px;
            font-weight: 600;
            color: #666;
        }
        
        .info-value {
            flex: 1;
            color: #333;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        
        .bulk-bar {
            background: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .profile-picture-preview {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            margin: 10px auto;
            display: block;
            border: 3px solid var(--primary-color);
        }
        
        .qr-code-img {
            max-width: 150px;
            margin: 10px auto;
            display: block;
            border: 1px solid #ddd;
            padding: 5px;
        }
        
        .selected-count {
            background: var(--primary-color);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .status-active {
            background: #d5f4e6;
            color: var(--success-color);
        }
        
        .status-inactive {
            background: #f8d7da;
            color: var(--danger-color);
        }
        
        .status-archived {
            background: #fff3cd;
            color: var(--warning-color);
        }
        
        .image-preview-container {
            text-align: center;
            margin-bottom: 15px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            font-size: 0.9rem;
        }
        
        @media (min-width: 769px) {
            .sidebar { transform: translateX(0); }
            .main-content { margin-left: var(--sidebar-width); }
            .mobile-menu-btn { display: none; }
        }
        
        @media (max-width: 768px) {
            .class-selector-form { flex-direction: column; }
            .bulk-bar { flex-direction: column; align-items: stretch; }
            .action-buttons { justify-content: center; }
            .info-row { flex-direction: column; }
            .info-label { width: 100%; margin-bottom: 5px; }
        }
        
        .text-muted { color: #999; }
        .mt-2 { margin-top: 10px; }

		/* Additional styles for scan feature */
.quick-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
    margin-top: 15px;
}

.attendance-summary {
    background: #f8f9fa;
    padding: 10px;
    border-radius: 8px;
    margin-top: 10px;
}

.bill-item {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.bill-amount {
    font-size: 1.2rem;
    font-weight: bold;
    color: var(--primary-color);
}

.bill-status-pending {
    color: var(--warning-color);
}

.bill-status-paid {
    color: var(--success-color);
}

.total-amount {
    font-size: 1.3rem;
    font-weight: bold;
    text-align: right;
    padding-top: 15px;
    margin-top: 15px;
    border-top: 2px solid #eee;
}
		/* Bill item styles */
.bill-item {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
}

.bill-amount {
    font-size: 1.1rem;
    font-weight: bold;
}

.bill-status-pending {
    color: #e74c3c;
}

.bill-status-paid {
    color: #27ae60;
}

.total-amount {
    font-size: 1.2rem;
    font-weight: bold;
    text-align: right;
    padding-top: 15px;
    margin-top: 15px;
    border-top: 2px solid #eee;
}

/* Attendance modal styles */
#attendanceStudentAvatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: var(--primary-color);
    color: white;
    margin: 0 auto;
    overflow: hidden;
}

/* Fix for modal z-index */
.modal {
    z-index: 10000;
}

/* Scanner result styles */
#scanResult {
    background: white;
    border-radius: 10px;
    padding: 15px;
    margin-top: 15px;
    border: 1px solid #ddd;
}
    </style>
</head>
<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>
    
    <div class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-text">
                <h3><?php echo $school_name; ?></h3>
                <p>Admin Panel</p>
            </div>
        </div>
        
        <div class="admin-info">
            <h4><?php echo htmlspecialchars($admin_name); ?></h4>
            <p><?php echo ucfirst(str_replace('_', ' ', $admin_role)); ?></p>
        </div>
        
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="manage-students.php" class="active"><i class="fas fa-users"></i> Manage Students</a></li>
                <li><a href="manage-staff.php"><i class="fas fa-chalkboard-teacher"></i> Manage Staff</a></li>
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
    
    <div class="main-content" id="mainContent">
        <div class="top-header">
            <div>
                <h1>Manage Students</h1>
                <p>Select a class to view and manage students</p>
            </div>
            <div>
                <button class="btn btn-info" onclick="openScanner()">
                    <i class="fas fa-camera"></i> Scan QR
                </button>
                <button class="btn btn-primary" onclick="openAddModal()">
                    <i class="fas fa-user-plus"></i> Add Student
                </button>
            </div>
        </div>
        
        <?php if (isset($_GET['message'])): ?>
            <div class="alert alert-<?php echo $_GET['type'] ?? 'success'; ?>">
                <i class="fas fa-<?php echo $_GET['type'] === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($_GET['message']); ?>
            </div>
        <?php endif; ?>
        
        <!-- Class Selector -->
        <div class="class-selector">
            <form method="GET" class="class-selector-form">
                <div class="form-group">
                    <label>Select Class</label>
                    <select name="class_id" class="form-control" required onchange="this.form.submit()">
                        <option value="">-- Select a class --</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $selected_class_id == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?> (<?php echo htmlspecialchars($class['class_code']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($selected_class_id > 0): ?>
                    <div class="form-group">
                        <input type="text" name="search" class="form-control" placeholder="Search students..." value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">Search</button>
                    <a href="manage-students.php?class_id=<?php echo $selected_class_id; ?>" class="btn btn-warning">Reset</a>
                <?php endif; ?>
            </form>
        </div>
        
        <?php if ($selected_class_id > 0 && $class_info): ?>
            <!-- Bulk Actions Bar -->
            <div class="bulk-bar">
                <div>
                    <label>
                        <input type="checkbox" id="selectAll"> Select All
                    </label>
                    <span id="selectedCount" class="selected-count" style="margin-left: 10px;">0 selected</span>
                </div>
                
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <form method="POST" id="transferForm" style="display: inline-flex; gap: 10px;">
                        <input type="hidden" name="action" value="transfer_students">
                        <input type="hidden" name="selected_students" id="transferSelectedStudents">
                        <input type="hidden" name="current_class_id" value="<?php echo $selected_class_id; ?>">
                        <select name="target_class_id" class="form-control" style="width: auto;" required>
                            <option value="">Transfer/Promote to...</option>
                            <?php foreach ($classes as $class): ?>
                                <?php if ($class['id'] != $selected_class_id): ?>
                                    <option value="<?php echo $class['id']; ?>">
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-success" onclick="return prepareTransfer()">
                            <i class="fas fa-arrow-right"></i> Move Selected
                        </button>
                    </form>
                    
                    <a href="bulk_generate_qr.php?class_id=<?php echo $selected_class_id; ?>" class="btn btn-info">
                        <i class="fas fa-qrcode"></i> Generate Class QR Codes
                    </a>
                </div>
            </div>
            
            <!-- Students List -->
            <div class="students-list">
                <?php if (empty($students)): ?>
                    <div class="empty-state">
                        <i class="fas fa-user-graduate" style="font-size: 48px; margin-bottom: 15px;"></i>
                        <p>No students found in this class</p>
                        <button class="btn btn-primary" onclick="openAddModal()">Add Student</button>
                    </div>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                        <div class="student-item" onclick="viewStudent(<?php echo $student['id']; ?>, event)">
                            <input type="checkbox" class="student-checkbox" value="<?php echo $student['id']; ?>" onclick="event.stopPropagation()">
                            <div class="student-avatar">
                                <?php if (!empty($student['profile_picture'])): ?>
                                    <img src="<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="<?php echo htmlspecialchars($student['full_name']); ?>">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($student['full_name'], 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="student-info">
                                <div class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                <div class="student-admission">Adm: <?php echo htmlspecialchars($student['admission_number']); ?></div>
                            </div>
                            <div class="student-status">
                                <span class="status-badge status-<?php echo $student['status']; ?>">
                                    <?php echo ucfirst($student['status']); ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php elseif ($selected_class_id > 0 && !$class_info): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i> Class not found
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-layer-group" style="font-size: 48px; margin-bottom: 15px;"></i>
                <p>Please select a class to view students</p>
                <a href="manage-classes.php" class="btn btn-primary">Manage Classes</a>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Student Detail Modal -->
    <div class="modal" id="studentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Student Details</h3>
                <button class="close-modal" onclick="closeStudentModal()">&times;</button>
            </div>
            <div class="modal-body" id="studentModalBody">
                <div style="text-align: center;">Loading...</div>
            </div>
        </div>
    </div>
    
    <!-- Add Student Modal -->
    <div class="modal" id="addStudentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Student</h3>
                <button class="close-modal" onclick="closeAddModal()">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_student">
                <div class="modal-body">
                    <div class="image-preview-container">
                        <img id="addImagePreview" class="profile-picture-preview" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100' viewBox='0 0 100 100'%3E%3Ccircle cx='50' cy='50' r='50' fill='%23ddd'/%3E%3Ctext x='50' y='67' text-anchor='middle' fill='%23999' font-size='40'%3E📷%3C/text%3E%3C/svg%3E">
                        <small>Click to upload photo (max 100KB)</small>
                    </div>
                    <div class="form-group">
                        <label>Profile Picture</label>
                        <input type="file" name="profile_picture" class="form-control" accept="image/jpeg,image/png,image/gif" onchange="previewImage(this, 'addImagePreview')">
                    </div>
                    <div class="form-group">
                        <label>Admission Number *</label>
                        <input type="text" name="admission_number" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Class *</label>
                        <select name="class_id" class="form-control" required>
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $selected_class_id == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Parent Phone</label>
                        <input type="text" name="parent_phone" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Parent Email</label>
                        <input type="email" name="parent_email" class="form-control">
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> QR code will be auto-generated. Default password is student's surname (lowercase)
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-warning" onclick="closeAddModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Student</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Student Modal -->
    <div class="modal" id="editStudentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Student</h3>
                <button class="close-modal" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="editStudentForm">
                <input type="hidden" name="action" value="update_student">
                <input type="hidden" name="student_id" id="edit_student_id">
                <div class="modal-body">
                    <div class="image-preview-container">
                        <img id="editImagePreview" class="profile-picture-preview" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100' viewBox='0 0 100 100'%3E%3Ccircle cx='50' cy='50' r='50' fill='%23ddd'/%3E%3Ctext x='50' y='67' text-anchor='middle' fill='%23999' font-size='40'%3E📷%3C/text%3E%3C/svg%3E">
                        <small>Click to change photo (max 100KB)</small>
                    </div>
                    <div class="form-group">
                        <label>Profile Picture</label>
                        <input type="file" name="profile_picture" class="form-control" accept="image/jpeg,image/png,image/gif" onchange="previewImage(this, 'editImagePreview')">
                    </div>
                    <div class="form-group">
                        <label>Admission Number</label>
                        <input type="text" id="edit_admission_number" name="admission_number" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" id="edit_full_name" name="full_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Class</label>
                        <select name="class_id" id="edit_class_id" class="form-control" required>
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>">
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Parent Phone</label>
                        <input type="text" id="edit_parent_phone" name="parent_phone" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Parent Email</label>
                        <input type="email" id="edit_parent_email" name="parent_email" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select id="edit_status" name="status" class="form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-warning" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Student</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- QR Code Modal -->
    <div class="modal" id="qrModal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <h3><i class="fas fa-qrcode"></i> <span id="qrStudentName"></span></h3>
                <button class="close-modal" onclick="closeQRModal()">&times;</button>
            </div>
            <div class="modal-body" style="text-align:center;">
                <img id="qrCodeImage" src="" style="width:250px; height:250px; margin:10px auto; border:1px solid #ddd; padding:10px;">
                <div class="alert alert-info" style="margin-top: 10px;">
                    <i class="fas fa-print"></i> Print and stick on student's ID card
                </div>
                <div class="action-buttons" style="justify-content:center; margin-top: 10px;">
                    <button class="btn btn-primary" onclick="printQRCode()"><i class="fas fa-print"></i> Print</button>
                    <button class="btn btn-success" onclick="downloadQRCode()"><i class="fas fa-download"></i> Download</button>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-warning" onclick="closeQRModal()">Close</button>
            </div>
        </div>
    </div>
    
    <!-- Scan QR Modal - Enhanced Version -->
<div class="modal" id="scanModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-camera"></i> Scan Student QR Code</h3>
            <button class="close-modal" onclick="closeScanModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="reader" style="width:100%;"></div>
            <div id="scanResult" style="display:none; margin-top:15px;">
                <div class="alert alert-info" id="scanStudentInfo"></div>
                <div class="action-buttons" id="scanActions" style="justify-content:center;">
                    <button class="btn btn-primary" onclick="viewScannedStudent()">
                        <i class="fas fa-user"></i> View Full Details
                    </button>
                    <button class="btn btn-success" onclick="markAttendanceFromScan()">
                        <i class="fas fa-calendar-check"></i> Mark Attendance
                    </button>
                    <button class="btn btn-info" onclick="viewBillsFromScan()">
                        <i class="fas fa-money-bill"></i> View Bills
                    </button>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-primary" onclick="startScanner()"><i class="fas fa-play"></i> Start Camera</button>
            <button class="btn btn-warning" onclick="stopScanner()"><i class="fas fa-stop"></i> Stop Camera</button>
        </div>
    </div>
</div>

<!-- Attendance Modal (for marking attendance from scan) -->
<div class="modal" id="attendanceModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-calendar-check"></i> Mark Attendance</h3>
            <button class="close-modal" onclick="closeAttendanceModal()">&times;</button>
        </div>
        <form method="POST" id="attendanceForm">
            <input type="hidden" name="action" value="mark_attendance">
            <input type="hidden" name="student_id" id="attendanceStudentId">
            <div class="modal-body">
                <div style="text-align:center; margin-bottom:20px;">
                    <div id="attendanceStudentAvatar" class="student-avatar" style="width:80px;height:80px;margin:0 auto;">📷</div>
                    <h3 id="attendanceStudentName" style="margin-top:10px;"></h3>
                    <p id="attendanceStudentClass" style="color:#666;"></p>
                </div>
                <div class="form-group">
                    <label>Attendance Status</label>
                    <select name="status" class="form-control" required>
                        <option value="present">✅ Present</option>
                        <option value="late">⏰ Late</option>
                        <option value="absent">❌ Absent</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Date</label>
                    <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="alert alert-info" id="attendanceInfo"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-warning" onclick="closeAttendanceModal()">Cancel</button>
                <button type="submit" class="btn btn-success">Save Attendance</button>
            </div>
        </form>
    </div>
</div>

<!-- Bills Modal (for viewing bills from scan) -->
<div class="modal" id="billsModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-money-bill-wave"></i> Student Bills & Payments</h3>
            <button class="close-modal" onclick="closeBillsModal()">&times;</button>
        </div>
        <div class="modal-body" id="billsModalBody">
            <div style="text-align:center;">Loading...</div>
        </div>
    </div>
</div>
    
    <script>
        let html5QrcodeScanner = null;
        let currentStudentData = null;
        let scannedStudentData = null;
        let currentScanner = null;
        
        // Sidebar toggle
        document.getElementById('mobileMenuBtn').onclick = () => {
            document.getElementById('sidebar').classList.toggle('active');
        };
        
        // Image preview
        function previewImage(input, previewId) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById(previewId).src = e.target.result;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Checkbox selection
        function updateSelectedCount() {
            const checked = document.querySelectorAll('.student-checkbox:checked').length;
            const countSpan = document.getElementById('selectedCount');
            if (countSpan) countSpan.innerHTML = checked + ' selected';
            const selectAll = document.getElementById('selectAll');
            const allBoxes = document.querySelectorAll('.student-checkbox');
            if (selectAll) selectAll.checked = checked === allBoxes.length && checked > 0;
        }
        
        if (document.getElementById('selectAll')) {
            document.getElementById('selectAll').addEventListener('change', function() {
                document.querySelectorAll('.student-checkbox').forEach(cb => cb.checked = this.checked);
                updateSelectedCount();
            });
        }
        
        document.querySelectorAll('.student-checkbox').forEach(cb => cb.addEventListener('change', updateSelectedCount));
        
        function prepareTransfer() {
            const selected = [];
            document.querySelectorAll('.student-checkbox:checked').forEach(cb => selected.push(cb.value));
            
            if (selected.length === 0) {
                alert('Please select at least one student to transfer');
                return false;
            }
            
            const targetClass = document.querySelector('select[name="target_class_id"]').value;
            if (!targetClass) {
                alert('Please select a target class');
                return false;
            }
            
            document.getElementById('transferSelectedStudents').value = selected.join(',');
            return confirm(`Transfer ${selected.length} student(s) to the selected class?`);
        }
        
        // View student details
        function viewStudent(studentId, event) {
            if (event.target.type === 'checkbox') return;
            
            fetch(`?get_student=${studentId}`)
                .then(response => response.json())
                .then(data => {
                    currentStudentData = data;
                    displayStudentModal(data);
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading student details');
                });
        }
        
        function displayStudentModal(student) {
            const modalBody = document.getElementById('studentModalBody');
            
            // Use the QR generation endpoint
            const qrImageUrl = `generate_qr.php?student_id=${student.id}&mode=display`;
            
            let profilePicHtml = '';
            if (student.profile_picture && student.profile_picture.trim() !== '') {
                profilePicHtml = `<img src="${student.profile_picture}" class="profile-picture-preview" style="width:100px;height:100px;margin:0 auto;">`;
            } else {
                profilePicHtml = `<div class="student-avatar" style="width:100px;height:100px;margin:0 auto;font-size:2rem;">${student.full_name.charAt(0)}</div>`;
            }
            
            modalBody.innerHTML = `
                <div style="text-align:center; margin-bottom:20px;">
                    ${profilePicHtml}
                </div>
                <div class="info-row">
                    <div class="info-label">Admission No:</div>
                    <div class="info-value"><strong>${student.admission_number}</strong></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Full Name:</div>
                    <div class="info-value">${escapeHtml(student.full_name)}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Class:</div>
                    <div class="info-value">${student.class_name_formatted || student.class}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Parent Phone:</div>
                    <div class="info-value">${student.parent_phone || 'Not provided'}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Parent Email:</div>
                    <div class="info-value">${student.parent_email || 'Not provided'}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Status:</div>
                    <div class="info-value">
                        <span class="status-badge status-${student.status}">${student.status.toUpperCase()}</span>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">QR Code:</div>
                    <div class="info-value">
                        <img src="${qrImageUrl}" class="qr-code-img" alt="QR Code" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\\'http://www.w3.org/2000/svg\\' width=\\'150\\' height=\\'150\\' viewBox=\\'0 0 150 150\\'%3E%3Crect width=\\'150\\' height=\\'150\\' fill=\\'%23f0f0f0\\'/%3E%3Ctext x=\\'75\\' y=\\'75\\' text-anchor=\\'middle\\' dy=\\'.3em\\' fill=\\'%23999\\' font-size=\\'12\\'%3EQR Code%3C/text%3E%3C/svg%3E'">
                        <div class="mt-2">
                            <button class="btn btn-info btn-small" onclick="showQRCode(${student.id}, '${escapeHtml(student.full_name).replace(/'/g, "\\'")}')">
                                <i class="fas fa-expand"></i> View Full Size
                            </button>
                            <button class="btn btn-warning btn-small" onclick="regenerateQR(${student.id})">
                                <i class="fas fa-sync-alt"></i> Regenerate QR
                            </button>
                        </div>
                    </div>
                </div>
                <div class="action-buttons">
                    <button class="btn btn-primary" onclick="editStudentFromModal()">
                        <i class="fas fa-edit"></i> Edit Student
                    </button>
                    <form method="POST" action="manage-students.php?class_id=${currentClassId()}" style="display:inline;">
                        <input type="hidden" name="action" value="remove_picture">
                        <input type="hidden" name="student_id" value="${student.id}">
                        <input type="hidden" name="class_id" value="${currentClassId()}">
                        <button type="submit" class="btn btn-warning" onclick="return confirm('Remove profile picture?')">
                            <i class="fas fa-image"></i> Remove Photo
                        </button>
                    </form>
                    <button class="btn btn-danger" onclick="deleteStudent(${student.id}, '${escapeHtml(student.full_name).replace(/'/g, "\\'")}')">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </div>
            `;
            document.getElementById('studentModal').style.display = 'flex';
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function currentClassId() {
            return <?php echo $selected_class_id; ?>;
        }
        
        function editStudentFromModal() {
            if (currentStudentData) {
                openEditModal(currentStudentData);
                closeStudentModal();
            }
        }
        
        function closeStudentModal() {
            document.getElementById('studentModal').style.display = 'none';
        }
        
        // Modal functions
        function openAddModal() {
            document.getElementById('addStudentModal').style.display = 'flex';
        }
        
        function closeAddModal() {
            document.getElementById('addStudentModal').style.display = 'none';
            document.getElementById('addImagePreview').src = 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'100\' height=\'100\' viewBox=\'0 0 100 100\'%3E%3Ccircle cx=\'50\' cy=\'50\' r=\'50\' fill=\'%23ddd\'/%3E%3Ctext x=\'50\' y=\'67\' text-anchor=\'middle\' fill=\'%23999\' font-size=\'40\'%3E📷%3C/text%3E%3C/svg%3E';
        }
        
        function openEditModal(student) {
            document.getElementById('edit_student_id').value = student.id;
            document.getElementById('edit_admission_number').value = student.admission_number;
            document.getElementById('edit_full_name').value = student.full_name;
            document.getElementById('edit_class_id').value = student.class_id;
            document.getElementById('edit_parent_phone').value = student.parent_phone || '';
            document.getElementById('edit_parent_email').value = student.parent_email || '';
            document.getElementById('edit_status').value = student.status;
            
            // Set image preview
            if (student.profile_picture && student.profile_picture.trim() !== '') {
                document.getElementById('editImagePreview').src = student.profile_picture;
            } else {
                document.getElementById('editImagePreview').src = 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'100\' height=\'100\' viewBox=\'0 0 100 100\'%3E%3Ccircle cx=\'50\' cy=\'50\' r=\'50\' fill=\'%23ddd\'/%3E%3Ctext x=\'50\' y=\'67\' text-anchor=\'middle\' fill=\'%23999\' font-size=\'40\'%3E📷%3C/text%3E%3C/svg%3E';
            }
            
            document.getElementById('editStudentModal').style.display = 'flex';
        }
        
        function closeEditModal() {
            document.getElementById('editStudentModal').style.display = 'none';
        }
        
        function deleteStudent(id, name) {
            if (confirm(`Delete student "${name}"? This cannot be undone.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_student">
                    <input type="hidden" name="student_id" value="${id}">
                    <input type="hidden" name="class_id" value="${currentClassId()}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // QR Code functions
        function showQRCode(studentId, studentName) {
            document.getElementById('qrStudentName').innerText = studentName;
            document.getElementById('qrCodeImage').src = `generate_qr.php?student_id=${studentId}&mode=display&t=${Date.now()}`;
            document.getElementById('qrModal').style.display = 'flex';
        }
        
        function regenerateQR(studentId) {
            if (confirm('Generate/Regenerate QR code for this student?')) {
                fetch(`regenerate_qr.php?student_id=${studentId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('QR code generated successfully!');
                            // Refresh the student modal
                            viewStudent(studentId, { target: { type: '' } });
                        } else {
                            alert('Error: ' + (data.error || 'Unknown error'));
                        }
                    })
                    .catch(error => {
                        alert('Error generating QR code');
                        console.error(error);
                    });
            }
        }
        
        function closeQRModal() {
            document.getElementById('qrModal').style.display = 'none';
        }
        
        function printQRCode() {
            const img = document.getElementById('qrCodeImage');
            const win = window.open('');
            win.document.write(`
                <html>
                    <head>
                        <title>Print QR Code</title>
                        <style>
                            body {
                                display: flex;
                                justify-content: center;
                                align-items: center;
                                height: 100vh;
                                margin: 0;
                                font-family: Arial, sans-serif;
                            }
                            .container {
                                text-align: center;
                            }
                            img {
                                width: 300px;
                                height: 300px;
                                border: 2px solid #ddd;
                                padding: 10px;
                            }
                            h3 {
                                margin-top: 20px;
                                color: #333;
                            }
                        </style>
                    </head>
                    <body>
                        <div class="container">
                            <img src="${img.src}">
                            <h3>Student QR Code</h3>
                        </div>
                        <script>window.print();<\/script>
                    </body>
                </html>
            `);
        }
        
        function downloadQRCode() {
            const link = document.createElement('a');
            link.download = 'student_qrcode.png';
            link.href = document.getElementById('qrCodeImage').src;
            link.click();
        }
        
        // Scanner functions (UPDATED with working code)
        let currentScannedStudent = null;
        
        function openScanner() {
            document.getElementById('scanModal').style.display = 'flex';
            document.getElementById('scanResult').style.display = 'none';
            scannedStudentData = null;
            currentScannedStudent = null;
        }
        
        function closeScanModal() {
            if (currentScanner) {
                stopScanner();
            }
            document.getElementById('scanModal').style.display = 'none';
            document.getElementById('scanResult').style.display = 'none';
        }
        
        function startScanner() {
            if (currentScanner) {
                currentScanner.stop();
            }
            
            const readerElement = document.getElementById('reader');
            if (!readerElement) return;
            
            readerElement.innerHTML = '';
            
            currentScanner = new Html5Qrcode("reader");
            currentScanner.start(
                { facingMode: "environment" }, 
                { 
                    fps: 10, 
                    qrbox: { width: 250, height: 250 }
                },
                (decodedText) => {
                    handleScannedData(decodedText);
                },
                (error) => { 
                    console.log("Scanning..."); 
                }
            ).catch(err => {
                console.error("Failed to start scanner:", err);
                alert("Failed to start camera. Please ensure camera permissions are granted.");
            });
        }
        
        function stopScanner() {
            if (currentScanner) {
                currentScanner.stop().then(() => {
                    currentScanner = null;
                }).catch(err => {
                    console.error("Error stopping scanner:", err);
                });
            }
        }
        
        function handleScannedData(decodedText) {
            try {
                let data;
                try {
                    data = JSON.parse(decodeURIComponent(decodedText));
                } catch(e) {
                    data = JSON.parse(decodedText);
                }
                
                if (data.type === 'student' && data.id) {
                    stopScanner();
                    fetchScannedStudentInfo(data.id);
                } else {
                    alert('Invalid student QR code format');
                }
            } catch(e) {
                const studentId = parseInt(decodedText);
                if (studentId > 0) {
                    stopScanner();
                    fetchScannedStudentInfo(studentId);
                } else {
                    alert('Invalid QR code. Please scan a valid student QR code.');
                }
            }
        }
        
        function fetchScannedStudentInfo(studentId) {
            fetch(`?get_student=${studentId}`)
                .then(response => response.json())
                .then(data => {
                    currentScannedStudent = data;
                    displayScanResult(data);
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading student details');
                });
        }
        
        function displayScanResult(student) {
            const scanResult = document.getElementById('scanResult');
            const scanStudentInfo = document.getElementById('scanStudentInfo');
            
            let avatarHtml = '';
            if (student.profile_picture && student.profile_picture.trim() !== '') {
                avatarHtml = `<img src="${student.profile_picture}" style="width:60px;height:60px;border-radius:50%;object-fit:cover;">`;
            } else {
                avatarHtml = `<div class="student-avatar" style="width:60px;height:60px;margin:0 auto;font-size:1.5rem;display:flex;align-items:center;justify-content:center;">${student.full_name.charAt(0)}</div>`;
            }
            
            scanStudentInfo.innerHTML = `
                <div style="text-align:center;">
                    ${avatarHtml}
                    <h3 style="margin:10px 0 5px;">${escapeHtml(student.full_name)}</h3>
                    <p style="color:#666;">Adm: ${student.admission_number} | Class: ${student.class_name_formatted || student.class}</p>
                    <p style="color:#666;">Parent: ${student.parent_phone || 'No phone'}</p>
                </div>
            `;
            
            scanResult.style.display = 'block';
        }
        
        function viewScannedStudent() {
            if (currentScannedStudent) {
                closeScanModal();
                // Call the existing viewStudent function
                viewStudent(currentScannedStudent.id, { target: { type: '' } });
            }
        }
        
        function markAttendanceFromScan() {
            if (currentScannedStudent) {
                closeScanModal();
                // Open attendance modal with the scanned student
                openAttendanceModalFromScan(currentScannedStudent);
            }
        }
        
        function openAttendanceModalFromScan(student) {
            document.getElementById('attendanceStudentId').value = student.id;
            document.getElementById('attendanceStudentName').innerHTML = escapeHtml(student.full_name);
            document.getElementById('attendanceStudentClass').innerHTML = `Adm: ${student.admission_number} | Class: ${student.class_name_formatted || student.class}`;
            
            const avatarDiv = document.getElementById('attendanceStudentAvatar');
            if (student.profile_picture && student.profile_picture.trim() !== '') {
                avatarDiv.innerHTML = `<img src="${student.profile_picture}" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">`;
            } else {
                avatarDiv.innerHTML = student.full_name.charAt(0);
                avatarDiv.style.display = 'flex';
                avatarDiv.style.alignItems = 'center';
                avatarDiv.style.justifyContent = 'center';
                avatarDiv.style.fontSize = '2rem';
                avatarDiv.style.background = 'var(--primary-color)';
                avatarDiv.style.color = 'white';
            }
            
            // Check if attendance already marked today
            const today = new Date().toISOString().split('T')[0];
            fetch(`check_attendance.php?student_id=${student.id}&date=${today}`)
                .then(response => response.json())
                .then(data => {
                    const infoDiv = document.getElementById('attendanceInfo');
                    if (data.marked) {
                        infoDiv.innerHTML = `<i class="fas fa-info-circle"></i> Attendance already marked as "${data.status}" for today. You can update it.`;
                        document.querySelector('#attendanceForm select[name="status"]').value = data.status;
                    } else {
                        infoDiv.innerHTML = `<i class="fas fa-info-circle"></i> No attendance recorded for today.`;
                        document.querySelector('#attendanceForm select[name="status"]').value = 'present';
                    }
                })
                .catch(() => {
                    document.getElementById('attendanceInfo').innerHTML = '';
                });
            
            document.getElementById('attendanceModal').style.display = 'flex';
        }
        
        function viewBillsFromScan() {
            if (currentScannedStudent) {
                closeScanModal();
                openBillsModalFromScan(currentScannedStudent);
            }
        }
        
        function openBillsModalFromScan(student) {
            const modalBody = document.getElementById('billsModalBody');
            modalBody.innerHTML = '<div style="text-align:center;"><i class="fas fa-spinner fa-spin"></i> Loading bills...</div>';
            document.getElementById('billsModal').style.display = 'flex';
            
            // Fetch bills for this student
            fetch(`get_student_bills.php?student_id=${student.id}`)
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        modalBody.innerHTML = `<div class="alert alert-error">Error: ${data.error}</div>`;
                        return;
                    }
                    
                    let billsHtml = `
                        <div style="text-align:center; margin-bottom:20px;">
                            <h3>${escapeHtml(student.full_name)}</h3>
                            <p>Admission: ${student.admission_number} | Class: ${student.class_name_formatted || student.class}</p>
                        </div>
                    `;
                    
                    if (data.bills && data.bills.length > 0) {
                        let totalPending = 0;
                        let totalPaid = 0;
                        
                        billsHtml += `<h4>Fee Summary</h4>`;
                        
                        data.bills.forEach(bill => {
                            const isPaid = bill.status === 'paid';
                            if (!isPaid) totalPending += parseFloat(bill.amount);
                            if (isPaid) totalPaid += parseFloat(bill.amount);
                            
                            billsHtml += `
                                <div class="bill-item">
                                    <div>
                                        <strong>${escapeHtml(bill.description || bill.bill_type)}</strong><br>
                                        <small>Due: ${bill.due_date || 'Not set'} | ${bill.status.toUpperCase()}</small>
                                    </div>
                                    <div class="bill-amount ${bill.status === 'paid' ? 'bill-status-paid' : 'bill-status-pending'}">
                                        ₦${parseFloat(bill.amount).toLocaleString()}
                                    </div>
                                </div>
                            `;
                        });
                        
                        billsHtml += `
                            <div class="total-amount">
                                <div>Total Paid: <span style="color:green;">₦${totalPaid.toLocaleString()}</span></div>
                                <div>Total Outstanding: <span style="color:#e74c3c;">₦${totalPending.toLocaleString()}</span></div>
                            </div>
                        `;
                    } else {
                        billsHtml += `<div class="alert alert-info">No bills found for this student.</div>`;
                    }
                    
                    modalBody.innerHTML = billsHtml;
                })
                .catch(error => {
                    modalBody.innerHTML = `<div class="alert alert-error">Error loading bills: ${error.message}</div>`;
                });
        }
        
        function closeBillsModal() {
            document.getElementById('billsModal').style.display = 'none';
        }
        
        function closeAttendanceModal() {
            document.getElementById('attendanceModal').style.display = 'none';
        }
        
        // Handle attendance form submission
        if (document.getElementById('attendanceForm')) {
            document.getElementById('attendanceForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                
                fetch('mark_attendance_api.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Attendance marked successfully!');
                        closeAttendanceModal();
                    } else {
                        alert('Error: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(error => {
                    alert('Error marking attendance: ' + error.message);
                });
            });
        }
        
        // Initialize checkbox count
        updateSelectedCount();
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = ['studentModal', 'addStudentModal', 'editStudentModal', 'qrModal', 'scanModal', 'attendanceModal', 'billsModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    modal.style.display = 'none';
                    if (modalId === 'scanModal' && currentScanner) {
                        stopScanner();
                    }
                }
            });
        };
    </script>
</body>
</html>
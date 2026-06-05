<?php
// admin/manage-students.php - Complete Student Management with First/Middle/Last Name Fields
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /gsa/login.php");
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
$page_title = "Manage Students";

// ============================================
// ENSURE FIRST_NAME, MIDDLE_NAME & LAST_NAME COLUMNS EXIST
// ============================================

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM students LIKE 'first_name'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE students ADD COLUMN first_name VARCHAR(100) AFTER admission_number");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM students LIKE 'middle_name'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE students ADD COLUMN middle_name VARCHAR(100) AFTER first_name");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM students LIKE 'last_name'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE students ADD COLUMN last_name VARCHAR(100) AFTER middle_name");
    }
} catch (Exception $e) {
    error_log("Table alter error: " . $e->getMessage());
}

// Helper function to build full name from parts
function buildFullName($first_name, $middle_name, $last_name)
{
    $parts = array_filter([$first_name, $middle_name, $last_name]);
    return implode(' ', $parts);
}

// ============================================
// HANDLE POST REQUESTS
// ============================================

// Add new student
if (isset($_POST['action']) && $_POST['action'] === 'add_student') {
    $admission_number = trim($_POST['admission_number']);
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name']);
    $full_name = buildFullName($first_name, $middle_name, $last_name);
    $class_id = $_POST['class_id'];
    $status = 'active';
    $parent_phone = trim($_POST['parent_phone'] ?? '');
    $parent_email = trim($_POST['parent_email'] ?? '');

    // Password: use custom password if provided, otherwise use last_name
    $custom_password = trim($_POST['password'] ?? '');
    if (!empty($custom_password)) {
        $password = password_hash($custom_password, PASSWORD_DEFAULT);
    } else {
        $password = password_hash(strtolower($last_name), PASSWORD_DEFAULT);
    }

    // Get class name from class_id
    $stmt = $pdo->prepare("SELECT class_name FROM classes WHERE id = ? AND school_id = ?");
    $stmt->execute([$class_id, $school_id]);
    $class = $stmt->fetch();
    $class_name = $class ? $class['class_name'] : '';

    try {
        $stmt = $pdo->prepare("INSERT INTO students (admission_number, first_name, middle_name, last_name, password, full_name, class, class_id, status, parent_phone, parent_email, school_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$admission_number, $first_name, $middle_name, $last_name, $password, $full_name, $class_name, $class_id, $status, $parent_phone, $parent_email, $school_id]);

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
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name']);
    $full_name = buildFullName($first_name, $middle_name, $last_name);
    $class_id = $_POST['class_id'];
    $status = $_POST['status'] ?? 'active';
    $parent_phone = trim($_POST['parent_phone'] ?? '');
    $parent_email = trim($_POST['parent_email'] ?? '');

    // Update password if provided
    $custom_password = trim($_POST['password'] ?? '');
    $password_sql = "";
    $password_params = [];
    if (!empty($custom_password)) {
        $hashed_password = password_hash($custom_password, PASSWORD_DEFAULT);
        $password_sql = ", password = ?";
        $password_params = [$hashed_password];
    }

    // Get class name
    $stmt = $pdo->prepare("SELECT class_name FROM classes WHERE id = ? AND school_id = ?");
    $stmt->execute([$class_id, $school_id]);
    $class = $stmt->fetch();
    $class_name = $class ? $class['class_name'] : '';

    try {
        $sql = "UPDATE students SET admission_number = ?, first_name = ?, middle_name = ?, last_name = ?, full_name = ?, class = ?, class_id = ?, status = ?, parent_phone = ?, parent_email = ?" . $password_sql . " WHERE id = ? AND school_id = ?";
        $params = array_merge([$admission_number, $first_name, $middle_name, $last_name, $full_name, $class_name, $class_id, $status, $parent_phone, $parent_email], $password_params, [$student_id, $school_id]);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

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

        // Regenerate QR code if name changed
        $qr_url = generateStudentQRCode($student_id, $admission_number, $full_name);
        saveStudentQRCode($pdo, $student_id, $qr_url);

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
// GET DATA
// ============================================

// Get all classes for display
$classes = $pdo->prepare("SELECT * FROM classes WHERE school_id = ? AND status = 'active' ORDER BY sort_order, class_name");
$classes->execute([$school_id]);
$all_classes = $classes->fetchAll();

// Get student count for each class
foreach ($all_classes as &$class) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE class_id = ? AND school_id = ?");
    $stmt->execute([$class['id'], $school_id]);
    $class['student_count'] = $stmt->fetchColumn();
}
unset($class);

// Get current selected class
$selected_class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$global_search_query = isset($_GET['global_search']) ? trim($_GET['global_search']) : '';
$view_mode = isset($_GET['class_id']) ? 'students' : 'classes';

// If global search is active, override view mode to show search results
$is_global_search = !empty($global_search_query);
if ($is_global_search) {
    $view_mode = 'search_results';
}

$students = [];
$current_class = null;

if ($selected_class_id > 0 && !$is_global_search) {
    // Get current class info
    $stmt = $pdo->prepare("SELECT * FROM classes WHERE id = ? AND school_id = ?");
    $stmt->execute([$selected_class_id, $school_id]);
    $current_class = $stmt->fetch();

    if ($current_class) {
        // Build query to get students
        $sql = "SELECT s.*, 
                (SELECT SUM(amount) FROM bills WHERE student_id = s.id AND status != 'paid') as total_owed
                FROM students s 
                WHERE s.school_id = ? AND s.class_id = ?";
        $params = [$school_id, $selected_class_id];

        if (!empty($search_query)) {
            $sql .= " AND (s.full_name LIKE ? OR s.admission_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ?)";
            $params[] = "%$search_query%";
            $params[] = "%$search_query%";
            $params[] = "%$search_query%";
            $params[] = "%$search_query%";
        }

        $sql .= " ORDER BY s.last_name, s.first_name";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $students = $stmt->fetchAll();
    }
}

// Handle global search across all classes
if ($is_global_search) {
    $sql = "SELECT s.*, c.class_name as class_name_formatted,
            (SELECT SUM(amount) FROM bills WHERE student_id = s.id AND status != 'paid') as total_owed
            FROM students s 
            LEFT JOIN classes c ON s.class_id = c.id
            WHERE s.school_id = ? 
            AND (s.full_name LIKE ? OR s.admission_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ?)";
    $params = [$school_id, "%$global_search_query%", "%$global_search_query%", "%$global_search_query%", "%$global_search_query%"];
    $sql .= " ORDER BY c.class_name, s.last_name, s.first_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $global_search_students = $stmt->fetchAll();
}

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

// Include sidebar
require_once 'includes/sidebar.php';
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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --primary-dark: #1a5a8a;
            --secondary-color: #d4af7a;
            --success-color: #27ae60;
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
            --info-color: #3498db;
            --purple-color: #9b59b6;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --gray-50: #f9fafb;
            --gray-100: #f0f2f5;
            --gray-200: #e4e7eb;
            --gray-400: #9ca3af;
            --gray-600: #6b7280;
            --gray-800: #1f2937;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
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
            padding: 15px 25px;
            border-radius: var(--radius-lg);
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: var(--shadow-sm);
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.4rem;
            margin-bottom: 4px;
        }

        .header-title p {
            color: var(--gray-600);
            font-size: 0.75rem;
        }

        /* Buttons */
        .btn {
            padding: 8px 18px;
            border-radius: var(--radius-md);
            border: none;
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 0.8rem;
            transition: all 0.2s;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-info {
            background: var(--info-color);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--gray-200);
            color: var(--gray-800);
        }

        .btn-outline:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.7rem;
        }

        /* Global Search Bar */
        .global-search-bar {
            background: white;
            border-radius: var(--radius-lg);
            padding: 15px 20px;
            margin-bottom: 25px;
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            box-shadow: var(--shadow-sm);
            border: 2px solid var(--gray-200);
        }

        .global-search-bar .search-icon {
            color: var(--primary-color);
            font-size: 1.2rem;
        }

        .global-search-bar input {
            flex: 1;
            min-width: 250px;
            padding: 12px 15px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 0.9rem;
            transition: border-color 0.2s;
        }

        .global-search-bar input:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .global-search-bar .search-btn {
            padding: 12px 24px;
            font-size: 0.9rem;
        }

        .global-search-bar .clear-btn {
            background: var(--gray-200);
            color: var(--gray-800);
        }

        .global-search-bar .clear-btn:hover {
            background: var(--gray-400);
        }

        /* Class List */
        .class-list {
            background: white;
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .class-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            border-bottom: 1px solid var(--gray-200);
            cursor: pointer;
            transition: background 0.2s;
        }

        .class-item:hover {
            background: var(--gray-50);
        }

        .class-item:last-child {
            border-bottom: none;
        }

        .class-name {
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--gray-800);
        }

        .class-student-count {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--gray-600);
            font-size: 0.75rem;
        }

        /* Back Navigation */
        .back-nav {
            background: white;
            border-radius: var(--radius-lg);
            padding: 12px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: var(--shadow-sm);
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: none;
            border: none;
            color: var(--primary-color);
            font-weight: 500;
            cursor: pointer;
            font-size: 0.85rem;
        }

        .class-dropdown select {
            padding: 8px 12px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 0.85rem;
            background: white;
            cursor: pointer;
        }

        /* Search Bar */
        .search-bar {
            background: white;
            border-radius: var(--radius-lg);
            padding: 12px 20px;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            box-shadow: var(--shadow-sm);
        }

        .search-bar input {
            flex: 1;
            min-width: 200px;
            padding: 8px 15px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 0.85rem;
        }

        /* Student List */
        .students-list {
            background: white;
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .student-item {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            border-bottom: 1px solid var(--gray-200);
            cursor: pointer;
            transition: background 0.2s;
        }

        .student-item:hover {
            background: var(--gray-50);
        }

        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1rem;
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
            font-size: 0.9rem;
            color: var(--gray-800);
        }

        .student-class-badge {
            font-size: 0.7rem;
            color: var(--primary-color);
            background: rgba(52, 152, 219, 0.1);
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            margin-top: 2px;
        }

        .student-admission {
            font-size: 0.7rem;
            color: var(--gray-600);
        }

        .student-checkbox {
            margin-right: 15px;
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.65rem;
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

        .bulk-bar {
            background: white;
            padding: 12px 20px;
            border-radius: var(--radius-lg);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: var(--shadow-sm);
        }

        .selected-count {
            background: var(--primary-color);
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
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
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 650px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            position: sticky;
            bottom: 0;
            background: white;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.3rem;
            cursor: pointer;
            color: var(--gray-600);
        }

        .alert {
            padding: 10px 15px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.8rem;
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

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            font-size: 0.8rem;
        }

        .form-group label .optional {
            font-weight: normal;
            color: var(--gray-600);
            font-size: 0.7rem;
        }

        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 0.85rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
        }

        .profile-picture-preview {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            margin: 10px auto;
            display: block;
            border: 3px solid var(--primary-color);
        }

        .info-row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid var(--gray-200);
        }

        .info-label {
            width: 130px;
            font-weight: 600;
            color: var(--gray-600);
            font-size: 0.8rem;
        }

        .info-value {
            flex: 1;
            color: var(--gray-800);
            font-size: 0.85rem;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        .qr-code-img {
            max-width: 120px;
            margin: 10px auto;
            display: block;
            border: 1px solid var(--gray-200);
            padding: 5px;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray-600);
        }

        .password-hint {
            font-size: 0.7rem;
            color: var(--gray-600);
            margin-top: 5px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }

            .back-nav {
                flex-direction: column;
                align-items: flex-start;
            }

            .info-row {
                flex-direction: column;
            }

            .info-label {
                width: 100%;
                margin-bottom: 5px;
            }

            .form-row,
            .form-row-3 {
                grid-template-columns: 1fr;
                gap: 10px;
            }
        }
    </style>
</head>

<body>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1>Manage Students</h1>
                <p>
                    <?php
                    if ($is_global_search) {
                        echo 'Search Results for: "' . htmlspecialchars($global_search_query) . '"';
                    } elseif ($view_mode === 'students' && $current_class) {
                        echo 'Viewing: ' . htmlspecialchars($current_class['class_name']);
                    } else {
                        echo 'Select a class to manage students';
                    }
                    ?>
                </p>
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

        <!-- GLOBAL SEARCH BAR -->
        <div class="global-search-bar">
            <i class="fas fa-search search-icon"></i>
            <form method="GET" style="flex: 1; display: flex; gap: 10px; flex-wrap: wrap;">
                <input type="text" name="global_search" placeholder="Search students by name, admission number across all classes..." value="<?php echo htmlspecialchars($global_search_query); ?>" autocomplete="off">
                <button type="submit" class="btn btn-primary search-btn">
                    <i class="fas fa-search"></i> Search All Classes
                </button>
                <?php if (!empty($global_search_query)): ?>
                    <a href="manage-students.php" class="btn clear-btn">
                        <i class="fas fa-times"></i> Clear Search
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (isset($_GET['message'])): ?>
            <div class="alert alert-<?php echo $_GET['type'] ?? 'success'; ?>">
                <i class="fas fa-<?php echo $_GET['type'] === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($_GET['message']); ?>
            </div>
        <?php endif; ?>

        <!-- GLOBAL SEARCH RESULTS VIEW -->
        <?php if ($is_global_search): ?>
            <div class="students-list">
                <?php if (empty($global_search_students)): ?>
                    <div class="empty-state">
                        <i class="fas fa-search" style="font-size: 40px; margin-bottom: 12px; color: var(--gray-400);"></i>
                        <p>No students found matching "<?php echo htmlspecialchars($global_search_query); ?>"</p>
                        <a href="manage-students.php" class="btn btn-primary btn-sm" style="margin-top: 10px;">View All Classes</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($global_search_students as $student): ?>
                        <div class="student-item" onclick="viewStudent(<?php echo $student['id']; ?>, event)">
                            <div class="student-avatar">
                                <?php if (!empty($student['profile_picture'])): ?>
                                    <img src="<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="<?php echo htmlspecialchars($student['full_name']); ?>">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($student['first_name'] ?: $student['full_name'], 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="student-info">
                                <div class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                <div class="student-admission">Adm: <?php echo htmlspecialchars($student['admission_number']); ?></div>
                                <div class="student-class-badge"><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($student['class_name_formatted'] ?: $student['class']); ?></div>
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

            <!-- CLASSES VIEW -->
        <?php elseif ($view_mode === 'classes'): ?>
            <div class="class-list">
                <?php foreach ($all_classes as $class): ?>
                    <div class="class-item" onclick="window.location.href='?class_id=<?php echo $class['id']; ?>'">
                        <div class="class-name">
                            <i class="fas fa-chalkboard" style="color: var(--primary-color); width: 24px;"></i>
                            <?php echo htmlspecialchars($class['class_name']); ?>
                        </div>
                        <div class="class-student-count">
                            <i class="fas fa-user-graduate"></i>
                            <?php echo $class['student_count']; ?> student<?php echo $class['student_count'] != 1 ? 's' : ''; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (empty($all_classes)): ?>
                    <div class="empty-state">
                        <i class="fas fa-layer-group" style="font-size: 40px; margin-bottom: 12px; color: var(--gray-400);"></i>
                        <p>No classes found. Please add classes first.</p>
                        <a href="manage-classes.php" class="btn btn-primary" style="margin-top: 10px;">Manage Classes</a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- STUDENTS VIEW -->
        <?php elseif ($view_mode === 'students' && $current_class): ?>

            <div class="back-nav">
                <div>
                    <button class="back-btn" onclick="window.location.href='manage-students.php'">
                        <i class="fas fa-arrow-left"></i> Back to Classes
                    </button>
                </div>
                <div class="class-dropdown">
                    <span>Switch class:</span>
                    <select id="classDropdown" onchange="switchClass(this.value)">
                        <?php foreach ($all_classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $selected_class_id == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?> (<?php echo $class['student_count']; ?> students)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="search-bar">
                <input type="text" id="searchInput" placeholder="Search students in this class by name or admission number..." value="<?php echo htmlspecialchars($search_query); ?>">
                <button class="btn btn-primary" onclick="searchStudents()"><i class="fas fa-search"></i> Search in Class</button>
                <?php if (!empty($search_query)): ?>
                    <a href="?class_id=<?php echo $selected_class_id; ?>" class="btn btn-outline"><i class="fas fa-times"></i> Clear</a>
                <?php endif; ?>
            </div>

            <div class="bulk-bar">
                <div>
                    <label style="font-size: 0.85rem;">
                        <input type="checkbox" id="selectAll"> Select All
                    </label>
                    <span id="selectedCount" class="selected-count" style="margin-left: 10px;">0 selected</span>
                </div>

                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <form method="POST" id="transferForm" style="display: inline-flex; gap: 8px;">
                        <input type="hidden" name="action" value="transfer_students">
                        <input type="hidden" name="selected_students" id="transferSelectedStudents">
                        <input type="hidden" name="current_class_id" value="<?php echo $selected_class_id; ?>">
                        <select name="target_class_id" class="form-control" style="width: auto; padding: 6px 10px;" required>
                            <option value="">Move to...</option>
                            <?php foreach ($all_classes as $class): ?>
                                <?php if ($class['id'] != $selected_class_id): ?>
                                    <option value="<?php echo $class['id']; ?>">
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-success btn-sm" onclick="return prepareTransfer()">
                            <i class="fas fa-arrow-right"></i> Move
                        </button>
                    </form>

                    <a href="bulk_generate_qr.php?class_id=<?php echo $selected_class_id; ?>" class="btn btn-info btn-sm">
                        <i class="fas fa-qrcode"></i> Generate QR Codes
                    </a>
                </div>
            </div>

            <div class="students-list">
                <?php if (empty($students)): ?>
                    <div class="empty-state">
                        <i class="fas fa-user-graduate" style="font-size: 40px; margin-bottom: 12px; color: var(--gray-400);"></i>
                        <p>
                            <?php if (!empty($search_query)): ?>
                                No students found matching "<?php echo htmlspecialchars($search_query); ?>" in <?php echo htmlspecialchars($current_class['class_name']); ?>
                            <?php else: ?>
                                No students found in <?php echo htmlspecialchars($current_class['class_name']); ?>
                            <?php endif; ?>
                        </p>
                        <button class="btn btn-primary btn-sm" onclick="openAddModal()" style="margin-top: 10px;">Add Student</button>
                    </div>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                        <div class="student-item" onclick="viewStudent(<?php echo $student['id']; ?>, event)">
                            <input type="checkbox" class="student-checkbox" value="<?php echo $student['id']; ?>" onclick="event.stopPropagation()">
                            <div class="student-avatar">
                                <?php if (!empty($student['profile_picture'])): ?>
                                    <img src="<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="<?php echo htmlspecialchars($student['full_name']); ?>">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($student['first_name'] ?: $student['full_name'], 0, 1)); ?>
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
        <?php endif; ?>
    </div>

    <!-- All Modal HTML remains the same as your original -->
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
                        <small style="display: block; text-align: center;">Click to upload photo (max 100KB)</small>
                    </div>
                    <div class="form-group">
                        <label>Profile Picture</label>
                        <input type="file" name="profile_picture" class="form-control" accept="image/jpeg,image/png,image/gif" onchange="previewImage(this, 'addImagePreview')">
                    </div>
                    <div class="form-row-3">
                        <div class="form-group">
                            <label>First Name *</label>
                            <input type="text" name="first_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Middle Name <span class="optional">(optional)</span></label>
                            <input type="text" name="middle_name" class="form-control" placeholder="Middle Name">
                        </div>
                        <div class="form-group">
                            <label>Last Name *</label>
                            <input type="text" name="last_name" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Admission Number *</label>
                        <input type="text" name="admission_number" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Class *</label>
                        <select name="class_id" class="form-control" required>
                            <option value="">Select Class</option>
                            <?php foreach ($all_classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $selected_class_id == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Password <span class="optional">(optional - leave blank to use last name)</span></label>
                        <input type="text" name="password" class="form-control" placeholder="Leave blank to auto-generate from last name">
                        <div class="password-hint">
                            <i class="fas fa-info-circle"></i> If left blank, password will be set to student's last name (lowercase)
                        </div>
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
                        <i class="fas fa-info-circle"></i> QR code will be auto-generated after adding the student.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeAddModal()">Cancel</button>
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
                        <small style="display: block; text-align: center;">Click to change photo (max 100KB)</small>
                    </div>
                    <div class="form-group">
                        <label>Profile Picture</label>
                        <input type="file" name="profile_picture" class="form-control" accept="image/jpeg,image/png,image/gif" onchange="previewImage(this, 'editImagePreview')">
                    </div>
                    <div class="form-row-3">
                        <div class="form-group">
                            <label>First Name *</label>
                            <input type="text" id="edit_first_name" name="first_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Middle Name <span class="optional">(optional)</span></label>
                            <input type="text" id="edit_middle_name" name="middle_name" class="form-control" placeholder="Middle Name">
                        </div>
                        <div class="form-group">
                            <label>Last Name *</label>
                            <input type="text" id="edit_last_name" name="last_name" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Admission Number *</label>
                        <input type="text" id="edit_admission_number" name="admission_number" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Class *</label>
                        <select name="class_id" id="edit_class_id" class="form-control" required>
                            <option value="">Select Class</option>
                            <?php foreach ($all_classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>">
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Password <span class="optional">(optional - leave blank to keep current)</span></label>
                        <input type="text" name="password" class="form-control" placeholder="Leave blank to keep current password">
                        <div class="password-hint">
                            <i class="fas fa-info-circle"></i> Only enter if you want to change the password.
                        </div>
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
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> QR code will be automatically updated if name or admission number changes.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Student</button>
                </div>
            </form>
        </div>
    </div>

    <!-- QR Code Modal -->
    <div class="modal" id="qrModal">
        <div class="modal-content" style="max-width: 380px;">
            <div class="modal-header">
                <h3><i class="fas fa-qrcode"></i> <span id="qrStudentName"></span></h3>
                <button class="close-modal" onclick="closeQRModal()">&times;</button>
            </div>
            <div class="modal-body" style="text-align:center;">
                <img id="qrCodeImage" src="" style="width:200px; height:200px; margin:10px auto; border:1px solid #ddd; padding:8px;">
                <div class="alert alert-info" style="margin-top: 10px; font-size:0.75rem;">
                    <i class="fas fa-print"></i> Print and stick on student's ID card
                </div>
                <div class="action-buttons" style="justify-content:center; margin-top: 8px;">
                    <button class="btn btn-primary btn-sm" onclick="printQRCode()"><i class="fas fa-print"></i> Print</button>
                    <button class="btn btn-success btn-sm" onclick="downloadQRCode()"><i class="fas fa-download"></i> Download</button>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline btn-sm" onclick="closeQRModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Scan QR Modal -->
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
                    <div class="action-buttons" style="justify-content:center;">
                        <button class="btn btn-primary btn-sm" onclick="viewScannedStudent()">
                            <i class="fas fa-user"></i> View Details
                        </button>
                        <button class="btn btn-success btn-sm" onclick="markAttendanceFromScan()">
                            <i class="fas fa-calendar-check"></i> Mark Attendance
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary btn-sm" onclick="startScanner()"><i class="fas fa-play"></i> Start Camera</button>
                <button class="btn btn-outline btn-sm" onclick="stopScanner()"><i class="fas fa-stop"></i> Stop Camera</button>
            </div>
        </div>
    </div>

    <!-- Attendance Modal -->
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
                    <div style="text-align:center; margin-bottom:15px;">
                        <div id="attendanceStudentAvatar" class="student-avatar" style="width:60px;height:60px;margin:0 auto;font-size:1.5rem;">📷</div>
                        <h3 id="attendanceStudentName" style="margin-top:8px; font-size:1rem;"></h3>
                        <p id="attendanceStudentClass" style="color:#666; font-size:0.75rem;"></p>
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
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline btn-sm" onclick="closeAttendanceModal()">Cancel</button>
                    <button type="submit" class="btn btn-success btn-sm">Save Attendance</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let currentScanner = null;
        let currentStudentData = null;
        let scannedStudentData = null;

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

        // Class switcher
        function switchClass(classId) {
            window.location.href = '?class_id=' + classId;
        }

        // Per-class search function
        function searchStudents() {
            const searchTerm = document.getElementById('searchInput').value;
            window.location.href = '?class_id=<?php echo $selected_class_id; ?>&search=' + encodeURIComponent(searchTerm);
        }

        document.getElementById('searchInput')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') searchStudents();
        });

        // Checkbox selection
        function updateSelectedCount() {
            const checked = document.querySelectorAll('.student-checkbox:checked').length;
            const countSpan = document.getElementById('selectedCount');
            if (countSpan) countSpan.innerHTML = checked + ' selected';
            const selectAll = document.getElementById('selectAll');
            const allBoxes = document.querySelectorAll('.student-checkbox');
            if (selectAll && allBoxes.length) selectAll.checked = checked === allBoxes.length && checked > 0;
        }

        if (document.getElementById('selectAll')) {
            document.getElementById('selectAll').addEventListener('change', function() {
                document.querySelectorAll('.student-checkbox').forEach(cb => cb.checked = this.checked);
                updateSelectedCount();
            });
        }

        document.querySelectorAll('.student-checkbox').forEach(cb => cb.addEventListener('change', updateSelectedCount));
        updateSelectedCount();

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
            if (event && event.target && event.target.type === 'checkbox') return;
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
            const qrImageUrl = `generate_qr.php?student_id=${student.id}&mode=display&t=${Date.now()}`;

            let profilePicHtml = '';
            if (student.profile_picture && student.profile_picture.trim() !== '') {
                profilePicHtml = `<img src="${student.profile_picture}" class="profile-picture-preview" style="width:80px;height:80px;margin:0 auto;">`;
            } else {
                profilePicHtml = `<div class="student-avatar" style="width:80px;height:80px;margin:0 auto;font-size:1.5rem;">${(student.first_name ? student.first_name.charAt(0) : student.full_name.charAt(0))}</div>`;
            }

            let fullNameDisplay = student.full_name;
            if (student.first_name) {
                let nameParts = [student.first_name];
                if (student.middle_name) nameParts.push(student.middle_name);
                if (student.last_name) nameParts.push(student.last_name);
                fullNameDisplay = nameParts.join(' ');
            }

            modalBody.innerHTML = `
                <div style="text-align:center; margin-bottom:15px;">${profilePicHtml}</div>
                <div class="info-row"><div class="info-label">First Name:</div><div class="info-value">${escapeHtml(student.first_name || '—')}</div></div>
                <div class="info-row"><div class="info-label">Middle Name:</div><div class="info-value">${escapeHtml(student.middle_name || '—')}</div></div>
                <div class="info-row"><div class="info-label">Last Name:</div><div class="info-value">${escapeHtml(student.last_name || '—')}</div></div>
                <div class="info-row"><div class="info-label">Full Name:</div><div class="info-value">${escapeHtml(fullNameDisplay)}</div></div>
                <div class="info-row"><div class="info-label">Admission No:</div><div class="info-value"><strong>${student.admission_number}</strong></div></div>
                <div class="info-row"><div class="info-label">Class:</div><div class="info-value">${student.class_name_formatted || student.class}</div></div>
                <div class="info-row"><div class="info-label">Parent Phone:</div><div class="info-value">${student.parent_phone || 'Not provided'}</div></div>
                <div class="info-row"><div class="info-label">Parent Email:</div><div class="info-value">${student.parent_email || 'Not provided'}</div></div>
                <div class="info-row"><div class="info-label">Status:</div><div class="info-value"><span class="status-badge status-${student.status}">${student.status.toUpperCase()}</span></div></div>
                <div class="info-row"><div class="info-label">QR Code:</div><div class="info-value"><img src="${qrImageUrl}" class="qr-code-img"><div class="mt-2"><button class="btn btn-info btn-sm" onclick="showQRCode(${student.id}, '${escapeHtml(fullNameDisplay).replace(/'/g, "\\'")}')"><i class="fas fa-expand"></i> View Full Size</button> <button class="btn btn-warning btn-sm" onclick="regenerateQR(${student.id})"><i class="fas fa-sync-alt"></i> Regenerate QR</button></div></div></div>
                <div class="action-buttons">
                    <button class="btn btn-primary btn-sm" onclick="editStudentFromModal()"><i class="fas fa-edit"></i> Edit</button>
                    <form method="POST" action="manage-students.php?class_id=<?php echo $selected_class_id; ?>" style="display:inline;">
                        <input type="hidden" name="action" value="remove_picture">
                        <input type="hidden" name="student_id" value="${student.id}">
                        <input type="hidden" name="class_id" value="<?php echo $selected_class_id; ?>">
                        <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('Remove profile picture?')"><i class="fas fa-image"></i> Remove Photo</button>
                    </form>
                    <button class="btn btn-danger btn-sm" onclick="deleteStudent(${student.id}, '${escapeHtml(fullNameDisplay).replace(/'/g, "\\'")}')"><i class="fas fa-trash"></i> Delete</button>
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

        function editStudentFromModal() {
            if (currentStudentData) {
                openEditModal(currentStudentData);
                closeStudentModal();
            }
        }

        function closeStudentModal() {
            document.getElementById('studentModal').style.display = 'none';
        }

        function openAddModal() {
            document.getElementById('addStudentModal').style.display = 'flex';
        }

        function closeAddModal() {
            document.getElementById('addStudentModal').style.display = 'none';
        }

        function openEditModal(student) {
            document.getElementById('edit_student_id').value = student.id;
            document.getElementById('edit_first_name').value = student.first_name || '';
            document.getElementById('edit_middle_name').value = student.middle_name || '';
            document.getElementById('edit_last_name').value = student.last_name || '';
            document.getElementById('edit_admission_number').value = student.admission_number;
            document.getElementById('edit_class_id').value = student.class_id;
            document.getElementById('edit_parent_phone').value = student.parent_phone || '';
            document.getElementById('edit_parent_email').value = student.parent_email || '';
            document.getElementById('edit_status').value = student.status;
            document.querySelector('#editStudentModal input[name="password"]').value = '';

            if (student.profile_picture && student.profile_picture.trim() !== '') {
                document.getElementById('editImagePreview').src = student.profile_picture;
            } else {
                document.getElementById('editImagePreview').src = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"%3E%3Ccircle cx="50" cy="50" r="50" fill="%23ddd"/%3E%3Ctext x="50" y="67" text-anchor="middle" fill="%23999" font-size="40"%3E📷%3C/text%3E%3C/svg%3E';
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
                form.innerHTML = `<input type="hidden" name="action" value="delete_student"><input type="hidden" name="student_id" value="${id}"><input type="hidden" name="class_id" value="<?php echo $selected_class_id; ?>">`;
                document.body.appendChild(form);
                form.submit();
            }
        }

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
                            viewStudent(studentId, {
                                target: {
                                    type: ''
                                }
                            });
                        } else {
                            alert('Error: ' + (data.error || 'Unknown error'));
                        }
                    })
                    .catch(error => alert('Error generating QR code'));
            }
        }

        function closeQRModal() {
            document.getElementById('qrModal').style.display = 'none';
        }

        function printQRCode() {
            const img = document.getElementById('qrCodeImage');
            const win = window.open('');
            win.document.write(`<html><head><title>Print QR Code</title><style>body{display:flex;justify-content:center;align-items:center;height:100vh;margin:0;}img{width:250px;height:250px;border:1px solid #ddd;padding:10px;}</style></head><body><img src="${img.src}"><script>window.print();<\/script></body></html>`);
        }

        function downloadQRCode() {
            const link = document.createElement('a');
            link.download = 'student_qrcode.png';
            link.href = document.getElementById('qrCodeImage').src;
            link.click();
        }

        // Scanner functions
        function openScanner() {
            document.getElementById('scanModal').style.display = 'flex';
            document.getElementById('scanResult').style.display = 'none';
            scannedStudentData = null;
        }

        function closeScanModal() {
            if (currentScanner) stopScanner();
            document.getElementById('scanModal').style.display = 'none';
        }

        function startScanner() {
            if (currentScanner) currentScanner.stop();
            const readerElement = document.getElementById('reader');
            if (!readerElement) return;
            readerElement.innerHTML = '';
            currentScanner = new Html5Qrcode("reader");
            currentScanner.start({
                    facingMode: "environment"
                }, {
                    fps: 10,
                    qrbox: {
                        width: 250,
                        height: 250
                    }
                },
                (decodedText) => handleScannedData(decodedText),
                (error) => console.log("Scanning...")
            ).catch(err => alert("Failed to start camera. Please grant camera permissions."));
        }

        function stopScanner() {
            if (currentScanner) {
                currentScanner.stop().then(() => currentScanner = null).catch(err => console.error(err));
            }
        }

        function handleScannedData(decodedText) {
            try {
                let data;
                try {
                    data = JSON.parse(decodeURIComponent(decodedText));
                } catch (e) {
                    data = JSON.parse(decodedText);
                }
                if (data.type === 'student' && data.id) {
                    stopScanner();
                    fetchScannedStudentInfo(data.id);
                } else {
                    alert('Invalid student QR code format');
                }
            } catch (e) {
                const studentId = parseInt(decodedText);
                if (studentId > 0) {
                    stopScanner();
                    fetchScannedStudentInfo(studentId);
                } else {
                    alert('Invalid QR code');
                }
            }
        }

        function fetchScannedStudentInfo(studentId) {
            fetch(`?get_student=${studentId}`)
                .then(response => response.json())
                .then(data => {
                    scannedStudentData = data;
                    displayScanResult(data);
                })
                .catch(error => alert('Error loading student details'));
        }

        function displayScanResult(student) {
            const scanResult = document.getElementById('scanResult');
            const scanStudentInfo = document.getElementById('scanStudentInfo');
            let avatarHtml = student.profile_picture ? `<img src="${student.profile_picture}" style="width:50px;height:50px;border-radius:50%;object-fit:cover;">` : `<div style="width:50px;height:50px;border-radius:50%;background:var(--primary-color);color:white;display:flex;align-items:center;justify-content:center;margin:0 auto;font-size:1.2rem;">${(student.first_name ? student.first_name.charAt(0) : student.full_name.charAt(0))}</div>`;

            let fullNameDisplay = student.full_name;
            if (student.first_name) {
                let nameParts = [student.first_name];
                if (student.middle_name) nameParts.push(student.middle_name);
                if (student.last_name) nameParts.push(student.last_name);
                fullNameDisplay = nameParts.join(' ');
            }

            scanStudentInfo.innerHTML = `<div style="text-align:center;">${avatarHtml}<h4 style="margin:8px 0 4px;">${escapeHtml(fullNameDisplay)}</h4><p style="font-size:0.75rem;">Adm: ${student.admission_number} | Class: ${student.class_name_formatted || student.class}</p></div>`;
            scanResult.style.display = 'block';
        }

        function viewScannedStudent() {
            if (scannedStudentData) {
                closeScanModal();
                viewStudent(scannedStudentData.id, {
                    target: {
                        type: ''
                    }
                });
            }
        }

        function markAttendanceFromScan() {
            if (scannedStudentData) {
                closeScanModal();
                openAttendanceModal(scannedStudentData);
            }
        }

        function openAttendanceModal(student) {
            document.getElementById('attendanceStudentId').value = student.id;

            let fullNameDisplay = student.full_name;
            if (student.first_name) {
                let nameParts = [student.first_name];
                if (student.middle_name) nameParts.push(student.middle_name);
                if (student.last_name) nameParts.push(student.last_name);
                fullNameDisplay = nameParts.join(' ');
            }

            document.getElementById('attendanceStudentName').innerHTML = escapeHtml(fullNameDisplay);
            document.getElementById('attendanceStudentClass').innerHTML = `Adm: ${student.admission_number} | Class: ${student.class_name_formatted || student.class}`;
            const avatarDiv = document.getElementById('attendanceStudentAvatar');
            if (student.profile_picture) {
                avatarDiv.innerHTML = `<img src="${student.profile_picture}" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">`;
            } else {
                avatarDiv.innerHTML = student.first_name ? student.first_name.charAt(0) : student.full_name.charAt(0);
                avatarDiv.style.display = 'flex';
                avatarDiv.style.alignItems = 'center';
                avatarDiv.style.justifyContent = 'center';
                avatarDiv.style.background = 'var(--primary-color)';
                avatarDiv.style.color = 'white';
            }
            document.getElementById('attendanceModal').style.display = 'flex';
        }

        function closeAttendanceModal() {
            document.getElementById('attendanceModal').style.display = 'none';
        }

        document.getElementById('attendanceForm')?.addEventListener('submit', function(e) {
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
                .catch(error => alert('Error marking attendance'));
        });

        window.onclick = function(event) {
            const modals = ['studentModal', 'addStudentModal', 'editStudentModal', 'qrModal', 'scanModal', 'attendanceModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    modal.style.display = 'none';
                    if (modalId === 'scanModal' && currentScanner) stopScanner();
                }
            });
        };
    </script>
</body>

</html>
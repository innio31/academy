<?php
// gos/staff/library.php - Staff Library Management with Multi-Class Support
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: /gos/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$staff_id = $_SESSION['user_id'];
$staff_name = $_SESSION['user_name'] ?? 'Staff Member';
$staff_role = $_SESSION['staff_role'] ?? 'staff';

// Initialize variables
$assigned_subjects = [];
$assigned_classes = [];
$staff_id_string = null;

try {
    // Get the staff_id string from the staff table (same as index.php)
    $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
    $stmt->execute([$staff_id, $school_id]);
    $staff_id_string = $stmt->fetchColumn();

    if (!$staff_id_string) {
        $error = "Staff record not found. Please contact administrator.";
    } else {
        // Get assigned subjects - SAME as index.php
        $stmt = $pdo->prepare("
            SELECT s.id, s.subject_name
            FROM subjects s
            JOIN staff_subjects ss ON s.id = ss.subject_id
            WHERE ss.staff_id = ? AND ss.school_id = ?
            ORDER BY s.subject_name
        ");
        $stmt->execute([$staff_id_string, $school_id]);
        $assigned_subjects = $stmt->fetchAll();

        // Get assigned classes - SAME as index.php (using DISTINCT class)
        $stmt = $pdo->prepare("
            SELECT DISTINCT class 
            FROM staff_classes 
            WHERE staff_id = ? AND school_id = ?
            ORDER BY class
        ");
        $stmt->execute([$staff_id_string, $school_id]);
        $assigned_classes = $stmt->fetchAll();
        $class_names = array_column($assigned_classes, 'class');
    }
} catch (Exception $e) {
    error_log("Staff library error: " . $e->getMessage());
    $error = "An error occurred while loading the library.";
}

// Handle upload (with multiple classes support)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_resource'])) {
    $title = trim($_POST['title']);
    $subject_id = !empty($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
    $selected_classes = $_POST['classes'] ?? []; // Array of selected classes
    $description = trim($_POST['description']);

    // Get subject name from ID
    $subject_name = '';
    if ($subject_id) {
        $stmt = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ? AND school_id = ?");
        $stmt->execute([$subject_id, $school_id]);
        $subject = $stmt->fetch();
        $subject_name = $subject['subject_name'] ?? '';
    }

    // Validate that selected classes are in staff's assigned classes
    $valid_classes = [];
    $assigned_class_names = array_column($assigned_classes, 'class');
    foreach ($selected_classes as $class) {
        if (in_array($class, $assigned_class_names)) {
            $valid_classes[] = $class;
        }
    }

    // Validate that the selected subject is in staff's assigned subjects
    $subject_valid = false;
    foreach ($assigned_subjects as $as) {
        if ($as['id'] == $subject_id) {
            $subject_valid = true;
            break;
        }
    }

    if (empty($title)) {
        $error = "Title is required";
        $message_type = "error";
    } elseif (!$subject_valid) {
        $error = "Invalid subject selected. You can only upload for your assigned subjects.";
        $message_type = "error";
    } elseif (empty($valid_classes)) {
        $error = "Please select at least one valid class from your assigned classes.";
        $message_type = "error";
    } elseif (isset($_FILES['resource_file']) && $_FILES['resource_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/library/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_FILES['resource_file']['name']));
        $file_path = 'uploads/library/' . $file_name;
        $file_type = pathinfo($file_name, PATHINFO_EXTENSION);
        $file_size = $_FILES['resource_file']['size'];

        if (move_uploaded_file($_FILES['resource_file']['tmp_name'], '../' . $file_path)) {
            // Store classes as comma-separated string
            $classes_string = implode(',', $valid_classes);
            
            $stmt = $pdo->prepare("
                INSERT INTO library_resources (school_id, title, subject, class, file_type, file_path, file_size, uploaded_by, uploaded_by_type, uploaded_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'staff', NOW())
            ");
            $stmt->execute([$school_id, $title, $subject_name, $classes_string, $file_type, $file_path, $file_size, $staff_id]);

            $message = "Resource uploaded successfully! Shared with " . count($valid_classes) . " class(es).";
            $message_type = "success";
        } else {
            $error = "Failed to upload file";
            $message_type = "error";
        }
    } else {
        $error = "Please select a file to upload";
        $message_type = "error";
    }
}

// Handle delete (only own resources)
if (isset($_GET['delete'])) {
    $resource_id = $_GET['delete'];

    $stmt = $pdo->prepare("SELECT file_path FROM library_resources WHERE id = ? AND school_id = ? AND uploaded_by = ? AND uploaded_by_type = 'staff'");
    $stmt->execute([$resource_id, $school_id, $staff_id]);
    $resource = $stmt->fetch();

    if ($resource) {
        if (file_exists('../' . $resource['file_path'])) {
            unlink('../' . $resource['file_path']);
        }
        $stmt = $pdo->prepare("DELETE FROM library_resources WHERE id = ?");
        $stmt->execute([$resource_id]);
        $message = "Resource deleted successfully";
        $message_type = "success";
    }
}

// Get resources (staff's own + shared to their classes) - with multi-class support
$search = $_GET['search'] ?? '';

// Build the base query - need to handle comma-separated classes
$query = "SELECT lr.*, 
          CASE WHEN lr.file_size < 1024 THEN CONCAT(lr.file_size, ' B')
               WHEN lr.file_size < 1048576 THEN CONCAT(ROUND(lr.file_size/1024, 1), ' KB')
               ELSE CONCAT(ROUND(lr.file_size/1048576, 1), ' MB')
          END as formatted_size
          FROM library_resources lr
          WHERE lr.school_id = ? AND (lr.uploaded_by = ?";

$params = [$school_id, $staff_id];

// Add class condition for multi-class resources - check if staff's class is in the comma-separated list
if (!empty($class_names)) {
    $class_conditions = [];
    foreach ($class_names as $class) {
        $class_conditions[] = "FIND_IN_SET(?, lr.class)";
        $params[] = $class;
    }
    $query .= " OR (" . implode(" OR ", $class_conditions) . ")";
}

$query .= " OR lr.class = 'All')";

// Add search condition if provided
if (!empty($search)) {
    $query .= " AND (lr.title LIKE ? OR lr.subject LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$query .= " ORDER BY lr.uploaded_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$resources = $stmt->fetchAll();

// Get file icon function
function getFileIcon($file_type) {
    $ext = strtolower($file_type);
    $icons = [
        'pdf' => '📕',
        'doc' => '📘',
        'docx' => '📘',
        'ppt' => '📙',
        'pptx' => '📙',
        'xls' => '📗',
        'xlsx' => '📗',
        'jpg' => '🖼️',
        'jpeg' => '🖼️',
        'png' => '🖼️',
        'gif' => '🖼️',
        'mp4' => '🎬',
        'mp3' => '🎵',
        'txt' => '📄',
        'zip' => '📦'
    ];
    return $icons[$ext] ?? '📁';
}

// Helper to format class display (convert comma-separated to badges)
function formatClasses($class_string) {
    if ($class_string === 'All') {
        return '<span class="info-item" style="background: var(--primary-color); color: white;"><i class="fas fa-globe"></i> All Classes</span>';
    }
    $classes = explode(',', $class_string);
    $badges = [];
    foreach ($classes as $class) {
        $badges[] = '<span class="info-item"><i class="fas fa-users"></i> ' . htmlspecialchars(trim($class)) . '</span>';
    }
    return implode(' ', $badges);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($school_name); ?> - Library</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --primary-dark: #1a5a8a;
            --secondary-color: #d4af7a;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #3498db;
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
            --sidebar-width: 280px;
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

        /* Mobile Menu Button */
        .mobile-menu-btn {
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            width: 44px;
            height: 44px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 20px;
            cursor: pointer;
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            justify-content: center;
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
            transition: all 0.3s ease;
        }

        .sidebar-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        /* Main Content */
        .main-content {
            margin-left: 0;
            padding: 20px;
            min-height: 100vh;
            transition: margin-left 0.28s ease;
        }

        /* Top Header */
        .top-header {
            background: white;
            padding: 20px 25px;
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
            font-size: 1.6rem;
            margin-bottom: 5px;
            font-weight: 700;
        }

        .header-title h1 i {
            margin-right: 10px;
        }

        .header-title p {
            color: var(--gray-600);
            font-size: 0.85rem;
        }

        .header-title p i {
            color: var(--primary-color);
            font-size: 0.7rem;
            margin: 0 4px;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-bottom: 25px;
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

        .card-header h3 {
            color: var(--gray-800);
            font-size: 1.1rem;
            font-weight: 600;
        }

        .card-header h3 i {
            color: var(--primary-color);
            margin-right: 8px;
        }

        /* Forms */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
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
            font-family: inherit;
            transition: all 0.2s;
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        /* Multi-select for classes */
        .multi-select {
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            padding: 8px;
            max-height: 150px;
            overflow-y: auto;
        }

        .multi-select label {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 10px;
            margin: 0;
            font-size: 0.85rem;
            font-weight: normal;
            text-transform: none;
            cursor: pointer;
            border-radius: var(--radius-sm);
            transition: background 0.2s;
        }

        .multi-select label:hover {
            background: var(--gray-100);
        }

        .multi-select input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
            accent-color: var(--primary-color);
        }

        .selected-classes-badge {
            display: inline-block;
            background: var(--info-color);
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            margin-left: 8px;
        }

        /* Search Bar */
        .search-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .search-bar input {
            flex: 1;
            min-width: 200px;
        }

        /* Buttons */
        .btn {
            padding: 8px 18px;
            border-radius: var(--radius-md);
            border: none;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8rem;
            transition: all 0.2s;
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
            background: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background: #219a52;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background: #c0392b;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.75rem;
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
            padding: 14px 16px;
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
            font-size: 0.85rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .data-table tr:hover td {
            background: var(--gray-50);
        }

        .data-table td strong {
            font-size: 0.9rem;
            font-weight: 600;
        }

        /* File type badge */
        .file-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .file-pdf {
            background: #fee2e2;
            color: #dc2626;
        }

        .file-doc {
            background: #d1fae5;
            color: #059669;
        }

        .file-ppt {
            background: #fed7aa;
            color: #ea580c;
        }

        .file-xls {
            background: #d1fae5;
            color: #059669;
        }

        .file-zip {
            background: #e0e7ff;
            color: #4f46e5;
        }

        .file-image {
            background: #fce7f3;
            color: #db2777;
        }

        .file-default {
            background: var(--gray-100);
            color: var(--gray-600);
        }

        /* Info Items */
        .info-items {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .info-item {
            background: var(--gray-100);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            color: var(--gray-800);
            font-weight: 500;
        }

        .info-item i {
            margin-right: 6px;
            color: var(--primary-color);
        }

        /* Alerts */
        .alert {
            padding: 15px 20px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d5f4e6;
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }

        .alert-error {
            background: #f8d7da;
            color: var(--danger-color);
            border-left: 4px solid var(--danger-color);
        }

        .alert-info {
            background: #eef2ff;
            color: var(--info-color);
            border-left: 4px solid var(--info-color);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: var(--gray-600);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: var(--gray-400);
        }

        .empty-state p {
            margin-top: 8px;
        }

        /* Selection Info */
        .selection-info {
            background: var(--gray-50);
            border-radius: var(--radius-md);
            padding: 12px 15px;
            margin-top: 10px;
            font-size: 0.8rem;
            color: var(--gray-600);
        }

        .selection-info i {
            color: var(--info-color);
            margin-right: 6px;
        }

        /* Desktop */
        @media (min-width: 768px) {
            .mobile-menu-btn,
            .sidebar-overlay {
                display: none;
            }

            .main-content {
                margin-left: var(--sidebar-width);
            }
        }

        /* Mobile */
        @media (max-width: 767px) {
            .main-content {
                padding-top: 70px;
            }

            .top-header {
                flex-direction: column;
                text-align: center;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .search-bar {
                flex-direction: column;
            }

            .card-header {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>

<body>

    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Include Staff Sidebar -->
    <?php include_once 'includes/staff_sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-book"></i> Library Resources</h1>
                <p><i class="fas fa-chevron-right"></i> Upload and manage teaching resources for your classes</p>
            </div>
            <div>
                <span class="info-item"><i class="fas fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></span>
            </div>
        </div>

        <?php if (isset($message)): ?>
            <div class="alert alert-<?php echo $message_type ?? 'success'; ?>">
                <i class="fas fa-<?php echo ($message_type ?? 'success') === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Assigned Info Card - Same as index.php -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chalkboard"></i> My Assignments</h3>
            </div>
            <div class="info-items">
                <?php if (!empty($assigned_subjects)): ?>
                    <?php foreach ($assigned_subjects as $subject): ?>
                        <span class="info-item"><i class="fas fa-book"></i> <?php echo htmlspecialchars($subject['subject_name']); ?></span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="info-item"><i class="fas fa-info-circle"></i> No subjects assigned yet</span>
                <?php endif; ?>
                <?php if (!empty($assigned_classes)): ?>
                    <?php foreach ($assigned_classes as $class): ?>
                        <span class="info-item"><i class="fas fa-users"></i> <?php echo htmlspecialchars($class['class']); ?></span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="info-item"><i class="fas fa-info-circle"></i> No classes assigned yet</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Upload Form - Only show if staff has assigned subjects and classes -->
        <?php if (!empty($assigned_subjects) && !empty($assigned_classes)): ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-upload"></i> Upload Resource</h3>
                <span class="info-item"><i class="fas fa-info-circle"></i> Max file size: 10MB</span>
            </div>
            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-heading"></i> Title *</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g., Mathematics Worksheet 1" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-book"></i> Subject *</label>
                        <select name="subject_id" class="form-select" required>
                            <option value="">Select Subject</option>
                            <?php foreach ($assigned_subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['subject_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-layer-group"></i> Classes * (Select one or more)</label>
                        <div class="multi-select" id="classesMultiSelect">
                            <?php foreach ($assigned_classes as $class): ?>
                                <label>
                                    <input type="checkbox" name="classes[]" value="<?php echo htmlspecialchars($class['class']); ?>">
                                    <span><?php echo htmlspecialchars($class['class']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="selection-info" id="selectionInfo">
                            <i class="fas fa-info-circle"></i>
                            <span id="selectedCount">0</span> class(es) selected
                        </div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-align-left"></i> Description (Optional)</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Brief description of the resource..."></textarea>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-paperclip"></i> File *</label>
                        <input type="file" name="resource_file" class="form-control" required>
                        <small style="color: var(--gray-600); margin-top: 5px;">Allowed: PDF, DOC, DOCX, PPT, PPTX, XLS, XLSX, TXT, ZIP, Images</small>
                    </div>
                </div>
                <button type="submit" name="upload_resource" class="btn btn-success">
                    <i class="fas fa-cloud-upload-alt"></i> Upload Resource
                </button>
            </form>
        </div>
        <?php elseif (!empty($assigned_subjects) && empty($assigned_classes)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> You have assigned subjects but no classes. Please contact the administrator to assign classes to you.
            </div>
        <?php elseif (empty($assigned_subjects) && !empty($assigned_classes)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> You have assigned classes but no subjects. Please contact the administrator to assign subjects to you.
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> You have not been assigned any subjects or classes yet. Please contact the administrator.
            </div>
        <?php endif; ?>

        <!-- Resources List -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Available Resources</h3>
                <?php if (!empty($resources)): ?>
                    <span class="info-item"><i class="fas fa-database"></i> <?php echo count($resources); ?> resources</span>
                <?php endif; ?>
            </div>

            <div class="search-bar">
                <form method="GET" style="display: flex; gap: 10px; flex: 1; flex-wrap: wrap;">
                    <input type="text" name="search" class="form-control" placeholder="Search by title or subject..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
                    <?php if (!empty($search)): ?>
                        <a href="library.php" class="btn btn-warning"><i class="fas fa-times"></i> Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (empty($resources)): ?>
                <div class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <p>No resources found</p>
                    <?php if (!empty($search)): ?>
                        <p style="font-size: 0.8rem;">Try adjusting your search terms</p>
                    <?php else: ?>
                        <p>Upload your first resource using the form above</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>File</th>
                                <th>Title</th>
                                <th>Subject</th>
                                <th>Classes</th>
                                <th>Type</th>
                                <th>Size</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resources as $resource):
                                $file_type = strtolower($resource['file_type']);
                                $badge_class = 'file-default';
                                if (in_array($file_type, ['pdf'])) $badge_class = 'file-pdf';
                                elseif (in_array($file_type, ['doc', 'docx'])) $badge_class = 'file-doc';
                                elseif (in_array($file_type, ['ppt', 'pptx'])) $badge_class = 'file-ppt';
                                elseif (in_array($file_type, ['xls', 'xlsx'])) $badge_class = 'file-xls';
                                elseif (in_array($file_type, ['zip', 'rar'])) $badge_class = 'file-zip';
                                elseif (in_array($file_type, ['jpg', 'jpeg', 'png', 'gif'])) $badge_class = 'file-image';
                            ?>
                                <tr>
                                    <td style="font-size: 1.5rem;"><?php echo getFileIcon($file_type); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($resource['title']); ?></strong>
                                        <?php if ($resource['description']): ?>
                                            <br><small style="color: var(--gray-500);"><?php echo htmlspecialchars(substr($resource['description'], 0, 60)); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($resource['subject']); ?></td>
                                    <td>
                                        <div class="info-items" style="margin: 0;">
                                            <?php echo formatClasses($resource['class']); ?>
                                        </div>
                                    </td>
                                    <td><span class="file-badge <?php echo $badge_class; ?>"><?php echo strtoupper($file_type); ?></span></td>
                                    <td><?php echo $resource['formatted_size']; ?></td>
                                    <td>
                                        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                            <a href="/<?php echo $resource['file_path']; ?>" target="_blank" class="btn btn-primary btn-sm">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <a href="/<?php echo $resource['file_path']; ?>" download class="btn btn-success btn-sm">
                                                <i class="fas fa-download"></i> Download
                                            </a>
                                            <?php if ($resource['uploaded_by'] == $staff_id && $resource['uploaded_by_type'] == 'staff'): ?>
                                                <a href="library.php?delete=<?php echo $resource['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this resource?')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </a>
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
    </div>

    <script>
        // Mobile menu toggle
        const mobileBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('staffSidebar');
        const overlay = document.getElementById('sidebarOverlay');

        if (mobileBtn) {
            mobileBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (sidebar) sidebar.classList.toggle('active');
                if (overlay) overlay.classList.toggle('active');
                document.body.style.overflow = sidebar?.classList.contains('active') ? 'hidden' : '';
            });
        }

        if (overlay) {
            overlay.addEventListener('click', function() {
                if (sidebar) sidebar.classList.remove('active');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            });
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768 && sidebar && sidebar.classList.contains('active')) {
                if (!sidebar.contains(e.target) && !mobileBtn.contains(e.target)) {
                    sidebar.classList.remove('active');
                    if (overlay) overlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            }
        });

        // Update selected classes count
        const checkboxes = document.querySelectorAll('input[name="classes[]"]');
        const selectedCountSpan = document.getElementById('selectedCount');
        
        function updateSelectedCount() {
            const checked = document.querySelectorAll('input[name="classes[]"]:checked');
            if (selectedCountSpan) {
                selectedCountSpan.textContent = checked.length;
            }
        }
        
        if (checkboxes.length > 0) {
            checkboxes.forEach(cb => {
                cb.addEventListener('change', updateSelectedCount);
            });
            updateSelectedCount();
        }

        // File size validation
        const fileInput = document.querySelector('input[type="file"]');
        if (fileInput) {
            fileInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file && file.size > 10 * 1024 * 1024) {
                    alert('File size exceeds 10MB limit!');
                    this.value = '';
                }
            });
        }

        // Form validation to ensure at least one class is selected
        const uploadForm = document.getElementById('uploadForm');
        if (uploadForm) {
            uploadForm.addEventListener('submit', function(e) {
                const selectedClasses = document.querySelectorAll('input[name="classes[]"]:checked');
                if (selectedClasses.length === 0) {
                    e.preventDefault();
                    alert('Please select at least one class to share this resource with.');
                }
            });
        }
    </script>
</body>

</html>
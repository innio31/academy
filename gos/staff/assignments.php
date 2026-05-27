<?php
// gos/staff/assignments.php - Staff Assignments Management with File Attachments
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: /gos/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$staff_id = $_SESSION['user_id'];  // This is the numeric ID from staff table
$staff_name = $_SESSION['user_name'] ?? 'Staff Member';
$staff_role = $_SESSION['staff_role'] ?? 'staff';
$staff_id_string = $_SESSION['staff_id'] ?? $staff_id;

// Initialize variables
$subjects = [];
$classes = [];
$assignments = [];
$message = null;
$message_type = null;

// Create uploads directory if not exists
$upload_dir = '../uploads/assignments/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

try {
    // Get the staff_id string for querying staff_subjects and staff_classes
    $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
    $stmt->execute([$staff_id, $school_id]);
    $staff_id_string_db = $stmt->fetchColumn();

    if (!$staff_id_string_db) {
        $message = "Staff record not found. Please contact administrator.";
        $message_type = "error";
    } else {
        $staff_id_string = $staff_id_string_db;

        // Get staff assigned subjects using the string staff_id
        $stmt = $pdo->prepare("
            SELECT s.id as subject_id, s.subject_name 
            FROM subjects s 
            JOIN staff_subjects ss ON s.id = ss.subject_id 
            WHERE ss.staff_id = ? AND ss.school_id = ?
            ORDER BY s.subject_name
        ");
        $stmt->execute([$staff_id_string, $school_id]);
        $subjects = $stmt->fetchAll();

        // Get staff assigned classes using the string staff_id
        $stmt = $pdo->prepare("SELECT class FROM staff_classes WHERE staff_id = ? AND school_id = ? ORDER BY class");
        $stmt->execute([$staff_id_string, $school_id]);
        $classes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) {
    error_log("Staff data fetch error: " . $e->getMessage());
    $message = "An error occurred while loading your data.";
    $message_type = "error";
}

// Handle assignment creation with file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_assignment'])) {
    $title = trim($_POST['title']);
    $subject_id = intval($_POST['subject_id']);
    $class = trim($_POST['class']);
    $instructions = trim($_POST['instructions']);
    $deadline = $_POST['deadline'];
    $max_marks = intval($_POST['max_marks'] ?? 0);
    $submission_type = $_POST['submission_type'] ?? 'online';
    $allow_attachment = isset($_POST['allow_attachment']) ? intval($_POST['allow_attachment']) : 1;
    $file_path = null;

    // Handle file upload (assignment material)
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['attachment'];
        $file_name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $file['name']);
        $target_file = $upload_dir . $file_name;

        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed_extensions = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'zip', 'rar', 'jpg', 'jpeg', 'png'];

        if (in_array($file_ext, $allowed_extensions) && $file['size'] <= 10 * 1024 * 1024) {
            if (move_uploaded_file($file['tmp_name'], $target_file)) {
                $file_path = 'uploads/assignments/' . $file_name;
            }
        }
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO assignments (title, subject_id, class, instructions, deadline, max_marks, 
                                    submission_type, allow_attachment, file_path, staff_id, school_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $title,
            $subject_id,
            $class,
            $instructions,
            $deadline,
            $max_marks,
            $submission_type,
            $allow_attachment,
            $file_path,
            $staff_id,
            $school_id
        ]);

        $message = "Assignment created successfully!";
        $message_type = "success";

        header("Location: assignments.php?message=" . urlencode($message) . "&type=success");
        exit();
    } catch (Exception $e) {
        error_log("Assignment creation error: " . $e->getMessage());
        $message = "Failed to create assignment: " . $e->getMessage();
        $message_type = "error";
    }
}

// Handle assignment deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $assignment_id = intval($_GET['delete']);
    try {
        // Get file path before deleting
        $stmt = $pdo->prepare("SELECT file_path FROM assignments WHERE id = ? AND school_id = ? AND staff_id = ?");
        $stmt->execute([$assignment_id, $school_id, $staff_id]);
        $assignment = $stmt->fetch();

        if ($assignment) {
            // Delete file if exists
            if ($assignment['file_path'] && file_exists('../' . $assignment['file_path'])) {
                unlink('../' . $assignment['file_path']);
            }

            // Delete assignment
            $stmt = $pdo->prepare("DELETE FROM assignments WHERE id = ? AND school_id = ? AND staff_id = ?");
            $stmt->execute([$assignment_id, $school_id, $staff_id]);

            $message = "Assignment deleted successfully!";
            $message_type = "success";
        }
    } catch (Exception $e) {
        error_log("Assignment deletion error: " . $e->getMessage());
        $message = "Failed to delete assignment.";
        $message_type = "error";
    }
}

// Get assignments (using numeric staff_id)
if (!empty($classes)) {
    try {
        $placeholders = str_repeat('?,', count($classes) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT a.*, s.subject_name,
                   (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id) as submissions_count
            FROM assignments a
            JOIN subjects s ON a.subject_id = s.id
            WHERE a.school_id = ? AND a.staff_id = ? AND a.class IN ($placeholders)
            ORDER BY a.created_at DESC
        ");
        $stmt->execute(array_merge([$school_id, $staff_id], $classes));
        $assignments = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Assignments fetch error: " . $e->getMessage());
        $assignments = [];
    }
}

// Get success/error message from URL
if (isset($_GET['message'])) {
    $message = $_GET['message'];
    $message_type = $_GET['type'] ?? 'success';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Assignments</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            padding: 10px 14px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            font-family: inherit;
            width: 100%;
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

        /* Radio Group */
        .radio-group {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            padding: 12px;
            background: var(--gray-50);
            border-radius: var(--radius-md);
        }

        .radio-option {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .radio-option input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .radio-option i {
            font-size: 1rem;
        }

        .radio-option small {
            color: var(--gray-600);
        }

        /* Info Box */
        .info-box {
            padding: 15px;
            background: #fff3cd;
            border-radius: var(--radius-md);
            border-left: 4px solid var(--warning-color);
        }

        .info-box i {
            color: var(--warning-color);
            margin-right: 8px;
        }

        .info-box p {
            margin-top: 5px;
            font-size: 0.8rem;
        }

        /* Buttons */
        .btn {
            padding: 10px 20px;
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

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background: #c0392b;
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
            min-width: 800px;
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

        /* Make assignment title bigger */
        .data-table td strong {
            font-size: 0.9rem;
            font-weight: 600;
        }

        /* File Attachment */
        .file-attachment {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--gray-100);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
        }

        .file-attachment i {
            font-size: 0.7rem;
        }

        .file-attachment a {
            color: var(--primary-color);
            text-decoration: none;
        }

        .file-attachment a:hover {
            text-decoration: underline;
        }

        /* Deadline Styling */
        .deadline-past {
            color: var(--danger-color);
            font-weight: 600;
        }

        .deadline-upcoming {
            color: var(--success-color);
        }

        /* Alerts */
        .alert-success {
            background: #d5f4e6;
            padding: 15px 20px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            color: var(--success-color);
            display: flex;
            align-items: center;
            gap: 10px;
            border-left: 4px solid var(--success-color);
        }

        .alert-error {
            background: #f8d7da;
            padding: 15px 20px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            color: var(--danger-color);
            display: flex;
            align-items: center;
            gap: 10px;
            border-left: 4px solid var(--danger-color);
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

        /* Info Item */
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

        /* Submission Type Badge */
        .submission-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .submission-online {
            background: #d1ecf1;
            color: #0c5460;
        }

        .submission-written {
            background: #f8d7da;
            color: #721c24;
        }

        .submission-both {
            background: #d5f4e6;
            color: #155724;
        }

        /* Responsive */
        @media (min-width: 768px) {
            .main-content {
                margin-left: 280px;
            }
        }

        @media (max-width: 767px) {
            .main-content {
                padding-top: 70px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .radio-group {
                flex-direction: column;
                gap: 10px;
            }

            .top-header {
                flex-direction: column;
                text-align: center;
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

    <!-- Include Staff Sidebar -->
    <?php include_once 'includes/staff_sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-tasks"></i> Assignments</h1>
                <p><i class="fas fa-chevron-right"></i> Create and manage assignments with file attachments</p>
            </div>
            <div>
                <span class="info-item"><i class="fas fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></span>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Create Assignment Form -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-plus-circle"></i> Create New Assignment</h3>
            </div>
            <?php if (empty($classes) || empty($subjects)): ?>
                <div class="empty-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>You need to be assigned to classes and subjects before creating assignments.</p>
                    <p>Please contact the administrator to assign you to classes and subjects.</p>
                </div>
            <?php else: ?>
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Assignment Title *</label>
                            <input type="text" name="title" class="form-control" placeholder="e.g., Mathematics Assignment 1" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-book"></i> Subject *</label>
                            <select name="subject_id" class="form-select" required>
                                <option value="">Select Subject</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['subject_id']; ?>"><?php echo htmlspecialchars($subject['subject_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-layer-group"></i> Class *</label>
                            <select name="class" class="form-select" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo htmlspecialchars($class); ?>"><?php echo htmlspecialchars($class); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar"></i> Deadline *</label>
                            <input type="datetime-local" name="deadline" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-star"></i> Maximum Marks</label>
                            <input type="number" name="max_marks" class="form-control" value="100" min="0" step="1">
                        </div>
                    </div>

                    <!-- Submission Type Section -->
                    <div class="form-group">
                        <label><i class="fas fa-laptop"></i> Submission Type *</label>
                        <div class="radio-group">
                            <label class="radio-option">
                                <input type="radio" name="submission_type" value="online" checked onchange="toggleSubmissionOptions()">
                                <i class="fas fa-globe" style="color: #3498db;"></i>
                                <strong>Online Submission</strong>
                                <small>(Submit via portal)</small>
                            </label>
                            <label class="radio-option">
                                <input type="radio" name="submission_type" value="written" onchange="toggleSubmissionOptions()">
                                <i class="fas fa-pen-fancy" style="color: #e74c3c;"></i>
                                <strong>Written Submission</strong>
                                <small>(Physical submission in class)</small>
                            </label>
                            <label class="radio-option">
                                <input type="radio" name="submission_type" value="both" onchange="toggleSubmissionOptions()">
                                <i class="fas fa-exchange-alt" style="color: #27ae60;"></i>
                                <strong>Both Options</strong>
                                <small>(Students can choose)</small>
                            </label>
                        </div>
                    </div>

                    <!-- Online Submission Options (shown by default) -->
                    <div id="onlineOptions" class="form-group">
                        <label><i class="fas fa-paperclip"></i> Allow File Attachments?</label>
                        <div class="radio-group">
                            <label class="radio-option">
                                <input type="radio" name="allow_attachment" value="1" checked> Yes, allow attachments
                            </label>
                            <label class="radio-option">
                                <input type="radio" name="allow_attachment" value="0"> No, text only
                            </label>
                        </div>
                        <small style="color: var(--gray-600); display: block; margin-top: 5px;">If enabled, students can upload PDF, DOC, or image files (max 10MB).</small>
                    </div>

                    <!-- Written Submission Info (hidden initially) -->
                    <div id="writtenInfo" class="info-box" style="display: none;">
                        <i class="fas fa-info-circle"></i>
                        <strong>Written Submission Instructions:</strong>
                        <p>Students will submit this assignment physically in class. No online submission will be accepted through the portal. Make sure to collect the physical copies during class.</p>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-align-left"></i> Instructions</label>
                        <textarea name="instructions" class="form-control" rows="4" placeholder="Enter assignment instructions, guidelines, or additional information..."></textarea>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-paperclip"></i> Attachment (Optional)</label>
                        <input type="file" name="attachment" class="form-control" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.zip,.rar,.jpg,.jpeg,.png">
                        <small style="color: var(--gray-600); margin-top: 5px;">Max size: 10MB. Allowed: PDF, DOC, DOCX, PPT, PPTX, XLS, XLSX, TXT, ZIP, RAR, Images</small>
                    </div>

                    <button type="submit" name="create_assignment" class="btn btn-primary">
                        <i class="fas fa-save"></i> Create Assignment
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <script>
            function toggleSubmissionOptions() {
                const submissionType = document.querySelector('input[name="submission_type"]:checked').value;
                const onlineOptions = document.getElementById('onlineOptions');
                const writtenInfo = document.getElementById('writtenInfo');

                if (submissionType === 'online') {
                    onlineOptions.style.display = 'block';
                    writtenInfo.style.display = 'none';
                } else if (submissionType === 'written') {
                    onlineOptions.style.display = 'none';
                    writtenInfo.style.display = 'block';
                } else if (submissionType === 'both') {
                    onlineOptions.style.display = 'block';
                    writtenInfo.style.display = 'block';
                }
            }
        </script>

        <!-- Assignments List -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> My Assignments</h3>
                <?php if (!empty($assignments)): ?>
                    <span class="info-item"><i class="fas fa-chart-line"></i> Total: <?php echo count($assignments); ?> assignments</span>
                <?php endif; ?>
            </div>
            <?php if (empty($assignments)): ?>
                <div class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <p>No assignments created yet.</p>
                    <p>Use the form above to create your first assignment.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Subject / Class</th>
                                <th>Deadline</th>
                                <th>Type</th>
                                <th>Attachment</th>
                                <th>Submissions</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignments as $assignment):
                                $is_deadline_past = strtotime($assignment['deadline']) < time();
                                $submission_type = $assignment['submission_type'] ?? 'online';
                                $type_badge_class = $submission_type == 'online' ? 'submission-online' : ($submission_type == 'written' ? 'submission-written' : 'submission-both');
                                $type_icon = $submission_type == 'online' ? '🌐' : ($submission_type == 'written' ? '📝' : '🔄');
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($assignment['title']); ?></strong></td>
                                    <td>
                                        <?php echo htmlspecialchars($assignment['subject_name']); ?><br>
                                        <small class="info-item" style="background: none; padding: 0;">📚 <?php echo htmlspecialchars($assignment['class']); ?></small>
                                    </td>
                                    <td>
                                        <?php echo date('M d, Y H:i', strtotime($assignment['deadline'])); ?>
                                        <?php if ($is_deadline_past): ?>
                                            <br><span class="deadline-past"><i class="fas fa-clock"></i> Past Deadline</span>
                                        <?php else: ?>
                                            <?php $days_left = ceil((strtotime($assignment['deadline']) - time()) / (60 * 60 * 24)); ?>
                                            <br><span class="deadline-upcoming"><i class="fas fa-hourglass-half"></i> <?php echo $days_left; ?> days left</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="submission-badge <?php echo $type_badge_class; ?>">
                                            <?php echo $type_icon; ?> <?php echo ucfirst($submission_type); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($assignment['file_path']): ?>
                                            <div class="file-attachment">
                                                <i class="fas fa-paperclip"></i>
                                                <a href="/gos/<?php echo $assignment['file_path']; ?>" target="_blank">
                                                    <?php
                                                    $file_name = basename($assignment['file_path']);
                                                    echo strlen($file_name) > 20 ? substr($file_name, 0, 20) . '...' : $file_name;
                                                    ?>
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: var(--gray-400);"><i class="fas fa-ban"></i> No attachment</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="info-item" style="background: var(--gray-100); padding: 4px 10px;">
                                            <i class="fas fa-users"></i> <?php echo $assignment['submissions_count']; ?>
                                            <?php echo $assignment['submissions_count'] == 1 ? 'submission' : 'submissions'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                            <a href="view-submissions.php?id=<?php echo $assignment['id']; ?>" class="btn btn-primary btn-sm">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <a href="edit-assignment.php?id=<?php echo $assignment['id']; ?>" class="btn btn-primary btn-sm">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <a href="?delete=<?php echo $assignment['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this assignment? This action cannot be undone.')">
                                                <i class="fas fa-trash"></i> Delete
                                            </a>
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
        // Set minimum date for deadline to today
        const deadlineInput = document.querySelector('input[name="deadline"]');
        if (deadlineInput) {
            const now = new Date();
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            const minDateTime = now.toISOString().slice(0, 16);
            deadlineInput.min = minDateTime;
        }
    </script>
</body>

</html>
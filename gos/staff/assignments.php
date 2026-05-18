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
$staff_id = $_SESSION['user_id'];
$staff_name = $_SESSION['user_name'] ?? 'Staff Member';

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
    // Get the staff_id string from the staff table
    $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
    $stmt->execute([$staff_id, $school_id]);
    $staff_id_string = $stmt->fetchColumn();

    if (!$staff_id_string) {
        $message = "Staff record not found. Please contact administrator.";
        $message_type = "error";
    } else {
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
    $file_path = null;

    // Handle file upload
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['attachment'];
        $file_name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $file['name']);
        $target_file = $upload_dir . $file_name;

        // Get file extension
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed_extensions = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'zip', 'rar', 'jpg', 'jpeg', 'png'];

        // Validate file type
        if (in_array($file_ext, $allowed_extensions)) {
            // Validate file size (max 10MB)
            if ($file['size'] <= 10 * 1024 * 1024) {
                if (move_uploaded_file($file['tmp_name'], $target_file)) {
                    $file_path = 'uploads/assignments/' . $file_name;
                } else {
                    $message = "Failed to upload file. Please check directory permissions.";
                    $message_type = "error";
                }
            } else {
                $message = "File is too large. Maximum size is 10MB.";
                $message_type = "error";
            }
        } else {
            $message = "File type not allowed. Allowed types: " . implode(', ', $allowed_extensions);
            $message_type = "error";
        }
    }

    // Only proceed if no file upload error
    if (!isset($message) || $message_type !== 'error') {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO assignments (title, subject_id, class, instructions, deadline, max_marks, file_path, staff_id, school_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $title,
                $subject_id,
                $class,
                $instructions,
                $deadline,
                $max_marks,
                $file_path,
                $staff_id_string,
                $school_id
            ]);

            $message = "Assignment created successfully!";
            $message_type = "success";
        } catch (Exception $e) {
            error_log("Assignment creation error: " . $e->getMessage());
            $message = "Failed to create assignment: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Handle assignment deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $assignment_id = intval($_GET['delete']);
    try {
        // Get file path before deleting
        $stmt = $pdo->prepare("SELECT file_path FROM assignments WHERE id = ? AND school_id = ? AND staff_id = ?");
        $stmt->execute([$assignment_id, $school_id, $staff_id_string]);
        $assignment = $stmt->fetch();

        if ($assignment) {
            // Delete file if exists
            if ($assignment['file_path'] && file_exists('../' . $assignment['file_path'])) {
                unlink('../' . $assignment['file_path']);
            }

            // Delete assignment
            $stmt = $pdo->prepare("DELETE FROM assignments WHERE id = ? AND school_id = ? AND staff_id = ?");
            $stmt->execute([$assignment_id, $school_id, $staff_id_string]);

            $message = "Assignment deleted successfully!";
            $message_type = "success";
        }
    } catch (Exception $e) {
        error_log("Assignment deletion error: " . $e->getMessage());
        $message = "Failed to delete assignment.";
        $message_type = "error";
    }
}

// Get assignments
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
        $stmt->execute(array_merge([$school_id, $staff_id_string], $classes));
        $assignments = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Assignments fetch error: " . $e->getMessage());
        $assignments = [];
    }
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
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-color), #1a2a3a);
            color: white;
            padding: 20px 0;
            z-index: 100;
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
            background: #d4af7a;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .staff-info {
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

        .main-content {
            margin-left: 0;
            padding: 20px;
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
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.6rem;
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            border-bottom: 2px solid #ecf0f1;
            padding-bottom: 10px;
        }

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
            font-size: 12px;
            margin-bottom: 5px;
            font-weight: 500;
            color: #555;
        }

        .form-control,
        .form-select {
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            width: 100%;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

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

        .btn-danger {
            background: #e74c3c;
            color: white;
        }

        .btn-sm {
            padding: 5px 12px;
            font-size: 12px;
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
            background: #f5f5f5;
            font-weight: 600;
        }

        .alert-success {
            background: #d5f4e6;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            color: #27ae60;
        }

        .alert-error {
            background: #f8d7da;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            color: #e74c3c;
        }

        .empty-state {
            text-align: center;
            padding: 30px;
            color: #999;
        }

        .file-attachment {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #f0f0f0;
            padding: 4px 8px;
            border-radius: 5px;
            font-size: 11px;
        }

        .file-attachment i {
            font-size: 12px;
        }

        .file-attachment a {
            color: var(--primary-color);
            text-decoration: none;
        }

        .deadline-past {
            color: #e74c3c;
            font-weight: bold;
        }

        .deadline-upcoming {
            color: #27ae60;
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
            .form-grid {
                grid-template-columns: 1fr;
            }

            .top-header {
                flex-direction: column;
                text-align: center;
            }

            .data-table {
                font-size: 12px;
            }

            .data-table th,
            .data-table td {
                padding: 8px;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>

    <div class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-chalkboard-teacher"></i></div>
            <div class="logo-text">
                <h3><?php echo htmlspecialchars($school_name); ?></h3>
                <p>Staff Portal</p>
            </div>
        </div>
        <div class="staff-info">
            <h4><?php echo htmlspecialchars($staff_name); ?></h4>
        </div>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-students.php"><i class="fas fa-users"></i> My Students</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="assignments.php" class="active"><i class="fas fa-tasks"></i> Assignments</a></li>
            <li><a href="/gos/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-tasks"></i> Assignments</h1>
                <p>Create and manage assignments with file attachments</p>
            </div>
            <button class="btn" onclick="window.location.href='/gos/logout.php'"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>

        <?php if (isset($message)): ?>
            <div class="alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Create Assignment -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-plus-circle"></i> Create New Assignment</h3>
            </div>
            <?php if (empty($classes) || empty($subjects)): ?>
                <div class="empty-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>You need to be assigned to classes and subjects before creating assignments.</p>
                    <p style="margin-top: 10px;">Please contact the administrator to assign you to classes and subjects.</p>
                </div>
            <?php else: ?>
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Assignment Title *</label>
                            <input type="text" name="title" class="form-control" placeholder="e.g., Mathematics Assignment 1" required>
                        </div>
                        <div class="form-group">
                            <label>Subject *</label>
                            <select name="subject_id" class="form-select" required>
                                <option value="">Select Subject</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['subject_id']; ?>"><?php echo htmlspecialchars($subject['subject_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Class *</label>
                            <select name="class" class="form-select" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo htmlspecialchars($class); ?>"><?php echo htmlspecialchars($class); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Deadline *</label>
                            <input type="datetime-local" name="deadline" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Maximum Marks</label>
                            <input type="number" name="max_marks" class="form-control" value="100" min="0" step="1">
                        </div>
                        <div class="form-group">
                            <label>Attachment (Optional)</label>
                            <input type="file" name="attachment" class="form-control" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.zip,.rar,.jpg,.jpeg,.png">
                            <small style="color: #666; margin-top: 5px;">Max size: 10MB. Allowed: PDF, DOC, DOCX, PPT, PPTX, XLS, XLSX, TXT, ZIP, RAR, Images</small>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Instructions</label>
                        <textarea name="instructions" class="form-control" rows="3" placeholder="Enter assignment instructions, guidelines, or additional information..."></textarea>
                    </div>
                    <button type="submit" name="create_assignment" class="btn btn-primary">
                        <i class="fas fa-save"></i> Create Assignment
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Assignments List -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> My Assignments</h3>
            </div>
            <?php if (empty($assignments)): ?>
                <div class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <p>No assignments created yet.</p>
                    <p style="margin-top: 10px;">Use the form above to create your first assignment.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Subject</th>
                                <th>Class</th>
                                <th>Deadline</th>
                                <th>Attachment</th>
                                <th>Submissions</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignments as $assignment):
                                $is_deadline_past = strtotime($assignment['deadline']) < time();
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($assignment['title']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($assignment['subject_name']); ?></td>
                                    <td><?php echo htmlspecialchars($assignment['class']); ?></td>
                                    <td><?php
                                        echo date('M d, Y H:i', strtotime($assignment['deadline']));
                                        if ($is_deadline_past) {
                                            echo '<br><small class="deadline-past">Past Deadline</small>';
                                        } else {
                                            $days_left = ceil((strtotime($assignment['deadline']) - time()) / (60 * 60 * 24));
                                            echo '<br><small class="deadline-upcoming">' . $days_left . ' days left</small>';
                                        }
                                        ?></td>
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
                                            <span style="color: #999;">No attachment</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $assignment['submissions_count']; ?>
                                        <?php echo $assignment['submissions_count'] == 1 ? 'submission' : 'submissions'; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 5px; flex-wrap: wrap;">
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
        document.getElementById('mobileMenuBtn').onclick = () => document.getElementById('sidebar').classList.toggle('active');

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768) {
                const sidebar = document.getElementById('sidebar');
                const menuBtn = document.getElementById('mobileMenuBtn');
                if (!sidebar.contains(e.target) && !menuBtn.contains(e.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

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
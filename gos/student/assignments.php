<?php
// gos/student/assignments.php - Student Assignments with File Handling
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: /gos/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'] ?? 'Student';

// Get student class from database (not just session)
$stmt = $pdo->prepare("SELECT class, admission_number FROM students WHERE id = ? AND school_id = ?");
$stmt->execute([$student_id, $school_id]);
$student_data = $stmt->fetch();

if (!$student_data) {
    header("Location: /gos/login.php");
    exit();
}

$student_class = $student_data['class']; // This is the class name string
$admission_number = $student_data['admission_number'];

$assignment_id = $_GET['id'] ?? 0;
$view_submission = $_GET['submission'] ?? 0;

// Debug - uncomment to check if class is being retrieved
// error_log("Student ID: $student_id, Class: $student_class");

// Handle assignment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_assignment'])) {
    $assignment_id = $_POST['assignment_id'];
    $submitted_text = trim($_POST['submitted_text']);
    $submission_method = $_POST['submission_method'] ?? 'online';

    // Handle file upload
    $file_path = null;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/submissions/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $file_name = time() . '_' . $student_id . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_FILES['attachment']['name']));
        $target_file = $upload_dir . $file_name;

        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target_file)) {
            $file_path = 'uploads/submissions/' . $file_name;
        }
    }

    // Check if already submitted
    $stmt = $pdo->prepare("SELECT id FROM assignment_submissions WHERE student_id = ? AND assignment_id = ?");
    $stmt->execute([$student_id, $assignment_id]);

    if ($stmt->fetch()) {
        $message = "You have already submitted this assignment!";
        $message_type = "error";
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO assignment_submissions (student_id, assignment_id, submitted_text, file_path, status, school_id, submitted_at)
            VALUES (?, ?, ?, ?, 'submitted', ?, NOW())
        ");
        $stmt->execute([$student_id, $assignment_id, $submitted_text, $file_path, $school_id]);

        $message = "Assignment submitted successfully!";
        $message_type = "success";
    }
}

// Get specific assignment details
$assignment = null;
$existing_submission = null;
if ($assignment_id) {
    // Use class name directly (both are strings)
    $stmt = $pdo->prepare("
        SELECT a.*, s.subject_name 
        FROM assignments a
        JOIN subjects s ON a.subject_id = s.id
        WHERE a.id = ? AND a.school_id = ? AND a.class = ?
    ");
    $stmt->execute([$assignment_id, $school_id, $student_class]);
    $assignment = $stmt->fetch();

    if ($assignment) {
        // Check if already submitted
        $stmt = $pdo->prepare("
            SELECT * FROM assignment_submissions 
            WHERE student_id = ? AND assignment_id = ?
        ");
        $stmt->execute([$student_id, $assignment_id]);
        $existing_submission = $stmt->fetch();
    } else {
        // Assignment not found for this class
        $error_message = "Assignment not found or not available for your class.";
    }
}

// Get submission details
$submission = null;
if ($view_submission) {
    $stmt = $pdo->prepare("
        SELECT asub.*, a.title, a.subject_id, s.subject_name, a.max_marks, a.instructions, a.file_path as assignment_file
        FROM assignment_submissions asub
        JOIN assignments a ON asub.assignment_id = a.id
        JOIN subjects s ON a.subject_id = s.id
        WHERE asub.id = ? AND asub.student_id = ?
    ");
    $stmt->execute([$view_submission, $student_id]);
    $submission = $stmt->fetch();
}

// Get pending assignments (not submitted yet)
$stmt = $pdo->prepare("
    SELECT a.*, s.subject_name,
           DATEDIFF(a.deadline, NOW()) as days_left,
           CASE 
               WHEN a.deadline < NOW() THEN 'expired'
               WHEN DATEDIFF(a.deadline, NOW()) <= 1 THEN 'urgent'
               WHEN DATEDIFF(a.deadline, NOW()) <= 3 THEN 'near'
               ELSE 'normal'
           END as urgency
    FROM assignments a
    JOIN subjects s ON a.subject_id = s.id
    WHERE a.school_id = ? AND a.class = ? 
    AND a.id NOT IN (
        SELECT assignment_id FROM assignment_submissions 
        WHERE student_id = ?
    )
    ORDER BY a.deadline ASC
");
$stmt->execute([$school_id, $student_class, $student_id]);
$pending_assignments = $stmt->fetchAll();

// Get completed/submitted assignments
$stmt = $pdo->prepare("
    SELECT asub.*, a.title, a.subject_id, s.subject_name, a.max_marks, a.deadline
    FROM assignment_submissions asub
    JOIN assignments a ON asub.assignment_id = a.id
    JOIN subjects s ON a.subject_id = s.id
    WHERE asub.student_id = ? AND asub.school_id = ?
    ORDER BY asub.submitted_at DESC
");
$stmt->execute([$student_id, $school_id]);
$completed_assignments = $stmt->fetchAll();

// Debug info - uncomment to see what's happening
// if (empty($pending_assignments) && empty($completed_assignments)) {
//     error_log("No assignments found for class: $student_class");
//     $debug_info = "Class: $student_class, School ID: $school_id";
// }
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
            --success-color: #27ae60;
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
            --info-color: #3498db;
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

        .student-info {
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
            border-bottom: 2px solid #ecf0f1;
            padding-bottom: 10px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .assignment-item {
            padding: 15px;
            border-bottom: 1px solid #eee;
            transition: background 0.2s ease;
        }

        .assignment-item:hover {
            background: #f8f9fa;
        }

        .assignment-item:last-child {
            border-bottom: none;
        }

        .assignment-title {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 5px;
        }

        .assignment-meta {
            font-size: 0.75rem;
            color: #666;
            margin-bottom: 8px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.8rem;
            transition: all 0.2s ease;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-info {
            background: var(--info-color);
            color: white;
        }

        .btn-sm {
            padding: 4px 10px;
            font-size: 0.7rem;
        }

        .deadline-urgent {
            color: var(--danger-color);
            font-weight: bold;
        }

        .deadline-near {
            color: var(--warning-color);
        }

        .deadline-expired {
            color: #999;
        }

        .submission-detail {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }

        .grade-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .grade-graded {
            background: #d5f4e6;
            color: var(--success-color);
        }

        .grade-submitted {
            background: #fff3cd;
            color: var(--warning-color);
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 0.85rem;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.85rem;
            transition: border-color 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
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
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid var(--info-color);
        }

        .file-attachment {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #f0f0f0;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.75rem;
        }

        .file-attachment a {
            color: var(--primary-color);
            text-decoration: none;
        }

        .file-attachment a:hover {
            text-decoration: underline;
        }

        .submission-type {
            display: flex;
            gap: 20px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .submission-type label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
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
            .top-header {
                flex-direction: column;
                text-align: center;
            }

            .assignment-meta {
                flex-direction: column;
                gap: 5px;
            }

            .submission-type {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>

    <div class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-user-graduate"></i></div>
            <div class="logo-text">
                <h3><?php echo htmlspecialchars($school_name); ?></h3>
                <p>Student Portal</p>
            </div>
        </div>
        <div class="student-info">
            <h4><?php echo htmlspecialchars($student_name); ?></h4>
            <p><?php echo htmlspecialchars($student_class); ?></p>
            <p><?php echo htmlspecialchars($admission_number); ?></p>
        </div>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="take-exam.php"><i class="fas fa-file-alt"></i> Take Exam</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> My Results</a></li>
            <li><a href="assignments.php" class="active"><i class="fas fa-tasks"></i> Assignments</a></li>
            <li><a href="profile.php"><i class="fas fa-user-cog"></i> My Profile</a></li>
            <li><a href="/gos/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-tasks"></i> Assignments</h1>
                <p>View and submit your assignments for <?php echo htmlspecialchars($student_class); ?></p>
            </div>
            <button class="btn" onclick="window.location.href='/gos/logout.php'"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>

        <?php if (isset($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <!-- View Assignment Details (for submission) -->
        <?php if ($assignment && !$existing_submission && !$view_submission): ?>
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($assignment['title']); ?></h3>
                    <?php
                    $is_expired = strtotime($assignment['deadline']) < time();
                    if ($is_expired):
                    ?>
                        <span class="grade-badge grade-submitted"><i class="fas fa-hourglass-end"></i> Expired</span>
                    <?php endif; ?>
                </div>

                <div class="assignment-meta" style="margin-bottom: 15px;">
                    <span><i class="fas fa-book"></i> <?php echo htmlspecialchars($assignment['subject_name']); ?></span>
                    <span><i class="fas fa-calendar"></i> Due: <?php echo date('F j, Y g:i A', strtotime($assignment['deadline'])); ?></span>
                    <span><i class="fas fa-star"></i> Max Marks: <?php echo $assignment['max_marks']; ?></span>
                    <span>
                        <?php if ($assignment['submission_type'] == 'online'): ?>
                            <i class="fas fa-globe"></i> Online Submission
                        <?php elseif ($assignment['submission_type'] == 'written'): ?>
                            <i class="fas fa-pen-fancy"></i> Written Submission
                        <?php else: ?>
                            <i class="fas fa-exchange-alt"></i> Online or Written
                        <?php endif; ?>
                    </span>
                </div>

                <div style="margin-bottom: 20px;">
                    <strong><i class="fas fa-info-circle"></i> Instructions:</strong>
                    <p style="margin-top: 8px; line-height: 1.6;"><?php echo nl2br(htmlspecialchars($assignment['instructions'])); ?></p>
                </div>

                <?php if ($assignment['file_path']): ?>
                    <div style="margin-bottom: 20px;">
                        <strong><i class="fas fa-paperclip"></i> Assignment File:</strong>
                        <div class="file-attachment" style="margin-top: 8px;">
                            <i class="fas fa-file"></i>
                            <a href="/gos/<?php echo $assignment['file_path']; ?>" target="_blank">
                                <?php echo basename($assignment['file_path']); ?>
                            </a>
                            <a href="/gos/<?php echo $assignment['file_path']; ?>" download class="btn btn-info btn-sm">
                                <i class="fas fa-download"></i> Download
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($assignment['submission_type'] == 'written'): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Written Submission Required:</strong>
                        <p style="margin-top: 5px;">This assignment requires a written submission. Please complete it on paper and submit it to your teacher in class.</p>
                        <p style="margin-top: 5px;">No online submission is available for this assignment.</p>
                    </div>
                    <a href="assignments.php" class="btn btn-warning">Back to Assignments</a>

                <?php elseif ($assignment['submission_type'] == 'online' || $assignment['submission_type'] == 'both'): ?>

                    <?php if ($assignment['submission_type'] == 'both'): ?>
                        <div class="form-group">
                            <label><i class="fas fa-laptop"></i> How would you like to submit?</label>
                            <div class="submission-type">
                                <label>
                                    <input type="radio" name="submission_method" value="online" checked onchange="toggleSubmissionMethod()">
                                    <i class="fas fa-globe"></i> Online Submission (Submit via portal)
                                </label>
                                <label>
                                    <input type="radio" name="submission_method" value="written" onchange="toggleSubmissionMethod()">
                                    <i class="fas fa-pen-fancy"></i> Written Submission (Submit physically in class)
                                </label>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div id="onlineSubmissionForm" style="<?php echo ($assignment['submission_type'] == 'both') ? '' : 'display: block;'; ?>">
                        <?php if (!$is_expired): ?>
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                                <input type="hidden" name="submission_method" id="submissionMethodInput" value="online">

                                <div class="form-group">
                                    <label><i class="fas fa-pen"></i> Your Submission</label>
                                    <textarea name="submitted_text" class="form-control" rows="6" placeholder="Type your answer here..."></textarea>
                                </div>

                                <?php if ($assignment['allow_attachment']): ?>
                                    <div class="form-group">
                                        <label><i class="fas fa-paperclip"></i> Attachment (Optional)</label>
                                        <input type="file" name="attachment" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.zip">
                                        <small style="color: #666;">Allowed: PDF, DOC, DOCX, JPG, PNG, ZIP (Max 10MB)</small>
                                    </div>
                                <?php endif; ?>

                                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                    <button type="submit" name="submit_assignment" class="btn btn-success">
                                        <i class="fas fa-paper-plane"></i> Submit Assignment
                                    </button>
                                    <a href="assignments.php" class="btn btn-warning">
                                        <i class="fas fa-arrow-left"></i> Back
                                    </a>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="alert alert-error">
                                <i class="fas fa-hourglass-end"></i> This assignment has expired and can no longer be submitted.
                            </div>
                            <a href="assignments.php" class="btn btn-warning">Back to Assignments</a>
                        <?php endif; ?>
                    </div>

                    <div id="writtenSubmissionForm" style="display: none;">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>You've chosen Written Submission:</strong>
                            <p style="margin-top: 5px;">Please complete this assignment on paper and submit it to your teacher in class.</p>
                            <p style="margin-top: 5px;">Make sure to write your name, admission number, and assignment title on your submission.</p>
                        </div>
                        <a href="assignments.php" class="btn btn-success" onclick="return confirmWrittenSubmission(<?php echo $assignment['id']; ?>)">
                            <i class="fas fa-check-circle"></i> Confirm Written Submission
                        </a>
                        <button type="button" class="btn btn-warning" onclick="document.querySelector('input[name=\" submission_method\"][value=\"online\"]').checked=true; toggleSubmissionMethod();">
                            <i class="fas fa-arrow-left"></i> Go Back to Online Submission
                        </button>
                    </div>

                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Already Submitted Message -->
        <?php if ($assignment && $existing_submission && !$view_submission): ?>
            <div class="card">
                <div class="card-header">
                    <h3><?php echo htmlspecialchars($assignment['title']); ?></h3>
                </div>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> You have already submitted this assignment.
                </div>
                <div class="submission-detail">
                    <p><strong>Submitted on:</strong> <?php echo date('F j, Y g:i A', strtotime($existing_submission['submitted_at'])); ?></p>
                    <p><strong>Status:</strong> <span class="grade-badge grade-<?php echo $existing_submission['status']; ?>"><?php echo ucfirst($existing_submission['status']); ?></span></p>
                    <?php if ($existing_submission['status'] === 'graded'): ?>
                        <p><strong>Grade:</strong> <?php echo $existing_submission['grade']; ?> / <?php echo $assignment['max_marks']; ?></p>
                        <p><strong>Teacher's Feedback:</strong> <?php echo nl2br(htmlspecialchars($existing_submission['teacher_feedback'])); ?></p>
                    <?php endif; ?>
                </div>
                <a href="assignments.php" class="btn btn-primary" style="margin-top: 15px;">Back to Assignments</a>
            </div>
        <?php endif; ?>

        <!-- View Submission Details -->
        <?php if ($submission): ?>
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-eye"></i> Submission: <?php echo htmlspecialchars($submission['title']); ?></h3>
                </div>

                <div class="assignment-meta" style="margin-bottom: 15px;">
                    <span><i class="fas fa-book"></i> <?php echo htmlspecialchars($submission['subject_name']); ?></span>
                    <span><i class="fas fa-calendar-check"></i> Submitted: <?php echo date('F j, Y g:i A', strtotime($submission['submitted_at'])); ?></span>
                </div>

                <?php if ($submission['assignment_file']): ?>
                    <div style="margin-bottom: 15px;">
                        <strong><i class="fas fa-paperclip"></i> Original Assignment:</strong>
                        <div class="file-attachment" style="margin-top: 5px;">
                            <i class="fas fa-file"></i>
                            <a href="/gos/<?php echo $submission['assignment_file']; ?>" target="_blank">Download Assignment File</a>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="submission-detail">
                    <p><strong>Status:</strong> <span class="grade-badge grade-<?php echo $submission['status']; ?>"><?php echo ucfirst($submission['status']); ?></span></p>

                    <?php if ($submission['status'] === 'graded'): ?>
                        <p style="margin-top: 10px;"><strong>Grade:</strong> <?php echo $submission['grade']; ?> / <?php echo $submission['max_marks']; ?></p>
                        <p><strong>Teacher's Feedback:</strong> <?php echo nl2br(htmlspecialchars($submission['teacher_feedback'])); ?></p>
                    <?php endif; ?>

                    <p style="margin-top: 15px;"><strong><i class="fas fa-pen"></i> Your Submission:</strong></p>
                    <div style="background: white; padding: 12px; border-radius: 8px; margin-top: 5px; border: 1px solid #e0e0e0;">
                        <?php echo nl2br(htmlspecialchars($submission['submitted_text'])); ?>
                    </div>

                    <?php if ($submission['file_path']): ?>
                        <p style="margin-top: 15px;"><strong><i class="fas fa-paperclip"></i> Your Attachment:</strong></p>
                        <div class="file-attachment">
                            <i class="fas fa-file"></i>
                            <a href="/gos/<?php echo $submission['file_path']; ?>" target="_blank"><?php echo basename($submission['file_path']); ?></a>
                            <a href="/gos/<?php echo $submission['file_path']; ?>" download class="btn btn-info btn-sm">
                                <i class="fas fa-download"></i> Download
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <a href="assignments.php" class="btn btn-primary" style="margin-top: 15px;">
                    <i class="fas fa-arrow-left"></i> Back to Assignments
                </a>
            </div>
        <?php endif; ?>

        <!-- Pending Assignments -->
        <?php if (!$assignment_id && !$view_submission): ?>
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-hourglass-half"></i> Pending Assignments</h3>
                    <span class="assignment-meta" style="margin:0;"><?php echo count($pending_assignments); ?> pending</span>
                </div>
                <?php if (empty($pending_assignments)): ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <p>No pending assignments. Great job!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($pending_assignments as $assignment):
                        $is_expired = strtotime($assignment['deadline']) < time();
                        $urgency_class = $is_expired ? 'deadline-expired' : ($assignment['urgency'] === 'urgent' ? 'deadline-urgent' : ($assignment['urgency'] === 'near' ? 'deadline-near' : ''));
                    ?>
                        <div class="assignment-item">
                            <div class="assignment-title">
                                <?php echo htmlspecialchars($assignment['title']); ?>
                                <?php if ($is_expired): ?>
                                    <span class="grade-badge grade-submitted" style="margin-left: 10px;">Expired</span>
                                <?php endif; ?>
                            </div>
                            <div class="assignment-meta">
                                <span><i class="fas fa-book"></i> <?php echo htmlspecialchars($assignment['subject_name']); ?></span>
                                <span><i class="fas fa-calendar"></i> Due: <?php echo date('M d, Y g:i A', strtotime($assignment['deadline'])); ?></span>
                                <?php if (!$is_expired): ?>
                                    <span class="<?php echo $urgency_class; ?>">
                                        <i class="fas fa-clock"></i>
                                        <?php if ($assignment['days_left'] <= 0): ?>
                                            Due today!
                                        <?php else: ?>
                                            <?php echo $assignment['days_left']; ?> day(s) left
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                                <span>
                                    <?php if ($assignment['submission_type'] == 'online'): ?>
                                        <i class="fas fa-globe"></i> Online
                                    <?php elseif ($assignment['submission_type'] == 'written'): ?>
                                        <i class="fas fa-pen-fancy"></i> Written
                                    <?php else: ?>
                                        <i class="fas fa-exchange-alt"></i> Online/Written
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php if ($assignment['file_path']): ?>
                                <div class="file-attachment" style="margin-bottom: 8px;">
                                    <i class="fas fa-paperclip"></i>
                                    <a href="/gos/<?php echo $assignment['file_path']; ?>" target="_blank">View Attachment</a>
                                </div>
                            <?php endif; ?>
                            <?php if (!$is_expired && $assignment['submission_type'] != 'written'): ?>
                                <a href="assignments.php?id=<?php echo $assignment['id']; ?>" class="btn btn-primary" style="margin-top: 8px;">
                                    <i class="fas fa-arrow-right"></i> Submit Assignment
                                </a>
                            <?php elseif ($assignment['submission_type'] == 'written' && !$is_expired): ?>
                                <span class="btn btn-info" style="margin-top: 8px; opacity:0.7;">
                                    <i class="fas fa-pen-fancy"></i> Written Submission Required
                                </span>
                            <?php elseif ($is_expired): ?>
                                <span class="btn btn-danger" style="margin-top: 8px; opacity:0.6;" disabled>
                                    <i class="fas fa-hourglass-end"></i> Submission Closed
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Completed Assignments -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-check-circle"></i> Completed Assignments</h3>
                    <span class="assignment-meta" style="margin:0;"><?php echo count($completed_assignments); ?> completed</span>
                </div>
                <?php if (empty($completed_assignments)): ?>
                    <div class="empty-state">
                        <i class="fas fa-folder-open"></i>
                        <p>No completed assignments yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($completed_assignments as $assignment): ?>
                        <div class="assignment-item">
                            <div class="assignment-title"><?php echo htmlspecialchars($assignment['title']); ?></div>
                            <div class="assignment-meta">
                                <span><i class="fas fa-book"></i> <?php echo htmlspecialchars($assignment['subject_name']); ?></span>
                                <span><i class="fas fa-calendar-check"></i> Submitted: <?php echo date('M d, Y', strtotime($assignment['submitted_at'])); ?></span>
                                <?php if ($assignment['status'] === 'graded'): ?>
                                    <span><i class="fas fa-star"></i> Grade: <?php echo $assignment['grade']; ?>/<?php echo $assignment['max_marks']; ?></span>
                                <?php endif; ?>
                                <span class="grade-badge grade-<?php echo $assignment['status']; ?>"><?php echo ucfirst($assignment['status']); ?></span>
                            </div>
                            <?php if ($assignment['file_path']): ?>
                                <div class="file-attachment" style="margin-bottom: 8px;">
                                    <i class="fas fa-paperclip"></i>
                                    <a href="/gos/<?php echo $assignment['file_path']; ?>" target="_blank">View Your Submission</a>
                                </div>
                            <?php endif; ?>
                            <a href="assignments.php?submission=<?php echo $assignment['id']; ?>" class="btn btn-primary btn-sm" style="margin-top: 8px;">
                                <i class="fas fa-eye"></i> View Details
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
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

        // Toggle between online and written submission methods
        function toggleSubmissionMethod() {
            const methodRadios = document.querySelectorAll('input[name="submission_method"]');
            let method = 'online';
            for (let radio of methodRadios) {
                if (radio.checked) {
                    method = radio.value;
                    break;
                }
            }

            const onlineForm = document.getElementById('onlineSubmissionForm');
            const writtenForm = document.getElementById('writtenSubmissionForm');
            const methodInput = document.getElementById('submissionMethodInput');

            if (method === 'online') {
                if (onlineForm) onlineForm.style.display = 'block';
                if (writtenForm) writtenForm.style.display = 'none';
                if (methodInput) methodInput.value = 'online';
            } else {
                if (onlineForm) onlineForm.style.display = 'none';
                if (writtenForm) writtenForm.style.display = 'block';
                if (methodInput) methodInput.value = 'written';
            }
        }

        // Confirm written submission
        function confirmWrittenSubmission(assignmentId) {
            if (confirm('Confirm that you will submit this assignment physically in class? You will not be able to submit online after this.')) {
                // You can implement an AJAX call here to record the written submission intent
                return true;
            }
            return false;
        }

        // File size validation
        document.querySelector('input[type="file"]')?.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file && file.size > 10 * 1024 * 1024) {
                alert('File size exceeds 10MB limit!');
                this.value = '';
            }
        });
    </script>
</body>

</html>
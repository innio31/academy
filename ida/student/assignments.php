<?php
// ida/student/assignments.php - Student Assignments with File Handling
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: /ida/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'] ?? 'Student';

// Get student details
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND school_id = ?");
$stmt->execute([$student_id, $school_id]);
$student_data = $stmt->fetch();

if (!$student_data) {
    header("Location: /ida/login.php");
    exit();
}

$student_class = $student_data['class'];
$admission_number = $student_data['admission_number'];

// Get profile picture path
$profile_picture = !empty($student_data['profile_picture']) ? $student_data['profile_picture'] : '/assets/uploads/default-avatar.png';
if (!empty($student_data['profile_picture']) && strpos($student_data['profile_picture'], '/') !== 0) {
    $profile_picture = '/uploads/' . $student_data['profile_picture'];
}

$assignment_id = $_GET['id'] ?? 0;
$view_submission = $_GET['submission'] ?? 0;

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
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-600: #4b5563;
            --gray-800: #1f2937;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --radius-lg: 1rem;
            --radius-md: 0.75rem;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            min-height: 100vh;
        }

        /* Sidebar Styles */
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
            overflow-y: auto;
            transform: translateX(-100%);
            transition: transform 0.3s ease;
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

        .logo-text h3 {
            font-size: 1rem;
            margin: 0;
        }

        .logo-text p {
            font-size: 0.7rem;
            opacity: 0.8;
        }

        .student-info {
            text-align: center;
            padding: 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            margin: 0 15px 20px;
        }

        .student-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #d4af7a;
            margin: 0 auto 12px auto;
            display: block;
            background: #f0f0f0;
        }

        .student-name {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .student-details {
            font-size: 0.75rem;
            opacity: 0.8;
            margin: 2px 0;
        }

        .student-details i {
            width: 18px;
            margin-right: 4px;
            font-size: 0.7rem;
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
            transition: all 0.2s;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255, 255, 255, 0.2);
        }

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
            display: block;
        }

        .top-header {
            background: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
            background: linear-gradient(135deg, var(--primary-color), #2c3e50);
            color: white;
        }

        .welcome-banner {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .welcome-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #d4af7a;
            background: #f0f0f0;
        }

        .welcome-text h1 {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .welcome-text p {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        /* Cards & containers */
        .content-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #ecf0f1;
        }

        .card-header h3 {
            color: var(--primary-color);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .badge-count {
            background: var(--gray-100);
            padding: 2px 10px;
            border-radius: 40px;
            font-size: 0.7rem;
            font-weight: 500;
            color: var(--gray-600);
        }

        .assignment-item {
            padding: 15px;
            border-bottom: 1px solid #eee;
            transition: background 0.2s;
        }

        .assignment-item:last-child {
            border-bottom: none;
        }

        .assignment-item:hover {
            background: var(--gray-50);
        }

        .assignment-title {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .assignment-meta {
            font-size: 0.75rem;
            color: #666;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 10px;
        }

        .assignment-meta span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .deadline-urgent {
            color: var(--danger);
            font-weight: 600;
        }

        .deadline-near {
            color: var(--warning);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.8rem;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-outline {
            border: 1px solid #e0e0e0;
            background: white;
            color: var(--gray-800);
        }

        .btn-sm {
            padding: 5px 12px;
            font-size: 0.7rem;
        }

        .grade-badge {
            font-size: 0.65rem;
            padding: 2px 10px;
            border-radius: 30px;
            font-weight: 600;
            background: var(--gray-100);
        }

        .grade-graded {
            background: #d1fae5;
            color: #065f46;
        }

        .grade-submitted {
            background: #fed7aa;
            color: #92400e;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            font-weight: 600;
            font-size: 0.8rem;
            margin-bottom: 6px;
            display: block;
        }

        .form-control,
        textarea.form-control {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid var(--gray-200);
            border-radius: 12px;
            font-family: inherit;
            font-size: 0.9rem;
            background: white;
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .submission-type-selector {
            display: flex;
            gap: 12px;
            background: var(--gray-50);
            padding: 10px;
            border-radius: 60px;
            margin-bottom: 16px;
        }

        .type-option {
            flex: 1;
            text-align: center;
            padding: 8px;
            border-radius: 40px;
            font-weight: 500;
            background: white;
            border: 1px solid var(--gray-200);
            cursor: pointer;
            transition: all 0.2s;
        }

        .type-option.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .file-attachment {
            background: var(--gray-50);
            padding: 8px 12px;
            border-radius: 40px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.75rem;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #e6f7ed;
            color: #0a5c2e;
            border-left: 4px solid var(--success);
        }

        .alert-error {
            background: #ffe6e5;
            color: #b91c1c;
            border-left: 4px solid var(--danger);
        }

        .alert-info {
            background: #eef2ff;
            color: #1e3a8a;
            border-left: 4px solid var(--info);
        }

        .empty-state {
            text-align: center;
            padding: 40px 24px;
            color: #999;
        }

        .submission-detail {
            background: var(--gray-50);
            padding: 16px;
            border-radius: 16px;
            margin-top: 12px;
        }

        .footer {
            text-align: center;
            padding: 20px;
            color: #666;
            font-size: 0.8rem;
            border-top: 1px solid #ecf0f1;
            margin-top: 20px;
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
            .welcome-banner {
                flex-direction: column;
                text-align: center;
            }

            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>

    <div class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-tasks"></i></div>
            <div class="logo-text">
                <h3><?php echo htmlspecialchars($school_name); ?></h3>
                <p>Student Portal</p>
            </div>
        </div>
        <div class="student-info">
            <img src="<?php echo htmlspecialchars($profile_picture); ?>"
                alt="Profile Picture"
                class="student-avatar"
                onerror="this.src='/assets/uploads/default-avatar.png'">
            <div class="student-name"><?php echo htmlspecialchars($student_name); ?></div>
            <div class="student-details"><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($admission_number); ?></div>
            <div class="student-details"><i class="fas fa-graduation-cap"></i> Class: <?php echo htmlspecialchars($student_class); ?></div>
        </div>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="take-exam.php"><i class="fas fa-file-alt"></i> Take Exam</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> My Results</a></li>
            <li><a href="assignments.php" class="active"><i class="fas fa-tasks"></i> Assignments</a></li>
            <li><a href="library.php"><i class="fas fa-book"></i> E-Library</a></li>
            <li><a href="profile.php"><i class="fas fa-user-cog"></i> My Profile</a></li>
            <li><a href="/ida/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="welcome-banner">
                <img src="<?php echo htmlspecialchars($profile_picture); ?>"
                    alt="Profile Picture"
                    class="welcome-avatar"
                    onerror="this.src='/assets/uploads/default-avatar.png'">
                <div class="welcome-text">
                    <h1>📚 My Assignments</h1>
                    <p><i class="fas fa-graduation-cap"></i> Class: <?php echo htmlspecialchars($student_class); ?> | <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($admission_number); ?></p>
                </div>
            </div>
        </div>

        <!-- Flash messages -->
        <?php if (isset($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>
        <?php if (isset($error_message)): ?>
            <div class="alert alert-error"><i class="fas fa-ban"></i> <?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <!-- ========== SINGLE ASSIGNMENT VIEW (SUBMISSION) ========== -->
        <?php if ($assignment && !$existing_submission && !$view_submission): ?>
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-pen-alt"></i> <?php echo htmlspecialchars($assignment['title']); ?></h3>
                    <?php if (strtotime($assignment['deadline']) < time()): ?>
                        <span class="grade-badge" style="background:#fee2e2; color:#b91c1c;"><i class="fas fa-hourglass-end"></i> Expired</span>
                    <?php endif; ?>
                </div>
                <div style="margin-bottom: 15px;">
                    <div class="assignment-meta" style="margin-bottom: 12px;">
                        <span><i class="fas fa-book-open"></i> <?php echo htmlspecialchars($assignment['subject_name']); ?></span>
                        <span><i class="fas fa-calendar-alt"></i> Due: <?php echo date('M d, Y g:i A', strtotime($assignment['deadline'])); ?></span>
                        <span><i class="fas fa-star"></i> Max: <?php echo $assignment['max_marks']; ?></span>
                    </div>
                    <div><strong><i class="fas fa-info-circle"></i> Instructions</strong>
                        <p style="margin-top: 8px; line-height:1.5;"><?php echo nl2br(htmlspecialchars($assignment['instructions'])); ?></p>
                    </div>
                    <?php if ($assignment['file_path']): ?>
                        <div class="file-attachment" style="margin: 12px 0;"><i class="fas fa-paperclip"></i> <a href="/ida/<?php echo $assignment['file_path']; ?>" target="_blank" style="color:var(--primary-color);">📄 Assignment file</a> <a href="/ida/<?php echo $assignment['file_path']; ?>" download class="btn btn-outline btn-sm" style="margin-left:8px;"><i class="fas fa-download"></i></a></div>
                    <?php endif; ?>
                </div>

                <?php if ($assignment['submission_type'] == 'written'): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-pen-fancy"></i> Written only: Submit physically to teacher.
                    </div>
                    <a href="assignments.php" class="btn btn-warning"><i class="fas fa-arrow-left"></i> Back</a>
                <?php elseif ($assignment['submission_type'] == 'online' || $assignment['submission_type'] == 'both'): ?>
                    <?php $is_expired = strtotime($assignment['deadline']) < time(); ?>
                    <?php if ($is_expired): ?>
                        <div class="alert alert-error">Submission deadline passed.</div>
                        <a href="assignments.php" class="btn btn-warning">Back</a>
                    <?php else: ?>
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                            <input type="hidden" name="submission_method" id="finalMethod" value="online">
                            <?php if ($assignment['submission_type'] == 'both'): ?>
                                <div class="submission-type-selector" id="methodToggle">
                                    <div class="type-option active" data-method="online"><i class="fas fa-globe"></i> Online</div>
                                    <div class="type-option" data-method="written"><i class="fas fa-pen-fancy"></i> Written</div>
                                </div>
                            <?php endif; ?>
                            <div id="onlineBox">
                                <div class="form-group">
                                    <label><i class="fas fa-paragraph"></i> Your answer</label>
                                    <textarea name="submitted_text" class="form-control" rows="5" placeholder="Write your submission..."></textarea>
                                </div>
                                <?php if ($assignment['allow_attachment']): ?>
                                    <div class="form-group">
                                        <label><i class="fas fa-paperclip"></i> Attachment (max 10MB)</label>
                                        <input type="file" name="attachment" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.zip">
                                    </div>
                                <?php endif; ?>
                                <button type="submit" name="submit_assignment" class="btn btn-success" style="width:100%;"><i class="fas fa-paper-plane"></i> Submit online</button>
                            </div>
                            <div id="writtenBox" style="display:none;">
                                <div class="alert alert-info" style="margin:10px 0;">📌 You've selected written submission. Please submit on paper in class.</div>
                                <button type="button" id="confirmWrittenBtn" class="btn btn-primary" style="width:100%;"><i class="fas fa-check"></i> Confirm written submission</button>
                            </div>
                            <a href="assignments.php" class="btn btn-outline" style="margin-top:12px; width:100%;">Cancel</a>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Already submitted view -->
        <?php if ($assignment && $existing_submission && !$view_submission): ?>
            <div class="content-card">
                <div class="card-header">
                    <h3><?php echo htmlspecialchars($assignment['title']); ?></h3>
                </div>
                <div>
                    <div class="alert alert-success"><i class="fas fa-check-circle"></i> Already submitted on <?php echo date('M d, Y', strtotime($existing_submission['submitted_at'])); ?></div>
                    <div class="submission-detail">
                        <strong>Status:</strong> <span class="grade-badge grade-<?php echo $existing_submission['status']; ?>"><?php echo ucfirst($existing_submission['status']); ?></span>
                        <?php if ($existing_submission['status'] === 'graded'): ?>
                            <p><strong>Grade:</strong> <?php echo $existing_submission['grade']; ?>/<?php echo $assignment['max_marks']; ?></p>
                            <p><strong>Feedback:</strong> <?php echo nl2br(htmlspecialchars($existing_submission['teacher_feedback'])); ?></p>
                        <?php endif; ?>
                    </div>
                    <a href="assignments.php" class="btn btn-primary" style="margin-top:16px;">← Back to list</a>
                </div>
            </div>
        <?php endif; ?>

        <!-- View single submission details -->
        <?php if ($submission): ?>
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-eye"></i> <?php echo htmlspecialchars($submission['title']); ?></h3>
                </div>
                <div>
                    <div class="assignment-meta">
                        <span><i class="fas fa-book"></i> <?php echo htmlspecialchars($submission['subject_name']); ?></span>
                        <span><i class="fas fa-calendar-check"></i> <?php echo date('M d, Y', strtotime($submission['submitted_at'])); ?></span>
                    </div>
                    <div class="submission-detail">
                        <p><strong>Status:</strong> <span class="grade-badge grade-<?php echo $submission['status']; ?>"><?php echo ucfirst($submission['status']); ?></span></p>
                        <?php if ($submission['status'] === 'graded'): ?>
                            <p><strong>Grade:</strong> <?php echo $submission['grade']; ?>/<?php echo $submission['max_marks']; ?></p>
                            <p><strong>Feedback:</strong> <?php echo nl2br(htmlspecialchars($submission['teacher_feedback'])); ?></p>
                        <?php endif; ?>
                        <p style="margin-top:12px;"><strong>Your answer:</strong></p>
                        <div style="background:white; padding:12px; border-radius:12px;"><?php echo nl2br(htmlspecialchars($submission['submitted_text'])); ?></div>
                        <?php if ($submission['file_path']): ?>
                            <div class="file-attachment" style="margin-top:12px;"><i class="fas fa-file"></i> <a href="/ida/<?php echo $submission['file_path']; ?>" target="_blank">View attachment</a></div>
                        <?php endif; ?>
                    </div>
                    <a href="assignments.php" class="btn btn-outline" style="margin-top:16px;">← Back</a>
                </div>
            </div>
        <?php endif; ?>

        <!-- ========== DASHBOARD: Pending & Completed ========== -->
        <?php if (!$assignment_id && !$view_submission): ?>
            <!-- Pending assignments -->
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-hourglass-half"></i> Pending Assignments</h3>
                    <span class="badge-count"><?php echo count($pending_assignments); ?></span>
                </div>
                <?php if (empty($pending_assignments)): ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle fa-2x"></i>
                        <p>All caught up! 🎉</p>
                    </div>
                    <?php else: foreach ($pending_assignments as $task):
                        $expired = strtotime($task['deadline']) < time();
                        $urgentClass = $expired ? 'deadline-expired' : ($task['urgency'] === 'urgent' ? 'deadline-urgent' : ($task['urgency'] === 'near' ? 'deadline-near' : ''));
                    ?>
                        <div class="assignment-item">
                            <div class="assignment-title">
                                <?php echo htmlspecialchars($task['title']); ?>
                                <?php if ($expired): ?>
                                    <span class="grade-badge" style="background:#fee2e2;">Expired</span>
                                <?php endif; ?>
                            </div>
                            <div class="assignment-meta">
                                <span><i class="fas fa-book"></i> <?php echo htmlspecialchars($task['subject_name']); ?></span>
                                <span><i class="fas fa-calendar"></i> <?php echo date('M d, g:i A', strtotime($task['deadline'])); ?></span>
                                <span class="<?php echo $urgentClass; ?>">
                                    <?php if (!$expired && $task['days_left'] > 0) echo $task['days_left'] . 'd left';
                                    elseif (!$expired) echo 'Due today'; ?>
                                </span>
                            </div>
                            <?php if ($task['file_path']): ?>
                                <div class="file-attachment"><i class="fas fa-paperclip"></i> <a href="/ida/<?php echo $task['file_path']; ?>" target="_blank">Attachment</a></div>
                            <?php endif; ?>
                            <?php if (!$expired && $task['submission_type'] != 'written'): ?>
                                <a href="assignments.php?id=<?php echo $task['id']; ?>" class="btn btn-primary btn-sm" style="margin-top:10px;"><i class="fas fa-arrow-right"></i> Submit</a>
                            <?php elseif ($task['submission_type'] == 'written' && !$expired): ?>
                                <span class="btn btn-outline btn-sm" style="margin-top:10px; opacity:0.7;"><i class="fas fa-pen-fancy"></i> Written only</span>
                            <?php elseif ($expired): ?>
                                <span class="btn btn-outline btn-sm" disabled style="opacity:0.5;">Closed</span>
                            <?php endif; ?>
                        </div>
                <?php endforeach;
                endif; ?>
            </div>

            <!-- Completed assignments -->
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-check-double"></i> Completed Assignments</h3>
                    <span class="badge-count"><?php echo count($completed_assignments); ?></span>
                </div>
                <?php if (empty($completed_assignments)): ?>
                    <div class="empty-state">
                        <i class="fas fa-folder-open"></i>
                        <p>No submissions yet</p>
                    </div>
                    <?php else: foreach ($completed_assignments as $comp): ?>
                        <div class="assignment-item">
                            <div class="assignment-title"><?php echo htmlspecialchars($comp['title']); ?></div>
                            <div class="assignment-meta">
                                <span><i class="fas fa-book"></i> <?php echo htmlspecialchars($comp['subject_name']); ?></span>
                                <span><i class="fas fa-calendar-check"></i> <?php echo date('M d', strtotime($comp['submitted_at'])); ?></span>
                                <?php if ($comp['status'] === 'graded'): ?>
                                    <span><i class="fas fa-star"></i> <?php echo $comp['grade']; ?>/<?php echo $comp['max_marks']; ?></span>
                                <?php endif; ?>
                                <span class="grade-badge grade-<?php echo $comp['status']; ?>"><?php echo ucfirst($comp['status']); ?></span>
                            </div>
                            <a href="assignments.php?submission=<?php echo $comp['id']; ?>" class="btn btn-outline btn-sm" style="margin-top:8px;"><i class="fas fa-eye"></i> Details</a>
                        </div>
                <?php endforeach;
                endif; ?>
            </div>
        <?php endif; ?>

        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> - Student Portal</p>
        </div>
    </div>

    <script>
        // Mobile sidebar toggle
        document.getElementById('mobileMenuBtn').onclick = () => document.getElementById('sidebar').classList.toggle('active');

        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768 &&
                !document.getElementById('sidebar').contains(e.target) &&
                !document.getElementById('mobileMenuBtn').contains(e.target)) {
                document.getElementById('sidebar').classList.remove('active');
            }
        });

        // Method toggle for both submission types
        const methodDivs = document.querySelectorAll('.type-option');
        const onlineBox = document.getElementById('onlineBox');
        const writtenBox = document.getElementById('writtenBox');
        const finalMethodInput = document.getElementById('finalMethod');
        const confirmWrittenBtn = document.getElementById('confirmWrittenBtn');

        function setMethod(method) {
            if (method === 'online') {
                if (onlineBox) onlineBox.style.display = 'block';
                if (writtenBox) writtenBox.style.display = 'none';
                if (finalMethodInput) finalMethodInput.value = 'online';
            } else {
                if (onlineBox) onlineBox.style.display = 'none';
                if (writtenBox) writtenBox.style.display = 'block';
                if (finalMethodInput) finalMethodInput.value = 'written';
            }
            if (methodDivs.length) {
                methodDivs.forEach(opt => {
                    const m = opt.getAttribute('data-method');
                    if (m === method) opt.classList.add('active');
                    else opt.classList.remove('active');
                });
            }
        }

        if (methodDivs.length) {
            methodDivs.forEach(opt => {
                opt.addEventListener('click', (e) => {
                    const method = opt.getAttribute('data-method');
                    setMethod(method);
                });
            });
        }

        if (confirmWrittenBtn) {
            confirmWrittenBtn.addEventListener('click', () => {
                if (confirm('Confirm written submission? You will submit physically in class.')) {
                    const form = document.querySelector('form');
                    if (form) {
                        const hiddenMethod = document.createElement('input');
                        hiddenMethod.type = 'hidden';
                        hiddenMethod.name = 'submission_method';
                        hiddenMethod.value = 'written';
                        form.appendChild(hiddenMethod);
                        form.submit();
                    }
                }
            });
        }

        // File size validation
        const fileInput = document.querySelector('input[type="file"]');
        if (fileInput) {
            fileInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file && file.size > 10 * 1024 * 1024) {
                    alert('File exceeds 10MB limit');
                    this.value = '';
                }
            });
        }
    </script>
</body>

</html>
<?php
// gos/student/assignments.php - Student Assignments
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
$student_class = $_SESSION['user_class'] ?? '';

$assignment_id = $_GET['id'] ?? 0;
$view_submission = $_GET['submission'] ?? 0;

// Handle assignment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_assignment'])) {
    $assignment_id = $_POST['assignment_id'];
    $submitted_text = trim($_POST['submitted_text']);

    // Handle file upload
    $file_path = null;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/assignments/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $file_name = time() . '_' . $_SESSION['user_id'] . '_' . basename($_FILES['attachment']['name']);
        $file_path = 'uploads/assignments/' . $file_name;
        move_uploaded_file($_FILES['attachment']['tmp_name'], '../' . $file_path);
    }

    $stmt = $pdo->prepare("
        INSERT INTO assignment_submissions (student_id, assignment_id, submitted_text, file_path, status, school_id, submitted_at)
        VALUES (?, ?, ?, ?, 'submitted', ?, NOW())
    ");
    $stmt->execute([$student_id, $assignment_id, $submitted_text, $file_path, $school_id]);

    $message = "Assignment submitted successfully!";
    $message_type = "success";
}

// Get specific assignment details
$assignment = null;
if ($assignment_id) {
    $stmt = $pdo->prepare("
        SELECT a.*, s.subject_name 
        FROM assignments a
        JOIN subjects s ON a.subject_id = s.id
        WHERE a.id = ? AND a.school_id = ? AND a.class = ?
    ");
    $stmt->execute([$assignment_id, $school_id, $student_class]);
    $assignment = $stmt->fetch();

    // Check if already submitted
    $stmt = $pdo->prepare("
        SELECT * FROM assignment_submissions 
        WHERE student_id = ? AND assignment_id = ?
    ");
    $stmt->execute([$student_id, $assignment_id]);
    $existing_submission = $stmt->fetch();
}

// Get submission details
$submission = null;
if ($view_submission) {
    $stmt = $pdo->prepare("
        SELECT asub.*, a.title, a.subject_id, s.subject_name, a.max_marks
        FROM assignment_submissions asub
        JOIN assignments a ON asub.assignment_id = a.id
        JOIN subjects s ON a.subject_id = s.id
        WHERE asub.id = ? AND asub.student_id = ?
    ");
    $stmt->execute([$view_submission, $student_id]);
    $submission = $stmt->fetch();
}

// Get pending assignments
$stmt = $pdo->prepare("
    SELECT a.*, s.subject_name,
           DATEDIFF(a.deadline, NOW()) as days_left
    FROM assignments a
    JOIN subjects s ON a.subject_id = s.id
    WHERE a.school_id = ? AND a.class = ? AND a.deadline >= CURDATE()
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
        }

        .assignment-item {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }

        .assignment-item:last-child {
            border-bottom: none;
        }

        .assignment-title {
            font-weight: 600;
            margin-bottom: 5px;
        }

        .assignment-meta {
            font-size: 0.75rem;
            color: #666;
            margin-bottom: 8px;
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
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-success {
            background: #27ae60;
            color: white;
        }

        .btn-warning {
            background: #f39c12;
            color: white;
        }

        .deadline-urgent {
            color: #e74c3c;
            font-weight: bold;
        }

        .deadline-near {
            color: #f39c12;
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
            font-size: 0.75rem;
            font-weight: 600;
        }

        .grade-graded {
            background: #d5f4e6;
            color: #27ae60;
        }

        .grade-pending {
            background: #fff3cd;
            color: #f39c12;
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
                gap: 15px;
            }

            .assignment-meta {
                flex-direction: column;
                gap: 5px;
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
            </div>
            <button class="btn" onclick="window.location.href='/gos/logout.php'"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>

        <?php if (isset($message)): ?>
            <div style="background:#d5f4e6; padding:15px; border-radius:10px; margin-bottom:20px;"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- View Assignment Details -->
        <?php if ($assignment && !$existing_submission && !$view_submission): ?>
            <div class="card">
                <div class="card-header">
                    <h3><?php echo htmlspecialchars($assignment['title']); ?></h3>
                </div>
                <div class="assignment-meta" style="margin-bottom: 15px;">
                    <span><i class="fas fa-book"></i> <?php echo htmlspecialchars($assignment['subject_name']); ?></span>
                    <span><i class="fas fa-calendar"></i> Due: <?php echo date('F j, Y g:i A', strtotime($assignment['deadline'])); ?></span>
                    <span><i class="fas fa-star"></i> Max Marks: <?php echo $assignment['max_marks']; ?></span>
                </div>
                <div style="margin-bottom: 20px;">
                    <strong>Instructions:</strong>
                    <p style="margin-top: 8px; line-height: 1.6;"><?php echo nl2br(htmlspecialchars($assignment['instructions'])); ?></p>
                </div>
                <?php if ($assignment['file_path']): ?>
                    <div><a href="/<?php echo $assignment['file_path']; ?>" target="_blank" class="btn btn-primary"><i class="fas fa-download"></i> Download Assignment File</a></div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" style="margin-top: 20px;">
                    <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                    <div class="form-group" style="margin-bottom: 15px;">
                        <label style="display:block; margin-bottom: 8px; font-weight:500;">Your Submission</label>
                        <textarea name="submitted_text" class="form-control" rows="6" style="width:100%; padding:12px; border:2px solid #e0e0e0; border-radius:8px;"></textarea>
                    </div>
                    <div class="form-group" style="margin-bottom: 15px;">
                        <label style="display:block; margin-bottom: 8px; font-weight:500;">Attachment (Optional)</label>
                        <input type="file" name="attachment" class="form-control" style="padding:10px;">
                    </div>
                    <button type="submit" name="submit_assignment" class="btn btn-success"><i class="fas fa-paper-plane"></i> Submit Assignment</button>
                    <a href="assignments.php" class="btn btn-warning">Cancel</a>
                </form>
            </div>
        <?php endif; ?>

        <!-- View Submission Details -->
        <?php if ($submission): ?>
            <div class="card">
                <div class="card-header">
                    <h3>Submission: <?php echo htmlspecialchars($submission['title']); ?></h3>
                </div>
                <div class="submission-detail">
                    <p><strong>Submitted on:</strong> <?php echo date('F j, Y g:i A', strtotime($submission['submitted_at'])); ?></p>
                    <p><strong>Status:</strong> <span class="grade-badge grade-<?php echo $submission['status']; ?>"><?php echo ucfirst($submission['status']); ?></span></p>
                    <?php if ($submission['status'] === 'graded'): ?>
                        <p><strong>Grade:</strong> <?php echo $submission['grade']; ?> / <?php echo $submission['max_marks']; ?></p>
                        <p><strong>Teacher's Feedback:</strong> <?php echo nl2br(htmlspecialchars($submission['teacher_feedback'])); ?></p>
                    <?php endif; ?>
                    <p><strong>Your Submission:</strong></p>
                    <p style="background:white; padding:12px; border-radius:8px; margin-top:5px;"><?php echo nl2br(htmlspecialchars($submission['submitted_text'])); ?></p>
                    <?php if ($submission['file_path']): ?>
                        <p><strong>Attachment:</strong> <a href="/<?php echo $submission['file_path']; ?>" target="_blank"><i class="fas fa-file"></i> View File</a></p>
                    <?php endif; ?>
                </div>
                <a href="assignments.php" class="btn btn-primary" style="margin-top: 15px;"><i class="fas fa-arrow-left"></i> Back to Assignments</a>
            </div>
        <?php endif; ?>

        <!-- Pending Assignments -->
        <?php if (!$assignment_id && !$view_submission): ?>
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-hourglass-half"></i> Pending Assignments</h3>
                </div>
                <?php if (empty($pending_assignments)): ?>
                    <p style="text-align:center; padding:30px; color:#999;">No pending assignments. Great job!</p>
                <?php else: ?>
                    <?php foreach ($pending_assignments as $assignment): ?>
                        <div class="assignment-item">
                            <div class="assignment-title"><?php echo htmlspecialchars($assignment['title']); ?></div>
                            <div class="assignment-meta">
                                <span><i class="fas fa-book"></i> <?php echo htmlspecialchars($assignment['subject_name']); ?></span>
                                <span><i class="fas fa-calendar"></i> Due: <?php echo date('M d, Y g:i A', strtotime($assignment['deadline'])); ?></span>
                                <span class="<?php echo $assignment['days_left'] <= 1 ? 'deadline-urgent' : ($assignment['days_left'] <= 3 ? 'deadline-near' : ''); ?>">
                                    <i class="fas fa-clock"></i> <?php echo $assignment['days_left']; ?> days left
                                </span>
                            </div>
                            <a href="assignments.php?id=<?php echo $assignment['id']; ?>" class="btn btn-primary" style="margin-top: 8px;"><i class="fas fa-arrow-right"></i> View Assignment</a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Completed Assignments -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-check-circle"></i> Completed Assignments</h3>
                </div>
                <?php if (empty($completed_assignments)): ?>
                    <p style="text-align:center; padding:30px; color:#999;">No completed assignments yet.</p>
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
                            <a href="assignments.php?submission=<?php echo $assignment['id']; ?>" class="btn btn-primary" style="margin-top: 8px;"><i class="fas fa-eye"></i> View Submission</a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <style>
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
        }
    </style>

    <script>
        document.getElementById('mobileMenuBtn').onclick = () => document.getElementById('sidebar').classList.toggle('active');
    </script>
</body>

</html>
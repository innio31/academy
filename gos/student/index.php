<?php
// gos/student/index.php - Student Dashboard
session_start();

// Check if student is logged in
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

// Get student details
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND school_id = ?");
$stmt->execute([$student_id, $school_id]);
$student = $stmt->fetch();

// Get profile picture path
$profile_picture = !empty($student['profile_picture']) ? $student['profile_picture'] : '/assets/uploads/default-avatar.png';
// If the path doesn't start with /, add the base path
if (!empty($student['profile_picture']) && strpos($student['profile_picture'], '/') !== 0) {
    $profile_picture = '/uploads/' . $student['profile_picture'];
}

// Get available exams for this student's class
$stmt = $pdo->prepare("
    SELECT e.*, s.subject_name 
    FROM exams e
    JOIN subjects s ON e.subject_id = s.id
    WHERE e.school_id = ? AND e.class = ? AND e.is_active = 1
    AND e.id NOT IN (
        SELECT exam_id FROM exam_sessions 
        WHERE student_id = ? AND status = 'completed'
    )
    ORDER BY e.created_at DESC
");
$stmt->execute([$school_id, $student_class, $student_id]);
$available_exams = $stmt->fetchAll();

// Get in-progress exams
$stmt = $pdo->prepare("
    SELECT es.*, e.exam_name, s.subject_name, e.duration_minutes,
           TIMESTAMPDIFF(SECOND, NOW(), es.end_time) as time_remaining
    FROM exam_sessions es 
    JOIN exams e ON es.exam_id = e.id 
    JOIN subjects s ON e.subject_id = s.id
    WHERE es.student_id = ? AND es.status = 'in_progress' AND es.end_time > NOW()
    ORDER BY es.start_time DESC
");
$stmt->execute([$student_id]);
$in_progress_exams = $stmt->fetchAll();

// Get completed exams with results
$stmt = $pdo->prepare("
    SELECT es.*, e.exam_name, s.subject_name,
           r.percentage, r.grade, r.total_score
    FROM exam_sessions es 
    JOIN exams e ON es.exam_id = e.id 
    JOIN subjects s ON e.subject_id = s.id
    LEFT JOIN results r ON es.exam_id = r.exam_id AND es.student_id = r.student_id
    WHERE es.student_id = ? AND es.status = 'completed'
    ORDER BY es.end_time DESC
    LIMIT 5
");
$stmt->execute([$student_id]);
$completed_exams = $stmt->fetchAll();

// Get pending assignments
$stmt = $pdo->prepare("
    SELECT a.*, s.subject_name 
    FROM assignments a
    JOIN subjects s ON a.subject_id = s.id
    WHERE a.school_id = ? AND a.class = ? AND a.deadline >= CURDATE()
    AND a.id NOT IN (
        SELECT assignment_id FROM assignment_submissions 
        WHERE student_id = ?
    )
    ORDER BY a.deadline ASC
    LIMIT 5
");
$stmt->execute([$school_id, $student_class, $student_id]);
$pending_assignments = $stmt->fetchAll();

// Get statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT es.exam_id) as total_exams_taken,
        AVG(r.percentage) as average_score,
        COUNT(DISTINCT asub.assignment_id) as assignments_submitted
    FROM exam_sessions es 
    LEFT JOIN results r ON es.exam_id = r.exam_id AND es.student_id = r.student_id
    LEFT JOIN assignment_submissions asub ON es.student_id = asub.student_id
    WHERE es.student_id = ? AND es.status = 'completed'
");
$stmt->execute([$student_id]);
$stats = $stmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Student Dashboard</title>
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
            min-height: 100vh;
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
            overflow-y: auto;
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

        /* Student profile picture in sidebar */
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

        /* Profile picture in welcome banner */
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .stat-label {
            color: #666;
            font-size: 0.8rem;
        }

        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .content-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
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
        }

        .exam-item,
        .assignment-item {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }

        .exam-item:last-child,
        .assignment-item:last-child {
            border-bottom: none;
        }

        .exam-title,
        .assignment-title {
            font-weight: 600;
            margin-bottom: 5px;
        }

        .exam-meta,
        .assignment-meta {
            font-size: 0.75rem;
            color: #666;
        }

        .btn {
            padding: 6px 12px;
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

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .action-btn {
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            text-decoration: none;
            color: var(--primary-color);
            transition: all 0.3s ease;
        }

        .action-btn:hover {
            transform: translateY(-3px);
            border-color: var(--primary-color);
        }

        .action-icon {
            font-size: 24px;
            margin-bottom: 8px;
            display: block;
        }

        .action-text {
            font-size: 0.75rem;
            font-weight: 500;
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
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .content-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions {
                grid-template-columns: repeat(2, 1fr);
            }

            .welcome-banner {
                flex-direction: column;
                text-align: center;
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
            <!-- Student Profile Picture in Sidebar -->
            <img src="<?php echo htmlspecialchars($profile_picture); ?>"
                alt="Profile Picture"
                class="student-avatar"
                onerror="this.src='/assets/uploads/default-avatar.png'">
            <div class="student-name"><?php echo htmlspecialchars($student_name); ?></div>
            <div class="student-details"><?php echo htmlspecialchars($student['admission_number']); ?></div>
            <div class="student-details"><?php echo htmlspecialchars($student_class); ?></div>
        </div>
        <ul class="nav-links">
            <li><a href="index.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="take-exam.php"><i class="fas fa-file-alt"></i> Take Exam</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> My Results</a></li>
            <li><a href="assignments.php"><i class="fas fa-tasks"></i> Assignments</a></li>
            <li><a href="library.php"><i class="fas fa-book"></i> E-Library</a></li>
            <li><a href="profile.php"><i class="fas fa-user-cog"></i> My Profile</a></li>
            <li><a href="/gos/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="welcome-banner">
                <!-- Student Profile Picture in Welcome Banner -->
                <img src="<?php echo htmlspecialchars($profile_picture); ?>"
                    alt="Profile Picture"
                    class="welcome-avatar"
                    onerror="this.src='/assets/uploads/default-avatar.png'">
                <div class="welcome-text">
                    <h1>Welcome, <?php echo htmlspecialchars($student_name); ?>!</h1>
                    <p><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($student_class); ?> | <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($student['admission_number']); ?></p>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_exams_taken'] ?? 0; ?></div>
                <div class="stat-label">Exams Taken</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo round($stats['average_score'] ?? 0, 1); ?>%</div>
                <div class="stat-label">Average Score</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['assignments_submitted'] ?? 0; ?></div>
                <div class="stat-label">Assignments Done</div>
            </div>
        </div>

        <!-- In-Progress Exams -->
        <?php if (!empty($in_progress_exams)): ?>
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-hourglass-half"></i> Continue Your Exams</h3>
                </div>
                <?php foreach ($in_progress_exams as $exam): ?>
                    <div class="exam-item">
                        <div class="exam-title"><?php echo htmlspecialchars($exam['exam_name']); ?> (<?php echo htmlspecialchars($exam['subject_name']); ?>)</div>
                        <div class="exam-meta">
                            Time Left: <?php echo gmdate("H:i:s", max(0, $exam['time_remaining'])); ?> |
                            Started: <?php echo date('g:i A', strtotime($exam['start_time'])); ?>
                        </div>
                        <a href="take-exam.php?resume=<?php echo $exam['id']; ?>" class="btn btn-warning" style="margin-top: 8px;"><i class="fas fa-play"></i> Continue</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Available Exams -->
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-file-alt"></i> Available Exams</h3><a href="take-exam.php" class="btn btn-primary">View All</a>
            </div>
            <?php if (empty($available_exams)): ?>
                <p style="text-align:center; padding:20px; color:#999;">No exams available at the moment.</p>
            <?php else: ?>
                <?php foreach ($available_exams as $exam): ?>
                    <div class="exam-item">
                        <div class="exam-title"><?php echo htmlspecialchars($exam['exam_name']); ?></div>
                        <div class="exam-meta"><?php echo htmlspecialchars($exam['subject_name']); ?> | Duration: <?php echo $exam['duration_minutes']; ?> mins</div>
                        <a href="take-exam.php?exam_id=<?php echo $exam['id']; ?>" class="btn btn-primary" style="margin-top: 8px;"><i class="fas fa-play"></i> Start Exam</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Pending Assignments -->
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-tasks"></i> Pending Assignments</h3><a href="assignments.php" class="btn btn-primary">View All</a>
            </div>
            <?php if (empty($pending_assignments)): ?>
                <p style="text-align:center; padding:20px; color:#999;">No pending assignments. Great job!</p>
            <?php else: ?>
                <?php foreach ($pending_assignments as $assignment): ?>
                    <div class="assignment-item">
                        <div class="assignment-title"><?php echo htmlspecialchars($assignment['title']); ?></div>
                        <div class="assignment-meta"><?php echo htmlspecialchars($assignment['subject_name']); ?> | Due: <?php echo date('M d, Y', strtotime($assignment['deadline'])); ?></div>
                        <a href="assignments.php?id=<?php echo $assignment['id']; ?>" class="btn btn-primary" style="margin-top: 8px;"><i class="fas fa-arrow-right"></i> View</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Recent Results -->
        <?php if (!empty($completed_exams)): ?>
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-line"></i> Recent Results</h3><a href="view-results.php" class="btn btn-primary">View All</a>
                </div>
                <?php foreach ($completed_exams as $exam): ?>
                    <div class="exam-item">
                        <div class="exam-title"><?php echo htmlspecialchars($exam['exam_name']); ?></div>
                        <div class="exam-meta"><?php echo htmlspecialchars($exam['subject_name']); ?> | Score: <?php echo $exam['percentage'] ?? 0; ?>% | Grade: <?php echo $exam['grade'] ?? 'N/A'; ?></div>
                        <a href="view-results.php?exam_id=<?php echo $exam['exam_id']; ?>" class="btn btn-primary" style="margin-top: 8px;"><i class="fas fa-eye"></i> View Details</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
            </div>
            <div class="quick-actions">
                <a href="take-exam.php" class="action-btn"><span class="action-icon"><i class="fas fa-play-circle"></i></span><span class="action-text">Take Exam</span></a>
                <a href="view-results.php" class="action-btn"><span class="action-icon"><i class="fas fa-chart-line"></i></span><span class="action-text">My Results</span></a>
                <a href="assignments.php" class="action-btn"><span class="action-icon"><i class="fas fa-tasks"></i></span><span class="action-text">Assignments</span></a>
                <a href="library.php" class="action-btn"><span class="action-icon"><i class="fas fa-book"></i></span><span class="action-text">E-Library</span></a>
            </div>
        </div>

        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> - Student Portal</p>
        </div>
    </div>

    <script>
        document.getElementById('mobileMenuBtn').onclick = () => document.getElementById('sidebar').classList.toggle('active');
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768 && !document.getElementById('sidebar').contains(e.target) && !document.getElementById('mobileMenuBtn').contains(e.target)) {
                document.getElementById('sidebar').classList.remove('active');
            }
        });
    </script>
</body>

</html>
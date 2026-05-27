<?php
// msv/student/index.php - Student Dashboard (All-in-One with Sidebar)
session_start();

// Include your working config file
require_once '../includes/config.php';

// Check if student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: /msv/login.php");
    exit();
}

// Now use the existing $pdo connection from config.php
$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;
$accent_color = SCHOOL_ACCENT;
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'] ?? 'Student';

// Get student details (including class from database)
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND school_id = ?");
$stmt->execute([$student_id, $school_id]);
$student = $stmt->fetch();

// Set class from database
$student_class = $student['class'] ?? '';
$admission_number = $student['admission_number'] ?? '';

// Get profile picture path
$profile_picture = !empty($student['profile_picture']) ? $student['profile_picture'] : '/assets/uploads/default-avatar.png';
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
           es.percentage, es.grade, es.score as total_score
    FROM exam_sessions es 
    JOIN exams e ON es.exam_id = e.id 
    JOIN subjects s ON e.subject_id = s.id
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
        AVG(es.percentage) as average_score,
        (SELECT COUNT(*) FROM assignment_submissions WHERE student_id = ?) as assignments_submitted
    FROM exam_sessions es 
    WHERE es.student_id = ? AND es.status = 'completed'
");
$stmt->execute([$student_id, $student_id]);
$stats = $stmt->fetch();

if (!$stats) {
    $stats = ['total_exams_taken' => 0, 'average_score' => 0, 'assignments_submitted' => 0];
}

// Helper function for color manipulation
function hexToRgb($hex) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return "$r,$g,$b";
}

function adjustBrightness($hex, $percent) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $factor = 1 + ($percent / 100);
    $r = (int)min(255, max(0, $r * $factor));
    $g = (int)min(255, max(0, $g * $factor));
    $b = (int)min(255, max(0, $b * $factor));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

// Sidebar styling
$sb_bg = adjustBrightness($primary_color, -12);
$sb_hover_bg = "rgba(" . hexToRgb($accent_color) . ", 0.08)";
$sb_active_bg = "rgba(" . hexToRgb($secondary_color) . ", 0.18)";
$logo_gradient = "linear-gradient(135deg, $secondary_color, $primary_color)";
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Student Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background: #f5f6fa; }
        
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: <?php echo $secondary_color; ?>;
            --sidebar-bg: <?php echo $sb_bg; ?>;
            --sidebar-width: 280px;
        }
        
        /* Sidebar Styles */
        .student-sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--sidebar-bg);
            position: fixed;
            top: 0;
            left: 0;
            overflow-y: auto;
            z-index: 1000;
            transition: transform 0.3s ease;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 999;
        }
        .sidebar-overlay.active { display: block; }
        
        .sidebar-header { padding: 25px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .logo { display: flex; align-items: center; gap: 12px; }
        .logo-icon {
            width: 45px; height: 45px; border-radius: 12px;
            background: <?php echo $logo_gradient; ?>;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
        }
        .logo-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .logo-icon i { color: white; font-size: 1.3rem; }
        .logo-text .school-name { font-size: 0.9rem; font-weight: 700; color: white; }
        .logo-text p { font-size: 0.7rem; color: rgba(255,255,255,0.7); }
        
        .student-info { text-align: center; padding: 25px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .student-avatar {
            width: 80px; height: 80px; border-radius: 50%;
            object-fit: cover; border: 3px solid var(--secondary-color);
            margin: 0 auto 12px;
        }
        .student-name { font-size: 1rem; font-weight: 600; color: white; margin-bottom: 5px; }
        .student-details { font-size: 0.75rem; color: rgba(255,255,255,0.7); margin: 3px 0; }
        .student-details i { width: 18px; font-size: 0.7rem; }
        
        .sidebar-nav { padding: 15px 10px; }
        .nav-item {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 15px; border-radius: 10px;
            color: rgba(255,255,255,0.85); text-decoration: none;
            font-size: 0.85rem; font-weight: 500;
            transition: all 0.2s;
        }
        .nav-item:hover { background: <?php echo $sb_hover_bg; ?>; color: white; }
        .nav-item.active { background: <?php echo $sb_active_bg; ?>; color: <?php echo $secondary_color; ?>; }
        .nav-item i { width: 22px; font-size: 1rem; }
        .nav-item.logout { margin-top: 20px; border-top: 1px solid rgba(255,255,255,0.1); border-radius: 0; padding-top: 20px; }
        
        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px 25px;
            min-height: 100vh;
        }
        
        .mobile-menu-btn {
            position: fixed; top: 20px; right: 20px; z-index: 1001;
            background: var(--primary-color); color: white;
            border: none; width: 45px; height: 45px;
            border-radius: 12px; font-size: 20px;
            display: none; cursor: pointer;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        /* Header Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-color), #1a1a6e);
            border-radius: 20px;
            padding: 25px 30px;
            margin-bottom: 30px;
            color: white;
            display: flex;
            align-items: center;
            gap: 25px;
            flex-wrap: wrap;
        }
        .welcome-avatar {
            width: 80px; height: 80px; border-radius: 50%;
            object-fit: cover; border: 3px solid var(--secondary-color);
        }
        .welcome-text h1 { font-size: 1.6rem; font-weight: 600; margin-bottom: 8px; }
        .welcome-text p { opacity: 0.9; font-size: 0.9rem; }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 25px 20px;
            border-radius: 16px;
            text-align: center;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-value { font-size: 2.2rem; font-weight: 700; color: var(--primary-color); }
        .stat-label { color: #666; font-size: 0.85rem; margin-top: 5px; }
        
        /* Content Cards */
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        .content-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f0f0f0;
        }
        .card-header h3 { color: var(--primary-color); font-size: 1.1rem; font-weight: 600; }
        .card-header h3 i { margin-right: 8px; }
        
        .exam-item, .assignment-item {
            padding: 14px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .exam-item:last-child, .assignment-item:last-child { border-bottom: none; }
        .exam-title, .assignment-title { font-weight: 600; margin-bottom: 6px; }
        .exam-meta, .assignment-meta { font-size: 0.75rem; color: #888; }
        
        .btn {
            padding: 6px 14px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.75rem;
            font-weight: 500;
            transition: all 0.2s;
        }
        .btn-primary { background: var(--primary-color); color: white; }
        .btn-primary:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn-warning { background: #f39c12; color: white; }
        .btn-outline { background: transparent; border: 1px solid var(--primary-color); color: var(--primary-color); }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-top: 10px;
        }
        .action-btn {
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            padding: 15px 10px;
            text-align: center;
            text-decoration: none;
            color: var(--primary-color);
            transition: all 0.2s;
        }
        .action-btn:hover { transform: translateY(-3px); border-color: var(--primary-color); background: white; }
        .action-icon { font-size: 24px; margin-bottom: 8px; display: block; }
        .action-text { font-size: 0.75rem; font-weight: 500; }
        
        .footer {
            text-align: center;
            padding: 25px;
            color: #888;
            font-size: 0.75rem;
            border-top: 1px solid #e0e0e0;
            margin-top: 20px;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .student-sidebar { transform: translateX(-100%); }
            .student-sidebar.active { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 15px; }
            .mobile-menu-btn { display: block; }
            .stats-grid { grid-template-columns: 1fr; }
            .content-grid { grid-template-columns: 1fr; }
            .welcome-banner { flex-direction: column; text-align: center; padding: 20px; }
        }
        
        @media (min-width: 769px) {
            .student-sidebar { transform: translateX(0); }
        }
        
        .no-data { text-align: center; padding: 30px; color: #999; font-size: 0.85rem; }
    </style>
</head>
<body>

<button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ============================================ -->
<!-- SIDEBAR (Integrated - Using your EXACT working logo logic) -->
<!-- ============================================ -->
<div class="student-sidebar" id="studentSidebar">
    <div class="sidebar-header">
        <div class="logo">
            <div class="logo-icon">
                <?php
                $logo_path = null;
                $logo_locations = [
                    '/msv/assets/logos/logo.png',
                    '/assets/logos/logo.png',
                    '../assets/logos/logo.png',
                    'assets/logos/logo.png'
                ];
                if (defined('SCHOOL_LOGO') && SCHOOL_LOGO && file_exists($_SERVER['DOCUMENT_ROOT'] . SCHOOL_LOGO)) {
                    $logo_path = SCHOOL_LOGO;
                } else {
                    foreach ($logo_locations as $location) {
                        if (file_exists($_SERVER['DOCUMENT_ROOT'] . $location)) {
                            $logo_path = $location;
                            break;
                        }
                    }
                }
                if ($logo_path && file_exists($_SERVER['DOCUMENT_ROOT'] . $logo_path)): ?>
                    <img src="<?php echo $logo_path; ?>" alt="<?php echo htmlspecialchars($school_name); ?>">
                <?php else: ?>
                    <i class="fas fa-graduation-cap"></i>
                <?php endif; ?>
            </div>
            <div class="logo-text">
                <h3 class="school-name"><?php echo htmlspecialchars($school_name); ?></h3>
                <p>Student Portal</p>
            </div>
        </div>
    </div>
    
    <div class="student-info">
        <img src="<?php echo htmlspecialchars($profile_picture); ?>" 
             class="student-avatar" 
             onerror="this.src='https://ui-avatars.com/api/?background=<?php echo ltrim($primary_color, '#'); ?>&color=fff&name=<?php echo urlencode($student_name); ?>'">
        <div class="student-name"><?php echo htmlspecialchars($student_name); ?></div>
        <div class="student-details"><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($admission_number ?: 'N/A'); ?></div>
        <div class="student-details"><i class="fas fa-graduation-cap"></i> Class: <?php echo htmlspecialchars($student_class ?: 'Not Assigned'); ?></div>
    </div>
    
    <nav class="sidebar-nav">
        <a href="index.php" class="nav-item <?php echo ($current_page == 'index.php') ? 'active' : ''; ?>">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>
        <a href="take-exam.php" class="nav-item">
            <i class="fas fa-file-alt"></i> Take Exam
        </a>
        <a href="assignments.php" class="nav-item">
            <i class="fas fa-tasks"></i> Assignments
        </a>
        <a href="view-results.php" class="nav-item">
            <i class="fas fa-chart-bar"></i> Results
        </a>
        <a href="report-card.php" class="nav-item">
            <i class="fas fa-id-card"></i> Report Card
        </a>
        <a href="library.php" class="nav-item">
            <i class="fas fa-book"></i> E-Library
        </a>
        <a href="profile.php" class="nav-item">
            <i class="fas fa-user-circle"></i> My Profile
        </a>
        <a href="/msv/logout.php" class="nav-item logout">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </nav>
</div>

<!-- ============================================ -->
<!-- MAIN CONTENT -->
<!-- ============================================ -->
<div class="main-content">
    
    <!-- Welcome Banner -->
    <div class="welcome-banner">
        <img src="<?php echo htmlspecialchars($profile_picture); ?>" 
             class="welcome-avatar" 
             onerror="this.src='https://ui-avatars.com/api/?background=<?php echo ltrim($primary_color, '#'); ?>&color=fff&name=<?php echo urlencode($student_name); ?>'">
        <div class="welcome-text">
            <h1>Welcome back, <?php echo htmlspecialchars($student_name); ?>! 👋</h1>
            <p><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($student_class ?: 'Class not assigned'); ?> | 
               <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($admission_number ?: 'Admission number not set'); ?></p>
        </div>
    </div>
    
    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($stats['total_exams_taken'] ?? 0); ?></div>
            <div class="stat-label">Exams Taken</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo round($stats['average_score'] ?? 0, 1); ?>%</div>
            <div class="stat-label">Average Score</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($stats['assignments_submitted'] ?? 0); ?></div>
            <div class="stat-label">Assignments Completed</div>
        </div>
    </div>
    
    <!-- In Progress Exams -->
    <?php if (!empty($in_progress_exams)): ?>
    <div class="content-card" style="margin-bottom: 25px;">
        <div class="card-header">
            <h3><i class="fas fa-hourglass-half"></i> Continue Your Exams</h3>
        </div>
        <?php foreach ($in_progress_exams as $exam): ?>
            <div class="exam-item">
                <div class="exam-title"><?php echo htmlspecialchars($exam['exam_name']); ?></div>
                <div class="exam-meta">
                    <i class="far fa-clock"></i> Time Left: <?php echo gmdate("H:i:s", max(0, $exam['time_remaining'])); ?>
                </div>
                <a href="take-exam.php?resume=<?php echo $exam['id']; ?>" class="btn btn-warning" style="margin-top: 8px;">
                    <i class="fas fa-play"></i> Continue
                </a>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <!-- Two Column Layout -->
    <div class="content-grid">
        <!-- Available Exams -->
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-file-alt"></i> Available Exams</h3>
                <a href="take-exam.php" class="btn btn-outline">View All</a>
            </div>
            <?php if (empty($available_exams)): ?>
                <div class="no-data">
                    <i class="fas fa-inbox" style="font-size: 32px; margin-bottom: 10px; display: block; opacity: 0.5;"></i>
                    No exams available at the moment.
                </div>
            <?php else: ?>
                <?php foreach ($available_exams as $exam): ?>
                    <div class="exam-item">
                        <div class="exam-title"><?php echo htmlspecialchars($exam['exam_name']); ?></div>
                        <div class="exam-meta">
                            <?php echo htmlspecialchars($exam['subject_name']); ?> | 
                            Duration: <?php echo $exam['duration_minutes']; ?> mins
                        </div>
                        <a href="take-exam.php?exam_id=<?php echo $exam['id']; ?>" class="btn btn-primary" style="margin-top: 8px;">
                            <i class="fas fa-play"></i> Start Exam
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Pending Assignments -->
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-tasks"></i> Pending Assignments</h3>
                <a href="assignments.php" class="btn btn-outline">View All</a>
            </div>
            <?php if (empty($pending_assignments)): ?>
                <div class="no-data">
                    <i class="fas fa-check-circle" style="font-size: 32px; margin-bottom: 10px; display: block; color: #27ae60;"></i>
                    No pending assignments. Great job!
                </div>
            <?php else: ?>
                <?php foreach ($pending_assignments as $assignment): ?>
                    <div class="assignment-item">
                        <div class="assignment-title"><?php echo htmlspecialchars($assignment['title']); ?></div>
                        <div class="assignment-meta">
                            <?php echo htmlspecialchars($assignment['subject_name']); ?> | 
                            Due: <?php echo date('M d, Y', strtotime($assignment['deadline'])); ?>
                        </div>
                        <a href="assignments.php?id=<?php echo $assignment['id']; ?>" class="btn btn-primary" style="margin-top: 8px;">
                            <i class="fas fa-arrow-right"></i> View Details
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Second Row -->
    <div class="content-grid">
        <!-- Recent Results -->
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-chart-line"></i> Recent Results</h3>
                <a href="view-results.php" class="btn btn-outline">View All</a>
            </div>
            <?php if (empty($completed_exams)): ?>
                <div class="no-data">
                    <i class="fas fa-chart-simple" style="font-size: 32px; margin-bottom: 10px; display: block; opacity: 0.5;"></i>
                    No results available yet.
                </div>
            <?php else: ?>
                <?php foreach ($completed_exams as $exam): ?>
                    <div class="exam-item">
                        <div class="exam-title"><?php echo htmlspecialchars($exam['exam_name']); ?></div>
                        <div class="exam-meta">
                            <?php echo htmlspecialchars($exam['subject_name']); ?> | 
                            Score: <?php echo round($exam['percentage'] ?? 0, 1); ?>% | 
                            Grade: <?php echo htmlspecialchars($exam['grade'] ?? 'N/A'); ?>
                        </div>
                        <a href="view-results.php?exam_id=<?php echo $exam['exam_id'] ?? $exam['id']; ?>" class="btn btn-primary" style="margin-top: 8px;">
                            <i class="fas fa-eye"></i> View Details
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Quick Actions -->
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
            </div>
            <div class="quick-actions">
                <a href="take-exam.php" class="action-btn">
                    <span class="action-icon"><i class="fas fa-play-circle"></i></span>
                    <span class="action-text">Take Exam</span>
                </a>
                <a href="view-results.php" class="action-btn">
                    <span class="action-icon"><i class="fas fa-chart-line"></i></span>
                    <span class="action-text">My Results</span>
                </a>
                <a href="assignments.php" class="action-btn">
                    <span class="action-icon"><i class="fas fa-tasks"></i></span>
                    <span class="action-text">Assignments</span>
                </a>
                <a href="library.php" class="action-btn">
                    <span class="action-icon"><i class="fas fa-book"></i></span>
                    <span class="action-text">E-Library</span>
                </a>
                <a href="report-card.php" class="action-btn">
                    <span class="action-icon"><i class="fas fa-id-card"></i></span>
                    <span class="action-text">Report Card</span>
                </a>
                <a href="profile.php" class="action-btn">
                    <span class="action-icon"><i class="fas fa-user-edit"></i></span>
                    <span class="action-text">My Profile</span>
                </a>
            </div>
        </div>
    </div>
    
    <div class="footer">
        <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> - Student Portal. All rights reserved.</p>
    </div>
</div>

<script>
    // Mobile sidebar toggle
    (function() {
        const menuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('studentSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        
        if (menuBtn && sidebar) {
            menuBtn.addEventListener('click', function(e) {
                e.preventDefault();
                sidebar.classList.toggle('active');
                if (overlay) overlay.classList.toggle('active');
                document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
            });
        }
        
        if (overlay) {
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            });
        }
        
        // Close sidebar on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                sidebar.classList.remove('active');
                if (overlay) overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    })();
</script>

</body>
</html>
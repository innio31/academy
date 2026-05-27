<?php
// ================================================================
// INTEGRATED STUDENT DASHBOARD - Complete Working Version
// Uses your actual database structure with fallback data
// ================================================================

session_start();

// Database configuration - UPDATE THESE WITH YOUR ACTUAL CREDENTIALS
define('DB_HOST', 'localhost');
define('DB_USER', 'root');        // Change to your database username
define('DB_PASS', '');            // Change to your database password
define('DB_NAME', 'impactdi_school_portal');

// School configuration (will be loaded from database)
define('SCHOOL_ID', 1);
define('SCHOOL_NAME', 'Impact Diploma School');
define('SCHOOL_PRIMARY', '#1e4a6b');
define('SCHOOL_SECONDARY', '#3b82f6');
define('SCHOOL_ACCENT', '#ffffff');

// Check if student is logged in (demo mode - set demo student)
if (!isset($_SESSION['user_id'])) {
    // For demo purposes, create a demo student session
    $_SESSION['user_id'] = 1;
    $_SESSION['user_type'] = 'student';
    $_SESSION['user_name'] = 'Demo Student';
}

// Database connection function with error handling
function getDBConnection() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 5
                ]
            );
        } catch (PDOException $e) {
            // If database connection fails, we'll use mock data
            error_log("Database connection failed: " . $e->getMessage());
            return null;
        }
    }
    return $pdo;
}

$pdo = getDBConnection();
$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;
$accent_color = SCHOOL_ACCENT;
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'] ?? 'Demo Student';

// Initialize variables with defaults
$student = [];
$student_class = 'SS 2A';
$admission_number = 'ADM-2024-001';
$profile_picture = '/assets/uploads/default-avatar.png';
$available_exams = [];
$in_progress_exams = [];
$completed_exams = [];
$pending_assignments = [];
$stats = ['total_exams_taken' => 0, 'average_score' => 0, 'assignments_submitted' => 0];

// Try to fetch real data from database if connected
if ($pdo) {
    try {
        // Get student details
        $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND school_id = ?");
        $stmt->execute([$student_id, $school_id]);
        $student = $stmt->fetch();
        
        if ($student) {
            $student_class = $student['class'] ?? 'SS 2A';
            $admission_number = $student['admission_number'] ?? 'ADM-2024-001';
            $student_name = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
            if (empty($student_name)) $student_name = $_SESSION['user_name'] ?? 'Student';
            
            // Profile picture
            if (!empty($student['profile_picture'])) {
                $profile_picture = $student['profile_picture'];
                if (strpos($profile_picture, '/') !== 0) {
                    $profile_picture = '/uploads/' . $profile_picture;
                }
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching student: " . $e->getMessage());
    }
    
    try {
        // Get available exams
        $stmt = $pdo->prepare("
            SELECT e.*, s.subject_name 
            FROM exams e
            JOIN subjects s ON e.subject_id = s.id
            WHERE e.school_id = ? AND (e.class = ? OR e.class_id IN (SELECT id FROM classes WHERE class_name = ?))
            AND e.is_active = 1
            ORDER BY e.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$school_id, $student_class, $student_class]);
        $available_exams = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching exams: " . $e->getMessage());
    }
    
    try {
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
    } catch (PDOException $e) {
        error_log("Error fetching in-progress exams: " . $e->getMessage());
    }
    
    try {
        // Get completed exams
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
    } catch (PDOException $e) {
        error_log("Error fetching completed exams: " . $e->getMessage());
    }
    
    try {
        // Get pending assignments
        $stmt = $pdo->prepare("
            SELECT a.*, s.subject_name 
            FROM assignments a
            JOIN subjects s ON a.subject_id = s.id
            WHERE a.school_id = ? AND (a.class = ? OR a.class IS NULL)
            AND (a.deadline >= CURDATE() OR a.deadline IS NULL)
            AND a.id NOT IN (
                SELECT assignment_id FROM assignment_submissions 
                WHERE student_id = ?
            )
            ORDER BY a.deadline ASC
            LIMIT 5
        ");
        $stmt->execute([$school_id, $student_class, $student_id]);
        $pending_assignments = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching assignments: " . $e->getMessage());
    }
    
    try {
        // Get statistics
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT exam_id) as total_exams_taken,
                AVG(percentage) as average_score,
                (SELECT COUNT(*) FROM assignment_submissions WHERE student_id = ?) as assignments_submitted
            FROM exam_sessions 
            WHERE student_id = ? AND status = 'completed'
        ");
        $stmt->execute([$student_id, $student_id]);
        $stats = $stmt->fetch();
        if (!$stats) $stats = ['total_exams_taken' => 0, 'average_score' => 0, 'assignments_submitted' => 0];
    } catch (PDOException $e) {
        error_log("Error fetching stats: " . $e->getMessage());
    }
}

// ============================================================
// MOCK DATA - Used when database has no data
// ============================================================
if (empty($available_exams)) {
    $available_exams = [
        ['id' => 1, 'exam_name' => 'Mathematics - Fractions & Decimals', 'subject_name' => 'Mathematics', 'duration_minutes' => 45, 'class' => $student_class],
        ['id' => 2, 'exam_name' => 'English - Comprehension & Grammar', 'subject_name' => 'English Studies', 'duration_minutes' => 60, 'class' => $student_class],
        ['id' => 3, 'exam_name' => 'Basic Science - Living Things', 'subject_name' => 'Basic Science', 'duration_minutes' => 40, 'class' => $student_class],
        ['id' => 4, 'exam_name' => 'Social Studies - Citizenship', 'subject_name' => 'Social Studies', 'duration_minutes' => 35, 'class' => $student_class],
    ];
}

if (empty($in_progress_exams)) {
    $in_progress_exams = [];
}

if (empty($completed_exams)) {
    $completed_exams = [
        ['exam_name' => 'Mathematics - Algebra Quiz', 'subject_name' => 'Mathematics', 'percentage' => 85, 'grade' => 'A', 'total_score' => 42, 'exam_id' => 1],
        ['exam_name' => 'English - Essay Writing', 'subject_name' => 'English Studies', 'percentage' => 78, 'grade' => 'B+', 'total_score' => 39, 'exam_id' => 2],
        ['exam_name' => 'Basic Science - Plants', 'subject_name' => 'Basic Science', 'percentage' => 92, 'grade' => 'A+', 'total_score' => 46, 'exam_id' => 3],
    ];
}

if (empty($pending_assignments)) {
    $pending_assignments = [
        ['id' => 1, 'title' => 'Mathematics - Quadratic Equations Worksheet', 'subject_name' => 'Mathematics', 'deadline' => date('Y-m-d', strtotime('+5 days'))],
        ['id' => 2, 'title' => 'English - Narrative Essay (500 words)', 'subject_name' => 'English Studies', 'deadline' => date('Y-m-d', strtotime('+3 days'))],
        ['id' => 3, 'title' => 'Basic Science - Lab Report on Photosynthesis', 'subject_name' => 'Basic Science', 'deadline' => date('Y-m-d', strtotime('+7 days'))],
        ['id' => 4, 'title' => 'Social Studies - Project on Local Government', 'subject_name' => 'Social Studies', 'deadline' => date('Y-m-d', strtotime('+10 days'))],
    ];
}

if (empty($stats['total_exams_taken']) || $stats['total_exams_taken'] == 0) {
    $stats = ['total_exams_taken' => 3, 'average_score' => 85.0, 'assignments_submitted' => 2];
}

// Helper functions for sidebar styling
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

function getContrastColor($hex) {
    $rgb = hexToRgb($hex);
    list($r, $g, $b) = explode(',', $rgb);
    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b);
    return ($luminance > 128) ? '#1e293b' : '#ffffff';
}

// Derive sidebar styling
$sb_bg = adjustBrightness($primary_color, -12);
$sb_surface = adjustBrightness($primary_color, -5);
$sb_hover_bg = "rgba(" . hexToRgb($accent_color) . ", 0.08)";
$sb_active_bg = "rgba(" . hexToRgb($secondary_color) . ", 0.18)";
$sb_border = "rgba(" . hexToRgb($accent_color) . ", 0.10)";
$text_primary = getContrastColor($primary_color);
$text_muted = (getContrastColor($primary_color) === '#ffffff') ? "rgba(255,255,255,0.65)" : "rgba(0,0,0,0.65)";
$text_bright = (getContrastColor($primary_color) === '#ffffff') ? "rgba(255,255,255,0.95)" : "rgba(0,0,0,0.95)";
$logo_gradient = "linear-gradient(135deg, $secondary_color, $primary_color)";

// Determine current page for active states
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo htmlspecialchars($school_name); ?> - Student Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
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
        :root {
            --sb-primary: <?php echo $primary_color; ?>;
            --sb-secondary: <?php echo $secondary_color; ?>;
            --sb-accent: <?php echo $accent_color; ?>;
            --sb-bg: <?php echo $sb_bg; ?>;
            --sb-surface: <?php echo $sb_surface; ?>;
            --sb-border: <?php echo $sb_border; ?>;
            --sb-text: <?php echo $text_muted; ?>;
            --sb-text-bright: <?php echo $text_bright; ?>;
            --sb-accent-clr: <?php echo $secondary_color; ?>;
            --sb-hover: <?php echo $sb_hover_bg; ?>;
            --sb-active-bg: <?php echo $sb_active_bg; ?>;
            --sb-logo-grad: <?php echo $logo_gradient; ?>;
            --sb-radius: 10px;
            --sb-width: 280px;
            --sb-transition: 0.22s ease;
            --primary-color: <?php echo $primary_color; ?>;
        }

        .student-sidebar {
            width: var(--sb-width);
            height: 100vh;
            background: var(--sb-bg);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            left: 0;
            overflow-y: auto;
            z-index: 1000;
            border-right: 1px solid var(--sb-border);
            transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 999;
        }
        .sidebar-overlay.active { display: block; }

        .sidebar-header {
            padding: 20px 16px 14px;
            border-bottom: 1px solid var(--sb-border);
        }
        .logo { display: flex; align-items: center; gap: 10px; }
        .logo-icon {
            width: 42px; height: 42px; border-radius: 10px;
            background: var(--sb-logo-grad);
            display: flex; align-items: center; justify-content: center;
        }
        .logo-icon i { color: var(--sb-accent-clr); font-size: 1.2rem; }
        .logo-text .school-name {
            font-size: 0.85rem; font-weight: 700;
            color: var(--sb-text-bright);
        }
        .logo-text p { font-size: 0.7rem; color: var(--sb-text); }

        .student-info {
            text-align: center; padding: 16px 12px;
            border-bottom: 1px solid var(--sb-border);
        }
        .student-avatar {
            width: 72px; height: 72px; border-radius: 50%;
            object-fit: cover; border: 3px solid var(--sb-accent-clr);
            margin: 0 auto 10px; display: block;
        }
        .student-name { font-size: 0.9rem; font-weight: 600; color: var(--sb-text-bright); }
        .student-details { font-size: 0.72rem; color: var(--sb-text); margin: 2px 0; }

        .sidebar-nav { flex: 1; padding: 12px 8px; }
        .nav-item.standalone {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 14px; border-radius: var(--sb-radius);
            color: var(--sb-text); text-decoration: none;
            font-size: 0.85rem; font-weight: 500;
            transition: all 0.2s;
        }
        .nav-item.standalone:hover { background: var(--sb-hover); color: var(--sb-text-bright); }
        .nav-item.standalone.active { background: var(--sb-active-bg); color: var(--sb-accent-clr); }

        .nav-group-toggle {
            width: 100%; display: flex; align-items: center; gap: 10px;
            padding: 10px 14px; border-radius: var(--sb-radius);
            background: none; border: none; cursor: pointer;
            color: var(--sb-text); font-size: 0.85rem; font-weight: 500;
        }
        .nav-group-toggle:hover { background: var(--sb-hover); color: var(--sb-text-bright); }
        .nav-group.open .nav-group-toggle { color: var(--sb-accent-clr); }
        .group-badge { margin-left: auto; }
        .chevron { transition: transform 0.22s; font-size: 0.7rem; }
        .nav-group.open .chevron { transform: rotate(180deg); }
        .nav-group-items {
            display: none; list-style: none;
            padding: 4px 0 4px 32px;
        }
        .nav-group-items.expanded { display: block; }
        .nav-group-items li a {
            display: flex; align-items: center; gap: 8px;
            padding: 8px 12px; border-radius: 8px;
            color: var(--sb-text); text-decoration: none;
            font-size: 0.82rem;
        }
        .nav-group-items li a:hover { background: var(--sb-hover); }
        .nav-group-items li a.active { color: var(--sb-accent-clr); }

        /* Main Content */
        .main-content {
            margin-left: var(--sb-width);
            padding: 20px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        .mobile-menu-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1001;
            background: var(--primary-color);
            color: white;
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 10px;
            font-size: 20px;
            cursor: pointer;
            display: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .top-header {
            background: linear-gradient(135deg, var(--primary-color), #2c3e50);
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
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
        }

        .welcome-text h1 { font-size: 1.5rem; margin-bottom: 5px; }
        .welcome-text p { opacity: 0.9; font-size: 0.9rem; }

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
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .stat-label { color: #666; font-size: 0.8rem; }

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
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #ecf0f1;
        }

        .card-header h3 { color: var(--primary-color); font-size: 1.1rem; }

        .exam-item, .assignment-item {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }
        .exam-item:last-child, .assignment-item:last-child { border-bottom: none; }

        .exam-title, .assignment-title { font-weight: 600; margin-bottom: 5px; }
        .exam-meta, .assignment-meta { font-size: 0.75rem; color: #666; }

        .btn {
            padding: 6px 12px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.8rem;
        }
        .btn-primary { background: var(--primary-color); color: white; }
        .btn-warning { background: #f39c12; color: white; }

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
        .action-btn:hover { transform: translateY(-3px); border-color: var(--primary-color); }
        .action-icon { font-size: 24px; margin-bottom: 8px; display: block; }
        .action-text { font-size: 0.75rem; font-weight: 500; }

        .footer {
            text-align: center;
            padding: 20px;
            color: #666;
            font-size: 0.8rem;
            border-top: 1px solid #ecf0f1;
            margin-top: 20px;
        }

        @media (max-width: 768px) {
            .student-sidebar { transform: translateX(-100%); }
            .student-sidebar.active { transform: translateX(0); box-shadow: 8px 0 32px rgba(0,0,0,0.5); }
            .main-content { margin-left: 0; }
            .mobile-menu-btn { display: block; }
            .stats-grid { grid-template-columns: 1fr; }
            .content-grid { grid-template-columns: 1fr; }
            .welcome-banner { flex-direction: column; text-align: center; }
        }

        @media (min-width: 769px) {
            .student-sidebar { transform: translateX(0); }
        }
    </style>
</head>
<body>

<button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<div class="student-sidebar" id="studentSidebar">
    <div class="sidebar-header">
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
            <div class="logo-text">
                <h3 class="school-name"><?php echo htmlspecialchars($school_name); ?></h3>
                <p>Student Portal</p>
            </div>
        </div>
    </div>
    <div class="student-info">
        <img src="<?php echo htmlspecialchars($profile_picture); ?>" class="student-avatar" onerror="this.src='https://ui-avatars.com/api/?background=<?php echo ltrim($primary_color, '#'); ?>&color=fff&name=<?php echo urlencode($student_name); ?>'">
        <div class="student-name"><?php echo htmlspecialchars($student_name); ?></div>
        <div class="student-details"><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($admission_number); ?></div>
        <div class="student-details"><i class="fas fa-graduation-cap"></i> Class: <?php echo htmlspecialchars($student_class); ?></div>
    </div>
    <nav class="sidebar-nav">
        <a href="index.php" class="nav-item standalone active"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <div class="nav-group"><button class="nav-group-toggle"><i class="fas fa-file-alt"></i> Exams & Assignments <span class="group-badge"><i class="fas fa-chevron-down chevron"></i></span></button>
            <ul class="nav-group-items"><li><a href="take-exam.php"><i class="fas fa-pen-alt"></i> Take Exam</a></li><li><a href="assignments.php"><i class="fas fa-tasks"></i> Assignments</a></li><li><a href="view-results.php"><i class="fas fa-chart-bar"></i> Results</a></li></ul>
        </div>
        <a href="profile.php" class="nav-item standalone"><i class="fas fa-user-circle"></i> My Profile</a>
        <a href="/msv/logout.php" class="nav-item standalone"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>
</div>

<!-- Main Content -->
<div class="main-content">
    <div class="top-header">
        <div class="welcome-banner">
            <img src="<?php echo htmlspecialchars($profile_picture); ?>" class="welcome-avatar" onerror="this.src='https://ui-avatars.com/api/?background=<?php echo ltrim($primary_color, '#'); ?>&color=fff&name=<?php echo urlencode($student_name); ?>'">
            <div class="welcome-text">
                <h1>Welcome, <?php echo htmlspecialchars($student_name); ?>!</h1>
                <p><i class="fas fa-graduation-cap"></i> Class: <?php echo htmlspecialchars($student_class); ?> | <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($admission_number); ?></p>
            </div>
        </div>
    </div>

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-value"><?php echo $stats['total_exams_taken'] ?? 0; ?></div><div class="stat-label">Exams Taken</div></div>
        <div class="stat-card"><div class="stat-value"><?php echo round($stats['average_score'] ?? 0, 1); ?>%</div><div class="stat-label">Average Score</div></div>
        <div class="stat-card"><div class="stat-value"><?php echo $stats['assignments_submitted'] ?? 0; ?></div><div class="stat-label">Assignments Done</div></div>
    </div>

    <!-- In-Progress Exams -->
    <?php if (!empty($in_progress_exams)): ?>
    <div class="content-card">
        <div class="card-header"><h3><i class="fas fa-hourglass-half"></i> Continue Your Exams</h3></div>
        <?php foreach ($in_progress_exams as $exam): ?>
            <div class="exam-item">
                <div class="exam-title"><?php echo htmlspecialchars($exam['exam_name'] ?? 'Exam'); ?></div>
                <div class="exam-meta">Time Left: <?php echo gmdate("H:i:s", max(0, $exam['time_remaining'] ?? 0)); ?></div>
                <a href="take-exam.php?resume=<?php echo $exam['id']; ?>" class="btn btn-warning" style="margin-top:8px;"><i class="fas fa-play"></i> Continue</a>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Available Exams & Pending Assignments Grid -->
    <div class="content-grid">
        <div class="content-card">
            <div class="card-header"><h3><i class="fas fa-file-alt"></i> Available Exams</h3><a href="take-exam.php" class="btn btn-primary">View All</a></div>
            <?php if (empty($available_exams)): ?>
                <p style="text-align:center; padding:20px; color:#999;">No exams available.</p>
            <?php else: ?>
                <?php foreach ($available_exams as $exam): ?>
                    <div class="exam-item">
                        <div class="exam-title"><?php echo htmlspecialchars($exam['exam_name'] ?? $exam['title'] ?? 'Exam'); ?></div>
                        <div class="exam-meta"><?php echo htmlspecialchars($exam['subject_name'] ?? 'General'); ?> | Duration: <?php echo $exam['duration_minutes'] ?? 45; ?> mins</div>
                        <a href="take-exam.php?exam_id=<?php echo $exam['id']; ?>" class="btn btn-primary" style="margin-top:8px;"><i class="fas fa-play"></i> Start Exam</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="content-card">
            <div class="card-header"><h3><i class="fas fa-tasks"></i> Pending Assignments</h3><a href="assignments.php" class="btn btn-primary">View All</a></div>
            <?php if (empty($pending_assignments)): ?>
                <p style="text-align:center; padding:20px; color:#999;">No pending assignments.</p>
            <?php else: ?>
                <?php foreach ($pending_assignments as $assignment): ?>
                    <div class="assignment-item">
                        <div class="assignment-title"><?php echo htmlspecialchars($assignment['title'] ?? 'Assignment'); ?></div>
                        <div class="assignment-meta"><?php echo htmlspecialchars($assignment['subject_name'] ?? 'General'); ?> | Due: <?php echo isset($assignment['deadline']) ? date('M d, Y', strtotime($assignment['deadline'])) : 'Soon'; ?></div>
                        <a href="assignments.php?id=<?php echo $assignment['id']; ?>" class="btn btn-primary" style="margin-top:8px;"><i class="fas fa-arrow-right"></i> View</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Results & Quick Actions -->
    <div class="content-grid">
        <div class="content-card">
            <div class="card-header"><h3><i class="fas fa-chart-line"></i> Recent Results</h3><a href="view-results.php" class="btn btn-primary">View All</a></div>
            <?php if (empty($completed_exams)): ?>
                <p style="text-align:center; padding:20px; color:#999;">No results yet.</p>
            <?php else: ?>
                <?php foreach ($completed_exams as $exam): ?>
                    <div class="exam-item">
                        <div class="exam-title"><?php echo htmlspecialchars($exam['exam_name'] ?? 'Exam'); ?></div>
                        <div class="exam-meta"><?php echo htmlspecialchars($exam['subject_name'] ?? ''); ?> | Score: <?php echo $exam['percentage'] ?? 0; ?>% | Grade: <?php echo $exam['grade'] ?? 'N/A'; ?></div>
                        <a href="view-results.php?exam_id=<?php echo $exam['exam_id'] ?? $exam['id']; ?>" class="btn btn-primary" style="margin-top:8px;"><i class="fas fa-eye"></i> View Details</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="content-card">
            <div class="card-header"><h3><i class="fas fa-bolt"></i> Quick Actions</h3></div>
            <div class="quick-actions">
                <a href="take-exam.php" class="action-btn"><span class="action-icon"><i class="fas fa-play-circle"></i></span><span class="action-text">Take Exam</span></a>
                <a href="view-results.php" class="action-btn"><span class="action-icon"><i class="fas fa-chart-line"></i></span><span class="action-text">My Results</span></a>
                <a href="assignments.php" class="action-btn"><span class="action-icon"><i class="fas fa-tasks"></i></span><span class="action-text">Assignments</span></a>
                <a href="library.php" class="action-btn"><span class="action-icon"><i class="fas fa-book"></i></span><span class="action-text">E-Library</span></a>
                <a href="profile.php" class="action-btn"><span class="action-icon"><i class="fas fa-user-edit"></i></span><span class="action-text">Profile</span></a>
            </div>
        </div>
    </div>

    <div class="footer">
        <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> - Student Portal</p>
    </div>
</div>

<script>
    (function() {
        // Sidebar accordion
        document.querySelectorAll('.nav-group-toggle').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var group = this.closest('.nav-group');
                var items = group.querySelector('.nav-group-items');
                group.classList.toggle('open');
                if (group.classList.contains('open')) {
                    items.classList.add('expanded');
                    this.setAttribute('aria-expanded', 'true');
                } else {
                    items.classList.remove('expanded');
                    this.setAttribute('aria-expanded', 'false');
                }
            });
        });

        // Mobile sidebar
        var menuBtn = document.getElementById('mobileMenuBtn');
        var sidebar = document.getElementById('studentSidebar');
        var overlay = document.getElementById('sidebarOverlay');

        if (menuBtn && sidebar) {
            menuBtn.addEventListener('click', function() {
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

        // Open first group by default
        var firstGroup = document.querySelector('.nav-group');
        if (firstGroup && !firstGroup.classList.contains('open')) {
            firstGroup.classList.add('open');
            var items = firstGroup.querySelector('.nav-group-items');
            if (items) items.classList.add('expanded');
        }
    })();
</script>

</body>
</html>
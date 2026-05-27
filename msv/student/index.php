<?php
// ================================================================
// INTEGRATED STUDENT DASHBOARD - All-in-One File
// No external sidebar include - everything is self-contained
// ================================================================

session_start();

// Check if student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: /msv/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY ?? '#3b82f6';
$accent_color = SCHOOL_ACCENT ?? '#ffffff';
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'] ?? 'Student';

// Get student details (including class from database)
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND school_id = ?");
$stmt->execute([$student_id, $school_id]);
$student = $stmt->fetch();

// Set class from database, not session
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

        /* ============================================
           SIDEBAR STYLES (Integrated)
           ============================================ */
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
            --sb-accent-glow: rgba(<?php echo hexToRgb($secondary_color); ?>, 0.18);
            --sb-hover: <?php echo $sb_hover_bg; ?>;
            --sb-active-bg: <?php echo $sb_active_bg; ?>;
            --sb-logo-grad: <?php echo $logo_gradient; ?>;
            --sb-radius: 10px;
            --sb-width: 280px;
            --sb-transition: 0.22s ease;
            --primary-color: <?php echo $primary_color; ?>;
        }

        /* Sidebar */
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
            overflow-x: hidden;
            z-index: 1000;
            border-right: 1px solid var(--sb-border);
            scrollbar-width: thin;
            scrollbar-color: var(--sb-surface) transparent;
            font-family: 'Poppins', 'Segoe UI', system-ui, sans-serif;
            transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Overlay */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 999;
        }
        .sidebar-overlay.active { display: block; }

        /* Header */
        .sidebar-header {
            padding: 20px 16px 14px;
            border-bottom: 1px solid var(--sb-border);
        }
        .logo { display: flex; align-items: center; gap: 10px; }
        .logo-icon {
            width: 42px; height: 42px; border-radius: 10px;
            background: var(--sb-logo-grad);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .logo-icon img { width: 34px; height: 34px; object-fit: contain; border-radius: 6px; }
        .logo-icon i { color: var(--sb-accent-clr); font-size: 1.2rem; }
        .logo-text .school-name {
            font-size: 0.85rem; font-weight: 700;
            color: var(--sb-text-bright); line-height: 1.2;
        }
        .logo-text p { font-size: 0.7rem; color: var(--sb-text); }

        /* Student info */
        .student-info {
            text-align: center; padding: 16px 12px;
            border-bottom: 1px solid var(--sb-border);
        }
        .student-avatar {
            width: 72px; height: 72px; border-radius: 50%;
            object-fit: cover; border: 3px solid var(--sb-accent-clr);
            margin: 0 auto 10px; display: block; background: #f0f0f0;
        }
        .student-name { font-size: 0.9rem; font-weight: 600; color: var(--sb-text-bright); margin-bottom: 4px; }
        .student-details { font-size: 0.72rem; color: var(--sb-text); margin: 2px 0; }
        .student-details i { width: 16px; font-size: 0.65rem; }

        /* Navigation */
        .sidebar-nav { flex: 1; padding: 12px 8px; display: flex; flex-direction: column; gap: 2px; }

        .nav-item.standalone {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 14px; border-radius: var(--sb-radius);
            color: var(--sb-text); text-decoration: none;
            font-size: 0.85rem; font-weight: 500;
            transition: background var(--sb-transition), color var(--sb-transition);
        }
        .nav-item.standalone:hover { background: var(--sb-hover); color: var(--sb-text-bright); }
        .nav-item.standalone.active { background: var(--sb-active-bg); color: var(--sb-accent-clr); font-weight: 600; }
        .nav-item.standalone.logout:hover { background: rgba(239,68,68,0.12); color: #f87171; }

        .nav-icon { width: 20px; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; }
        .nav-label { flex: 1; }

        /* Groups */
        .nav-group-toggle {
            width: 100%; display: flex; align-items: center; gap: 10px;
            padding: 10px 14px; border-radius: var(--sb-radius);
            background: none; border: none; cursor: pointer;
            color: var(--sb-text); font-size: 0.85rem; font-weight: 500;
            transition: background var(--sb-transition), color var(--sb-transition);
            font-family: inherit;
        }
        .nav-group-toggle:hover { background: var(--sb-hover); color: var(--sb-text-bright); }
        .nav-group.open .nav-group-toggle { color: var(--sb-accent-clr); }

        .group-badge { margin-left: auto; }
        .chevron { transition: transform 0.22s ease; font-size: 0.7rem; }
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
            transition: background var(--sb-transition), color var(--sb-transition);
        }
        .nav-group-items li a:hover { background: var(--sb-hover); color: var(--sb-text-bright); }
        .nav-group-items li a.active { color: var(--sb-accent-clr); font-weight: 600; }
        .nav-group-items li a i { width: 16px; font-size: 0.8rem; }

        /* Scrollbar */
        .student-sidebar::-webkit-scrollbar { width: 4px; }
        .student-sidebar::-webkit-scrollbar-thumb { background: var(--sb-surface); border-radius: 4px; }

        /* ============================================
           MAIN CONTENT STYLES
           ============================================ */
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

        .exam-item, .assignment-item {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }

        .exam-item:last-child, .assignment-item:last-child {
            border-bottom: none;
        }

        .exam-title, .assignment-title {
            font-weight: 600;
            margin-bottom: 5px;
        }

        .exam-meta, .assignment-meta {
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

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .student-sidebar {
                transform: translateX(-100%);
            }
            .student-sidebar.active {
                transform: translateX(0);
                box-shadow: 8px 0 32px rgba(0, 0, 0, 0.5);
            }
            .main-content {
                margin-left: 0;
            }
            .mobile-menu-btn {
                display: block;
            }
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

        @media (min-width: 769px) {
            .student-sidebar {
                transform: translateX(0);
            }
        }
    </style>
</head>
<body>

<!-- Mobile Menu Button -->
<button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ============================================
     INTEGRATED SIDEBAR (All navigation included)
     ============================================ -->
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
            alt="Profile Picture"
            class="student-avatar"
            onerror="this.src='/assets/uploads/default-avatar.png'">
        <div class="student-name"><?php echo htmlspecialchars($student_name); ?></div>
        <div class="student-details">
            <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($admission_number); ?>
        </div>
        <div class="student-details">
            <i class="fas fa-graduation-cap"></i> Class: <?php echo htmlspecialchars($student_class); ?>
        </div>
    </div>

    <nav class="sidebar-nav">
        <!-- Dashboard -->
        <a href="index.php" class="nav-item standalone <?php echo ($current_page == 'index.php') ? 'active' : ''; ?>">
            <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
            <span class="nav-label">Dashboard</span>
        </a>

        <!-- Exams & Assignments Group -->
        <div class="nav-group <?php echo (in_array($current_page, ['take-exam.php', 'assignments.php', 'view-results.php'])) ? 'open' : ''; ?>" data-group="exams">
            <button class="nav-group-toggle" aria-expanded="<?php echo (in_array($current_page, ['take-exam.php', 'assignments.php', 'view-results.php'])) ? 'true' : 'false'; ?>">
                <span class="nav-icon"><i class="fas fa-file-alt"></i></span>
                <span class="nav-label">Exams & Assignments</span>
                <span class="group-badge"><i class="fas fa-chevron-down chevron"></i></span>
            </button>
            <ul class="nav-group-items <?php echo (in_array($current_page, ['take-exam.php', 'assignments.php', 'view-results.php'])) ? 'expanded' : ''; ?>">
                <li><a href="take-exam.php" class="<?php echo ($current_page == 'take-exam.php') ? 'active' : ''; ?>"><i class="fas fa-pen-alt"></i> Take Exam</a></li>
                <li><a href="assignments.php" class="<?php echo ($current_page == 'assignments.php') ? 'active' : ''; ?>"><i class="fas fa-tasks"></i> Assignments</a></li>
                <li><a href="view-results.php" class="<?php echo ($current_page == 'view-results.php') ? 'active' : ''; ?>"><i class="fas fa-chart-bar"></i> Results</a></li>
            </ul>
        </div>

        <!-- Report Cards Group -->
        <div class="nav-group <?php echo (in_array($current_page, ['report-card.php', 'view-report-card.php'])) ? 'open' : ''; ?>" data-group="reportcards">
            <button class="nav-group-toggle" aria-expanded="<?php echo (in_array($current_page, ['report-card.php', 'view-report-card.php'])) ? 'true' : 'false'; ?>">
                <span class="nav-icon"><i class="fas fa-id-card"></i></span>
                <span class="nav-label">Report Cards</span>
                <span class="group-badge"><i class="fas fa-chevron-down chevron"></i></span>
            </button>
            <ul class="nav-group-items <?php echo (in_array($current_page, ['report-card.php', 'view-report-card.php'])) ? 'expanded' : ''; ?>">
                <li><a href="report-card.php" class="<?php echo ($current_page == 'report-card.php') ? 'active' : ''; ?>"><i class="fas fa-file-pdf"></i> My Report Card</a></li>
                <li><a href="view-report-card.php" class="<?php echo ($current_page == 'view-report-card.php') ? 'active' : ''; ?>"><i class="fas fa-chart-line"></i> Term Reports</a></li>
            </ul>
        </div>

        <!-- Resources Group -->
        <div class="nav-group <?php echo (in_array($current_page, ['library.php', 'waec-practice.php', 'bece-practice.php', 'jamb-practice.php'])) ? 'open' : ''; ?>" data-group="resources">
            <button class="nav-group-toggle" aria-expanded="<?php echo (in_array($current_page, ['library.php', 'waec-practice.php', 'bece-practice.php', 'jamb-practice.php'])) ? 'true' : 'false'; ?>">
                <span class="nav-icon"><i class="fas fa-book"></i></span>
                <span class="nav-label">Resources</span>
                <span class="group-badge"><i class="fas fa-chevron-down chevron"></i></span>
            </button>
            <ul class="nav-group-items <?php echo (in_array($current_page, ['library.php', 'waec-practice.php', 'bece-practice.php', 'jamb-practice.php'])) ? 'expanded' : ''; ?>">
                <li><a href="library.php" class="<?php echo ($current_page == 'library.php') ? 'active' : ''; ?>"><i class="fas fa-book-open"></i> E-Library</a></li>
                <li><a href="waec-practice.php" class="<?php echo ($current_page == 'waec-practice.php') ? 'active' : ''; ?>"><i class="fas fa-chalkboard"></i> WAEC Practice</a></li>
                <li><a href="bece-practice.php" class="<?php echo ($current_page == 'bece-practice.php') ? 'active' : ''; ?>"><i class="fas fa-graduation-cap"></i> BECE Practice</a></li>
                <li><a href="jamb-practice.php" class="<?php echo ($current_page == 'jamb-practice.php') ? 'active' : ''; ?>"><i class="fas fa-university"></i> JAMB Practice</a></li>
            </ul>
        </div>

        <!-- Profile -->
        <a href="profile.php" class="nav-item standalone <?php echo ($current_page == 'profile.php') ? 'active' : ''; ?>">
            <span class="nav-icon"><i class="fas fa-user-circle"></i></span>
            <span class="nav-label">My Profile</span>
        </a>

        <!-- Logout -->
        <a href="/msv/logout.php" class="nav-item standalone logout">
            <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
            <span class="nav-label">Logout</span>
        </a>
    </nav>
</div>

<!-- ============================================
     MAIN CONTENT AREA
     ============================================ -->
<div class="main-content">
    <div class="top-header">
        <div class="welcome-banner">
            <img src="<?php echo htmlspecialchars($profile_picture); ?>"
                alt="Profile Picture"
                class="welcome-avatar"
                onerror="this.src='/assets/uploads/default-avatar.png'">
            <div class="welcome-text">
                <h1>Welcome, <?php echo htmlspecialchars($student_name); ?>!</h1>
                <p><i class="fas fa-graduation-cap"></i> Class: <?php echo htmlspecialchars($student_class); ?> | <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($admission_number); ?></p>
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

    <!-- Available Exams + Pending Assignments + Recent Results Grid -->
    <div class="content-grid">
        <!-- Available Exams -->
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-file-alt"></i> Available Exams</h3>
                <a href="take-exam.php" class="btn btn-primary">View All</a>
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
                <h3><i class="fas fa-tasks"></i> Pending Assignments</h3>
                <a href="assignments.php" class="btn btn-primary">View All</a>
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
    </div>

    <div class="content-grid">
        <!-- Recent Results -->
        <?php if (!empty($completed_exams)): ?>
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-chart-line"></i> Recent Results</h3>
                <a href="view-results.php" class="btn btn-primary">View All</a>
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
                <a href="report-card.php" class="action-btn"><span class="action-icon"><i class="fas fa-id-card"></i></span><span class="action-text">Report Card</span></a>
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
        'use strict';

        // Sidebar Accordion Groups
        function initGroups() {
            document.querySelectorAll('.nav-group').forEach(function(group) {
                var toggle = group.querySelector('.nav-group-toggle');
                var items = group.querySelector('.nav-group-items');

                if (!toggle || !items) return;

                var isOpen = group.classList.contains('open');
                if (isOpen) {
                    items.classList.add('expanded');
                    toggle.setAttribute('aria-expanded', 'true');
                }

                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    var currentlyOpen = group.classList.contains('open');

                    // Close all sibling groups (accordion behaviour)
                    document.querySelectorAll('.nav-group.open').forEach(function(g) {
                        if (g !== group) {
                            g.classList.remove('open');
                            var gToggle = g.querySelector('.nav-group-toggle');
                            var gItems = g.querySelector('.nav-group-items');
                            if (gToggle) gToggle.setAttribute('aria-expanded', 'false');
                            if (gItems) gItems.classList.remove('expanded');
                        }
                    });

                    if (currentlyOpen) {
                        group.classList.remove('open');
                        toggle.setAttribute('aria-expanded', 'false');
                        items.classList.remove('expanded');
                    } else {
                        group.classList.add('open');
                        toggle.setAttribute('aria-expanded', 'true');
                        items.classList.add('expanded');
                    }
                });
            });
        }

        // Mobile Sidebar Logic
        function initMobileSidebar() {
            var toggle = document.getElementById('mobileMenuBtn');
            var sidebar = document.getElementById('studentSidebar');
            var overlay = document.getElementById('sidebarOverlay');
            var body = document.body;

            if (!sidebar) return;

            function openSidebar() {
                sidebar.classList.add('active');
                if (overlay) overlay.classList.add('active');
                body.style.overflow = 'hidden';
            }

            function closeSidebar() {
                sidebar.classList.remove('active');
                if (overlay) overlay.classList.remove('active');
                body.style.overflow = '';
            }

            if (toggle) {
                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    sidebar.classList.contains('active') ? closeSidebar() : openSidebar();
                });
            }

            if (overlay) {
                overlay.addEventListener('click', closeSidebar);
            }

            // Close sidebar when clicking nav links on mobile
            document.querySelectorAll('.nav-item, .nav-group-toggle, .nav-group-items a').forEach(function(link) {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 768) setTimeout(closeSidebar, 150);
                });
            });

            // Close on Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') closeSidebar();
            });

            // Handle resize
            var resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    if (window.innerWidth >= 769) closeSidebar();
                }, 250);
            });
        }

        // Timer updates for in-progress exams
        function updateTimers() {
            // Add any timer update logic if needed for dynamic countdowns
            console.log('Dashboard ready');
        }

        initGroups();
        initMobileSidebar();
        updateTimers();
    })();
</script>

</body>
</html>
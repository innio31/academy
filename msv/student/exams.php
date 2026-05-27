<?php
// msv/student/exams.php - View All Exams
session_start();

require_once '../includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: /msv/login.php");
    exit();
}

$school_id    = SCHOOL_ID;
$school_name  = SCHOOL_NAME;
$primary_color    = SCHOOL_PRIMARY;
$secondary_color  = SCHOOL_SECONDARY;
$accent_color     = SCHOOL_ACCENT;
$student_id   = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'] ?? 'Student';

// Get student details (includes class_id join — same as take-exam.php)
$stmt = $pdo->prepare("
    SELECT s.*, c.id as class_id, c.class_name
    FROM students s
    LEFT JOIN classes c ON c.class_name = s.class AND c.school_id = s.school_id
    WHERE s.id = ? AND s.school_id = ?
");
$stmt->execute([$student_id, $school_id]);
$student = $stmt->fetch();

$student_class    = $student['class']    ?? '';
$student_class_id = $student['class_id'] ?? 0;
$admission_number = $student['admission_number'] ?? '';
$profile_picture  = !empty($student['profile_picture']) ? $student['profile_picture'] : '/assets/uploads/default-avatar.png';
if (!empty($student['profile_picture']) && strpos($student['profile_picture'], '/') !== 0) {
    $profile_picture = '/uploads/' . $student['profile_picture'];
}

// Active (available) exams
$stmt = $pdo->prepare("
    SELECT e.*, s.subject_name
    FROM exams e
    LEFT JOIN subjects s ON e.subject_id = s.id
    WHERE e.school_id = ?
      AND e.class_id = ?
      AND e.is_active = 1
      AND e.id NOT IN (
          SELECT exam_id FROM exam_sessions
          WHERE student_id = ? AND status = 'completed'
      )
    ORDER BY e.created_at DESC
");
$stmt->execute([$school_id, $student_class_id, $student_id]);
$available_exams = $stmt->fetchAll();

// In-progress exams
$stmt = $pdo->prepare("
    SELECT es.*, e.exam_name, s.subject_name, e.duration_minutes,
           GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), es.end_time)) as time_remaining
    FROM exam_sessions es
    JOIN exams e ON es.exam_id = e.id
    LEFT JOIN subjects s ON e.subject_id = s.id
    WHERE es.student_id = ?
      AND es.status = 'in_progress'
      AND es.end_time > NOW()
    ORDER BY es.start_time DESC
");
$stmt->execute([$student_id]);
$in_progress_exams = $stmt->fetchAll();

// Completed exams
$stmt = $pdo->prepare("
    SELECT es.*, e.exam_name, s.subject_name, e.duration_minutes,
           es.percentage, es.grade, es.score as total_score, es.total_questions
    FROM exam_sessions es
    JOIN exams e ON es.exam_id = e.id
    LEFT JOIN subjects s ON e.subject_id = s.id
    WHERE es.student_id = ?
      AND es.status = 'completed'
    ORDER BY es.end_time DESC
");
$stmt->execute([$student_id]);
$completed_exams = $stmt->fetchAll();

// ---------- helpers (same as index.php) ----------
function hexToRgb($hex) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    return hexdec(substr($hex,0,2)).','.hexdec(substr($hex,2,2)).','.hexdec(substr($hex,4,2));
}
function adjustBrightness($hex, $percent) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    $r = hexdec(substr($hex,0,2)); $g = hexdec(substr($hex,2,2)); $b = hexdec(substr($hex,4,2));
    $f = 1 + ($percent / 100);
    return sprintf('#%02x%02x%02x', min(255,max(0,(int)($r*$f))), min(255,max(0,(int)($g*$f))), min(255,max(0,(int)($b*$f))));
}

$sb_bg        = adjustBrightness($primary_color, -12);
$sb_hover_bg  = "rgba(".hexToRgb($accent_color).", 0.08)";
$sb_active_bg = "rgba(".hexToRgb($secondary_color).", 0.18)";
$logo_gradient = "linear-gradient(135deg, $secondary_color, $primary_color)";
$current_page  = basename($_SERVER['PHP_SELF']);

// Grade badge colour helper
function gradeBadgeColor($grade) {
    return match(strtoupper($grade ?? '')) {
        'A'  => '#16a34a',
        'B'  => '#2563eb',
        'C'  => '#d97706',
        'D'  => '#ea580c',
        'F'  => '#dc2626',
        default => '#6b7280',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Exams – <?php echo htmlspecialchars($school_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ── Reset & base ───────────────────────────────────── */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background: #f5f6fa; }

        :root {
            --primary:   <?php echo $primary_color; ?>;
            --secondary: <?php echo $secondary_color; ?>;
            --sidebar-bg: <?php echo $sb_bg; ?>;
            --sidebar-w:  280px;
            --radius: 16px;
            --shadow: 0 2px 12px rgba(0,0,0,.08);
        }

        /* ── Sidebar (exact match to index.php) ─────────────── */
        .student-sidebar {
            width: var(--sidebar-w); height: 100vh;
            background: var(--sidebar-bg);
            position: fixed; top: 0; left: 0;
            overflow-y: auto; z-index: 1000;
            transition: transform .3s ease;
            box-shadow: 2px 0 10px rgba(0,0,0,.1);
        }
        .sidebar-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:999; }
        .sidebar-overlay.active { display:block; }

        .sidebar-header { padding:25px 20px; border-bottom:1px solid rgba(255,255,255,.1); }
        .logo { display:flex; align-items:center; gap:12px; }
        .logo-icon {
            width:45px; height:45px; border-radius:12px;
            background:<?php echo $logo_gradient; ?>;
            display:flex; align-items:center; justify-content:center; overflow:hidden;
        }
        .logo-icon img { width:100%; height:100%; object-fit:cover; }
        .logo-icon i { color:#fff; font-size:1.3rem; }
        .logo-text .school-name { font-size:.9rem; font-weight:700; color:#fff; }
        .logo-text p { font-size:.7rem; color:rgba(255,255,255,.7); }

        .student-info { text-align:center; padding:25px 20px; border-bottom:1px solid rgba(255,255,255,.1); }
        .student-avatar { width:80px; height:80px; border-radius:50%; object-fit:cover; border:3px solid var(--secondary); margin:0 auto 12px; }
        .student-name   { font-size:1rem; font-weight:600; color:#fff; margin-bottom:5px; }
        .student-details { font-size:.75rem; color:rgba(255,255,255,.7); margin:3px 0; }
        .student-details i { width:18px; font-size:.7rem; }

        .sidebar-nav { padding:15px 10px; }
        .nav-item {
            display:flex; align-items:center; gap:12px;
            padding:12px 15px; border-radius:10px;
            color:rgba(255,255,255,.85); text-decoration:none;
            font-size:.85rem; font-weight:500; transition:all .2s;
        }
        .nav-item:hover { background:<?php echo $sb_hover_bg; ?>; color:#fff; }
        .nav-item.active { background:<?php echo $sb_active_bg; ?>; color:<?php echo $secondary_color; ?>; }
        .nav-item i { width:22px; font-size:1rem; }
        .nav-item.logout { margin-top:20px; border-top:1px solid rgba(255,255,255,.1); border-radius:0; padding-top:20px; }

        /* ── Mobile controls ────────────────────────────────── */
        .mobile-menu-btn {
            position:fixed; top:20px; right:20px; z-index:1001;
            background:var(--primary); color:#fff;
            border:none; width:45px; height:45px;
            border-radius:12px; font-size:20px;
            display:none; cursor:pointer;
            box-shadow:0 2px 10px rgba(0,0,0,.1);
        }

        /* ── Main content ───────────────────────────────────── */
        .main-content { margin-left:var(--sidebar-w); padding:28px; min-height:100vh; }

        /* ── Page header ────────────────────────────────────── */
        .page-header {
            background: linear-gradient(135deg, var(--primary), #1a1a6e);
            border-radius:var(--radius); padding:28px 32px;
            margin-bottom:28px; color:#fff;
            display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px;
        }
        .page-header-left h1 { font-size:1.6rem; font-weight:700; margin-bottom:4px; }
        .page-header-left p  { font-size:.85rem; opacity:.85; }
        .page-header-stats { display:flex; gap:24px; }
        .header-stat { text-align:center; }
        .header-stat .val { font-size:1.8rem; font-weight:800; line-height:1; }
        .header-stat .lbl { font-size:.7rem; opacity:.8; margin-top:2px; }

        /* ── Section titles ─────────────────────────────────── */
        .section-title {
            display:flex; align-items:center; gap:10px;
            font-size:1.05rem; font-weight:700; color:var(--primary);
            margin-bottom:16px;
        }
        .section-title i { font-size:1.1rem; }

        /* ── Exam cards grid ────────────────────────────────── */
        .exams-grid {
            display:grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap:20px;
            margin-bottom:36px;
        }

        .exam-card {
            background:#fff; border-radius:var(--radius);
            box-shadow:var(--shadow); overflow:hidden;
            transition:transform .22s, box-shadow .22s;
            display:flex; flex-direction:column;
        }
        .exam-card:hover { transform:translateY(-4px); box-shadow:0 8px 24px rgba(0,0,0,.12); }

        .exam-card-top {
            padding:20px 20px 16px;
            border-left:4px solid var(--primary);
            flex:1;
        }
        .exam-card-top.in-progress { border-left-color: #f39c12; }
        .exam-card-top.completed   { border-left-color: #27ae60; }

        .exam-badge {
            display:inline-flex; align-items:center; gap:5px;
            font-size:.65rem; font-weight:600; text-transform:uppercase;
            letter-spacing:.05em; padding:3px 10px; border-radius:20px;
            margin-bottom:12px;
        }
        .badge-available  { background:#eff6ff; color:#2563eb; }
        .badge-progress   { background:#fef3c7; color:#92400e; }
        .badge-completed  { background:#f0fdf4; color:#15803d; }

        .exam-name    { font-size:1rem; font-weight:700; color:#1e293b; margin-bottom:6px; line-height:1.35; }
        .exam-subject { font-size:.78rem; color:#64748b; margin-bottom:14px; }
        .exam-subject i { margin-right:4px; }

        .exam-meta-row {
            display:flex; flex-wrap:wrap; gap:12px;
            font-size:.73rem; color:#6b7280;
        }
        .exam-meta-row span { display:flex; align-items:center; gap:5px; }

        /* Progress bar on in-progress cards */
        .mini-progress { margin-top:14px; }
        .mini-progress-bar { background:#e5e7eb; border-radius:6px; height:5px; overflow:hidden; }
        .mini-progress-fill { height:100%; border-radius:6px; background:linear-gradient(90deg,#f39c12,#e67e22); }
        .mini-progress-label { font-size:.65rem; color:#9ca3af; margin-top:4px; text-align:right; }

        /* Score display on completed cards */
        .score-chip {
            display:inline-flex; align-items:center; gap:8px;
            margin-top:12px; padding:6px 14px; border-radius:8px;
            font-size:.8rem; font-weight:600; background:#f8fafc;
        }
        .grade-badge {
            display:inline-flex; align-items:center; justify-content:center;
            width:28px; height:28px; border-radius:6px; font-size:.75rem; font-weight:800; color:#fff;
        }

        /* Card footer */
        .exam-card-footer {
            padding:14px 20px;
            border-top:1px solid #f1f5f9;
            display:flex; align-items:center; justify-content:flex-end; gap:10px;
        }

        .btn {
            display:inline-flex; align-items:center; gap:7px;
            padding:9px 18px; border-radius:10px; border:none;
            font-size:.78rem; font-weight:600; cursor:pointer;
            text-decoration:none; transition:all .2s;
        }
        .btn-primary { background:var(--primary); color:#fff; }
        .btn-primary:hover { opacity:.88; transform:translateY(-1px); }
        .btn-warning { background:#f39c12; color:#fff; }
        .btn-warning:hover { opacity:.88; transform:translateY(-1px); }
        .btn-outline {
            background:transparent; border:1.5px solid var(--primary);
            color:var(--primary);
        }
        .btn-outline:hover { background:var(--primary); color:#fff; }
        .btn-ghost { background:#f1f5f9; color:#475569; }
        .btn-ghost:hover { background:#e2e8f0; }

        /* ── Empty state ────────────────────────────────────── */
        .empty-state {
            text-align:center; padding:56px 20px;
            background:#fff; border-radius:var(--radius); box-shadow:var(--shadow);
        }
        .empty-state i { font-size:3rem; color:#cbd5e1; margin-bottom:14px; display:block; }
        .empty-state h3 { font-size:1rem; color:#64748b; font-weight:600; margin-bottom:6px; }
        .empty-state p  { font-size:.8rem; color:#94a3b8; }

        /* ── Tabs ───────────────────────────────────────────── */
        .tabs { display:flex; gap:6px; margin-bottom:24px; flex-wrap:wrap; }
        .tab {
            padding:8px 18px; border-radius:10px; border:none;
            font-size:.82rem; font-weight:600; cursor:pointer;
            background:#fff; color:#64748b; box-shadow:var(--shadow);
            transition:all .2s;
        }
        .tab.active { background:var(--primary); color:#fff; }
        .tab:hover:not(.active) { background:#f1f5f9; }
        .tab .count {
            display:inline-flex; align-items:center; justify-content:center;
            width:20px; height:20px; border-radius:50%;
            font-size:.65rem; font-weight:700; margin-left:6px;
            background:rgba(255,255,255,.25);
        }
        .tab:not(.active) .count { background:#f1f5f9; color:var(--primary); }

        .tab-section { display:none; }
        .tab-section.active { display:block; }

        /* ── Footer ─────────────────────────────────────────── */
        .footer { text-align:center; padding:25px; color:#888; font-size:.75rem; border-top:1px solid #e0e0e0; margin-top:20px; }

        /* ── Responsive ─────────────────────────────────────── */
        @media (max-width:768px) {
            .student-sidebar { transform:translateX(-100%); }
            .student-sidebar.active { transform:translateX(0); }
            .main-content { margin-left:0; padding:16px; }
            .mobile-menu-btn { display:block; }
            .page-header { flex-direction:column; }
            .exams-grid { grid-template-columns:1fr; }
        }
        @media (min-width:769px) {
            .student-sidebar { transform:translateX(0); }
        }
    </style>
</head>
<body>

<button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ===== SIDEBAR ===== -->
<div class="student-sidebar" id="studentSidebar">
    <div class="sidebar-header">
        <div class="logo">
            <div class="logo-icon">
                <?php
                $logo_path = null;
                $logo_locations = ['/msv/assets/logos/logo.png','/assets/logos/logo.png','../assets/logos/logo.png','assets/logos/logo.png'];
                if (defined('SCHOOL_LOGO') && SCHOOL_LOGO && file_exists($_SERVER['DOCUMENT_ROOT'] . SCHOOL_LOGO)) {
                    $logo_path = SCHOOL_LOGO;
                } else {
                    foreach ($logo_locations as $loc) {
                        if (file_exists($_SERVER['DOCUMENT_ROOT'] . $loc)) { $logo_path = $loc; break; }
                    }
                }
                if ($logo_path && file_exists($_SERVER['DOCUMENT_ROOT'] . $logo_path)): ?>
                    <img src="<?php echo $logo_path; ?>" alt="<?php echo htmlspecialchars($school_name); ?>">
                <?php else: ?><i class="fas fa-graduation-cap"></i><?php endif; ?>
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
             onerror="this.src='https://ui-avatars.com/api/?background=<?php echo ltrim($primary_color,'#'); ?>&color=fff&name=<?php echo urlencode($student_name); ?>'">
        <div class="student-name"><?php echo htmlspecialchars($student_name); ?></div>
        <div class="student-details"><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($admission_number ?: 'N/A'); ?></div>
        <div class="student-details"><i class="fas fa-graduation-cap"></i> Class: <?php echo htmlspecialchars($student_class ?: 'Not Assigned'); ?></div>
    </div>

    <nav class="sidebar-nav">
        <a href="index.php"       class="nav-item <?php echo $current_page==='index.php'   ?'active':''; ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="exams.php"       class="nav-item <?php echo $current_page==='exams.php'   ?'active':''; ?>"><i class="fas fa-file-alt"></i> My Exams</a>
        <a href="assignments.php" class="nav-item <?php echo $current_page==='assignments.php'?'active':''; ?>"><i class="fas fa-tasks"></i> Assignments</a>
        <a href="view-results.php" class="nav-item <?php echo $current_page==='view-results.php'?'active':''; ?>"><i class="fas fa-chart-bar"></i> Results</a>
        <a href="report-card.php" class="nav-item <?php echo $current_page==='report-card.php'?'active':''; ?>"><i class="fas fa-id-card"></i> Report Card</a>
        <a href="library.php"     class="nav-item <?php echo $current_page==='library.php' ?'active':''; ?>"><i class="fas fa-book"></i> E-Library</a>
        <a href="profile.php"     class="nav-item <?php echo $current_page==='profile.php' ?'active':''; ?>"><i class="fas fa-user-circle"></i> My Profile</a>
        <a href="/msv/logout.php" class="nav-item logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>
</div>

<!-- ===== MAIN CONTENT ===== -->
<div class="main-content">

    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-left">
            <h1><i class="fas fa-file-alt" style="margin-right:10px;"></i>My Exams</h1>
            <p><?php echo htmlspecialchars($student_class ?: 'All available exams for your class'); ?></p>
        </div>
        <div class="page-header-stats">
            <div class="header-stat">
                <div class="val"><?php echo count($available_exams); ?></div>
                <div class="lbl">Available</div>
            </div>
            <div class="header-stat">
                <div class="val"><?php echo count($in_progress_exams); ?></div>
                <div class="lbl">In Progress</div>
            </div>
            <div class="header-stat">
                <div class="val"><?php echo count($completed_exams); ?></div>
                <div class="lbl">Completed</div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab active" data-tab="available">
            <i class="fas fa-play-circle"></i> Available
            <span class="count"><?php echo count($available_exams); ?></span>
        </button>
        <?php if (!empty($in_progress_exams)): ?>
        <button class="tab" data-tab="progress">
            <i class="fas fa-hourglass-half"></i> In Progress
            <span class="count"><?php echo count($in_progress_exams); ?></span>
        </button>
        <?php endif; ?>
        <button class="tab" data-tab="completed">
            <i class="fas fa-check-circle"></i> Completed
            <span class="count"><?php echo count($completed_exams); ?></span>
        </button>
    </div>

    <!-- ── Available Exams ──────────────────────────────────── -->
    <div class="tab-section active" id="tab-available">
        <div class="section-title"><i class="fas fa-play-circle"></i> Available Exams</div>

        <?php if (empty($available_exams)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No Exams Available</h3>
                <p>You have no pending exams right now. Check back later!</p>
            </div>
        <?php else: ?>
            <div class="exams-grid">
                <?php foreach ($available_exams as $ex): ?>
                <div class="exam-card">
                    <div class="exam-card-top">
                        <span class="exam-badge badge-available"><i class="fas fa-circle" style="font-size:.5rem;"></i> Available</span>
                        <div class="exam-name"><?php echo htmlspecialchars($ex['exam_name']); ?></div>
                        <div class="exam-subject"><i class="fas fa-book-open"></i><?php echo htmlspecialchars($ex['subject_name'] ?? 'General'); ?></div>
                        <div class="exam-meta-row">
                            <span><i class="fas fa-clock"></i><?php echo $ex['duration_minutes']; ?> mins</span>
                            <span><i class="fas fa-question-circle"></i><?php echo $ex['objective_count']; ?> questions</span>
                            <span><i class="fas fa-graduation-cap"></i><?php echo htmlspecialchars($ex['class']); ?></span>
                        </div>
                    </div>
                    <div class="exam-card-footer">
                        <?php if (!empty($ex['instructions'])): ?>
                        <button class="btn btn-ghost" onclick="showInstructions(<?php echo htmlspecialchars(json_encode($ex)); ?>)">
                            <i class="fas fa-info-circle"></i> Info
                        </button>
                        <?php endif; ?>
                        <a href="start-exam.php?exam_id=<?php echo $ex['id']; ?>" class="btn btn-primary">
                            <i class="fas fa-play"></i> Start Exam
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ── In Progress Exams ───────────────────────────────── -->
    <?php if (!empty($in_progress_exams)): ?>
    <div class="tab-section" id="tab-progress">
        <div class="section-title"><i class="fas fa-hourglass-half"></i> In Progress</div>
        <div class="exams-grid">
            <?php foreach ($in_progress_exams as $ex): ?>
            <div class="exam-card">
                <div class="exam-card-top in-progress">
                    <span class="exam-badge badge-progress"><i class="fas fa-circle" style="font-size:.5rem;"></i> In Progress</span>
                    <div class="exam-name"><?php echo htmlspecialchars($ex['exam_name']); ?></div>
                    <div class="exam-subject"><i class="fas fa-book-open"></i><?php echo htmlspecialchars($ex['subject_name'] ?? 'General'); ?></div>
                    <div class="exam-meta-row">
                        <span><i class="fas fa-clock"></i><?php echo gmdate('H:i:s', max(0, $ex['time_remaining'])); ?> left</span>
                        <span><i class="fas fa-stopwatch"></i><?php echo $ex['duration_minutes']; ?> mins total</span>
                    </div>
                    <?php
                    $pct = $ex['duration_minutes'] > 0
                        ? round(($ex['time_remaining'] / ($ex['duration_minutes'] * 60)) * 100)
                        : 0;
                    ?>
                    <div class="mini-progress">
                        <div class="mini-progress-bar">
                            <div class="mini-progress-fill" style="width:<?php echo $pct; ?>%"></div>
                        </div>
                        <div class="mini-progress-label"><?php echo $pct; ?>% time remaining</div>
                    </div>
                </div>
                <div class="exam-card-footer">
                    <a href="start-exam.php?resume=<?php echo $ex['id']; ?>" class="btn btn-warning">
                        <i class="fas fa-play"></i> Continue
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Completed Exams ─────────────────────────────────── -->
    <div class="tab-section" id="tab-completed">
        <div class="section-title"><i class="fas fa-check-circle"></i> Completed Exams</div>

        <?php if (empty($completed_exams)): ?>
            <div class="empty-state">
                <i class="fas fa-chart-simple"></i>
                <h3>No Completed Exams Yet</h3>
                <p>Take an exam and your results will appear here.</p>
            </div>
        <?php else: ?>
            <div class="exams-grid">
                <?php foreach ($completed_exams as $ex): ?>
                <div class="exam-card">
                    <div class="exam-card-top completed">
                        <span class="exam-badge badge-completed"><i class="fas fa-check" style="font-size:.6rem;"></i> Completed</span>
                        <div class="exam-name"><?php echo htmlspecialchars($ex['exam_name']); ?></div>
                        <div class="exam-subject"><i class="fas fa-book-open"></i><?php echo htmlspecialchars($ex['subject_name'] ?? 'General'); ?></div>
                        <div class="exam-meta-row">
                            <span><i class="fas fa-calendar-check"></i><?php echo date('M d, Y', strtotime($ex['end_time'])); ?></span>
                            <span><i class="fas fa-list-ol"></i><?php echo $ex['total_questions'] ?? '—'; ?> questions</span>
                        </div>
                        <div class="score-chip">
                            <span class="grade-badge" style="background:<?php echo gradeBadgeColor($ex['grade']); ?>">
                                <?php echo htmlspecialchars($ex['grade'] ?? '—'); ?>
                            </span>
                            <span><?php echo round($ex['percentage'] ?? 0, 1); ?>% — <?php echo $ex['total_score'] ?? 0; ?>/<?php echo $ex['total_questions'] ?? '?'; ?> correct</span>
                        </div>
                    </div>
                    <div class="exam-card-footer">
                        <a href="view-results.php?exam_id=<?php echo $ex['exam_id']; ?>" class="btn btn-outline">
                            <i class="fas fa-chart-bar"></i> View Results
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="footer">
        &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> – Student Portal. All rights reserved.
    </div>
</div>

<!-- ===== Instructions Modal ===== -->
<div id="infoModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:2000; align-items:center; justify-content:center; padding:20px;">
    <div style="background:#fff; border-radius:16px; max-width:520px; width:100%; padding:28px; box-shadow:0 20px 60px rgba(0,0,0,.2);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 id="modalTitle" style="color:<?php echo $primary_color; ?>; font-size:1.1rem;"></h3>
            <button onclick="closeModal()" style="background:none; border:none; font-size:1.4rem; cursor:pointer; color:#64748b;">&times;</button>
        </div>
        <div id="modalBody" style="font-size:.88rem; color:#374151; line-height:1.7;"></div>
        <div style="margin-top:20px; display:flex; gap:10px; justify-content:flex-end;">
            <button onclick="closeModal()" style="padding:9px 18px; border-radius:8px; border:1.5px solid #e2e8f0; background:#f8fafc; color:#475569; font-size:.82rem; font-weight:600; cursor:pointer;">Close</button>
            <a id="modalStartBtn" href="#" style="padding:9px 18px; border-radius:8px; background:<?php echo $primary_color; ?>; color:#fff; font-size:.82rem; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                <i class="fas fa-play"></i> Start Exam
            </a>
        </div>
    </div>
</div>

<script>
// Sidebar toggle
(function() {
    const btn     = document.getElementById('mobileMenuBtn');
    const sidebar = document.getElementById('studentSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (btn && sidebar) {
        btn.addEventListener('click', e => {
            e.preventDefault();
            sidebar.classList.toggle('active');
            overlay?.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        });
    }
    overlay?.addEventListener('click', () => {
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            sidebar.classList.remove('active');
            overlay?.classList.remove('active');
            document.body.style.overflow = '';
        }
    });
})();

// Tabs
document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-section').forEach(s => s.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('tab-' + tab.dataset.tab).classList.add('active');
    });
});

// Instructions modal
function showInstructions(exam) {
    document.getElementById('modalTitle').textContent = exam.exam_name;
    document.getElementById('modalBody').innerHTML =
        '<strong>Subject:</strong> ' + (exam.subject_name || 'General') + '<br>' +
        '<strong>Duration:</strong> ' + exam.duration_minutes + ' minutes<br>' +
        '<strong>Questions:</strong> ' + exam.objective_count + '<br><br>' +
        '<strong>Instructions:</strong><br>' + (exam.instructions || 'No special instructions.').replace(/\n/g,'<br>');
    document.getElementById('modalStartBtn').href = 'start-exam.php?exam_id=' + exam.id;
    const modal = document.getElementById('infoModal');
    modal.style.display = 'flex';
}
function closeModal() {
    document.getElementById('infoModal').style.display = 'none';
}
document.getElementById('infoModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
</body>
</html>
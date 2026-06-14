<?php
// gos/student/includes/student_sidebar.php - Student Sidebar Component

// Make sure required variables are available
if (!isset($school_name) || !isset($student_name) || !isset($student_class)) {
    $school_name = SCHOOL_NAME ?? 'School';
    $student_name = $_SESSION['user_name'] ?? 'Student';
    $student_class = $student['class'] ?? '';
}

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);

// Define page groups for active states
$dashboard_pages = ['index.php'];
$exam_pages = ['take-exam.php', 'exams.php'];
$assignment_pages = ['assignments.php'];
$result_pages = ['view-results.php'];
$report_card_pages = ['report-card.php', 'view-report-card.php'];
$resources_pages = ['library.php', 'waec-practice.php', 'bece-practice.php', 'jamb-practice.php'];
$profile_pages = ['profile.php'];

$dashboard_active = in_array($current_page, $dashboard_pages);
$exam_active = in_array($current_page, $exam_pages);
$assignment_active = in_array($current_page, $assignment_pages);
$result_active = in_array($current_page, $result_pages);
$report_card_active = in_array($current_page, $report_card_pages);
$resources_active = in_array($current_page, $resources_pages);
$profile_active = in_array($current_page, $profile_pages);

// School Theme Resolution
$sb_primary = defined('SCHOOL_PRIMARY') ? SCHOOL_PRIMARY : '#1e293b';
$sb_secondary = defined('SCHOOL_SECONDARY') ? SCHOOL_SECONDARY : '#3b82f6';
$sb_accent = defined('SCHOOL_ACCENT') ? SCHOOL_ACCENT : '#ffffff';

// Helper functions
function sbHexToRgbStudent(string $hex): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return "$r,$g,$b";
}

function sbAdjustBrightnessStudent(string $hex, int $percent): string
{
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

function sbGetContrastColorStudent(string $hex): string
{
    $rgb = sbHexToRgbStudent($hex);
    list($r, $g, $b) = explode(',', $rgb);
    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b);
    return ($luminance > 128) ? '#1e293b' : '#ffffff';
}

// Derive sidebar styling
$sb_bg = sbAdjustBrightnessStudent($sb_primary, -12);
$sb_surface = sbAdjustBrightnessStudent($sb_primary, -5);
$sb_hover_bg = "rgba(" . sbHexToRgbStudent($sb_accent) . ", 0.08)";
$sb_active_bg = "rgba(" . sbHexToRgbStudent($sb_secondary) . ", 0.18)";
$sb_border = "rgba(" . sbHexToRgbStudent($sb_accent) . ", 0.10)";

$text_primary = sbGetContrastColorStudent($sb_primary);
$text_muted = (sbGetContrastColorStudent($sb_primary) === '#ffffff')
    ? "rgba(255,255,255,0.65)"
    : "rgba(0,0,0,0.65)";
$text_bright = (sbGetContrastColorStudent($sb_primary) === '#ffffff')
    ? "rgba(255,255,255,0.95)"
    : "rgba(0,0,0,0.95)";

$logo_gradient = "linear-gradient(135deg, $sb_secondary, $sb_primary)";

// Get profile picture path
$profile_picture = !empty($student['profile_picture']) ? $student['profile_picture'] : '/assets/uploads/default-avatar.png';
if (!empty($student['profile_picture']) && strpos($student['profile_picture'], '/') !== 0) {
    $profile_picture = '/uploads/' . $student['profile_picture'];
}
?>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<div class="student-sidebar" id="studentSidebar">

    <!-- Header -->
    <div class="sidebar-header">
        <div class="logo">
            <div class="logo-icon">
                <?php
                $logo_path = null;
                $logo_locations = [
                    '/gos/assets/logos/logo.png',
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

    <!-- Student Info -->
    <div class="student-info">
        <img src="<?php echo htmlspecialchars($profile_picture); ?>"
            alt="Profile Picture"
            class="student-avatar"
            onerror="this.src='/assets/uploads/default-avatar.png'">
        <div class="student-name"><?php echo htmlspecialchars($student_name); ?></div>
        <div class="student-details">
            <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($student['admission_number'] ?? ''); ?>
        </div>
        <div class="student-details">
            <i class="fas fa-graduation-cap"></i> Class: <?php echo htmlspecialchars($student_class); ?>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav">

        <!-- Dashboard -->
        <a href="index.php" class="nav-item standalone <?php echo $dashboard_active ? 'active' : ''; ?>">
            <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
            <span class="nav-label">Dashboard</span>
        </a>

        <!-- Exams & Assignments Group -->
        <div class="nav-group <?php echo ($exam_active || $assignment_active || $result_active) ? 'open' : ''; ?>" data-group="exams">
            <button class="nav-group-toggle" aria-expanded="<?php echo ($exam_active || $assignment_active || $result_active) ? 'true' : 'false'; ?>">
                <span class="nav-icon"><i class="fas fa-file-alt"></i></span>
                <span class="nav-label">Exams & Assignments</span>
                <span class="group-badge">
                    <i class="fas fa-chevron-down chevron"></i>
                </span>
            </button>
            <ul class="nav-group-items <?php echo ($exam_active || $assignment_active || $result_active) ? 'expanded' : ''; ?>">
                <li>
                    <a href="exams.php" class="<?php echo $exam_active ? 'active' : ''; ?>">
                        <i class="fas fa-pen-alt"></i> Take Exam
                    </a>
                </li>
                <li>
                    <a href="assignments.php" class="<?php echo $assignment_active ? 'active' : ''; ?>">
                        <i class="fas fa-tasks"></i> Assignments
                    </a>
                </li>
                <li>
                    <a href="view-results.php" class="<?php echo $result_active ? 'active' : ''; ?>">
                        <i class="fas fa-chart-bar"></i> Results
                    </a>
                </li>
            </ul>
        </div>

        <!-- Report Cards Group -->
        <div class="nav-group <?php echo $report_card_active ? 'open' : ''; ?>" data-group="reportcards">
            <button class="nav-group-toggle" aria-expanded="<?php echo $report_card_active ? 'true' : 'false'; ?>">
                <span class="nav-icon"><i class="fas fa-id-card"></i></span>
                <span class="nav-label">Report Cards</span>
                <span class="group-badge">
                    <i class="fas fa-chevron-down chevron"></i>
                </span>
            </button>
            <ul class="nav-group-items <?php echo $report_card_active ? 'expanded' : ''; ?>">
                <li>
                    <a href="report-card.php" class="<?php echo $current_page == 'report-card.php' ? 'active' : ''; ?>">
                        <i class="fas fa-file-pdf"></i> My Report Card
                    </a>
                </li>
            </ul>
        </div>

        <!-- Resources Group -->
        <div class="nav-group <?php echo $resources_active ? 'open' : ''; ?>" data-group="resources">
            <button class="nav-group-toggle" aria-expanded="<?php echo $resources_active ? 'true' : 'false'; ?>">
                <span class="nav-icon"><i class="fas fa-book"></i></span>
                <span class="nav-label">Resources</span>
                <span class="group-badge">
                    <i class="fas fa-chevron-down chevron"></i>
                </span>
            </button>
            <ul class="nav-group-items <?php echo $resources_active ? 'expanded' : ''; ?>">
                <li>
                    <a href="library.php" class="<?php echo $current_page == 'library.php' ? 'active' : ''; ?>">
                        <i class="fas fa-book-open"></i> E-Library
                    </a>
                </li>
                <li>
                    <a href="waec-practice.php" class="<?php echo $current_page == 'waec-practice.php' ? 'active' : ''; ?>">
                        <i class="fas fa-chalkboard"></i> WAEC Practice
                    </a>
                </li>
                <li>
                    <a href="bece-practice.php" class="<?php echo $current_page == 'bece-practice.php' ? 'active' : ''; ?>">
                        <i class="fas fa-graduation-cap"></i> BECE Practice
                    </a>
                </li>
                <li>
                    <a href="jamb-practice.php" class="<?php echo $current_page == 'jamb-practice.php' ? 'active' : ''; ?>">
                        <i class="fas fa-university"></i> JAMB Practice
                    </a>
                </li>
            </ul>
        </div>

        <!-- Profile -->
        <a href="profile.php" class="nav-item standalone <?php echo $profile_active ? 'active' : ''; ?>">
            <span class="nav-icon"><i class="fas fa-user-circle"></i></span>
            <span class="nav-label">My Profile</span>
        </a>

        <!-- Logout -->
        <a href="/gos/logout.php" class="nav-item standalone logout">
            <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
            <span class="nav-label">Logout</span>
        </a>

    </nav>
</div>

<style>
    /* CSS styles remain the same as in your original file */
    :root {
        --sb-primary: <?php echo $sb_primary; ?>;
        --sb-secondary: <?php echo $sb_secondary; ?>;
        --sb-accent: <?php echo $sb_accent; ?>;
        --sb-bg: <?php echo $sb_bg; ?>;
        --sb-surface: <?php echo $sb_surface; ?>;
        --sb-border: <?php echo $sb_border; ?>;
        --sb-text: <?php echo $text_muted; ?>;
        --sb-text-bright: <?php echo $text_bright; ?>;
        --sb-accent-clr: <?php echo $sb_secondary; ?>;
        --sb-accent-glow: rgba(<?php echo sbHexToRgbStudent($sb_secondary); ?>, 0.18);
        --sb-hover: <?php echo $sb_hover_bg; ?>;
        --sb-active-bg: <?php echo $sb_active_bg; ?>;
        --sb-logo-grad: <?php echo $logo_gradient; ?>;
        --sb-radius: 10px;
        --sb-width: 280px;
        --sb-transition: 0.22s ease;
    }

    /* Copy ALL your existing CSS styles from student_sidebar.php here */
    /* (keeping the same styles as before) */

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
    }

    /* Overlay */
    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 999;
    }

    .sidebar-overlay.active {
        display: block;
    }

    /* Header */
    .sidebar-header {
        padding: 20px 16px 14px;
        border-bottom: 1px solid var(--sb-border);
    }

    .logo {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .logo-icon {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        background: var(--sb-logo-grad);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .logo-icon img {
        width: 34px;
        height: 34px;
        object-fit: contain;
        border-radius: 6px;
    }

    .logo-icon i {
        color: var(--sb-accent-clr);
        font-size: 1.2rem;
    }

    .logo-text .school-name {
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--sb-text-bright);
        line-height: 1.2;
    }

    .logo-text p {
        font-size: 0.7rem;
        color: var(--sb-text);
    }

    /* Student info */
    .student-info {
        text-align: center;
        padding: 16px 12px;
        border-bottom: 1px solid var(--sb-border);
    }

    .student-avatar {
        width: 72px;
        height: 72px;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid var(--sb-accent-clr);
        margin: 0 auto 10px;
        display: block;
        background: #f0f0f0;
    }

    .student-name {
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--sb-text-bright);
        margin-bottom: 4px;
    }

    .student-details {
        font-size: 0.72rem;
        color: var(--sb-text);
        margin: 2px 0;
    }

    .student-details i {
        width: 16px;
        font-size: 0.65rem;
    }

    /* Nav */
    .sidebar-nav {
        flex: 1;
        padding: 12px 8px;
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .nav-item.standalone {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 14px;
        border-radius: var(--sb-radius);
        color: var(--sb-text);
        text-decoration: none;
        font-size: 0.85rem;
        font-weight: 500;
        transition: background var(--sb-transition), color var(--sb-transition);
    }

    .nav-item.standalone:hover {
        background: var(--sb-hover);
        color: var(--sb-text-bright);
    }

    .nav-item.standalone.active {
        background: var(--sb-active-bg);
        color: var(--sb-accent-clr);
        font-weight: 600;
    }

    .nav-item.standalone.logout:hover {
        background: rgba(239, 68, 68, 0.12);
        color: #f87171;
    }

    .nav-icon {
        width: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.9rem;
    }

    .nav-label {
        flex: 1;
    }

    /* Groups */
    .nav-group-toggle {
        width: 100%;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 14px;
        border-radius: var(--sb-radius);
        background: none;
        border: none;
        cursor: pointer;
        color: var(--sb-text);
        font-size: 0.85rem;
        font-weight: 500;
        transition: background var(--sb-transition), color var(--sb-transition);
        font-family: inherit;
    }

    .nav-group-toggle:hover {
        background: var(--sb-hover);
        color: var(--sb-text-bright);
    }

    .nav-group.open .nav-group-toggle {
        color: var(--sb-accent-clr);
    }

    .group-badge {
        margin-left: auto;
    }

    .chevron {
        transition: transform 0.22s ease;
        font-size: 0.7rem;
    }

    .nav-group.open .chevron {
        transform: rotate(180deg);
    }

    .nav-group-items {
        display: none;
        list-style: none;
        padding: 4px 0 4px 32px;
    }

    .nav-group-items.expanded {
        display: block;
    }

    .nav-group-items li a {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 8px;
        color: var(--sb-text);
        text-decoration: none;
        font-size: 0.82rem;
        transition: background var(--sb-transition), color var(--sb-transition);
    }

    .nav-group-items li a:hover {
        background: var(--sb-hover);
        color: var(--sb-text-bright);
    }

    .nav-group-items li a.active {
        color: var(--sb-accent-clr);
        font-weight: 600;
    }

    .nav-group-items li a i {
        width: 16px;
        font-size: 0.8rem;
    }

    /* Scrollbar */
    .student-sidebar::-webkit-scrollbar {
        width: 4px;
    }

    .student-sidebar::-webkit-scrollbar-thumb {
        background: var(--sb-surface);
        border-radius: 4px;
    }

    @media (max-width: 767px) {
        .student-sidebar {
            transform: translateX(-100%);
            transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1001;
        }

        .student-sidebar.active {
            transform: translateX(0);
            box-shadow: 8px 0 32px rgba(0, 0, 0, 0.5);
        }
    }
</style>
<script>
    (function() {
        'use strict';

        // Check if sidebar is already initialized
        if (window.__studentSidebarInitialized) {
            return;
        }
        window.__studentSidebarInitialized = true;

        /* ── Accordion groups ── */
        function initGroups() {
            document.querySelectorAll('.nav-group').forEach(function(group) {
                var toggle = group.querySelector('.nav-group-toggle');
                var items = group.querySelector('.nav-group-items');

                if (!toggle || !items) return;

                // Store toggle state
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

        /* ── Mobile sidebar logic ── */
        function initMobileSidebar() {
            var toggle = document.getElementById('mobileMenuBtn');
            var sidebar = document.getElementById('studentSidebar');
            var overlay = document.getElementById('sidebarOverlay');
            var body = document.body;

            if (!sidebar) return;

            function open() {
                sidebar.classList.add('active');
                if (overlay) overlay.classList.add('active');
                body.style.overflow = 'hidden';
            }

            function close() {
                sidebar.classList.remove('active');
                if (overlay) overlay.classList.remove('active');
                body.style.overflow = '';
            }

            if (toggle) {
                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    sidebar.classList.contains('active') ? close() : open();
                });
            }

            if (overlay) {
                overlay.addEventListener('click', close);
            }

            // Close sidebar when clicking nav links on mobile
            document.querySelectorAll('.nav-item, .nav-group-toggle, .nav-group-items a').forEach(function(link) {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 767) setTimeout(close, 150);
                });
            });

            // Close on Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') close();
            });

            // Handle resize
            var resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    if (window.innerWidth >= 768) close();
                }, 250);
            });
        }

        function init() {
            initGroups();
            initMobileSidebar();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }
    })();
</script>
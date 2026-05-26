<?php
// msv/staff/includes/staff_sidebar.php - Reusable sidebar component for staff portal
// Fully dynamic school theme integration – reads constants from config.php

// Make sure required variables are available
if (!isset($school_name) || !isset($staff_name) || !isset($staff_role)) {
    $school_name = SCHOOL_NAME ?? 'School';
    $staff_name = $_SESSION['user_name'] ?? 'Staff Member';
    $staff_role = $_SESSION['staff_role'] ?? 'staff';
}

// Get the actual staff_id from the staff table (not the auto-increment id)
global $pdo;
$staff_id_string = '';
if (isset($_SESSION['user_id']) && isset($pdo)) {
    try {
        $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
        $stmt->execute([$_SESSION['user_id'], SCHOOL_ID]);
        $staff_id_string = $stmt->fetchColumn();
        if (!$staff_id_string) {
            $staff_id_string = $_SESSION['staff_id'] ?? $_SESSION['user_id'];
        }
    } catch (Exception $e) {
        $staff_id_string = $_SESSION['staff_id'] ?? $_SESSION['user_id'];
    }
} else {
    $staff_id_string = $_SESSION['staff_id'] ?? ($_SESSION['user_id'] ?? '');
}

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);

// ── School Theme Resolution ──────────────────────────────────────────────────
$sb_primary   = defined('SCHOOL_PRIMARY')   ? SCHOOL_PRIMARY   : '#1e293b';
$sb_secondary = defined('SCHOOL_SECONDARY') ? SCHOOL_SECONDARY : '#3b82f6';
$sb_accent    = defined('SCHOOL_ACCENT')    ? SCHOOL_ACCENT    : '#ffffff';

// Helper: convert hex to RGB string "r,g,b"
function staffHexToRgb(string $hex): string
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

// Helper: adjust color brightness
function staffAdjustBrightness(string $hex, int $percent): string
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

// Helper: get contrast color
function staffGetContrastColor(string $hex): string
{
    $rgb = staffHexToRgb($hex);
    list($r, $g, $b) = explode(',', $rgb);
    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b);
    return ($luminance > 128) ? '#1e293b' : '#ffffff';
}

// ── Derive sidebar styling ──────────────────────────────────────────────
$sb_bg          = staffAdjustBrightness($sb_primary, -12);
$sb_surface     = staffAdjustBrightness($sb_primary, -5);
$sb_hover_bg    = "rgba(" . staffHexToRgb($sb_accent) . ", 0.08)";
$sb_active_bg   = "rgba(" . staffHexToRgb($sb_secondary) . ", 0.18)";
$sb_border      = "rgba(" . staffHexToRgb($sb_accent) . ", 0.10)";

$text_primary   = staffGetContrastColor($sb_primary);
$text_muted     = (staffGetContrastColor($sb_primary) === '#ffffff')
    ? "rgba(255,255,255,0.65)"
    : "rgba(0,0,0,0.65)";
$text_bright    = (staffGetContrastColor($sb_primary) === '#ffffff')
    ? "rgba(255,255,255,0.95)"
    : "rgba(0,0,0,0.95)";

$logo_gradient  = "linear-gradient(135deg, $sb_secondary, $sb_primary)";
?>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<div class="staff-sidebar" id="staffSidebar">

    <!-- Header -->
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
                    <i class="fas fa-chalkboard-teacher"></i>
                <?php endif; ?>
            </div>
            <div class="logo-text">
                <h3 class="school-name"><?php echo htmlspecialchars($school_name); ?></h3>
                <p>Staff Portal</p>
            </div>
        </div>
    </div>

    <!-- Staff Info - CORRECTED: Now shows actual staff_id from staff table -->
    <div class="staff-info">
        <div class="staff-avatar">
            <?php echo strtoupper(substr($staff_name, 0, 1)); ?>
        </div>
        <div class="staff-details">
            <h4><?php echo htmlspecialchars($staff_name); ?></h4>
            <p>Staff ID: <?php echo htmlspecialchars($staff_id_string); ?></p>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav">

        <!-- Dashboard -->
        <a href="index.php" class="nav-item standalone <?php echo $current_page == 'index.php' ? 'active' : ''; ?>">
            <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
            <span class="nav-label">Dashboard</span>
        </a>

        <!-- Students Group -->
        <div class="nav-group <?php echo in_array($current_page, ['manage-students.php', 'view-student.php']) ? 'open' : ''; ?>" data-group="students">
            <button class="nav-group-toggle" aria-expanded="<?php echo in_array($current_page, ['manage-students.php', 'view-student.php']) ? 'true' : 'false'; ?>">
                <span class="nav-icon"><i class="fas fa-users"></i></span>
                <span class="nav-label">Students</span>
                <span class="group-badge">
                    <i class="fas fa-chevron-down chevron"></i>
                </span>
            </button>
            <ul class="nav-group-items <?php echo in_array($current_page, ['manage-students.php', 'view-student.php']) ? 'expanded' : ''; ?>">
                <li>
                    <a href="manage-students.php" class="<?php echo $current_page == 'manage-students.php' ? 'active' : ''; ?>">
                        <i class="fas fa-user-graduate"></i> My Students
                    </a>
                </li>
            </ul>
        </div>

        <!-- Exams & Results Group -->
<div class="nav-group <?php echo in_array($current_page, ['manage-exams.php', 'view-results.php', 'staff_traits_comments.php']) ? 'open' : ''; ?>" data-group="exams">
    <button class="nav-group-toggle" aria-expanded="<?php echo in_array($current_page, ['manage-exams.php', 'view-results.php', 'staff_traits_comments.php']) ? 'true' : 'false'; ?>">
        <span class="nav-icon"><i class="fas fa-file-alt"></i></span>
        <span class="nav-label">Exams & Results</span>
        <span class="group-badge">
            <i class="fas fa-chevron-down chevron"></i>
        </span>
    </button>
    <ul class="nav-group-items <?php echo in_array($current_page, ['manage-exams.php', 'view-results.php', 'staff_traits_comments.php']) ? 'expanded' : ''; ?>">
        <li>
            <a href="manage-exams.php" class="<?php echo $current_page == 'manage-exams.php' ? 'active' : ''; ?>">
                <i class="fas fa-pen-alt"></i> Manage Exams
            </a>
        </li>
        <li>
            <a href="view-results.php" class="<?php echo $current_page == 'view-results.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-bar"></i> View Results
            </a>
        </li>
        <li>
            <a href="staff_traits_comments.php" class="<?php echo $current_page == 'staff_traits_comments.php' ? 'active' : ''; ?>">
                <i class="fas fa-comment-dots"></i> Process Results
            </a>
        </li>
    </ul>
</div>

        <!-- Resources Group (Assignments + Library) -->
        <div class="nav-group <?php echo in_array($current_page, ['assignments.php', 'view-submissions.php', 'edit-assignment.php', 'library.php']) ? 'open' : ''; ?>" data-group="resources">
            <button class="nav-group-toggle" aria-expanded="<?php echo in_array($current_page, ['assignments.php', 'view-submissions.php', 'edit-assignment.php', 'library.php']) ? 'true' : 'false'; ?>">
                <span class="nav-icon"><i class="fas fa-folder-open"></i></span>
                <span class="nav-label">Resources</span>
                <span class="group-badge">
                    <i class="fas fa-chevron-down chevron"></i>
                </span>
            </button>
            <ul class="nav-group-items <?php echo in_array($current_page, ['assignments.php', 'view-submissions.php', 'edit-assignment.php', 'library.php']) ? 'expanded' : ''; ?>">
                <li>
                    <a href="assignments.php" class="<?php echo in_array($current_page, ['assignments.php', 'view-submissions.php', 'edit-assignment.php']) ? 'active' : ''; ?>">
                        <i class="fas fa-tasks"></i> Assignments
                    </a>
                </li>
                <li>
                    <a href="library.php" class="<?php echo $current_page == 'library.php' ? 'active' : ''; ?>">
                        <i class="fas fa-book"></i> Library
                    </a>
                </li>
            </ul>
        </div>

        <!-- Attendance -->
        <a href="attendance.php" class="nav-item standalone <?php echo $current_page == 'attendance.php' ? 'active' : ''; ?>">
            <span class="nav-icon"><i class="fas fa-calendar-check"></i></span>
            <span class="nav-label">Attendance</span>
        </a>

        <!-- Profile -->
        <a href="profile.php" class="nav-item standalone <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>">
            <span class="nav-icon"><i class="fas fa-user-cog"></i></span>
            <span class="nav-label">My Profile</span>
        </a>

        <!-- Logout -->
        <a href="../logout.php" class="nav-item standalone logout">
            <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
            <span class="nav-label">Logout</span>
        </a>

    </nav>
</div>

<style>
    /* ============================================================
   STAFF SIDEBAR — Dynamic School Theme Integration
   ============================================================ */

    /* Theme CSS variables */
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
        --sb-accent-glow: rgba(<?php echo staffHexToRgb($sb_secondary); ?>, 0.18);
        --sb-hover: <?php echo $sb_hover_bg; ?>;
        --sb-active-bg: <?php echo $sb_active_bg; ?>;
        --sb-logo-grad: <?php echo $logo_gradient; ?>;

        --sb-radius: 10px;
        --sb-width: 280px;
        --sb-transition: 0.22s ease;
    }

    /* ---------- Base ---------- */
    .staff-sidebar {
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
        transform: translateX(-100%);
        transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .staff-sidebar.active {
        transform: translateX(0);
        box-shadow: 8px 0 32px rgba(0, 0, 0, 0.5);
    }

    .staff-sidebar::-webkit-scrollbar {
        width: 4px;
    }

    .staff-sidebar::-webkit-scrollbar-track {
        background: transparent;
    }

    .staff-sidebar::-webkit-scrollbar-thumb {
        background: var(--sb-surface);
        border-radius: 4px;
    }

    /* ---------- Header ---------- */
    .sidebar-header {
        padding: 24px 20px 20px;
        border-bottom: 1px solid var(--sb-border);
        flex-shrink: 0;
    }

    .logo {
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .logo-icon {
        width: 52px;
        height: 52px;
        flex-shrink: 0;
        border-radius: 12px;
        background: var(--sb-logo-grad);
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        box-shadow: 0 4px 12px var(--sb-accent-glow);
    }

    .logo-icon img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .logo-icon i {
        font-size: 26px;
        color: #fff;
    }

    .logo-text h3.school-name {
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--sb-text-bright);
        line-height: 1.3;
        white-space: normal;
        word-break: break-word;
        margin: 0 0 4px;
    }

    .logo-text p {
        font-size: 0.8rem;
        color: var(--sb-text);
        text-transform: uppercase;
        letter-spacing: 0.08em;
        margin: 0;
    }

    /* ---------- Staff Info ---------- */
    .staff-info {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 18px 20px;
        border-bottom: 1px solid var(--sb-border);
        flex-shrink: 0;
    }

    .staff-avatar {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background: var(--sb-logo-grad);
        color: #fff;
        font-size: 1.1rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .staff-details h4 {
        font-size: 1rem;
        font-weight: 600;
        color: var(--sb-text-bright);
        margin: 0 0 3px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 170px;
    }

    .staff-details p {
        font-size: 0.75rem;
        color: var(--sb-text);
        margin: 0;
    }

    /* ---------- Nav ---------- */
    .sidebar-nav {
        flex: 1;
        padding: 12px 0 24px;
        display: flex;
        flex-direction: column;
    }

    /* Standalone items */
    .nav-item.standalone {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 12px 20px;
        color: var(--sb-text);
        text-decoration: none;
        font-size: 1rem;
        font-weight: 500;
        transition: background var(--sb-transition), color var(--sb-transition);
        border-radius: 0;
        position: relative;
    }

    .nav-item.standalone:hover {
        background: var(--sb-hover);
        color: var(--sb-text-bright);
    }

    .nav-item.standalone.active {
        background: var(--sb-active-bg);
        color: var(--sb-accent-clr);
    }

    .nav-item.standalone.active::before {
        content: '';
        position: absolute;
        left: 0;
        top: 6px;
        bottom: 6px;
        width: 3px;
        background: var(--sb-accent-clr);
        border-radius: 0 3px 3px 0;
    }

    .nav-item.standalone.logout {
        margin-top: auto;
        border-top: 1px solid var(--sb-border);
    }

    .nav-item.standalone.logout:hover {
        color: #ef4444;
    }

    /* Nav group wrapper */
    .nav-group {
        flex-shrink: 0;
    }

    /* Group toggle button */
    .nav-group-toggle {
        width: 100%;
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 12px 20px;
        background: none;
        border: none;
        cursor: pointer;
        color: var(--sb-text);
        font-size: 1rem;
        font-weight: 500;
        text-align: left;
        transition: background var(--sb-transition), color var(--sb-transition);
        position: relative;
    }

    .nav-group-toggle:hover {
        background: var(--sb-hover);
        color: var(--sb-text-bright);
    }

    .nav-group.open>.nav-group-toggle {
        color: var(--sb-text-bright);
        background: var(--sb-hover);
    }

    .nav-label {
        flex: 1;
    }

    .group-badge {
        display: flex;
        align-items: center;
        flex-shrink: 0;
    }

    .chevron {
        font-size: 0.75rem;
        color: var(--sb-text);
        transition: transform 0.25s ease;
    }

    .nav-group.open .chevron {
        transform: rotate(180deg);
    }

    /* Icon wrapper */
    .nav-icon {
        width: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.05rem;
        flex-shrink: 0;
    }

    /* Group item list */
    .nav-group-items {
        list-style: none;
        margin: 0;
        padding: 0;
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease;
        background: rgba(0, 0, 0, 0.15);
    }

    .nav-group-items.expanded {
        max-height: 500px;
    }

    .nav-group-items li a {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 20px 10px 52px;
        color: var(--sb-text);
        text-decoration: none;
        font-size: 0.95rem;
        transition: background var(--sb-transition), color var(--sb-transition);
        position: relative;
    }

    .nav-group-items li a i {
        font-size: 0.9rem;
        width: 20px;
        text-align: center;
        flex-shrink: 0;
    }

    .nav-group-items li a:hover {
        background: var(--sb-hover);
        color: var(--sb-text-bright);
    }

    .nav-group-items li a.active {
        color: var(--sb-accent-clr);
        background: var(--sb-active-bg);
    }

    .nav-group-items li a.active::before {
        content: '';
        position: absolute;
        left: 0;
        top: 4px;
        bottom: 4px;
        width: 3px;
        background: var(--sb-accent-clr);
        border-radius: 0 3px 3px 0;
    }

    /* Vertical connector line for group items */
    .nav-group.open .nav-group-items {
        border-left: 1px solid var(--sb-border);
        margin-left: 32px;
    }

    .nav-group.open .nav-group-items li a {
        padding-left: 24px;
    }

    .nav-group.open .nav-group-items li a.active::before {
        left: -1px;
    }

    /* ---------- Overlay ---------- */
    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.55);
        z-index: 999;
        backdrop-filter: blur(2px);
    }

    .sidebar-overlay.active {
        display: block;
    }

    /* ---------- Mobile Menu Button ---------- */
    .mobile-menu-btn {
        position: fixed;
        top: 16px;
        left: 16px;
        z-index: 1001;
        width: 44px;
        height: 44px;
        background: var(--sb-primary);
        color: white;
        border: none;
        border-radius: 10px;
        font-size: 20px;
        cursor: pointer;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    /* ---------- Main Content Adjustment ---------- */
    .main-content {
        margin-left: 0;
        padding: 20px;
        min-height: 100vh;
        transition: margin-left 0.28s ease;
    }

    @media (min-width: 768px) {
        .staff-sidebar {
            transform: translateX(0);
        }

        .main-content {
            margin-left: var(--sb-width);
        }

        .mobile-menu-btn {
            display: none;
        }
    }

    @media (max-width: 767px) {
        .main-content {
            padding-top: 70px;
        }
    }
</style>

<script>
    (function() {
        'use strict';

        /* ── Accordion groups ── */
        function initGroups() {
            document.querySelectorAll('.nav-group').forEach(function(group) {
                var toggle = group.querySelector('.nav-group-toggle');
                var items = group.querySelector('.nav-group-items');

                if (!toggle || !items) return;

                toggle.addEventListener('click', function() {
                    var isOpen = group.classList.contains('open');

                    // Close all sibling groups (accordion behaviour)
                    document.querySelectorAll('.nav-group.open').forEach(function(g) {
                        if (g !== group) {
                            g.classList.remove('open');
                            g.querySelector('.nav-group-toggle').setAttribute('aria-expanded', 'false');
                            g.querySelector('.nav-group-items').classList.remove('expanded');
                        }
                    });

                    if (isOpen) {
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

        /* ── Mobile sidebar ── */
        function initMobileSidebar() {
            var toggle = document.getElementById('mobileMenuBtn');
            var sidebar = document.getElementById('staffSidebar');
            var overlay = document.getElementById('sidebarOverlay');
            var body = document.body;

            if (!sidebar || !overlay) return;

            function open() {
                sidebar.classList.add('active');
                overlay.classList.add('active');
                body.style.overflow = 'hidden';
            }

            function close() {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                body.style.overflow = '';
            }

            if (toggle) {
                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    sidebar.classList.contains('active') ? close() : open();
                });
            }

            overlay.addEventListener('click', close);

            // Close sidebar when clicking a nav link on mobile
            document.querySelectorAll('.nav-item.standalone, .nav-group-items a').forEach(function(link) {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 767) setTimeout(close, 150);
                });
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') close();
            });

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
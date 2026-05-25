<?php
// tbis/admin/includes/sidebar.php - Reusable sidebar component with dynamic school theming

// Make sure required variables are available
if (!isset($school_name) || !isset($admin_name) || !isset($admin_role)) {
    $school_name = SCHOOL_NAME ?? 'School';
    $admin_name = $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);

// Load school theme colors from config (if constants are defined)
$school_primary = defined('SCHOOL_PRIMARY') ? SCHOOL_PRIMARY : '#2B3DA0';
$school_secondary = defined('SCHOOL_SECONDARY') ? SCHOOL_SECONDARY : '#2E9E4F';
$school_accent = defined('SCHOOL_ACCENT') ? SCHOOL_ACCENT : '#FFFFFF';

// Subscription status check
if (!isset($subscription_active)) {
    $subscription_active = false;
    $subscription_end_date = '';
    $subscription_days_remaining = 0;

    try {
        global $pdo;
        if (isset($pdo) && defined('SCHOOL_ID')) {
            $stmt = $pdo->prepare("SELECT subscription_status, subscription_expiry FROM schools WHERE id = ?");
            $stmt->execute([SCHOOL_ID]);
            $school_sub = $stmt->fetch();

            if ($school_sub) {
                $subscription_active = ($school_sub['subscription_status'] === 'active');
                $subscription_end_date = $school_sub['subscription_expiry'];

                if ($subscription_end_date && $subscription_end_date !== '0000-00-00') {
                    $expiry_timestamp = strtotime($subscription_end_date);
                    $current_timestamp = time();
                    $subscription_days_remaining = ceil(($expiry_timestamp - $current_timestamp) / (60 * 60 * 24));
                    $subscription_days_remaining = max(0, $subscription_days_remaining);

                    if ($expiry_timestamp < $current_timestamp) {
                        $subscription_active = false;
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Sidebar subscription check error: " . $e->getMessage());
    }
}
?>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar with dynamic theme -->
<div class="sidebar" id="sidebar" data-primary="<?php echo $school_primary; ?>" data-secondary="<?php echo $school_secondary; ?>" data-accent="<?php echo $school_accent; ?>">
    <div class="sidebar-header">
        <div class="logo">
            <div class="logo-icon">
                <?php
                $logo_path = null;
                $logo_locations = [
                    '/tbis/assets/logos/logo.png',
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

                if ($logo_path && file_exists($_SERVER['DOCUMENT_ROOT'] . $logo_path)):
                ?>
                    <img src="<?php echo $logo_path; ?>" alt="<?php echo htmlspecialchars($school_name); ?>">
                <?php else: ?>
                    <i class="fas fa-graduation-cap" style="color: <?php echo $school_accent; ?>;"></i>
                <?php endif; ?>
            </div>
            <div class="logo-text">
                <h3 class="school-name" style="color: <?php echo $school_accent; ?>;"><?php echo htmlspecialchars($school_name); ?></h3>
                <p style="color: <?php echo $school_accent; ?>; opacity: 0.8;">Admin Panel</p>
            </div>
        </div>
    </div>

    <div class="admin-info">
        <h4 style="color: <?php echo $school_accent; ?>;"><?php echo htmlspecialchars($admin_name); ?></h4>
        <p style="color: <?php echo $school_accent; ?>; opacity: 0.7;"><?php echo ucfirst(str_replace('_', ' ', $admin_role)); ?></p>
    </div>

    <!-- Subscription Status -->
    <?php
    $status_class = '';
    $display_days = $subscription_days_remaining ?? 0;

    if (!$subscription_active || $display_days <= 0) {
        $status_class = 'danger';
        $display_days = 0;
    } elseif ($display_days <= 14) {
        $status_class = 'danger';
    } elseif ($display_days <= 30) {
        $status_class = 'warning';
    } else {
        $status_class = 'active';
    }
    ?>
    <div class="subscription-status <?php echo $status_class; ?>">
        <div class="status-label">
            <i class="fas fa-calendar-alt"></i> Subscription
        </div>
        <div class="days-remaining">
            <?php if ($display_days > 0): ?>
                <?php echo $display_days; ?> days
            <?php else: ?>
                EXPIRED
            <?php endif; ?>
        </div>
        <div class="expiry-date">
            <?php if (isset($subscription_end_date) && $subscription_end_date && $subscription_end_date !== '0000-00-00'): ?>
                Expires: <?php echo date('M j, Y', strtotime($subscription_end_date)); ?>
            <?php else: ?>
                No expiry date
            <?php endif; ?>
        </div>
    </div>

    <div class="sidebar-content">
        <ul class="nav-links">
            <li><a href="index.php" class="<?php echo $current_page == 'index.php' ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a></li>
            <li><a href="manage-students.php" class="<?php echo $current_page == 'manage-students.php' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i> Manage Students
                </a></li>
            <li><a href="manage-staff.php" class="<?php echo $current_page == 'manage-staff.php' ? 'active' : ''; ?>">
                    <i class="fas fa-chalkboard-teacher"></i> Manage Staff
                </a></li>
            <li><a href="manage-subjects.php" class="<?php echo $current_page == 'manage-subjects.php' ? 'active' : ''; ?>">
                    <i class="fas fa-book"></i> Manage Subjects
                </a></li>
            <li><a href="manage-classes.php" class="<?php echo $current_page == 'manage-classes.php' ? 'active' : ''; ?>">
                    <i class="fas fa-layer-group"></i> Manage Classes
                </a></li>
            <li><a href="manage-exams.php" class="<?php echo $current_page == 'manage-exams.php' ? 'active' : ''; ?>">
                    <i class="fas fa-file-alt"></i> Manage Exams
                </a></li>
            <li><a href="view-results.php" class="<?php echo $current_page == 'view-results.php' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar"></i> View Results
                </a></li>
            <li><a href="attendance.php" class="<?php echo $current_page == 'attendance.php' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-check"></i> Attendance Reports
                </a></li>
            <li><a href="exam_record_setup.php" class="<?php echo $current_page == 'exam_record_setup.php' ? 'active' : ''; ?>">
                    <i class="fas fa-file-invoice"></i> Process Results
                </a></li>
            <li><a href="ai-tools.php" class="<?php echo $current_page == 'ai-tools.php' ? 'active' : ''; ?>">
                    <i class="fas fa-robot"></i> AI Teaching Tools
                </a></li>
            <li><a href="reports.php" class="<?php echo $current_page == 'reports.php' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-line"></i> Reports
                </a></li>
            <li><a href="sync.php" class="<?php echo $current_page == 'sync.php' ? 'active' : ''; ?>">
                    <i class="fas fa-sync-alt"></i> Sync to Cloud
                </a></li>
            <li><a href="/tbis/logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a></li>
        </ul>
    </div>
</div>

<style>
    /* Base sidebar styles - will be overlaid with dynamic colors via JS */
    .sidebar {
        width: 280px;
        background: var(--sidebar-primary, #2B3DA0);
        color: var(--sidebar-accent, #FFFFFF);
        position: fixed;
        left: 0;
        top: 0;
        height: 100vh;
        z-index: 1000;
        transition: transform 0.3s ease;
        overflow-y: auto;
        box-shadow: 2px 0 10px rgba(0,0,0,0.1);
    }

    .sidebar::-webkit-scrollbar {
        width: 6px;
    }

    .sidebar::-webkit-scrollbar-track {
        background: rgba(255,255,255,0.1);
    }

    .sidebar::-webkit-scrollbar-thumb {
        background: rgba(255,255,255,0.3);
        border-radius: 3px;
    }

    .sidebar-header {
        padding: 20px;
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }

    .logo {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .logo-text .school-name {
        white-space: normal !important;
        word-wrap: break-word;
        line-height: 1.2;
        font-size: 0.9rem;
        margin: 0 0 4px 0;
    }

    .logo-text p {
        margin: 0;
        font-size: 0.7rem;
        opacity: 0.8;
    }

    .logo-icon {
        width: 48px;
        height: 48px;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background: rgba(255, 255, 255, 0.15);
        overflow: hidden;
    }

    .logo-icon img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .logo-icon i {
        font-size: 24px;
    }

    .admin-info {
        padding: 20px;
        text-align: center;
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }

    .admin-info h4 {
        margin: 0 0 5px 0;
        font-size: 1rem;
    }

    .admin-info p {
        margin: 0;
        font-size: 0.8rem;
    }

    /* Subscription Status Styling */
    .subscription-status {
        margin: 15px;
        padding: 12px;
        border-radius: 10px;
        text-align: center;
        background: rgba(255,255,255,0.1);
        backdrop-filter: blur(4px);
    }

    .subscription-status .status-label {
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 5px;
        opacity: 0.9;
    }

    .subscription-status .days-remaining {
        font-size: 1.3rem;
        font-weight: 700;
        margin: 5px 0;
    }

    .subscription-status .expiry-date {
        font-size: 0.7rem;
        opacity: 0.8;
    }

    /* Status colors - keep these semantic */
    .subscription-status.active {
        background: rgba(46, 158, 79, 0.25);
        border-left: 3px solid #2E9E4F;
    }

    .subscription-status.active .days-remaining {
        color: #2E9E4F;
    }

    .subscription-status.warning {
        background: rgba(243, 156, 18, 0.25);
        border-left: 3px solid #f39c12;
    }

    .subscription-status.warning .days-remaining {
        color: #f39c12;
    }

    .subscription-status.danger {
        background: rgba(231, 76, 60, 0.25);
        border-left: 3px solid #e74c3c;
    }

    .subscription-status.danger .days-remaining {
        color: #e74c3c;
    }

    .sidebar-content {
        padding: 10px 0;
    }

    .nav-links {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .nav-links li {
        margin: 5px 15px;
    }

    .nav-links li a {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 15px;
        color: var(--sidebar-accent, #FFFFFF);
        text-decoration: none;
        border-radius: 10px;
        transition: all 0.2s ease;
        font-size: 0.9rem;
    }

    .nav-links li a i {
        width: 20px;
        font-size: 1rem;
        text-align: center;
    }

    .nav-links li a:hover {
        background: rgba(255,255,255,0.15);
        transform: translateX(5px);
    }

    .nav-links li a.active {
        background: var(--sidebar-secondary, #2E9E4F);
        color: white;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }

    /* Sidebar Overlay for mobile */
    .sidebar-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 999;
        display: none;
    }

    .sidebar-overlay.active {
        display: block;
    }

    /* Mobile Responsive */
    @media (max-width: 767px) {
        .sidebar {
            transform: translateX(-100%);
        }

        .sidebar.active {
            transform: translateX(0);
        }
    }

    @media (min-width: 768px) {
        .sidebar {
            transform: translateX(0) !important;
        }
        
        .sidebar-overlay {
            display: none !important;
        }
    }
</style>

<script>
    // Apply dynamic school theme colors to sidebar
    (function() {
        'use strict';

        function applySchoolTheme() {
            const sidebar = document.getElementById('sidebar');
            if (!sidebar) return;

            const primary = sidebar.getAttribute('data-primary') || '#2B3DA0';
            const secondary = sidebar.getAttribute('data-secondary') || '#2E9E4F';
            const accent = sidebar.getAttribute('data-accent') || '#FFFFFF';

            // Set CSS custom properties on sidebar element
            sidebar.style.setProperty('--sidebar-primary', primary);
            sidebar.style.setProperty('--sidebar-secondary', secondary);
            sidebar.style.setProperty('--sidebar-accent', accent);

            // Also set background directly for immediate effect
            sidebar.style.background = primary;

            // Style the active nav link with secondary color
            const style = document.createElement('style');
            style.textContent = `
                .sidebar .nav-links li a.active {
                    background: ${secondary} !important;
                }
                .sidebar .logo-icon {
                    background: rgba(255,255,255,0.2);
                }
                .sidebar .subscription-status.active .days-remaining {
                    color: ${secondary};
                }
            `;
            document.head.appendChild(style);
        }

        // Apply theme as soon as possible
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', applySchoolTheme);
        } else {
            applySchoolTheme();
        }
    })();

    // Mobile menu functionality
    (function() {
        'use strict';

        function initSidebar() {
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const sidebar = document.getElementById('sidebar');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            const body = document.body;

            if (!mobileMenuToggle || !sidebar || !sidebarOverlay) {
                return;
            }

            function openSidebar() {
                sidebar.classList.add('active');
                sidebarOverlay.classList.add('active');
                body.classList.add('menu-open');
                body.style.overflow = 'hidden';
            }

            function closeSidebar() {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
                body.classList.remove('menu-open');
                body.style.overflow = '';
            }

            function toggleSidebar() {
                if (sidebar.classList.contains('active')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            }

            mobileMenuToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                toggleSidebar();
            });

            sidebarOverlay.addEventListener('click', function(e) {
                e.preventDefault();
                closeSidebar();
            });

            const navLinks = document.querySelectorAll('.nav-links a');
            navLinks.forEach(function(link) {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 767) {
                        setTimeout(closeSidebar, 150);
                    }
                });
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && sidebar.classList.contains('active')) {
                    closeSidebar();
                }
            });

            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    if (window.innerWidth >= 768) {
                        sidebar.classList.remove('active');
                        sidebarOverlay.classList.remove('active');
                        body.classList.remove('menu-open');
                        body.style.overflow = '';
                    }
                }, 250);
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initSidebar);
        } else {
            initSidebar();
        }
    })();
</script>

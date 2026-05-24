<?php
// tbis/admin/includes/sidebar.php - Complete standalone sidebar component

// Make sure required variables are available
if (!isset($school_name) || !isset($admin_name) || !isset($admin_role)) {
    // Fallback if variables aren't set
    $school_name = SCHOOL_NAME ?? 'School';
    $admin_name = $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);

// ── SUBSCRIPTION STATUS CHECK ─────────────────────────────────────────────────
// This runs automatically when sidebar is included
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
// ──────────────────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<style>
    /* Sidebar styles */
    .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        width: var(--sidebar-width, 260px);
        height: 100vh;
        background: linear-gradient(180deg, var(--primary-color, #2c3e50), var(--dark-color, #1a252f));
        color: white;
        padding: 20px 0;
        transition: transform 0.3s ease;
        z-index: 1000;
        overflow-y: auto;
        transform: translateX(-100%);
    }

    .sidebar.active {
        transform: translateX(0);
    }

    /* Custom scrollbar for sidebar */
    .sidebar::-webkit-scrollbar {
        width: 5px;
    }

    .sidebar::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.1);
    }

    .sidebar::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.3);
        border-radius: 5px;
    }

    .sidebar-header {
        padding: 0 20px 20px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        margin-bottom: 15px;
    }

    .logo {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .logo-icon {
        width: 48px;
        height: 48px;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: var(--radius-sm, 10px);
        background: rgba(255, 255, 255, 0.15);
        overflow: hidden;
    }

    /* Logo image styling - No squishing */
    .logo-icon img {
        width: auto;
        height: auto;
        max-width: 80%;
        max-height: 80%;
        object-fit: contain;
        display: block;
    }

    .logo-icon i {
        font-size: 24px;
        color: white;
    }

    .logo-text {
        flex: 1;
        min-width: 0;
    }

    .logo-text h3 {
        font-size: 0.95rem;
        font-weight: 600;
        margin: 0;
        line-height: 1.3;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .logo-text p {
        font-size: 0.7rem;
        opacity: 0.8;
        margin: 0;
        line-height: 1.3;
    }

    .admin-info {
        text-align: center;
        padding: 15px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        margin: 15px;
    }

    .admin-info h4 {
        margin: 0 0 5px 0;
        font-size: 0.9rem;
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .admin-info p {
        margin: 0;
        font-size: 0.75rem;
        opacity: 0.8;
    }

    .subscription-status {
        margin: 15px;
        padding: 12px;
        border-radius: 10px;
        background: rgba(255, 255, 255, 0.1);
        text-align: center;
        transition: all 0.3s ease;
    }

    .subscription-status .status-label {
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        opacity: 0.8;
        margin-bottom: 5px;
    }

    .subscription-status .days-remaining {
        font-size: 1.3rem;
        font-weight: 700;
    }

    .subscription-status .expiry-date {
        font-size: 0.7rem;
        opacity: 0.8;
        margin-top: 5px;
    }

    .subscription-status.warning {
        background: rgba(243, 156, 18, 0.3);
        border-left: 3px solid var(--warning-color, #f39c12);
    }

    .subscription-status.danger {
        background: rgba(231, 76, 60, 0.3);
        border-left: 3px solid var(--danger-color, #e74c3c);
        animation: pulseRed 1.5s infinite;
    }

    .subscription-status.active {
        background: rgba(39, 174, 96, 0.2);
        border-left: 3px solid var(--success-color, #27ae60);
    }

    @keyframes pulseRed {
        0% {
            opacity: 1;
        }

        50% {
            opacity: 0.7;
        }

        100% {
            opacity: 1;
        }
    }

    .sidebar-content {
        margin-top: 10px;
    }

    .nav-links {
        list-style: none;
        padding: 0 15px;
        margin: 0;
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
        transition: all 0.3s ease;
        font-size: 0.85rem;
        font-weight: 400;
    }

    .nav-links a:hover {
        background: rgba(255, 255, 255, 0.15);
        transform: translateX(3px);
    }

    .nav-links a.active {
        background: rgba(255, 255, 255, 0.2);
        border-left: 3px solid var(--secondary-color, #3498db);
    }

    .nav-links i {
        width: 20px;
        text-align: center;
        font-size: 1rem;
    }

    /* Mobile Menu Toggle Button - FIXED */
    .mobile-menu-toggle {
        position: fixed;
        top: 15px;
        left: 15px;
        z-index: 1001;
        width: 44px;
        height: 44px;
        background: var(--primary-color, #2c3e50);
        color: white;
        border: none;
        border-radius: 8px;
        font-size: 20px;
        cursor: pointer;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        transition: all 0.3s ease;
    }

    .mobile-menu-toggle:hover {
        background: var(--secondary-color, #3498db);
        transform: scale(1.05);
    }

    .mobile-menu-toggle:active {
        transform: scale(0.95);
    }

    .sidebar-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 999;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }

    .sidebar-overlay.active {
        opacity: 1;
        visibility: visible;
    }

    /* Main content adjustment - add this to your pages */
    .main-content {
        min-height: 100vh;
        padding: 20px;
        transition: margin-left 0.3s ease;
    }

    /* Desktop Styles */
    @media (min-width: 768px) {
        .sidebar {
            transform: translateX(0);
        }

        .mobile-menu-toggle {
            display: none !important;
        }

        .main-content {
            margin-left: var(--sidebar-width, 260px);
        }
    }

    /* Mobile Styles - FIXED */
    @media (max-width: 767px) {
        body {
            overflow-x: hidden;
        }

        .mobile-menu-toggle {
            display: flex !important;
            align-items: center;
            justify-content: center;
        }

        .sidebar {
            transform: translateX(-100%);
            width: 85%;
            max-width: 280px;
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .main-content {
            margin-left: 0 !important;
            padding-top: 70px;
        }

        /* Prevent body scroll when sidebar is open */
        body.menu-open {
            overflow: hidden;
        }

        .logo-text h3 {
            font-size: 0.85rem;
        }

        .admin-info h4 {
            font-size: 0.85rem;
        }

        .nav-links a {
            padding: 10px 12px;
            font-size: 0.8rem;
        }
    }

    /* Small screens */
    @media (max-width: 480px) {
        .sidebar {
            width: 85%;
            max-width: 280px;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
        }

        .logo-text h3 {
            font-size: 0.8rem;
        }
    }
</style>

<!-- Mobile Menu Toggle Button -->
<button class="mobile-menu-toggle" id="mobileMenuToggle">
    <i class="fas fa-bars"></i>
</button>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <div class="logo-icon">
                <?php
                // Check for logo at multiple possible locations
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
                    <i class="fas fa-graduation-cap"></i>
                <?php endif; ?>
            </div>
            <div class="logo-text">
                <h3><?php echo htmlspecialchars($school_name); ?></h3>
                <p>Admin Panel</p>
            </div>
        </div>
    </div>

    <div class="admin-info">
        <h4><?php echo htmlspecialchars($admin_name); ?></h4>
        <p><?php echo ucfirst(str_replace('_', ' ', $admin_role)); ?></p>
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

<script>
    // FIXED: Mobile menu functionality - fully responsive
    (function() {
        'use strict';

        // Wait for DOM to be fully loaded
        document.addEventListener('DOMContentLoaded', function() {
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const sidebar = document.getElementById('sidebar');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            const body = document.body;

            // Check if elements exist
            if (!mobileMenuToggle || !sidebar || !sidebarOverlay) {
                console.warn('Sidebar elements not found');
                return;
            }

            // Function to open sidebar
            function openSidebar() {
                sidebar.classList.add('active');
                sidebarOverlay.classList.add('active');
                body.classList.add('menu-open');
                body.style.overflow = 'hidden';
            }

            // Function to close sidebar
            function closeSidebar() {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
                body.classList.remove('menu-open');
                body.style.overflow = '';
            }

            // Function to toggle sidebar
            function toggleSidebar() {
                if (sidebar.classList.contains('active')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            }

            // Mobile menu toggle click
            mobileMenuToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                toggleSidebar();
            });

            // Overlay click - close sidebar
            sidebarOverlay.addEventListener('click', function(e) {
                e.preventDefault();
                closeSidebar();
            });

            // Close sidebar when clicking a link (on mobile only)
            const navLinks = document.querySelectorAll('.nav-links a');
            navLinks.forEach(function(link) {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 767) {
                        setTimeout(closeSidebar, 150);
                    }
                });
            });

            // Close sidebar when pressing Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && sidebar.classList.contains('active')) {
                    closeSidebar();
                }
            });

            // Handle window resize - reset sidebar state on desktop
            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    if (window.innerWidth >= 768) {
                        // On desktop, ensure sidebar is visible and overlay is hidden
                        sidebar.classList.remove('active');
                        sidebarOverlay.classList.remove('active');
                        body.classList.remove('menu-open');
                        body.style.overflow = '';
                    }
                }, 250);
            });

            // Prevent body scroll when touching sidebar on mobile
            sidebar.addEventListener('touchmove', function(e) {
                if (window.innerWidth <= 767) {
                    e.stopPropagation();
                }
            });

            // Initial check for desktop
            if (window.innerWidth >= 768) {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
            }
        });
    })();
</script>
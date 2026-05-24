<?php
// tbis/admin/includes/sidebar.php - Reusable sidebar component

// Make sure required variables are available
if (!isset($school_name) || !isset($admin_name) || !isset($admin_role)) {
    $school_name = SCHOOL_NAME ?? 'School';
    $admin_name = $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);

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
                <h3 class="school-name"><?php echo htmlspecialchars($school_name); ?></h3>
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

<style>
    /* Additional styles for sidebar - add to your main page CSS or keep here */
    .logo-text .school-name {
        white-space: normal !important;
        word-wrap: break-word;
        line-height: 1.2;
        font-size: 0.9rem;
    }

    /* Ensure logo icon doesn't squish the image */
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
        color: white;
    }
</style>

<script>
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
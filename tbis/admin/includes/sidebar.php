<?php
// tbis/admin/includes/sidebar.php - Reusable sidebar component

// Make sure required variables are available
if (!isset($school_name) || !isset($admin_name) || !isset($admin_role)) {
    // Fallback if variables aren't set
    $school_name = SCHOOL_NAME ?? 'School';
    $admin_name = $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<style>
    /* Sidebar styles - will be merged with your existing CSS */
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

    .sidebar-header {
        padding: 0 20px 20px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .logo {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .logo-icon {
        width: 44px;
        height: 44px;
        background: var(--secondary-color, #3498db);
        border-radius: var(--radius-sm, 8px);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        overflow: hidden;
    }

    .logo-icon img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .logo-text h3 {
        font-size: 1rem;
        font-weight: 600;
        margin: 0;
    }

    .logo-text p {
        font-size: 0.7rem;
        opacity: 0.8;
        margin: 0;
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
    }

    .subscription-status.active {
        background: rgba(39, 174, 96, 0.2);
        border-left: 3px solid var(--success-color, #27ae60);
    }

    .nav-links {
        list-style: none;
        padding: 0 15px;
        margin-top: 10px;
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
    }

    .nav-links a:hover {
        background: rgba(255, 255, 255, 0.15);
    }

    .nav-links a.active {
        background: rgba(255, 255, 255, 0.2);
        border-left: 3px solid var(--secondary-color, #3498db);
    }

    .nav-links i {
        width: 20px;
        text-align: center;
    }

    @media (min-width: 768px) {
        .sidebar {
            transform: translateX(0);
        }
    }
</style>

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
    <?php if (isset($subscription_active)): ?>
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
    <?php endif; ?>

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

<!-- Mobile menu toggle and overlay -->
<button class="mobile-menu-toggle" id="mobileMenuToggle">
    <i class="fas fa-bars"></i>
</button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script>
    // Mobile menu functionality
    (function() {
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        if (mobileMenuToggle && sidebar && sidebarOverlay) {
            mobileMenuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('active');
                sidebarOverlay.classList.toggle('active');
                document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
            });

            sidebarOverlay.addEventListener('click', function() {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
                document.body.style.overflow = '';
            });

            // Close sidebar on mobile when clicking a link
            document.querySelectorAll('.nav-links a').forEach(link => {
                link.addEventListener('click', () => {
                    if (window.innerWidth <= 767) {
                        sidebar.classList.remove('active');
                        sidebarOverlay.classList.remove('active');
                        document.body.style.overflow = '';
                    }
                });
            });
        }
    })();
</script>
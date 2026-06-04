<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Only super_admin and admin can access
requireRole(['super_admin', 'admin']);

// Get statistics for dashboard
$stats = [];

// Get institution settings
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('institution_name', 'app_name', 'app_slogan')");
$stmt->execute();
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$institution_name = $settings['institution_name'] ?? 'University Portal';
$app_slogan = $settings['app_slogan'] ?? 'Excellence in Education';

// Total students
$stmt = $pdo->query("SELECT COUNT(*) as total FROM students");
$stats['students'] = $stmt->fetch()['total'];

// Total staff
$stmt = $pdo->query("SELECT COUNT(*) as total FROM staff");
$stats['staff'] = $stmt->fetch()['total'];

// Total courses
$stmt = $pdo->query("SELECT COUNT(*) as total FROM courses");
$stats['courses'] = $stmt->fetch()['total'];

// Total departments
$stmt = $pdo->query("SELECT COUNT(*) as total FROM departments");
$stats['departments'] = $stmt->fetch()['total'];

// Current session
$stmt = $pdo->query("SELECT * FROM academic_sessions WHERE is_current = 1 LIMIT 1");
$currentSession = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Admin Dashboard - University Portal</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fb;
            overflow-x: hidden;
        }
        
        /* Sidebar styles - visible on desktop by default */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100%;
            background: #1a202c;
            color: white;
            z-index: 1000;
            transition: transform 0.3s ease-in-out;
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            transform: translateX(0);
        }
        
        /* Mobile: sidebar hidden by default */
        @media (max-width: 767px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
        }
        
        .sidebar-header {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid #2d3748;
            background: #1a202c;
        }
        
        .sidebar-header h3 {
            font-size: 18px;
            margin-bottom: 5px;
        }
        
        .sidebar-header p {
            font-size: 12px;
            opacity: 0.8;
        }
        
        .sidebar-nav {
            padding: 15px 0;
        }
        
        .nav-item {
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #cbd5e0;
            text-decoration: none;
            transition: all 0.25s;
            font-size: 14px;
            border-left: 3px solid transparent;
        }
        
        .nav-item:hover {
            background: #2d3748;
            color: white;
            border-left-color: #667eea;
        }
        
        .nav-item.active {
            background: #2d3748;
            color: white;
            border-left-color: #667eea;
        }
        
        /* Main content area */
        .main-content {
            margin-left: 260px;
            padding: 20px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
        }
        
        /* Mobile: full width */
        @media (max-width: 767px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
        }
        
        /* Top bar */
        .top-bar {
            background: white;
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
            flex-wrap: wrap;
            gap: 10px;
        }
        
        /* Hamburger menu - only visible on mobile */
        .menu-toggle {
            display: none;
            background: #667eea;
            border: none;
            color: white;
            font-size: 22px;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: 8px;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
            line-height: 1;
        }
        
        @media (max-width: 767px) {
            .menu-toggle {
                display: inline-flex;
            }
        }
        
        .menu-toggle:hover {
            background: #5a67d8;
        }
        
        .welcome-text {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            flex: 1;
            word-break: break-word;
        }
        
        @media (max-width: 767px) {
            .welcome-text {
                font-size: 14px;
                margin-left: 5px;
            }
        }
        
        .logout-btn {
            background: #e53e3e;
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .logout-btn:hover {
            background: #c53030;
        }
        
        /* Stats grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 767px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 14px rgba(0,0,0,0.08);
        }
        
        .stat-title {
            color: #718096;
            font-size: 14px;
            margin-bottom: 10px;
            letter-spacing: 0.3px;
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: 800;
            color: #2d3748;
        }
        
        @media (max-width: 767px) {
            .stat-number {
                font-size: 28px;
            }
        }
        
        /* Quick actions section */
        .quick-actions {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            margin-bottom: 20px;
        }
        
        .quick-actions h3 {
            margin-bottom: 18px;
            color: #2d3748;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .action-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }
        
        @media (max-width: 767px) {
            .action-buttons {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 10px;
            }
        }
        
        .action-btn {
            background: #667eea;
            color: white;
            padding: 12px;
            text-align: center;
            text-decoration: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .action-btn:hover {
            background: #5a67d8;
            transform: translateY(-2px);
        }
        
        /* Session banner */
        .session-banner {
            margin-top: 20px;
            padding: 15px 18px;
            border-radius: 12px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .session-info {
            background: #e9f5ff;
            border-left: 4px solid #3182ce;
        }
        
        .session-warning {
            background: #fff3cd;
            border-left: 4px solid #ecc94b;
        }
        
        /* Overlay for mobile sidebar */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 999;
            display: none;
        }
        
        .sidebar-overlay.active {
            display: block;
        }
        
        @media (min-width: 768px) {
            .sidebar-overlay {
                display: none !important;
            }
        }
        
        /* Scrollbar styling */
        .sidebar::-webkit-scrollbar {
            width: 5px;
        }
        
        .sidebar::-webkit-scrollbar-track {
            background: #2d3748;
        }
        
        .sidebar::-webkit-scrollbar-thumb {
            background: #667eea;
            border-radius: 5px;
        }
        
        /* Footer */
        .footer-note {
            margin-top: 30px;
            text-align: center;
            font-size: 12px;
            color: #a0aec0;
            padding: 15px 0;
        }
        
        /* Touch-friendly improvements */
        .nav-item, .action-btn, .logout-btn, .menu-toggle {
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }
    </style>
</head>
<body>
    <!-- Sidebar Overlay (mobile only) -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Sidebar Navigation -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h3>🏫 <?php echo htmlspecialchars($institution_name); ?></h3>
            <p>Admin Portal</p>
        </div>
        
         <div class="sidebar-nav">
            <a href="#" class="nav-item active">📊 Dashboard</a>
            <a href="faculties.php" class="nav-item">🏛️ Faculties</a>
            <a href="departments.php" class="nav-item">📚 Departments</a>
            <a href="courses.php" class="nav-item">📖 Courses</a>
            <a href="staff.php" class="nav-item">👨‍🏫 Staff Management</a>
            <a href="students.php" class="nav-item">👨‍🎓 Student Management</a>
            <a href="parents.php" class="nav-item">👪 Parent Management</a>
            <a href="attendance_reports.php" class="nav-item">📅 Attendance Reports</a>
             <a href="registration_control.php" class="nav-item">🎛️ Registration Control</a>
            <a href="result_reports.php" class="nav-item">📊 Result Reports</a>
             <a href="approve_results.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'approve_results.php' ? 'active' : ''; ?>">✅ Approve Results</a>
            <a href="settings.php" class="nav-item">⚙️ Settings</a>
        </div>
    </div>
    
    <div class="main-content" id="mainContent">
        <div class="top-bar">
            <button class="menu-toggle" id="menuToggle" aria-label="Menu">
                ☰
            </button>
            <div class="welcome-text">
                👋 Welcome, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?> 
                (<?php echo ucfirst($_SESSION['role'] ?? 'admin'); ?>)
            </div>
            <a href="../logout.php" class="logout-btn">
                🚪 Logout
            </a>
        </div>
        
        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-title">👨‍🎓 Total Students</div>
                <div class="stat-number"><?php echo number_format($stats['students'] ?? 0); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-title">👨‍🏫 Total Staff</div>
                <div class="stat-number"><?php echo number_format($stats['staff'] ?? 0); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-title">📖 Active Courses</div>
                <div class="stat-number"><?php echo number_format($stats['courses'] ?? 0); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-title">🏛️ Departments</div>
                <div class="stat-number"><?php echo number_format($stats['departments'] ?? 0); ?></div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <h3>
                <span>⚡</span> Quick Actions
            </h3>
            <div class="action-buttons">
                <a href="students.php?action=add" class="action-btn">➕ Add Student</a>
                <a href="staff.php?action=add" class="action-btn">👨‍🏫 Add Staff</a>
                <a href="courses.php?action=add" class="action-btn">📖 Create Course</a>
                <a href="departments.php?action=add" class="action-btn">🏛️ Add Dept</a>
                <a href="generate_id_cards.php" class="action-btn">🪪 ID Cards</a>
                <a href="reports.php" class="action-btn">📈 Reports</a>
            </div>
        </div>
        
        <!-- Academic Session Info -->
        <?php if ($currentSession && !empty($currentSession['name'])): ?>
            <div class="session-banner session-info">
                <span>📅</span>
                <strong>Current Academic Session:</strong> <?php echo htmlspecialchars($currentSession['name']); ?>
            </div>
        <?php else: ?>
            <div class="session-banner session-warning">
                <span>⚠️</span>
                No active academic session set. Please go to <strong>Settings</strong> to configure.
            </div>
        <?php endif; ?>
        
        <div class="footer-note">
            University Portal v2.0 • Admin Dashboard
        </div>
    </div>
    
    <script>
        // Mobile sidebar functionality
        (function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const menuToggle = document.getElementById('menuToggle');
            
            // Check if we're on mobile
            function isMobile() {
                return window.innerWidth < 768;
            }
            
            // Close sidebar
            function closeSidebar() {
                if (sidebar && isMobile()) {
                    sidebar.classList.remove('open');
                    if (overlay) overlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            }
            
            // Open sidebar
            function openSidebar() {
                if (sidebar && isMobile()) {
                    sidebar.classList.add('open');
                    if (overlay) overlay.classList.add('active');
                    document.body.style.overflow = 'hidden';
                }
            }
            
            // Toggle sidebar
            function toggleSidebar() {
                if (sidebar && sidebar.classList.contains('open')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            }
            
            // Event listener for menu button
            if (menuToggle) {
                menuToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            // Close sidebar when clicking overlay
            if (overlay) {
                overlay.addEventListener('click', function() {
                    closeSidebar();
                });
            }
            
            // Close sidebar when clicking nav links on mobile
            const navLinks = document.querySelectorAll('.nav-item');
            navLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (isMobile()) {
                        setTimeout(() => {
                            closeSidebar();
                        }, 100);
                    }
                });
            });
            
            // Handle window resize
            window.addEventListener('resize', function() {
                if (!isMobile()) {
                    // On desktop, ensure sidebar is visible and no overlay
                    if (sidebar) {
                        sidebar.classList.remove('open');
                    }
                    if (overlay) {
                        overlay.classList.remove('active');
                    }
                    document.body.style.overflow = '';
                }
            });
            
            // Close on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && isMobile()) {
                    closeSidebar();
                }
            });
        })();
        
        // Highlight active nav item based on current page
        (function highlightCurrentNav() {
            const currentPath = window.location.pathname;
            const currentFile = currentPath.split('/').pop();
            const navItems = document.querySelectorAll('.nav-item');
            
            navItems.forEach(item => {
                const href = item.getAttribute('href');
                if (href && href !== '#') {
                    const hrefFile = href.split('/').pop();
                    if (currentFile === hrefFile) {
                        navItems.forEach(nav => nav.classList.remove('active'));
                        item.classList.add('active');
                    }
                } else if (href === '#' && (currentFile === 'admin_dashboard.php' || currentFile === 'dashboard.php' || currentFile === '')) {
                    item.classList.add('active');
                }
            });
        })();
    </script>
</body>
</html>
<?php
// gos/admin/index.php - Admin Dashboard (Aligned with offline version)
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../includes/config.php';

// Check if admin is logged in (support both offline and online session styles)
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /gos/login.php");
    exit();
}

// Get admin info from session
if (isset($_SESSION['admin_id'])) {
    $admin_id = $_SESSION['admin_id'];
    $admin_name = $_SESSION['admin_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'super_admin';
} else {
    $admin_id = $_SESSION['user_id'];
    $admin_name = $_SESSION['user_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;

// Get statistics for this school only
try {
    // Total Students
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM students WHERE school_id = ? AND status = 'active'");
    $stmt->execute([$school_id]);
    $total_students = $stmt->fetch()['total'];

    // Total Staff
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM staff WHERE school_id = ? AND is_active = 1");
    $stmt->execute([$school_id]);
    $total_staff = $stmt->fetch()['total'];

    // Total Exams
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM exams WHERE school_id = ?");
    $stmt->execute([$school_id]);
    $total_exams = $stmt->fetch()['total'];

    // Total Subjects
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM subjects WHERE school_id = ?");
    $stmt->execute([$school_id]);
    $total_subjects = $stmt->fetch()['total'];

    // Recent Activity Logs
    $stmt = $pdo->prepare("
        SELECT al.*, 
               CASE 
                   WHEN al.user_type = 'student' THEN s.full_name
                   WHEN al.user_type = 'staff' THEN st.full_name
                   WHEN al.user_type = 'admin' THEN a.full_name
                   ELSE 'Unknown User'
               END as user_name
        FROM activity_logs al
        LEFT JOIN students s ON al.user_id = s.id AND al.user_type = 'student' AND s.school_id = ?
        LEFT JOIN staff st ON al.user_id = st.id AND al.user_type = 'staff' AND st.school_id = ?
        LEFT JOIN admin_users a ON al.user_id = a.id AND al.user_type = 'admin' AND a.school_id = ?
        WHERE al.school_id = ?
        ORDER BY al.created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$school_id, $school_id, $school_id, $school_id]);
    $recent_activities = $stmt->fetchAll();

    // Recent Exams
    $stmt = $pdo->prepare("
        SELECT e.*, s.subject_name 
        FROM exams e 
        LEFT JOIN subjects s ON e.subject_id = s.id 
        WHERE e.school_id = ?
        ORDER BY e.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$school_id]);
    $recent_exams = $stmt->fetchAll();

    // Recent Results
    $stmt = $pdo->prepare("
        SELECT r.*, stu.full_name as student_name, e.exam_name 
        FROM results r 
        JOIN students stu ON r.student_id = stu.id 
        JOIN exams e ON r.exam_id = e.id 
        WHERE stu.school_id = ?
        ORDER BY r.submitted_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$school_id]);
    $recent_results = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Admin dashboard error: " . $e->getMessage());
    $error_message = "Error loading dashboard data";
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $school_name; ?> - Admin Dashboard</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: <?php echo $secondary_color; ?>;
            --accent-color: #e74c3c;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --sidebar-width: 260px;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.1);
            --radius-sm: 8px;
            --radius-md: 12px;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            color: #333;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-color), var(--dark-color));
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
            background: var(--secondary-color);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .logo-text h3 {
            font-size: 1rem;
            font-weight: 600;
        }

        .logo-text p {
            font-size: 0.7rem;
            opacity: 0.8;
        }

        .admin-info {
            text-align: center;
            padding: 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            margin: 15px;
        }

        .nav-links {
            list-style: none;
            padding: 0 15px;
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
            transition: var(--transition);
        }

        .nav-links a:hover {
            background: rgba(255, 255, 255, 0.15);
        }

        .nav-links a.active {
            background: rgba(255, 255, 255, 0.2);
            border-left: 3px solid var(--secondary-color);
        }

        .nav-links i {
            width: 20px;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            min-height: 100vh;
            padding: 20px;
            transition: var(--transition);
        }

        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            width: 44px;
            height: 44px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 20px;
            cursor: pointer;
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
            transition: var(--transition);
        }

        .sidebar-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        /* Top Header */
        .top-header {
            background: white;
            padding: 20px;
            border-radius: var(--radius-md);
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: var(--shadow-sm);
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .header-title p {
            color: #666;
            font-size: 0.85rem;
        }

        .logout-btn {
            background: var(--danger-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            border-top: 4px solid;
        }

        .stat-card.students {
            border-top-color: var(--secondary-color);
        }

        .stat-card.staff {
            border-top-color: var(--warning-color);
        }

        .stat-card.exams {
            border-top-color: var(--success-color);
        }

        .stat-card.subjects {
            border-top-color: var(--accent-color);
        }

        .stat-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: white;
        }

        .stat-card.students .stat-icon {
            background: var(--secondary-color);
        }

        .stat-card.staff .stat-icon {
            background: var(--warning-color);
        }

        .stat-card.exams .stat-icon {
            background: var(--success-color);
        }

        .stat-card.subjects .stat-icon {
            background: var(--accent-color);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
        }

        .stat-label {
            font-size: 0.85rem;
            color: #666;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .content-card {
            background: white;
            border-radius: var(--radius-md);
            padding: 20px;
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--light-color);
        }

        .card-header h3 {
            color: var(--primary-color);
            font-size: 1.1rem;
        }

        .card-header a {
            color: var(--secondary-color);
            text-decoration: none;
            font-size: 0.8rem;
        }

        /* Tables */
        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #eee;
            font-size: 0.85rem;
        }

        .data-table th {
            background: var(--light-color);
            font-weight: 600;
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .status-active {
            background: #d5f4e6;
            color: var(--success-color);
        }

        .status-inactive {
            background: #f8d7da;
            color: var(--danger-color);
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .action-btn {
            background: white;
            border: 2px solid var(--light-color);
            border-radius: var(--radius-sm);
            padding: 15px;
            text-align: center;
            text-decoration: none;
            color: var(--primary-color);
            transition: var(--transition);
        }

        .action-btn:hover {
            border-color: var(--secondary-color);
            transform: translateY(-3px);
        }

        .action-icon {
            font-size: 24px;
            margin-bottom: 8px;
        }

        .action-text {
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* Activity List */
        .activity-list {
            list-style: none;
        }

        .activity-item {
            padding: 12px 0;
            border-bottom: 1px solid var(--light-color);
            display: flex;
            gap: 12px;
        }

        .activity-icon {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .activity-icon.student {
            background: rgba(52, 152, 219, 0.1);
            color: var(--secondary-color);
        }

        .activity-content {
            flex: 1;
        }

        .activity-content h4 {
            font-size: 0.85rem;
            margin-bottom: 4px;
        }

        .activity-content p {
            font-size: 0.75rem;
            color: #666;
        }

        .activity-time {
            font-size: 0.7rem;
            color: #999;
        }

        .dashboard-footer {
            text-align: center;
            padding: 20px;
            color: #666;
            font-size: 0.8rem;
            border-top: 1px solid var(--light-color);
            margin-top: 20px;
        }

        @media (min-width: 768px) {

            .mobile-menu-toggle,
            .sidebar-overlay {
                display: none;
            }

            .sidebar {
                transform: translateX(0);
            }

            .main-content {
                margin-left: var(--sidebar-width);
            }
        }

        @media (max-width: 767px) {
            .main-content {
                padding-top: 70px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .content-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="logo-text">
                    <h3><?php echo $school_name; ?></h3>
                    <p>Admin Panel</p>
                </div>
            </div>
        </div>

        <div class="admin-info">
            <h4><?php echo htmlspecialchars($admin_name); ?></h4>
            <p><?php echo ucfirst(str_replace('_', ' ', $admin_role)); ?></p>
        </div>

        <div class="sidebar-content">
            <ul class="nav-links">
                <li><a href="index.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="manage-students.php"><i class="fas fa-users"></i> Manage Students</a></li>
                <li><a href="manage-staff.php"><i class="fas fa-chalkboard-teacher"></i> Manage Staff</a></li>
                <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Manage Subjects</a></li>
                <li><a href="manage-classes.php"><i class="fas fa-book"></i> Manage Classes</a></li>
                <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
                <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
                <li><a href="attendance.php"><i class="fas fa-calendar-check"></i> Attendance Reports</a></li>
                <li><a href="exam_record_setup.php"><i class="fas fa-calendar-check"></i> Process Results</a></li>
                <li><a href="ai-tools.php"><i class="fas fa-robot"></i> AI Teaching Tools</a></li>
                <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
                <li><a href="sync.php"><i class="fas fa-sync-alt"></i> Sync to Cloud</a></li>
                <li><a href="/ida/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="top-header">
            <div class="header-title">
                <h1>Admin Dashboard</h1>
                <p>Welcome back, <?php echo htmlspecialchars($admin_name); ?>!</p>
            </div>
            <div class="header-actions">
                <button class="logout-btn" onclick="window.location.href='/gos/logout.php'">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card students">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value"><?php echo $total_students ?? 0; ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card staff">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value"><?php echo $total_staff ?? 0; ?></div>
                        <div class="stat-label">Total Staff</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card exams">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value"><?php echo $total_exams ?? 0; ?></div>
                        <div class="stat-label">Total Exams</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card subjects">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value"><?php echo $total_subjects ?? 0; ?></div>
                        <div class="stat-label">Total Subjects</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-book"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <div class="content-card">
                <div class="card-header">
                    <h3>Quick Actions</h3>
                </div>
                <div class="quick-actions">
                    <a href="manage-students.php" class="action-btn">
                        <div class="action-icon"><i class="fas fa-user-plus"></i></div>
                        <div class="action-text">Add Student</div>
                    </a>
                    <a href="manage-staff.php" class="action-btn">
                        <div class="action-icon"><i class="fas fa-user-tie"></i></div>
                        <div class="action-text">Add Staff</div>
                    </a>
                    <a href="manage-exams.php" class="action-btn">
                        <div class="action-icon"><i class="fas fa-plus-circle"></i></div>
                        <div class="action-text">Create Exam</div>
                    </a>
                    <a href="sync.php" class="action-btn">
                        <div class="action-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                        <div class="action-text">Sync to Cloud</div>
                    </a>
                </div>
            </div>

            <div class="content-card">
                <div class="card-header">
                    <h3>Recent Activities</h3>
                    <a href="reports.php">View All</a>
                </div>
                <ul class="activity-list">
                    <?php if (!empty($recent_activities)): ?>
                        <?php foreach ($recent_activities as $activity): ?>
                            <li class="activity-item">
                                <div class="activity-icon <?php echo $activity['user_type']; ?>">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div class="activity-content">
                                    <h4><?php echo htmlspecialchars($activity['user_name'] ?? 'Unknown'); ?></h4>
                                    <p><?php echo htmlspecialchars($activity['activity']); ?></p>
                                    <div class="activity-time">
                                        <?php echo date('M d, H:i', strtotime($activity['created_at'])); ?>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li style="text-align:center; color:#999; padding:20px;">No recent activities</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <!-- Recent Exams -->
        <div class="content-card">
            <div class="card-header">
                <h3>Recent Exams</h3>
                <a href="manage-exams.php">View All</a>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Exam Name</th>
                            <th>Subject</th>
                            <th>Class</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recent_exams)): ?>
                            <?php foreach ($recent_exams as $exam): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($exam['exam_name']); ?></td>
                                    <td><?php echo htmlspecialchars($exam['subject_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($exam['class']); ?></td>
                                    <td><span class="status-badge <?php echo $exam['is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $exam['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align:center;">No exams found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer -->
        <div class="dashboard-footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo $school_name; ?> - Online Portal</p>
        </div>
    </div>

    <script>
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('active');
                sidebarOverlay.classList.toggle('active');
                document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
            });
        }

        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', function() {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
                document.body.style.overflow = '';
            });
        }

        document.querySelectorAll('.nav-links a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 767) {
                    sidebar.classList.remove('active');
                    sidebarOverlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
        });
    </script>
</body>

</html>
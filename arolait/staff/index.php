<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole(['staff']);

// Get institution settings
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('institution_name', 'app_name', 'app_slogan')");
$stmt->execute();
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$institution_name = $settings['institution_name'] ?? 'University Portal';
$app_slogan = $settings['app_slogan'] ?? 'Excellence in Education';



// Get staff information with faculty
$stmt = $pdo->prepare("
    SELECT s.*, 
           d.name as department_name,
           f.name as faculty_name
    FROM staff s
    JOIN departments d ON s.department_id = d.id
    JOIN faculties f ON d.faculty_id = f.id
    WHERE s.id = ?
");
$stmt->execute([$_SESSION['staff_id']]);
$staff = $stmt->fetch();


// Store staff_id in session if not already set
if (!isset($_SESSION['staff_id'])) {
    $_SESSION['staff_id'] = $staff['id'];
}

// Get courses assigned to this staff from course_offerings
$stmt = $pdo->prepare("
    SELECT 
        c.*, 
        d.name as department_name,
        s.name as semester_name,
        a.name as session_name,
        COUNT(DISTINCT scr.student_id) as enrolled_students
    FROM course_offerings co
    JOIN courses c ON co.course_id = c.id
    JOIN departments d ON c.department_id = d.id
    JOIN semesters s ON co.semester_id = s.id
    JOIN academic_sessions a ON s.session_id = a.id
    LEFT JOIN student_course_registrations scr ON co.id = scr.offering_id AND scr.status = 'registered'
    WHERE co.lecturer_id = ? AND s.is_current = 1
    GROUP BY c.id, co.id
    ORDER BY c.code
");
$stmt->execute([$_SESSION['user_id']]);
$courses = $stmt->fetchAll();

// Get today's attendance summary
$today = date('Y-m-d');
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total_taken
    FROM attendance
    WHERE staff_id = ? AND date = ?
");
$stmt->execute([$_SESSION['user_id'], $today]);
$today_attendance = $stmt->fetch();

// Get recent activities
$stmt = $pdo->prepare("
    SELECT 'attendance' as type, COUNT(*) as count, date
    FROM attendance
    WHERE staff_id = ? AND date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY date
    ORDER BY date DESC
    LIMIT 5
");
$stmt->execute([$_SESSION['user_id']]);
$recent_activity = $stmt->fetchAll();

// Get pending results to approve (if staff is HOD or Dean)
$pending_results = 0;
if ($staff['designation'] == 'HOD' || $staff['designation'] == 'Dean') {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as pending
        FROM results r
        JOIN courses c ON r.course_id = c.id
        WHERE c.department_id = ? AND r.is_approved = 0
    ");
    $stmt->execute([$staff['department_id']]);
    $pending_results = $stmt->fetch()['pending'];
}

// Get total enrolled students across all staff courses
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT scr.student_id) as total_students
    FROM course_offerings co
    JOIN student_course_registrations scr ON co.id = scr.offering_id
    WHERE co.lecturer_id = ? AND scr.status = 'registered'
");
$stmt->execute([$_SESSION['user_id']]);
$total_students = $stmt->fetch()['total_students'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Staff Dashboard - University Portal</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f5f7fb;
        }
        
        /* Sidebar */
        .sidebar {
            width: 260px;
            background: #2d3748;
            color: white;
            position: fixed;
            height: 100%;
            overflow-y: auto;
            transition: transform 0.3s ease;
            z-index: 100;
        }
        
        .sidebar-header {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid #4a5568;
        }
        
        .sidebar-header h3 {
            font-size: 18px;
        }
        
        .sidebar-header p {
            font-size: 12px;
            opacity: 0.8;
            margin-top: 5px;
        }
        
        .sidebar-nav {
            padding: 20px 0;
        }
        
        .nav-item {
            padding: 12px 20px;
            display: block;
            color: #cbd5e0;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .nav-item:hover {
            background: #4a5568;
            color: white;
            padding-left: 25px;
        }
        
        .nav-item.active {
            background: #667eea;
            color: white;
        }
        
        /* Main Content */
        .main-content {
            margin-left: 260px;
            padding: 20px;
            min-height: 100vh;
        }
        
        /* Top Bar */
        .top-bar {
            background: white;
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .welcome-text h2 {
            font-size: 18px;
            color: #2d3748;
        }
        
        .welcome-text p {
            font-size: 12px;
            color: #718096;
            margin-top: 4px;
        }
        
        .staff-badge {
            background: #667eea;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
        }
        
        .logout-btn {
            background: #e53e3e;
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            transition: background 0.3s;
        }
        
        .logout-btn:hover {
            background: #c53030;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
        }
        
        .stat-title {
            font-size: 13px;
            color: #718096;
            margin-bottom: 8px;
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #2d3748;
        }
        
        .stat-unit {
            font-size: 12px;
            color: #718096;
            margin-left: 5px;
        }
        
        /* Cards Grid */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            font-weight: 600;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .course-item {
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .course-item:last-child {
            border-bottom: none;
        }
        
        .course-code {
            font-weight: 600;
            color: #2d3748;
        }
        
        .course-title {
            font-size: 13px;
            color: #718096;
            margin: 4px 0;
        }
        
        .course-stats {
            display: flex;
            gap: 15px;
            margin-top: 8px;
            font-size: 12px;
        }
        
        .course-stats span {
            color: #667eea;
            font-weight: 600;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5a67d8;
        }
        
        .btn-success {
            background: #48bb78;
            color: white;
        }
        
        .btn-warning {
            background: #ed8936;
            color: white;
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid #667eea;
            color: #667eea;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            flex-wrap: wrap;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .action-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            text-decoration: none;
            transition: all 0.3s;
            border: 1px solid #e2e8f0;
        }
        
        .action-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border-color: #667eea;
        }
        
        .action-icon {
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .action-title {
            font-weight: 600;
            color: #2d3748;
        }
        
        .action-desc {
            font-size: 12px;
            color: #718096;
            margin-top: 5px;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                z-index: 1000;
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .menu-toggle {
                display: block;
                position: fixed;
                top: 15px;
                left: 15px;
                z-index: 1001;
                background: #667eea;
                color: white;
                border: none;
                padding: 10px;
                border-radius: 8px;
                cursor: pointer;
            }
            
            .top-bar {
                margin-top: 50px;
            }
        }
        
        @media (min-width: 769px) {
            .menu-toggle {
                display: none;
            }
        }
        
        .flash-message {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 8px;
            color: white;
            z-index: 2000;
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .flash-success { background: #48bb78; }
        .flash-error { background: #f56565; }
    </style>
</head>
<body>
    <button class="menu-toggle" onclick="toggleSidebar()">☰ Menu</button>
    
    <div class="sidebar" id="sidebar">
<div class="sidebar-header">
            <h3>🏫 <?php echo htmlspecialchars($institution_name); ?></h3>
            <p>Staff Portal</p>
        </div>
     

<div class="sidebar-nav">
    <a href="index.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">📊 Dashboard</a>
    <a href="my_courses.php" class="nav-item">📖 My Courses</a>
    <a href="take_attendance.php" class="nav-item">✅ Take Attendance</a>
    <a href="attendance_history.php" class="nav-item">📅 Attendance History</a>
    <a href="upload_results.php" class="nav-item">📝 Upload Results</a>
    <a href="my_results.php" class="nav-item">📊 My Results</a>
    <a href="student_list.php" class="nav-item">👨‍🎓 Student List</a>
    
    <?php 
    // Check if staff is a Dean
    $stmt = $pdo->prepare("SELECT designation FROM staff WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $staff_role = $stmt->fetch();
    if ($staff_role && $staff_role['designation'] == 'Dean'): 
    ?>
        <a href="../admin/approve_results.php" class="nav-item">✅ Approve Results (Dean)</a>
    <?php endif; ?>
    
    <a href="../logout.php" class="nav-item">🚪 Logout</a>
</div>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <div class="welcome-text">
    <h2>Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</h2>
    <p>
        <?php echo htmlspecialchars($staff['designation'] ?: 'Staff'); ?> | 
        Faculty of <?php echo htmlspecialchars($staff['faculty_name']); ?> | 
        Department of <?php echo htmlspecialchars($staff['department_name']); ?>
    </p>
</div>
            <div>
                <span class="staff-badge">ID: <?php echo $_SESSION['staff_number']; ?></span>
                <a href="../logout.php" class="logout-btn">🚪 Logout</a>
            </div>
        </div>
        
        <!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-title">📖 My Courses</div>
        <div class="stat-number"><?php echo count($courses); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-title">👨‍🎓 Total Students</div>
        <div class="stat-number"><?php echo $total_students; ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-title">✅ Today's Attendance</div>
        <div class="stat-number"><?php echo $today_attendance['total_taken'] ?? 0; ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-title">⏳ Pending Results</div>
        <div class="stat-number"><?php echo $pending_results; ?></div>
    </div>
</div>
        
        <!-- My Courses -->
        <div class="card">
            <div class="card-header">📖 My Assigned Courses</div>
            <div class="card-body">
                <?php if(empty($courses)): ?>
                    <p style="text-align: center; color: #718096;">No courses assigned yet. Contact your HOD.</p>
                <?php else: ?>
                    <?php foreach($courses as $course): ?>
                        <div class="course-item">
                            <div class="course-code"><?php echo htmlspecialchars($course['code']); ?></div>
                            <div class="course-title"><?php echo htmlspecialchars($course['title']); ?></div>
                            <div class="course-stats">
                                <span>📊 <?php echo $course['credit_unit']; ?> Units</span>
                                <span>👨‍🎓 <?php echo $course['enrolled_students']; ?> Students</span>
                            </div>
                            <div class="action-buttons">
                                <a href="take_attendance.php?course_id=<?php echo $course['id']; ?>" class="btn btn-primary">✅ Take Attendance</a>
                                <a href="upload_results.php?course_id=<?php echo $course['id']; ?>" class="btn btn-success">📝 Upload Results</a>
                                <a href="view_course_attendance.php?course_id=<?php echo $course['id']; ?>" class="btn btn-outline">📊 View Report</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="take_attendance.php" class="action-card">
                <div class="action-icon">✅</div>
                <div class="action-title">Take Attendance</div>
                <div class="action-desc">Mark student attendance using QR code</div>
            </a>
            <a href="upload_results.php" class="action-card">
                <div class="action-icon">📝</div>
                <div class="action-title">Upload Results</div>
                <div class="action-desc">Enter CA and Exam scores</div>
            </a>
            <a href="attendance_history.php" class="action-card">
                <div class="action-icon">📅</div>
                <div class="action-title">Attendance History</div>
                <div class="action-desc">View past attendance records</div>
            </a>
            <a href="student_list.php" class="action-card">
                <div class="action-icon">👨‍🎓</div>
                <div class="action-title">Student List</div>
                <div class="action-desc">View students in your courses</div>
            </a>
        </div>
    </div>
    
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
        }
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.querySelector('.menu-toggle');
            if (window.innerWidth <= 768 && sidebar.classList.contains('open')) {
                if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });
        
        // Show flash message
        const urlParams = new URLSearchParams(window.location.search);
        const message = urlParams.get('message');
        const error = urlParams.get('error');
        
        if(message) {
            const flash = document.createElement('div');
            flash.className = 'flash-message flash-success';
            flash.innerHTML = message;
            document.body.appendChild(flash);
            setTimeout(() => flash.remove(), 3000);
        }
        if(error) {
            const flash = document.createElement('div');
            flash.className = 'flash-message flash-error';
            flash.innerHTML = error;
            document.body.appendChild(flash);
            setTimeout(() => flash.remove(), 3000);
        }
    </script>
</body>
</html>
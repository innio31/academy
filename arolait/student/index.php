<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole(['student']);

// Get institution settings
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('institution_name', 'app_name', 'app_slogan')");
$stmt->execute();
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$institution_name = $settings['institution_name'] ?? 'University Portal';
$app_slogan = $settings['app_slogan'] ?? 'Excellence in Education';

// Get student information with profile picture
$stmt = $pdo->prepare("
    SELECT s.*, u.email, u.first_name, u.last_name, u.phone, u.profile_pic,
           d.name as department_name, d.code as department_code,
           f.name as faculty_name,
           a.name as current_session_name
    FROM students s
    JOIN users u ON s.user_id = u.id
    JOIN departments d ON s.department_id = d.id
    JOIN faculties f ON d.faculty_id = f.id
    LEFT JOIN academic_sessions a ON s.current_session_id = a.id
    WHERE s.id = ?
");
$stmt->execute([$_SESSION['student_id']]);
$student = $stmt->fetch();

// Get profile picture path
$profile_pic_path = !empty($student['profile_pic']) && file_exists($_SERVER['DOCUMENT_ROOT'] . $student['profile_pic']) 
    ? $student['profile_pic'] 
    : null;

// Get current semester and registration status
$stmt = $pdo->prepare("
    SELECT s.*, a.name as session_name,
           rs.is_open as registration_open,
           rs.open_date, rs.close_date,
           rs.max_credits, rs.min_credits
    FROM semesters s
    JOIN academic_sessions a ON s.session_id = a.id
    LEFT JOIN registration_settings rs ON s.id = rs.semester_id
    WHERE s.is_current = 1 AND a.is_current = 1
    LIMIT 1
");
$stmt->execute();
$current_semester = $stmt->fetch();

// Get registered courses for current semester using correct table structure
$registered_courses = [];
$total_credits = 0;
if ($current_semester) {
    $stmt = $pdo->prepare("
        SELECT c.*, scr.registered_at, scr.status
        FROM student_course_registrations scr
        JOIN course_offerings co ON scr.offering_id = co.id
        JOIN courses c ON co.course_id = c.id
        WHERE scr.student_id = ? AND co.semester_id = ? AND scr.status = 'registered'
        ORDER BY c.code
    ");
    $stmt->execute([$_SESSION['student_id'], $current_semester['id']]);
    $registered_courses = $stmt->fetchAll();
    
    foreach($registered_courses as $course) {
        $total_credits += $course['credit_unit'];
    }
}

// Get attendance summary
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_classes,
        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
        ROUND((SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0)) * 100, 2) as attendance_rate
    FROM attendance
    WHERE student_id = ?
");
$stmt->execute([$_SESSION['student_id']]);
$attendance_summary = $stmt->fetch();

// Get CGPA
$cgpa = 0;
if ($student['current_session_id']) {
    $cgpa = getStudentCGPA($pdo, $_SESSION['student_id'], $student['current_session_id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Student Dashboard - <?php echo htmlspecialchars($institution_name); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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
        
        .sidebar-header h3 { font-size: 18px; }
        .sidebar-header p { font-size: 11px; opacity: 0.8; margin-top: 5px; }
        
        .sidebar-nav { padding: 20px 0; }
        .nav-item {
            padding: 12px 20px;
            display: block;
            color: #cbd5e0;
            text-decoration: none;
            transition: all 0.3s;
        }
        .nav-item:hover { background: #4a5568; color: white; padding-left: 25px; }
        .nav-item.active { background: #667eea; color: white; }
        
        .main-content {
            margin-left: 260px;
            padding: 20px;
            min-height: 100vh;
        }
        
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
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        
        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .user-avatar .avatar-placeholder {
            font-size: 24px;
            color: white;
        }
        
        .welcome-text h2 { font-size: 18px; color: #2d3748; }
        .welcome-text p { font-size: 12px; color: #718096; margin-top: 4px; }
        
        .logout-btn {
            background: #e53e3e;
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            transition: background 0.3s;
        }
        .logout-btn:hover { background: #c53030; }
        
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
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        .stat-card:hover { transform: translateY(-2px); }
        
        .stat-number { font-size: 32px; font-weight: bold; color: #667eea; }
        .stat-label { font-size: 12px; color: #718096; margin-top: 5px; }
        
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
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
        
        .card-header.green { background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); }
        .card-header.orange { background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%); }
        
        .card-body { padding: 20px; }
        
        .course-item {
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .course-item:last-child { border-bottom: none; }
        .course-code { font-weight: 600; color: #2d3748; }
        .course-title { font-size: 13px; color: #718096; margin: 4px 0; }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5a67d8; }
        .btn-success { background: #48bb78; color: white; }
        .btn-success:hover { background: #38a169; }
        .btn-warning { background: #ed8936; color: white; }
        .btn-warning:hover { background: #dd6b20; }
        
        .registration-alert {
            background: #feebc8;
            border-left: 4px solid #ed8936;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .menu-toggle { display: none; }
        
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); position: fixed; }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
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
            .top-bar { margin-top: 50px; }
            .cards-grid { grid-template-columns: 1fr; }
            .user-info { width: 100%; justify-content: space-between; }
        }
        
        .institution-badge {
            font-size: 11px;
            color: #718096;
            text-align: center;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #4a5568;
        }
    </style>
</head>
<body>
    <button class="menu-toggle" onclick="toggleSidebar()">☰ Menu</button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h3>🏫 <?php echo htmlspecialchars($institution_name); ?></h3>
            <p>Student Portal</p>
        </div>
        <div class="sidebar-nav">
            <a href="index.php" class="nav-item active">📊 Dashboard</a>
            <a href="course_registration.php" class="nav-item">📝 Course Registration</a>
            <a href="my_courses.php" class="nav-item">📖 My Courses</a>
            <a href="my_results.php" class="nav-item">📊 My Results</a>
            <a href="my_attendance.php" class="nav-item">📅 My Attendance</a>
            <a href="profile.php" class="nav-item">👤 My Profile</a>
            <a href="../logout.php" class="nav-item">🚪 Logout</a>
        </div>
        <div class="institution-badge">
            <?php echo htmlspecialchars($app_slogan); ?>
        </div>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <div class="user-info">
                <div class="user-avatar">
                    <?php if($profile_pic_path): ?>
                        <img src="<?php echo $profile_pic_path; ?>" alt="Profile Picture">
                    <?php else: ?>
                        <div class="avatar-placeholder">👤</div>
                    <?php endif; ?>
                </div>
                <div class="welcome-text">
                    <h2>Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</h2>
                    <p><?php echo htmlspecialchars($student['department_name']); ?> | Level <?php echo $student['current_level']; ?></p>
                </div>
            </div>
            <div>
                <span style="background: #e2e8f0; padding: 5px 12px; border-radius: 20px; font-size: 12px; margin-right: 10px;">
                    Reg: <?php echo $student['reg_number']; ?>
                </span>
                <a href="../logout.php" class="logout-btn">🚪 Logout</a>
            </div>
        </div>
        
        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_credits; ?></div>
                <div class="stat-label">Registered Credits</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $attendance_summary['attendance_rate'] ?? 0; ?>%</div>
                <div class="stat-label">Attendance Rate</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($cgpa, 2); ?></div>
                <div class="stat-label">CGPA</div>
            </div>
        </div>
        
        <!-- Registration Alert -->
        <?php if($current_semester && ($current_semester['registration_open'] ?? false)): ?>
            <div class="registration-alert">
                <strong>📝 Course Registration is OPEN!</strong><br>
                <?php echo $current_semester['name']; ?> Semester, <?php echo $current_semester['session_name']; ?><br>
                Min Credits: <?php echo $current_semester['min_credits']; ?> | Max Credits: <?php echo $current_semester['max_credits']; ?>
                <?php if($current_semester['close_date']): ?>
                    <br>Registration closes: <?php echo date('F j, Y g:i A', strtotime($current_semester['close_date'])); ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <!-- Cards -->
        <div class="cards-grid">
            <!-- Registered Courses -->
            <div class="card">
                <div class="card-header green">📖 Currently Registered Courses</div>
                <div class="card-body">
                    <?php if(empty($registered_courses)): ?>
                        <p style="color: #718096; text-align: center;">No courses registered yet.</p>
                        <?php if($current_semester && ($current_semester['registration_open'] ?? false)): ?>
                            <div style="text-align: center; margin-top: 10px;">
                                <a href="course_registration.php" class="btn btn-primary">Register Now</a>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php foreach($registered_courses as $course): ?>
                            <div class="course-item">
                                <div class="course-code"><?php echo htmlspecialchars($course['code']); ?></div>
                                <div class="course-title"><?php echo htmlspecialchars($course['title']); ?></div>
                                <div style="font-size: 12px; color: #718096;"><?php echo $course['credit_unit']; ?> Units</div>
                            </div>
                        <?php endforeach; ?>
                        <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #e2e8f0;">
                            <strong>Total Credits: <?php echo $total_credits; ?></strong>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="card">
                <div class="card-header orange">⚡ Quick Actions</div>
                <div class="card-body">
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php if($current_semester && ($current_semester['registration_open'] ?? false)): ?>
                            <a href="course_registration.php" class="btn btn-primary" style="justify-content: center;">📝 Course Registration</a>
                        <?php endif; ?>
                        <a href="my_results.php" class="btn btn-success" style="justify-content: center;">📊 Check Results</a>
                        <a href="my_attendance.php" class="btn btn-warning" style="justify-content: center;">📅 View Attendance</a>
                        <a href="print_registration_form.php" class="btn btn-primary" style="justify-content: center; background: #4299e1;" target="_blank">🖨️ Print Registration Form</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
        }
        
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.querySelector('.menu-toggle');
            if (window.innerWidth <= 768 && sidebar.classList.contains('open')) {
                if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });
    </script>
</body>
</html>
<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole(['parent']);

// Get parent information and linked student
$stmt = $pdo->prepare("
    SELECT p.*, 
           s.id as student_id, s.reg_number, s.current_level,
           CONCAT(su.first_name, ' ', su.last_name) as student_name,
           su.email as student_email,
           d.name as department_name, d.code as department_code,
           f.name as faculty_name
    FROM parents p
    JOIN students s ON p.student_id = s.id
    JOIN users su ON s.user_id = su.id
    JOIN departments d ON s.department_id = d.id
    JOIN faculties f ON d.faculty_id = f.id
    WHERE p.id = ?
");
$stmt->execute([$_SESSION['parent_id']]);
$parent = $stmt->fetch();

if (!$parent) {
    header("Location: ../index.php?error=Parent record not found");
    exit();
}

// Get current semester
$stmt = $pdo->prepare("
    SELECT s.*, a.name as session_name
    FROM semesters s
    JOIN academic_sessions a ON s.session_id = a.id
    WHERE s.is_current = 1 AND a.is_current = 1
    LIMIT 1
");
$stmt->execute();
$current_semester = $stmt->fetch();

// Get student's registered courses for current semester
$registered_courses = [];
$total_credits = 0;
if ($current_semester) {
    $stmt = $pdo->prepare("
        SELECT c.*, cr.registered_at
        FROM course_registrations cr
        JOIN courses c ON cr.course_id = c.id
        WHERE cr.student_id = ? AND cr.semester_id = ? AND cr.is_dropped = 0
        ORDER BY c.code
    ");
    $stmt->execute([$parent['student_id'], $current_semester['id']]);
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
        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count,
        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count,
        ROUND((SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0)) * 100, 2) as attendance_rate
    FROM attendance
    WHERE student_id = ?
");
$stmt->execute([$parent['student_id']]);
$attendance_summary = $stmt->fetch();

// Get courses with low attendance (below 70%)
$stmt = $pdo->prepare("
    SELECT 
        c.id, c.code, c.title,
        COUNT(a.id) as total_classes,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
        ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / NULLIF(COUNT(a.id), 0)) * 100, 2) as attendance_rate
    FROM courses c
    LEFT JOIN attendance a ON c.id = a.course_id AND a.student_id = ?
    WHERE c.id IN (SELECT course_id FROM course_registrations WHERE student_id = ? AND is_dropped = 0)
    GROUP BY c.id
    HAVING attendance_rate < 70 AND attendance_rate IS NOT NULL
");
$stmt->execute([$parent['student_id'], $parent['student_id']]);
$low_attendance_courses = $stmt->fetchAll();

// Get latest results (last semester with approved results)
$stmt = $pdo->prepare("
    SELECT 
        s.name as semester_name,
        a.name as session_name,
        SUM(r.credit_unit) as total_cu,
        SUM(r.course_unit_point) as total_cup,
        ROUND(SUM(r.course_unit_point) / NULLIF(SUM(r.credit_unit), 0), 2) as gpa
    FROM results r
    JOIN semesters s ON r.semester_id = s.id
    JOIN academic_sessions a ON s.session_id = a.id
    WHERE r.student_id = ? AND r.is_approved = 1
    GROUP BY r.semester_id
    ORDER BY a.start_date DESC, s.id DESC
    LIMIT 1
");
$stmt->execute([$parent['student_id']]);
$latest_results = $stmt->fetch();

// Calculate CGPA
$stmt = $pdo->prepare("
    SELECT 
        SUM(credit_unit) as total_cu,
        SUM(course_unit_point) as total_cup
    FROM results
    WHERE student_id = ? AND is_approved = 1
");
$stmt->execute([$parent['student_id']]);
$cgpa_totals = $stmt->fetch();
$cgpa = 0;
if ($cgpa_totals && $cgpa_totals['total_cu'] > 0) {
    $cgpa = $cgpa_totals['total_cup'] / $cgpa_totals['total_cu'];
}
$academic_standing = getAcademicStanding($cgpa);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Parent Dashboard - University Portal</title>
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
        
        /* Main Content */
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
        .welcome-text h2 { font-size: 18px; color: #2d3748; }
        .welcome-text p { font-size: 12px; color: #718096; margin-top: 4px; }
        .logout-btn {
            background: #e53e3e;
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
        }
        
        .student-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .student-name { font-size: 24px; font-weight: bold; margin-bottom: 5px; }
        .student-details { font-size: 14px; opacity: 0.9; }
        
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
        }
        .stat-number { font-size: 28px; font-weight: bold; color: #667eea; }
        .stat-label { font-size: 12px; color: #718096; margin-top: 5px; }
        
        .warning-card {
            background: #feebc8;
            border-left: 4px solid #ed8936;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
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
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-outline { background: transparent; border: 1px solid #667eea; color: #667eea; }
        
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 8px;
        }
        .progress-fill { height: 100%; transition: width 0.3s; }
        .progress-fill.good { background: #48bb78; }
        .progress-fill.warning { background: #ed8936; }
        .progress-fill.danger { background: #f56565; }
        
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
        }
    </style>
</head>
<body>
    <button class="menu-toggle" onclick="toggleSidebar()">☰ Menu</button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h3>🏫 University Portal</h3>
            <p>Parent Panel</p>
        </div>
        <div class="sidebar-nav">
            <a href="index.php" class="nav-item active">📊 Dashboard</a>
            <a href="child_results.php" class="nav-item">📊 Child's Results</a>
            <a href="child_attendance.php" class="nav-item">📅 Child's Attendance</a>
            <a href="child_courses.php" class="nav-item">📖 Child's Courses</a>
            <a href="profile.php" class="nav-item">👤 My Profile</a>
            <a href="../logout.php" class="nav-item">🚪 Logout</a>
        </div>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <div class="welcome-text">
                <h2>Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</h2>
                <p>Parent/Guardian Portal</p>
            </div>
            <a href="../logout.php" class="logout-btn">🚪 Logout</a>
        </div>
        
        <!-- Child Information -->
        <div class="student-card">
            <div class="student-name">👨‍🎓 <?php echo htmlspecialchars($parent['student_name']); ?></div>
            <div class="student-details">
                Registration Number: <?php echo $parent['reg_number']; ?> | 
                <?php echo htmlspecialchars($parent['department_name']); ?> | 
                Level <?php echo $parent['current_level']; ?>
            </div>
            <div class="student-details">
                Relationship: <?php echo $parent['relationship'] ?: 'Parent/Guardian'; ?>
            </div>
        </div>
        
        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $attendance_summary['attendance_rate'] ?? 0; ?>%</div>
                <div class="stat-label">Overall Attendance</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($cgpa, 2); ?></div>
                <div class="stat-label">CGPA</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_credits; ?></div>
                <div class="stat-label">Current Credits</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $latest_results['gpa'] ?? 'N/A'; ?></div>
                <div class="stat-label">Last Semester GPA</div>
            </div>
        </div>
        
        <!-- Attendance Warning -->
        <?php if(!empty($low_attendance_courses)): ?>
            <div class="warning-card">
                <strong>⚠️ Attendance Alert!</strong><br>
                Your ward's attendance is below 70% in the following course(s):
                <?php foreach($low_attendance_courses as $course): ?>
                    • <?php echo $course['code']; ?> (<?php echo $course['attendance_rate']; ?>%)
                <?php endforeach; ?>
                Please remind them that students with attendance below 70% may not be eligible to write examinations.
            </div>
        <?php endif; ?>
        
        <div class="cards-grid">
            <!-- Current Courses -->
            <div class="card">
                <div class="card-header green">📖 Current Semester Courses</div>
                <div class="card-body">
                    <?php if(empty($registered_courses)): ?>
                        <p style="color: #718096; text-align: center;">No courses registered for current semester.</p>
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
            
            <!-- Academic Standing -->
            <div class="card">
                <div class="card-header orange">📈 Academic Standing</div>
                <div class="card-body">
                    <div style="text-align: center; padding: 20px;">
                        <div style="font-size: 48px; font-weight: bold; color: #667eea;"><?php echo number_format($cgpa, 2); ?></div>
                        <div style="margin-top: 10px; padding: 10px; background: #f0fff4; border-radius: 8px;">
                            <strong><?php echo $academic_standing[0]; ?></strong>
                        </div>
                        <?php if($latest_results): ?>
                            <div style="margin-top: 15px; font-size: 13px; color: #718096;">
                                Last Semester: <?php echo $latest_results['semester_name']; ?> <?php echo $latest_results['session_name']; ?><br>
                                GPA: <?php echo $latest_results['gpa']; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="card">
                <div class="card-header">⚡ Quick Actions</div>
                <div class="card-body">
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <a href="child_results.php" class="btn btn-primary" style="justify-content: center;">📊 View Full Results</a>
                        <a href="child_attendance.php" class="btn btn-primary" style="justify-content: center; background: #48bb78;">📅 View Attendance Details</a>
                        <a href="child_courses.php" class="btn btn-primary" style="justify-content: center; background: #ed8936;">📖 View All Courses</a>
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
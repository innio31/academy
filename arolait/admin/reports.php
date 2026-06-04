<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

$active_tab = $_GET['tab'] ?? 'overview';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Get statistics for overview
// Total students
$stmt = $pdo->query("SELECT COUNT(*) as total FROM students");
$total_students = $stmt->fetch()['total'];

// Total staff
$stmt = $pdo->query("SELECT COUNT(*) as total FROM staff");
$total_staff = $stmt->fetch()['total'];

// Total courses
$stmt = $pdo->query("SELECT COUNT(*) as total FROM courses WHERE is_active = 1");
$total_courses = $stmt->fetch()['total'];

// Total departments
$stmt = $pdo->query("SELECT COUNT(*) as total FROM departments");
$total_departments = $stmt->fetch()['total'];

// Current semester registration stats
$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT scr.student_id) as students_registered,
        COUNT(scr.id) as total_registrations
    FROM student_course_registrations scr
    JOIN course_offerings co ON scr.offering_id = co.id
    JOIN semesters s ON co.semester_id = s.id
    WHERE s.is_current = 1 AND scr.status = 'registered'
");
$stmt->execute();
$reg_stats = $stmt->fetch();

// Attendance summary for current month
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_attendance,
        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count,
        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count
    FROM attendance
    WHERE date BETWEEN ? AND ?
");
$stmt->execute([$date_from, $date_to]);
$attendance_stats = $stmt->fetch();

// Results summary
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_results,
        SUM(CASE WHEN is_approved = 1 THEN 1 ELSE 0 END) as approved_count,
        SUM(CASE WHEN is_approved = 0 THEN 1 ELSE 0 END) as pending_count,
        AVG(total_score) as avg_score
    FROM results
");
$stmt->execute();
$result_stats = $stmt->fetch();

// Get department-wise student distribution
$dept_stats = $pdo->query("
    SELECT d.name, d.code, COUNT(s.id) as student_count
    FROM departments d
    LEFT JOIN students s ON d.id = s.department_id
    GROUP BY d.id
    ORDER BY student_count DESC
    LIMIT 10
")->fetchAll();

// Get popular courses (most registered)
$popular_courses = $pdo->query("
    SELECT c.code, c.title, COUNT(scr.id) as registration_count
    FROM courses c
    JOIN course_offerings co ON c.id = co.course_id
    JOIN student_course_registrations scr ON co.id = scr.offering_id
    WHERE scr.status = 'registered'
    GROUP BY c.id
    ORDER BY registration_count DESC
    LIMIT 10
")->fetchAll();

// Get recent activities
$recent_activities = $pdo->query("
    SELECT 'result_upload' as type, COUNT(*) as count, DATE(created_at) as date
    FROM results
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at)
    UNION ALL
    SELECT 'attendance' as type, COUNT(*) as count, DATE(date) as date
    FROM attendance
    WHERE date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(date)
    ORDER BY date DESC
    LIMIT 10
")->fetchAll();

// Get grade distribution
$grade_distribution = $pdo->query("
    SELECT 
        grade,
        COUNT(*) as count,
        ROUND((COUNT(*) / (SELECT COUNT(*) FROM results WHERE grade IS NOT NULL)) * 100, 2) as percentage
    FROM results
    WHERE grade IS NOT NULL AND grade != ''
    GROUP BY grade
    ORDER BY FIELD(grade, 'A', 'AB', 'B', 'BC', 'C', 'CD', 'D', 'E', 'F')
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Reports - Admin Portal</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fb;
            padding: 16px;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        
        .header {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 24px; color: #2d3748; margin-bottom: 8px; }
        .header p { color: #718096; font-size: 14px; }
        
        .tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-bottom: 20px;
            background: white;
            padding: 10px;
            border-radius: 12px;
        }
        .tab-btn {
            padding: 10px 20px;
            background: transparent;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .tab-btn:hover { background: #e2e8f0; }
        .tab-btn.active { background: #667eea; color: white; }
        
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
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
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
        .card-body { padding: 20px; }
        
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        th { background: #f7fafc; font-weight: 600; }
        
        .filter-bar {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }
        .filter-group { flex: 1; min-width: 150px; }
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-size: 12px;
            font-weight: 600;
            color: #4a5568;
        }
        .filter-group input, .filter-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
        }
        
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
        .btn-outline { background: transparent; border: 1px solid #667eea; color: #667eea; }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        @media (max-width: 768px) {
            .cards-grid { grid-template-columns: 1fr; }
            .filter-form { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>📊 Reports & Analytics</h1>
                <p>View institutional statistics and performance metrics</p>
            </div>
            <a href="index.php" style="color: #667eea; text-decoration: none;">← Back to Dashboard</a>
        </div>
        
        <!-- Tabs -->
        <div class="tabs">
            <button class="tab-btn <?php echo $active_tab == 'overview' ? 'active' : ''; ?>" onclick="showTab('overview')">📈 Overview</button>
            <button class="tab-btn <?php echo $active_tab == 'attendance' ? 'active' : ''; ?>" onclick="showTab('attendance')">✅ Attendance Report</button>
            <button class="tab-btn <?php echo $active_tab == 'results' ? 'active' : ''; ?>" onclick="showTab('results')">📊 Results Report</button>
            <button class="tab-btn <?php echo $active_tab == 'courses' ? 'active' : ''; ?>" onclick="showTab('courses')">📚 Course Report</button>
            <button class="tab-btn <?php echo $active_tab == 'students' ? 'active' : ''; ?>" onclick="showTab('students')">👨‍🎓 Student Report</button>
        </div>
        
        <!-- Overview Tab -->
        <div id="tab-overview" class="tab-content <?php echo $active_tab == 'overview' ? 'active' : ''; ?>">
            <!-- Key Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($total_students); ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($total_staff); ?></div>
                    <div class="stat-label">Total Staff</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($total_courses); ?></div>
                    <div class="stat-label">Active Courses</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($total_departments); ?></div>
                    <div class="stat-label">Departments</div>
                </div>
            </div>
            
            <div class="cards-grid">
                <!-- Registration Stats -->
                <div class="card">
                    <div class="card-header">📝 Current Semester Registration</div>
                    <div class="card-body">
                        <div style="text-align: center; padding: 20px;">
                            <div style="font-size: 48px; font-weight: bold; color: #667eea;"><?php echo number_format($reg_stats['students_registered'] ?? 0); ?></div>
                            <div style="color: #718096;">Students Registered</div>
                            <div style="margin-top: 15px;">
                                <strong><?php echo number_format($reg_stats['total_registrations'] ?? 0); ?></strong> total course registrations
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Attendance Stats -->
                <div class="card">
                    <div class="card-header">✅ Attendance Overview (Last 30 Days)</div>
                    <div class="card-body">
                        <canvas id="attendanceChart" style="max-height: 250px;"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="cards-grid">
                <!-- Department Distribution -->
                <div class="card">
                    <div class="card-header">🏛️ Student Distribution by Department</div>
                    <div class="card-body">
                        <canvas id="deptChart" style="max-height: 300px;"></canvas>
                    </div>
                </div>
                
                <!-- Grade Distribution -->
                <div class="card">
                    <div class="card-header">🎓 Grade Distribution</div>
                    <div class="card-body">
                        <canvas id="gradeChart" style="max-height: 300px;"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Popular Courses -->
            <div class="card">
                <div class="card-header">🔥 Most Popular Courses</div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr><th>Course Code</th><th>Course Title</th><th>Enrollments</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($popular_courses as $course): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($course['code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($course['title']); ?></td>
                                    <td><?php echo number_format($course['registration_count']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if(empty($popular_courses)): ?>
                                <tr><td colspan="3" style="text-align: center;">No data available</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Attendance Report Tab -->
        <div id="tab-attendance" class="tab-content <?php echo $active_tab == 'attendance' ? 'active' : ''; ?>">
            <div class="filter-bar">
                <form method="GET" action="" class="filter-form">
                    <input type="hidden" name="tab" value="attendance">
                    <div class="filter-group">
                        <label>Date From</label>
                        <input type="date" name="date_from" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="filter-group">
                        <label>Date To</label>
                        <input type="date" name="date_to" value="<?php echo $date_to; ?>">
                    </div>
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary">📊 Generate Report</button>
                    </div>
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <a href="reports.php?tab=attendance" class="btn btn-outline">Reset</a>
                    </div>
                </form>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($attendance_stats['total_attendance'] ?? 0); ?></div>
                    <div class="stat-label">Total Records</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #48bb78;"><?php echo number_format($attendance_stats['present_count'] ?? 0); ?></div>
                    <div class="stat-label">Present</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #f56565;"><?php echo number_format($attendance_stats['absent_count'] ?? 0); ?></div>
                    <div class="stat-label">Absent</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #ed8936;"><?php echo number_format($attendance_stats['late_count'] ?? 0); ?></div>
                    <div class="stat-label">Late</div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">📈 Daily Attendance Trend</div>
                <div class="card-body">
                    <canvas id="dailyAttendanceChart" style="max-height: 300px;"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Results Report Tab -->
        <div id="tab-results" class="tab-content <?php echo $active_tab == 'results' ? 'active' : ''; ?>">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($result_stats['total_results'] ?? 0); ?></div>
                    <div class="stat-label">Total Results</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #48bb78;"><?php echo number_format($result_stats['approved_count'] ?? 0); ?></div>
                    <div class="stat-label">Approved</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #ed8936;"><?php echo number_format($result_stats['pending_count'] ?? 0); ?></div>
                    <div class="stat-label">Pending Approval</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($result_stats['avg_score'] ?? 0, 1); ?>%</div>
                    <div class="stat-label">Average Score</div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">📊 Grade Distribution Analysis</div>
                <div class="card-body">
                    <canvas id="gradeDistributionChart" style="max-height: 300px;"></canvas>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">📋 Grade Distribution Details</div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr><th>Grade</th><th>Count</th><th>Percentage</th><th>Visual</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($grade_distribution as $grade): ?>
                                <tr>
                                    <td><strong><?php echo $grade['grade']; ?></strong></td>
                                    <td><?php echo number_format($grade['count']); ?></td>
                                    <td><?php echo number_format($grade['percentage'], 2); ?>%</td>
                                    <td style="width: 200px;">
                                        <div style="background: #e2e8f0; height: 20px; border-radius: 10px; overflow: hidden;">
                                            <div style="width: <?php echo $grade['percentage']; ?>%; background: #667eea; height: 100%;"></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Course Report Tab -->
        <div id="tab-courses" class="tab-content <?php echo $active_tab == 'courses' ? 'active' : ''; ?>">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($total_courses); ?></div>
                    <div class="stat-label">Total Courses</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($total_courses); ?></div>
                    <div class="stat-label">Active Courses</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($total_departments); ?></div>
                    <div class="stat-label">Departments</div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">📚 Course List</div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Course Code</th>
                                <th>Course Title</th>
                                <th>Department</th>
                                <th>Credit Unit</th>
                                <th>Level</th>
                                <th>Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $courses_list = $pdo->query("
                                SELECT c.*, d.name as department_name
                                FROM courses c
                                JOIN departments d ON c.department_id = d.id
                                WHERE c.is_active = 1
                                ORDER BY c.code
                                LIMIT 50
                            ")->fetchAll();
                            ?>
                            <?php foreach($courses_list as $course): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($course['code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($course['title']); ?></td>
                                    <td><?php echo htmlspecialchars($course['department_name']); ?></td>
                                    <td><?php echo $course['credit_unit']; ?></td>
                                    <td><?php echo $course['level']; ?></td>
                                    <td><?php echo $course['is_elective'] ? 'Elective' : 'Core'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Student Report Tab -->
        <div id="tab-students" class="tab-content <?php echo $active_tab == 'students' ? 'active' : ''; ?>">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($total_students); ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($total_students); ?></div>
                    <div class="stat-label">Active Students</div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">👨‍🎓 Students by Department</div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr><th>Department</th><th>Code</th><th>Number of Students</th><th>Percentage</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($dept_stats as $dept): 
                                $percentage = $total_students > 0 ? ($dept['student_count'] / $total_students) * 100 : 0;
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($dept['name']); ?></td>
                                    <td><?php echo htmlspecialchars($dept['code']); ?></td>
                                    <td><?php echo number_format($dept['student_count']); ?></td>
                                    <td><?php echo number_format($percentage, 1); ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            // Remove active class from all buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            // Show selected tab
            document.getElementById(`tab-${tabName}`).classList.add('active');
            // Update URL without reload
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
        }
        
        // Charts
        <?php if($active_tab == 'overview'): ?>
        // Attendance Chart
        const attendanceCtx = document.getElementById('attendanceChart')?.getContext('2d');
        if (attendanceCtx) {
            new Chart(attendanceCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Present', 'Absent', 'Late'],
                    datasets: [{
                        data: [<?php echo $attendance_stats['present_count'] ?? 0; ?>, <?php echo $attendance_stats['absent_count'] ?? 0; ?>, <?php echo $attendance_stats['late_count'] ?? 0; ?>],
                        backgroundColor: ['#48bb78', '#f56565', '#ed8936']
                    }]
                },
                options: { responsive: true, maintainAspectRatio: true }
            });
        }
        
        // Department Chart
        const deptCtx = document.getElementById('deptChart')?.getContext('2d');
        if (deptCtx) {
            new Chart(deptCtx, {
                type: 'bar',
                data: {
                    labels: [<?php echo "'" . implode("','", array_column(array_slice($dept_stats, 0, 8), 'code')) . "'"; ?>],
                    datasets: [{
                        label: 'Number of Students',
                        data: [<?php echo implode(',', array_column(array_slice($dept_stats, 0, 8), 'student_count')); ?>],
                        backgroundColor: '#667eea'
                    }]
                },
                options: { responsive: true, maintainAspectRatio: true }
            });
        }
        
        // Grade Chart
        const gradeCtx = document.getElementById('gradeChart')?.getContext('2d');
        if (gradeCtx) {
            new Chart(gradeCtx, {
                type: 'bar',
                data: {
                    labels: [<?php echo "'" . implode("','", array_column($grade_distribution, 'grade')) . "'"; ?>],
                    datasets: [{
                        label: 'Number of Students',
                        data: [<?php echo implode(',', array_column($grade_distribution, 'count')); ?>],
                        backgroundColor: '#48bb78'
                    }]
                },
                options: { responsive: true, maintainAspectRatio: true }
            });
        }
        <?php endif; ?>
        
        <?php if($active_tab == 'attendance'): ?>
        // Daily Attendance Trend
        const dailyCtx = document.getElementById('dailyAttendanceChart')?.getContext('2d');
        if (dailyCtx) {
            new Chart(dailyCtx, {
                type: 'line',
                data: {
                    labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
                    datasets: [{
                        label: 'Attendance Rate',
                        data: [75, 82, 78, 85],
                        borderColor: '#667eea',
                        tension: 0.3
                    }]
                },
                options: { responsive: true, maintainAspectRatio: true }
            });
        }
        <?php endif; ?>
        
        <?php if($active_tab == 'results'): ?>
        // Grade Distribution Chart
        const gradeDistCtx = document.getElementById('gradeDistributionChart')?.getContext('2d');
        if (gradeDistCtx) {
            new Chart(gradeDistCtx, {
                type: 'bar',
                data: {
                    labels: [<?php echo "'" . implode("','", array_column($grade_distribution, 'grade')) . "'"; ?>],
                    datasets: [{
                        label: 'Number of Students',
                        data: [<?php echo implode(',', array_column($grade_distribution, 'count')); ?>],
                        backgroundColor: '#667eea'
                    }]
                },
                options: { responsive: true, maintainAspectRatio: true }
            });
        }
        <?php endif; ?>
    </script>
</body>
</html>
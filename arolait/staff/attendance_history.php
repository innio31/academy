<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole(['staff']);

$course_id = $_GET['course_id'] ?? 0;
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$status_filter = $_GET['status'] ?? '';

// Get staff's assigned courses from course_offerings
$stmt = $pdo->prepare("
    SELECT 
        c.id as course_id,
        c.code,
        c.title,
        c.credit_unit,
        d.name as department_name,
        s.name as semester_name,
        a.name as session_name,
        co.id as offering_id
    FROM course_offerings co
    JOIN courses c ON co.course_id = c.id
    JOIN departments d ON c.department_id = d.id
    JOIN semesters s ON co.semester_id = s.id
    JOIN academic_sessions a ON s.session_id = a.id
    WHERE co.lecturer_id = ?
    GROUP BY c.id, co.id
    ORDER BY a.start_date DESC, s.id DESC, c.code
");
$stmt->execute([$_SESSION['user_id']]);
$courses = $stmt->fetchAll();

// If course selected, get attendance records
$attendance_records = [];
$attendance_summary = [];
$student_summary = [];
$course_info = null;

if ($course_id) {
    // Get course details
    $stmt = $pdo->prepare("
        SELECT 
            c.*,
            d.name as department_name
        FROM courses c
        JOIN departments d ON c.department_id = d.id
        WHERE c.id = ?
    ");
    $stmt->execute([$course_id]);
    $course_info = $stmt->fetch();
    
    if ($course_info) {
        // Get attendance records with student info
        $sql = "
            SELECT 
                a.id as attendance_id,
                a.date,
                a.time,
                a.status,
                s.id as student_id,
                s.reg_number,
                CONCAT(u.first_name, ' ', u.last_name) as student_name,
                u.email as student_email
            FROM attendance a
            JOIN students s ON a.student_id = s.id
            JOIN users u ON s.user_id = u.id
            WHERE a.course_id = ? AND a.date BETWEEN ? AND ?
        ";
        $params = [$course_id, $date_from, $date_to];
        
        if ($status_filter) {
            $sql .= " AND a.status = ?";
            $params[] = $status_filter;
        }
        
        $sql .= " ORDER BY a.date DESC, a.time DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $attendance_records = $stmt->fetchAll();
        
        // Get summary statistics by date
        $stmt = $pdo->prepare("
            SELECT 
                DATE(date) as attendance_date,
                COUNT(*) as total_records,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count,
                ROUND((SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as attendance_rate
            FROM attendance
            WHERE course_id = ? AND date BETWEEN ? AND ?
            GROUP BY DATE(date)
            ORDER BY attendance_date DESC
        ");
        $stmt->execute([$course_id, $date_from, $date_to]);
        $attendance_summary = $stmt->fetchAll();
        
        // Get student-wise attendance summary for students enrolled in this course
        // First get offering_id
        $stmt = $pdo->prepare("
            SELECT co.id as offering_id
            FROM course_offerings co
            WHERE co.course_id = ? AND co.lecturer_id = ?
            LIMIT 1
        ");
        $stmt->execute([$course_id, $_SESSION['user_id']]);
        $offering = $stmt->fetch();
        $offering_id = $offering ? $offering['offering_id'] : 0;
        
        if ($offering_id) {
            $stmt = $pdo->prepare("
                SELECT 
                    s.id as student_id,
                    s.reg_number,
                    CONCAT(u.first_name, ' ', u.last_name) as student_name,
                    COUNT(a.id) as total_classes,
                    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                    SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                    SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
                    ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / NULLIF(COUNT(a.id), 0)) * 100, 2) as attendance_percentage
                FROM student_course_registrations scr
                JOIN students s ON scr.student_id = s.id
                JOIN users u ON s.user_id = u.id
                LEFT JOIN attendance a ON s.id = a.student_id AND a.course_id = ? AND a.date BETWEEN ? AND ?
                WHERE scr.offering_id = ? AND scr.status = 'registered'
                GROUP BY s.id
                ORDER BY attendance_percentage ASC
            ");
            $stmt->execute([$course_id, $date_from, $date_to, $offering_id]);
            $student_summary = $stmt->fetchAll();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Attendance History - Staff Portal</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f5f7fb;
            padding: 16px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 24px;
            color: #2d3748;
            margin-bottom: 8px;
        }
        
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
        
        .filter-group {
            flex: 1;
            min-width: 150px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-size: 12px;
            font-weight: 600;
            color: #4a5568;
        }
        
        .filter-group select, .filter-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
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
        
        .btn-primary {
            background: #667eea;
            color: white;
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
        
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #667eea;
        }
        
        .stat-label {
            font-size: 12px;
            color: #718096;
            margin-top: 5px;
        }
        
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
        
        .card-body {
            padding: 20px;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        
        th {
            background: #f7fafc;
            font-weight: 600;
        }
        
        .status-present {
            background: #c6f6d5;
            color: #22543d;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
            font-size: 12px;
        }
        
        .status-absent {
            background: #fed7d7;
            color: #c53030;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
            font-size: 12px;
        }
        
        .status-late {
            background: #feebc8;
            color: #7c2d12;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
            font-size: 12px;
        }
        
        .progress-bar {
            width: 100%;
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 5px;
        }
        
        .progress-fill {
            height: 100%;
            transition: width 0.3s;
        }
        
        .progress-fill.good {
            background: #48bb78;
        }
        
        .progress-fill.warning {
            background: #ed8936;
        }
        
        .progress-fill.danger {
            background: #f56565;
        }
        
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .btn-small {
            padding: 4px 8px;
            font-size: 11px;
        }
        
        @media (max-width: 768px) {
            .filter-form {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .cards-grid {
                grid-template-columns: 1fr;
            }
            
            th, td {
                padding: 8px;
                font-size: 12px;
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
    <div class="container">
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                <div>
                    <h1>📅 Attendance History</h1>
                    <p>View and analyze student attendance records</p>
                </div>
                <a href="index.php" style="color: #667eea; text-decoration: none;">← Back to Dashboard</a>
            </div>
        </div>
        
        <!-- Course Selection -->
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form">
                <div class="filter-group">
                    <label>Select Course</label>
                    <select name="course_id" required onchange="this.form.submit()">
                        <option value="">-- Select Course --</option>
                        <?php foreach($courses as $course): ?>
                            <option value="<?php echo $course['course_id']; ?>" <?php echo $course_id == $course['course_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($course['code'] . ' - ' . $course['title'] . ' (' . $course['semester_name'] . ' ' . $course['session_name'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if($course_id): ?>
                    <div class="filter-group">
                        <label>Date From</label>
                        <input type="date" name="date_from" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="filter-group">
                        <label>Date To</label>
                        <input type="date" name="date_to" value="<?php echo $date_to; ?>">
                    </div>
                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="">All Status</option>
                            <option value="present" <?php echo $status_filter == 'present' ? 'selected' : ''; ?>>Present</option>
                            <option value="absent" <?php echo $status_filter == 'absent' ? 'selected' : ''; ?>>Absent</option>
                            <option value="late" <?php echo $status_filter == 'late' ? 'selected' : ''; ?>>Late</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary">📊 Generate Report</button>
                    </div>
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <a href="attendance_history.php?course_id=<?php echo $course_id; ?>" class="btn btn-outline">Reset</a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
        
        <?php if($course_id && $course_info): ?>
        
        <!-- Course Info -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo htmlspecialchars($course_info['code']); ?></div>
                <div class="stat-label">Course Code</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $course_info['credit_unit']; ?></div>
                <div class="stat-label">Credit Unit</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count($attendance_records); ?></div>
                <div class="stat-label">Total Records</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count($attendance_summary); ?></div>
                <div class="stat-label">Days with Attendance</div>
            </div>
        </div>
        
        <!-- Charts Row -->
        <div class="cards-grid">
            <!-- Daily Attendance Trend -->
            <div class="card">
                <div class="card-header">📈 Daily Attendance Trend</div>
                <div class="card-body">
                    <canvas id="attendanceTrendChart" style="max-height: 300px;"></canvas>
                </div>
            </div>
            
            <!-- Status Distribution -->
            <div class="card">
                <div class="card-header">🥧 Attendance Status Distribution</div>
                <div class="card-body">
                    <canvas id="statusDistributionChart" style="max-height: 300px;"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Student-wise Attendance Summary -->
        <div class="card" style="margin-bottom: 20px;">
            <div class="card-header">👨‍🎓 Student-wise Attendance Summary (<?php echo $date_from; ?> to <?php echo $date_to; ?>)</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>S/N</th>
                                <th>Reg Number</th>
                                <th>Student Name</th>
                                <th>Present</th>
                                <th>Absent</th>
                                <th>Late</th>
                                <th>Total</th>
                                <th>Attendance %</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sn = 1;
                            $below_threshold = 0;
                            foreach($student_summary as $student):
                                $percentage = $student['attendance_percentage'] ?? 0;
                                $color = $percentage >= 70 ? '#48bb78' : ($percentage >= 50 ? '#ed8936' : '#f56565');
                                $fill_class = $percentage >= 70 ? 'good' : ($percentage >= 50 ? 'warning' : 'danger');
                                if ($percentage < 70 && $percentage > 0) $below_threshold++;
                            ?>
                                <tr>
                                    <td><?php echo $sn++; ?></td>
                                    <td><?php echo htmlspecialchars($student['reg_number']); ?></td>
                                    <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                    <td><?php echo $student['present_count'] ?? 0; ?></td>
                                    <td><?php echo $student['absent_count'] ?? 0; ?></td>
                                    <td><?php echo $student['late_count'] ?? 0; ?></td>
                                    <td><?php echo $student['total_classes'] ?? 0; ?></td>
                                    <td>
                                        <strong style="color: <?php echo $color; ?>;"><?php echo $percentage; ?>%</strong>
                                        <div class="progress-bar">
                                            <div class="progress-fill <?php echo $fill_class; ?>" style="width: <?php echo $percentage; ?>%;"></div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if($percentage >= 70): ?>
                                            <span class="status-present">✓ Compliant</span>
                                        <?php elseif($percentage >= 50): ?>
                                            <span class="status-late">⚠ At Risk</span>
                                        <?php elseif($percentage > 0): ?>
                                            <span class="status-absent">🔴 Below 70%</span>
                                        <?php else: ?>
                                            <span class="status-absent">No Records</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if(empty($student_summary)): ?>
                                <tr>
                                    <td colspan="9" style="text-align: center;">No students enrolled in this course</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if($below_threshold > 0): ?>
                    <div style="margin-top: 15px; padding: 10px; background: #feebc8; border-radius: 8px;">
                        <strong>⚠️ Warning:</strong> <?php echo $below_threshold; ?> student(s) have attendance below 70% and may be barred from examinations.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Detailed Attendance Records -->
        <div class="card">
            <div class="card-header">📋 Detailed Attendance Records</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Reg Number</th>
                                <th>Student Name</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($attendance_records as $record): ?>
                                <tr>
                                    <td><?php echo date('M j, Y', strtotime($record['date'])); ?></td>
                                    <td><?php echo date('g:i A', strtotime($record['time'])); ?></td>
                                    <td><?php echo htmlspecialchars($record['reg_number']); ?></td>
                                    <td><?php echo htmlspecialchars($record['student_name']); ?></td>
                                    <td>
                                        <?php if($record['status'] == 'present'): ?>
                                            <span class="status-present">✓ Present</span>
                                        <?php elseif($record['status'] == 'absent'): ?>
                                            <span class="status-absent">✗ Absent</span>
                                        <?php else: ?>
                                            <span class="status-late">⏰ Late</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="edit_attendance.php?id=<?php echo $record['attendance_id']; ?>" class="btn btn-outline btn-small">Edit</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if(empty($attendance_records)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center;">No attendance records found for this period</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <?php elseif($course_id && !$course_info): ?>
            <div style="background: #feebc8; padding: 20px; border-radius: 12px; text-align: center;">
                <p>Course not found.</p>
            </div>
        <?php elseif(!$course_id): ?>
            <div style="background: #e9f5ff; padding: 20px; border-radius: 12px; text-align: center;">
                <p>Please select a course to view attendance history.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        <?php if($course_id && !empty($attendance_summary)): ?>
        // Daily Attendance Trend Chart
        const trendCtx = document.getElementById('attendanceTrendChart').getContext('2d');
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: [<?php echo "'" . implode("','", array_reverse(array_map(function($d) { return date('M j', strtotime($d['attendance_date'])); }, $attendance_summary))) . "'"; ?>],
                datasets: [{
                    label: 'Attendance Rate (%)',
                    data: [<?php echo implode(',', array_reverse(array_map(function($d) { return $d['attendance_rate']; }, $attendance_summary))); ?>],
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        title: { display: true, text: 'Attendance Percentage (%)' }
                    }
                }
            }
        });
        <?php endif; ?>
        
        <?php if($course_id && !empty($attendance_records)): 
            $present = count(array_filter($attendance_records, function($r) { return $r['status'] == 'present'; }));
            $absent = count(array_filter($attendance_records, function($r) { return $r['status'] == 'absent'; }));
            $late = count(array_filter($attendance_records, function($r) { return $r['status'] == 'late'; }));
        ?>
        // Status Distribution Chart
        const statusCtx = document.getElementById('statusDistributionChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Present', 'Absent', 'Late'],
                datasets: [{
                    data: [<?php echo $present; ?>, <?php echo $absent; ?>, <?php echo $late; ?>],
                    backgroundColor: ['#48bb78', '#f56565', '#ed8936'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
        <?php endif; ?>
        
        // Flash message function
        function showFlash(message, type) {
            const flash = document.createElement('div');
            flash.className = `flash-message flash-${type}`;
            flash.innerHTML = message;
            document.body.appendChild(flash);
            setTimeout(() => flash.remove(), 3000);
        }
    </script>
</body>
</html>
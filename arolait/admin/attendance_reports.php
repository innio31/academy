<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin', 'staff']);

// Get filters
$course_id = $_GET['course'] ?? '';
$department_id = $_GET['department'] ?? '';
$level = $_GET['level'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$policy_threshold = 70; // 70% attendance policy

// Get courses for filter
$courses = $pdo->query("
    SELECT c.id, c.code, c.title, d.name as department_name 
    FROM courses c
    JOIN departments d ON c.department_id = d.id
    WHERE c.is_active = 1
    ORDER BY c.code
")->fetchAll();

// Get departments for filter
$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();

// Build attendance summary query
$sql = "SELECT 
            s.id as student_id,
            s.reg_number,
            CONCAT(u.first_name, ' ', u.last_name) as student_name,
            d.name as department_name,
            s.current_level,
            c.id as course_id,
            c.code as course_code,
            c.title as course_title,
            c.credit_unit,
            COUNT(DISTINCT a.id) as total_classes,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT a.id), 0)) * 100, 2) as attendance_percentage,
            CASE 
                WHEN ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT a.id), 0)) * 100, 2) < 70 THEN 'Warning'
                WHEN ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT a.id), 0)) * 100, 2) >= 70 THEN 'Good'
                ELSE 'Insufficient Data'
            END as attendance_status
        FROM students s
        JOIN users u ON s.user_id = u.id
        JOIN departments d ON s.department_id = d.id
        LEFT JOIN attendance a ON s.id = a.student_id
        LEFT JOIN courses c ON a.course_id = c.id
        WHERE 1=1";
$params = [];

if ($course_id) {
    $sql .= " AND c.id = ?";
    $params[] = $course_id;
}
if ($department_id) {
    $sql .= " AND s.department_id = ?";
    $params[] = $department_id;
}
if ($level) {
    $sql .= " AND s.current_level = ?";
    $params[] = $level;
}
if ($date_from && $date_to) {
    $sql .= " AND DATE(a.date) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
}

$sql .= " GROUP BY s.id, c.id
          ORDER BY attendance_percentage ASC, student_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$attendance_data = $stmt->fetchAll();

// Separate students below 70%
$below_threshold = array_filter($attendance_data, function($item) use ($policy_threshold) {
    return $item['attendance_percentage'] < $policy_threshold && $item['attendance_percentage'] !== null;
});

$above_threshold = array_filter($attendance_data, function($item) use ($policy_threshold) {
    return $item['attendance_percentage'] >= $policy_threshold && $item['attendance_percentage'] !== null;
});

// Get overall statistics
$total_records = count($attendance_data);
$total_below = count($below_threshold);
$total_above = count($above_threshold);
$warning_percentage = $total_records > 0 ? round(($total_below / $total_records) * 100, 2) : 0;

// Get course-specific summary
$course_summary = [];
if (!$course_id) {
    $stmt = $pdo->prepare("
        SELECT 
            c.id, c.code, c.title,
            COUNT(DISTINCT a.student_id) as students_attended,
            COUNT(DISTINCT a.id) as total_classes,
            AVG(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100 as avg_attendance
        FROM courses c
        LEFT JOIN attendance a ON c.id = a.course_id
        WHERE DATE(a.date) BETWEEN ? AND ?
        GROUP BY c.id
        ORDER BY avg_attendance ASC
        LIMIT 10
    ");
    $stmt->execute([$date_from, $date_to]);
    $course_summary = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Attendance Reports - University Portal</title>
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
        
        .header p {
            color: #718096;
            font-size: 14px;
        }
        
        .policy-alert {
            background: #feebc8;
            border-left: 4px solid #ed8936;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .policy-alert h3 {
            color: #7c2d12;
            margin-bottom: 8px;
        }
        
        .policy-alert p {
            color: #7c2d12;
            font-size: 14px;
        }
        
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: bold;
        }
        
        .stat-number.warning {
            color: #ed8936;
        }
        
        .stat-number.danger {
            color: #f56565;
        }
        
        .stat-number.success {
            color: #48bb78;
        }
        
        .stat-label {
            font-size: 12px;
            color: #718096;
            margin-top: 5px;
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
        
        .btn-danger {
            background: #f56565;
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
        
        .charts-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .chart-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .chart-card h3 {
            margin-bottom: 15px;
            color: #2d3748;
        }
        
        .warning-students {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .warning-header {
            background: #f56565;
            color: white;
            padding: 15px;
            font-weight: 600;
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
            color: #2d3748;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .badge-warning {
            background: #feebc8;
            color: #7c2d12;
        }
        
        .badge-danger {
            background: #fed7d7;
            color: #c53030;
        }
        
        .badge-success {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            transition: width 0.3s;
        }
        
        .progress-fill.warning {
            background: #ed8936;
        }
        
        .progress-fill.danger {
            background: #f56565;
        }
        
        .progress-fill.success {
            background: #48bb78;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            justify-content: flex-end;
        }
        
        @media (max-width: 640px) {
            .filter-form {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .charts-row {
                grid-template-columns: 1fr;
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
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h1>📊 Attendance Reports</h1>
                    <p>Track student attendance and enforce 70% attendance policy</p>
                </div>
                <a href="index.php" style="color: #667eea; text-decoration: none;">← Back to Dashboard</a>
            </div>
        </div>
        
        <!-- Policy Alert -->
        <div class="policy-alert">
            <h3>⚠️ Academic Policy: 70% Minimum Attendance Required</h3>
            <p>Students with attendance below 70% will be flagged and may be barred from writing examinations. Currently, <strong><?php echo $total_below; ?></strong> student records are below the threshold.</p>
        </div>
        
        <!-- Statistics -->
        <div class="stats-bar">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_records; ?></div>
                <div class="stat-label">Total Records</div>
            </div>
            <div class="stat-card">
                <div class="stat-number success"><?php echo $total_above; ?></div>
                <div class="stat-label">Above 70%</div>
            </div>
            <div class="stat-card">
                <div class="stat-number danger"><?php echo $total_below; ?></div>
                <div class="stat-label">Below 70% (Warning)</div>
            </div>
            <div class="stat-card">
                <div class="stat-number warning"><?php echo $warning_percentage; ?>%</div>
                <div class="stat-label">Warning Rate</div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form">
                <div class="filter-group">
                    <label>Course</label>
                    <select name="course">
                        <option value="">All Courses</option>
                        <?php foreach($courses as $course): ?>
                            <option value="<?php echo $course['id']; ?>" <?php echo $course_id == $course['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($course['code'] . ' - ' . $course['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Department</label>
                    <select name="department">
                        <option value="">All Departments</option>
                        <?php foreach($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" <?php echo $department_id == $dept['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Level</label>
                    <select name="level">
                        <option value="">All Levels</option>
                        <option value="100" <?php echo $level == '100' ? 'selected' : ''; ?>>100 Level</option>
                        <option value="200" <?php echo $level == '200' ? 'selected' : ''; ?>>200 Level</option>
                        <option value="300" <?php echo $level == '300' ? 'selected' : ''; ?>>300 Level</option>
                        <option value="400" <?php echo $level == '400' ? 'selected' : ''; ?>>400 Level</option>
                        <option value="500" <?php echo $level == '500' ? 'selected' : ''; ?>>500 Level</option>
                    </select>
                </div>
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
            </form>
        </div>
        
        <!-- Charts -->
        <div class="charts-row">
            <div class="chart-card">
                <h3>Attendance Distribution</h3>
                <canvas id="attendanceChart" style="max-height: 300px;"></canvas>
            </div>
            <div class="chart-card">
                <h3>Top 5 Courses with Lowest Attendance</h3>
                <canvas id="courseChart" style="max-height: 300px;"></canvas>
            </div>
        </div>
        
        <!-- Students Below 70% Warning List -->
        <?php if(!empty($below_threshold)): ?>
        <div class="warning-students">
            <div class="warning-header">
                ⚠️ STUDENTS BELOW 70% ATTENDANCE THRESHOLD (<?php echo count($below_threshold); ?> students)
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Reg Number</th>
                            <th>Student Name</th>
                            <th>Department</th>
                            <th>Level</th>
                            <th>Course</th>
                            <th>Present</th>
                            <th>Total Classes</th>
                            <th>Attendance %</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($below_threshold as $record): ?>
                            <tr style="background: #fff5f5;">
                                <td><?php echo htmlspecialchars($record['reg_number']); ?></td>
                                <td><?php echo htmlspecialchars($record['student_name']); ?></td>
                                <td><?php echo htmlspecialchars($record['department_name']); ?></td>
                                <td><?php echo $record['current_level']; ?></td>
                                <td><?php echo htmlspecialchars($record['course_code']); ?></td>
                                <td><?php echo $record['present_count']; ?></td>
                                <td><?php echo $record['total_classes']; ?></td>
                                <td>
                                    <strong style="color: #f56565;"><?php echo $record['attendance_percentage']; ?>%</strong>
                                    <div class="progress-bar">
                                        <div class="progress-fill danger" style="width: <?php echo $record['attendance_percentage']; ?>%;"></div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-danger">Warning</span>
                                </td>
                                <td>
                                    <a href="view_student.php?id=<?php echo $record['student_id']; ?>" class="btn btn-primary" style="padding: 4px 8px; font-size: 11px;">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- All Attendance Records -->
        <div class="warning-students">
            <div class="warning-header" style="background: #667eea;">
                📋 All Attendance Records
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Reg Number</th>
                            <th>Student Name</th>
                            <th>Department</th>
                            <th>Course</th>
                            <th>Present</th>
                            <th>Absent</th>
                            <th>Late</th>
                            <th>Total</th>
                            <th>Attendance %</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($attendance_data)): ?>
                            <tr>
                                <td colspan="10" style="text-align: center;">No attendance records found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($attendance_data as $record): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($record['reg_number']); ?></td>
                                    <td><?php echo htmlspecialchars($record['student_name']); ?></td>
                                    <td><?php echo htmlspecialchars($record['department_name']); ?></td>
                                    <td><?php echo htmlspecialchars($record['course_code']); ?></td>
                                    <td><?php echo $record['present_count'] ?? 0; ?></td>
                                    <td><?php echo $record['absent_count'] ?? 0; ?></td>
                                    <td><?php echo $record['late_count'] ?? 0; ?></td>
                                    <td><?php echo $record['total_classes'] ?? 0; ?></td>
                                    <td>
                                        <?php 
                                        $percentage = $record['attendance_percentage'] ?? 0;
                                        $color = $percentage < 70 ? '#f56565' : ($percentage < 80 ? '#ed8936' : '#48bb78');
                                        ?>
                                        <strong style="color: <?php echo $color; ?>;"><?php echo $percentage; ?>%</strong>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $percentage; ?>%; background: <?php echo $color; ?>;"></div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if($percentage < 70): ?>
                                            <span class="badge badge-danger">Below Policy</span>
                                        <?php elseif($percentage < 80): ?>
                                            <span class="badge badge-warning">At Risk</span>
                                        <?php else: ?>
                                            <span class="badge badge-success">Compliant</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Action Buttons -->
        <div class="action-buttons">
            <button onclick="exportToCSV()" class="btn btn-success">📥 Export to CSV</button>
            <button onclick="window.print()" class="btn btn-primary">🖨️ Print Report</button>
            <a href="notify_low_attendance.php" class="btn btn-warning">📧 Notify Below 70% Students</a>
        </div>
    </div>
    
    <script>
        // Attendance Distribution Chart
        const ctx1 = document.getElementById('attendanceChart').getContext('2d');
        new Chart(ctx1, {
            type: 'doughnut',
            data: {
                labels: ['Above 70% (Compliant)', 'Below 70% (Warning)'],
                datasets: [{
                    data: [<?php echo $total_above; ?>, <?php echo $total_below; ?>],
                    backgroundColor: ['#48bb78', '#f56565'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        
        // Course Attendance Chart
        <?php if(!empty($course_summary)): ?>
        const ctx2 = document.getElementById('courseChart').getContext('2d');
        new Chart(ctx2, {
            type: 'bar',
            data: {
                labels: [<?php echo "'" . implode("','", array_map(function($c) { return addslashes($c['code']); }, $course_summary)) . "'"; ?>],
                datasets: [{
                    label: 'Average Attendance %',
                    data: [<?php echo implode(',', array_map(function($c) { return round($c['avg_attendance'], 2); }, $course_summary)); ?>],
                    backgroundColor: '#ed8936',
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        title: {
                            display: true,
                            text: 'Attendance Percentage (%)'
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `Attendance: ${context.raw}%`;
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>
        
        // Export to CSV
        function exportToCSV() {
            let csv = [];
            let rows = document.querySelectorAll('table tbody tr');
            
            // Headers
            let headers = [];
            document.querySelectorAll('table thead th').forEach(header => {
                headers.push(header.innerText);
            });
            csv.push(headers.join(','));
            
            // Data rows
            rows.forEach(row => {
                let rowData = [];
                row.querySelectorAll('td').forEach(cell => {
                    let text = cell.innerText.replace(/,/g, ';');
                    rowData.push(`"${text}"`);
                });
                csv.push(rowData.join(','));
            });
            
            // Download
            const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `attendance_report_<?php echo date('Y-m-d'); ?>.csv`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }
        
        // Show flash message
        const urlParams = new URLSearchParams(window.location.search);
        const message = urlParams.get('message');
        if(message) {
            const flash = document.createElement('div');
            flash.className = 'flash-message';
            flash.style.background = '#48bb78';
            flash.innerHTML = message;
            document.body.appendChild(flash);
            setTimeout(() => flash.remove(), 3000);
        }
    </script>
</body>
</html>
<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole(['student']);

$course_id = $_GET['course_id'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Get student's enrolled courses from student_course_registrations
$stmt = $pdo->prepare("
    SELECT DISTINCT 
        c.id, 
        c.code, 
        c.title, 
        c.credit_unit
    FROM student_course_registrations scr
    JOIN course_offerings co ON scr.offering_id = co.id
    JOIN courses c ON co.course_id = c.id
    WHERE scr.student_id = ? AND scr.status = 'registered'
    ORDER BY c.code
");
$stmt->execute([$_SESSION['student_id']]);
$enrolled_courses = $stmt->fetchAll();

// Get attendance records
$sql = "
    SELECT 
        a.*,
        c.code, 
        c.title, 
        c.credit_unit
    FROM attendance a
    JOIN courses c ON a.course_id = c.id
    WHERE a.student_id = ? AND a.date BETWEEN ? AND ?
";
$params = [$_SESSION['student_id'], $date_from, $date_to];

if ($course_id) {
    $sql .= " AND a.course_id = ?";
    $params[] = $course_id;
}

$sql .= " ORDER BY a.date DESC, a.time DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$attendance_records = $stmt->fetchAll();

// Get attendance summary by course
$stmt = $pdo->prepare("
    SELECT 
        c.id, 
        c.code, 
        c.title,
        COUNT(a.id) as total_classes,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
        ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / NULLIF(COUNT(a.id), 0)) * 100, 2) as attendance_rate
    FROM courses c
    LEFT JOIN attendance a ON c.id = a.course_id AND a.student_id = ?
    WHERE c.id IN (
        SELECT DISTINCT c2.id 
        FROM student_course_registrations scr
        JOIN course_offerings co ON scr.offering_id = co.id
        JOIN courses c2 ON co.course_id = c2.id
        WHERE scr.student_id = ? AND scr.status = 'registered'
    )
    GROUP BY c.id
    ORDER BY attendance_rate ASC
");
$stmt->execute([$_SESSION['student_id'], $_SESSION['student_id']]);
$attendance_summary = $stmt->fetchAll();

// Calculate overall attendance
$overall_present = 0;
$overall_total = 0;
foreach($attendance_summary as $summary) {
    $overall_present += $summary['present_count'];
    $overall_total += $summary['total_classes'];
}
$overall_rate = $overall_total > 0 ? round(($overall_present / $overall_total) * 100, 2) : 0;

// Get courses with attendance below 70%
$below_threshold = array_filter($attendance_summary, function($c) {
    return $c['attendance_rate'] < 70 && $c['attendance_rate'] !== null;
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>My Attendance - Student Portal</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fb;
            padding: 16px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .header {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 24px; color: #2d3748; margin-bottom: 8px; }
        .header p { color: #718096; font-size: 14px; }
        
        .warning-alert {
            background: #feebc8;
            border-left: 4px solid #ed8936;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
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
        .stat-number { font-size: 32px; font-weight: bold; }
        .stat-number.good { color: #48bb78; }
        .stat-number.warning { color: #ed8936; }
        .stat-number.danger { color: #f56565; }
        .stat-label { font-size: 12px; color: #718096; margin-top: 5px; }
        
        .filter-bar {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
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
        .filter-group select, .filter-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }
        .btn {
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }
        .btn:hover { background: #5a67d8; }
        
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
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f7fafc; font-weight: 600; }
        
        .status-present { background: #c6f6d5; color: #22543d; padding: 4px 8px; border-radius: 6px; display: inline-block; font-size: 12px; font-weight: 600; }
        .status-absent { background: #fed7d7; color: #c53030; padding: 4px 8px; border-radius: 6px; display: inline-block; font-size: 12px; font-weight: 600; }
        .status-late { background: #feebc8; color: #7c2d12; padding: 4px 8px; border-radius: 6px; display: inline-block; font-size: 12px; font-weight: 600; }
        
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 5px;
        }
        .progress-fill { height: 100%; transition: width 0.3s; }
        .progress-fill.good { background: #48bb78; }
        .progress-fill.warning { background: #ed8936; }
        .progress-fill.danger { background: #f56565; }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #718096;
        }
        
        @media (max-width: 640px) {
            .filter-form { flex-direction: column; }
            .filter-group { width: 100%; }
            .cards-grid { grid-template-columns: 1fr; }
            th, td { padding: 8px; font-size: 12px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h1>📅 My Attendance</h1>
                    <p>Track your class attendance record</p>
                </div>
                <a href="index.php" style="color: #667eea; text-decoration: none;">← Back to Dashboard</a>
            </div>
        </div>
        
        <!-- Warning Alert -->
        <?php if(!empty($below_threshold)): ?>
            <div class="warning-alert">
                <strong>⚠️ Attendance Warning!</strong><br>
                Your attendance is below 70% in the following course(s):
                <?php 
                $warning_courses = [];
                foreach($below_threshold as $course) {
                    $warning_courses[] = $course['code'] . ' (' . $course['attendance_rate'] . '%)';
                }
                echo implode(', ', $warning_courses);
                ?>
                <br>Please note that students with attendance below 70% may not be eligible to write examinations.
            </div>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number <?php echo $overall_rate >= 70 ? 'good' : ($overall_rate >= 50 ? 'warning' : 'danger'); ?>">
                    <?php echo $overall_rate; ?>%
                </div>
                <div class="stat-label">Overall Attendance Rate</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $overall_present; ?></div>
                <div class="stat-label">Days Present</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $overall_total; ?></div>
                <div class="stat-label">Total Class Days</div>
            </div>
            <div class="stat-card">
                <div class="stat-number <?php echo count($below_threshold) > 0 ? 'danger' : 'good'; ?>">
                    <?php echo count($below_threshold); ?>
                </div>
                <div class="stat-label">Courses Below 70%</div>
            </div>
        </div>
        
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form">
                <div class="filter-group">
                    <label>📖 Course</label>
                    <select name="course_id">
                        <option value="">All Courses</option>
                        <?php foreach($enrolled_courses as $course): ?>
                            <option value="<?php echo $course['id']; ?>" <?php echo $course_id == $course['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($course['code'] . ' - ' . $course['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>📅 Date From</label>
                    <input type="date" name="date_from" value="<?php echo $date_from; ?>">
                </div>
                <div class="filter-group">
                    <label>📅 Date To</label>
                    <input type="date" name="date_to" value="<?php echo $date_to; ?>">
                </div>
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn">📊 Apply Filter</button>
                </div>
            </form>
        </div>
        
        <!-- Attendance Summary by Course -->
        <div class="cards-grid">
            <div class="card">
                <div class="card-header">📊 Attendance by Course</div>
                <div class="card-body">
                    <?php if(empty($attendance_summary)): ?>
                        <div class="empty-state">
                            <p>No attendance records found.</p>
                            <p style="font-size: 12px; margin-top: 10px;">Attendance will appear here once your lecturers mark your presence.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach($attendance_summary as $course): ?>
                            <?php 
                            $rate = $course['attendance_rate'] ?? 0;
                            $color = $rate >= 70 ? 'good' : ($rate >= 50 ? 'warning' : 'danger');
                            ?>
                            <div style="margin-bottom: 20px;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                    <div>
                                        <strong><?php echo htmlspecialchars($course['code']); ?></strong>
                                        <span style="font-size: 11px; color: #718096; margin-left: 8px;"><?php echo htmlspecialchars($course['title']); ?></span>
                                    </div>
                                    <span style="font-weight: bold; color: <?php echo $color == 'good' ? '#48bb78' : ($color == 'warning' ? '#ed8936' : '#f56565'); ?>;">
                                        <?php echo $rate; ?>%
                                    </span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill <?php echo $color; ?>" style="width: <?php echo $rate; ?>%;"></div>
                                </div>
                                <div style="font-size: 11px; color: #718096; margin-top: 6px; display: flex; gap: 15px; flex-wrap: wrap;">
                                    <span>✅ Present: <?php echo $course['present_count']; ?></span>
                                    <span>❌ Absent: <?php echo $course['absent_count']; ?></span>
                                    <span>⏰ Late: <?php echo $course['late_count']; ?></span>
                                    <span>📚 Total: <?php echo $course['total_classes']; ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">📋 Recent Attendance Records</div>
                <div class="card-body">
                    <div class="table-responsive">
                        <?php if(empty($attendance_records)): ?>
                            <div class="empty-state">
                                <p>No attendance records found for this period.</p>
                            </div>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Course</th>
                                        <th>Time</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach(array_slice($attendance_records, 0, 15) as $record): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($record['date'])); ?></td>
                                            <td><?php echo htmlspecialchars($record['code']); ?></td>
                                            <td><?php echo date('g:i A', strtotime($record['time'])); ?></td>
                                            <td>
                                                <?php if($record['status'] == 'present'): ?>
                                                    <span class="status-present">✓ Present</span>
                                                <?php elseif($record['status'] == 'absent'): ?>
                                                    <span class="status-absent">✗ Absent</span>
                                                <?php else: ?>
                                                    <span class="status-late">⏰ Late</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if(count($attendance_records) > 15): ?>
                                <p style="text-align: center; margin-top: 15px; font-size: 12px; color: #718096;">
                                    Showing 15 of <?php echo count($attendance_records); ?> records
                                </p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Debug info (remove in production) -->
        <?php if(empty($enrolled_courses)): ?>
            <div class="card" style="background: #fee2e2;">
                <div class="card-body" style="text-align: center;">
                    <p style="color: #c53030;">⚠️ You are not registered for any courses this semester.</p>
                    <p style="font-size: 12px; margin-top: 10px;">Please complete your course registration first.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
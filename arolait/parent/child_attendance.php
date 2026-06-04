<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole(['parent']);

// Get parent's linked student
$stmt = $pdo->prepare("
    SELECT p.student_id, s.reg_number, s.current_level,
           CONCAT(u.first_name, ' ', u.last_name) as student_name,
           d.name as department_name
    FROM parents p
    JOIN students s ON p.student_id = s.id
    JOIN users u ON s.user_id = u.id
    JOIN departments d ON s.department_id = d.id
    WHERE p.id = ?
");
$stmt->execute([$_SESSION['parent_id']]);
$parent = $stmt->fetch();

$student_id = $parent['student_id'];
$course_id = $_GET['course_id'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Get student's enrolled courses
$stmt = $pdo->prepare("
    SELECT DISTINCT c.id, c.code, c.title, c.credit_unit
    FROM course_registrations cr
    JOIN courses c ON cr.course_id = c.id
    WHERE cr.student_id = ? AND cr.is_dropped = 0
    ORDER BY c.code
");
$stmt->execute([$student_id]);
$enrolled_courses = $stmt->fetchAll();

// Get attendance records
$sql = "
    SELECT 
        a.*,
        c.code, c.title
    FROM attendance a
    JOIN courses c ON a.course_id = c.id
    WHERE a.student_id = ? AND a.date BETWEEN ? AND ?
";
$params = [$student_id, $date_from, $date_to];

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
        c.id, c.code, c.title,
        COUNT(a.id) as total_classes,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
        ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / NULLIF(COUNT(a.id), 0)) * 100, 2) as attendance_rate
    FROM courses c
    LEFT JOIN attendance a ON c.id = a.course_id AND a.student_id = ?
    WHERE c.id IN (SELECT course_id FROM course_registrations WHERE student_id = ? AND is_dropped = 0)
    GROUP BY c.id
    ORDER BY attendance_rate ASC
");
$stmt->execute([$student_id, $student_id]);
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
    <title>Child's Attendance - Parent Portal</title>
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
        }
        .header h1 { font-size: 24px; color: #2d3748; margin-bottom: 8px; }
        
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
        }
        .stat-number { font-size: 28px; font-weight: bold; }
        .stat-number.good { color: #48bb78; }
        .stat-number.warning { color: #ed8936; }
        .stat-number.danger { color: #f56565; }
        .stat-label { font-size: 12px; color: #718096; margin-top: 5px; }
        
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
        .filter-group label { display: block; margin-bottom: 5px; font-size: 12px; font-weight: 600; }
        .filter-group select, .filter-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
        }
        .btn {
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 20px;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            font-weight: 600;
        }
        .card-body { padding: 20px; }
        
        .course-item {
            margin-bottom: 15px;
            padding: 10px;
            background: #f7fafc;
            border-radius: 8px;
        }
        .course-code { font-weight: 600; }
        
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
        
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f7fafc; font-weight: 600; }
        
        .status-present { background: #c6f6d5; color: #22543d; padding: 4px 8px; border-radius: 6px; display: inline-block; font-size: 12px; }
        .status-absent { background: #fed7d7; color: #c53030; padding: 4px 8px; border-radius: 6px; display: inline-block; font-size: 12px; }
        .status-late { background: #feebc8; color: #7c2d12; padding: 4px 8px; border-radius: 6px; display: inline-block; font-size: 12px; }
        
        @media (max-width: 640px) {
            .filter-form { flex-direction: column; }
            .filter-group { width: 100%; }
            th, td { padding: 8px; font-size: 12px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                <div>
                    <h1>📅 Child's Attendance Record</h1>
                    <p><?php echo htmlspecialchars($parent['student_name']); ?> (<?php echo $parent['reg_number']; ?>) | <?php echo htmlspecialchars($parent['department_name']); ?></p>
                </div>
                <a href="index.php" style="color: #667eea; text-decoration: none;">← Back to Dashboard</a>
            </div>
        </div>
        
        <!-- Warning Alert -->
        <?php if(!empty($below_threshold)): ?>
            <div class="warning-alert">
                <strong>⚠️ Attendance Warning!</strong><br>
                Your ward's attendance is below 70% in the following course(s):
                <?php foreach($below_threshold as $course): ?>
                    • <?php echo $course['code']; ?> (<?php echo $course['attendance_rate']; ?>%)
                <?php endforeach; ?>
                Please remind them that students with attendance below 70% may not be eligible to write examinations.
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
                <div class="stat-number"><?php echo count($below_threshold); ?></div>
                <div class="stat-label">Courses Below 70%</div>
            </div>
        </div>
        
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form">
                <div class="filter-group">
                    <label>Course</label>
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
                    <label>Date From</label>
                    <input type="date" name="date_from" value="<?php echo $date_from; ?>">
                </div>
                <div class="filter-group">
                    <label>Date To</label>
                    <input type="date" name="date_to" value="<?php echo $date_to; ?>">
                </div>
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn">📊 Filter</button>
                </div>
            </form>
        </div>
        
        <!-- Attendance by Course -->
        <div class="card">
            <div class="card-header">📊 Attendance by Course</div>
            <div class="card-body">
                <?php foreach($attendance_summary as $course): ?>
                    <?php 
                    $rate = $course['attendance_rate'] ?? 0;
                    $color = $rate >= 70 ? 'good' : ($rate >= 50 ? 'warning' : 'danger');
                    ?>
                    <div class="course-item">
                        <div class="course-code"><?php echo htmlspecialchars($course['code']); ?> - <?php echo htmlspecialchars($course['title']); ?></div>
                        <div style="display: flex; justify-content: space-between; margin-top: 5px;">
                            <span>Present: <?php echo $course['present_count']; ?></span>
                            <span>Absent: <?php echo $course['absent_count']; ?></span>
                            <span>Late: <?php echo $course['late_count']; ?></span>
                            <span><strong>Rate: <?php echo $rate; ?>%</strong></span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill <?php echo $color; ?>" style="width: <?php echo $rate; ?>%;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if(empty($attendance_summary)): ?>
                    <p style="text-align: center; color: #718096;">No attendance records found.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Recent Attendance Records -->
        <div class="card">
            <div class="card-header">📋 Recent Attendance Records</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr><th>Date</th><th>Course</th><th>Time</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach(array_slice($attendance_records, 0, 20) as $record): ?>
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
                            <?php if(empty($attendance_records)): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center;">No attendance records found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
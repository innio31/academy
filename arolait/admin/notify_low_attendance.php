<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

$policy_threshold = 70;
$notified_count = 0;

// Get students below threshold with their course attendance
$stmt = $pdo->prepare("
    SELECT 
        s.id as student_id,
        s.reg_number,
        CONCAT(u.first_name, ' ', u.last_name) as student_name,
        u.email as student_email,
        c.id as course_id,
        c.code as course_code,
        c.title as course_title,
        COUNT(DISTINCT a.id) as total_classes,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
        ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT a.id), 0)) * 100, 2) as attendance_percentage
    FROM students s
    JOIN users u ON s.user_id = u.id
    LEFT JOIN attendance a ON s.id = a.student_id
    LEFT JOIN courses c ON a.course_id = c.id
    GROUP BY s.id, c.id
    HAVING attendance_percentage < ? AND attendance_percentage IS NOT NULL
    ORDER BY attendance_percentage ASC
");
$stmt->execute([$policy_threshold]);
$low_attendance_students = $stmt->fetchAll();

// Group by student
$students_to_notify = [];
foreach($low_attendance_students as $record) {
    if (!isset($students_to_notify[$record['student_id']])) {
        $students_to_notify[$record['student_id']] = [
            'name' => $record['student_name'],
            'email' => $record['student_email'],
            'reg_number' => $record['reg_number'],
            'courses' => []
        ];
    }
    $students_to_notify[$record['student_id']]['courses'][] = [
        'code' => $record['course_code'],
        'title' => $record['course_title'],
        'attendance' => $record['attendance_percentage']
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notify Low Attendance Students</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fb;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        .card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-primary {
            background: #667eea;
            color: white;
        }
        textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-family: monospace;
            margin: 10px 0;
        }
        .student-list {
            background: #f7fafc;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .badge {
            background: #f56565;
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>📧 Notify Students Below 70% Attendance</h1>
            <p>Send email notifications to students whose attendance is below the 70% policy threshold.</p>
            
            <h3>Students to Notify (<?php echo count($students_to_notify); ?>)</h3>
            
            <?php foreach($students_to_notify as $student): ?>
                <div class="student-list">
                    <strong><?php echo htmlspecialchars($student['name']); ?></strong> (<?php echo $student['reg_number']; ?>)<br>
                    Email: <?php echo $student['email']; ?><br>
                    Courses with low attendance:
                    <ul>
                        <?php foreach($student['courses'] as $course): ?>
                            <li><?php echo $course['code']; ?> - <?php echo $course['attendance']; ?>% attendance <span class="badge">Warning</span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
            
            <form method="POST" action="send_attendance_notifications.php">
                <textarea name="message" rows="5" placeholder="Custom message to students...">Dear Student,

This is to inform you that your attendance in the following course(s) is below the required 70% threshold. Please note that students with attendance below 70% may not be eligible to write examinations.

Please ensure you attend all remaining classes to improve your attendance.

Regards,
Academic Affairs Office</textarea>
                
                <button type="submit" class="btn btn-primary" onclick="return confirm('Send notifications to <?php echo count($students_to_notify); ?> students?')">📧 Send Notifications</button>
                <a href="attendance_reports.php" style="margin-left: 10px;">← Back to Reports</a>
            </form>
        </div>
    </div>
</body>
</html>
<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole(['parent']);

// Get parent's linked student
$stmt = $pdo->prepare("
    SELECT p.student_id, s.reg_number, s.current_level,
           CONCAT(u.first_name, ' ', u.last_name) as student_name
    FROM parents p
    JOIN students s ON p.student_id = s.id
    JOIN users u ON s.user_id = u.id
    WHERE p.id = ?
");
$stmt->execute([$_SESSION['parent_id']]);
$parent = $stmt->fetch();

$student_id = $parent['student_id'];

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

// Get registered courses for current semester
$current_courses = [];
$total_credits = 0;
if ($current_semester) {
    $stmt = $pdo->prepare("
        SELECT c.*, cr.registered_at
        FROM course_registrations cr
        JOIN courses c ON cr.course_id = c.id
        WHERE cr.student_id = ? AND cr.semester_id = ? AND cr.is_dropped = 0
        ORDER BY c.code
    ");
    $stmt->execute([$student_id, $current_semester['id']]);
    $current_courses = $stmt->fetchAll();
    
    foreach($current_courses as $course) {
        $total_credits += $course['credit_unit'];
    }
}

// Get course history
$stmt = $pdo->prepare("
    SELECT 
        cr.*,
        c.code, c.title, c.credit_unit,
        s.name as semester_name,
        a.name as session_name,
        r.grade, r.total_score
    FROM course_registrations cr
    JOIN courses c ON cr.course_id = c.id
    JOIN semesters s ON cr.semester_id = s.id
    JOIN academic_sessions a ON s.session_id = a.id
    LEFT JOIN results r ON r.course_id = c.id AND r.student_id = cr.student_id AND r.semester_id = cr.semester_id
    WHERE cr.student_id = ? AND cr.is_dropped = 0
    ORDER BY a.start_date DESC, s.id DESC, c.code
");
$stmt->execute([$student_id]);
$course_history = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Child's Courses - Parent Portal</title>
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
        .stat-number { font-size: 28px; font-weight: bold; color: #667eea; }
        .stat-label { font-size: 12px; color: #718096; margin-top: 5px; }
        
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
        .card-header.green { background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); }
        
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f7fafc; font-weight: 600; }
        
        .course-code { font-weight: 600; color: #2d3748; }
        .credit-badge {
            background: #e9f5ff;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            display: inline-block;
        }
        
        .grade-A { color: #48bb78; font-weight: bold; }
        .grade-AB { color: #4299e1; font-weight: bold; }
        .grade-B { color: #4299e1; font-weight: bold; }
        .grade-BC { color: #ed8936; font-weight: bold; }
        .grade-C { color: #ed8936; font-weight: bold; }
        .grade-CD { color: #ecc94b; font-weight: bold; }
        .grade-D { color: #ecc94b; font-weight: bold; }
        .grade-E { color: #f56565; font-weight: bold; }
        .grade-F { color: #f56565; font-weight: bold; }
        
        @media (max-width: 640px) {
            th, td { padding: 8px; font-size: 12px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                <div>
                    <h1>📖 Child's Courses</h1>
                    <p><?php echo htmlspecialchars($parent['student_name']); ?> (<?php echo $parent['reg_number']; ?>) | Level <?php echo $parent['current_level']; ?></p>
                </div>
                <a href="index.php" style="color: #667eea; text-decoration: none;">← Back to Dashboard</a>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo count($current_courses); ?></div>
                <div class="stat-label">Current Courses</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_credits; ?></div>
                <div class="stat-label">Total Credits (Current)</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count($course_history); ?></div>
                <div class="stat-label">Courses Taken (Total)</div>
            </div>
        </div>
        
        <!-- Current Semester Courses -->
        <div class="card">
            <div class="card-header green">
                📚 Current Semester Courses - <?php echo $current_semester ? $current_semester['name'] . ' Semester, ' . $current_semester['session_name'] : 'No active semester'; ?>
            </div>
            <div class="table-responsive">
                <?php if(!empty($current_courses)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>S/N</th>
                                <th>Course Code</th>
                                <th>Course Title</th>
                                <th>Credit Unit</th>
                                <th>Registered On</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sn = 1; foreach($current_courses as $course): ?>
                                <tr>
                                    <td><?php echo $sn++; ?></td>
                                    <td class="course-code"><?php echo htmlspecialchars($course['code']); ?></td>
                                    <td><?php echo htmlspecialchars($course['title']); ?></td>
                                    <td><span class="credit-badge"><?php echo $course['credit_unit']; ?> Units</span></td>
                                    <td><?php echo date('M j, Y', strtotime($course['registered_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="padding: 40px; text-align: center; color: #718096;">
                        No courses registered for current semester.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Course History -->
        <div class="card">
            <div class="card-header">
                📜 Course History (All Semesters)
            </div>
            <div class="table-responsive">
                <?php if(!empty($course_history)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Session</th>
                                <th>Semester</th>
                                <th>Course Code</th>
                                <th>Course Title</th>
                                <th>Credit Unit</th>
                                <th>Grade</th>
                                <th>Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($course_history as $course): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($course['session_name']); ?></td>
                                    <td><?php echo htmlspecialchars($course['semester_name']); ?></td>
                                    <td class="course-code"><?php echo htmlspecialchars($course['code']); ?></td>
                                    <td><?php echo htmlspecialchars($course['title']); ?></td>
                                    <td><?php echo $course['credit_unit']; ?></td>
                                    <td class="grade-<?php echo $course['grade']; ?>">
                                        <?php echo $course['grade'] ?: 'Pending'; ?>
                                    </td>
                                    <td><?php echo $course['total_score'] ? number_format($course['total_score'], 2) . '%' : '-'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="padding: 40px; text-align: center; color: #718096;">
                        No course history found.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
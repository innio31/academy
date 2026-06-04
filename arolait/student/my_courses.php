<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole(['student']);

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

// Get all registered courses for current semester using correct table structure
$registered_courses = [];
$total_credits = 0;
if ($current_semester) {
    $stmt = $pdo->prepare("
        SELECT 
            c.*, 
            scr.registered_at, 
            scr.status,
            CONCAT(u.first_name, ' ', u.last_name) as lecturer_name,
            co.semester_id,
            co.id as offering_id
        FROM student_course_registrations scr
        JOIN course_offerings co ON scr.offering_id = co.id
        JOIN courses c ON co.course_id = c.id
        LEFT JOIN users u ON co.lecturer_id = u.id
        WHERE scr.student_id = ? AND co.semester_id = ? AND scr.status = 'registered'
        ORDER BY c.code
    ");
    $stmt->execute([$_SESSION['student_id'], $current_semester['id']]);
    $registered_courses = $stmt->fetchAll();
    
    foreach($registered_courses as $course) {
        $total_credits += $course['credit_unit'];
    }
}

// Get course registration history (past semesters) using correct table structure
$stmt = $pdo->prepare("
    SELECT 
        scr.registered_at,
        scr.status,
        c.id as course_id,
        c.code, 
        c.title, 
        c.credit_unit,
        s.name as semester_name,
        a.name as session_name,
        a.start_date as session_start,
        r.grade, 
        r.total_score,
        r.grade_point,
        r.course_unit_point,
        CONCAT(lec.first_name, ' ', lec.last_name) as lecturer_name
    FROM student_course_registrations scr
    JOIN course_offerings co ON scr.offering_id = co.id
    JOIN courses c ON co.course_id = c.id
    JOIN semesters s ON co.semester_id = s.id
    JOIN academic_sessions a ON s.session_id = a.id
    LEFT JOIN users lec ON co.lecturer_id = lec.id
    LEFT JOIN results r ON r.course_id = c.id AND r.student_id = scr.student_id AND r.semester_id = co.semester_id
    WHERE scr.student_id = ? AND scr.status = 'registered'
    ORDER BY a.start_date DESC, s.id DESC, c.code
");
$stmt->execute([$_SESSION['student_id']]);
$course_history = $stmt->fetchAll();

// Calculate total courses taken across all semesters
$total_courses_taken = count($course_history);

// Calculate total credits across all semesters
$total_credits_all = 0;
foreach($course_history as $course) {
    $total_credits_all += $course['credit_unit'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>My Courses - Student Portal</title>
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
        .stat-number { font-size: 32px; font-weight: bold; color: #667eea; }
        .stat-label { font-size: 12px; color: #718096; margin-top: 5px; }
        
        .card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 20px;
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
        
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f7fafc; font-weight: 600; }
        
        .course-code { font-weight: 600; color: #2d3748; }
        .credit-badge {
            background: #e9f5ff;
            padding: 4px 10px;
            border-radius: 20px;
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
        
        .btn {
            padding: 8px 16px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: background 0.3s;
        }
        .btn:hover { background: #5a67d8; }
        
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }
        .status-active { background: #c6f6d5; color: #22543d; }
        .status-completed { background: #bee3f8; color: #2c5282; }
        
        .empty-state {
            text-align: center;
            padding: 60px;
            color: #718096;
        }
        
        @media (max-width: 640px) {
            th, td { padding: 8px; font-size: 12px; }
            .stats-grid { gap: 10px; }
            .stat-number { font-size: 24px; }
        }
        
        @media print {
            .header a, .btn { display: none; }
            body { background: white; padding: 0; }
            .card { box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h1>📖 My Courses</h1>
                    <p>View all your registered courses and academic history</p>
                </div>
                <a href="index.php" style="color: #667eea; text-decoration: none;">← Back to Dashboard</a>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo count($registered_courses); ?></div>
                <div class="stat-label">Current Courses</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_credits; ?></div>
                <div class="stat-label">Current Credits</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_courses_taken; ?></div>
                <div class="stat-label">Total Courses Taken</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_credits_all; ?></div>
                <div class="stat-label">Total Credits (All)</div>
            </div>
        </div>
        
        <!-- Current Semester Courses -->
        <div class="card">
            <div class="card-header green">
                📚 Current Semester Courses
                <?php if($current_semester): ?>
                    - <?php echo $current_semester['name']; ?> Semester, <?php echo $current_semester['session_name']; ?>
                <?php else: ?>
                    - No active semester
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <?php if(!empty($registered_courses)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>S/N</th>
                                <th>Course Code</th>
                                <th>Course Title</th>
                                <th>Credit Unit</th>
                                <th>Lecturer</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sn = 1; foreach($registered_courses as $course): ?>
                                <tr>
                                    <td><?php echo $sn++; ?></td>
                                    <td class="course-code"><?php echo htmlspecialchars($course['code']); ?></td>
                                    <td><?php echo htmlspecialchars($course['title']); ?></td>
                                    <td><span class="credit-badge"><?php echo $course['credit_unit']; ?> Unit(s)</span></td>
                                    <td><?php echo htmlspecialchars($course['lecturer_name'] ?? 'Not Assigned'); ?></td>
                                    <td>
                                        <span class="status-badge status-active">✓ Active</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <p>📭 No courses registered for current semester.</p>
                        <?php if($current_semester): ?>
                            <br>
                            <a href="course_registration.php" class="btn">📝 Register Now</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Course History (All Semesters) -->
        <div class="card">
            <div class="card-header orange">
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
                                <th>Lecturer</th>
                                <th>Grade</th>
                                <th>Score</th>
                                <th>GP</th>
                                <th>CUP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($course_history as $course): 
                                $grade_class = $course['grade'] ? 'grade-' . $course['grade'] : '';
                                if ($course['grade'] === 'AB') $grade_class = 'grade-AB';
                                if ($course['grade'] === 'BC') $grade_class = 'grade-BC';
                                if ($course['grade'] === 'CD') $grade_class = 'grade-CD';
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($course['session_name']); ?></td>
                                    <td><?php echo htmlspecialchars($course['semester_name']); ?></td>
                                    <td class="course-code"><?php echo htmlspecialchars($course['code']); ?></td>
                                    <td><?php echo htmlspecialchars($course['title']); ?></td>
                                    <td><?php echo $course['credit_unit']; ?></td>
                                    <td><small><?php echo htmlspecialchars($course['lecturer_name'] ?? 'N/A'); ?></small></td>
                                    <td class="<?php echo $grade_class; ?>">
                                        <?php echo $course['grade'] ?: '—'; ?>
                                    </td>
                                    <td><?php echo $course['total_score'] ? number_format($course['total_score'], 1) . '%' : '—'; ?></td>
                                    <td><?php echo $course['grade_point'] ? number_format($course['grade_point'], 2) : '—'; ?></td>
                                    <td><?php echo $course['course_unit_point'] ? number_format($course['course_unit_point'], 2) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <p>📭 No course history found.</p>
                        <p style="font-size: 12px; margin-top: 10px;">Complete your course registration to see your history.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Registration Status Info -->
        <?php if($current_semester): ?>
        <div class="card">
            <div class="card-header">
                ℹ️ Registration Information
            </div>
            <div style="padding: 20px;">
                <p><strong>Current Semester:</strong> <?php echo $current_semester['name']; ?> Semester, <?php echo $current_semester['session_name']; ?></p>
                <p><strong>Registered Courses:</strong> <?php echo count($registered_courses); ?> course(s)</p>
                <p><strong>Total Credits:</strong> <?php echo $total_credits; ?> credit unit(s)</p>
                <?php if(empty($registered_courses)): ?>
                    <p style="color: #ed8936; margin-top: 10px;">⚠️ You have not registered for any courses this semester. Please complete your registration.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
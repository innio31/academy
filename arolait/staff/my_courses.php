<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole(['staff']);

// Get current staff user_id from session (not staff_id)
$user_id = $_SESSION['user_id'];

// Get current session and semester
$current_session = $pdo->query("SELECT id, name FROM academic_sessions WHERE is_current = 1 LIMIT 1")->fetch();
$current_semester = $pdo->query("SELECT id, name FROM semesters WHERE is_current = 1 LIMIT 1")->fetch();

// Get filter parameters
$session_filter = $_GET['session'] ?? ($current_session['id'] ?? '');
$semester_filter = $_GET['semester'] ?? ($current_semester['id'] ?? '');

// Get all sessions for filter
$sessions = $pdo->query("SELECT id, name, is_current FROM academic_sessions ORDER BY start_date DESC")->fetchAll();

// Get all semesters for filter
$semesters = $pdo->query("
    SELECT s.*, a.name as session_name 
    FROM semesters s 
    JOIN academic_sessions a ON s.session_id = a.id 
    ORDER BY a.start_date DESC, s.id
")->fetchAll();

// Build query for course offerings using your schema
$sql = "
    SELECT 
        co.id as offering_id,
        c.id as course_id,
        c.code,
        c.title,
        c.credit_unit,
        d.id as department_id,
        d.name as department_name,
        d.code as department_code,
        s.id as semester_id,
        s.name as semester_name,
        a.id as session_id,
        a.name as session_name,
        a.is_current as is_current_session,
        COUNT(DISTINCT scr.student_id) as enrolled_students
    FROM course_offerings co
    JOIN courses c ON co.course_id = c.id
    JOIN departments d ON c.department_id = d.id
    JOIN semesters s ON co.semester_id = s.id
    JOIN academic_sessions a ON s.session_id = a.id
    LEFT JOIN student_course_registrations scr ON co.id = scr.offering_id AND scr.status = 'registered'
    WHERE co.lecturer_id = ?
";

$params = [$user_id];

if ($session_filter) {
    $sql .= " AND a.id = ?";
    $params[] = $session_filter;
}
if ($semester_filter) {
    $sql .= " AND s.id = ?";
    $params[] = $semester_filter;
}

$sql .= " GROUP BY co.id ORDER BY a.start_date DESC, s.id DESC, c.code";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$course_offerings = $stmt->fetchAll();

// Get statistics
$total_courses = count($course_offerings);
$total_students = array_sum(array_column($course_offerings, 'enrolled_students'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>My Courses - Staff Portal</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f5f7fb;
            padding: 16px;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        
        .header {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 24px; color: #2d3748; margin-bottom: 8px; }
        .header p { color: #718096; font-size: 14px; }
        
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            padding: 15px 20px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-number { font-size: 28px; font-weight: bold; color: #667eea; }
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
        .filter-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            background: white;
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
        .btn-outline { background: transparent; border: 1px solid #667eea; color: #667eea; }
        .btn-outline:hover { background: #667eea; color: white; }
        .btn-success { background: #48bb78; color: white; }
        .btn-info { background: #4299e1; color: white; }
        
        .courses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 20px;
        }
        .course-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .course-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.15);
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 16px;
        }
        .course-code { font-size: 14px; font-family: monospace; opacity: 0.9; margin-bottom: 4px; }
        .course-title { font-size: 18px; font-weight: 600; margin-bottom: 8px; }
        .course-meta { display: flex; gap: 15px; font-size: 12px; opacity: 0.85; flex-wrap: wrap; }
        
        .card-body { padding: 16px; }
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
        }
        .info-label { font-size: 13px; color: #718096; }
        .info-value { font-size: 14px; font-weight: 500; color: #2d3748; }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-current { background: #c6f6d5; color: #22543d; }
        
        .card-actions {
            display: flex;
            gap: 8px;
            padding: 16px;
            background: #f7fafc;
            flex-wrap: wrap;
        }
        .btn-small { padding: 6px 12px; font-size: 12px; }
        
        .empty-state {
            background: white;
            border-radius: 12px;
            padding: 60px;
            text-align: center;
        }
        .empty-state p { color: #718096; margin-bottom: 20px; }
        
        @media (max-width: 768px) {
            .filter-form { flex-direction: column; }
            .filter-group { width: 100%; }
            .courses-grid { grid-template-columns: 1fr; }
            .course-meta { flex-direction: column; gap: 5px; }
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
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h1>📖 My Courses</h1>
                    <p>View all courses assigned to you for teaching</p>
                </div>
                <a href="index.php" style="color: #667eea; text-decoration: none;">← Back to Dashboard</a>
            </div>
        </div>
        
        <div class="stats-bar">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_courses; ?></div>
                <div class="stat-label">Total Courses</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_students; ?></div>
                <div class="stat-label">Enrolled Students</div>
            </div>
        </div>
        
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form">
                <div class="filter-group">
                    <label>Academic Session</label>
                    <select name="session" onchange="this.form.submit()">
                        <option value="">All Sessions</option>
                        <?php foreach($sessions as $session): ?>
                            <option value="<?php echo $session['id']; ?>" <?php echo $session_filter == $session['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($session['name']); ?>
                                <?php echo $session['is_current'] ? '(Current)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Semester</label>
                    <select name="semester" onchange="this.form.submit()">
                        <option value="">All Semesters</option>
                        <?php foreach($semesters as $semester): ?>
                            <option value="<?php echo $semester['id']; ?>" <?php echo $semester_filter == $semester['id'] ? 'selected' : ''; ?>>
                                <?php echo $semester['name'] . ' - ' . $semester['session_name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if($session_filter || $semester_filter): ?>
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <a href="my_courses.php" class="btn btn-outline">Clear Filters</a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
        
        <?php if(!empty($course_offerings)): ?>
            <div class="courses-grid">
                <?php foreach($course_offerings as $course): ?>
                    <div class="course-card">
                        <div class="card-header">
                            <div class="course-code"><?php echo htmlspecialchars($course['code']); ?></div>
                            <div class="course-title"><?php echo htmlspecialchars($course['title']); ?></div>
                            <div class="course-meta">
                                <span>🎓 <?php echo $course['credit_unit']; ?> Units</span>
                                <span>🏛️ <?php echo htmlspecialchars($course['department_code']); ?></span>
                                <?php if($course['is_current_session']): ?>
                                    <span class="badge badge-current">Current Session</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="card-body">
                            <div class="info-row">
                                <span class="info-label">📅 Semester</span>
                                <span class="info-value"><?php echo $course['semester_name'] . ' - ' . $course['session_name']; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">👨‍🎓 Enrolled Students</span>
                                <span class="info-value"><?php echo $course['enrolled_students']; ?> students</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">📚 Department</span>
                                <span class="info-value"><?php echo htmlspecialchars($course['department_name']); ?></span>
                            </div>
                        </div>
                        
                        <div class="card-actions">
                            <a href="take_attendance.php?course_id=<?php echo $course['course_id']; ?>" class="btn btn-primary btn-small">
                                ✅ Take Attendance
                            </a>
                            <a href="upload_results.php?course_id=<?php echo $course['course_id']; ?>" class="btn btn-success btn-small">
                                📝 Upload Results
                            </a>
                            <a href="attendance_history.php?course_id=<?php echo $course['course_id']; ?>" class="btn btn-info btn-small">
                                📊 View Report
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <p>📭 No courses found for the selected filters.</p>
                <?php if($session_filter || $semester_filter): ?>
                    <a href="my_courses.php" class="btn btn-primary">Clear Filters</a>
                <?php else: ?>
                    <p style="font-size: 14px;">You haven't been assigned any courses yet. Contact your HOD or administrator.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        const urlParams = new URLSearchParams(window.location.search);
        const message = urlParams.get('message');
        const error = urlParams.get('error');
        
        if(message) {
            const flash = document.createElement('div');
            flash.className = 'flash-message flash-success';
            flash.innerHTML = message;
            document.body.appendChild(flash);
            setTimeout(() => flash.remove(), 3000);
        }
        if(error) {
            const flash = document.createElement('div');
            flash.className = 'flash-message flash-error';
            flash.innerHTML = error;
            document.body.appendChild(flash);
            setTimeout(() => flash.remove(), 3000);
        }
    </script>
</body>
</html>
<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole(['staff']);

$course_id = $_GET['course_id'] ?? 0;
$session_filter = $_GET['session'] ?? '';
$semester_filter = $_GET['semester'] ?? '';

// Get staff's assigned courses from course_offerings
$stmt = $pdo->prepare("
    SELECT 
        c.*, 
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

// Get all sessions for filter
$sessions = $pdo->query("SELECT id, name, is_current FROM academic_sessions ORDER BY start_date DESC")->fetchAll();

// Get all semesters for filter
$semesters = $pdo->query("
    SELECT s.*, a.name as session_name 
    FROM semesters s 
    JOIN academic_sessions a ON s.session_id = a.id 
    ORDER BY a.start_date DESC, s.id
")->fetchAll();

// Variables for results data
$results = [];
$course_info = null;
$summary_stats = [
    'total_students' => 0,
    'results_submitted' => 0,
    'pending' => 0,
    'average_score' => 0,
    'highest_score' => 0,
    'lowest_score' => 0,
    'total_cup' => 0,
    'total_cu' => 0,
    'gpa' => 0
];

if ($course_id) {
    // Get course details
    $stmt = $pdo->prepare("
        SELECT c.*, d.name as department_name 
        FROM courses c
        JOIN departments d ON c.department_id = d.id
        WHERE c.id = ?
    ");
    $stmt->execute([$course_id]);
    $course_info = $stmt->fetch();
    
    if ($course_info) {
        // Get offering_id for this course and staff
        $stmt = $pdo->prepare("
            SELECT co.id as offering_id, co.semester_id
            FROM course_offerings co
            WHERE co.course_id = ? AND co.lecturer_id = ?
            LIMIT 1
        ");
        $stmt->execute([$course_id, $_SESSION['user_id']]);
        $offering = $stmt->fetch();
        $offering_id = $offering ? $offering['offering_id'] : 0;
        $default_semester_id = $offering ? $offering['semester_id'] : 0;
        
        // Use filters or defaults
        $session_id = $session_filter ?: null;
        $semester_id = $semester_filter ?: $default_semester_id;
        
        // Build query for results
        $sql = "
            SELECT 
                r.*,
                s.id as student_id,
                s.reg_number,
                CONCAT(u.first_name, ' ', u.last_name) as student_name,
                u.email as student_email
            FROM results r
            JOIN students s ON r.student_id = s.id
            JOIN users u ON s.user_id = u.id
            WHERE r.course_id = ?
        ";
        $params = [$course_id];
        
        if ($session_id) {
            $sql .= " AND r.session_id = ?";
            $params[] = $session_id;
        }
        if ($semester_id) {
            $sql .= " AND r.semester_id = ?";
            $params[] = $semester_id;
        }
        
        $sql .= " ORDER BY u.last_name ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();
        
        // Get enrolled students count for this course
        if ($offering_id) {
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT scr.student_id) as total
                FROM student_course_registrations scr
                WHERE scr.offering_id = ? AND scr.status = 'registered'
            ");
            $stmt->execute([$offering_id]);
            $summary_stats['total_students'] = $stmt->fetch()['total'];
        }
        
        // Calculate statistics
        $summary_stats['results_submitted'] = count($results);
        $summary_stats['pending'] = $summary_stats['total_students'] - $summary_stats['results_submitted'];
        
        if (!empty($results)) {
            $scores = array_column($results, 'total_score');
            $summary_stats['average_score'] = round(array_sum($scores) / count($scores), 2);
            $summary_stats['highest_score'] = max($scores);
            $summary_stats['lowest_score'] = min($scores);
            
            foreach ($results as $result) {
                $summary_stats['total_cup'] += $result['course_unit_point'];
            }
            $summary_stats['total_cu'] = $summary_stats['results_submitted'] * $course_info['credit_unit'];
            $summary_stats['gpa'] = $summary_stats['total_cu'] > 0 ? 
                round($summary_stats['total_cup'] / $summary_stats['total_cu'], 2) : 0;
        }
    }
}

// Handle result approval (if staff is HOD or Dean)
$can_approve = isset($staff) && ($staff['designation'] == 'HOD' || $staff['designation'] == 'Dean');

if ($can_approve && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_results'])) {
    $result_ids = $_POST['result_ids'] ?? [];
    $approved_count = 0;
    
    foreach ($result_ids as $result_id) {
        $stmt = $pdo->prepare("UPDATE results SET is_approved = 1, approved_by = ?, approved_at = NOW() WHERE id = ?");
        if ($stmt->execute([$_SESSION['user_id'], $result_id])) {
            $approved_count++;
        }
    }
    
    $success = "$approved_count result(s) approved successfully.";
    
    // Refresh results
    $sql = "
        SELECT 
            r.*,
            s.id as student_id,
            s.reg_number,
            CONCAT(u.first_name, ' ', u.last_name) as student_name,
            u.email as student_email
        FROM results r
        JOIN students s ON r.student_id = s.id
        JOIN users u ON s.user_id = u.id
        WHERE r.course_id = ?
    ";
    $params = [$course_id];
    if ($session_filter) {
        $sql .= " AND r.session_id = ?";
        $params[] = $session_filter;
    }
    if ($semester_filter) {
        $sql .= " AND r.semester_id = ?";
        $params[] = $semester_filter;
    }
    $sql .= " ORDER BY u.last_name ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();
}

// Handle result export
if (isset($_GET['export']) && $course_id) {
    $filename = "results_{$course_info['code']}_" . date('Y-m-d') . ".csv";
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Reg Number', 'Student Name', 'CA Score', 'Exam Score', 'Total Score', 'Grade', 'Grade Point', 'CUP', 'Status']);
    
    foreach ($results as $result) {
        fputcsv($output, [
            $result['reg_number'],
            $result['student_name'],
            $result['ca_score'],
            $result['exam_score'],
            $result['total_score'],
            $result['grade'],
            $result['grade_point'],
            $result['course_unit_point'],
            $result['is_approved'] ? 'Approved' : 'Pending'
        ]);
    }
    fclose($output);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>My Results - Staff Portal</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            min-width: 180px;
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
        .btn-success { background: #48bb78; color: white; }
        .btn-success:hover { background: #38a169; }
        .btn-warning { background: #ed8936; color: white; }
        .btn-danger { background: #f56565; color: white; }
        .btn-outline { background: transparent; border: 1px solid #667eea; color: #667eea; }
        .btn-outline:hover { background: #667eea; color: white; }
        .btn-info { background: #4299e1; color: white; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .stat-number { font-size: 28px; font-weight: bold; color: #667eea; }
        .stat-label { font-size: 11px; color: #718096; margin-top: 5px; }
        
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
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
        th {
            background: #f7fafc;
            font-weight: 600;
            position: sticky;
            top: 0;
        }
        
        .grade-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        .grade-A, .grade-AB { background: #c6f6d5; color: #22543d; }
        .grade-B, .grade-BC { background: #bee3f8; color: #2c5282; }
        .grade-C, .grade-CD { background: #feebc8; color: #7c2d12; }
        .grade-D, .grade-E, .grade-F { background: #fed7d7; color: #c53030; }
        
        .status-approved {
            background: #c6f6d5;
            color: #22543d;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
            font-size: 11px;
            font-weight: 600;
        }
        .status-pending {
            background: #feebc8;
            color: #7c2d12;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
            font-size: 11px;
            font-weight: 600;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 15px;
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
        
        .empty-state {
            background: white;
            border-radius: 12px;
            padding: 60px;
            text-align: center;
        }
        .empty-state p { color: #718096; margin-bottom: 20px; }
        
        .checkbox-col {
            width: 40px;
            text-align: center;
        }
        .checkbox-col input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .filter-form { flex-direction: column; }
            .filter-group { width: 100%; }
            th, td { padding: 8px; font-size: 12px; }
            .cards-grid { grid-template-columns: 1fr; }
        }
        
        .info-note {
            background: #e9f5ff;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 13px;
            color: #2c5282;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h1>📊 My Results</h1>
                    <p>View and manage results for your courses</p>
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
                            <option value="<?php echo $course['id']; ?>" <?php echo $course_id == $course['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($course['code'] . ' - ' . $course['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if($course_id): ?>
                    <div class="filter-group">
                        <label>Academic Session</label>
                        <select name="session" onchange="this.form.submit()">
                            <option value="">All Sessions</option>
                            <?php foreach($sessions as $session): ?>
                                <option value="<?php echo $session['id']; ?>" <?php echo $session_filter == $session['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($session['name']); ?>
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
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <a href="my_results.php?course_id=<?php echo $course_id; ?>" class="btn btn-outline">Clear Filters</a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
        
        <?php if($course_id && $course_info): ?>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $summary_stats['total_students']; ?></div>
                <div class="stat-label">Total Students</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $summary_stats['results_submitted']; ?></div>
                <div class="stat-label">Results Submitted</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $summary_stats['pending']; ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $summary_stats['average_score']; ?>%</div>
                <div class="stat-label">Average Score</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $summary_stats['gpa']; ?></div>
                <div class="stat-label">Course GPA</div>
            </div>
        </div>
        
        <!-- Charts Row -->
        <?php if(!empty($results)): ?>
        <div class="cards-grid">
            <!-- Grade Distribution Chart -->
            <div class="card">
                <div class="card-header">📊 Grade Distribution</div>
                <div class="card-body">
                    <canvas id="gradeChart" style="max-height: 250px;"></canvas>
                </div>
            </div>
            
            <!-- Score Range Chart -->
            <div class="card">
                <div class="card-header">📈 Score Distribution</div>
                <div class="card-body">
                    <canvas id="scoreChart" style="max-height: 250px;"></canvas>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Results Table -->
        <div class="card">
            <div class="card-header">
                📋 Results for <?php echo htmlspecialchars($course_info['code'] . ' - ' . $course_info['title']); ?>
                (<?php echo $course_info['credit_unit']; ?> Credit Unit(s))
            </div>
            <div class="card-body">
                <div class="action-buttons">
                    <a href="?course_id=<?php echo $course_id; ?>&export=1<?php echo $session_filter ? '&session=' . $session_filter : ''; ?><?php echo $semester_filter ? '&semester=' . $semester_filter : ''; ?>" class="btn btn-success">📥 Export to CSV</a>
                    <a href="upload_results.php?course_id=<?php echo $course_id; ?>" class="btn btn-primary">📝 Edit Results</a>
                </div>
                
                <?php if(empty($results)): ?>
                    <div class="empty-state" style="padding: 40px;">
                        <p>No results found for this course.</p>
                        <a href="upload_results.php?course_id=<?php echo $course_id; ?>" class="btn btn-primary">Upload Results</a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <form method="POST" action="" id="approvalForm">
                            <div style="margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                                <div>
                                    <strong>Total Records:</strong> <?php echo count($results); ?>
                                </div>
                                <?php if($can_approve): ?>
                                    <div>
                                        <button type="button" class="btn btn-warning" onclick="selectAll()">✓ Select All</button>
                                        <button type="button" class="btn btn-outline" onclick="deselectAll()">✗ Deselect All</button>
                                        <button type="submit" name="approve_results" class="btn btn-success">✅ Approve Selected</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <table>
                                <thead>
                                    <tr>
                                        <?php if($can_approve): ?>
                                            <th class="checkbox-col">
                                                <input type="checkbox" id="selectAllCheckbox" onclick="toggleSelectAll()">
                                            </th>
                                        <?php endif; ?>
                                        <th>S/N</th>
                                        <th>Reg Number</th>
                                        <th>Student Name</th>
                                        <th>CA Score</th>
                                        <th>Exam Score</th>
                                        <th>Total Score</th>
                                        <th>Grade</th>
                                        <th>GP</th>
                                        <th>CUP</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $sn = 1;
                                    $grade_counts = ['A' => 0, 'AB' => 0, 'B' => 0, 'BC' => 0, 'C' => 0, 'CD' => 0, 'D' => 0, 'E' => 0, 'F' => 0];
                                    foreach($results as $result):
                                        $grade = $result['grade'];
                                        if(isset($grade_counts[$grade])) $grade_counts[$grade]++;
                                    ?>
                                        <tr>
                                            <?php if($can_approve): ?>
                                                <td class="checkbox-col">
                                                    <input type="checkbox" name="result_ids[]" value="<?php echo $result['id']; ?>" 
                                                           <?php echo $result['is_approved'] ? 'disabled' : ''; ?>>
                                                </td>
                                            <?php endif; ?>
                                            <td><?php echo $sn++; ?></td>
                                            <td><?php echo htmlspecialchars($result['reg_number']); ?></td>
                                            <td><?php echo htmlspecialchars($result['student_name']); ?></td>
                                            <td><?php echo $result['ca_score']; ?></td>
                                            <td><?php echo $result['exam_score']; ?></td>
                                            <td><strong><?php echo $result['total_score']; ?>%</strong></td>
                                            <td>
                                                <span class="grade-badge grade-<?php echo $grade; ?>"><?php echo $grade; ?></span>
                                            </td>
                                            <td><?php echo $result['grade_point']; ?></td>
                                            <td><?php echo $result['course_unit_point']; ?></td>
                                            <td>
                                                <?php if($result['is_approved']): ?>
                                                    <span class="status-approved">✓ Approved</span>
                                                <?php else: ?>
                                                    <span class="status-pending">⏳ Pending</span>
                                                <?php endif; ?>
                                             </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php elseif($course_id && !$course_info): ?>
            <div class="empty-state">
                <p>Course not found or you don't have access to this course.</p>
                <a href="my_results.php" class="btn btn-primary">Select Another Course</a>
            </div>
        <?php elseif(!$course_id): ?>
            <div class="empty-state">
                <p>Please select a course to view results.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        <?php if(!empty($results) && $course_id): ?>
        // Grade Distribution Chart
        const gradeCtx = document.getElementById('gradeChart').getContext('2d');
        new Chart(gradeCtx, {
            type: 'bar',
            data: {
                labels: ['A', 'AB', 'B', 'BC', 'C', 'CD', 'D', 'E', 'F'],
                datasets: [{
                    label: 'Number of Students',
                    data: [
                        <?php echo $grade_counts['A']; ?>,
                        <?php echo $grade_counts['AB']; ?>,
                        <?php echo $grade_counts['B']; ?>,
                        <?php echo $grade_counts['BC']; ?>,
                        <?php echo $grade_counts['C']; ?>,
                        <?php echo $grade_counts['CD']; ?>,
                        <?php echo $grade_counts['D']; ?>,
                        <?php echo $grade_counts['E']; ?>,
                        <?php echo $grade_counts['F']; ?>
                    ],
                    backgroundColor: '#667eea',
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Number of Students' }
                    }
                }
            }
        });
        
        // Score Distribution Chart
        const scoreCtx = document.getElementById('scoreChart').getContext('2d');
        new Chart(scoreCtx, {
            type: 'doughnut',
            data: {
                labels: ['A (75-100)', 'AB (70-74)', 'B (65-69)', 'BC (60-64)', 'C (55-59)', 'CD (50-54)', 'D (45-49)', 'E (40-44)', 'F (0-39)'],
                datasets: [{
                    data: [
                        <?php echo $grade_counts['A']; ?>,
                        <?php echo $grade_counts['AB']; ?>,
                        <?php echo $grade_counts['B']; ?>,
                        <?php echo $grade_counts['BC']; ?>,
                        <?php echo $grade_counts['C']; ?>,
                        <?php echo $grade_counts['CD']; ?>,
                        <?php echo $grade_counts['D']; ?>,
                        <?php echo $grade_counts['E']; ?>,
                        <?php echo $grade_counts['F']; ?>
                    ],
                    backgroundColor: [
                        '#48bb78', '#68d391', '#4299e1', '#63b3ed', 
                        '#ed8936', '#f6ad55', '#f56565', '#fc8181', '#e53e3e'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'right', labels: { font: { size: 10 } } }
                }
            }
        });
        <?php endif; ?>
        
        function toggleSelectAll() {
            const checkboxes = document.querySelectorAll('input[name="result_ids[]"]');
            const selectAll = document.getElementById('selectAllCheckbox');
            checkboxes.forEach(checkbox => {
                if (!checkbox.disabled) {
                    checkbox.checked = selectAll.checked;
                }
            });
        }
        
        function selectAll() {
            const checkboxes = document.querySelectorAll('input[name="result_ids[]"]');
            checkboxes.forEach(checkbox => {
                if (!checkbox.disabled) {
                    checkbox.checked = true;
                }
            });
            const selectAllCheckbox = document.getElementById('selectAllCheckbox');
            if (selectAllCheckbox) selectAllCheckbox.checked = true;
        }
        
        function deselectAll() {
            const checkboxes = document.querySelectorAll('input[name="result_ids[]"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            const selectAllCheckbox = document.getElementById('selectAllCheckbox');
            if (selectAllCheckbox) selectAllCheckbox.checked = false;
        }
        
        // Flash messages
        <?php if(isset($success)): ?>
            const flash = document.createElement('div');
            flash.className = 'flash-message flash-success';
            flash.innerHTML = '<?php echo addslashes($success); ?>';
            document.body.appendChild(flash);
            setTimeout(() => flash.remove(), 4000);
        <?php endif; ?>
        
        <?php if(isset($error)): ?>
            const flash = document.createElement('div');
            flash.className = 'flash-message flash-error';
            flash.innerHTML = '<?php echo addslashes($error); ?>';
            document.body.appendChild(flash);
            setTimeout(() => flash.remove(), 4000);
        <?php endif; ?>
    </script>
</body>
</html>
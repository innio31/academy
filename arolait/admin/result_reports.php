<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin', 'staff']);

// Get filters
$session_id = $_GET['session'] ?? '';
$semester_id = $_GET['semester'] ?? '';
$department_id = $_GET['department'] ?? '';
$level = $_GET['level'] ?? '';
$student_id = $_GET['student'] ?? '';

// Get data for filters
$sessions = $pdo->query("SELECT id, name, is_current FROM academic_sessions ORDER BY start_date DESC")->fetchAll();
$semesters = $pdo->query("
    SELECT s.*, a.name as session_name 
    FROM semesters s 
    JOIN academic_sessions a ON s.session_id = a.id 
    ORDER BY a.start_date DESC, s.id
")->fetchAll();
$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();

// Get students for filter
$students = [];
if ($department_id && $level) {
    $stmt = $pdo->prepare("
        SELECT s.id, s.reg_number, CONCAT(u.first_name, ' ', u.last_name) as student_name
        FROM students s
        JOIN users u ON s.user_id = u.id
        WHERE s.department_id = ? AND s.current_level = ?
        ORDER BY u.last_name
    ");
    $stmt->execute([$department_id, $level]);
    $students = $stmt->fetchAll();
}

// Get grading scale
$grading_scale = $pdo->query("SELECT * FROM grading_scales ORDER BY min_score DESC")->fetchAll();

// Process single student result
$student_results = null;
$student_info = null;
$semester_total_cu = 0;
$semester_total_cup = 0;
$semester_gpa = 0;

if ($student_id && $semester_id) {
    // Get student info
    $stmt = $pdo->prepare("
        SELECT 
            s.*,
            CONCAT(u.first_name, ' ', u.last_name) as student_name,
            u.email,
            d.name as department_name,
            d.code as department_code,
            f.name as faculty_name
        FROM students s
        JOIN users u ON s.user_id = u.id
        JOIN departments d ON s.department_id = d.id
        JOIN faculties f ON d.faculty_id = f.id
        WHERE s.id = ?
    ");
    $stmt->execute([$student_id]);
    $student_info = $stmt->fetch();
    
    // Get semester info
    $stmt = $pdo->prepare("
        SELECT s.*, a.name as session_name 
        FROM semesters s 
        JOIN academic_sessions a ON s.session_id = a.id 
        WHERE s.id = ?
    ");
    $stmt->execute([$semester_id]);
    $semester_info = $stmt->fetch();
    
    // Get results
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            c.code as course_code,
            c.title as course_title,
            c.credit_unit
        FROM results r
        JOIN courses c ON r.course_id = c.id
        WHERE r.student_id = ? AND r.semester_id = ? AND r.is_approved = 1
        ORDER BY c.code
    ");
    $stmt->execute([$student_id, $semester_id]);
    $student_results = $stmt->fetchAll();
    
    // Calculate totals
    foreach($student_results as $result) {
        $semester_total_cu += $result['credit_unit'];
        $semester_total_cup += $result['course_unit_point'];
    }
    $semester_gpa = $semester_total_cu > 0 ? $semester_total_cup / $semester_total_cu : 0;
}

// Calculate CGPA for multiple semesters
$cgpa_data = null;
$cgpa_total_cup = 0;
$cgpa_total_cu = 0;
$cgpa_value = 0;

if ($student_id && $session_id) {
    $stmt = $pdo->prepare("
        SELECT 
            s.id as semester_id,
            s.name as semester_name,
            a.name as session_name,
            SUM(r.credit_unit) as total_cu,
            SUM(r.course_unit_point) as total_cup
        FROM results r
        JOIN courses c ON r.course_id = c.id
        JOIN semesters s ON r.semester_id = s.id
        JOIN academic_sessions a ON s.session_id = a.id
        WHERE r.student_id = ? AND a.id = ? AND r.is_approved = 1
        GROUP BY s.id
        ORDER BY s.id
    ");
    $stmt->execute([$student_id, $session_id]);
    $semester_results = $stmt->fetchAll();
    
    foreach($semester_results as $sem) {
        $cgpa_total_cup += $sem['total_cup'];
        $cgpa_total_cu += $sem['total_cu'];
    }
    $cgpa_value = $cgpa_total_cu > 0 ? $cgpa_total_cup / $cgpa_total_cu : 0;
}

// Get academic standing based on CGPA
function getAcademicStanding($cgpa) {
    if ($cgpa >= 4.5) return ['First Class', 'standing-first'];
    if ($cgpa >= 3.5) return ['Second Class Upper', 'standing-second'];
    if ($cgpa >= 2.5) return ['Second Class Lower', 'standing-pass'];
    if ($cgpa >= 1.5) return ['Third Class', 'standing-probation'];
    return ['Probation', 'standing-probation'];
}

// Get department performance summary
$dept_summary = [];
if ($department_id && $semester_id) {
    $stmt = $pdo->prepare("
        SELECT 
            s.current_level,
            COUNT(DISTINCT s.id) as student_count,
            AVG(r.course_unit_point / r.credit_unit) as avg_gpa
        FROM students s
        JOIN results r ON s.id = r.student_id
        WHERE s.department_id = ? AND r.semester_id = ?
        GROUP BY s.current_level
    ");
    $stmt->execute([$department_id, $semester_id]);
    $dept_summary = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Result Reports - University Portal</title>
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
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 24px; color: #2d3748; margin-bottom: 8px; }
        
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
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-success { background: #48bb78; color: white; }
        .btn-warning { background: #ed8936; color: white; }
        
        .grading-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 20px;
        }
        .grading-table th {
            background: #2d3748;
            color: white;
            padding: 12px;
        }
        .grading-table td {
            padding: 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .result-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .result-header {
            background: linear-gradient(135deg, #1a365d 0%, #2b6cb0 100%);
            color: white;
            padding: 20px;
        }
        .student-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            padding: 20px;
            background: #f7fafc;
        }
        .info-item { display: flex; flex-direction: column; }
        .info-label { font-size: 12px; color: #718096; }
        .info-value { font-size: 16px; font-weight: 600; color: #2d3748; }
        
        .table-responsive { overflow-x: auto; padding: 0 20px 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f7fafc; font-weight: 600; }
        
        .total-row { background: #e9f5ff; font-weight: 600; }
        .gpa-section {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            padding: 20px;
            background: #f0fff4;
            border-top: 2px solid #48bb78;
        }
        .gpa-box { text-align: center; flex: 1; }
        .gpa-label { font-size: 12px; color: #718096; }
        .gpa-value { font-size: 28px; font-weight: bold; color: #22543d; }
        
        .grade-A { color: #48bb78; font-weight: bold; }
        .grade-AB { color: #4299e1; font-weight: bold; }
        .grade-B { color: #4299e1; font-weight: bold; }
        .grade-BC { color: #ed8936; font-weight: bold; }
        .grade-C { color: #ed8936; font-weight: bold; }
        .grade-CD { color: #ecc94b; font-weight: bold; }
        .grade-D { color: #ecc94b; font-weight: bold; }
        .grade-E { color: #f56565; font-weight: bold; }
        .grade-F { color: #f56565; font-weight: bold; }
        
        .cgpa-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
            text-align: center;
        }
        .cgpa-value { font-size: 48px; font-weight: bold; color: #667eea; }
        .standing-first { background: #c6f6d5; color: #22543d; padding: 10px; border-radius: 8px; }
        .standing-second { background: #bee3f8; color: #2c5282; padding: 10px; border-radius: 8px; }
        .standing-pass { background: #feebc8; color: #7c2d12; padding: 10px; border-radius: 8px; }
        .standing-probation { background: #fed7d7; color: #c53030; padding: 10px; border-radius: 8px; }
        
        @media (max-width: 640px) {
            .filter-form { flex-direction: column; }
            .gpa-section { flex-direction: column; }
        }
        @media print {
            .filter-bar, .btn, .grading-table, .no-print { display: none; }
            .result-card { box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                <div>
                    <h1>📊 Result Reports</h1>
                    <p>View student results, calculate GPA and CGPA</p>
                </div>
                <a href="index.php" style="color: #667eea; text-decoration: none;">← Back to Dashboard</a>
            </div>
        </div>
        
        <!-- Grading Scale Reference -->
        <div class="grading-table">
            <table style="width: 100%;">
                <thead>
                    <tr><th colspan="4" style="background: #667eea;">📋 Grading Scale System</th></tr>
                    <tr><th>Mark Range</th><th>Letter Grade</th><th>Grade Point (GP)</th><th>Remark</th></tr>
                </thead>
                <tbody>
                    <?php foreach($grading_scale as $grade): ?>
                    <tr>
                        <td><?php echo $grade['min_score']; ?>% - <?php echo $grade['max_score']; ?>%</td>
                        <td class="grade-<?php echo $grade['grade']; ?>"><?php echo $grade['grade']; ?></td>
                        <td><?php echo number_format($grade['grade_point'], 2); ?></td>
                        <td><?php echo $grade['remark']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Filters -->
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form" id="filterForm">
                <div class="filter-group">
                    <label>Academic Session</label>
                    <select name="session" id="session_filter" onchange="this.form.submit()">
                        <option value="">Select Session</option>
                        <?php foreach($sessions as $session): ?>
                            <option value="<?php echo $session['id']; ?>" <?php echo $session_id == $session['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($session['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Semester</label>
                    <select name="semester" id="semester_filter" onchange="this.form.submit()">
                        <option value="">Select Semester</option>
                        <?php foreach($semesters as $semester): ?>
                            <option value="<?php echo $semester['id']; ?>" <?php echo $semester_id == $semester['id'] ? 'selected' : ''; ?>>
                                <?php echo $semester['name'] . ' - ' . $semester['session_name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Department</label>
                    <select name="department" id="department_filter" onchange="this.form.submit()">
                        <option value="">Select Department</option>
                        <?php foreach($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" <?php echo $department_id == $dept['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Level</label>
                    <select name="level" id="level_filter" onchange="this.form.submit()">
                        <option value="">Select Level</option>
                        <option value="100" <?php echo $level == '100' ? 'selected' : ''; ?>>100 Level</option>
                        <option value="200" <?php echo $level == '200' ? 'selected' : ''; ?>>200 Level</option>
                        <option value="300" <?php echo $level == '300' ? 'selected' : ''; ?>>300 Level</option>
                        <option value="400" <?php echo $level == '400' ? 'selected' : ''; ?>>400 Level</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Student</label>
                    <select name="student" id="student_filter" onchange="this.form.submit()">
                        <option value="">Select Student</option>
                        <?php foreach($students as $student): ?>
                            <option value="<?php echo $student['id']; ?>" <?php echo $student_id == $student['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($student['student_name'] . ' (' . $student['reg_number'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
        
        <!-- Student Result Display -->
        <?php if($student_id && $semester_id && $student_results): ?>
            <div class="result-card">
                <div class="result-header">
                    <h2><?php echo $semester_info['name']; ?> Semester Examination Results</h2>
                    <p><?php echo $semester_info['session_name']; ?> Academic Session</p>
                </div>
                
                <div class="student-info">
                    <div class="info-item">
                        <span class="info-label">Student Name</span>
                        <span class="info-value"><?php echo htmlspecialchars($student_info['student_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Registration Number</span>
                        <span class="info-value"><?php echo $student_info['reg_number']; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Department</span>
                        <span class="info-value"><?php echo htmlspecialchars($student_info['department_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Level</span>
                        <span class="info-value"><?php echo $student_info['current_level']; ?> Level</span>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>S/N</th>
                                <th>Course Title</th>
                                <th>Course Code</th>
                                <th>CA Score</th>
                                <th>Exam Score</th>
                                <th>Total Score</th>
                                <th>Letter Grade</th>
                                <th>Credit Unit (CU)</th>
                                <th>Grade Point (GP)</th>
                                <th>Course Unit Point (CUP)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sn = 1; 
                            $total_cu = 0;
                            $total_cup = 0;
                            foreach($student_results as $result): 
                                $total_cu += $result['credit_unit'];
                                $total_cup += $result['course_unit_point'];
                            ?>
                                <tr>
                                    <td><?php echo $sn++; ?></td>
                                    <td><?php echo htmlspecialchars($result['course_title']); ?></td>
                                    <td><?php echo htmlspecialchars($result['course_code']); ?></td>
                                    <td><?php echo number_format($result['ca_score'], 2); ?></td>
                                    <td><?php echo number_format($result['exam_score'], 2); ?></td>
                                    <td><?php echo number_format($result['total_score'], 2); ?>%</td>
                                    <td class="grade-<?php echo $result['grade']; ?>"><?php echo $result['grade']; ?></td>
                                    <td><?php echo number_format($result['credit_unit'], 2); ?></td>
                                    <td><?php echo number_format($result['grade_point'], 2); ?></td>
                                    <td><?php echo number_format($result['course_unit_point'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="total-row">
                                <td colspan="7"><strong>TOTAL</strong></td>
                                <td><strong><?php echo number_format($total_cu, 2); ?></strong></td>
                                <td></td>
                                <td><strong><?php echo number_format($total_cup, 2); ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <div class="gpa-section">
                    <div class="gpa-box">
                        <div class="gpa-label">Total Credit Unit (CU)</div>
                        <div class="gpa-value"><?php echo number_format($total_cu, 2); ?></div>
                    </div>
                    <div class="gpa-box">
                        <div class="gpa-label">Total Course Unit Point (CUP)</div>
                        <div class="gpa-value"><?php echo number_format($total_cup, 2); ?></div>
                    </div>
                    <div class="gpa-box">
                        <div class="gpa-label">Semester GPA</div>
                        <div class="gpa-value"><?php echo number_format($semester_gpa, 2); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- CGPA Calculation -->
            <?php if($session_id): ?>
            <div class="cgpa-card">
                <h3>Cumulative Grade Point Average (CGPA)</h3>
                <div class="cgpa-value"><?php echo number_format($cgpa_value, 2); ?></div>
                <div class="info-item" style="margin-top: 10px;">
                    <span class="info-label">Total CUP: <?php echo number_format($cgpa_total_cup, 2); ?></span>
                    <span class="info-label"> | Total CU: <?php echo number_format($cgpa_total_cu, 2); ?></span>
                </div>
                <div class="<?php echo getAcademicStanding($cgpa_value)[1]; ?>" style="margin-top: 15px; padding: 10px; border-radius: 8px;">
                    <strong>Academic Standing: <?php echo getAcademicStanding($cgpa_value)[0]; ?></strong>
                </div>
                <div style="margin-top: 15px; font-size: 12px; color: #718096;">
                    CGPA = Total CUP ÷ Total CU = <?php echo number_format($cgpa_total_cup, 2); ?> ÷ <?php echo number_format($cgpa_total_cu, 2); ?> = <?php echo number_format($cgpa_value, 2); ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="action-buttons no-print" style="display: flex; gap: 10px; justify-content: center; margin-top: 20px;">
                <button onclick="window.print()" class="btn btn-success">🖨️ Print Result</button>
                <a href="process_results.php?student_id=<?php echo $student_id; ?>&semester_id=<?php echo $semester_id; ?>" class="btn btn-primary">📝 Process Results</a>
                <a href="upload_results.php?student_id=<?php echo $student_id; ?>&semester_id=<?php echo $semester_id; ?>" class="btn btn-warning">📤 Upload/Edit Results</a>
            </div>
            
        <?php elseif($student_id && $semester_id && empty($student_results)): ?>
            <div style="background: #feebc8; padding: 20px; border-radius: 12px; text-align: center;">
                <p>No results found for this student in the selected semester.</p>
                <a href="upload_results.php?student_id=<?php echo $student_id; ?>&semester_id=<?php echo $semester_id; ?>" class="btn btn-primary" style="margin-top: 10px;">Upload Results</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
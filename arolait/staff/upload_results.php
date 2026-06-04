<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole(['staff']);

$course_id = $_GET['course_id'] ?? 0;
$success = '';
$error = '';

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
    WHERE co.lecturer_id = ? AND s.is_current = 1
    GROUP BY c.id, co.id
    ORDER BY c.code
");
$stmt->execute([$_SESSION['user_id']]);
$courses = $stmt->fetchAll();

// Get current session and semester for the selected course
$current_session = $pdo->query("SELECT id, name FROM academic_sessions WHERE is_current = 1 LIMIT 1")->fetch();
$current_semester = $pdo->query("SELECT id, name FROM semesters WHERE is_current = 1 LIMIT 1")->fetch();

$session_id = $current_session['id'] ?? 0;
$semester_id = $current_semester['id'] ?? 0;

// Variables for results data
$enrolled_students = [];
$results_data = [];
$course_info = null;

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
            SELECT co.id as offering_id
            FROM course_offerings co
            WHERE co.course_id = ? AND co.lecturer_id = ?
            LIMIT 1
        ");
        $stmt->execute([$course_id, $_SESSION['user_id']]);
        $offering = $stmt->fetch();
        $offering_id = $offering ? $offering['offering_id'] : 0;
        
        if ($offering_id) {
            // Get enrolled students for this course offering
            $stmt = $pdo->prepare("
                SELECT 
                    s.id as student_id,
                    s.reg_number,
                    CONCAT(u.first_name, ' ', u.last_name) as student_name,
                    u.email
                FROM student_course_registrations scr
                JOIN students s ON scr.student_id = s.id
                JOIN users u ON s.user_id = u.id
                WHERE scr.offering_id = ? AND scr.status = 'registered'
                ORDER BY u.last_name
            ");
            $stmt->execute([$offering_id]);
            $enrolled_students = $stmt->fetchAll();
            
            // Get existing results for this course
            $stmt = $pdo->prepare("
                SELECT student_id, ca_score, exam_score, total_score, grade, grade_point, course_unit_point
                FROM results
                WHERE course_id = ? AND session_id = ? AND semester_id = ?
            ");
            $stmt->execute([$course_id, $session_id, $semester_id]);
            foreach ($stmt->fetchAll() as $result) {
                $results_data[$result['student_id']] = $result;
            }
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_results'])) {
    $ca_scores = $_POST['ca_score'] ?? [];
    $exam_scores = $_POST['exam_score'] ?? [];
    $student_ids = $_POST['student_id'] ?? [];
    
    $saved_count = 0;
    $updated_count = 0;
    
    foreach ($student_ids as $index => $student_id) {
        $ca_score = floatval($ca_scores[$index] ?? 0);
        $exam_score = floatval($exam_scores[$index] ?? 0);
        
        // Validate scores (CA max 30, Exam max 70)
        if ($ca_score < 0 || $ca_score > 30) {
            $error = "CA Score must be between 0 and 30 for student ID: $student_id";
            continue;
        }
        if ($exam_score < 0 || $exam_score > 70) {
            $error = "Exam Score must be between 0 and 70 for student ID: $student_id";
            continue;
        }
        
        // Calculate total score (CA + Exam)
        $total_score = $ca_score + $exam_score;
        $total_score = round($total_score, 2);
        
        // Use functions from functions.php to calculate grade and grade point
        $grade = calculateGrade($total_score);
        $grade_point = calculateGradePoint($total_score);
        $course_unit_point = calculateCUP($grade_point, $course_info['credit_unit']);
        
        // Check if result already exists
        $stmt = $pdo->prepare("
            SELECT id FROM results 
            WHERE student_id = ? AND course_id = ? AND session_id = ? AND semester_id = ?
        ");
        $stmt->execute([$student_id, $course_id, $session_id, $semester_id]);
        
        if ($stmt->fetch()) {
            // Update existing result
            $stmt = $pdo->prepare("
                UPDATE results 
                SET ca_score = ?, exam_score = ?, total_score = ?, 
                    grade = ?, grade_point = ?, course_unit_point = ?,
                    is_approved = 0
                WHERE student_id = ? AND course_id = ? AND session_id = ? AND semester_id = ?
            ");
            if ($stmt->execute([$ca_score, $exam_score, $total_score, $grade, $grade_point, $course_unit_point, $student_id, $course_id, $session_id, $semester_id])) {
                $updated_count++;
            }
        } else {
            // Insert new result
            $stmt = $pdo->prepare("
                INSERT INTO results (student_id, course_id, session_id, semester_id, ca_score, exam_score, total_score, grade, grade_point, course_unit_point, is_approved)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
            ");
            if ($stmt->execute([$student_id, $course_id, $session_id, $semester_id, $ca_score, $exam_score, $total_score, $grade, $grade_point, $course_unit_point])) {
                $saved_count++;
            }
        }
    }
    
    if ($saved_count > 0 || $updated_count > 0) {
        $success = "Results saved: $saved_count new, $updated_count updated.";
        // Refresh results data
        $stmt = $pdo->prepare("
            SELECT student_id, ca_score, exam_score, total_score, grade, grade_point, course_unit_point
            FROM results
            WHERE course_id = ? AND session_id = ? AND semester_id = ?
        ");
        $stmt->execute([$course_id, $session_id, $semester_id]);
        $results_data = [];
        foreach ($stmt->fetchAll() as $result) {
            $results_data[$result['student_id']] = $result;
        }
    } elseif ($error == '') {
        $error = "No results were saved. Please check your input.";
    }
}

// Handle bulk upload via CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
    $file = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($file, 'r');
    $header = fgetcsv($handle); // Skip header row
    
    $csv_saved = 0;
    $csv_updated = 0;
    $csv_errors = [];
    
    while (($data = fgetcsv($handle)) !== false) {
        $reg_number = trim($data[0] ?? '');
        $ca_score = floatval($data[1] ?? 0);
        $exam_score = floatval($data[2] ?? 0);
        
        if (empty($reg_number)) {
            continue;
        }
        
        // Find student by registration number
        $stmt = $pdo->prepare("SELECT id FROM students WHERE reg_number = ?");
        $stmt->execute([$reg_number]);
        $student = $stmt->fetch();
        
        if (!$student) {
            $csv_errors[] = "Student not found: $reg_number";
            continue;
        }
        
        $student_id = $student['id'];
        
        // Validate scores (CA max 30, Exam max 70)
        if ($ca_score < 0 || $ca_score > 30) {
            $csv_errors[] = "Invalid CA score for $reg_number: $ca_score (max 30)";
            continue;
        }
        if ($exam_score < 0 || $exam_score > 70) {
            $csv_errors[] = "Invalid Exam score for $reg_number: $exam_score (max 70)";
            continue;
        }
        
        // Calculate total score (CA + Exam)
        $total_score = $ca_score + $exam_score;
        $total_score = round($total_score, 2);
        
        // Use functions from functions.php
        $grade = calculateGrade($total_score);
        $grade_point = calculateGradePoint($total_score);
        $course_unit_point = calculateCUP($grade_point, $course_info['credit_unit']);
        
        // Check if exists
        $stmt = $pdo->prepare("
            SELECT id FROM results 
            WHERE student_id = ? AND course_id = ? AND session_id = ? AND semester_id = ?
        ");
        $stmt->execute([$student_id, $course_id, $session_id, $semester_id]);
        
        if ($stmt->fetch()) {
            $stmt = $pdo->prepare("
                UPDATE results 
                SET ca_score = ?, exam_score = ?, total_score = ?, 
                    grade = ?, grade_point = ?, course_unit_point = ?, 
                    is_approved = 0, updated_at = NOW() 
                WHERE student_id = ? AND course_id = ? AND session_id = ? AND semester_id = ?
            ");
            if ($stmt->execute([$ca_score, $exam_score, $total_score, $grade, $grade_point, $course_unit_point, $student_id, $course_id, $session_id, $semester_id])) {
                $csv_updated++;
            }
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO results (student_id, course_id, session_id, semester_id, ca_score, exam_score, total_score, grade, grade_point, course_unit_point, is_approved)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
            ");
            if ($stmt->execute([$student_id, $course_id, $session_id, $semester_id, $ca_score, $exam_score, $total_score, $grade, $grade_point, $course_unit_point])) {
                $csv_saved++;
            }
        }
    }
    fclose($handle);
    
    if ($csv_saved > 0 || $csv_updated > 0) {
        $success = "CSV upload successful! $csv_saved new, $csv_updated updated records.";
        // Refresh results data
        $stmt = $pdo->prepare("
            SELECT student_id, ca_score, exam_score, total_score, grade, grade_point, course_unit_point
            FROM results
            WHERE course_id = ? AND session_id = ? AND semester_id = ?
        ");
        $stmt->execute([$course_id, $session_id, $semester_id]);
        $results_data = [];
        foreach ($stmt->fetchAll() as $result) {
            $results_data[$result['student_id']] = $result;
        }
    }
    
    if (!empty($csv_errors)) {
        $error = implode(', ', array_slice($csv_errors, 0, 5));
        if (count($csv_errors) > 5) {
            $error .= "... and " . (count($csv_errors) - 5) . " more errors";
        }
    }
}

// Calculate course statistics
$stats = [
    'total_students' => count($enrolled_students),
    'results_submitted' => count($results_data),
    'pending' => count($enrolled_students) - count($results_data),
    'average_score' => 0,
    'highest_score' => 0,
    'lowest_score' => 100,
    'total_cup' => 0,
    'total_cu' => 0
];

if (!empty($results_data)) {
    $scores = array_column($results_data, 'total_score');
    $stats['average_score'] = round(array_sum($scores) / count($scores), 2);
    $stats['highest_score'] = max($scores);
    $stats['lowest_score'] = min($scores);
    
    // Calculate total CUP and CU for statistics
    foreach ($results_data as $result) {
        $stats['total_cup'] += $result['course_unit_point'];
    }
    $stats['total_cu'] = $stats['results_submitted'] * $course_info['credit_unit'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Upload Results - Staff Portal</title>
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
            min-width: 200px;
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
            transition: all 0.3s;
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5a67d8; }
        .btn-success { background: #48bb78; color: white; }
        .btn-success:hover { background: #38a169; }
        .btn-warning { background: #ed8936; color: white; }
        .btn-outline { background: transparent; border: 1px solid #667eea; color: #667eea; }
        .btn-outline:hover { background: #667eea; color: white; }
        .btn-danger { background: #f56565; color: white; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
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
        
        .card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            font-weight: 600;
        }
        .card-body { padding: 20px; }
        
        .csv-upload {
            border: 2px dashed #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
        }
        .csv-upload input { margin-top: 10px; }
        
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
        input.score-input {
            width: 80px;
            padding: 6px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            text-align: center;
        }
        input.score-input:focus { outline: none; border-color: #667eea; }
        
        .grade-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        .grade-A { background: #c6f6d5; color: #22543d; }
        .grade-AB { background: #c6f6d5; color: #22543d; }
        .grade-B { background: #bee3f8; color: #2c5282; }
        .grade-BC { background: #bee3f8; color: #2c5282; }
        .grade-C { background: #feebc8; color: #7c2d12; }
        .grade-CD { background: #feebc8; color: #7c2d12; }
        .grade-D { background: #fed7d7; color: #c53030; }
        .grade-E { background: #fed7d7; color: #c53030; }
        .grade-F { background: #fed7d7; color: #c53030; }
        
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
        
        .btn-group { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; flex-wrap: wrap; }
        
        @media (max-width: 768px) {
            .filter-form { flex-direction: column; }
            .filter-group { width: 100%; }
            th, td { padding: 8px; font-size: 12px; }
            input.score-input { width: 60px; }
        }
        
        .info-note {
            background: #e9f5ff;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 13px;
            color: #2c5282;
        }
        .info-note strong { color: #2b6cb0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h1>📝 Upload Results</h1>
                    <p>Enter CA (max 30) and Exam (max 70) scores for your courses</p>
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
            </form>
        </div>
        
        <?php if($course_id && $course_info && !empty($enrolled_students)): ?>
        
        <div class="info-note">
            <strong>ℹ️ Grading System:</strong> 
            CA Score = 30 marks maximum | 
            Exam Score = 70 marks maximum | 
            Total Score = CA + Exam (max 100) | 
            Grades are automatically calculated based on the institutional grading scale.
        </div>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_students']; ?></div>
                <div class="stat-label">Total Students</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['results_submitted']; ?></div>
                <div class="stat-label">Results Submitted</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['pending']; ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['average_score']; ?>%</div>
                <div class="stat-label">Average Score</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_cup']; ?></div>
                <div class="stat-label">Total CUP</div>
            </div>
        </div>
        
        <!-- CSV Upload Section -->
        <div class="card">
            <div class="card-header">📂 Bulk Upload (CSV)</div>
            <div class="card-body">
                <div class="csv-upload">
                    <p><strong>CSV Format:</strong> Registration Number, CA Score (0-30), Exam Score (0-70)</p>
                    <p style="font-size: 12px; color: #718096; margin-top: 5px;">Example: CSC/2026/0001, 25, 60</p>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="file" name="csv_file" accept=".csv" required>
                        <button type="submit" class="btn btn-primary" style="margin-left: 10px;">📤 Upload CSV</button>
                    </form>
                </div>
                <div style="text-align: right;">
                    <a href="#" class="btn btn-outline btn-small" onclick="downloadSampleCSV()">Download Sample CSV</a>
                </div>
            </div>
        </div>
        
        <!-- Results Entry Form -->
        <div class="card">
            <div class="card-header">
                📋 Enter Results for <?php echo htmlspecialchars($course_info['code'] . ' - ' . $course_info['title']); ?>
                (<?php echo $course_info['credit_unit']; ?> Credit Unit(s))
            </div>
            <div class="card-body">
                <form method="POST" action="" id="resultsForm">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>S/N</th>
                                    <th>Reg Number</th>
                                    <th>Student Name</th>
                                    <th>CA Score (0-30)</th>
                                    <th>Exam Score (0-70)</th>
                                    <th>Total (0-100)</th>
                                    <th>Grade</th>
                                    <th>GP</th>
                                    <th>CUP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $sn = 1;
                                foreach($enrolled_students as $student):
                                    $existing = $results_data[$student['student_id']] ?? null;
                                    $ca = $existing['ca_score'] ?? '';
                                    $exam = $existing['exam_score'] ?? '';
                                    $total = $existing['total_score'] ?? '';
                                    $grade = $existing['grade'] ?? '';
                                    $gp = $existing['grade_point'] ?? '';
                                    $cup = $existing['course_unit_point'] ?? '';
                                ?>
                                <tr>
                                    <td><?php echo $sn++; ?></td>
                                    <td><?php echo htmlspecialchars($student['reg_number']); ?></td>
                                    <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                    <td>
                                        <input type="hidden" name="student_id[]" value="<?php echo $student['student_id']; ?>">
                                        <input type="number" name="ca_score[]" class="score-input" step="0.01" min="0" max="30" 
                                               value="<?php echo $ca; ?>" onchange="calculateTotal(this, <?php echo $student['student_id']; ?>, <?php echo $course_info['credit_unit']; ?>)"
                                               placeholder="0-30">
                                    </td>
                                    <td>
                                        <input type="number" name="exam_score[]" class="score-input" step="0.01" min="0" max="70" 
                                               value="<?php echo $exam; ?>" onchange="calculateTotal(this, <?php echo $student['student_id']; ?>, <?php echo $course_info['credit_unit']; ?>)"
                                               placeholder="0-70">
                                    </td>
                                    <td>
                                        <span id="total_<?php echo $student['student_id']; ?>" class="total-score"><?php echo $total; ?></span>
                                    </td>
                                    <td>
                                        <span id="grade_<?php echo $student['student_id']; ?>" class="grade-badge <?php echo $grade ? 'grade-' . $grade : ''; ?>"><?php echo $grade ?: '-'; ?></span>
                                    </td>
                                    <td>
                                        <span id="gp_<?php echo $student['student_id']; ?>"><?php echo $gp ?: '-'; ?></span>
                                    </td>
                                    <td>
                                        <span id="cup_<?php echo $student['student_id']; ?>"><?php echo $cup ?: '-'; ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="btn-group">
                        <button type="submit" name="save_results" class="btn btn-success">💾 Save All Results</button>
                        <button type="button" class="btn btn-outline" onclick="calculateAllTotals(<?php echo $course_info['credit_unit']; ?>)">🔄 Calculate All</button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php elseif($course_id && $course_info && empty($enrolled_students)): ?>
            <div class="card">
                <div class="card-body" style="text-align: center; padding: 40px;">
                    <p>No students enrolled in this course yet.</p>
                </div>
            </div>
        <?php elseif($course_id && !$course_info): ?>
            <div class="card">
                <div class="card-body" style="text-align: center; padding: 40px;">
                    <p>Course not found.</p>
                </div>
            </div>
        <?php elseif(!$course_id): ?>
            <div class="card">
                <div class="card-body" style="text-align: center; padding: 40px;">
                    <p>Please select a course to upload results.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Grading scale mapping (matching calculateGrade and calculateGradePoint functions)
        function getGradeInfo(score) {
            if (score >= 75) return { grade: 'A', grade_point: 4.00 };
            if (score >= 70) return { grade: 'AB', grade_point: 3.50 };
            if (score >= 65) return { grade: 'B', grade_point: 3.25 };
            if (score >= 60) return { grade: 'BC', grade_point: 3.00 };
            if (score >= 55) return { grade: 'C', grade_point: 2.75 };
            if (score >= 50) return { grade: 'CD', grade_point: 2.50 };
            if (score >= 45) return { grade: 'D', grade_point: 2.25 };
            if (score >= 40) return { grade: 'E', grade_point: 2.00 };
            return { grade: 'F', grade_point: 0.00 };
        }
        
        function calculateTotal(input, studentId, creditUnit) {
            const row = input.closest('tr');
            const caInput = row.querySelector('input[name="ca_score[]"]');
            const examInput = row.querySelector('input[name="exam_score[]"]');
            
            let ca = parseFloat(caInput.value) || 0;
            let exam = parseFloat(examInput.value) || 0;
            
            // Validate ranges
            if (ca < 0) ca = 0;
            if (ca > 30) ca = 30;
            if (exam < 0) exam = 0;
            if (exam > 70) exam = 70;
            
            // Update inputs if values were out of range
            if (ca != caInput.value) caInput.value = ca;
            if (exam != examInput.value) examInput.value = exam;
            
            // Calculate total (CA + Exam)
            let total = ca + exam;
            total = Math.round(total * 100) / 100;
            
            // Get grade info
            const gradeInfo = getGradeInfo(total);
            
            // Calculate CUP (Course Unit Point)
            const cup = gradeInfo.grade_point * creditUnit;
            
            // Update display
            document.getElementById(`total_${studentId}`).innerText = total;
            const gradeSpan = document.getElementById(`grade_${studentId}`);
            gradeSpan.innerText = gradeInfo.grade;
            gradeSpan.className = `grade-badge grade-${gradeInfo.grade}`;
            document.getElementById(`gp_${studentId}`).innerText = gradeInfo.grade_point;
            document.getElementById(`cup_${studentId}`).innerText = cup;
        }
        
        function calculateAllTotals(creditUnit) {
            const rows = document.querySelectorAll('#resultsForm tbody tr');
            rows.forEach(row => {
                const caInput = row.querySelector('input[name="ca_score[]"]');
                const examInput = row.querySelector('input[name="exam_score[]"]');
                const studentIdInput = row.querySelector('input[name="student_id[]"]');
                
                if (caInput && examInput && studentIdInput) {
                    const studentId = studentIdInput.value;
                    calculateTotal(caInput, studentId, creditUnit);
                }
            });
        }
        
        // Auto-calculate on page load for existing values
        document.addEventListener('DOMContentLoaded', function() {
            <?php if($course_id && $course_info): ?>
            calculateAllTotals(<?php echo $course_info['credit_unit']; ?>);
            <?php endif; ?>
        });
        
        // Download sample CSV
        function downloadSampleCSV() {
            const csvContent = "Registration Number,CA Score (0-30),Exam Score (0-70)\nCSC/2026/0001,25,60\nCSC/2026/0002,20,55\nCSC/2026/0003,28,65";
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'sample_results.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }
        
        // Flash messages
        <?php if($success): ?>
            const flash = document.createElement('div');
            flash.className = 'flash-message flash-success';
            flash.innerHTML = '<?php echo addslashes($success); ?>';
            document.body.appendChild(flash);
            setTimeout(() => flash.remove(), 4000);
        <?php endif; ?>
        
        <?php if($error): ?>
            const flash = document.createElement('div');
            flash.className = 'flash-message flash-error';
            flash.innerHTML = '<?php echo addslashes($error); ?>';
            document.body.appendChild(flash);
            setTimeout(() => flash.remove(), 4000);
        <?php endif; ?>
    </script>
</body>
</html>
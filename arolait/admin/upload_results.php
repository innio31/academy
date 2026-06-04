<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole(['super_admin', 'admin', 'staff']);

$student_id = $_GET['student_id'] ?? 0;
$semester_id = $_GET['semester_id'] ?? 0;
$message = '';
$error = '';

if (!$student_id || !$semester_id) {
    header("Location: result_reports.php?error=Please select student and semester first");
    exit();
}

// Get student info
$stmt = $pdo->prepare("
    SELECT s.*, CONCAT(u.first_name, ' ', u.last_name) as student_name
    FROM students s
    JOIN users u ON s.user_id = u.id
    WHERE s.id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

// Get semester info
$stmt = $pdo->prepare("
    SELECT s.*, a.name as session_name, a.id as session_id
    FROM semesters s 
    JOIN academic_sessions a ON s.session_id = a.id 
    WHERE s.id = ?
");
$stmt->execute([$semester_id]);
$semester = $stmt->fetch();

// Get courses for this student's level and department
$stmt = $pdo->prepare("
    SELECT c.* 
    FROM courses c
    WHERE c.department_id = ? AND c.level = ? AND c.semester_id = ?
    ORDER BY c.code
");
$stmt->execute([$student['department_id'], $student['current_level'], $semester_id]);
$courses = $stmt->fetchAll();

// Get existing results
$existing_results = [];
$stmt = $pdo->prepare("SELECT * FROM results WHERE student_id = ? AND semester_id = ?");
$stmt->execute([$student_id, $semester_id]);
foreach($stmt->fetchAll() as $result) {
    $existing_results[$result['course_id']] = $result;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        foreach($_POST['results'] as $course_id => $scores) {
            $ca_score = floatval($scores['ca'] ?? 0);
            $exam_score = floatval($scores['exam'] ?? 0);
            
            // Get course credit unit
            $stmt = $pdo->prepare("SELECT credit_unit FROM courses WHERE id = ?");
            $stmt->execute([$course_id]);
            $credit_unit = $stmt->fetch()['credit_unit'];
            
            // Calculate using PHP function
            $total_score = ($ca_score + $exam_score) / 2;
            $grade = calculateGrade($total_score);
            $grade_point = calculateGradePoint($total_score);
            $course_unit_point = calculateCUP($grade_point, $credit_unit);
            
            // Check if result exists
            if (isset($existing_results[$course_id])) {
                // Update
                $stmt = $pdo->prepare("
                    UPDATE results 
                    SET ca_score = ?, exam_score = ?, total_score = ?,
                        grade = ?, grade_point = ?, course_unit_point = ?,
                        is_approved = 0
                    WHERE student_id = ? AND course_id = ? AND semester_id = ?
                ");
                $stmt->execute([
                    $ca_score, $exam_score, $total_score,
                    $grade, $grade_point, $course_unit_point,
                    $student_id, $course_id, $semester_id
                ]);
            } else {
                // Insert
                $stmt = $pdo->prepare("
                    INSERT INTO results (student_id, course_id, semester_id, session_id, 
                                        ca_score, exam_score, total_score, credit_unit,
                                        grade, grade_point, course_unit_point, is_approved)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
                ");
                $stmt->execute([
                    $student_id, $course_id, $semester_id, $semester['session_id'],
                    $ca_score, $exam_score, $total_score, $credit_unit,
                    $grade, $grade_point, $course_unit_point
                ]);
            }
        }
        
        $pdo->commit();
        $message = "Results saved successfully! Grades calculated automatically.";
        
        // Refresh existing results
        $stmt = $pdo->prepare("SELECT * FROM results WHERE student_id = ? AND semester_id = ?");
        $stmt->execute([$student_id, $semester_id]);
        $existing_results = [];
        foreach($stmt->fetchAll() as $result) {
            $existing_results[$result['course_id']] = $result;
        }
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// Handle approval
if (isset($_GET['approve'])) {
    $stmt = $pdo->prepare("
        UPDATE results 
        SET is_approved = 1, approved_by = ?, approved_at = NOW()
        WHERE student_id = ? AND semester_id = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $student_id, $semester_id]);
    header("Location: result_reports.php?student=$student_id&semester=$semester_id&message=Results approved");
    exit();
}

// Calculate current semester totals for display
$current_totals = getSemesterTotals($pdo, $student_id, $semester_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Results - <?php echo htmlspecialchars($student['student_name']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fb;
            padding: 20px;
        }
        .container { max-width: 1300px; margin: 0 auto; }
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
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-success { background: #48bb78; color: white; }
        .btn-warning { background: #ed8936; color: white; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f7fafc; font-weight: 600; }
        input[type="number"] {
            width: 100px;
            padding: 8px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 14px;
        }
        .message {
            background: #c6f6d5;
            color: #22543d;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .error {
            background: #fed7d7;
            color: #c53030;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
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
        .totals-box {
            background: #e9f5ff;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            display: flex;
            justify-content: space-around;
            text-align: center;
        }
        .totals-box div { flex: 1; }
        .totals-label { font-size: 12px; color: #718096; }
        .totals-value { font-size: 24px; font-weight: bold; color: #2d3748; }
        @media (max-width: 768px) {
            table { font-size: 12px; }
            input[type="number"] { width: 70px; }
            th, td { padding: 8px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>📝 Upload/Edit Student Results</h1>
            <p><strong>Student:</strong> <?php echo htmlspecialchars($student['student_name']); ?> (<?php echo $student['reg_number']; ?>)</p>
            <p><strong>Semester:</strong> <?php echo $semester['name']; ?> Semester - <?php echo $semester['session_name']; ?></p>
            <p><strong>Department:</strong> <?php echo $student['department_id']; ?> | <strong>Level:</strong> <?php echo $student['current_level']; ?> Level</p>
            <a href="result_reports.php?student=<?php echo $student_id; ?>&semester=<?php echo $semester_id; ?>" style="color: #667eea; text-decoration: none;">← Back to Results</a>
        </div>
        
        <?php if($message): ?>
            <div class="message">✓ <?php echo $message; ?></div>
        <?php endif; ?>
        <?php if($error): ?>
            <div class="error">✗ <?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="card">
            <form method="POST" action="" id="resultsForm">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>S/N</th>
                                <th>Course Code</th>
                                <th>Course Title</th>
                                <th>CU</th>
                                <th>CA (0-100)</th>
                                <th>Exam (0-100)</th>
                                <th>Total (%)</th>
                                <th>Grade</th>
                                <th>GP</th>
                                <th>CUP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sn = 1;
                            $display_total_cu = 0;
                            $display_total_cup = 0;
                            foreach($courses as $course): 
                                $ca = isset($existing_results[$course['id']]) ? $existing_results[$course['id']]['ca_score'] : '';
                                $exam = isset($existing_results[$course['id']]) ? $existing_results[$course['id']]['exam_score'] : '';
                                $total = ($ca !== '' && $exam !== '') ? ($ca + $exam) / 2 : '';
                                $grade = $total !== '' ? calculateGrade($total) : '';
                                $grade_point = $total !== '' ? calculateGradePoint($total) : 0;
                                $cup = $grade_point * $course['credit_unit'];
                                
                                if ($total !== '') {
                                    $display_total_cu += $course['credit_unit'];
                                    $display_total_cup += $cup;
                                }
                            ?>
                                <tr>
                                    <td><?php echo $sn++; ?></td>
                                    <td><?php echo htmlspecialchars($course['code']); ?></td>
                                    <td><?php echo htmlspecialchars($course['title']); ?></td>
                                    <td><?php echo $course['credit_unit']; ?></td>
                                    <td>
                                        <input type="number" name="results[<?php echo $course['id']; ?>][ca]" 
                                               value="<?php echo $ca; ?>" step="0.5" min="0" max="100" 
                                               class="ca-input" data-course="<?php echo $course['id']; ?>"
                                               data-credit="<?php echo $course['credit_unit']; ?>">
                                    </td>
                                    <td>
                                        <input type="number" name="results[<?php echo $course['id']; ?>][exam]" 
                                               value="<?php echo $exam; ?>" step="0.5" min="0" max="100"
                                               class="exam-input" data-course="<?php echo $course['id']; ?>"
                                               data-credit="<?php echo $course['credit_unit']; ?>">
                                    </td>
                                    <td class="total-display" id="total_<?php echo $course['id']; ?>">
                                        <?php echo $total ? number_format($total, 2) : '-'; ?>
                                    </td>
                                    <td class="grade-display grade-<?php echo $grade; ?>" id="grade_<?php echo $course['id']; ?>">
                                        <?php echo $grade ?: '-'; ?>
                                    </td>
                                    <td class="gp-display" id="gp_<?php echo $course['id']; ?>">
                                        <?php echo $grade_point ? number_format($grade_point, 2) : '-'; ?>
                                    </td>
                                    <td class="cup-display" id="cup_<?php echo $course['id']; ?>">
                                        <?php echo $cup ? number_format($cup, 2) : '-'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="totals-box">
                    <div>
                        <div class="totals-label">Total Credit Unit (CU)</div>
                        <div class="totals-value" id="total_cu_display"><?php echo number_format($display_total_cu, 2); ?></div>
                    </div>
                    <div>
                        <div class="totals-label">Total Course Unit Point (CUP)</div>
                        <div class="totals-value" id="total_cup_display"><?php echo number_format($display_total_cup, 2); ?></div>
                    </div>
                    <div>
                        <div class="totals-label">Semester GPA</div>
                        <div class="totals-value" id="gpa_display">
                            <?php echo $display_total_cu > 0 ? number_format($display_total_cup / $display_total_cu, 2) : '0.00'; ?>
                        </div>
                    </div>
                </div>
                
                <div style="margin-top: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary">💾 Save Results</button>
                    <a href="?approve=1&student_id=<?php echo $student_id; ?>&semester_id=<?php echo $semester_id; ?>" 
                       class="btn btn-success" onclick="return confirm('Approve these results? This will make them visible to students and parents.')">
                        ✓ Approve Results
                    </a>
                    <button type="button" class="btn btn-warning" onclick="calculateAll()">🔄 Recalculate All</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Grade calculation functions (matching PHP)
        function calculateGrade(total) {
            if (total >= 75) return 'A';
            if (total >= 70) return 'AB';
            if (total >= 65) return 'B';
            if (total >= 60) return 'BC';
            if (total >= 55) return 'C';
            if (total >= 50) return 'CD';
            if (total >= 45) return 'D';
            if (total >= 40) return 'E';
            return 'F';
        }
        
        function calculateGradePoint(total) {
            if (total >= 75) return 4.00;
            if (total >= 70) return 3.50;
            if (total >= 65) return 3.25;
            if (total >= 60) return 3.00;
            if (total >= 55) return 2.75;
            if (total >= 50) return 2.50;
            if (total >= 45) return 2.25;
            if (total >= 40) return 2.00;
            return 0.00;
        }
        
        function calculateRow(courseId, creditUnit) {
            let caInput = document.querySelector(`input[name="results[${courseId}][ca]"]`);
            let examInput = document.querySelector(`input[name="results[${courseId}][exam]"]`);
            
            let ca = parseFloat(caInput.value) || 0;
            let exam = parseFloat(examInput.value) || 0;
            let total = (ca + exam) / 2;
            
            let grade = calculateGrade(total);
            let gradePoint = calculateGradePoint(total);
            let cup = gradePoint * creditUnit;
            
            document.getElementById(`total_${courseId}`).innerText = total.toFixed(2);
            document.getElementById(`grade_${courseId}`).innerHTML = grade;
            document.getElementById(`grade_${courseId}`).className = `grade-display grade-${grade}`;
            document.getElementById(`gp_${courseId}`).innerText = gradePoint.toFixed(2);
            document.getElementById(`cup_${courseId}`).innerText = cup.toFixed(2);
            
            return { cup, creditUnit };
        }
        
        function calculateAll() {
            let totalCU = 0;
            let totalCUP = 0;
            
            <?php foreach($courses as $course): ?>
                let result_<?php echo $course['id']; ?> = calculateRow(<?php echo $course['id']; ?>, <?php echo $course['credit_unit']; ?>);
                totalCU += result_<?php echo $course['id']; ?>.creditUnit;
                totalCUP += result_<?php echo $course['id']; ?>.cup;
            <?php endforeach; ?>
            
            document.getElementById('total_cu_display').innerText = totalCU.toFixed(2);
            document.getElementById('total_cup_display').innerText = totalCUP.toFixed(2);
            let gpa = totalCU > 0 ? (totalCUP / totalCU) : 0;
            document.getElementById('gpa_display').innerText = gpa.toFixed(2);
        }
        
        // Attach event listeners to all inputs
        document.querySelectorAll('.ca-input, .exam-input').forEach(input => {
            input.addEventListener('input', function() {
                let courseId = this.dataset.course;
                let creditUnit = parseFloat(this.dataset.credit);
                calculateRow(courseId, creditUnit);
                calculateAll();
            });
        });
    </script>
</body>
</html>
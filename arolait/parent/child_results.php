<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole(['parent']);

// Get parent's linked student
$stmt = $pdo->prepare("
    SELECT p.student_id, s.reg_number, CONCAT(u.first_name, ' ', u.last_name) as student_name
    FROM parents p
    JOIN students s ON p.student_id = s.id
    JOIN users u ON s.user_id = u.id
    WHERE p.id = ?
");
$stmt->execute([$_SESSION['parent_id']]);
$parent = $stmt->fetch();

$student_id = $parent['student_id'];

// Get available semesters for filtering
$semesters = $pdo->prepare("
    SELECT DISTINCT s.id, s.name as semester_name, a.id as session_id, a.name as session_name
    FROM semesters s
    JOIN academic_sessions a ON s.session_id = a.id
    JOIN results r ON r.semester_id = s.id
    WHERE r.student_id = ? AND r.is_approved = 1
    ORDER BY a.start_date DESC, s.id DESC
");
$semesters->execute([$student_id]);
$available_semesters = $semesters->fetchAll();

$selected_semester = $_GET['semester_id'] ?? ($available_semesters[0]['id'] ?? 0);
$results = [];
$semester_info = null;
$total_cu = 0;
$total_cup = 0;
$gpa = 0;

if ($selected_semester) {
    // Get semester info
    $stmt = $pdo->prepare("
        SELECT s.*, a.name as session_name
        FROM semesters s
        JOIN academic_sessions a ON s.session_id = a.id
        WHERE s.id = ?
    ");
    $stmt->execute([$selected_semester]);
    $semester_info = $stmt->fetch();
    
    // Get results
    $stmt = $pdo->prepare("
        SELECT r.*, c.code, c.title, c.credit_unit
        FROM results r
        JOIN courses c ON r.course_id = c.id
        WHERE r.student_id = ? AND r.semester_id = ? AND r.is_approved = 1
        ORDER BY c.code
    ");
    $stmt->execute([$student_id, $selected_semester]);
    $results = $stmt->fetchAll();
    
    foreach($results as $result) {
        $total_cu += $result['credit_unit'];
        $total_cup += $result['course_unit_point'];
    }
    $gpa = $total_cu > 0 ? $total_cup / $total_cu : 0;
}

// Calculate CGPA
$stmt = $pdo->prepare("
    SELECT 
        SUM(credit_unit) as total_cu,
        SUM(course_unit_point) as total_cup
    FROM results
    WHERE student_id = ? AND is_approved = 1
");
$stmt->execute([$student_id]);
$cgpa_totals = $stmt->fetch();
$cgpa = 0;
if ($cgpa_totals && $cgpa_totals['total_cu'] > 0) {
    $cgpa = $cgpa_totals['total_cup'] / $cgpa_totals['total_cu'];
}
$academic_standing = getAcademicStanding($cgpa);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Child's Results - Parent Portal</title>
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
        .filter-group { flex: 1; min-width: 200px; }
        .filter-group label { display: block; margin-bottom: 5px; font-size: 12px; font-weight: 600; }
        .filter-group select {
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
        
        .total-row { background: #e9f5ff; font-weight: 600; }
        
        .grade-A { color: #48bb78; font-weight: bold; }
        .grade-AB { color: #4299e1; font-weight: bold; }
        .grade-B { color: #4299e1; font-weight: bold; }
        .grade-BC { color: #ed8936; font-weight: bold; }
        .grade-C { color: #ed8936; font-weight: bold; }
        .grade-CD { color: #ecc94b; font-weight: bold; }
        .grade-D { color: #ecc94b; font-weight: bold; }
        .grade-E { color: #f56565; font-weight: bold; }
        .grade-F { color: #f56565; font-weight: bold; }
        
        .standing-box {
            background: #f0fff4;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        
        @media (max-width: 640px) {
            .filter-form { flex-direction: column; }
            .filter-group { width: 100%; }
            th, td { padding: 8px; font-size: 12px; }
        }
        
        @media print {
            .filter-bar, .btn, .no-print { display: none; }
            body { background: white; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                <div>
                    <h1>📊 Child's Academic Results</h1>
                    <p><?php echo htmlspecialchars($parent['student_name']); ?> (<?php echo $parent['reg_number']; ?>)</p>
                </div>
                <a href="index.php" style="color: #667eea; text-decoration: none;">← Back to Dashboard</a>
            </div>
        </div>
        
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form">
                <div class="filter-group">
                    <label>Select Semester</label>
                    <select name="semester_id" onchange="this.form.submit()">
                        <?php foreach($available_semesters as $sem): ?>
                            <option value="<?php echo $sem['id']; ?>" <?php echo $selected_semester == $sem['id'] ? 'selected' : ''; ?>>
                                <?php echo $sem['semester_name']; ?> Semester - <?php echo $sem['session_name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <button type="button" onclick="window.print()" class="btn">🖨️ Print Results</button>
                </div>
            </form>
        </div>
        
        <?php if($semester_info && !empty($results)): ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo count($results); ?></div>
                <div class="stat-label">Courses Taken</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($total_cu, 2); ?></div>
                <div class="stat-label">Total Credit Units</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($total_cup, 2); ?></div>
                <div class="stat-label">Total CUP</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($gpa, 2); ?></div>
                <div class="stat-label">Semester GPA</div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header green">
                <?php echo $semester_info['name']; ?> Semester Results - <?php echo $semester_info['session_name']; ?>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>S/N</th>
                            <th>Course Code</th>
                            <th>Course Title</th>
                            <th>Credit Unit</th>
                            <th>CA Score</th>
                            <th>Exam Score</th>
                            <th>Total (%)</th>
                            <th>Grade</th>
                            <th>GP</th>
                            <th>CUP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sn = 1; foreach($results as $result): ?>
                            <tr>
                                <td><?php echo $sn++; ?></td>
                                <td><?php echo htmlspecialchars($result['code']); ?></td>
                                <td><?php echo htmlspecialchars($result['title']); ?></td>
                                <td><?php echo $result['credit_unit']; ?></td>
                                <td><?php echo number_format($result['ca_score'], 2); ?></td>
                                <td><?php echo number_format($result['exam_score'], 2); ?></td>
                                <td><?php echo number_format($result['total_score'], 2); ?>%</td>
                                <td class="grade-<?php echo $result['grade']; ?>"><?php echo $result['grade']; ?></td>
                                <td><?php echo number_format($result['grade_point'], 2); ?></td>
                                <td><?php echo number_format($result['course_unit_point'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="3"><strong>TOTAL</strong></td>
                            <td><strong><?php echo number_format($total_cu, 2); ?></strong></td>
                            <td colspan="5"></td>
                            <td><strong><?php echo number_format($total_cup, 2); ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div style="padding: 20px; text-align: center;">
                <strong>GPA = Total CUP ÷ Total CU = <?php echo number_format($total_cup, 2); ?> ÷ <?php echo number_format($total_cu, 2); ?> = <?php echo number_format($gpa, 2); ?></strong>
            </div>
        </div>
        <?php elseif($selected_semester): ?>
            <div style="background: #feebc8; padding: 20px; border-radius: 12px; text-align: center;">
                No results available for the selected semester.
            </div>
        <?php else: ?>
            <div style="background: #e9f5ff; padding: 20px; border-radius: 12px; text-align: center;">
                No results available yet. Results will appear here once approved.
            </div>
        <?php endif; ?>
        
        <!-- CGPA Section -->
        <div class="card">
            <div class="card-header">
                📈 Cumulative Grade Point Average (CGPA)
            </div>
            <div class="standing-box">
                <div style="font-size: 48px; font-weight: bold; color: #667eea;"><?php echo number_format($cgpa, 2); ?></div>
                <div><strong>Academic Standing: <?php echo $academic_standing[0]; ?></strong></div>
            </div>
        </div>
    </div>
</body>
</html>
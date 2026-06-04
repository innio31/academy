<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole(['student']);

// Get available semesters with results for this student
$stmt = $pdo->prepare("
    SELECT DISTINCT 
        s.id, 
        s.name as semester_name, 
        a.id as session_id, 
        a.name as session_name,
        a.start_date
    FROM results r
    JOIN semesters s ON r.semester_id = s.id
    JOIN academic_sessions a ON s.session_id = a.id
    WHERE r.student_id = ? AND r.is_approved = 1
    ORDER BY a.start_date DESC, s.id DESC
");
$stmt->execute([$_SESSION['student_id']]);
$available_semesters = $stmt->fetchAll();

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
    
    // Get results with course info
    $stmt = $pdo->prepare("
        SELECT 
            r.*, 
            c.code, 
            c.title, 
            c.credit_unit
        FROM results r
        JOIN courses c ON r.course_id = c.id
        WHERE r.student_id = ? AND r.semester_id = ? AND r.is_approved = 1
        ORDER BY c.code
    ");
    $stmt->execute([$_SESSION['student_id'], $selected_semester]);
    $results = $stmt->fetchAll();
    
    foreach($results as $result) {
        $total_cu += $result['credit_unit'];
        $total_cup += $result['course_unit_point'];
    }
    $gpa = $total_cu > 0 ? $total_cup / $total_cu : 0;
}

// Calculate CGPA across all semesters
$cgpa = 0;
$stmt = $pdo->prepare("
    SELECT 
        SUM(c.credit_unit) as total_cu,
        SUM(r.course_unit_point) as total_cup
    FROM results r
    JOIN courses c ON r.course_id = c.id
    WHERE r.student_id = ? AND r.is_approved = 1
");
$stmt->execute([$_SESSION['student_id']]);
$cgpa_totals = $stmt->fetch();
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
    <title>My Results - Student Portal</title>
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
        
        .filter-bar {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }
        .filter-group { flex: 1; min-width: 200px; }
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
            background: white;
        }
        .btn {
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }
        .btn:hover { background: #5a67d8; }
        
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
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            margin: 20px;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px;
            color: #718096;
        }
        
        @media (max-width: 640px) {
            .filter-form { flex-direction: column; }
            .filter-group { width: 100%; }
            th, td { padding: 8px; font-size: 12px; }
        }
        
        @media print {
            .filter-bar, .btn, .no-print, .header a { display: none; }
            body { background: white; padding: 0; margin: 0; }
            .container { max-width: 100%; }
            .card { box-shadow: none; border: 1px solid #ddd; }
            .stats-grid { page-break-inside: avoid; }
            table { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h1>📊 My Results</h1>
                    <p>View your academic performance and transcripts</p>
                </div>
                <a href="index.php" style="color: #667eea; text-decoration: none;">← Back to Dashboard</a>
            </div>
        </div>
        
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form">
                <div class="filter-group">
                    <label>Select Semester</label>
                    <select name="semester_id" onchange="this.form.submit()">
                        <option value="">-- Select Semester --</option>
                        <?php foreach($available_semesters as $sem): ?>
                            <option value="<?php echo $sem['id']; ?>" <?php echo $selected_semester == $sem['id'] ? 'selected' : ''; ?>>
                                <?php echo $sem['semester_name']; ?> Semester - <?php echo $sem['session_name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if(!empty($results)): ?>
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <button type="button" onclick="window.print()" class="btn">🖨️ Print Results</button>
                </div>
                <?php endif; ?>
            </form>
        </div>
        
        <?php if($semester_info && !empty($results)): ?>
        <!-- Semester Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo count($results); ?></div>
                <div class="stat-label">Courses Taken</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($total_cu, 0); ?></div>
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
        
        <!-- Results Table -->
        <div class="card">
            <div class="card-header green">
                📋 <?php echo $semester_info['name']; ?> Semester Results - <?php echo $semester_info['session_name']; ?>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>S/N</th>
                            <th>Course Code</th>
                            <th>Course Title</th>
                            <th>CU</th>
                            <th>CA</th>
                            <th>Exam</th>
                            <th>Total</th>
                            <th>Grade</th>
                            <th>GP</th>
                            <th>CUP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sn = 1; foreach($results as $result): 
                            $grade_class = 'grade-' . $result['grade'];
                            if ($result['grade'] === 'AB') $grade_class = 'grade-AB';
                            if ($result['grade'] === 'BC') $grade_class = 'grade-BC';
                            if ($result['grade'] === 'CD') $grade_class = 'grade-CD';
                        ?>
                            <tr>
                                <td><?php echo $sn++; ?></td>
                                <td><strong><?php echo htmlspecialchars($result['code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($result['title']); ?></td>
                                <td><?php echo $result['credit_unit']; ?></td>
                                <td><?php echo number_format($result['ca_score'], 1); ?></td>
                                <td><?php echo number_format($result['exam_score'], 1); ?></td>
                                <td><strong><?php echo number_format($result['total_score'], 1); ?>%</strong></td>
                                <td class="<?php echo $grade_class; ?>"><?php echo $result['grade']; ?></td>
                                <td><?php echo number_format($result['grade_point'], 2); ?></td>
                                <td><?php echo number_format($result['course_unit_point'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="3"><strong>TOTAL</strong></td>
                            <td><strong><?php echo number_format($total_cu, 0); ?></strong></td>
                            <td colspan="5"></td>
                            <td><strong><?php echo number_format($total_cup, 2); ?></strong></td>
                            <td><strong><?php echo number_format($gpa, 2); ?></strong> (GPA)</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div style="padding: 15px 20px; background: #f7fafc; text-align: center; font-size: 14px;">
                <strong>GPA Calculation:</strong> Total CUP (<?php echo number_format($total_cup, 2); ?>) ÷ Total CU (<?php echo number_format($total_cu, 0); ?>) = <strong><?php echo number_format($gpa, 2); ?></strong>
            </div>
        </div>
        
        <?php elseif($selected_semester): ?>
            <div class="card">
                <div class="empty-state">
                    <p>📭 No results available for the selected semester.</p>
                    <p style="font-size: 12px; margin-top: 10px;">Results will appear here once approved by the academic office.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="empty-state">
                    <p>📭 No results available yet.</p>
                    <p style="font-size: 12px; margin-top: 10px;">Results will appear here once approved by the academic office.</p>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- CGPA Section (Always show if there are any results) -->
        <?php if($cgpa > 0): ?>
        <div class="card">
            <div class="card-header">
                📈 Cumulative Grade Point Average (CGPA)
            </div>
            <div class="standing-box">
                <div style="font-size: 48px; font-weight: bold; color: #667eea;"><?php echo number_format($cgpa, 2); ?></div>
                <div style="margin-top: 10px;">
                    <strong>Academic Standing: <?php echo $academic_standing[0]; ?></strong>
                </div>
                <div style="font-size: 12px; color: #718096; margin-top: 15px;">
                    Based on all approved results across all semesters
                </div>
                <div style="font-size: 11px; color: #718096; margin-top: 5px;">
                    Total CU: <?php echo number_format($cgpa_totals['total_cu'] ?? 0, 0); ?> | 
                    Total CUP: <?php echo number_format($cgpa_totals['total_cup'] ?? 0, 2); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Allow only super_admin, admin, and dean
$allowed_roles = ['super_admin', 'admin'];
$user_role = $_SESSION['role'] ?? '';

// Check if user is dean (based on staff designation)
$is_dean = false;
if ($user_role === 'staff') {
    $stmt = $pdo->prepare("SELECT designation FROM staff WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $staff = $stmt->fetch();
    $is_dean = ($staff && $staff['designation'] === 'Dean');
}

if (!in_array($user_role, $allowed_roles) && !$is_dean) {
    header("Location: ../unauthorized.php");
    exit();
}

$message = '';
$error = '';
$success = '';

// Get filter parameters
$session_filter = $_GET['session'] ?? '';
$semester_filter = $_GET['semester'] ?? '';
$department_filter = $_GET['department'] ?? '';
$course_filter = $_GET['course'] ?? '';
$status_filter = $_GET['status'] ?? 'pending'; // pending, approved, all

// Get all sessions for filter
$sessions = $pdo->query("SELECT id, name, is_current FROM academic_sessions ORDER BY start_date DESC")->fetchAll();

// Get all semesters for filter
$semesters = $pdo->query("
    SELECT s.*, a.name as session_name 
    FROM semesters s 
    JOIN academic_sessions a ON s.session_id = a.id 
    ORDER BY a.start_date DESC, s.id
")->fetchAll();

// Get departments (for dean filtering)
$departments = [];
if ($is_dean) {
    // Get dean's department
    $stmt = $pdo->prepare("
        SELECT d.id, d.name, d.code 
        FROM staff s
        JOIN departments d ON s.department_id = d.id
        WHERE s.user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $dean_dept = $stmt->fetch();
    if ($dean_dept) {
        $departments = [$dean_dept];
        $department_filter = $dean_dept['id']; // Force filter to dean's department
    }
} else {
    // Admin/super_admin can see all departments
    $departments = $pdo->query("SELECT id, name, code FROM departments ORDER BY name")->fetchAll();
}

// Build query for pending results
$sql = "
    SELECT 
        r.id as result_id,
        r.ca_score,
        r.exam_score,
        r.total_score,
        r.grade,
        r.grade_point,
        r.course_unit_point,
        r.is_approved,
        r.approved_by,
        r.approved_at,
        r.created_at,
        s.id as student_id,
        s.reg_number,
        u.first_name,
        u.last_name,
        u.email,
        c.id as course_id,
        c.code as course_code,
        c.title as course_title,
        c.credit_unit,
        d.id as department_id,
        d.name as department_name,
        d.code as department_code,
        a.id as session_id,
        a.name as session_name,
        sem.name as semester_name,
        CONCAT(staff_u.first_name, ' ', staff_u.last_name) as lecturer_name,
        app.first_name as approved_by_first,
        app.last_name as approved_by_last
    FROM results r
    JOIN students s ON r.student_id = s.id
    JOIN users u ON s.user_id = u.id
    JOIN courses c ON r.course_id = c.id
    JOIN departments d ON c.department_id = d.id
    JOIN academic_sessions a ON r.session_id = a.id
    JOIN semesters sem ON r.semester_id = sem.id
    LEFT JOIN course_offerings co ON c.id = co.course_id AND r.semester_id = co.semester_id
    LEFT JOIN staff st ON co.lecturer_id = st.user_id
    LEFT JOIN users staff_u ON st.user_id = staff_u.id
    LEFT JOIN users app ON r.approved_by = app.id
    WHERE 1=1
";

$params = [];

if ($status_filter === 'pending') {
    $sql .= " AND r.is_approved = 0";
} elseif ($status_filter === 'approved') {
    $sql .= " AND r.is_approved = 1";
}

if ($session_filter) {
    $sql .= " AND r.session_id = ?";
    $params[] = $session_filter;
}
if ($semester_filter) {
    $sql .= " AND r.semester_id = ?";
    $params[] = $semester_filter;
}
if ($department_filter) {
    $sql .= " AND c.department_id = ?";
    $params[] = $department_filter;
}
if ($course_filter) {
    $sql .= " AND c.id = ?";
    $params[] = $course_filter;
}

$sql .= " ORDER BY d.name, c.code, u.last_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll();

// Get courses for filter (based on department)
$courses = [];
if ($department_filter) {
    $stmt = $pdo->prepare("
        SELECT id, code, title FROM courses WHERE department_id = ? ORDER BY code
    ");
    $stmt->execute([$department_filter]);
    $courses = $stmt->fetchAll();
}

// Handle approval submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_selected'])) {
    $result_ids = $_POST['result_ids'] ?? [];
    $approved_count = 0;
    
    if (empty($result_ids)) {
        $error = "Please select at least one result to approve.";
    } else {
        foreach ($result_ids as $result_id) {
            $stmt = $pdo->prepare("
                UPDATE results 
                SET is_approved = 1, approved_by = ?, approved_at = NOW() 
                WHERE id = ? AND is_approved = 0
            ");
            if ($stmt->execute([$_SESSION['user_id'], $result_id])) {
                $approved_count++;
            }
        }
        $success = "$approved_count result(s) approved successfully.";
        
        // Refresh results after approval
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();
    }
}

// Handle single result approval via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_approve'])) {
    header('Content-Type: application/json');
    $result_id = $_POST['result_id'] ?? 0;
    
    if ($result_id) {
        $stmt = $pdo->prepare("
            UPDATE results 
            SET is_approved = 1, approved_by = ?, approved_at = NOW() 
            WHERE id = ? AND is_approved = 0
        ");
        if ($stmt->execute([$_SESSION['user_id'], $result_id])) {
            echo json_encode(['success' => true, 'message' => 'Result approved successfully']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to approve result']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid result ID']);
    }
    exit();
}

// Get statistics
$stats = [];
$stmt = $pdo->query("
    SELECT 
        COUNT(CASE WHEN is_approved = 0 THEN 1 END) as pending_count,
        COUNT(CASE WHEN is_approved = 1 THEN 1 END) as approved_count,
        COUNT(*) as total_count,
        AVG(CASE WHEN is_approved = 0 THEN total_score END) as pending_avg,
        AVG(CASE WHEN is_approved = 1 THEN total_score END) as approved_avg
    FROM results
");
$stats = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Approve Results - Admin Portal</title>
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 24px; color: #2d3748; margin-bottom: 8px; }
        .header p { color: #718096; font-size: 14px; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .stat-number { font-size: 32px; font-weight: bold; }
        .stat-number.pending { color: #ed8936; }
        .stat-number.approved { color: #48bb78; }
        .stat-number.total { color: #667eea; }
        .stat-label { font-size: 12px; color: #718096; margin-top: 5px; }
        
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
        .filter-group select, .filter-group input {
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
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
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
        
        .status-pending {
            background: #feebc8;
            color: #7c2d12;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
            font-size: 11px;
            font-weight: 600;
        }
        .status-approved {
            background: #c6f6d5;
            color: #22543d;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
            font-size: 11px;
            font-weight: 600;
        }
        
        .checkbox-col {
            width: 40px;
            text-align: center;
        }
        .checkbox-col input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
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
            text-align: center;
            padding: 60px;
            color: #718096;
        }
        
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }
        .badge-dean { background: #ed8936; color: white; }
        
        @media (max-width: 768px) {
            .filter-form { flex-direction: column; }
            .filter-group { width: 100%; }
            th, td { padding: 8px; font-size: 12px; }
            .checkbox-col { width: 30px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>✅ Result Approval</h1>
                <p>Review and approve student results submitted by lecturers</p>
            </div>
            <div>
                <?php if($is_dean): ?>
                    <span class="badge badge-dean">Dean Access</span>
                <?php endif; ?>
                <a href="index.php" style="color: #667eea; text-decoration: none; margin-left: 15px;">← Back to Dashboard</a>
            </div>
        </div>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number pending"><?php echo number_format($stats['pending_count'] ?? 0); ?></div>
                <div class="stat-label">Pending Approval</div>
            </div>
            <div class="stat-card">
                <div class="stat-number approved"><?php echo number_format($stats['approved_count'] ?? 0); ?></div>
                <div class="stat-label">Approved Results</div>
            </div>
            <div class="stat-card">
                <div class="stat-number total"><?php echo number_format($stats['total_count'] ?? 0); ?></div>
                <div class="stat-label">Total Results</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo round($stats['pending_avg'] ?? 0, 1); ?>%</div>
                <div class="stat-label">Pending Avg Score</div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form" id="filterForm">
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
                <div class="filter-group">
                    <label>Department</label>
                    <select name="department" id="departmentSelect" onchange="this.form.submit()" <?php echo $is_dean ? 'disabled' : ''; ?>>
                        <option value="">All Departments</option>
                        <?php foreach($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" <?php echo $department_filter == $dept['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Course</label>
                    <select name="course" onchange="this.form.submit()">
                        <option value="">All Courses</option>
                        <?php foreach($courses as $course): ?>
                            <option value="<?php echo $course['id']; ?>" <?php echo $course_filter == $course['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($course['code'] . ' - ' . $course['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Status</label>
                    <select name="status" onchange="this.form.submit()">
                        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending Approval</option>
                        <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Results</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <a href="approve_results.php" class="btn btn-outline">Clear Filters</a>
                </div>
            </form>
        </div>
        
        <!-- Results Table -->
        <div class="card">
            <div class="card-header">
                <span>📋 Results List</span>
                <span><?php echo count($results); ?> record(s) found</span>
            </div>
            <div class="card-body">
                <?php if(empty($results)): ?>
                    <div class="empty-state">
                        <p>No results found matching your criteria.</p>
                    </div>
                <?php else: ?>
                    <form method="POST" action="" id="approvalForm">
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <?php if($status_filter !== 'approved'): ?>
                                            <th class="checkbox-col">
                                                <input type="checkbox" id="selectAll" onclick="toggleSelectAll()">
                                            </th>
                                        <?php endif; ?>
                                        <th>S/N</th>
                                        <th>Student</th>
                                        <th>Reg Number</th>
                                        <th>Course</th>
                                        <th>Department</th>
                                        <th>CA (30)</th>
                                        <th>Exam (70)</th>
                                        <th>Total</th>
                                        <th>Grade</th>
                                        <th>GP</th>
                                        <th>CUP</th>
                                        <th>Lecturer</th>
                                        <th>Status</th>
                                        <?php if($status_filter !== 'approved'): ?>
                                            <th>Action</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $sn = 1;
                                    foreach($results as $result):
                                        $grade_class = $result['grade'] ? 'grade-' . substr($result['grade'], 0, 1) : '';
                                        if ($result['grade'] === 'AB') $grade_class = 'grade-AB';
                                        if ($result['grade'] === 'BC') $grade_class = 'grade-BC';
                                        if ($result['grade'] === 'CD') $grade_class = 'grade-CD';
                                    ?>
                                        <tr>
                                            <?php if($status_filter !== 'approved' && !$result['is_approved']): ?>
                                                <td class="checkbox-col">
                                                    <input type="checkbox" name="result_ids[]" value="<?php echo $result['result_id']; ?>" class="result-checkbox">
                                                </td>
                                            <?php elseif($status_filter !== 'approved' && $result['is_approved']): ?>
                                                <td class="checkbox-col"></td>
                                            <?php endif; ?>
                                            <td><?php echo $sn++; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($result['first_name'] . ' ' . $result['last_name']); ?></strong><br>
                                                <small style="color: #718096;"><?php echo htmlspecialchars($result['email']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($result['reg_number']); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($result['course_code']); ?><br>
                                                <small style="color: #718096;"><?php echo htmlspecialchars($result['course_title']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($result['department_code']); ?></td>
                                            <td><?php echo $result['ca_score']; ?></td>
                                            <td><?php echo $result['exam_score']; ?></td>
                                            <td><strong><?php echo $result['total_score']; ?>%</strong></td>
                                            <td><span class="grade-badge <?php echo $grade_class; ?>"><?php echo $result['grade'] ?: '-'; ?></span></td>
                                            <td><?php echo $result['grade_point']; ?></td>
                                            <td><?php echo $result['course_unit_point']; ?></td>
                                            <td><small><?php echo htmlspecialchars($result['lecturer_name'] ?: 'N/A'); ?></small></td>
                                            <td>
                                                <?php if($result['is_approved']): ?>
                                                    <span class="status-approved">✓ Approved</span>
                                                    <?php if($result['approved_by_first']): ?>
                                                        <br><small>by <?php echo $result['approved_by_first'] . ' ' . $result['approved_by_last']; ?></small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="status-pending">⏳ Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php if($status_filter !== 'approved' && !$result['is_approved']): ?>
                                                <td>
                                                    <button type="button" class="btn btn-success btn-sm" onclick="approveSingle(<?php echo $result['result_id']; ?>)">
                                                        Approve
                                                    </button>
                                                </td>
                                            <?php elseif($status_filter !== 'approved' && $result['is_approved']): ?>
                                                <td></td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php if($status_filter !== 'approved'): ?>
                            <div class="action-buttons">
                                <button type="button" class="btn btn-outline" onclick="selectAllPending()">✓ Select All Pending</button>
                                <button type="button" class="btn btn-outline" onclick="deselectAll()">✗ Deselect All</button>
                                <button type="submit" name="approve_selected" class="btn btn-success" onclick="return confirm('Approve selected results? This action cannot be undone.')">
                                    ✅ Approve Selected
                                </button>
                            </div>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.result-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
        }
        
        function selectAllPending() {
            const checkboxes = document.querySelectorAll('.result-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
            const selectAll = document.getElementById('selectAll');
            if (selectAll) selectAll.checked = true;
        }
        
        function deselectAll() {
            const checkboxes = document.querySelectorAll('.result-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            const selectAll = document.getElementById('selectAll');
            if (selectAll) selectAll.checked = false;
        }
        
        async function approveSingle(resultId) {
            if (!confirm('Approve this result? This action cannot be undone.')) return;
            
            const formData = new FormData();
            formData.append('ajax_approve', 1);
            formData.append('result_id', resultId);
            
            try {
                const response = await fetch('approve_results.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success) {
                    showFlash(data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showFlash(data.error, 'error');
                }
            } catch (error) {
                showFlash('Error approving result', 'error');
            }
        }
        
        function showFlash(message, type) {
            const flash = document.createElement('div');
            flash.className = `flash-message flash-${type}`;
            flash.innerHTML = message;
            document.body.appendChild(flash);
            setTimeout(() => flash.remove(), 3000);
        }
        
        // Department change triggers course reload
        document.getElementById('departmentSelect')?.addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });
        
        // Flash messages for PHP
        <?php if($success): ?>
            showFlash('<?php echo addslashes($success); ?>', 'success');
        <?php endif; ?>
        <?php if($error): ?>
            showFlash('<?php echo addslashes($error); ?>', 'error');
        <?php endif; ?>
    </script>
</body>
</html>
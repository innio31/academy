<?php
// gos/report-card/index.php - Role-based Report Card Dashboard
// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include config (which already starts the session)
require_once '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id']) && !isset($_SESSION['staff_id'])) {
    header("Location: ../login.php");
    exit();
}

// Use the global pdo variable from config
global $pdo;

// Determine user type and permissions
if (isset($_SESSION['admin_id'])) {
    $user_type = 'admin';
    $user_name = $_SESSION['admin_name'] ?? $_SESSION['full_name'] ?? 'Administrator';
    $user_id = $_SESSION['admin_id'];
} elseif (isset($_SESSION['staff_id'])) {
    $user_type = 'staff';
    $user_name = $_SESSION['staff_name'] ?? $_SESSION['full_name'] ?? 'Staff';
    $user_id = $_SESSION['staff_id'];
} else {
    $user_type = $_SESSION['user_type'] ?? 'student';
    $user_name = $_SESSION['full_name'] ?? $_SESSION['user_name'] ?? 'Student';
    $user_id = $_SESSION['user_id'];
}

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;

// Get current session and term
$current_session = date('Y') . '/' . (date('Y') + 1);
$current_term = 'First';

// For staff, get assigned classes
$assigned_classes = [];
if ($user_type === 'staff') {
    $staff_id = $_SESSION['staff_id'] ?? $user_id;
    try {
        $stmt = $pdo->prepare("SELECT class FROM staff_classes WHERE staff_id = ?");
        $stmt->execute([$staff_id]);
        $assigned_classes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $assigned_classes = [];
    }
}

// Get statistics
$total_students = 0;
$total_subjects = 0;
$students_with_scores = 0;
$recent_reports = [];

try {
    if ($user_type === 'admin') {
        // Total students
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE school_id = ? AND status = 'active'");
        $stmt->execute([$school_id]);
        $total_students = $stmt->fetchColumn();
        
        // Total subjects
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE school_id = ?");
        $stmt->execute([$school_id]);
        $total_subjects = $stmt->fetchColumn();
        
        // Students with scores this term
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) FROM student_scores ss 
                               JOIN students s ON ss.student_id = s.id 
                               WHERE s.school_id = ? AND ss.session = ? AND ss.term = ?");
        $stmt->execute([$school_id, $current_session, $current_term]);
        $students_with_scores = $stmt->fetchColumn();
        
        // Recent report cards
        $stmt = $pdo->prepare("
            SELECT s.id, s.full_name, s.class, sp.session, sp.term, sp.average, sp.class_position 
            FROM student_positions sp
            JOIN students s ON sp.student_id = s.id
            WHERE s.school_id = ?
            ORDER BY sp.created_at DESC LIMIT 10
        ");
        $stmt->execute([$school_id]);
        $recent_reports = $stmt->fetchAll();
        
    } elseif ($user_type === 'staff' && !empty($assigned_classes)) {
        $placeholders = str_repeat('?,', count($assigned_classes) - 1) . '?';
        $params = array_merge([$school_id], $assigned_classes);
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE school_id = ? AND status = 'active' AND class IN ($placeholders)");
        $stmt->execute($params);
        $total_students = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("
            SELECT s.id, s.full_name, s.class, sp.session, sp.term, sp.average, sp.class_position 
            FROM student_positions sp
            JOIN students s ON sp.student_id = s.id
            WHERE s.school_id = ? AND s.class IN ($placeholders)
            ORDER BY sp.created_at DESC LIMIT 10
        ");
        $stmt->execute($params);
        $recent_reports = $stmt->fetchAll();
        
    } elseif ($user_type === 'student') {
        $stmt = $pdo->prepare("
            SELECT s.id, s.full_name, s.class, sp.session, sp.term, sp.average, sp.class_position 
            FROM student_positions sp
            JOIN students s ON sp.student_id = s.id
            WHERE s.id = ? AND s.school_id = ?
            ORDER BY sp.created_at DESC LIMIT 5
        ");
        $stmt->execute([$user_id, $school_id]);
        $recent_reports = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    // Display error for debugging
    echo "Database Error: " . $e->getMessage();
    // Don't exit, continue with empty data
}

// Get available sessions for filter
$sessions = [];
$stmt = $pdo->prepare("SELECT DISTINCT session FROM student_scores WHERE school_id = ? ORDER BY session DESC");
$stmt->execute([$school_id]);
$sessions = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($sessions)) {
    $sessions = [$current_session];
}

$terms = ['First', 'Second', 'Third'];

// Get classes for filter (admin only)
$classes = [];
if ($user_type === 'admin') {
    $stmt = $pdo->prepare("SELECT DISTINCT class FROM students WHERE school_id = ? AND status = 'active' ORDER BY class");
    $stmt->execute([$school_id]);
    $classes = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Report Card System</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: #d4af7a;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #3498db;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            color: #333;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-color), var(--dark-color));
            color: white;
            padding: 20px 0;
            transition: all 0.3s ease;
            z-index: 100;
            overflow-y: auto;
            transform: translateX(-100%);
        }
        .sidebar.active { transform: translateX(0); }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 20px;
            margin-bottom: 20px;
        }
        .logo-icon {
            width: 40px;
            height: 40px;
            background: var(--secondary-color);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        .admin-info {
            text-align: center;
            padding: 15px;
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
            margin: 0 15px 20px;
        }
        .nav-links { list-style: none; padding: 0 15px; }
        .nav-links li { margin-bottom: 5px; }
        .nav-links a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            border-radius: 8px;
        }
        .nav-links a:hover, .nav-links a.active { background: rgba(255,255,255,0.2); }
        
        /* Main Content */
        .main-content {
            margin-left: 0;
            padding: 20px;
            min-height: 100vh;
        }
        .mobile-menu-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 101;
            background: var(--primary-color);
            color: white;
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 10px;
            font-size: 20px;
            cursor: pointer;
        }
        .top-header {
            background: white;
            padding: 20px 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .header-title h1 { color: var(--primary-color); font-size: 1.6rem; margin-bottom: 5px; }
        .header-title p { color: #666; font-size: 0.85rem; }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .stat-value { font-size: 2rem; font-weight: 700; color: var(--primary-color); }
        .stat-label { color: #666; font-size: 0.8rem; margin-top: 5px; }
        
        /* Action Cards */
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .action-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            text-decoration: none;
            color: var(--primary-color);
            transition: all 0.3s ease;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 2px solid transparent;
        }
        .action-card:hover {
            transform: translateY(-5px);
            border-color: var(--primary-color);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .action-icon { font-size: 2rem; margin-bottom: 10px; display: block; }
        .action-title { font-size: 1rem; font-weight: 600; margin-bottom: 5px; }
        .action-desc { font-size: 0.75rem; color: #666; }
        
        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .filter-section h3 { margin-bottom: 15px; font-size: 1.1rem; }
        .filter-form {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .form-group { flex: 1; min-width: 150px; }
        .form-group label { display: block; margin-bottom: 5px; font-size: 0.8rem; color: #666; }
        .form-control, .form-select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }
        .btn-primary { background: var(--primary-color); color: white; }
        .btn-success { background: var(--success-color); color: white; }
        .btn-secondary { background: #95a5a6; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 0.8rem; }
        
        /* Table */
        .table-container {
            background: white;
            border-radius: 15px;
            overflow-x: auto;
            margin-bottom: 20px;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }
        .data-table th, .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
            font-size: 0.85rem;
        }
        .data-table th {
            background: var(--light-color);
            font-weight: 600;
            color: var(--dark-color);
        }
        .data-table tr:hover { background: #f9f9f9; }
        
        .empty-state {
            text-align: center;
            padding: 50px;
            color: #999;
        }
        
        @media (min-width: 769px) {
            .sidebar { transform: translateX(0); }
            .main-content { margin-left: 260px; }
            .mobile-menu-btn { display: none; }
        }
        @media (max-width: 768px) {
            .filter-form { flex-direction: column; }
            .filter-form .btn { width: 100%; justify-content: center; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
            <div class="logo-text"><h3><?php echo htmlspecialchars($school_name); ?></h3><p>Report Cards</p></div>
        </div>
        <div class="admin-info">
            <h4><?php echo htmlspecialchars($user_name); ?></h4>
            <p><?php echo ucfirst($user_type); ?></p>
        </div>
        <ul class="nav-links">
            <li><a href="index.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <?php if ($user_type === 'admin' || $user_type === 'staff'): ?>
            <li><a href="enter-scores.php"><i class="fas fa-edit"></i> Enter Scores</a></li>
            <li><a href="enter-comments.php"><i class="fas fa-comment"></i> Comments & Traits</a></li>
            <?php endif; ?>
            <?php if ($user_type === 'admin'): ?>
            <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
            <li><a href="calculate-positions.php"><i class="fas fa-chart-line"></i> Calculate Positions</a></li>
            <?php endif; ?>
            <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-file-contract"></i> Report Card System</h1>
                <p>Manage student report cards for <?php echo $current_session; ?> - <?php echo $current_term; ?> Term</p>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_students; ?></div>
                <div class="stat-label">Total Students</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_subjects; ?></div>
                <div class="stat-label">Subjects</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $students_with_scores; ?></div>
                <div class="stat-label">Students with Scores</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count($recent_reports); ?></div>
                <div class="stat-label">Reports Generated</div>
            </div>
        </div>
        
        <!-- Action Cards -->
        <div class="actions-grid">
            <?php if ($user_type === 'admin' || $user_type === 'staff'): ?>
            <a href="enter-scores.php" class="action-card">
                <span class="action-icon">📝</span>
                <div class="action-title">Enter Scores</div>
                <div class="action-desc">Input student academic scores</div>
            </a>
            <a href="enter-comments.php" class="action-card">
                <span class="action-icon">💬</span>
                <div class="action-title">Comments & Traits</div>
                <div class="action-desc">Add behavioral comments and ratings</div>
            </a>
            <?php endif; ?>
            <?php if ($user_type === 'admin'): ?>
            <a href="settings.php" class="action-card">
                <span class="action-icon">⚙️</span>
                <div class="action-title">Settings</div>
                <div class="action-desc">Configure grading system</div>
            </a>
            <a href="calculate-positions.php" class="action-card">
                <span class="action-icon">📊</span>
                <div class="action-title">Calculate Positions</div>
                <div class="action-desc">Compute class rankings</div>
            </a>
            <?php endif; ?>
        </div>
        
        <!-- Student/Report Card Lookup -->
        <div class="filter-section">
            <h3><i class="fas fa-search"></i> View Report Card</h3>
            <form method="GET" action="view-report.php" class="filter-form">
                <?php if ($user_type === 'admin' && !empty($classes)): ?>
                <div class="form-group">
                    <label>Class</label>
                    <select name="class" id="classSelect" class="form-select" required>
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo htmlspecialchars($class); ?>"><?php echo htmlspecialchars($class); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Student</label>
                    <select name="student_id" id="studentSelect" class="form-select" required>
                        <option value="">Select Student</option>
                    </select>
                </div>
                <?php elseif ($user_type === 'staff' && !empty($assigned_classes)): ?>
                <div class="form-group">
                    <label>Class</label>
                    <select name="class" id="classSelect" class="form-select" required>
                        <option value="">Select Class</option>
                        <?php foreach ($assigned_classes as $class): ?>
                            <option value="<?php echo htmlspecialchars($class); ?>"><?php echo htmlspecialchars($class); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Student</label>
                    <select name="student_id" id="studentSelect" class="form-select" required>
                        <option value="">Select Student</option>
                    </select>
                </div>
                <?php elseif ($user_type === 'student'): ?>
                    <input type="hidden" name="student_id" value="<?php echo $user_id; ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label>Session</label>
                    <select name="session" class="form-select">
                        <?php foreach ($sessions as $session): ?>
                            <option value="<?php echo htmlspecialchars($session); ?>"><?php echo htmlspecialchars($session); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Term</label>
                    <select name="term" class="form-select">
                        <?php foreach ($terms as $term): ?>
                            <option value="<?php echo $term; ?>"><?php echo $term; ?> Term</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-eye"></i> View Report Card</button>
            </form>
        </div>
        
        <!-- Recent Report Cards -->
        <div class="table-container">
            <h3 style="padding: 15px 0 0 15px;"><i class="fas fa-history"></i> Recent Report Cards</h3>
            <?php if (empty($recent_reports)): ?>
                <div class="empty-state">
                    <i class="fas fa-file-alt" style="font-size: 48px; margin-bottom: 15px; color: #ccc;"></i>
                    <p>No report cards generated yet.</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr><th>Student Name</th><th>Class</th><th>Session</th><th>Term</th><th>Average</th><th>Grade</th><th>Position</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_reports as $report): 
                            $grade = ($report['average'] >= 70) ? 'A' : (($report['average'] >= 60) ? 'B' : (($report['average'] >= 50) ? 'C' : (($report['average'] >= 45) ? 'D' : 'F')));
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($report['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($report['class']); ?></td>
                                <td><?php echo htmlspecialchars($report['session']); ?></td>
                                <td><?php echo htmlspecialchars($report['term']); ?> Term</td>
                                <td><?php echo number_format($report['average'], 1); ?>%</td>
                                <td><span style="display:inline-block; padding:4px 10px; border-radius:20px; background:<?php echo ($grade == 'A') ? '#27ae60' : (($grade == 'B') ? '#2ecc71' : (($grade == 'C') ? '#f39c12' : (($grade == 'D') ? '#e67e22' : '#e74c3c'))); ?>; color:white;"><?php echo $grade; ?></span></td>
                                <td><?php echo $report['class_position'] ? $report['class_position'] . 'th' : '-'; ?></td>
                                <td><a href="view-report.php?student_id=<?php echo $report['id']; ?>&session=<?php echo urlencode($report['session']); ?>&term=<?php echo urlencode($report['term']); ?>" class="btn btn-primary btn-sm"><i class="fas fa-eye"></i> View</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        const mobileBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        if(mobileBtn) mobileBtn.onclick = () => sidebar.classList.toggle('active');
        
        // Dynamic student loading based on class selection
        const classSelect = document.getElementById('classSelect');
        const studentSelect = document.getElementById('studentSelect');
        
        if (classSelect && studentSelect) {
            classSelect.addEventListener('change', function() {
                const classVal = this.value;
                if (classVal) {
                    fetch(`get-students.php?class=${encodeURIComponent(classVal)}`)
                        .then(res => res.json())
                        .then(data => {
                            studentSelect.innerHTML = '<option value="">Select Student</option>';
                            if (Array.isArray(data)) {
                                data.forEach(student => {
                                    studentSelect.innerHTML += `<option value="${student.id}">${student.full_name} (${student.admission_number || ''})</option>`;
                                });
                            }
                        })
                        .catch(err => console.log('Error:', err));
                } else {
                    studentSelect.innerHTML = '<option value="">Select Student</option>';
                }
            });
        }
    </script>
</body>
</html>
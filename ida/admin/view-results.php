<?php
// admin/view-results.php - View Exam Results with Multi-School Support
session_start();

// Check if admin is logged in (support both session styles)
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /ida/login.php");
    exit();
}

// Get admin info
if (isset($_SESSION['admin_id'])) {
    $admin_id = $_SESSION['admin_id'];
    $admin_name = $_SESSION['admin_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'super_admin';
} else {
    $admin_id = $_SESSION['user_id'];
    $admin_name = $_SESSION['user_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;

// Get filter parameters
$exam_filter = $_GET['exam_id'] ?? '';
$class_filter = $_GET['class'] ?? '';
$student_search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$grade_filter = $_GET['grade'] ?? '';

// Get all exams for this school
$exams_stmt = $pdo->prepare("SELECT id, exam_name, class FROM exams WHERE school_id = ? ORDER BY created_at DESC");
$exams_stmt->execute([$school_id]);
$exams = $exams_stmt->fetchAll();

// Get all classes for filter
$classes_stmt = $pdo->prepare("SELECT DISTINCT class FROM students WHERE school_id = ? AND status = 'active' ORDER BY class");
$classes_stmt->execute([$school_id]);
$classes = $classes_stmt->fetchAll();

// Build query for results
// Build query for results
$query = "
    SELECT r.*, 
           s.full_name as student_name, 
           s.admission_number, 
           s.class as student_class,
           e.exam_name,
           e.subject_id,
           sub.subject_name,
           e.exam_type,
           e.duration_minutes
    FROM results r
    JOIN students s ON r.student_id = s.id AND s.school_id = ?
    JOIN exams e ON r.exam_id = e.id AND e.school_id = ?
    LEFT JOIN subjects sub ON e.subject_id = sub.id
    WHERE 1=1
";

$params = [$school_id, $school_id];

if (!empty($exam_filter)) {
    $query .= " AND r.exam_id = ?";
    $params[] = $exam_filter;
}
if (!empty($class_filter)) {
    $query .= " AND s.class = ?";
    $params[] = $class_filter;
}
if (!empty($student_search)) {
    $query .= " AND (s.full_name LIKE ? OR s.admission_number LIKE ?)";
    $params[] = "%$student_search%";
    $params[] = "%$student_search%";
}
if (!empty($date_from)) {
    $query .= " AND DATE(r.submitted_at) >= ?";
    $params[] = $date_from;
}
if (!empty($date_to)) {
    $query .= " AND DATE(r.submitted_at) <= ?";
    $params[] = $date_to;
}
if (!empty($grade_filter)) {
    $query .= " AND r.grade = ?";
    $params[] = $grade_filter;
}

$query .= " ORDER BY r.submitted_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$results = $stmt->fetchAll();

// Calculate statistics
$total_results = count($results);
$total_score = 0;
$grade_counts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
$pass_count = 0;
$fail_count = 0;

foreach ($results as $result) {
    $total_score += $result['total_score'] ?? 0;
    $grade = $result['grade'] ?? 'F';
    if (isset($grade_counts[$grade])) {
        $grade_counts[$grade]++;
    }
    if (($result['percentage'] ?? 0) >= 50) {
        $pass_count++;
    } else {
        $fail_count++;
    }
}

$average_score = $total_results > 0 ? round($total_score / $total_results, 2) : 0;
$pass_rate = $total_results > 0 ? round(($pass_count / $total_results) * 100, 1) : 0;

// Grade definitions
$grade_definitions = [
    'A' => ['min' => 70, 'max' => 100, 'color' => '#27ae60', 'description' => 'Excellent'],
    'B' => ['min' => 60, 'max' => 69, 'color' => '#2ecc71', 'description' => 'Very Good'],
    'C' => ['min' => 50, 'max' => 59, 'color' => '#f39c12', 'description' => 'Good'],
    'D' => ['min' => 45, 'max' => 49, 'color' => '#e67e22', 'description' => 'Pass'],
    'F' => ['min' => 0, 'max' => 44, 'color' => '#e74c3c', 'description' => 'Fail']
];

// Get top performers
$top_performers = array_slice($results, 0, 5);

// Get recent submissions
$recent_stmt = $pdo->prepare("
    SELECT r.*, s.full_name, e.exam_name 
    FROM results r
    JOIN students s ON r.student_id = s.id AND s.school_id = ?
    JOIN exams e ON r.exam_id = e.id AND e.school_id = ?
    ORDER BY r.submitted_at DESC 
    LIMIT 10
");
$recent_stmt->execute([$school_id, $school_id]);
$recent_results = $recent_stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - View Results</title>

    <!-- Chart.js for analytics -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
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
            --sidebar-width: 260px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            color: #333;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-color), var(--dark-color));
            color: white;
            padding: 20px 0;
            transition: all 0.3s ease;
            z-index: 100;
            overflow-y: auto;
            transform: translateX(-100%);
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 20px;
            margin-bottom: 15px;
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
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            margin: 0 15px 20px;
        }

        .nav-links {
            list-style: none;
            padding: 0 15px;
        }

        .nav-links li {
            margin-bottom: 5px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            border-radius: 8px;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255, 255, 255, 0.2);
        }

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
            padding: 15px 25px;
            border-radius: 10px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.8rem;
            margin-bottom: 5px;
        }

        .logout-btn {
            background: var(--danger-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .stat-label {
            color: #666;
            font-size: 0.85rem;
            margin-top: 5px;
        }

        .stat-card.success {
            border-left: 4px solid var(--success-color);
        }

        .stat-card.warning {
            border-left: 4px solid var(--warning-color);
        }

        .stat-card.info {
            border-left: 4px solid var(--info-color);
        }

        .stat-card.danger {
            border-left: 4px solid var(--danger-color);
        }

        /* Filter Section */
        .filter-section {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--primary-color);
            font-size: 0.85rem;
        }

        .form-control,
        .form-select {
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem;
            width: 100%;
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        /* Chart Container */
        .chart-container {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .chart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
        }

        .chart-box {
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .chart-box h4 {
            margin-bottom: 15px;
            color: var(--primary-color);
        }

        canvas {
            max-height: 250px;
            width: 100%;
        }

        /* Grade Badges */
        .grade-A {
            background: #27ae60;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .grade-B {
            background: #2ecc71;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .grade-C {
            background: #f39c12;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .grade-D {
            background: #e67e22;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .grade-F {
            background: #e74c3c;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Table */
        .table-container {
            background: white;
            border-radius: 10px;
            overflow-x: auto;
            margin-bottom: 20px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .data-table th {
            background: var(--primary-color);
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .data-table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
            font-size: 0.85rem;
        }

        .data-table tr:hover {
            background: #f9f9f9;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 15px 20px;
            border-bottom: 2px solid var(--light-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
        }

        @media (min-width: 769px) {
            .sidebar {
                transform: translateX(0);
            }

            .main-content {
                margin-left: var(--sidebar-width);
            }

            .mobile-menu-btn {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .filter-form {
                grid-template-columns: 1fr;
            }

            .chart-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>

    <div class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
            <div class="logo-text">
                <h3><?php echo htmlspecialchars($school_name); ?></h3>
                <p>Admin Panel</p>
            </div>
        </div>
        <div class="admin-info">
            <h4><?php echo htmlspecialchars($admin_name); ?></h4>
            <p><?php echo ucfirst($admin_role); ?></p>
        </div>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-students.php"><i class="fas fa-users"></i> Students</a></li>
            <li><a href="manage-staff.php"><i class="fas fa-chalkboard-teacher"></i> Staff</a></li>
            <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Subjects</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Exams</a></li>
            <li><a href="view-results.php" class="active"><i class="fas fa-chart-bar"></i> Results</a></li>
            <li><a href="../ida/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1>Exam Results</h1>
                <p>View and analyze student performance</p>
            </div>
            <button class="logout-btn" onclick="window.location.href='../ida/logout.php'">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card success">
                <div class="stat-value"><?php echo $total_results; ?></div>
                <div class="stat-label">Total Results</div>
            </div>
            <div class="stat-card info">
                <div class="stat-value"><?php echo $average_score; ?></div>
                <div class="stat-label">Average Score</div>
            </div>
            <div class="stat-card success">
                <div class="stat-value"><?php echo $pass_rate; ?>%</div>
                <div class="stat-label">Pass Rate</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-value"><?php echo $fail_count; ?></div>
                <div class="stat-label">Failed Students</div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" class="filter-form">
                <div class="form-group">
                    <label>Exam</label>
                    <select name="exam_id" class="form-select">
                        <option value="">All Exams</option>
                        <?php foreach ($exams as $exam): ?>
                            <option value="<?php echo $exam['id']; ?>" <?php echo $exam_filter == $exam['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($exam['exam_name'] . ' (' . $exam['class'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Class</label>
                    <select name="class" class="form-select">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo htmlspecialchars($class['class']); ?>" <?php echo $class_filter === $class['class'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Student Name/ID</label>
                    <input type="text" name="search" class="form-control" placeholder="Search student..." value="<?php echo htmlspecialchars($student_search); ?>">
                </div>
                <div class="form-group">
                    <label>Date From</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="form-group">
                    <label>Date To</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="form-group">
                    <label>Grade</label>
                    <select name="grade" class="form-select">
                        <option value="">All Grades</option>
                        <option value="A" <?php echo $grade_filter === 'A' ? 'selected' : ''; ?>>A (70-100%)</option>
                        <option value="B" <?php echo $grade_filter === 'B' ? 'selected' : ''; ?>>B (60-69%)</option>
                        <option value="C" <?php echo $grade_filter === 'C' ? 'selected' : ''; ?>>C (50-59%)</option>
                        <option value="D" <?php echo $grade_filter === 'D' ? 'selected' : ''; ?>>D (45-49%)</option>
                        <option value="F" <?php echo $grade_filter === 'F' ? 'selected' : ''; ?>>F (0-44%)</option>
                    </select>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply Filters</button>
                    <a href="view-results.php" class="btn btn-secondary"><i class="fas fa-redo"></i> Reset</a>
                </div>
            </form>
        </div>

        <!-- Charts -->
        <div class="chart-container">
            <div class="chart-grid">
                <div class="chart-box">
                    <h4>Grade Distribution</h4>
                    <canvas id="gradeChart"></canvas>
                </div>
                <div class="chart-box">
                    <h4>Pass vs Fail</h4>
                    <canvas id="passFailChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Results Table -->
        <div class="table-container">
            <?php if (empty($results)): ?>
                <div style="text-align: center; padding: 50px; color: #999;">
                    <i class="fas fa-chart-bar" style="font-size: 48px; margin-bottom: 15px;"></i>
                    <h3>No Results Found</h3>
                    <p>Try adjusting your filters or wait for students to take exams.</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Admission No</th>
                            <th>Class</th>
                            <th>Exam</th>
                            <th>Score</th>
                            <th>Percentage</th>
                            <th>Grade</th>
                            <th>Submitted</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $result):
                            $percentage = $result['percentage'] ?? 0;
                            $grade = $result['grade'] ?? 'F';
                            $grade_class = 'grade-' . $grade;
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($result['student_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($result['admission_number']); ?></td>
                                <td><?php echo htmlspecialchars($result['student_class']); ?></td>
                                <td><?php echo htmlspecialchars($result['exam_name']); ?></td>
                                <td><?php echo ($result['total_score'] ?? 0); ?></td>
                                <td><?php echo number_format($percentage, 1); ?>%</td>
                                <td><span class="<?php echo $grade_class; ?>"><?php echo $grade; ?></span></td>
                                <td><?php echo date('M d, Y', strtotime($result['submitted_at'])); ?></td>
                                <td>
                                    <button class="btn btn-primary btn-sm" onclick="viewResultDetails(<?php echo $result['id']; ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Export Button -->
        <?php if (!empty($results)): ?>
            <div style="text-align: center; margin-top: 20px;">
                <button class="btn btn-success" onclick="exportResults()">
                    <i class="fas fa-download"></i> Export to CSV
                </button>
            </div>
        <?php endif; ?>
    </div>

    <!-- Result Details Modal -->
    <div class="modal" id="resultModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Result Details</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="resultDetails">
                <p>Loading...</p>
            </div>
            <div class="modal-footer" style="padding: 15px; text-align: right;">
                <button class="btn btn-secondary" onclick="closeModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu
        const mobileBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        if (mobileBtn) mobileBtn.onclick = () => sidebar.classList.toggle('active');

        // Charts
        <?php if (!empty($results)): ?>
            // Grade Distribution Chart
            const gradeCtx = document.getElementById('gradeChart').getContext('2d');
            new Chart(gradeCtx, {
                type: 'bar',
                data: {
                    labels: ['A', 'B', 'C', 'D', 'F'],
                    datasets: [{
                        label: 'Number of Students',
                        data: [<?php echo $grade_counts['A']; ?>, <?php echo $grade_counts['B']; ?>, <?php echo $grade_counts['C']; ?>, <?php echo $grade_counts['D']; ?>, <?php echo $grade_counts['F']; ?>],
                        backgroundColor: ['#27ae60', '#2ecc71', '#f39c12', '#e67e22', '#e74c3c'],
                        borderRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'top'
                        }
                    }
                }
            });

            // Pass/Fail Chart
            const pfCtx = document.getElementById('passFailChart').getContext('2d');
            new Chart(pfCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Pass (≥50%)', 'Fail (<50%)'],
                    datasets: [{
                        data: [<?php echo $pass_count; ?>, <?php echo $fail_count; ?>],
                        backgroundColor: ['#27ae60', '#e74c3c']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        <?php endif; ?>

        function viewResultDetails(resultId) {
            fetch(`get-result-details.php?id=${resultId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        let html = `
                            <table style="width:100%; border-collapse:collapse;">
                                <tr><td style="padding:8px; border-bottom:1px solid #eee;"><strong>Student:</strong></td><td style="padding:8px; border-bottom:1px solid #eee;">${data.student_name}</td></tr>
                                <tr><td style="padding:8px; border-bottom:1px solid #eee;"><strong>Admission No:</strong></td><td style="padding:8px; border-bottom:1px solid #eee;">${data.admission_number}</td></tr>
                                <tr><td style="padding:8px; border-bottom:1px solid #eee;"><strong>Exam:</strong></td><td style="padding:8px; border-bottom:1px solid #eee;">${data.exam_name}</td></tr>
                                <tr><td style="padding:8px; border-bottom:1px solid #eee;"><strong>Score:</strong></td><td style="padding:8px; border-bottom:1px solid #eee;">${data.total_score} / ${data.total_questions}</td></tr>
                                <tr><td style="padding:8px; border-bottom:1px solid #eee;"><strong>Percentage:</strong></td><td style="padding:8px; border-bottom:1px solid #eee;">${data.percentage}%</td></tr>
                                <tr><td style="padding:8px; border-bottom:1px solid #eee;"><strong>Grade:</strong></td><td style="padding:8px; border-bottom:1px solid #eee;"><span class="grade-${data.grade}">${data.grade}</span></td></tr>
                                <tr><td style="padding:8px; border-bottom:1px solid #eee;"><strong>Time Taken:</strong></td><td style="padding:8px; border-bottom:1px solid #eee;">${data.time_taken ? data.time_taken + ' sec' : 'N/A'}</td></tr>
                                <tr><td style="padding:8px;"><strong>Submitted:</strong></td><td style="padding:8px;">${new Date(data.submitted_at).toLocaleString()}</td></tr>
                            </table>
                        `;
                        document.getElementById('resultDetails').innerHTML = html;
                    } else {
                        document.getElementById('resultDetails').innerHTML = '<p>Error loading details</p>';
                    }
                })
                .catch(err => {
                    document.getElementById('resultDetails').innerHTML = '<p>Error loading details</p>';
                });
            document.getElementById('resultModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('resultModal').classList.remove('active');
        }

        function exportResults() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'csv');
            window.location.href = `export-results.php?${params.toString()}`;
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeModal();
        });
    </script>
</body>

</html>
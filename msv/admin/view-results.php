<?php
// admin/view-results.php - View Exam Results with Card Layout & Modal Actions
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /msv/login.php");
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
$page_title = "Exam Results";

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
$classes_stmt = $pdo->prepare("SELECT DISTINCT class FROM students WHERE school_id = ? AND status = 'active' AND class IS NOT NULL AND class != '' ORDER BY class");
$classes_stmt->execute([$school_id]);
$classes = $classes_stmt->fetchAll();

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

// Include sidebar
require_once 'includes/sidebar.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($school_name); ?> - Exam Results</title>

    <!-- Chart.js for analytics -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --primary-dark: #1a5a8a;
            --secondary-color: #d4af7a;
            --success: #27ae60;
            --success-light: #d5f4e6;
            --warning: #f39c12;
            --warning-light: #fef5e7;
            --danger: #e74c3c;
            --danger-light: #fbe9e7;
            --info: #3498db;
            --info-light: #eaf6ff;
            --purple: #9b59b6;
            --gray-50: #f9fafb;
            --gray-100: #f0f2f5;
            --gray-200: #e4e7eb;
            --gray-300: #d1d5db;
            --gray-600: #6b7280;
            --gray-800: #1f2937;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 16px;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--gray-100);
            color: var(--gray-800);
            min-height: 100vh;
        }

        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        /* Top Header */
        .top-header {
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            box-shadow: var(--shadow-sm);
        }

        .header-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 4px;
        }

        .header-title p {
            color: var(--gray-600);
            font-size: 0.8rem;
        }

        /* Buttons */
        .btn {
            padding: 10px 18px;
            border-radius: var(--radius-md);
            border: none;
            font-weight: 500;
            font-size: 0.8rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.7rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--gray-300);
            color: var(--gray-800);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            padding: 18px;
            border-radius: var(--radius-lg);
            text-align: center;
            box-shadow: var(--shadow-sm);
            border-left: 4px solid;
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-card.success {
            border-left-color: var(--success);
        }

        .stat-card.info {
            border-left-color: var(--info);
        }

        .stat-card.warning {
            border-left-color: var(--warning);
        }

        .stat-card.danger {
            border-left-color: var(--danger);
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--gray-800);
        }

        .stat-label {
            font-size: 0.7rem;
            color: var(--gray-600);
            margin-top: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Filter Bar */
        .filter-bar {
            background: white;
            border-radius: var(--radius-lg);
            padding: 15px 20px;
            margin-bottom: 20px;
        }

        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 140px;
        }

        .filter-group label {
            display: block;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--gray-600);
            margin-bottom: 5px;
        }

        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 0.85rem;
            background: white;
        }

        .filter-actions {
            display: flex;
            gap: 8px;
        }

        /* Charts Container */
        .charts-container {
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-bottom: 24px;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }

        .chart-card {
            background: var(--gray-50);
            border-radius: var(--radius-md);
            padding: 16px;
        }

        .chart-card h4 {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--gray-800);
        }

        canvas {
            max-height: 220px;
            width: 100%;
        }

        /* Results Grid - Mobile First */
        .results-grid {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .result-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 18px;
            box-shadow: var(--shadow-sm);
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid var(--gray-200);
        }

        .result-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary-color);
        }

        .result-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }

        .student-name {
            font-size: 1rem;
            font-weight: 700;
            color: var(--gray-800);
            background: var(--info-light);
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-block;
        }

        .student-name i {
            margin-right: 6px;
            color: var(--primary-color);
        }

        .grade-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
        }

        .grade-A {
            background: #27ae60;
            color: white;
        }

        .grade-B {
            background: #2ecc71;
            color: white;
        }

        .grade-C {
            background: #f39c12;
            color: white;
        }

        .grade-D {
            background: #e67e22;
            color: white;
        }

        .grade-F {
            background: #e74c3c;
            color: white;
        }

        .result-details {
            margin: 12px 0;
        }

        .result-detail-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-right: 16px;
            margin-bottom: 8px;
            font-size: 0.75rem;
            color: var(--gray-600);
        }

        .result-detail-item i {
            width: 16px;
            color: var(--primary-color);
        }

        .score-row {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--gray-200);
        }

        .score-item {
            flex: 1;
            text-align: center;
        }

        .score-value {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .score-label {
            font-size: 0.7rem;
            color: var(--gray-600);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 550px;
            max-height: 85vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
        }

        .modal-header h3 {
            font-size: 1.1rem;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--gray-600);
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        /* Info rows in modal */
        .info-row {
            display: flex;
            padding: 12px 0;
            border-bottom: 1px solid var(--gray-200);
        }

        .info-label {
            width: 130px;
            font-weight: 600;
            color: var(--gray-600);
            font-size: 0.8rem;
        }

        .info-value {
            flex: 1;
            color: var(--gray-800);
            font-size: 0.85rem;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: var(--radius-lg);
        }

        .empty-state i {
            font-size: 48px;
            color: var(--gray-300);
            margin-bottom: 16px;
        }

        .empty-state h3 {
            margin-bottom: 8px;
        }

        .loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }

            .top-header {
                flex-direction: column;
                text-align: center;
            }

            .filter-form {
                flex-direction: column;
            }

            .filter-group {
                width: 100%;
            }

            .filter-actions {
                width: 100%;
            }

            .filter-actions .btn {
                flex: 1;
                justify-content: center;
            }

            .charts-grid {
                grid-template-columns: 1fr;
            }

            .info-row {
                flex-direction: column;
            }

            .info-label {
                width: 100%;
                margin-bottom: 5px;
            }

            .score-row {
                flex-direction: column;
                gap: 10px;
            }

            .score-item {
                text-align: left;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
        }
    </style>
</head>

<body>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-chart-bar"></i> Exam Results</h1>
                <p>View and analyze student performance across exams</p>
            </div>
            <?php if (!empty($results)): ?>
                <button class="btn btn-success" onclick="exportResults()">
                    <i class="fas fa-download"></i> Export to CSV
                </button>
            <?php endif; ?>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card success">
                <div class="stat-value"><?php echo $total_results; ?></div>
                <div class="stat-label">Total Results</div>
            </div>
            <div class="stat-card info">
                <div class="stat-value"><?php echo $average_score; ?></div>
                <div class="stat-label">Avg Score</div>
            </div>
            <div class="stat-card success">
                <div class="stat-value"><?php echo $pass_rate; ?>%</div>
                <div class="stat-label">Pass Rate</div>
            </div>
            <div class="stat-card danger">
                <div class="stat-value"><?php echo $fail_count; ?></div>
                <div class="stat-label">Failed</div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-bar">
            <form method="GET" class="filter-form">
                <div class="filter-group">
                    <label>Exam</label>
                    <select name="exam_id">
                        <option value="">All Exams</option>
                        <?php foreach ($exams as $exam): ?>
                            <option value="<?php echo $exam['id']; ?>" <?php echo $exam_filter == $exam['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($exam['exam_name'] . ' (' . $exam['class'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Class</label>
                    <select name="class">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo htmlspecialchars($class['class']); ?>" <?php echo $class_filter === $class['class'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Search Student</label>
                    <input type="text" name="search" placeholder="Name or Admission No..." value="<?php echo htmlspecialchars($student_search); ?>">
                </div>
                <div class="filter-group">
                    <label>Grade</label>
                    <select name="grade">
                        <option value="">All Grades</option>
                        <option value="A" <?php echo $grade_filter === 'A' ? 'selected' : ''; ?>>A (70-100%)</option>
                        <option value="B" <?php echo $grade_filter === 'B' ? 'selected' : ''; ?>>B (60-69%)</option>
                        <option value="C" <?php echo $grade_filter === 'C' ? 'selected' : ''; ?>>C (50-59%)</option>
                        <option value="D" <?php echo $grade_filter === 'D' ? 'selected' : ''; ?>>D (45-49%)</option>
                        <option value="F" <?php echo $grade_filter === 'F' ? 'selected' : ''; ?>>F (0-44%)</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Date From</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="filter-group">
                    <label>Date To</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
                    <a href="view-results.php" class="btn btn-outline"><i class="fas fa-times"></i> Reset</a>
                </div>
            </form>
        </div>

        <!-- Charts Section -->
        <?php if (!empty($results)): ?>
            <div class="charts-container">
                <div class="charts-grid">
                    <div class="chart-card">
                        <h4><i class="fas fa-chart-bar"></i> Grade Distribution</h4>
                        <canvas id="gradeChart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h4><i class="fas fa-chart-pie"></i> Pass vs Fail</h4>
                        <canvas id="passFailChart"></canvas>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Results Grid -->
        <?php if (empty($results)): ?>
            <div class="empty-state">
                <i class="fas fa-chart-bar"></i>
                <h3>No Results Found</h3>
                <p>Try adjusting your filters or wait for students to take exams.</p>
            </div>
        <?php else: ?>
            <div class="results-grid" id="resultsGrid">
                <?php foreach ($results as $result):
                    $percentage = $result['percentage'] ?? 0;
                    $grade = $result['grade'] ?? 'F';
                    $total_score = $result['total_score'] ?? 0;
                    $total_questions = ($result['objective_count'] ?? 0) + ($result['subjective_count'] ?? 0) + ($result['theory_count'] ?? 0);
                ?>
                    <div class="result-card" data-result-id="<?php echo $result['id']; ?>" data-student-name="<?php echo htmlspecialchars($result['student_name']); ?>">
                        <div class="result-card-header">
                            <span class="student-name">
                                <i class="fas fa-user-graduate"></i> <?php echo htmlspecialchars($result['student_name']); ?>
                            </span>
                            <span class="grade-badge grade-<?php echo $grade; ?>">Grade: <?php echo $grade; ?></span>
                        </div>
                        <div class="result-details">
                            <span class="result-detail-item"><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($result['admission_number']); ?></span>
                            <span class="result-detail-item"><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($result['student_class']); ?></span>
                            <span class="result-detail-item"><i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($result['exam_name']); ?></span>
                            <span class="result-detail-item"><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($result['submitted_at'])); ?></span>
                        </div>
                        <div class="score-row">
                            <div class="score-item">
                                <div class="score-value"><?php echo $total_score; ?> / <?php echo $total_questions ?: '?'; ?></div>
                                <div class="score-label">Total Score</div>
                            </div>
                            <div class="score-item">
                                <div class="score-value"><?php echo number_format($percentage, 1); ?>%</div>
                                <div class="score-label">Percentage</div>
                            </div>
                            <div class="score-item">
                                <div class="score-value"><?php echo $grade; ?></div>
                                <div class="score-label">Grade</div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Export Hint -->
            <div style="text-align: center; margin-top: 20px; font-size: 0.7rem; color: var(--gray-600);">
                <i class="fas fa-chart-line"></i> Click on any result card to view detailed breakdown
            </div>
        <?php endif; ?>
    </div>

    <!-- Result Details Modal -->
    <div class="modal" id="resultModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Result Details</h3>
                <button class="close-modal" onclick="closeModal('resultModal')">&times;</button>
            </div>
            <div class="modal-body" id="resultDetailsBody">
                <div style="text-align: center; padding: 40px;">
                    <div class="loading"></div>
                    <p>Loading details...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('resultModal')">Close</button>
            </div>
        </div>
    </div>

    <script>
        // Chart data
        const gradeData = [<?php echo $grade_counts['A']; ?>, <?php echo $grade_counts['B']; ?>, <?php echo $grade_counts['C']; ?>, <?php echo $grade_counts['D']; ?>, <?php echo $grade_counts['F']; ?>];
        const gradeLabels = ['A (70-100%)', 'B (60-69%)', 'C (50-59%)', 'D (45-49%)', 'F (0-44%)'];
        const gradeColors = ['#27ae60', '#2ecc71', '#f39c12', '#e67e22', '#e74c3c'];

        <?php if (!empty($results)): ?>
            // Grade Distribution Chart
            const gradeCtx = document.getElementById('gradeChart')?.getContext('2d');
            if (gradeCtx) {
                new Chart(gradeCtx, {
                    type: 'bar',
                    data: {
                        labels: gradeLabels,
                        datasets: [{
                            label: 'Number of Students',
                            data: gradeData,
                            backgroundColor: gradeColors,
                            borderRadius: 8,
                            barPercentage: 0.7
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                position: 'top'
                            },
                            tooltip: {
                                callbacks: {
                                    label: (ctx) => `${ctx.raw} student(s)`
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                stepSize: 1,
                                title: {
                                    display: true,
                                    text: 'Students'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Grade'
                                }
                            }
                        }
                    }
                });
            }

            // Pass/Fail Chart
            const pfCtx = document.getElementById('passFailChart')?.getContext('2d');
            if (pfCtx) {
                new Chart(pfCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Pass (≥50%)', 'Fail (<50%)'],
                        datasets: [{
                            data: [<?php echo $pass_count; ?>, <?php echo $fail_count; ?>],
                            backgroundColor: ['#27ae60', '#e74c3c'],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            },
                            tooltip: {
                                callbacks: {
                                    label: (ctx) => `${ctx.raw} student(s) (${((ctx.raw / <?php echo $total_results; ?>) * 100).toFixed(1)}%)`
                                }
                            }
                        }
                    }
                });
            }
        <?php endif; ?>

        // Result card click handler - fetch details via AJAX
        document.querySelectorAll('.result-card').forEach(card => {
            card.addEventListener('click', function(e) {
                if (e.target.closest('.btn')) return;
                const resultId = this.getAttribute('data-result-id');
                const studentName = this.getAttribute('data-student-name');
                viewResultDetails(resultId, studentName);
            });
        });

        function viewResultDetails(resultId, studentName) {
            const modal = document.getElementById('resultModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalBody = document.getElementById('resultDetailsBody');

            modalTitle.innerHTML = `<i class="fas fa-user-graduate"></i> ${escapeHtml(studentName)} - Result Details`;
            modalBody.innerHTML = '<div style="text-align: center; padding: 40px;"><div class="loading"></div><p>Loading details...</p></div>';
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';

            fetch(`get-result-details.php?id=${resultId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const gradeClass = `grade-${data.grade}`;
                        modalBody.innerHTML = `
                            <div class="info-row">
                                <div class="info-label">Student Name:</div>
                                <div class="info-value"><strong>${escapeHtml(data.student_name)}</strong></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Admission No:</div>
                                <div class="info-value">${escapeHtml(data.admission_number)}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Class:</div>
                                <div class="info-value">${escapeHtml(data.student_class)}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Exam:</div>
                                <div class="info-value">${escapeHtml(data.exam_name)}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Exam Type:</div>
                                <div class="info-value">${escapeHtml(data.exam_type || 'Objective')}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Score:</div>
                                <div class="info-value"><strong>${data.total_score} / ${data.total_questions}</strong></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Percentage:</div>
                                <div class="info-value">${data.percentage}%</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Grade:</div>
                                <div class="info-value"><span class="grade-badge ${gradeClass}">${data.grade}</span></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Time Taken:</div>
                                <div class="info-value">${data.time_taken ? data.time_taken + ' seconds' : 'N/A'}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Submitted:</div>
                                <div class="info-value">${new Date(data.submitted_at).toLocaleString()}</div>
                            </div>
                            ${data.objective_breakdown ? `
                            <div class="info-row">
                                <div class="info-label">Objective:</div>
                                <div class="info-value">${data.objective_correct}/${data.objective_total} correct (${data.objective_percentage}%)</div>
                            </div>
                            ` : ''}
                            ${data.subjective_breakdown ? `
                            <div class="info-row">
                                <div class="info-label">Subjective:</div>
                                <div class="info-value">${data.subjective_score}/${data.subjective_total} marks</div>
                            </div>
                            ` : ''}
                        `;
                    } else {
                        modalBody.innerHTML = `<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> ${escapeHtml(data.error || 'Failed to load details')}</div>`;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    modalBody.innerHTML = `<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Failed to load result details. Please try again.</div>`;
                });
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = '';
        }

        function exportResults() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'csv');
            window.location.href = `export-results.php?${params.toString()}`;
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close modals on outside click
        window.onclick = function(event) {
            if (event.target.classList && event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
                document.body.style.overflow = '';
            }
        };

        // Close modal on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal').forEach(modal => {
                    modal.classList.remove('active');
                });
                document.body.style.overflow = '';
            }
        });
    </script>
</body>

</html>
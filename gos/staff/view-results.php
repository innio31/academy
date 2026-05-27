<?php
// gos/staff/view-results.php - Staff View Results
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: /gos/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$staff_id = $_SESSION['user_id'];
$staff_name = $_SESSION['user_name'] ?? 'Staff Member';
$staff_role = $_SESSION['staff_role'] ?? 'staff';
$staff_id_string = $_SESSION['staff_id'] ?? $staff_id;

// Initialize variables
$classes = [];
$exams = [];
$results = [];
$selected_class = '';
$selected_exam = '';
$error = null;

try {
    // Get the staff_id string from the staff table
    $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
    $stmt->execute([$staff_id, $school_id]);
    $staff_id_string_db = $stmt->fetchColumn();

    if (!$staff_id_string_db) {
        $error = "Staff record not found. Please contact administrator.";
    } else {
        $staff_id_string = $staff_id_string_db;

        // Get assigned classes using the string staff_id
        $stmt = $pdo->prepare("SELECT class FROM staff_classes WHERE staff_id = ? AND school_id = ? ORDER BY class");
        $stmt->execute([$staff_id_string, $school_id]);
        $classes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) {
    error_log("Staff data fetch error: " . $e->getMessage());
    $error = "An error occurred while loading your data.";
}

// Get filters
$selected_class = $_GET['class'] ?? ($classes[0] ?? '');
$selected_exam = $_GET['exam'] ?? '';

// Get exams for this staff's classes
if (!empty($classes)) {
    try {
        $placeholders = str_repeat('?,', count($classes) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT id, exam_name, class, exam_type, subject_id,
                   (SELECT subject_name FROM subjects WHERE id = exams.subject_id) as subject_name
            FROM exams 
            WHERE school_id = ? AND class IN ($placeholders)
            ORDER BY created_at DESC
        ");
        $stmt->execute(array_merge([$school_id], $classes));
        $exams = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Exams fetch error: " . $e->getMessage());
        $exams = [];
    }
}

// Get results
if ($selected_class && !empty($selected_exam)) {
    try {
        $stmt = $pdo->prepare("
            SELECT r.*, s.full_name, s.admission_number, s.id as student_id
            FROM results r
            JOIN students s ON r.student_id = s.id
            WHERE r.school_id = ? AND r.exam_id = ? AND s.class = ?
            ORDER BY r.percentage DESC
        ");
        $stmt->execute([$school_id, $selected_exam, $selected_class]);
        $results = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Results fetch error: " . $e->getMessage());
        $results = [];
    }
}

// Calculate statistics
$stats = [];
if (!empty($results)) {
    $scores = array_column($results, 'percentage');
    $stats['total_students'] = count($results);
    $stats['average'] = round(array_sum($scores) / count($scores), 1);
    $stats['highest'] = round(max($scores), 1);
    $stats['lowest'] = round(min($scores), 1);

    // Grade distribution
    $stats['grades'] = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
    foreach ($results as $result) {
        $grade = $result['grade'] ?? 'F';
        if (isset($stats['grades'][$grade])) {
            $stats['grades'][$grade]++;
        } else {
            $stats['grades']['F']++;
        }
    }
}

// Get exam details for display
$exam_details = null;
if ($selected_exam) {
    foreach ($exams as $exam) {
        if ($exam['id'] == $selected_exam) {
            $exam_details = $exam;
            break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - View Results</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --primary-dark: #1a5a8a;
            --secondary-color: #d4af7a;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #3498db;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --gray-50: #f9fafb;
            --gray-100: #f0f2f5;
            --gray-200: #e4e7eb;
            --gray-400: #9ca3af;
            --gray-600: #6b7280;
            --gray-800: #1f2937;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
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
            margin-left: 0;
            padding: 20px;
            min-height: 100vh;
            transition: margin-left 0.28s ease;
        }

        /* Top Header */
        .top-header {
            background: white;
            padding: 20px 25px;
            border-radius: var(--radius-lg);
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: var(--shadow-sm);
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.6rem;
            margin-bottom: 5px;
            font-weight: 700;
        }

        .header-title h1 i {
            margin-right: 10px;
        }

        .header-title p {
            color: var(--gray-600);
            font-size: 0.85rem;
        }

        .header-title p i {
            color: var(--primary-color);
            font-size: 0.7rem;
            margin: 0 4px;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--gray-200);
        }

        .card-header h3 {
            color: var(--gray-800);
            font-size: 1.1rem;
            font-weight: 600;
        }

        .card-header h3 i {
            color: var(--primary-color);
            margin-right: 8px;
        }

        /* Filter Bar */
        .filter-bar {
            background: white;
            padding: 20px;
            border-radius: var(--radius-lg);
            margin-bottom: 25px;
            box-shadow: var(--shadow-sm);
        }

        .filter-bar form {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-600);
            margin-bottom: 6px;
        }

        .form-control,
        .form-select {
            padding: 10px 14px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            font-family: inherit;
            min-width: 220px;
            transition: all 0.2s;
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-box {
            background: var(--gray-50);
            padding: 15px;
            border-radius: var(--radius-md);
            text-align: center;
            border: 1px solid var(--gray-200);
        }

        .stat-value {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .stat-label {
            font-size: 0.7rem;
            color: var(--gray-600);
            margin-top: 5px;
        }

        /* Grade Distribution */
        .grade-distribution {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .grade-item {
            text-align: center;
            min-width: 60px;
            padding: 10px;
            border-radius: var(--radius-md);
        }

        .grade-letter {
            font-size: 1.3rem;
            font-weight: 700;
        }

        .grade-count {
            font-size: 0.7rem;
            color: var(--gray-600);
        }

        .grade-A {
            background: #d5f4e6;
            color: #27ae60;
        }

        .grade-B {
            background: #d1f2eb;
            color: #2ecc71;
        }

        .grade-C {
            background: #fef5e7;
            color: #f39c12;
        }

        .grade-D {
            background: #fdebd0;
            color: #e67e22;
        }

        .grade-F {
            background: #f8d7da;
            color: #e74c3c;
        }

        /* Table */
        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        .data-table th {
            text-align: left;
            padding: 14px 16px;
            background: var(--gray-50);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-600);
            border-bottom: 2px solid var(--gray-200);
        }

        .data-table td {
            padding: 14px 16px;
            font-size: 0.85rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .data-table tr:hover td {
            background: var(--gray-50);
        }

        /* Make student name bigger */
        .data-table td strong {
            font-size: 0.9rem;
            font-weight: 600;
        }

        /* Grade styling in table */
        .grade-cell {
            font-weight: 700;
        }

        .grade-A {
            color: #27ae60;
        }

        .grade-B {
            color: #2ecc71;
        }

        .grade-C {
            color: #f39c12;
        }

        .grade-D {
            color: #e67e22;
        }

        .grade-F {
            color: #e74c3c;
        }

        /* Error Message */
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px 20px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-left: 4px solid var(--danger-color);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: var(--gray-600);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: var(--gray-400);
        }

        .empty-state p {
            margin-top: 8px;
        }

        /* Info Item */
        .info-item {
            background: var(--gray-100);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            color: var(--gray-800);
            font-weight: 500;
        }

        .info-item i {
            margin-right: 6px;
            color: var(--primary-color);
        }

        /* Responsive */
        @media (min-width: 768px) {
            .main-content {
                margin-left: 280px;
            }
        }

        @media (max-width: 767px) {
            .main-content {
                padding-top: 70px;
            }

            .filter-bar form {
                flex-direction: column;
                width: 100%;
            }

            .form-control,
            .form-select {
                width: 100%;
            }

            .form-group {
                width: 100%;
            }

            .top-header {
                flex-direction: column;
                text-align: center;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Include Staff Sidebar -->
    <?php include_once 'includes/staff_sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-chart-bar"></i> View Results</h1>
                <p><i class="fas fa-chevron-right"></i> Review and analyze student examination performance</p>
            </div>
            <div>
                <span class="info-item"><i class="fas fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></span>
            </div>
        </div>

        <?php if (isset($error)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if (empty($classes)): ?>
            <div class="card">
                <div class="empty-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>You have not been assigned to any class.</p>
                    <p>Please contact the administrator to assign you to classes.</p>
                </div>
            </div>
        <?php else: ?>
            <!-- Filter Bar -->
            <div class="filter-bar">
                <form method="GET">
                    <div class="form-group">
                        <label><i class="fas fa-layer-group"></i> Select Class</label>
                        <select name="class" class="form-select" onchange="this.form.submit()">
                            <option value="">-- Select Class --</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo htmlspecialchars($class); ?>" <?php echo $selected_class == $class ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-file-alt"></i> Select Exam</label>
                        <select name="exam" class="form-select" onchange="this.form.submit()">
                            <option value="">-- Select Exam --</option>
                            <?php foreach ($exams as $exam): ?>
                                <?php if ($exam['class'] == $selected_class): ?>
                                    <option value="<?php echo $exam['id']; ?>" <?php echo $selected_exam == $exam['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($exam['exam_name']); ?>
                                        (<?php echo htmlspecialchars($exam['subject_name'] ?? 'General'); ?>)
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>

            <!-- Results Display -->
            <?php if ($selected_class && $selected_exam && !empty($results)): ?>
                <!-- Statistics Overview -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-line"></i> Performance Overview</h3>
                        <?php if ($exam_details): ?>
                            <span class="info-item">
                                <i class="fas fa-book"></i> <?php echo htmlspecialchars($exam_details['subject_name'] ?? 'N/A'); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="stats-grid">
                        <div class="stat-box">
                            <div class="stat-value"><?php echo $stats['total_students']; ?></div>
                            <div class="stat-label">Total Students</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-value"><?php echo $stats['average']; ?>%</div>
                            <div class="stat-label">Average Score</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-value"><?php echo $stats['highest']; ?>%</div>
                            <div class="stat-label">Highest Score</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-value"><?php echo $stats['lowest']; ?>%</div>
                            <div class="stat-label">Lowest Score</div>
                        </div>
                    </div>

                    <!-- Grade Distribution -->
                    <div style="margin-top: 15px;">
                        <div class="grade-distribution">
                            <?php foreach ($stats['grades'] as $grade => $count): ?>
                                <div class="grade-item grade-<?php echo $grade; ?>">
                                    <div class="grade-letter"><?php echo $grade; ?></div>
                                    <div class="grade-count"><?php echo $count; ?> students</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Results Table -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-list"></i> Student Results</h3>
                        <button onclick="window.print()" class="btn" style="background: var(--gray-200); color: var(--gray-800);">
                            <i class="fas fa-print"></i> Print Results
                        </button>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Admission No</th>
                                    <th>Student Name</th>
                                    <th>Score</th>
                                    <th>Percentage</th>
                                    <th>Grade</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $sn = 1;
                                foreach ($results as $result):
                                    $percentage = $result['percentage'] ?? 0;
                                    $grade = $result['grade'] ?? 'F';
                                    $grade_class = 'grade-' . $grade;
                                ?>
                                    <tr>
                                        <td><?php echo $sn++; ?></td>
                                        <td><code><?php echo htmlspecialchars($result['admission_number']); ?></code></td>
                                        <td><strong><?php echo htmlspecialchars($result['full_name']); ?></strong></td>
                                        <td><?php echo $result['total_score']; ?> / <?php echo $result['total_questions']; ?></td>
                                        <td><?php echo number_format($percentage, 1); ?>%</td>
                                        <td class="grade-cell <?php echo $grade_class; ?>"><?php echo $grade; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php elseif ($selected_class && $selected_exam): ?>
                <div class="card">
                    <div class="empty-state">
                        <i class="fas fa-folder-open"></i>
                        <p>No results found for this exam.</p>
                        <p>Results may not have been published yet.</p>
                    </div>
                </div>
            <?php elseif ($selected_class): ?>
                <div class="card">
                    <div class="empty-state">
                        <i class="fas fa-info-circle"></i>
                        <p>Select an exam from the dropdown above to view results.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="empty-state">
                        <i class="fas fa-info-circle"></i>
                        <p>Select a class from the dropdown above to view results.</p>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        // Mobile menu toggle is handled in staff_sidebar.php
        document.addEventListener('DOMContentLoaded', function() {
            // Any page-specific initialization
        });
    </script>
</body>

</html>
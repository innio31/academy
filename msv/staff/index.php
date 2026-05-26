<?php
// msv/staff/index.php - Staff Dashboard with modern sidebar
session_start();

// Check if staff is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: /msv/login.php");
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

// Initialize variables with default values
$error = null;
$assigned_subjects = [];
$assigned_classes = [];
$class_names = [];
$total_students = 0;
$total_exams = 0;
$pending_grading = 0;
$recent_activities = [];
$upcoming_deadlines = [];
$recent_results = [];

try {
    // Get the staff_id string from the staff table (this is what's stored in staff_subjects/staff_classes)
    $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
    $stmt->execute([$staff_id, $school_id]);
    $staff_id_string_db = $stmt->fetchColumn();

    if (!$staff_id_string_db) {
        $error = "Staff record not found. Please contact administrator.";
    } else {
        $staff_id_string = $staff_id_string_db;

        // Get assigned subjects using the string staff_id (removed subject_code)
        $stmt = $pdo->prepare("
            SELECT s.id, s.subject_name
            FROM subjects s
            JOIN staff_subjects ss ON s.id = ss.subject_id
            WHERE ss.staff_id = ? AND ss.school_id = ?
            ORDER BY s.subject_name
        ");
        $stmt->execute([$staff_id_string, $school_id]);
        $assigned_subjects = $stmt->fetchAll();

        // Get assigned classes using the string staff_id
        $stmt = $pdo->prepare("
            SELECT DISTINCT class 
            FROM staff_classes 
            WHERE staff_id = ? AND school_id = ?
            ORDER BY class
        ");
        $stmt->execute([$staff_id_string, $school_id]);
        $assigned_classes = $stmt->fetchAll();
        $class_names = array_column($assigned_classes, 'class');

        // Total Students in assigned classes
        if (!empty($class_names)) {
            $placeholders = str_repeat('?,', count($class_names) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total 
                FROM students 
                WHERE school_id = ? AND status = 'active' 
                AND class IN ($placeholders)
            ");
            $stmt->execute(array_merge([$school_id], $class_names));
            $total_students = $stmt->fetch()['total'];
        }

        // Total Exams
        if (!empty($assigned_subjects) && !empty($class_names)) {
            $subject_ids = array_column($assigned_subjects, 'id');
            $subject_placeholders = str_repeat('?,', count($subject_ids) - 1) . '?';
            $class_placeholders = str_repeat('?,', count($class_names) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total 
                FROM exams 
                WHERE school_id = ? 
                AND subject_id IN ($subject_placeholders)
                AND class IN ($class_placeholders)
            ");
            $stmt->execute(array_merge([$school_id], $subject_ids, $class_names));
            $total_exams = $stmt->fetch()['total'];
        }

        // Pending grading
        try {
            // Check if assignment_submissions table exists
            $stmt = $pdo->query("SHOW TABLES LIKE 'assignment_submissions'");
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as total 
                    FROM assignment_submissions 
                    WHERE status = 'submitted' AND school_id = ?
                    AND assignment_id IN (SELECT id FROM assignments WHERE school_id = ? AND staff_id = ?)
                ");
                $stmt->execute([$school_id, $school_id, $staff_id_string]);
                $pending_grading = $stmt->fetch()['total'];
            } else {
                $pending_grading = 0;
            }
        } catch (Exception $e) {
            $pending_grading = 0;
            error_log("Pending grading query error: " . $e->getMessage());
        }

        // Recent activities
        try {
            // Check if activity_logs table exists
            $stmt = $pdo->query("SHOW TABLES LIKE 'activity_logs'");
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->prepare("
                    SELECT activity, created_at 
                    FROM activity_logs 
                    WHERE user_id = ? AND user_type = 'staff' AND school_id = ?
                    ORDER BY created_at DESC 
                    LIMIT 10
                ");
                $stmt->execute([$staff_id, $school_id]);
                $recent_activities = $stmt->fetchAll();
            } else {
                $recent_activities = [];
            }
        } catch (Exception $e) {
            $recent_activities = [];
            error_log("Recent activities query error: " . $e->getMessage());
        }

        // Upcoming deadlines
        try {
            // Check if assignments table exists
            $stmt = $pdo->query("SHOW TABLES LIKE 'assignments'");
            if ($stmt->rowCount() > 0) {
                // Check if deadline column exists
                $stmt = $pdo->query("SHOW COLUMNS FROM assignments LIKE 'deadline'");
                if ($stmt->rowCount() > 0) {
                    $stmt = $pdo->prepare("
                        SELECT a.*, s.subject_name,
                               DATEDIFF(a.deadline, NOW()) as days_left
                        FROM assignments a
                        LEFT JOIN subjects s ON a.subject_id = s.id
                        WHERE a.school_id = ? AND a.staff_id = ?
                        AND a.deadline > NOW()
                        ORDER BY a.deadline ASC
                        LIMIT 5
                    ");
                    $stmt->execute([$school_id, $staff_id_string]);
                    $upcoming_deadlines = $stmt->fetchAll();
                } else {
                    $upcoming_deadlines = [];
                }
            } else {
                $upcoming_deadlines = [];
            }
        } catch (Exception $e) {
            $upcoming_deadlines = [];
            error_log("Upcoming deadlines query error: " . $e->getMessage());
        }

        // Recent results from students in assigned classes
        if (!empty($class_names)) {
            try {
                // Check if results table exists
                $stmt = $pdo->query("SHOW TABLES LIKE 'results'");
                if ($stmt->rowCount() > 0) {
                    $placeholders = str_repeat('?,', count($class_names) - 1) . '?';
                    $stmt = $pdo->prepare("
                        SELECT r.*, stu.full_name as student_name, e.exam_name, stu.class,
                               r.percentage, r.grade, r.submitted_at
                        FROM results r 
                        JOIN students stu ON r.student_id = stu.id 
                        JOIN exams e ON r.exam_id = e.id 
                        WHERE stu.school_id = ? AND stu.class IN ($placeholders)
                        ORDER BY r.submitted_at DESC 
                        LIMIT 5
                    ");
                    $stmt->execute(array_merge([$school_id], $class_names));
                    $recent_results = $stmt->fetchAll();
                } else {
                    $recent_results = [];
                }
            } catch (Exception $e) {
                $recent_results = [];
                error_log("Recent results query error: " . $e->getMessage());
            }
        }
    }
} catch (Exception $e) {
    error_log("Staff dashboard error: " . $e->getMessage());
    $error = "An error occurred while loading the dashboard: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Staff Dashboard</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

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

        .header-title p {
            color: var(--gray-600);
            font-size: 0.85rem;
        }

        .header-title p i {
            color: var(--primary-color);
            font-size: 0.7rem;
            margin: 0 4px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: var(--radius-lg);
            border-top: 4px solid;
            box-shadow: var(--shadow-sm);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .stat-card.students {
            border-top-color: var(--info-color);
        }

        .stat-card.exams {
            border-top-color: var(--warning-color);
        }

        .stat-card.grading {
            border-top-color: var(--danger-color);
        }

        .stat-value {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--gray-800);
        }

        .stat-label {
            color: var(--gray-600);
            font-size: 0.85rem;
            margin-top: 5px;
        }

        .stat-icon {
            float: right;
            font-size: 2.5rem;
            opacity: 0.15;
            color: var(--primary-color);
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .content-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px;
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
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

        /* Info Items */
        .info-items {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }

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

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .action-btn {
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-md);
            padding: 15px;
            text-align: center;
            text-decoration: none;
            color: var(--gray-800);
            transition: all 0.3s ease;
            display: block;
        }

        .action-btn:hover {
            transform: translateY(-3px);
            border-color: var(--primary-color);
            box-shadow: var(--shadow-md);
        }

        .action-icon {
            font-size: 28px;
            margin-bottom: 8px;
            color: var(--primary-color);
        }

        .action-text {
            font-size: 0.85rem;
            font-weight: 500;
        }

        /* Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 12px 10px;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
            font-size: 0.85rem;
        }

        .data-table th {
            background: var(--gray-50);
            font-weight: 600;
            color: var(--gray-600);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .data-table tr:hover td {
            background: var(--gray-50);
        }

        /* Buttons */
        .btn {
            padding: 8px 16px;
            border-radius: var(--radius-md);
            border: none;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8rem;
            transition: all 0.2s;
        }

        .btn-sm {
            padding: 5px 12px;
            font-size: 0.75rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--gray-200);
            color: var(--gray-800);
        }

        .btn-outline:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
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
        }

        /* Footer */
        .footer {
            text-align: center;
            padding: 20px;
            color: var(--gray-600);
            font-size: 0.75rem;
            border-top: 1px solid var(--gray-200);
            margin-top: 20px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 30px;
            color: var(--gray-600);
        }

        .empty-state i {
            font-size: 40px;
            margin-bottom: 10px;
            color: var(--gray-400);
        }

        /* Days Left Badge */
        .days-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .days-badge.urgent {
            background: #fef5e7;
            color: var(--warning-color);
        }

        .days-badge.critical {
            background: #fbe9e7;
            color: var(--danger-color);
        }

        .days-badge.normal {
            background: #d5f4e6;
            color: var(--success-color);
        }

        /* Grade Badge */
        .grade-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 700;
            background: var(--gray-100);
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

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .content-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions {
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
                <h1>Staff Dashboard</h1>
                <p><i class="fas fa-chevron-right"></i> Welcome back, <?php echo htmlspecialchars($staff_name); ?>!</p>
            </div>
            <div>
                <span class="info-item"><i class="fas fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></span>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Assigned Info Card -->
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-chalkboard"></i> My Assignments</h3>
            </div>
            <div class="info-items">
                <?php if (!empty($assigned_subjects)): ?>
                    <?php foreach ($assigned_subjects as $subject): ?>
                        <span class="info-item"><i class="fas fa-book"></i> <?php echo htmlspecialchars($subject['subject_name']); ?></span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="info-item"><i class="fas fa-info-circle"></i> No subjects assigned yet</span>
                <?php endif; ?>
                <?php if (!empty($assigned_classes)): ?>
                    <?php foreach ($assigned_classes as $class): ?>
                        <span class="info-item"><i class="fas fa-users"></i> <?php echo htmlspecialchars($class['class']); ?></span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="info-item"><i class="fas fa-info-circle"></i> No classes assigned yet</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card students">
                <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
                <div class="stat-value"><?php echo $total_students; ?></div>
                <div class="stat-label">My Students</div>
            </div>
            <div class="stat-card exams">
                <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                <div class="stat-value"><?php echo $total_exams; ?></div>
                <div class="stat-label">My Exams</div>
            </div>
            <div class="stat-card grading">
                <div class="stat-icon"><i class="fas fa-check-double"></i></div>
                <div class="stat-value"><?php echo $pending_grading; ?></div>
                <div class="stat-label">Pending Grading</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
            </div>
            <div class="quick-actions">
                <a href="manage-exams.php" class="action-btn">
                    <div class="action-icon"><i class="fas fa-plus-circle"></i></div>
                    <div class="action-text">Create Exam</div>
                </a>
                <a href="assignments.php" class="action-btn">
                    <div class="action-icon"><i class="fas fa-tasks"></i></div>
                    <div class="action-text">New Assignment</div>
                </a>
                <a href="attendance.php" class="action-btn">
                    <div class="action-icon"><i class="fas fa-calendar-check"></i></div>
                    <div class="action-text">Take Attendance</div>
                </a>
                <a href="manage-students.php" class="action-btn">
                    <div class="action-icon"><i class="fas fa-users"></i></div>
                    <div class="action-text">View Students</div>
                </a>
            </div>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Upcoming Deadlines -->
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-clock"></i> Upcoming Deadlines</h3>
                    <a href="assignments.php" class="btn btn-outline btn-sm">View All</a>
                </div>
                <?php if (!empty($upcoming_deadlines)): ?>
                    <table class="data-table">
                        <?php foreach ($upcoming_deadlines as $dl): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($dl['title']); ?></strong><br>
                                    <small><?php echo htmlspecialchars($dl['subject_name']); ?></small>
                                </td>
                                <td style="text-align: right">
                                    <?php
                                    $days = $dl['days_left'];
                                    $badge_class = 'normal';
                                    if ($days <= 2) $badge_class = 'critical';
                                    elseif ($days <= 5) $badge_class = 'urgent';
                                    ?>
                                    <span class="days-badge <?php echo $badge_class; ?>">
                                        <?php echo $days; ?> days left
                                    </span>
                                    <br>
                                    <small><?php echo date('M d, Y', strtotime($dl['deadline'])); ?></small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <p>No upcoming deadlines</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Recent Activities -->
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-history"></i> Recent Activities</h3>
                </div>
                <?php if (!empty($recent_activities)): ?>
                    <table class="data-table">
                        <?php foreach (array_slice($recent_activities, 0, 5) as $activity): ?>
                            <tr>
                                <td>
                                    <i class="fas fa-circle" style="font-size: 0.5rem; color: var(--primary-color); margin-right: 8px;"></i>
                                    <?php echo htmlspecialchars($activity['activity']); ?>
                                    <br>
                                    <small><?php echo date('M d, Y h:i A', strtotime($activity['created_at'])); ?></small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>No recent activities</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Results -->
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-chart-bar"></i> Recent Exam Results</h3>
                <a href="view-results.php" class="btn btn-outline btn-sm">View All</a>
            </div>
            <?php if (!empty($recent_results)): ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Exam</th>
                                <th>Score</th>
                                <th>Grade</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_results as $result): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($result['student_name']); ?></strong><br>
                                        <small><?php echo htmlspecialchars($result['class']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($result['exam_name']); ?></td>
                                    <td><?php echo number_format($result['percentage'] ?? 0, 1); ?>%</td>
                                    <td><span class="grade-badge"><?php echo $result['grade'] ?? 'N/A'; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-chart-line"></i>
                    <p>No results available yet</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> - Staff Portal</p>
        </div>
    </div>

    <script>
        // Mobile menu toggle is handled in sidebar.js
        document.addEventListener('DOMContentLoaded', function() {
            // Any additional JS can go here
        });
    </script>
</body>

</html>
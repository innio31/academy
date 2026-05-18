<?php
// gos/staff/index.php - Staff Dashboard
session_start();

// Check if staff is logged in
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
    $staff_id_string = $stmt->fetchColumn();

    if (!$staff_id_string) {
        $error = "Staff record not found. Please contact administrator.";
    } else {
        // Get assigned subjects using the string staff_id
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

        // Pending grading - assignments that need to be graded
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM assignment_submissions 
            WHERE status = 'submitted' AND school_id = ?
            AND assignment_id IN (SELECT id FROM assignments WHERE school_id = ? AND staff_id = ?)
        ");
        $stmt->execute([$school_id, $school_id, $staff_id_string]);
        $pending_grading = $stmt->fetch()['total'];

        // Recent activities
        $stmt = $pdo->prepare("
            SELECT activity, created_at 
            FROM activity_logs 
            WHERE user_id = ? AND user_type = 'staff' AND school_id = ?
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        $stmt->execute([$staff_id, $school_id]);
        $recent_activities = $stmt->fetchAll();

        // Upcoming deadlines
        $stmt = $pdo->prepare("
            SELECT a.*, s.subject_name,
                   DATEDIFF(a.deadline, NOW()) as days_left
            FROM assignments a
            JOIN subjects s ON a.subject_id = s.id
            WHERE a.school_id = ? AND a.staff_id = ?
            AND a.deadline > NOW()
            ORDER BY a.deadline ASC
            LIMIT 5
        ");
        $stmt->execute([$school_id, $staff_id_string]);
        $upcoming_deadlines = $stmt->fetchAll();

        // Recent results from students in assigned classes
        if (!empty($class_names)) {
            $placeholders = str_repeat('?,', count($class_names) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT r.*, stu.full_name as student_name, e.exam_name, stu.class
                FROM results r 
                JOIN students stu ON r.student_id = stu.id 
                JOIN exams e ON r.exam_id = e.id 
                WHERE stu.school_id = ? AND stu.class IN ($placeholders)
                ORDER BY r.submitted_at DESC 
                LIMIT 5
            ");
            $stmt->execute(array_merge([$school_id], $class_names));
            $recent_results = $stmt->fetchAll();
        }
    }
} catch (Exception $e) {
    error_log("Staff dashboard error: " . $e->getMessage());
    $error = "An error occurred while loading the dashboard. Please try again later.";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Staff Dashboard</title>
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
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-color), var(--dark-color));
            color: white;
            padding: 20px 0;
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

        .staff-info {
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
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.6rem;
            margin-bottom: 5px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            border-top: 4px solid;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
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
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .stat-label {
            color: #666;
            font-size: 0.85rem;
        }

        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .content-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--light-color);
        }

        .card-header h3 {
            color: var(--primary-color);
            font-size: 1.1rem;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--primary-color);
            color: white;
            font-size: 0.85rem;
        }

        .btn-sm {
            padding: 5px 12px;
            font-size: 0.8rem;
        }

        .btn-success {
            background: var(--success-color);
        }

        .btn-warning {
            background: var(--warning-color);
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .action-btn {
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            text-decoration: none;
            color: var(--primary-color);
            transition: all 0.3s ease;
        }

        .action-btn:hover {
            transform: translateY(-3px);
            border-color: var(--primary-color);
        }

        .action-icon {
            font-size: 24px;
            margin-bottom: 8px;
        }

        .action-text {
            font-size: 0.8rem;
            font-weight: 500;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #eee;
            font-size: 0.85rem;
        }

        .data-table th {
            background: var(--light-color);
            font-weight: 600;
        }

        .info-items {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }

        .info-item {
            background: var(--light-color);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            color: var(--primary-color);
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
        }

        .footer {
            text-align: center;
            padding: 20px;
            color: #666;
            font-size: 0.8rem;
            border-top: 1px solid var(--light-color);
            margin-top: 20px;
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
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>

    <div class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-chalkboard-teacher"></i></div>
            <div class="logo-text">
                <h3><?php echo htmlspecialchars($school_name); ?></h3>
                <p>Staff Portal</p>
            </div>
        </div>
        <div class="staff-info">
            <h4><?php echo htmlspecialchars($staff_name); ?></h4>
            <p>Staff ID: <?php echo htmlspecialchars($staff_id_string ?? $staff_id); ?></p>
        </div>
        <ul class="nav-links">
            <li><a href="index.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-students.php"><i class="fas fa-users"></i> My Students</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="assignments.php"><i class="fas fa-tasks"></i> Assignments</a></li>
            <li><a href="attendance.php"><i class="fas fa-calendar-check"></i> Attendance</a></li>
            <li><a href="profile.php"><i class="fas fa-user-cog"></i> My Profile</a></li>
            <li><a href="/gos/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1>Staff Dashboard</h1>
                <p>Welcome back, <?php echo htmlspecialchars($staff_name); ?>!</p>
            </div>
            <button class="btn" onclick="window.location.href='/gos/logout.php'"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>

        <?php if ($error): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Assigned Info -->
        <div class="content-card">
            <h3><i class="fas fa-book"></i> My Assignments</h3>
            <div class="info-items">
                <?php if (!empty($assigned_subjects)): ?>
                    <?php foreach ($assigned_subjects as $subject): ?>
                        <span class="info-item">📖 <?php echo htmlspecialchars($subject['subject_name']); ?></span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="info-item">No subjects assigned yet</span>
                <?php endif; ?>
                <?php if (!empty($assigned_classes)): ?>
                    <?php foreach ($assigned_classes as $class): ?>
                        <span class="info-item">🏫 <?php echo htmlspecialchars($class['class']); ?></span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="info-item">No classes assigned yet</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card students">
                <div class="stat-value"><?php echo $total_students; ?></div>
                <div class="stat-label">My Students</div>
            </div>
            <div class="stat-card exams">
                <div class="stat-value"><?php echo $total_exams; ?></div>
                <div class="stat-label">My Exams</div>
            </div>
            <div class="stat-card grading">
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
                    <h3><i class="fas fa-clock"></i> Upcoming Deadlines</h3><a href="assignments.php" class="btn btn-sm">View All</a>
                </div>
                <?php if (!empty($upcoming_deadlines)): ?>
                    <table class="data-table">
                        <?php foreach ($upcoming_deadlines as $dl): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($dl['title']); ?></strong><br><small><?php echo htmlspecialchars($dl['subject_name']); ?></small></td>
                                <td style="text-align:right"><?php echo date('M d', strtotime($dl['deadline'])); ?><br><small><?php echo $dl['days_left']; ?> days left</small></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php else: ?>
                    <p style="text-align:center; color:#999;">No upcoming deadlines</p>
                <?php endif; ?>
            </div>

            <!-- Recent Activities -->
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-history"></i> Recent Activities</h3>
                </div>
                <?php if (!empty($recent_activities)): ?>
                    <table class="data-table">
                        <?php foreach ($recent_activities as $activity): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($activity['activity']); ?><br><small><?php echo date('M d, H:i', strtotime($activity['created_at'])); ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php else: ?>
                    <p style="text-align:center; color:#999;">No recent activities</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Results -->
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-chart-bar"></i> Recent Exam Results</h3><a href="view-results.php" class="btn btn-sm">View All</a>
            </div>
            <?php if (!empty($recent_results)): ?>
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
                                <td><?php echo htmlspecialchars($result['student_name']); ?><br><small><?php echo htmlspecialchars($result['class']); ?></small></td>
                                <td><?php echo htmlspecialchars($result['exam_name']); ?></td>
                                <td><?php echo number_format($result['percentage'] ?? 0, 1); ?>%</td>
                                <td><strong><?php echo $result['grade'] ?? 'N/A'; ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="text-align:center; color:#999;">No results available</p>
            <?php endif; ?>
        </div>

        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> - Staff Portal</p>
        </div>
    </div>

    <script>
        document.getElementById('mobileMenuBtn').onclick = () => document.getElementById('sidebar').classList.toggle('active');
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768 && !document.getElementById('sidebar').contains(e.target) && !document.getElementById('mobileMenuBtn').contains(e.target)) {
                document.getElementById('sidebar').classList.remove('active');
            }
        });
    </script>
</body>

</html>
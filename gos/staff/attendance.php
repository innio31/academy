<?php
// gos/staff/attendance.php - Staff Attendance Tracking
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

// Initialize variables
$classes = [];
$students = [];
$attendance = [];
$message = null;
$selected_class = '';
$selected_date = date('Y-m-d');

try {
    // Get the staff_id string from the staff table
    $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
    $stmt->execute([$staff_id, $school_id]);
    $staff_id_string = $stmt->fetchColumn();

    if (!$staff_id_string) {
        $message = "Staff record not found. Please contact administrator.";
    } else {
        // Get assigned classes using the string staff_id
        $stmt = $pdo->prepare("SELECT class FROM staff_classes WHERE staff_id = ? AND school_id = ? ORDER BY class");
        $stmt->execute([$staff_id_string, $school_id]);
        $classes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) {
    error_log("Staff data fetch error: " . $e->getMessage());
    $message = "An error occurred while loading your data.";
}

// Mark attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_attendance'])) {
    $student_ids = $_POST['student_id'] ?? [];
    $statuses = $_POST['status'] ?? [];
    $date = $_POST['attendance_date'] ?? date('Y-m-d');
    $attendance_note = $_POST['attendance_note'] ?? null;

    // Check if date is not in future
    if (strtotime($date) > strtotime(date('Y-m-d'))) {
        $message = "Cannot mark attendance for future dates.";
    } else {
        try {
            $pdo->beginTransaction();

            foreach ($student_ids as $index => $student_id) {
                $status = $statuses[$index] ?? 'present';

                $stmt = $pdo->prepare("
                    INSERT INTO attendance (school_id, student_id, date, status, created_at) 
                    VALUES (?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE status = ?, created_at = NOW()
                ");
                $stmt->execute([$school_id, $student_id, $date, $status, $status]);

                // Log activity
                $stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, user_type, activity, ip_address, user_agent, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $staff_id,
                    'staff',
                    "Marked attendance for student ID: $student_id as $status on $date",
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
            }

            $pdo->commit();
            $message = "Attendance marked successfully for " . date('F j, Y', strtotime($date));
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Attendance marking error: " . $e->getMessage());
            $message = "Failed to mark attendance. Please try again.";
        }
    }
}

// Get selected class and date
$selected_class = $_GET['class'] ?? ($classes[0] ?? '');
$selected_date = $_GET['date'] ?? date('Y-m-d');

// Validate date is not in future
if (strtotime($selected_date) > strtotime(date('Y-m-d'))) {
    $selected_date = date('Y-m-d');
}

// Get students in class
if ($selected_class) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, full_name, admission_number 
            FROM students 
            WHERE school_id = ? AND class = ? AND status = 'active' 
            ORDER BY full_name
        ");
        $stmt->execute([$school_id, $selected_class]);
        $students = $stmt->fetchAll();

        // Get attendance for these students on selected date
        if (!empty($students)) {
            $ids = array_column($students, 'id');
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT student_id, status, created_at 
                FROM attendance 
                WHERE school_id = ? AND date = ? AND student_id IN ($placeholders)
            ");
            $stmt->execute(array_merge([$school_id, $selected_date], $ids));
            $attendance_records = $stmt->fetchAll();

            foreach ($attendance_records as $record) {
                $attendance[$record['student_id']] = [
                    'status' => $record['status'],
                    'time' => $record['created_at']
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Students fetch error: " . $e->getMessage());
        $message = "An error occurred while loading student data.";
    }
}

// Get attendance summary for the selected class
$summary = [];
if ($selected_class && !empty($students)) {
    try {
        $start_date = date('Y-m-d', strtotime('-30 days'));
        $stmt = $pdo->prepare("
            SELECT student_id, 
                   COUNT(CASE WHEN status = 'present' THEN 1 END) as present_count,
                   COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_count,
                   COUNT(CASE WHEN status = 'late' THEN 1 END) as late_count,
                   COUNT(*) as total_days
            FROM attendance 
            WHERE school_id = ? AND class = ? AND date BETWEEN ? AND ?
            GROUP BY student_id
        ");
        $stmt->execute([$school_id, $selected_class, $start_date, date('Y-m-d')]);
        $attendance_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($attendance_summary as $summary_record) {
            $summary[$summary_record['student_id']] = $summary_record;
        }
    } catch (Exception $e) {
        error_log("Summary fetch error: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Attendance</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
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
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-color), #1a2a3a);
            color: white;
            padding: 20px 0;
            z-index: 100;
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
            background: #d4af7a;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
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
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            border-bottom: 2px solid #ecf0f1;
            padding-bottom: 10px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .filter-bar {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
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
            font-size: 12px;
            margin-bottom: 5px;
            font-weight: 500;
            color: #555;
        }

        .form-control,
        .form-select {
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            width: 200px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-success {
            background: #27ae60;
            color: white;
        }

        .btn-warning {
            background: #f39c12;
            color: white;
        }

        .btn-danger {
            background: #e74c3c;
            color: white;
        }

        .btn-sm {
            padding: 5px 12px;
            font-size: 12px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .data-table th {
            background: #f5f5f5;
            font-weight: 600;
        }

        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-block;
        }

        .badge-present {
            background: #d5f4e6;
            color: #27ae60;
        }

        .badge-absent {
            background: #f8d7da;
            color: #e74c3c;
        }

        .badge-late {
            background: #fff3cd;
            color: #f39c12;
        }

        .alert-success {
            background: #d5f4e6;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            color: #27ae60;
        }

        .alert-error {
            background: #f8d7da;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            color: #e74c3c;
        }

        .empty-state {
            text-align: center;
            padding: 30px;
            color: #999;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
        }

        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: var(--primary-color);
        }

        .stat-label {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }

        .quick-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
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
            .filter-bar {
                flex-direction: column;
            }

            .form-control,
            .form-select {
                width: 100%;
            }

            .data-table {
                font-size: 12px;
            }

            .data-table th,
            .data-table td {
                padding: 8px;
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
        </div>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-students.php"><i class="fas fa-users"></i> My Students</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="assignments.php"><i class="fas fa-tasks"></i> Assignments</a></li>
            <li><a href="attendance.php" class="active"><i class="fas fa-calendar-check"></i> Attendance</a></li>
            <li><a href="/gos/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-calendar-check"></i> Student Attendance</h1>
                <p>Mark and track student attendance</p>
            </div>
            <button class="btn" onclick="window.location.href='/gos/logout.php'"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>

        <?php if (isset($message)): ?>
            <div class="alert-<?php echo strpos($message, 'Failed') !== false || strpos($message, 'not found') !== false ? 'error' : 'success'; ?>">
                <i class="fas fa-<?php echo strpos($message, 'Failed') !== false || strpos($message, 'not found') !== false ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if (empty($classes)): ?>
            <div class="card">
                <div class="empty-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>You have not been assigned to any class.</p>
                    <p style="margin-top: 10px;">Please contact the administrator to assign you to classes.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="filter-bar">
                <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
                    <div class="form-group">
                        <label>Class</label>
                        <select name="class" class="form-select" onchange="this.form.submit()">
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo htmlspecialchars($class); ?>" <?php echo $selected_class == $class ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" name="date" class="form-control" value="<?php echo $selected_date; ?>" max="<?php echo date('Y-m-d'); ?>" onchange="this.form.submit()">
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> View</button>
                    </div>
                </form>
            </div>

            <?php if (!empty($students)): ?>
                <!-- Quick Actions -->
                <div class="quick-actions">
                    <button onclick="markAll('present')" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Mark All Present</button>
                    <button onclick="markAll('absent')" class="btn btn-danger btn-sm"><i class="fas fa-times"></i> Mark All Absent</button>
                    <button onclick="markAll('late')" class="btn btn-warning btn-sm"><i class="fas fa-clock"></i> Mark All Late</button>
                </div>

                <!-- Attendance Summary Stats -->
                <?php if (!empty($summary)): ?>
                    <div class="stats-grid">
                        <?php
                        $total_present = 0;
                        $total_absent = 0;
                        $total_late = 0;
                        foreach ($summary as $stat) {
                            $total_present += $stat['present_count'];
                            $total_absent += $stat['absent_count'];
                            $total_late += $stat['late_count'];
                        }
                        $total_days = $total_present + $total_absent + $total_late;
                        ?>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $total_present; ?></div>
                            <div class="stat-label">Present (Last 30 days)</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $total_absent; ?></div>
                            <div class="stat-label">Absent (Last 30 days)</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $total_late; ?></div>
                            <div class="stat-label">Late (Last 30 days)</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $total_days > 0 ? round(($total_present / $total_days) * 100) : 0; ?>%</div>
                            <div class="stat-label">Attendance Rate</div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Attendance Form -->
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-users"></i>
                            Attendance for <?php echo htmlspecialchars($selected_class); ?> -
                            <?php echo date('l, F j, Y', strtotime($selected_date)); ?>
                        </h3>
                        <?php if ($selected_date == date('Y-m-d')): ?>
                            <span class="badge badge-present"><i class="fas fa-check-circle"></i> Today</span>
                        <?php elseif (strtotime($selected_date) < strtotime(date('Y-m-d'))): ?>
                            <span class="badge badge-late"><i class="fas fa-history"></i> Past Date</span>
                        <?php endif; ?>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="attendance_date" value="<?php echo $selected_date; ?>">
                        <div style="overflow-x: auto;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>S/N</th>
                                        <th>Admission No</th>
                                        <th>Student Name</th>
                                        <th>Status</th>
                                        <?php if ($selected_date == date('Y-m-d') || strtotime($selected_date) < strtotime(date('Y-m-d'))): ?>
                                            <th>Previous Status</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $sn = 1;
                                    foreach ($students as $student):
                                        $current_status = $attendance[$student['id']]['status'] ?? 'present';
                                        $recorded_time = $attendance[$student['id']]['time'] ?? null;
                                    ?>
                                        <tr>
                                            <td><?php echo $sn++; ?></td>
                                            <td><?php echo htmlspecialchars($student['admission_number']); ?></td>
                                            <td><strong><?php echo htmlspecialchars($student['full_name']); ?></strong>
                                                <?php if ($recorded_time): ?>
                                                    <br><small style="color:#999;">Recorded: <?php echo date('h:i A', strtotime($recorded_time)); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <input type="hidden" name="student_id[]" value="<?php echo $student['id']; ?>">
                                                <select name="status[]" class="form-select" style="width:120px;">
                                                    <option value="present" <?php echo $current_status == 'present' ? 'selected' : ''; ?>>Present</option>
                                                    <option value="absent" <?php echo $current_status == 'absent' ? 'selected' : ''; ?>>Absent</option>
                                                    <option value="late" <?php echo $current_status == 'late' ? 'selected' : ''; ?>>Late</option>
                                                </select>
                                            </td>
                                            <?php if ($selected_date == date('Y-m-d') || strtotime($selected_date) < strtotime(date('Y-m-d'))): ?>
                                                <td>
                                                    <?php if ($recorded_time): ?>
                                                        <span class="badge badge-<?php echo $current_status; ?>">
                                                            <?php echo ucfirst($current_status); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge badge-late">Not marked</span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                            <div class="form-group">
                                <label>Optional Note</label>
                                <input type="text" name="attendance_note" class="form-control" placeholder="Add a note for this attendance session" style="width: 300px;">
                            </div>
                            <button type="submit" name="mark_attendance" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Attendance
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Individual Student Attendance Records -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-line"></i> Individual Student Attendance (Last 10 days)</h3>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Admission No</th>
                                    <?php for ($i = 9; $i >= 0; $i--):
                                        $date = date('Y-m-d', strtotime("-$i days"));
                                    ?>
                                        <th><?php echo date('m/d', strtotime($date)); ?></th>
                                    <?php endfor; ?>
                                    <th>Attendance %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student):
                                    // Get last 10 days attendance for this student
                                    $last_10_days = [];
                                    for ($i = 9; $i >= 0; $i--) {
                                        $date = date('Y-m-d', strtotime("-$i days"));
                                        $stmt = $pdo->prepare("SELECT status FROM attendance WHERE student_id = ? AND date = ?");
                                        $stmt->execute([$student['id'], $date]);
                                        $status = $stmt->fetchColumn();
                                        $last_10_days[$date] = $status ?: '-';
                                    }

                                    // Calculate percentage
                                    $present_count = 0;
                                    foreach ($last_10_days as $status) {
                                        if ($status == 'present') $present_count++;
                                    }
                                    $percentage = round(($present_count / 10) * 100);
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($student['full_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($student['admission_number']); ?></td>
                                        <?php foreach ($last_10_days as $status): ?>
                                            <td>
                                                <span class="badge badge-<?php echo $status == 'present' ? 'present' : ($status == 'late' ? 'late' : ($status == 'absent' ? 'absent' : 'late')); ?>">
                                                    <?php echo $status == 'present' ? 'P' : ($status == 'late' ? 'L' : ($status == 'absent' ? 'A' : '-')); ?>
                                                </span>
                                            </td>
                                        <?php endforeach; ?>
                                        <td><strong><?php echo $percentage; ?>%</strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($selected_class): ?>
                <div class="card">
                    <div class="empty-state">
                        <i class="fas fa-users-slash"></i>
                        <p>No active students found in this class.</p>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').onclick = () => document.getElementById('sidebar').classList.toggle('active');

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768) {
                const sidebar = document.getElementById('sidebar');
                const menuBtn = document.getElementById('mobileMenuBtn');
                if (!sidebar.contains(e.target) && !menuBtn.contains(e.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // Mark all students with a specific status
        function markAll(status) {
            const selects = document.querySelectorAll('select[name="status[]"]');
            selects.forEach(select => {
                select.value = status;
            });
        }

        // Quick filter for today's date
        function goToToday() {
            const dateInput = document.querySelector('input[name="date"]');
            if (dateInput) {
                dateInput.value = '<?php echo date('Y-m-d'); ?>';
                dateInput.form.submit();
            }
        }

        // Confirm before saving
        document.querySelector('form')?.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn && !submitBtn.disabled) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            }
        });
    </script>
</body>

</html>
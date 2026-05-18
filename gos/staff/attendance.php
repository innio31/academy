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

// Get assigned classes
$stmt = $pdo->prepare("SELECT class FROM staff_classes WHERE staff_id = ? AND school_id = ?");
$stmt->execute([$staff_id, $school_id]);
$classes = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Mark attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_attendance'])) {
    $student_id = $_POST['student_id'];
    $status = $_POST['status'];
    $date = date('Y-m-d');

    $stmt = $pdo->prepare("
        INSERT INTO attendance (school_id, student_id, date, status) 
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE status = ?
    ");
    $stmt->execute([$school_id, $student_id, $date, $status, $status]);

    $message = "Attendance marked successfully!";
}

// Get selected class and date
$selected_class = $_GET['class'] ?? ($classes[0] ?? '');
$selected_date = $_GET['date'] ?? date('Y-m-d');

// Get students in class
$students = [];
if ($selected_class) {
    $stmt = $pdo->prepare("SELECT id, full_name, admission_number FROM students WHERE school_id = ? AND class = ? AND status = 'active' ORDER BY full_name");
    $stmt->execute([$school_id, $selected_class]);
    $students = $stmt->fetchAll();

    // Get today's attendance for these students
    $attendance = [];
    if (!empty($students)) {
        $ids = array_column($students, 'id');
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $pdo->prepare("SELECT student_id, status FROM attendance WHERE school_id = ? AND date = ? AND student_id IN ($placeholders)");
        $stmt->execute(array_merge([$school_id, $selected_date], $ids));
        $attendance = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
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

        .present {
            background: #d5f4e6;
        }

        .absent {
            background: #f8d7da;
        }

        .late {
            background: #fff3cd;
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
            <li><a href="attendance.php" class="active"><i class="fas fa-calendar-check"></i> Attendance</a></li>
            <li><a href="/gos/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-calendar-check"></i> Student Attendance</h1>
            </div>
            <button class="btn" onclick="window.location.href='/gos/logout.php'"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>

        <?php if (isset($message)): ?>
            <div style="background:#d5f4e6; padding:15px; border-radius:10px; margin-bottom:20px;"><?php echo $message; ?></div>
        <?php endif; ?>

        <div class="filter-bar">
            <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
                <div class="form-group"><label>Class</label><select name="class" class="form-select" onchange="this.form.submit()"><?php foreach ($classes as $class): ?><option value="<?php echo htmlspecialchars($class); ?>" <?php echo $selected_class == $class ? 'selected' : ''; ?>><?php echo htmlspecialchars($class); ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Date</label><input type="date" name="date" class="form-control" value="<?php echo $selected_date; ?>" onchange="this.form.submit()"></div>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <h3>Attendance for <?php echo htmlspecialchars($selected_class); ?> - <?php echo date('F j, Y', strtotime($selected_date)); ?></h3>
            </div>
            <?php if (empty($students)): ?>
                <p style="text-align:center; padding:30px; color:#999;">No students found in this class.</p>
            <?php else: ?>
                <form method="POST">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>S/N</th>
                                <th>Admission No</th>
                                <th>Student Name</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sn = 1;
                            foreach ($students as $student):
                                $status = $attendance[$student['id']] ?? 'present';
                            ?>
                                <tr>
                                    <td><?php echo $sn++; ?></td>
                                    <td><?php echo htmlspecialchars($student['admission_number']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($student['full_name']); ?></strong></td>
                                    <td>
                                        <input type="hidden" name="student_id[]" value="<?php echo $student['id']; ?>">
                                        <select name="status[]" class="form-select" style="width:120px;">
                                            <option value="present" <?php echo $status == 'present' ? 'selected' : ''; ?>>Present</option>
                                            <option value="absent" <?php echo $status == 'absent' ? 'selected' : ''; ?>>Absent</option>
                                            <option value="late" <?php echo $status == 'late' ? 'selected' : ''; ?>>Late</option>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <button type="submit" name="mark_attendance" class="btn btn-primary" style="margin-top: 20px;"><i class="fas fa-save"></i> Save Attendance</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <script>
        document.getElementById('mobileMenuBtn').onclick = () => document.getElementById('sidebar').classList.toggle('active');
    </script>
</body>

</html>
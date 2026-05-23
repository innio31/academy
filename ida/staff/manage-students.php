<?php
// ida/staff/manage-students.php - Staff View of Students
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: /ida/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$staff_id = $_SESSION['user_id'];
$staff_name = $_SESSION['user_name'] ?? 'Staff Member';

// Get the staff_id string from the staff table
$stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
$stmt->execute([$staff_id, $school_id]);
$staff_id_string = $stmt->fetchColumn();

if (!$staff_id_string) {
    $error = "Staff record not found. Please contact administrator.";
    $students = [];
    $assigned_classes = [];
} else {
    // Get staff assigned classes using the string version
    $stmt = $pdo->prepare("
        SELECT class FROM staff_classes 
        WHERE staff_id = ? AND school_id = ?
    ");
    $stmt->execute([$staff_id_string, $school_id]);
    $assigned_classes = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// If no classes assigned
if (empty($assigned_classes)) {
    $error = "You have not been assigned to any class. Please contact the administrator.";
    $students = [];
} else {
    // Get filter parameters
    $search = $_GET['search'] ?? '';
    $class_filter = $_GET['class'] ?? '';
    $status_filter = $_GET['status'] ?? 'active';

    // Build query
    $query = "SELECT * FROM students WHERE school_id = ? AND class IN (" . str_repeat('?,', count($assigned_classes) - 1) . '?)';
    $params = [$school_id];
    $params = array_merge($params, $assigned_classes);

    if (!empty($search)) {
        $query .= " AND (full_name LIKE ? OR admission_number LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if (!empty($class_filter)) {
        $query .= " AND class = ?";
        $params[] = $class_filter;
    }
    if (!empty($status_filter) && $status_filter !== 'all') {
        $query .= " AND status = ?";
        $params[] = $status_filter;
    }

    $query .= " ORDER BY class, full_name";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $students = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - My Students</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: #d4af7a;
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
            min-height: 100vh;
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
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.6rem;
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

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
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
            padding: 8px 16px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-sm {
            padding: 5px 12px;
            font-size: 12px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            overflow: hidden;
        }

        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .data-table th {
            background: var(--light-color);
            font-weight: 600;
        }

        .data-table tr:hover {
            background: #f9f9f9;
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }

        .status-active {
            background: #d5f4e6;
            color: #27ae60;
        }

        .status-inactive {
            background: #f8d7da;
            color: #e74c3c;
        }

        .empty-state {
            text-align: center;
            padding: 50px;
            color: #999;
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
            <li><a href="manage-students.php" class="active"><i class="fas fa-users"></i> My Students</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="assignments.php"><i class="fas fa-tasks"></i> Assignments</a></li>
            <li><a href="../ida/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-users"></i> My Students</h1>
            </div>
            <button class="btn" onclick="window.location.href='../ida/logout.php'"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>

        <div class="filter-bar">
            <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
                <div class="filter-group"><label>Search</label><input type="text" name="search" class="form-control" placeholder="Name or Admission" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"></div>
                <div class="filter-group"><label>Class</label><select name="class" class="form-select">
                        <option value="">All Classes</option><?php foreach ($assigned_classes as $class): ?><option value="<?php echo htmlspecialchars($class); ?>" <?php echo ($_GET['class'] ?? '') == $class ? 'selected' : ''; ?>><?php echo htmlspecialchars($class); ?></option><?php endforeach; ?>
                    </select></div>
                <div class="filter-group"><label>Status</label><select name="status" class="form-select">
                        <option value="active">Active</option>
                        <option value="all">All</option>
                        <option value="inactive">Inactive</option>
                    </select></div>
                <div class="filter-group"><button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button></div>
            </form>
        </div>

        <div style="background: white; border-radius: 10px; overflow-x: auto;">
            <?php if (isset($error)): ?>
                <div class="empty-state"><i class="fas fa-exclamation-triangle"></i>
                    <h3><?php echo $error; ?></h3>
                </div>
            <?php elseif (empty($students)): ?>
                <div class="empty-state"><i class="fas fa-users-slash"></i>
                    <h3>No students found</h3>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>S/N</th>
                            <th>Admission No</th>
                            <th>Full Name</th>
                            <th>Class</th>
                            <th>Parent Phone</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sn = 1;
                        foreach ($students as $student): ?>
                            <tr>
                                <td><?php echo $sn++; ?></td>
                                <td><?php echo htmlspecialchars($student['admission_number']); ?></td>
                                <td><strong><?php echo htmlspecialchars($student['full_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($student['class']); ?></td>
                                <td><?php echo htmlspecialchars($student['parent_phone'] ?? '—'); ?></td>
                                <td><span class="status-badge status-<?php echo $student['status']; ?>"><?php echo ucfirst($student['status']); ?></span></td>
                                <td><a href="view-student.php?id=<?php echo $student['id']; ?>" class="btn btn-primary btn-sm"><i class="fas fa-eye"></i> View</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <script>
        document.getElementById('mobileMenuBtn').onclick = () => document.getElementById('sidebar').classList.toggle('active');
    </script>
</body>

</html>
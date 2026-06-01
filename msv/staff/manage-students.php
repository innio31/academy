<?php
// msv/staff/manage-students.php - Staff View of Students (with global search)
session_start();

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

// Get the staff_id string from the staff table
$stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
$stmt->execute([$staff_id, $school_id]);
$staff_id_string_db = $stmt->fetchColumn();

if (!$staff_id_string_db) {
    $error = "Staff record not found. Please contact administrator.";
    $students = [];
    $assigned_classes = [];
} else {
    $staff_id_string = $staff_id_string_db;
    // Get staff assigned classes using the string version
    $stmt = $pdo->prepare("
    SELECT sc.class_id, c.class_name 
    FROM staff_classes sc
    LEFT JOIN classes c ON sc.class_id = c.id
    WHERE sc.staff_id = ? AND sc.school_id = ?
    ORDER BY c.class_name
");
$stmt->execute([$staff_id_string, $school_id]);
$assigned_classes = $stmt->fetchAll(); // Now contains both id and name
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

    // Build query - START WITH ALL ASSIGNED CLASSES, THEN APPLY FILTERS
    $query = "SELECT s.*, c.class_name 
          FROM students s
          LEFT JOIN classes c ON s.class_id = c.id
          WHERE s.school_id = ? AND s.class_id IN (" . str_repeat('?,', count($assigned_classes) - 1) . '?)';
    $params = [$school_id];
    $params = array_merge($params, $assigned_classes);

    // Apply search filter (if search term provided)
    if (!empty($search)) {
        $query .= " AND (full_name LIKE ? OR admission_number LIKE ? OR first_name LIKE ? OR last_name LIKE ?)";
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    // Apply class filter (if specific class selected)
    if (!empty($class_filter)) {
        $query .= " AND s.class_id = ?";
        $params[] = $class_filter;
    }

    // Apply status filter
    if (!empty($status_filter) && $status_filter !== 'all') {
        $query .= " AND s.status = ?";
        $params[] = $status_filter;
    }

    $query .= " ORDER BY c.class_name, s.full_name";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    // Also get all assigned classes for dropdown (always show all assigned classes)
    $all_assigned_classes = $assigned_classes;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - My Students</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            --sidebar-width: 280px;
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

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
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
            min-width: 180px;
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        /* Buttons */
        .btn {
            padding: 8px 18px;
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

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.75rem;
        }

        /* Table */
        .students-table-container {
            background: white;
            border-radius: var(--radius-lg);
            overflow-x: auto;
            box-shadow: var(--shadow-sm);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
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
        .data-table td strong,
        .student-name {
            font-size: 0.95rem !important;
            font-weight: 600 !important;
            color: var(--gray-800);
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-active {
            background: #d5f4e6;
            color: var(--success-color);
        }

        .status-inactive {
            background: #f8d7da;
            color: var(--danger-color);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray-600);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: var(--gray-400);
        }

        .empty-state h3 {
            font-size: 1rem;
            font-weight: 500;
        }

        /* Info Item for top bar */
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

        .result-count {
            background: var(--primary-color);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* Responsive */
        @media (min-width: 768px) {
            .main-content {
                margin-left: var(--sidebar-width);
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

            .filter-group {
                width: 100%;
            }

            .top-header {
                flex-direction: column;
                text-align: center;
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
                <h1><i class="fas fa-users"></i> My Students</h1>
                <p><i class="fas fa-chevron-right"></i> View and manage students in your assigned classes</p>
            </div>
            <div>
                <span class="info-item"><i class="fas fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></span>
            </div>
        </div>

        <div class="filter-bar">
            <form method="GET">
                <div class="filter-group" style="flex: 2;">
                    <label><i class="fas fa-search"></i> Search Students</label>
                    <input type="text" name="search" class="form-control" placeholder="Search by name, admission number..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-layer-group"></i> Filter by Class</label>
                    <select name="class" class="form-select">
                        <option value="">All Classes</option>
                        <?php foreach ($assigned_classes as $class): ?>
                            <option value="<?php echo htmlspecialchars($class); ?>" <?php echo ($_GET['class'] ?? '') == $class ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-flag"></i> Status</label>
                    <select name="status" class="form-select">
                        <option value="active" <?php echo ($_GET['status'] ?? 'active') == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="all" <?php echo ($_GET['status'] ?? '') == 'all' ? 'selected' : ''; ?>>All Students</option>
                        <option value="inactive" <?php echo ($_GET['status'] ?? '') == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply Filter</button>
                    <?php if (!empty($_GET['search']) || !empty($_GET['class']) || ($_GET['status'] ?? 'active') !== 'active'): ?>
                        <a href="manage-students.php" class="btn" style="background: var(--gray-200); color: var(--gray-800); margin-left: 8px;"><i class="fas fa-times"></i> Clear Filters</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="students-table-container">
            <?php if (isset($error)): ?>
                <div class="empty-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h3><?php echo htmlspecialchars($error); ?></h3>
                </div>
            <?php elseif (empty($students)): ?>
                <div class="empty-state">
                    <i class="fas fa-users-slash"></i>
                    <h3>No students found</h3>
                    <p style="margin-top: 8px;">
                        <?php if (!empty($_GET['search']) || !empty($_GET['class']) || ($_GET['status'] ?? 'active') !== 'active'): ?>
                            Try adjusting your search or filter criteria
                        <?php else: ?>
                            You have no students assigned to your classes yet
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Admission No</th>
                            <th>Student Name</th>
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
                                <td><code><?php echo htmlspecialchars($student['admission_number']); ?></code></td>
                                <td><strong class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($student['class_name']); ?></td>
                                <td><?php echo htmlspecialchars($student['parent_phone'] ?? '—'); ?></td>
                                <td><span class="status-badge status-<?php echo $student['status']; ?>"><?php echo ucfirst($student['status']); ?></span></td>
                                <td>
                                    <a href="view-student.php?id=<?php echo $student['id']; ?>" class="btn btn-primary btn-sm">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div style="padding: 15px 20px; border-top: 1px solid var(--gray-200); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <span class="info-item"><i class="fas fa-users"></i> Total: <?php echo count($students); ?> student(s)</span>
                    <span class="result-count">Showing results from your assigned classes</span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Mobile menu toggle - handled in staff_sidebar.php
        const mobileBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('staffSidebar');
        const overlay = document.getElementById('sidebarOverlay');

        if (mobileBtn) {
            mobileBtn.onclick = () => {
                sidebar.classList.toggle('active');
                if (overlay) overlay.classList.toggle('active');
            };
        }

        if (overlay) {
            overlay.onclick = () => {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            };
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768 && sidebar && sidebar.classList.contains('active')) {
                if (!sidebar.contains(e.target) && !mobileBtn.contains(e.target)) {
                    sidebar.classList.remove('active');
                    if (overlay) overlay.classList.remove('active');
                }
            }
        });
    </script>
</body>

</html>
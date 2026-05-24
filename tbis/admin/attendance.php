<?php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /tbis/login.php");
    exit();
}

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

// Handle POST requests for staff permissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'assign_teacher') {
        $staff_id = $_POST['staff_id'];
        $can_take_attendance = isset($_POST['can_take_attendance']) ? 1 : 0;
        $can_view_reports = isset($_POST['can_view_reports']) ? 1 : 0;
        $assigned_classes = isset($_POST['assigned_classes']) ? implode(',', $_POST['assigned_classes']) : '';

        // Check if exists
        $stmt = $pdo->prepare("SELECT id FROM attendance_permissions WHERE staff_id = ? AND school_id = ?");
        $stmt->execute([$staff_id, $school_id]);

        if ($stmt->fetch()) {
            $stmt = $pdo->prepare("UPDATE attendance_permissions SET can_take_attendance = ?, can_view_reports = ?, assigned_classes = ? WHERE staff_id = ? AND school_id = ?");
            $stmt->execute([$can_take_attendance, $can_view_reports, $assigned_classes, $staff_id, $school_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO attendance_permissions (school_id, staff_id, can_take_attendance, can_view_reports, assigned_classes) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$school_id, $staff_id, $can_take_attendance, $can_view_reports, $assigned_classes]);
        }

        $success_message = "Staff permissions updated successfully!";
    }
}

// Get classes for dropdown
$classes = $pdo->prepare("SELECT * FROM classes WHERE school_id = ? AND status = 'active' ORDER BY sort_order, class_name");
$classes->execute([$school_id]);
$classes = $classes->fetchAll();

// Get staff for assignment
$staff = $pdo->prepare("
    SELECT s.id, s.full_name, s.role, s.email, 
           ap.can_take_attendance, ap.can_view_reports, ap.assigned_classes 
    FROM staff s 
    LEFT JOIN attendance_permissions ap ON s.id = ap.staff_id AND ap.school_id = ?
    WHERE s.school_id = ? AND s.role IN ('staff', 'admin')
    ORDER BY s.full_name
");
$staff->execute([$school_id, $school_id]);
$staff = $staff->fetchAll();

// Get active events
$active_events = $pdo->prepare("SELECT * FROM attendance_sessions WHERE school_id = ? AND status = 'active' ORDER BY start_time DESC");
$active_events->execute([$school_id]);
$active_events = $active_events->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title><?php echo $school_name; ?> - Attendance Management</title>

    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: #d4af7a;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --warning-color: #f59e0b;
            --info-color: #3b82f6;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --sidebar-width: 280px;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--gray-100);
            color: var(--gray-800);
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(135deg, var(--primary-color), var(--gray-800));
            color: white;
            overflow-y: auto;
            z-index: 1000;
            transition: transform 0.3s ease;
            transform: translateX(-100%);
        }

        .sidebar.open {
            transform: translateX(0);
        }

        .sidebar-header {
            padding: 24px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header h3 {
            font-size: 18px;
            margin-bottom: 4px;
        }

        .sidebar-header p {
            font-size: 12px;
            opacity: 0.7;
        }

        .admin-info {
            padding: 20px;
            background: rgba(255, 255, 255, 0.1);
            margin: 16px;
            border-radius: 12px;
            text-align: center;
        }

        .admin-info h4 {
            font-size: 14px;
            margin-bottom: 4px;
        }

        .admin-info p {
            font-size: 11px;
            opacity: 0.7;
        }

        .nav-links {
            list-style: none;
            padding: 0 12px;
        }

        .nav-links li {
            margin-bottom: 4px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            border-radius: 10px;
            transition: all 0.2s ease;
            font-size: 14px;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
        }

        .nav-links a i {
            width: 20px;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            margin-left: 0;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        /* Top Bar */
        .top-bar {
            background: white;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: var(--shadow-sm);
            position: sticky;
            top: 0;
            z-index: 99;
        }

        .menu-toggle {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--gray-700);
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .menu-toggle:hover {
            background: var(--gray-100);
        }

        .page-title {
            flex: 1;
        }

        .page-title h1 {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .page-title p {
            font-size: 12px;
            color: var(--gray-500);
        }

        /* Container */
        .container {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }

        .card-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            transition: transform 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-number {
            font-size: 32px;
            font-weight: 800;
            color: var(--primary-color);
            line-height: 1;
        }

        .stat-label {
            font-size: 13px;
            color: var(--gray-500);
            margin-top: 8px;
        }

        /* Scanner */
        .scanner-container {
            background: var(--gray-900);
            border-radius: 16px;
            overflow: hidden;
            position: relative;
            min-height: 400px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #reader {
            width: 100%;
            min-height: 400px;
            display: none;
        }

        #reader.active {
            display: block;
        }

        .scanner-placeholder {
            text-align: center;
            padding: 80px 20px;
            color: white;
        }

        .scanner-placeholder i {
            font-size: 64px;
            margin-bottom: 16px;
            opacity: 0.8;
        }

        .scanner-placeholder p {
            font-size: 14px;
            opacity: 0.8;
            margin-bottom: 20px;
        }

        /* Buttons */
        .btn {
            padding: 10px 20px;
            border-radius: 10px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.2s ease;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-secondary {
            background: var(--gray-200);
            color: var(--gray-700);
        }

        .btn-block {
            width: 100%;
        }

        .camera-btn {
            background: var(--primary-color);
            color: white;
            padding: 14px 28px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            font-family: inherit;
        }

        .stop-camera-btn {
            background: var(--danger-color);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            margin-top: 12px;
            font-family: inherit;
        }

        /* Scan Type Selector */
        .scan-type-selector {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
        }

        .scan-type-btn {
            flex: 1;
            padding: 12px;
            border: 2px solid var(--gray-200);
            background: white;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: inherit;
        }

        .scan-type-btn.active {
            border-color: var(--primary-color);
            background: var(--primary-color);
            color: white;
        }

        /* Mode Toggle */
        .mode-toggle {
            display: flex;
            gap: 8px;
            background: var(--gray-100);
            padding: 4px;
            border-radius: 12px;
            margin-bottom: 20px;
        }

        .mode-btn {
            flex: 1;
            padding: 10px;
            border: none;
            background: transparent;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: inherit;
        }

        .mode-btn.active {
            background: var(--primary-color);
            color: white;
            box-shadow: var(--shadow-sm);
        }

        /* Staff Permission Table */
        .permission-table {
            width: 100%;
            border-collapse: collapse;
        }

        .permission-table th,
        .permission-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
            vertical-align: middle;
        }

        .permission-table th {
            background: var(--gray-50);
            font-weight: 600;
            color: var(--gray-600);
        }

        .permission-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-enabled {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-disabled {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Tables */
        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
        }

        .data-table th {
            font-weight: 600;
            color: var(--gray-600);
            background: var(--gray-50);
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-present {
            background: #d1fae5;
            color: #065f46;
        }

        .status-late {
            background: #fed7aa;
            color: #92400e;
        }

        .status-absent {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Feedback Toast */
        .scan-feedback {
            position: fixed;
            bottom: 20px;
            right: 20px;
            left: 20px;
            max-width: 400px;
            margin: 0 auto;
            background: var(--gray-900);
            color: white;
            padding: 16px;
            border-radius: 12px;
            text-align: center;
            animation: slideUp 0.3s ease;
            z-index: 1100;
            box-shadow: var(--shadow-lg);
        }

        @keyframes slideUp {
            from {
                transform: translateY(100%);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1050;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            max-height: 85vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--gray-200);
            display: flex;
            gap: 12px;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .close-modal:hover {
            background: var(--gray-100);
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--gray-200);
            border-radius: 10px;
            font-family: inherit;
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        /* Event Items */
        .event-item {
            background: white;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            border: 1px solid var(--gray-200);
        }

        .event-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .event-badge {
            background: var(--info-color);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .attendance-list-item {
            padding: 12px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .attendance-list-item:last-child {
            border-bottom: none;
        }

        .student-info h4 {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .student-info p {
            font-size: 12px;
            color: var(--gray-500);
        }

        .live-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10b981;
            margin-right: 8px;
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--gray-200);
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 10px 20px;
            background: none;
            border: none;
            font-weight: 500;
            cursor: pointer;
            color: var(--gray-600);
            transition: all 0.2s ease;
            font-family: inherit;
            border-radius: 8px 8px 0 0;
        }

        .tab-btn.active {
            color: var(--primary-color);
            border-bottom: 2px solid var(--primary-color);
            margin-bottom: -2px;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Alert */
        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 16px;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid var(--success-color);
        }

        /* Responsive */
        @media (min-width: 769px) {
            .sidebar {
                transform: translateX(0);
            }

            .main-content {
                margin-left: var(--sidebar-width);
            }

            .menu-toggle {
                display: none;
            }

            .scan-feedback {
                left: auto;
                right: 20px;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            .container {
                padding: 16px;
            }

            .card {
                padding: 16px;
            }

            .tabs {
                gap: 4px;
            }

            .tab-btn {
                padding: 8px 12px;
                font-size: 13px;
            }
        }
    </style>
</head>

<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h3><?php echo $school_name; ?></h3>
            <p>School Management System</p>
        </div>

        <div class="admin-info">
            <h4><?php echo htmlspecialchars($admin_name); ?></h4>
            <p><?php echo ucfirst(str_replace('_', ' ', $admin_role)); ?></p>
        </div>

        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-students.php"><i class="fas fa-users"></i> Manage Students</a></li>
            <li><a href="manage-staff.php"><i class="fas fa-chalkboard-teacher"></i> Manage Staff</a></li>
            <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Manage Subjects</a></li>
            <li><a href="manage-classes.php"><i class="fas fa-layer-group"></i> Manage Classes</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="attendance.php" class="active"><i class="fas fa-calendar-check"></i> Attendance</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="sync.php"><i class="fas fa-sync-alt"></i> Sync to Cloud</a></li>
            <li><a href="../tbis/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title">
                <h1>Attendance Management</h1>
                <p>Track student attendance with QR scanning</p>
            </div>
        </div>

        <div class="container">
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                </div>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('scanner')">
                    <i class="fas fa-qrcode"></i> Scanner
                </button>
                <button class="tab-btn" onclick="switchTab('reports')">
                    <i class="fas fa-chart-bar"></i> Reports
                </button>
                <button class="tab-btn" onclick="switchTab('staff')">
                    <i class="fas fa-users"></i> Staff Permissions
                </button>
                <button class="tab-btn" onclick="switchTab('events')">
                    <i class="fas fa-calendar-alt"></i> Events
                </button>
            </div>

            <!-- Tab 1: Scanner -->
            <div id="tab-scanner" class="tab-content active">
                <!-- Scanner Card -->
                <div class="card">
                    <div class="card-title">
                        <span><i class="fas fa-qrcode"></i> QR Scanner</span>
                    </div>

                    <div class="scan-type-selector">
                        <button class="scan-type-btn active" onclick="setScanType('check_in')">
                            <i class="fas fa-sign-in-alt"></i> Check In
                        </button>
                        <button class="scan-type-btn" onclick="setScanType('check_out')">
                            <i class="fas fa-sign-out-alt"></i> Check Out
                        </button>
                    </div>

                    <div class="scanner-container">
                        <div id="reader"></div>
                        <div id="scannerPlaceholder" class="scanner-placeholder">
                            <i class="fas fa-camera"></i>
                            <p>Camera is off</p>
                            <button class="camera-btn" onclick="startScanner()">
                                <i class="fas fa-video"></i> Start Camera
                            </button>
                        </div>
                    </div>
                    <div id="scanStatus" style="margin-top: 12px; text-align: center; font-size: 13px; color: var(--gray-500);">
                        <span class="live-indicator"></span> Click "Start Camera" to begin scanning
                    </div>
                </div>

                <!-- Today's Stats -->
                <div class="card">
                    <div class="card-title">
                        <span><i class="fas fa-chart-line"></i> Today's Summary</span>
                        <span id="currentDate" style="font-size: 13px; color: var(--gray-500);"></span>
                    </div>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-number" id="totalPresent">-</div>
                            <div class="stat-label">Present</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number" id="totalLate">-</div>
                            <div class="stat-label">Late</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number" id="totalAbsent">-</div>
                            <div class="stat-label">Absent</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number" id="totalStudents">-</div>
                            <div class="stat-label">Total Enrolled</div>
                        </div>
                    </div>
                </div>

                <!-- Recent Scans -->
                <div class="card">
                    <div class="card-title">
                        <span><i class="fas fa-clock"></i> Recent Scans</span>
                        <button class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px;" onclick="loadRecentScans()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Student Name</th>
                                    <th>Admission No</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="recentScansBody">
                                <tr>
                                    <td colspan="5" style="text-align:center;">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Tab 2: Reports -->
            <div id="tab-reports" class="tab-content">
                <div class="card">
                    <div class="card-title">
                        <span><i class="fas fa-chart-bar"></i> Attendance Reports</span>
                    </div>
                    <div class="stats-grid" style="margin-bottom: 20px;">
                        <div class="stat-card" onclick="showAttendanceList()">
                            <i class="fas fa-list" style="font-size: 24px; color: var(--primary-color);"></i>
                            <div class="stat-label">View Today's List</div>
                        </div>
                        <div class="stat-card" onclick="showHistory()">
                            <i class="fas fa-history" style="font-size: 24px; color: var(--primary-color);"></i>
                            <div class="stat-label">View History</div>
                        </div>
                        <div class="stat-card" onclick="exportReport()">
                            <i class="fas fa-download" style="font-size: 24px; color: var(--primary-color);"></i>
                            <div class="stat-label">Export Report</div>
                        </div>
                    </div>
                </div>

                <!-- Absentee Alert -->
                <div class="card">
                    <div class="card-title">
                        <span><i class="fas fa-bell"></i> Absentee Alert</span>
                    </div>
                    <div class="form-group">
                        <label>Select Class</label>
                        <select id="absentClass" class="form-control">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" id="absentDate" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <button class="btn btn-primary btn-block" onclick="loadAbsentList()">
                        <i class="fas fa-search"></i> Load Absent Students
                    </button>
                    <div id="absentList" style="margin-top: 16px;"></div>
                </div>
            </div>

            <!-- Tab 3: Staff Permissions -->
            <div id="tab-staff" class="tab-content">
                <div class="card">
                    <div class="card-title">
                        <span><i class="fas fa-user-plus"></i> Assign/Edit Staff Permissions</span>
                    </div>
                    <form method="POST" class="assign-form">
                        <input type="hidden" name="action" value="assign_teacher">
                        <div class="form-group">
                            <label>Select Staff Member</label>
                            <select name="staff_id" class="form-control" required>
                                <option value="">Choose Staff Member</option>
                                <?php foreach ($staff as $teacher): ?>
                                    <option value="<?php echo $teacher['id']; ?>">
                                        <?php echo htmlspecialchars($teacher['full_name']); ?>
                                        (<?php echo ucfirst($teacher['role']); ?>)
                                        <?php echo $teacher['email'] ? ' - ' . $teacher['email'] : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" name="can_take_attendance" value="1" checked>
                                Can Take Attendance
                            </label>
                        </div>
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" name="can_view_reports" value="1" checked>
                                Can View Reports
                            </label>
                        </div>
                        <div class="form-group">
                            <label>Assigned Classes (Optional - Leave empty for all classes)</label>
                            <select name="assigned_classes[]" class="form-control" multiple size="5">
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small>Hold Ctrl/Cmd to select multiple classes</small>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-save"></i> Save Permissions
                        </button>
                    </form>
                </div>

                <div class="card">
                    <div class="card-title">
                        <span><i class="fas fa-list"></i> Current Staff Permissions</span>
                    </div>
                    <div class="table-container">
                        <table class="permission-table">
                            <thead>
                                <tr>
                                    <th>Staff Name</th>
                                    <th>Role</th>
                                    <th>Take Attendance</th>
                                    <th>View Reports</th>
                                    <th>Assigned Classes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($staff as $teacher): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($teacher['full_name']); ?></td>
                                        <td><?php echo ucfirst($teacher['role']); ?></td>
                                        <td>
                                            <span class="permission-badge <?php echo $teacher['can_take_attendance'] ? 'badge-enabled' : 'badge-disabled'; ?>">
                                                <?php echo $teacher['can_take_attendance'] ? 'Yes' : 'No'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="permission-badge <?php echo $teacher['can_view_reports'] ? 'badge-enabled' : 'badge-disabled'; ?>">
                                                <?php echo $teacher['can_view_reports'] ? 'Yes' : 'No'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            if ($teacher['assigned_classes']) {
                                                $class_ids = explode(',', $teacher['assigned_classes']);
                                                $class_names = [];
                                                foreach ($classes as $class) {
                                                    if (in_array($class['id'], $class_ids)) {
                                                        $class_names[] = $class['class_name'];
                                                    }
                                                }
                                                echo implode(', ', $class_names);
                                            } else {
                                                echo '<span class="permission-badge badge-enabled">All Classes</span>';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (count($staff) == 0): ?>
                                    <tr>
                                        <td colspan="5" style="text-align:center;">No staff members found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Tab 4: Events -->
            <div id="tab-events" class="tab-content">
                <div class="card">
                    <button class="btn btn-primary btn-block" onclick="openEventModal()">
                        <i class="fas fa-plus-circle"></i> Create New Event
                    </button>
                </div>
                <div id="activeEventsList"></div>
                <div class="card">
                    <div class="card-title">Past Events</div>
                    <div id="pastEventsList"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Event Modal -->
    <div class="modal" id="eventModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create New Event</h3>
                <button class="close-modal" onclick="closeEventModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Event Name</label>
                    <input type="text" id="eventName" class="form-control" placeholder="e.g., Sports Day, Graduation, Assembly">
                </div>
                <div class="form-group">
                    <label>Event Type</label>
                    <select id="eventType" class="form-control">
                        <option value="check_in">Standard (Check In/Out)</option>
                        <option value="event">Special Event</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Restrict to Class (Optional)</label>
                    <select id="eventClass" class="form-control">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeEventModal()">Cancel</button>
                <button class="btn btn-primary" onclick="createEvent()">Create Event</button>
            </div>
        </div>
    </div>

    <!-- Attendance List Modal -->
    <div class="modal" id="attendanceListModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="attendanceListTitle">Attendance List</h3>
                <button class="close-modal" onclick="closeAttendanceListModal()">&times;</button>
            </div>
            <div class="modal-body" id="attendanceListBody"></div>
        </div>
    </div>

    <script>
        let html5QrcodeScanner = null;
        let currentScanType = 'check_in';
        let currentEventId = null;
        let isScannerActive = false;

        // Initialize
        document.getElementById('currentDate').innerText = new Date().toLocaleDateString('en-US', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });

        // Sidebar toggle
        document.getElementById('menuToggle').onclick = function() {
            document.getElementById('sidebar').classList.toggle('open');
        };

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const menuToggle = document.getElementById('menuToggle');
            if (window.innerWidth <= 768 && sidebar.classList.contains('open')) {
                if (!sidebar.contains(event.target) && !menuToggle.contains(event.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });

        // Tab switching
        function switchTab(tabName) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

            event.target.classList.add('active');
            document.getElementById(`tab-${tabName}`).classList.add('active');

            // Stop scanner when leaving scanner tab
            if (tabName !== 'scanner' && isScannerActive) {
                stopScanner();
            }

            // Load data for specific tabs
            if (tabName === 'events') {
                loadEvents();
            }
        }

        function setScanType(type) {
            currentScanType = type;
            document.querySelectorAll('.scan-type-btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
        }

        function startScanner() {
            if (html5QrcodeScanner && isScannerActive) {
                showFeedback('Camera is already active', 'info');
                return;
            }

            document.getElementById('scannerPlaceholder').style.display = 'none';
            document.getElementById('reader').classList.add('active');
            document.getElementById('reader').style.display = 'block';

            html5QrcodeScanner = new Html5Qrcode("reader");
            html5QrcodeScanner.start({
                    facingMode: "environment"
                }, {
                    fps: 10,
                    qrbox: {
                        width: 250,
                        height: 250
                    }
                },
                (decodedText) => {
                    handleScan(decodedText);
                },
                (error) => {
                    // Silent error handling
                }
            ).then(() => {
                isScannerActive = true;
                document.getElementById('scanStatus').innerHTML = '<span class="live-indicator"></span> Camera active - Ready to scan QR codes';

                if (!document.getElementById('stopCameraBtn')) {
                    const container = document.querySelector('.scanner-container');
                    const stopBtn = document.createElement('button');
                    stopBtn.id = 'stopCameraBtn';
                    stopBtn.className = 'stop-camera-btn';
                    stopBtn.innerHTML = '<i class="fas fa-stop"></i> Stop Camera';
                    stopBtn.onclick = stopScanner;
                    container.appendChild(stopBtn);
                }
            }).catch(err => {
                console.error("Failed to start scanner:", err);
                document.getElementById('scanStatus').innerHTML = '<span style="color: #ef4444;">⚠️ Camera access denied. Please check permissions.</span>';
                document.getElementById('scannerPlaceholder').style.display = 'block';
                document.getElementById('reader').style.display = 'none';
            });
        }

        function stopScanner() {
            if (html5QrcodeScanner && isScannerActive) {
                html5QrcodeScanner.stop().then(() => {
                    isScannerActive = false;
                    document.getElementById('reader').style.display = 'none';
                    document.getElementById('reader').classList.remove('active');
                    document.getElementById('scannerPlaceholder').style.display = 'block';
                    document.getElementById('scanStatus').innerHTML = '<span class="live-indicator"></span> Camera stopped - Click "Start Camera" to begin scanning';

                    const stopBtn = document.getElementById('stopCameraBtn');
                    if (stopBtn) stopBtn.remove();
                }).catch(err => {
                    console.error("Error stopping scanner:", err);
                });
            }
        }

        function handleScan(decodedText) {
            try {
                let studentId;
                try {
                    const data = JSON.parse(decodeURIComponent(decodedText));
                    if (data.type === 'student' && data.id) {
                        studentId = data.id;
                    } else if (data.id) {
                        studentId = data.id;
                    } else {
                        studentId = parseInt(decodedText);
                    }
                } catch (e) {
                    studentId = parseInt(decodedText);
                }

                if (studentId && studentId > 0) {
                    recordAttendance(studentId, currentScanType, currentEventId);
                } else {
                    showFeedback('Invalid QR code format', 'error');
                }
            } catch (e) {
                showFeedback('Invalid QR code', 'error');
            }
        }

        function recordAttendance(studentId, scanType, eventId = null) {
            const requestData = {
                action: 'record_attendance',
                student_id: studentId,
                scan_type: scanType
            };

            if (eventId) {
                requestData.event_id = eventId;
            }

            fetch('attendance_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(requestData)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const emoji = scanType === 'check_in' ? '✅' : '👋';
                        showFeedback(`${emoji} ${data.student_name} - ${scanType === 'check_in' ? 'Checked In' : 'Checked Out'} (${data.status})`, 'success');
                        loadTodayStats();
                        loadRecentScans();
                    } else {
                        showFeedback(`❌ ${data.error || 'Failed to record attendance'}`, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showFeedback('Network error. Please check your connection.', 'error');
                });
        }

        function showFeedback(message, type) {
            const feedback = document.createElement('div');
            feedback.className = 'scan-feedback';
            if (type === 'success') {
                feedback.style.background = '#10b981';
            } else if (type === 'error') {
                feedback.style.background = '#ef4444';
            } else {
                feedback.style.background = '#3b82f6';
            }
            feedback.innerHTML = message;
            document.body.appendChild(feedback);

            setTimeout(() => {
                feedback.remove();
            }, 3000);
        }

        function loadTodayStats() {
            fetch('attendance_api.php?action=today_stats')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('totalPresent').innerText = data.present || 0;
                        document.getElementById('totalLate').innerText = data.late || 0;
                        document.getElementById('totalAbsent').innerText = data.absent || 0;
                        document.getElementById('totalStudents').innerText = data.total || 0;
                    }
                })
                .catch(error => {
                    console.error('Error loading stats:', error);
                });
        }

        function loadRecentScans() {
            fetch('attendance_api.php?action=recent_scans')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.scans) {
                        const tbody = document.getElementById('recentScansBody');
                        if (!data.scans || data.scans.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">No scans yet today</td></tr>';
                            return;
                        }
                        tbody.innerHTML = '';
                        data.scans.forEach(scan => {
                            tbody.innerHTML += `
                                <tr>
                                    <td>${scan.time || new Date(scan.scan_time).toLocaleTimeString()}</td>
                                    <td>${escapeHtml(scan.student_name)}</td>
                                    <td>${escapeHtml(scan.admission_number)}</td>
                                    <td><span class="status-badge">${scan.scan_type === 'check_in' ? 'In' : 'Out'}</span></td>
                                    <td><span class="status-badge status-${scan.status}">${scan.status}</span></td>
                                </tr>
                            `;
                        });
                    }
                })
                .catch(error => {
                    console.error('Error loading scans:', error);
                });
        }

        function loadEvents() {
            fetch('attendance_api.php?action=get_active_events')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const activeEvents = data.events.filter(e => e.status === 'active');
                        const pastEvents = data.events.filter(e => e.status !== 'active');

                        const activeContainer = document.getElementById('activeEventsList');
                        if (activeEvents.length === 0) {
                            activeContainer.innerHTML = `
                                <div class="card">
                                    <p style="text-align:center; color: var(--gray-500);">No active events. Create one to start!</p>
                                </div>
                            `;
                        } else {
                            activeContainer.innerHTML = activeEvents.map(event => `
                                <div class="event-item">
                                    <div class="event-header">
                                        <strong><i class="fas fa-calendar-alt"></i> ${escapeHtml(event.session_name)}</strong>
                                        <span class="event-badge">Active</span>
                                    </div>
                                    <p style="font-size: 13px; margin-bottom: 12px;">
                                        Started: ${new Date(event.start_time).toLocaleTimeString()}
                                    </p>
                                    <div class="scan-type-selector" style="margin-bottom: 12px;">
                                        <button class="btn btn-primary" style="flex:1" onclick="scanForEvent(${event.id}, 'check_in')">
                                            <i class="fas fa-sign-in-alt"></i> Check In
                                        </button>
                                        <button class="btn btn-secondary" style="flex:1" onclick="scanForEvent(${event.id}, 'check_out')">
                                            <i class="fas fa-sign-out-alt"></i> Check Out
                                        </button>
                                    </div>
                                    <button class="btn btn-danger btn-block" onclick="closeEvent(${event.id})">
                                        Close Event
                                    </button>
                                </div>
                            `).join('');
                        }

                        const pastContainer = document.getElementById('pastEventsList');
                        if (pastEvents.length === 0) {
                            pastContainer.innerHTML = '<p style="text-align:center; color: var(--gray-500); padding: 20px;">No past events</p>';
                        } else {
                            pastContainer.innerHTML = pastEvents.map(event => `
                                <div class="attendance-list-item">
                                    <div>
                                        <strong>${escapeHtml(event.session_name)}</strong><br>
                                        <small>${new Date(event.start_time).toLocaleDateString()}</small>
                                    </div>
                                    <button class="btn btn-secondary" onclick="viewEventAttendance(${event.id})">
                                        View
                                    </button>
                                </div>
                            `).join('');
                        }
                    }
                });
        }

        function scanForEvent(eventId, type) {
            currentEventId = eventId;
            currentScanType = type;
            switchTab('scanner');
            if (!isScannerActive) {
                startScanner();
            }
            showFeedback(`Ready to scan for event: ${type === 'check_in' ? 'Check In' : 'Check Out'}`, 'success');
        }

        function openEventModal() {
            document.getElementById('eventModal').classList.add('show');
        }

        function closeEventModal() {
            document.getElementById('eventModal').classList.remove('show');
            document.getElementById('eventName').value = '';
            document.getElementById('eventType').value = 'check_in';
            document.getElementById('eventClass').value = '';
        }

        function createEvent() {
            const eventName = document.getElementById('eventName').value;
            const eventType = document.getElementById('eventType').value;
            const classId = document.getElementById('eventClass').value;

            if (!eventName) {
                showFeedback('Please enter an event name', 'error');
                return;
            }

            fetch('attendance_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'create_event',
                        event_name: eventName,
                        event_type: eventType,
                        class_id: classId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        closeEventModal();
                        showFeedback('Event created successfully!', 'success');
                        loadEvents();
                    } else {
                        showFeedback(data.error || 'Failed to create event', 'error');
                    }
                })
                .catch(error => {
                    showFeedback('Network error', 'error');
                });
        }

        function closeEvent(eventId) {
            if (confirm('Close this event? No more scans will be recorded.')) {
                fetch('attendance_api.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: 'close_event',
                            event_id: eventId
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showFeedback('Event closed', 'success');
                            loadEvents();
                        }
                    });
            }
        }

        function viewEventAttendance(eventId) {
            showFeedback('Event attendance details coming soon', 'info');
        }

        function showAttendanceList() {
            const date = new Date().toISOString().split('T')[0];
            fetch(`attendance_api.php?action=get_daily_stats&date=${date}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const modal = document.getElementById('attendanceListModal');
                        const title = document.getElementById('attendanceListTitle');
                        const body = document.getElementById('attendanceListBody');

                        title.innerHTML = `Attendance - ${data.date}`;

                        const presentStudents = data.attendance.filter(s => s.scan_type === 'check_in' && s.status !== 'late');
                        const lateStudents = data.attendance.filter(s => s.status === 'late');
                        const absentStudents = data.attendance.filter(s => !s.scan_type);

                        body.innerHTML = `
                            <div class="stats-grid" style="margin-bottom: 16px;">
                                <div class="stat-card">
                                    <div class="stat-number">${presentStudents.length}</div>
                                    <div class="stat-label">Present</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-number">${lateStudents.length}</div>
                                    <div class="stat-label">Late</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-number">${absentStudents.length}</div>
                                    <div class="stat-label">Absent</div>
                                </div>
                            </div>
                            <h4 style="margin-bottom: 12px;">Present Students</h4>
                            ${presentStudents.map(s => `
                                <div class="attendance-list-item">
                                    <div class="student-info">
                                        <h4>${escapeHtml(s.full_name)}</h4>
                                        <p>${escapeHtml(s.admission_number)} | ${escapeHtml(s.class_name || 'No Class')}</p>
                                    </div>
                                    <span class="status-badge status-present">Present</span>
                                </div>
                            `).join('') || '<p>No present students</p>'}
                            <h4 style="margin: 16px 0 12px;">Late Students</h4>
                            ${lateStudents.map(s => `
                                <div class="attendance-list-item">
                                    <div class="student-info">
                                        <h4>${escapeHtml(s.full_name)}</h4>
                                        <p>${escapeHtml(s.admission_number)} | ${escapeHtml(s.class_name || 'No Class')}</p>
                                    </div>
                                    <span class="status-badge status-late">Late</span>
                                </div>
                            `).join('') || '<p>No late students</p>'}
                            <h4 style="margin: 16px 0 12px;">Absent Students</h4>
                            ${absentStudents.map(s => `
                                <div class="attendance-list-item">
                                    <div class="student-info">
                                        <h4>${escapeHtml(s.full_name)}</h4>
                                        <p>${escapeHtml(s.admission_number)} | ${escapeHtml(s.class_name || 'No Class')}</p>
                                    </div>
                                    <span class="status-badge status-absent">Absent</span>
                                </div>
                            `).join('') || '<p>No absent students</p>'}
                        `;

                        modal.classList.add('show');
                    }
                });
        }

        function showHistory() {
            const date = new Date().toISOString().split('T')[0];
            fetch(`attendance_api.php?action=get_history&date=${date}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const modal = document.getElementById('attendanceListModal');
                        const title = document.getElementById('attendanceListTitle');
                        const body = document.getElementById('attendanceListBody');

                        title.innerHTML = `Attendance History - ${date}`;

                        if (!data.logs || data.logs.length === 0) {
                            body.innerHTML = '<p style="text-align:center;">No attendance records for this date</p>';
                        } else {
                            body.innerHTML = `
                                <div class="date-picker" style="display: flex; gap: 12px; margin-bottom: 16px;">
                                    <input type="date" id="historyDate" class="form-control" value="${date}">
                                    <button class="btn btn-primary" onclick="loadHistoryByDate()">Load</button>
                                </div>
                                <div id="historyList">
                                    ${data.logs.map(log => `
                                        <div class="attendance-list-item">
                                            <div class="student-info">
                                                <h4>${escapeHtml(log.student_name)}</h4>
                                                <p>${escapeHtml(log.admission_number)} | ${escapeHtml(log.class_name || 'No Class')}</p>
                                                <small>${new Date(log.scan_time).toLocaleTimeString()}</small>
                                            </div>
                                            <div>
                                                <span class="status-badge">${log.scan_type === 'check_in' ? 'In' : 'Out'}</span>
                                                <span class="status-badge status-${log.status}">${log.status}</span>
                                            </div>
                                        </div>
                                    `).join('')}
                                </div>
                            `;
                        }

                        modal.classList.add('show');
                    }
                });
        }

        function loadHistoryByDate() {
            const date = document.getElementById('historyDate').value;
            fetch(`attendance_api.php?action=get_history&date=${date}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const historyList = document.getElementById('historyList');
                        if (!data.logs || data.logs.length === 0) {
                            historyList.innerHTML = '<p style="text-align:center;">No attendance records for this date</p>';
                        } else {
                            historyList.innerHTML = data.logs.map(log => `
                                <div class="attendance-list-item">
                                    <div class="student-info">
                                        <h4>${escapeHtml(log.student_name)}</h4>
                                        <p>${escapeHtml(log.admission_number)} | ${escapeHtml(log.class_name || 'No Class')}</p>
                                        <small>${new Date(log.scan_time).toLocaleTimeString()}</small>
                                    </div>
                                    <div>
                                        <span class="status-badge">${log.scan_type === 'check_in' ? 'In' : 'Out'}</span>
                                        <span class="status-badge status-${log.status}">${log.status}</span>
                                    </div>
                                </div>
                            `).join('');
                        }
                    }
                });
        }

        function loadAbsentList() {
            const classId = document.getElementById('absentClass').value;
            const date = document.getElementById('absentDate').value;

            fetch(`attendance_api.php?action=get_daily_stats&date=${date}&class_id=${classId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const absentStudents = data.attendance.filter(s => !s.scan_type);
                        const container = document.getElementById('absentList');

                        if (absentStudents.length === 0) {
                            container.innerHTML = '<div class="alert alert-success">No absent students for this date!</div>';
                        } else {
                            container.innerHTML = `
                                <div style="margin-top: 16px;">
                                    <p><strong>${absentStudents.length} student(s) absent</strong></p>
                                    <div class="table-container">
                                        <table class="data-table">
                                            <thead>
                                                <tr><th>Name</th><th>Admission No</th><th>Class</th><th>Parent Phone</th></tr>
                                            </thead>
                                            <tbody>
                                                ${absentStudents.map(s => `
                                                    <tr>
                                                        <td>${escapeHtml(s.full_name)}</td>
                                                        <td>${escapeHtml(s.admission_number)}</td>
                                                        <td>${escapeHtml(s.class_name || 'No Class')}</td>
                                                        <td>${escapeHtml(s.parent_phone || 'N/A')}</td>
                                                    </tr>
                                                `).join('')}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            `;
                        }
                    }
                });
        }

        function exportReport() {
            alert('Export feature will be available soon. You can copy the data from the table.');
        }

        function closeAttendanceListModal() {
            document.getElementById('attendanceListModal').classList.remove('show');
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Auto-refresh every 10 seconds
        setInterval(() => {
            if (document.getElementById('tab-scanner').classList.contains('active')) {
                loadTodayStats();
                loadRecentScans();
            }
        }, 10000);

        // Close modals on outside click
        window.onclick = function(event) {
            const modal = document.getElementById('eventModal');
            const listModal = document.getElementById('attendanceListModal');
            if (event.target === modal) {
                closeEventModal();
            }
            if (event.target === listModal) {
                closeAttendanceListModal();
            }
        };

        // Initial load
        loadTodayStats();
        loadRecentScans();

        // Clean up on page unload
        window.addEventListener('beforeunload', function() {
            if (html5QrcodeScanner && isScannerActive) {
                html5QrcodeScanner.stop();
            }
        });
    </script>
</body>

</html>
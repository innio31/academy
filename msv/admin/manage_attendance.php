<?php
// admin/manage_attendance.php - Complete Attendance Management with QR Generation
// Follows existing admin file structure (like manage-students.php)

session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /msv/login.php");
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

if ($admin_role !== 'super_admin' && $admin_role !== 'admin') {
    header("Location: index.php?message=Access+denied&type=error");
    exit();
}

require_once '../includes/config.php';
require_once '../includes/qr_helper.php';
require_once '../includes/notification_helper.php';
require_once '../includes/email_helper.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input) {
        $action = $input['action'] ?? $action;
    }
    
    switch ($action) {
        case 'regenerate_school_qr':
            $result = regenerateSchoolQRCode($pdo, $school_id, $admin_id);
            echo json_encode(['success' => true, 'qr' => $result]);
            break;
            
        case 'generate_class_qr':
            $class_id = $_POST['class_id'] ?? $input['class_id'] ?? 0;
            $class_name = $_POST['class_name'] ?? $input['class_name'] ?? '';
            $expiry_hours = $_POST['expiry_hours'] ?? $input['expiry_hours'] ?? null;
            
            $result = generateClassQRCode($pdo, $school_id, $class_id, $class_name, $admin_id, $expiry_hours);
            echo json_encode(['success' => true, 'qr' => $result]);
            break;
            
        case 'get_unread_count':
            $count = getUnreadNotificationCount($pdo, $school_id, $admin_id, 'admin');
            echo json_encode(['success' => true, 'count' => $count]);
            break;
            
        case 'get_notifications':
            $notifications = getUserNotifications($pdo, $school_id, $admin_id, 'admin', 20);
            echo json_encode(['success' => true, 'notifications' => $notifications]);
            break;
            
        case 'mark_read':
            $notification_id = $_POST['notification_id'] ?? $input['notification_id'] ?? 0;
            markNotificationRead($pdo, $notification_id, $admin_id);
            echo json_encode(['success' => true]);
            break;
            
        case 'mark_all_read':
            markAllNotificationsRead($pdo, $school_id, $admin_id, 'admin');
            echo json_encode(['success' => true]);
            break;
            
        case 'test_email':
            $test_email = $_POST['test_email'] ?? $input['test_email'] ?? '';
            $result = testEmailConfiguration($pdo, $school_id, $test_email);
            echo json_encode($result);
            break;
            
        case 'save_email_settings':
            $settings = [
                'email_notifications_enabled' => $_POST['email_notifications_enabled'] ?? 0,
                'email_from_name' => $_POST['email_from_name'] ?? $school_name,
                'email_from_address' => $_POST['email_from_address'] ?? '',
                'smtp_host' => $_POST['smtp_host'] ?? '',
                'smtp_port' => $_POST['smtp_port'] ?? 587,
                'smtp_encryption' => $_POST['smtp_encryption'] ?? 'tls',
                'smtp_username' => $_POST['smtp_username'] ?? '',
                'smtp_password' => $_POST['smtp_password'] ?? ''
            ];
            
            $stmt = $pdo->prepare("
                UPDATE attendance_settings 
                SET email_notifications_enabled = ?, email_from_name = ?, email_from_address = ?,
                    smtp_host = ?, smtp_port = ?, smtp_encryption = ?, smtp_username = ?, smtp_password = ?
                WHERE school_id = ?
            ");
            $stmt->execute([
                $settings['email_notifications_enabled'],
                $settings['email_from_name'],
                $settings['email_from_address'],
                $settings['smtp_host'],
                $settings['smtp_port'],
                $settings['smtp_encryption'],
                $settings['smtp_username'],
                $settings['smtp_password'],
                $school_id
            ]);
            echo json_encode(['success' => true, 'message' => 'Email settings saved']);
            break;
            
        case 'get_absent_stats':
            $date = $_GET['date'] ?? date('Y-m-d');
            $stmt = $pdo->prepare("
                SELECT c.id, c.class_name, 
                    (SELECT COUNT(*) FROM students WHERE class_id = c.id AND school_id = ? AND status = 'active') as total,
                    (SELECT COUNT(*) FROM attendance_logs al 
                     JOIN students s ON al.student_id = s.id 
                     WHERE s.class_id = c.id AND DATE(al.scan_time) = ? AND al.scan_type = 'check_in') as present
                FROM classes c
                WHERE c.school_id = ? AND c.status = 'active'
                ORDER BY c.class_name
            ");
            $stmt->execute([$school_id, $date, $school_id]);
            $stats = $stmt->fetchAll();
            
            foreach ($stats as &$stat) {
                $stat['absent'] = max(0, $stat['total'] - $stat['present']);
            }
            
            echo json_encode(['success' => true, 'stats' => $stats, 'date' => $date]);
            break;
            
        case 'get_absent_students':
            $date = $_GET['date'] ?? date('Y-m-d');
            $class_id = $_GET['class_id'] ?? 0;
            
            if ($class_id) {
                $stmt = $pdo->prepare("
                    SELECT s.id, s.full_name, s.admission_number, s.parent_phone, s.parent_email
                    FROM students s
                    WHERE s.class_id = ? AND s.school_id = ? AND s.status = 'active'
                    AND NOT EXISTS (
                        SELECT 1 FROM attendance_logs al 
                        WHERE al.student_id = s.id AND DATE(al.scan_time) = ? AND al.scan_type = 'check_in'
                    )
                    ORDER BY s.full_name
                ");
                $stmt->execute([$class_id, $school_id, $date]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT s.id, s.full_name, s.admission_number, s.parent_phone, s.parent_email, c.class_name
                    FROM students s
                    LEFT JOIN classes c ON s.class_id = c.id
                    WHERE s.school_id = ? AND s.status = 'active'
                    AND NOT EXISTS (
                        SELECT 1 FROM attendance_logs al 
                        WHERE al.student_id = s.id AND DATE(al.scan_time) = ? AND al.scan_type = 'check_in'
                    )
                    ORDER BY c.class_name, s.full_name
                ");
                $stmt->execute([$school_id, $date]);
            }
            $students = $stmt->fetchAll();
            
            echo json_encode(['success' => true, 'students' => $students, 'count' => count($students)]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    exit();
}

// Get current active school QR
$active_school_qr = getActiveSchoolQRCode($pdo, $school_id);

// Get all classes
$classes = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? AND status = 'active' ORDER BY class_name");
$classes->execute([$school_id]);
$all_classes = $classes->fetchAll();

// Get attendance settings
$settings = $pdo->prepare("SELECT * FROM attendance_settings WHERE school_id = ?");
$settings->execute([$school_id]);
$attendance_settings = $settings->fetch();
if (!$attendance_settings) {
    $stmt = $pdo->prepare("INSERT INTO attendance_settings (school_id) VALUES (?)");
    $stmt->execute([$school_id]);
    $attendance_settings = ['email_notifications_enabled' => 1, 'email_from_name' => $school_name];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title><?php echo htmlspecialchars($school_name); ?> - Attendance Management</title>
    
    <!-- QR Scanner -->
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    
    <!-- Font Awesome & Google Fonts -->
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
            --secondary-color: <?php echo $secondary_color; ?>;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
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
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --shadow-sm: 0 1px 2px 0 rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--gray-100);
            color: var(--gray-800);
            min-height: 100vh;
        }

        /* Main Content */
        .main-content {
            min-height: 100vh;
            padding: 20px;
            transition: margin-left 0.3s ease;
        }

        /* Top Bar */
        .top-bar {
            background: white;
            border-radius: var(--radius-lg);
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: var(--shadow-sm);
        }

        .header-title h1 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 4px;
        }

        .header-title h1 i {
            color: var(--primary-color);
            margin-right: 10px;
        }

        .header-title p {
            font-size: 0.75rem;
            color: var(--gray-500);
        }

        /* Notification Bell */
        .notification-bell {
            position: relative;
            cursor: pointer;
        }

        .notification-bell i {
            font-size: 1.3rem;
            color: var(--gray-600);
        }

        .notification-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--danger);
            color: white;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 20px;
            min-width: 18px;
            text-align: center;
        }

        /* Notification Dropdown */
        .notification-dropdown {
            position: absolute;
            top: 50px;
            right: 10px;
            width: 320px;
            max-width: calc(100vw - 20px);
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            z-index: 1000;
            display: none;
            max-height: 400px;
            overflow-y: auto;
        }

        .notification-dropdown.show {
            display: block;
        }

        .notification-header {
            padding: 12px 16px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
        }

        .notification-item {
            padding: 12px 16px;
            border-bottom: 1px solid var(--gray-100);
            cursor: pointer;
            transition: background 0.2s;
        }

        .notification-item:hover {
            background: var(--gray-50);
        }

        .notification-item.unread {
            background: rgba(59, 130, 246, 0.05);
            border-left: 3px solid var(--primary-color);
        }

        .notification-title {
            font-weight: 600;
            font-size: 0.8rem;
            margin-bottom: 4px;
        }

        .notification-body {
            font-size: 0.7rem;
            color: var(--gray-600);
        }

        .notification-time {
            font-size: 0.6rem;
            color: var(--gray-400);
            margin-top: 4px;
        }

        /* Container */
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Tabs */
        .tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 20px;
            background: white;
            padding: 8px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
        }

        .tab-btn {
            flex: 1;
            padding: 12px 8px;
            background: none;
            border: none;
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            border-radius: var(--radius-md);
            transition: all 0.2s;
            font-family: inherit;
            color: var(--gray-600);
        }

        .tab-btn i {
            margin-right: 8px;
        }

        .tab-btn.active {
            background: var(--primary-color);
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--gray-200);
        }

        .card-header h2 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--gray-800);
        }

        .card-header h2 i {
            color: var(--primary-color);
            margin-right: 8px;
        }

        /* QR Display */
        .qr-display {
            text-align: center;
            padding: 20px;
            background: var(--gray-50);
            border-radius: var(--radius-lg);
        }

        .qr-image {
            max-width: 200px;
            width: 100%;
            height: auto;
            margin: 0 auto;
            border: 3px solid white;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-md);
        }

        .qr-info {
            margin-top: 12px;
            font-size: 0.75rem;
            color: var(--gray-500);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }

        @media (min-width: 640px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .stat-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 16px;
            text-align: center;
            box-shadow: var(--shadow-sm);
            cursor: pointer;
            transition: transform 0.2s;
        }

        .stat-card:active {
            transform: scale(0.98);
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--primary-color);
            line-height: 1;
        }

        .stat-label {
            font-size: 0.7rem;
            color: var(--gray-500);
            margin-top: 4px;
        }

        /* Class Stats List */
        .class-stats-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .class-stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: var(--gray-50);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: background 0.2s;
        }

        .class-stat-item:active {
            background: var(--gray-200);
        }

        .class-name {
            font-weight: 600;
            font-size: 0.85rem;
        }

        .class-absent {
            background: #fee2e2;
            color: var(--danger);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* Buttons */
        .btn {
            padding: 10px 18px;
            border-radius: var(--radius-md);
            border: none;
            font-weight: 500;
            font-size: 0.8rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            font-family: inherit;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:active {
            transform: scale(0.97);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-secondary {
            background: var(--gray-200);
            color: var(--gray-700);
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.7rem;
        }

        .btn-block {
            width: 100%;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            font-size: 0.75rem;
            color: var(--gray-700);
        }

        .form-control,
        .form-select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 0.85rem;
            transition: all 0.2s;
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        /* Checkbox */
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        /* Table */
        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.75rem;
            min-width: 500px;
        }

        .data-table th,
        .data-table td {
            padding: 10px 8px;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
        }

        .data-table th {
            background: var(--gray-50);
            font-weight: 600;
            color: var(--gray-600);
            font-size: 0.7rem;
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
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 500px;
            max-height: 85vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 16px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
        }

        .modal-header h3 {
            font-size: 1rem;
            font-weight: 600;
        }

        .modal-body {
            padding: 16px;
        }

        .modal-footer {
            padding: 16px;
            border-top: 1px solid var(--gray-200);
            display: flex;
            gap: 12px;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .close-modal:active {
            background: var(--gray-100);
        }

        /* Alert */
        .alert {
            padding: 12px;
            border-radius: var(--radius-md);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.75rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 3px solid var(--success);
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border-left: 3px solid var(--danger);
        }

        .alert-warning {
            background: #fed7aa;
            color: #92400e;
            border-left: 3px solid var(--warning);
        }

        /* Responsive */
        @media (max-width: 767px) {
            .main-content {
                padding: 16px;
            }
            
            .tab-btn {
                font-size: 0.7rem;
                padding: 10px 4px;
            }
            
            .tab-btn i {
                display: block;
                margin: 0 0 4px 0;
                font-size: 1rem;
            }
            
            .card {
                padding: 16px;
            }
            
            .stats-grid {
                gap: 10px;
            }
            
            .stat-number {
                font-size: 1.5rem;
            }
        }

        @media (min-width: 768px) {
            .main-content {
                margin-left: 280px;
            }
            
            .stats-grid {
                gap: 16px;
            }
            
            .stat-number {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="header-title">
                <h1><i class="fas fa-calendar-check"></i> Attendance Management</h1>
                <p>Manage QR codes, track attendance, send notifications</p>
            </div>
            <div class="notification-bell" id="notificationBell">
                <i class="fas fa-bell"></i>
                <span class="notification-badge" id="notificationCount">0</span>
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header">
                        <strong>Notifications</strong>
                        <button class="btn btn-secondary btn-sm" id="markAllReadBtn" style="padding: 4px 8px; font-size: 0.65rem;">Mark all read</button>
                    </div>
                    <div id="notificationList"></div>
                </div>
            </div>
        </div>

        <div class="container">
            <!-- Tabs -->
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('school_qr')">
                    <i class="fas fa-qrcode"></i> <span>School QR</span>
                </button>
                <button class="tab-btn" onclick="switchTab('class_qr')">
                    <i class="fas fa-chalkboard"></i> <span>Class QR</span>
                </button>
                <button class="tab-btn" onclick="switchTab('absent_alert')">
                    <i class="fas fa-bell"></i> <span>Absent Alert</span>
                </button>
                <button class="tab-btn" onclick="switchTab('email_settings')">
                    <i class="fas fa-envelope"></i> <span>Email</span>
                </button>
            </div>

            <!-- Tab 1: School QR -->
            <div id="tab-school_qr" class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-qrcode"></i> School QR Code</h2>
                        <button class="btn btn-primary btn-sm" onclick="regenerateSchoolQR()">
                            <i class="fas fa-sync-alt"></i> Regenerate
                        </button>
                    </div>
                    <div class="qr-display" id="schoolQRDisplay">
                        <?php if ($active_school_qr && file_exists($_SERVER['DOCUMENT_ROOT'] . $active_school_qr['qr_image'])): ?>
                            <img src="<?php echo htmlspecialchars($active_school_qr['qr_image']); ?>" alt="School QR Code" class="qr-image">
                            <div class="qr-info">
                                <p><i class="fas fa-clock"></i> Expires: <?php echo date('M j, Y g:i A', strtotime($active_school_qr['expires_at'])); ?></p>
                                <p><i class="fas fa-info-circle"></i> Staff scan this QR to clock in/out</p>
                            </div>
                        <?php else: ?>
                            <p style="margin-bottom: 16px;">No active school QR code. Generate one for staff attendance.</p>
                            <button class="btn btn-primary" onclick="regenerateSchoolQR()">
                                <i class="fas fa-qrcode"></i> Generate School QR
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-info-circle"></i> How It Works</h2>
                    </div>
                    <ul style="padding-left: 20px; font-size: 0.8rem; color: var(--gray-600);">
                        <li>Staff scan this QR code using the staff portal to clock in/out</li>
                        <li>QR code regenerates automatically every 24 hours (prevents sharing)</li>
                        <li>You can manually regenerate anytime - old QR becomes invalid</li>
                        <li>Each scan is logged with timestamp for accountability</li>
                    </ul>
                </div>
            </div>

            <!-- Tab 2: Class QR -->
            <div id="tab-class_qr" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-chalkboard"></i> Class QR Codes</h2>
                    </div>
                    <div class="form-group">
                        <label>Select Class</label>
                        <select id="classSelect" class="form-select">
                            <option value="">Select a class</option>
                            <?php foreach ($all_classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Expiry (Optional)</label>
                        <select id="qrExpiry" class="form-select">
                            <option value="">Never expires</option>
                            <option value="1">1 hour</option>
                            <option value="6">6 hours</option>
                            <option value="12">12 hours</option>
                            <option value="24">24 hours</option>
                            <option value="168">7 days</option>
                        </select>
                    </div>
                    <button class="btn btn-primary btn-block" onclick="generateClassQR()">
                        <i class="fas fa-qrcode"></i> Generate Class QR
                    </button>
                </div>

                <div id="classQRResult" style="display: none;" class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-qrcode"></i> Generated QR Code</h2>
                    </div>
                    <div class="qr-display">
                        <img id="classQRImage" src="" alt="Class QR Code" class="qr-image">
                        <div class="qr-info">
                            <p id="classQRInfo"></p>
                            <p><i class="fas fa-info-circle"></i> Teachers scan this QR to mark their attendance for this class</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab 3: Absent Alert -->
            <div id="tab-absent_alert" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-chart-bar"></i> Absentee Summary</h2>
                    </div>
                    <div class="form-group">
                        <label>Select Date</label>
                        <input type="date" id="absentDate" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <button class="btn btn-primary btn-block" onclick="loadAbsentStats()">
                        <i class="fas fa-chart-line"></i> Load Summary
                    </button>
                </div>

                <div id="absentStatsContainer" style="display: none;" class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-users"></i> Absent Students by Class</h2>
                    </div>
                    <div id="classStatsList" class="class-stats-list"></div>
                </div>
            </div>

            <!-- Tab 4: Email Settings -->
            <div id="tab-email_settings" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-envelope"></i> Email Notification Settings</h2>
                    </div>
                    <form id="emailSettingsForm">
                        <div class="form-group">
                            <label class="checkbox-item">
                                <input type="checkbox" name="email_notifications_enabled" value="1" <?php echo ($attendance_settings['email_notifications_enabled'] ?? 1) ? 'checked' : ''; ?>>
                                Enable Email Notifications
                            </label>
                        </div>
                        <div class="form-group">
                            <label>From Name</label>
                            <input type="text" name="email_from_name" class="form-control" value="<?php echo htmlspecialchars($attendance_settings['email_from_name'] ?? $school_name); ?>">
                        </div>
                        <div class="form-group">
                            <label>From Email</label>
                            <input type="email" name="email_from_address" class="form-control" value="<?php echo htmlspecialchars($attendance_settings['email_from_address'] ?? 'noreply@' . $_SERVER['HTTP_HOST']); ?>">
                        </div>
                        <div class="form-group">
                            <label>SMTP Host</label>
                            <input type="text" name="smtp_host" class="form-control" value="<?php echo htmlspecialchars($attendance_settings['smtp_host'] ?? ''); ?>" placeholder="smtp.gmail.com">
                        </div>
                        <div class="form-group">
                            <label>SMTP Port</label>
                            <input type="number" name="smtp_port" class="form-control" value="<?php echo htmlspecialchars($attendance_settings['smtp_port'] ?? 587); ?>">
                        </div>
                        <div class="form-group">
                            <label>SMTP Encryption</label>
                            <select name="smtp_encryption" class="form-select">
                                <option value="tls" <?php echo ($attendance_settings['smtp_encryption'] ?? '') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                                <option value="ssl" <?php echo ($attendance_settings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                <option value="none" <?php echo ($attendance_settings['smtp_encryption'] ?? '') === 'none' ? 'selected' : ''; ?>>None</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>SMTP Username</label>
                            <input type="text" name="smtp_username" class="form-control" value="<?php echo htmlspecialchars($attendance_settings['smtp_username'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>SMTP Password</label>
                            <input type="password" name="smtp_password" class="form-control" value="<?php echo htmlspecialchars($attendance_settings['smtp_password'] ?? ''); ?>">
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-save"></i> Save Settings
                        </button>
                    </form>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-flask"></i> Test Email</h2>
                    </div>
                    <div class="form-group">
                        <label>Test Email Address</label>
                        <input type="email" id="testEmail" class="form-control" placeholder="admin@school.com">
                    </div>
                    <button class="btn btn-success btn-block" onclick="testEmailConfig()">
                        <i class="fas fa-paper-plane"></i> Send Test Email
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Absent Students Modal -->
    <div class="modal" id="absentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="absentModalTitle">Absent Students</h3>
                <button class="close-modal" onclick="closeAbsentModal()">&times;</button>
            </div>
            <div class="modal-body" id="absentModalBody"></div>
        </div>
    </div>

    <!-- Sidebar -->
    <?php require_once 'includes/sidebar.php'; ?>

    <script>
        let html5QrScanner = null;
        let isScannerActive = false;

        // Tab switching
        function switchTab(tabName) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            event.target.closest('.tab-btn').classList.add('active');
            document.getElementById(`tab-${tabName}`).classList.add('active');
        }

        // Regenerate School QR
        function regenerateSchoolQR() {
            const formData = new URLSearchParams();
            formData.append('action', 'regenerate_school_qr');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Failed to regenerate QR code');
                }
            });
        }

        // Generate Class QR
        function generateClassQR() {
            const classId = document.getElementById('classSelect').value;
            const expiryHours = document.getElementById('qrExpiry').value;
            
            if (!classId) {
                alert('Please select a class');
                return;
            }
            
            const formData = new URLSearchParams();
            formData.append('action', 'generate_class_qr');
            formData.append('class_id', classId);
            if (expiryHours) formData.append('expiry_hours', expiryHours);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.qr) {
                    document.getElementById('classQRImage').src = data.qr.qr_url;
                    const expiryText = data.qr.expires_at !== 'never' ? `Expires: ${data.qr.expires_at}` : 'Never expires';
                    document.getElementById('classQRInfo').innerHTML = `<strong>${data.qr.class_name}</strong><br>${expiryText}`;
                    document.getElementById('classQRResult').style.display = 'block';
                } else {
                    alert('Failed to generate QR code');
                }
            });
        }

        // Load Absent Stats
        function loadAbsentStats() {
            const date = document.getElementById('absentDate').value;
            
            fetch(`${window.location.href}?action=get_absent_stats&date=${date}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const container = document.getElementById('classStatsList');
                    const statsContainer = document.getElementById('absentStatsContainer');
                    
                    if (data.stats.length === 0) {
                        container.innerHTML = '<p style="text-align:center; padding:20px;">No classes found</p>';
                    } else {
                        container.innerHTML = data.stats.map(stat => `
                            <div class="class-stat-item" onclick="showAbsentStudents(${stat.id}, '${escapeHtml(stat.class_name)}', '${date}')">
                                <div>
                                    <div class="class-name">${escapeHtml(stat.class_name)}</div>
                                    <small>Total: ${stat.total} students</small>
                                </div>
                                <div class="class-absent">${stat.absent} absent</div>
                            </div>
                        `).join('');
                    }
                    
                    statsContainer.style.display = 'block';
                }
            });
        }

        function showAbsentStudents(classId, className, date) {
            fetch(`${window.location.href}?action=get_absent_students&date=${date}&class_id=${classId}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const modal = document.getElementById('absentModal');
                    const title = document.getElementById('absentModalTitle');
                    const body = document.getElementById('absentModalBody');
                    
                    title.innerHTML = `${escapeHtml(className)} - Absent Students (${data.count})`;
                    
                    if (data.students.length === 0) {
                        body.innerHTML = '<p style="text-align:center; padding:20px;">No absent students</p>';
                    } else {
                        body.innerHTML = `
                            <div class="table-container">
                                <table class="data-table">
                                    <thead>
                                        <tr><th>Name</th><th>Admission No</th><th>Parent Contact</th></tr>
                                    </thead>
                                    <tbody>
                                        ${data.students.map(s => `
                                            <tr>
                                                <td><strong>${escapeHtml(s.full_name)}</strong></td>
                                                <td>${escapeHtml(s.admission_number)}</td>
                                                <td>
                                                    ${s.parent_phone ? `<i class="fas fa-phone"></i> ${escapeHtml(s.parent_phone)}<br>` : ''}
                                                    ${s.parent_email ? `<i class="fas fa-envelope"></i> ${escapeHtml(s.parent_email)}` : ''}
                                                    ${!s.parent_phone && !s.parent_email ? '—' : ''}
                                                </td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        `;
                    }
                    
                    modal.classList.add('show');
                }
            });
        }

        function closeAbsentModal() {
            document.getElementById('absentModal').classList.remove('show');
        }

        // Email Settings
        document.getElementById('emailSettingsForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'save_email_settings');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Email settings saved successfully');
                } else {
                    alert('Failed to save settings');
                }
            });
        });

        function testEmailConfig() {
            const testEmail = document.getElementById('testEmail').value;
            if (!testEmail) {
                alert('Please enter a test email address');
                return;
            }
            
            const formData = new URLSearchParams();
            formData.append('action', 'test_email');
            formData.append('test_email', testEmail);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Test email sent successfully! Check your inbox.');
                } else {
                    alert('Failed to send test email: ' + (data.error || 'Unknown error'));
                }
            });
        }

        // Notification functions
        function loadNotifications() {
            fetch(`${window.location.href}?action=get_notifications`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const list = document.getElementById('notificationList');
                    if (data.notifications.length === 0) {
                        list.innerHTML = '<div style="padding: 20px; text-align:center; color: var(--gray-500);">No notifications</div>';
                    } else {
                        list.innerHTML = data.notifications.map(n => `
                            <div class="notification-item ${n.is_read ? '' : 'unread'}" onclick="markNotificationRead(${n.id})">
                                <div class="notification-title">${escapeHtml(n.title)}</div>
                                <div class="notification-body">${escapeHtml(n.body)}</div>
                                <div class="notification-time">${n.time_ago}</div>
                            </div>
                        `).join('');
                    }
                }
            });
        }

        function loadUnreadCount() {
            fetch(`${window.location.href}?action=get_unread_count`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const badge = document.getElementById('notificationCount');
                    badge.textContent = data.count;
                    badge.style.display = data.count > 0 ? 'inline-block' : 'none';
                }
            });
        }

        function markNotificationRead(id) {
            const formData = new URLSearchParams();
            formData.append('action', 'mark_read');
            formData.append('notification_id', id);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(() => {
                loadNotifications();
                loadUnreadCount();
            });
        }

        document.getElementById('markAllReadBtn').addEventListener('click', function() {
            const formData = new URLSearchParams();
            formData.append('action', 'mark_all_read');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(() => {
                loadNotifications();
                loadUnreadCount();
            });
        });

        // Notification dropdown toggle
        const bell = document.getElementById('notificationBell');
        const dropdown = document.getElementById('notificationDropdown');
        
        bell.addEventListener('click', function(e) {
            e.stopPropagation();
            dropdown.classList.toggle('show');
            if (dropdown.classList.contains('show')) {
                loadNotifications();
            }
        });
        
        document.addEventListener('click', function() {
            dropdown.classList.remove('show');
        });
        
        dropdown.addEventListener('click', function(e) {
            e.stopPropagation();
        });

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Auto-refresh unread count every 30 seconds
        loadUnreadCount();
        setInterval(loadUnreadCount, 30000);
    </script>
</body>
</html>
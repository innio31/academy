<?php
// admin/manage_attendance.php - Main UI file (no AJAX handlers)

session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /ida/login.php");
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

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;

// Get current active school QR
$active_school_qr = getActiveSchoolQRCode($pdo, $school_id);

// Get all classes for filters
$classes = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? AND status = 'active' ORDER BY class_name");
$classes->execute([$school_id]);
$all_classes = $classes->fetchAll();

// Get all staff for report filters
$staff = $pdo->prepare("SELECT id, full_name, staff_id, role FROM staff WHERE school_id = ? AND is_active = 1 ORDER BY full_name");
$staff->execute([$school_id]);
$all_staff = $staff->fetchAll();

// Get attendance settings
$settings = $pdo->prepare("SELECT * FROM attendance_settings WHERE school_id = ?");
$settings->execute([$school_id]);
$attendance_settings = $settings->fetch();
if (!$attendance_settings) {
    $stmt = $pdo->prepare("INSERT INTO attendance_settings (school_id) VALUES (?)");
    $stmt->execute([$school_id]);
    $attendance_settings = ['email_notifications_enabled' => 1, 'email_from_name' => $school_name];
}

// Helper function to get active school QR
function getActiveSchoolQRCode($pdo, $school_id)
{
    $stmt = $pdo->prepare("
        SELECT * FROM school_qr_codes 
        WHERE school_id = ? AND status = 'active' 
        AND (expires_at IS NULL OR expires_at > NOW())
        ORDER BY generated_at DESC LIMIT 1
    ");
    $stmt->execute([$school_id]);
    return $stmt->fetch();
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

    <!-- Chart.js for reports -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <!-- Font Awesome & Google Fonts -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
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
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--gray-100);
            color: var(--gray-800);
            min-height: 100vh;
        }

        .main-content {
            min-height: 100vh;
            padding: 16px;
            transition: margin-left 0.3s ease;
        }

        .top-bar {
            background: white;
            border-radius: var(--radius-lg);
            padding: 12px 16px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: var(--shadow-sm);
        }

        .header-title h1 {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 2px;
        }

        .header-title h1 i {
            color: var(--primary-color);
            margin-right: 8px;
        }

        .header-title p {
            font-size: 0.7rem;
            color: var(--gray-500);
        }

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

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 16px;
            background: white;
            padding: 8px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            overflow-x: auto;
        }

        .tab-btn {
            flex: 0 0 auto;
            padding: 10px 12px;
            background: none;
            border: none;
            font-weight: 600;
            font-size: 0.75rem;
            cursor: pointer;
            border-radius: var(--radius-md);
            transition: all 0.2s;
            font-family: inherit;
            color: var(--gray-600);
            white-space: nowrap;
        }

        .tab-btn i {
            margin-right: 6px;
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

        .card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 16px;
            margin-bottom: 16px;
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

        .qr-display {
            text-align: center;
            padding: 20px;
            background: var(--gray-50);
            border-radius: var(--radius-lg);
        }

        .qr-image {
            max-width: 180px;
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

        .qr-actions {
            margin-top: 16px;
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }

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

        .report-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }

        .report-stat {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 16px;
            border-radius: var(--radius-lg);
            text-align: center;
        }

        .report-stat .value {
            font-size: 1.8rem;
            font-weight: 800;
        }

        .report-stat .label {
            font-size: 0.7rem;
            opacity: 0.9;
        }

        .duration-options {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 8px;
        }

        .duration-btn {
            flex: 1;
            padding: 8px;
            background: var(--gray-100);
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-size: 0.7rem;
            font-weight: 500;
            cursor: pointer;
            text-align: center;
        }

        .duration-btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

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
            background: white;
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .btn {
            padding: 10px 16px;
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

        .btn-secondary {
            background: var(--gray-200);
            color: var(--gray-700);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.7rem;
        }

        .btn-block {
            width: 100%;
        }

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
        }

        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .status-present {
            background: #d1fae5;
            color: #065f46;
        }

        .status-absent {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-late {
            background: #fed7aa;
            color: #92400e;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--gray-200);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--primary-color);
            border-radius: 4px;
        }

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
        }

        .class-absent {
            background: #fee2e2;
            color: var(--danger);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .chart-container {
            margin: 20px 0;
            height: 250px;
            position: relative;
        }

        canvas {
            max-height: 250px;
            width: 100%;
        }

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
            padding: 16px;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: var(--radius-lg);
            width: 100%;
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
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
        }

        .alert {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px;
            border-radius: var(--radius-md);
            background: white;
            box-shadow: var(--shadow-lg);
            z-index: 10000;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            color: white;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        @media (min-width: 768px) {
            .main-content {
                margin-left: 280px;
            }
        }

        @media (max-width: 767px) {
            .main-content {
                padding: 12px;
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
                <p>Manage QR codes, track attendance, generate reports</p>
            </div>
            <div class="notification-bell" id="notificationBell">
                <i class="fas fa-bell"></i>
                <span class="notification-badge" id="notificationCount">0</span>
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header">
                        <strong>Notifications</strong>
                        <button class="btn btn-secondary btn-sm" id="markAllReadBtn">Mark all read</button>
                    </div>
                    <div id="notificationList"></div>
                </div>
            </div>
        </div>

        <div class="container">
            <!-- Tabs -->
            <div class="tabs">
                <button class="tab-btn active" data-tab="school_qr">
                    <i class="fas fa-qrcode"></i> School QR
                </button>
                <button class="tab-btn" data-tab="class_qr">
                    <i class="fas fa-chalkboard"></i> Class QR
                </button>
                <button class="tab-btn" data-tab="attendance_report">
                    <i class="fas fa-chart-line"></i> Reports
                </button>
                <button class="tab-btn" data-tab="absent_alert">
                    <i class="fas fa-bell"></i> Absent Alert
                </button>
                <button class="tab-btn" data-tab="email_settings">
                    <i class="fas fa-envelope"></i> Email
                </button>
                <button class="tab-btn" data-tab="take_attendance">
                    <i class="fas fa-user-check"></i> Take Attendance
                </button>
                <button class="tab-btn" data-tab="staff_permissions">
                    <i class="fas fa-user-shield"></i> Staff Permissions
                </button>
            </div>

            <!-- Tab 1: School QR -->
            <div id="tab-school_qr" class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-qrcode"></i> School QR Code</h2>
                    </div>
                    <div class="qr-display">
                        <?php if ($active_school_qr && !empty($active_school_qr['qr_image']) && file_exists($_SERVER['DOCUMENT_ROOT'] . $active_school_qr['qr_image'])): ?>
                            <img src="<?php echo htmlspecialchars($active_school_qr['qr_image']); ?>" alt="School QR Code" class="qr-image" id="schoolQRImg">
                            <div class="qr-info">
                                <p><i class="fas fa-clock"></i> Expires: <?php echo $active_school_qr['expires_at'] ? date('M j, Y g:i A', strtotime($active_school_qr['expires_at'])) : 'Never expires'; ?></p>
                                <p><i class="fas fa-info-circle"></i> Staff scan this QR to clock in/out</p>
                            </div>
                            <div class="qr-actions">
                                <button class="btn btn-secondary btn-sm" onclick="downloadQR('schoolQRImg', 'school_qr')">
                                    <i class="fas fa-download"></i> Download
                                </button>
                                <button class="btn btn-secondary btn-sm" onclick="printQR('schoolQRImg', 'School QR Code')">
                                    <i class="fas fa-print"></i> Print
                                </button>
                            </div>
                        <?php else: ?>
                            <p>No active school QR code. Generate one below.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-clock"></i> Select QR Validity Period</h2>
                    </div>
                    <div class="duration-options" id="qrDurationOptions">
                        <div class="duration-btn" data-hours="72">3 Days</div>
                        <div class="duration-btn" data-hours="168">7 Days</div>
                        <div class="duration-btn" data-hours="720">30 Days</div>
                        <div class="duration-btn" data-hours="0" data-never="true">Never Expires</div>
                    </div>
                    <button class="btn btn-primary btn-block" onclick="regenerateSchoolQR()" style="margin-top: 16px;">
                        <i class="fas fa-sync-alt"></i> Generate / Regenerate QR
                    </button>
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
                        </div>
                        <div class="qr-actions">
                            <button class="btn btn-secondary btn-sm" onclick="downloadQR('classQRImage', 'class_qr')">
                                <i class="fas fa-download"></i> Download
                            </button>
                            <button class="btn btn-secondary btn-sm" onclick="printQR('classQRImage', 'Class QR Code')">
                                <i class="fas fa-print"></i> Print
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab 3: Attendance Report -->
            <div id="tab-attendance_report" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-chart-bar"></i> Attendance Report</h2>
                    </div>

                    <div class="form-group">
                        <label>Report Period</label>
                        <div class="duration-options" id="reportTypeOptions">
                            <div class="duration-btn active" data-type="daily">Daily</div>
                            <div class="duration-btn" data-type="weekly">Weekly</div>
                            <div class="duration-btn" data-type="monthly">Monthly</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Report Category</label>
                        <div class="duration-options" id="reportCategoryOptions">
                            <div class="duration-btn active" data-category="student">Student Attendance</div>
                            <div class="duration-btn" data-category="staff_attendance">Staff Attendance (Clock)</div>
                            <div class="duration-btn" data-category="staff_class">Staff Class Scan</div>
                        </div>
                    </div>

                    <div id="dailyFilter" class="form-group">
                        <label>Date</label>
                        <input type="date" id="reportDate" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div id="rangeFilter" style="display: none;">
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" id="startDate" class="form-control" value="<?php echo date('Y-m-d', strtotime('-7 days')); ?>">
                        </div>
                        <div class="form-group">
                            <label>End Date</label>
                            <input type="date" id="endDate" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>

                    <div id="classFilterGroup" class="form-group">
                        <label>Filter by Class (Optional)</label>
                        <select id="reportClassFilter" class="form-select">
                            <option value="">All Classes</option>
                            <?php foreach ($all_classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="staffFilterGroup" style="display: none;" class="form-group">
                        <label>Filter by Staff (Optional)</label>
                        <select id="reportStaffFilter" class="form-select">
                            <option value="">All Staff</option>
                            <?php foreach ($all_staff as $staff_member): ?>
                                <option value="<?php echo $staff_member['id']; ?>"><?php echo htmlspecialchars($staff_member['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button class="btn btn-primary btn-block" onclick="loadAttendanceReport()">
                        <i class="fas fa-chart-line"></i> Generate Report
                    </button>

                    <div style="margin-top: 12px; display: flex; gap: 10px;">
                        <button class="btn btn-secondary" onclick="exportReport('csv')">
                            <i class="fas fa-file-csv"></i> Export CSV
                        </button>
                        <button class="btn btn-secondary" onclick="exportReport('pdf')">
                            <i class="fas fa-file-pdf"></i> Export PDF
                        </button>
                    </div>
                </div>

                <div id="reportResults" style="display: none;">
                    <div id="reportSummary" class="report-summary"></div>
                    <div id="reportChart" class="chart-container"></div>
                    <div id="reportTable" class="card">
                        <div class="card-header">
                            <h2><i class="fas fa-table"></i> Detailed Report</h2>
                        </div>
                        <div class="table-container" id="reportTableBody"></div>
                    </div>
                </div>
            </div>

            <!-- Tab 4: Absent Alert -->
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

            <!-- Tab 5: Email Settings -->
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
                            <input type="email" name="email_from_address" class="form-control" value="<?php echo htmlspecialchars($attendance_settings['email_from_address'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>SMTP Host</label>
                            <input type="text" name="smtp_host" class="form-control" value="<?php echo htmlspecialchars($attendance_settings['smtp_host'] ?? ''); ?>">
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

            <!-- TAB: TAKE ATTENDANCE (Admin) -->
            <div id="tab-take_attendance" class="tab-content">

                <!-- Today's Stats -->
                <div class="stats-grid" style="grid-template-columns:repeat(4,1fr);">
                    <div class="card" style="text-align:center;padding:16px;margin-bottom:0;">
                        <div class="report-stat" style="background:linear-gradient(135deg,#10b981,#059669);border-radius:10px;padding:16px;">
                            <div class="value" id="adm_present">—</div>
                            <div class="label">Present</div>
                        </div>
                    </div>
                    <div class="card" style="text-align:center;padding:16px;margin-bottom:0;">
                        <div class="report-stat" style="background:linear-gradient(135deg,#ef4444,#dc2626);border-radius:10px;padding:16px;">
                            <div class="value" id="adm_absent">—</div>
                            <div class="label">Absent</div>
                        </div>
                    </div>
                    <div class="card" style="text-align:center;padding:16px;margin-bottom:0;">
                        <div class="report-stat" style="background:linear-gradient(135deg,#f59e0b,#d97706);border-radius:10px;padding:16px;">
                            <div class="value" id="adm_late">—</div>
                            <div class="label">Late</div>
                        </div>
                    </div>
                    <div class="card" style="text-align:center;padding:16px;margin-bottom:0;">
                        <div class="report-stat" style="background:linear-gradient(135deg,var(--primary-color),var(--secondary-color));border-radius:10px;padding:16px;">
                            <div class="value" id="adm_total">—</div>
                            <div class="label">Total</div>
                        </div>
                    </div>
                </div>

                <div style="height:16px;"></div>

                <!-- Mode toggle -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-user-check"></i> Mark Student Attendance</h2>
                    </div>

                    <div style="display:flex;gap:8px;margin-bottom:16px;">
                        <button class="btn btn-primary" id="admModeQR"    onclick="admSetMode('qr')">
                            <i class="fas fa-qrcode"></i> QR Scan
                        </button>
                        <button class="btn btn-secondary" id="admModeManual" onclick="admSetMode('manual')">
                            <i class="fas fa-list-check"></i> Manual (Class List)
                        </button>
                    </div>

                    <!-- QR Mode -->
                    <div id="admQrMode">
                        <div style="display:flex;gap:8px;margin-bottom:12px;">
                            <button class="btn btn-success" id="admScanTypeIn"  onclick="admSetScanType('check_in')"  style="flex:1;">
                                <i class="fas fa-sign-in-alt"></i> Check In
                            </button>
                            <button class="btn btn-secondary" id="admScanTypeOut" onclick="admSetScanType('check_out')" style="flex:1;">
                                <i class="fas fa-sign-out-alt"></i> Check Out
                            </button>
                        </div>

                        <div id="admScannerWrap" style="background:#111827;border-radius:12px;overflow:hidden;min-height:300px;display:flex;align-items:center;justify-content:center;">
                            <div id="admScannerPlaceholder" style="text-align:center;color:white;padding:60px 20px;">
                                <i class="fas fa-camera" style="font-size:48px;opacity:0.6;margin-bottom:12px;display:block;"></i>
                                Camera not started
                            </div>
                            <div id="adm-qr-reader" style="width:100%;display:none;"></div>
                        </div>

                        <div style="display:flex;gap:10px;margin-top:12px;">
                            <button class="btn btn-primary" id="admStartScanBtn" onclick="admStartScanner()" style="flex:1;">
                                <i class="fas fa-camera"></i> Start Camera
                            </button>
                            <button class="btn btn-secondary" id="admStopScanBtn" onclick="admStopScanner()" style="display:none;flex:1;">
                                <i class="fas fa-stop"></i> Stop
                            </button>
                        </div>

                        <div id="admScanResult" style="margin-top:12px;"></div>
                    </div>

                    <!-- Manual / Class List Mode -->
                    <div id="admManualMode" style="display:none;">
                        <div style="display:flex;gap:10px;margin-bottom:12px;flex-wrap:wrap;">
                            <div style="flex:1;min-width:160px;">
                                <label class="form-label" style="font-size:0.75rem;font-weight:500;color:var(--gray-700);display:block;margin-bottom:4px;">Class</label>
                                <select id="admClassSelect" class="form-select" onchange="admLoadClassStudents()">
                                    <option value="">Select class…</option>
                                    <?php foreach ($all_classes as $c): ?>
                                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['class_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div style="flex:1;min-width:140px;">
                                <label class="form-label" style="font-size:0.75rem;font-weight:500;color:var(--gray-700);display:block;margin-bottom:4px;">Date</label>
                                <input type="date" id="admAttDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" onchange="admLoadClassStudents()">
                            </div>
                        </div>

                        <div id="admClassStudentsWrap">
                            <p style="color:var(--gray-500);font-size:0.8rem;">Select a class to load students.</p>
                        </div>

                        <div id="admBulkActions" style="display:none;margin-top:12px;">
                            <div style="display:flex;gap:10px;">
                                <button class="btn btn-success" onclick="admMarkAllPresent()" style="flex:1;">
                                    <i class="fas fa-check-double"></i> Mark All Present
                                </button>
                                <button class="btn btn-primary" onclick="admSaveBulk()" style="flex:1;">
                                    <i class="fas fa-save"></i> Save Attendance
                                </button>
                            </div>
                        </div>

                        <div id="admBulkResult" style="margin-top:12px;"></div>
                    </div>
                </div>

                <!-- Recent Scans -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-history"></i> Recent Scans Today</h2>
                        <button class="btn btn-secondary btn-sm" onclick="admLoadRecentScans()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr><th>Time</th><th>Name</th><th>Class</th><th>Type</th><th>Status</th></tr>
                            </thead>
                            <tbody id="admRecentScansBody">
                                <tr><td colspan="5" style="text-align:center;">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>


            <!-- TAB: STAFF PERMISSIONS -->
            <div id="tab-staff_permissions" class="tab-content">

                <!-- Grant / Edit Permission form -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-user-shield"></i> Grant / Edit Permission</h2>
                    </div>

                    <div class="form-group">
                        <label>Staff Member</label>
                        <select id="permStaffSelect" class="form-select">
                            <option value="">Select staff…</option>
                            <?php foreach ($all_staff as $sm): ?>
                                <option value="<?php echo $sm['id']; ?>">
                                    <?php echo htmlspecialchars($sm['full_name'] . ' (' . $sm['staff_id'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap;">
                        <label style="display:flex;align-items:center;gap:8px;font-size:0.8rem;cursor:pointer;">
                            <input type="checkbox" id="permCanTake" checked style="width:16px;height:16px;">
                            Can take attendance
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;font-size:0.8rem;cursor:pointer;">
                            <input type="checkbox" id="permCanView" checked style="width:16px;height:16px;">
                            Can view reports
                        </label>
                    </div>

                    <div class="form-group">
                        <label>Assigned Classes <span style="font-size:0.7rem;color:var(--gray-500);">(leave empty = all classes)</span></label>
                        <div id="permClassCheckboxes" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px;max-height:200px;overflow-y:auto;border:2px solid var(--gray-200);border-radius:10px;padding:12px;">
                            <?php foreach ($all_classes as $c): ?>
                                <label style="display:flex;align-items:center;gap:6px;font-size:0.78rem;cursor:pointer;">
                                    <input type="checkbox" class="perm-class-cb" value="<?php echo $c['id']; ?>" style="width:14px;height:14px;">
                                    <?php echo htmlspecialchars($c['class_name']); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div style="display:flex;gap:10px;">
                        <button class="btn btn-primary" onclick="savePermissions()" style="flex:1;">
                            <i class="fas fa-save"></i> Save Permissions
                        </button>
                        <button class="btn btn-secondary" onclick="clearPermForm()" style="flex:1;">
                            <i class="fas fa-times"></i> Clear
                        </button>
                    </div>

                    <div id="permSaveResult" style="margin-top:12px;"></div>
                </div>

                <!-- Current Permissions Table -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-table"></i> Current Staff Permissions</h2>
                        <button class="btn btn-secondary btn-sm" onclick="loadPermissions()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Staff</th>
                                    <th>Code</th>
                                    <th>Take Att.</th>
                                    <th>View Reports</th>
                                    <th>Assigned Classes</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="permTableBody">
                                <tr><td colspan="6" style="text-align:center;">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
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
        // API URL
        const API_URL = 'manage_attendance_api.php';

        let attendanceChart = null;
        let currentReportData = null;
        let currentUserType = 'student';
        let selectedDuration = 72;
        let neverExpires = false;

        // ==================== TAB SWITCHING ====================
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const tabName = this.dataset.tab;
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                this.classList.add('active');
                document.getElementById(`tab-${tabName}`).classList.add('active');

                if (tabName === 'attendance_report') {
                    loadAttendanceReport();
                }
                if (tabName === 'take_attendance') {
                    admLoadTodayStats();
                    admLoadRecentScans();
                }
                if (tabName === 'staff_permissions') {
                    loadPermissions();
                }
            });
        });

        // ==================== QR DURATION SELECTION ====================
        document.querySelectorAll('#qrDurationOptions .duration-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('#qrDurationOptions .duration-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');

                if (this.dataset.never === 'true') {
                    neverExpires = true;
                    selectedDuration = 0;
                } else {
                    neverExpires = false;
                    selectedDuration = parseInt(this.dataset.hours);
                }
            });
        });

        // ==================== REPORT TYPE SELECTION ====================
        document.querySelectorAll('#reportTypeOptions .duration-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('#reportTypeOptions .duration-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');

                const type = this.dataset.type;
                if (type === 'daily') {
                    document.getElementById('dailyFilter').style.display = 'block';
                    document.getElementById('rangeFilter').style.display = 'none';
                } else {
                    document.getElementById('dailyFilter').style.display = 'none';
                    document.getElementById('rangeFilter').style.display = 'block';
                }
            });
        });

        document.querySelectorAll('#reportCategoryOptions .duration-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('#reportCategoryOptions .duration-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');

                currentUserType = this.dataset.category;

                const classFilter = document.getElementById('classFilterGroup');
                const staffFilter = document.getElementById('staffFilterGroup');

                if (currentUserType === 'student') {
                    classFilter.style.display = 'block';
                    staffFilter.style.display = 'none';
                } else if (currentUserType === 'staff_attendance') {
                    classFilter.style.display = 'none';
                    staffFilter.style.display = 'block';
                } else if (currentUserType === 'staff_class') {
                    classFilter.style.display = 'block';
                    staffFilter.style.display = 'block';
                }
            });
        });

        // ==================== API CALLS ====================
        async function apiCall(action, data = {}, method = 'GET') {
            const url = new URL(API_URL, window.location.href);
            url.searchParams.append('action', action);

            const options = {
                method: method,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            };

            if (method === 'POST') {
                const formData = new URLSearchParams();
                for (const [key, value] of Object.entries(data)) {
                    formData.append(key, value);
                }
                options.body = formData;
            } else {
                for (const [key, value] of Object.entries(data)) {
                    url.searchParams.append(key, value);
                }
            }

            const response = await fetch(url, options);
            return await response.json();
        }

        // ==================== SCHOOL QR ====================
        async function regenerateSchoolQR() {
            showLoading('Generating QR code...');

            const data = {
                action: 'regenerate_school_qr'
            };
            if (neverExpires) {
                data.never_expires = '1';
            } else {
                data.duration_hours = selectedDuration;
            }

            const result = await apiCall('regenerate_school_qr', data, 'POST');
            hideLoading();

            if (result.success) {
                showAlert('QR Code generated successfully!', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showAlert('Failed: ' + (result.error || 'Unknown error'), 'error');
            }
        }

        // ==================== CLASS QR ====================
        async function generateClassQR() {
            const classId = document.getElementById('classSelect').value;
            const expiryHours = document.getElementById('qrExpiry').value;

            if (!classId) {
                showAlert('Please select a class', 'error');
                return;
            }

            showLoading('Generating class QR code...');

            const data = {
                action: 'generate_class_qr',
                class_id: classId
            };
            if (expiryHours) data.expiry_hours = expiryHours;

            const result = await apiCall('generate_class_qr', data, 'POST');
            hideLoading();

            if (result.success && result.qr) {
                document.getElementById('classQRImage').src = result.qr.qr_url;
                const expiryText = result.qr.expires_at !== 'never' ? `Expires: ${result.qr.expires_at}` : 'Never expires';
                document.getElementById('classQRInfo').innerHTML = `<strong>${escapeHtml(result.qr.class_name)}</strong><br>${expiryText}`;
                document.getElementById('classQRResult').style.display = 'block';
                showAlert('Class QR Code generated successfully!', 'success');
            } else {
                showAlert('Failed to generate QR code', 'error');
            }
        }

        // ==================== ATTENDANCE REPORT ====================
        async function loadAttendanceReport() {
            const reportType = document.querySelector('#reportTypeOptions .duration-btn.active').dataset.type;
            const classId = document.getElementById('reportClassFilter').value;
            const staffId = document.getElementById('reportStaffFilter').value;

            let params = {
                action: 'get_attendance_report',
                report_type: reportType,
                user_type: currentUserType
            };

            if (reportType === 'daily') {
                params.date = document.getElementById('reportDate').value;
            } else {
                params.start_date = document.getElementById('startDate').value;
                params.end_date = document.getElementById('endDate').value;
            }

            if (classId) params.class_id = classId;
            if (staffId && (currentUserType === 'staff_attendance' || currentUserType === 'staff_class')) {
                params.staff_id = staffId;
            }

            showLoading('Loading report...');
            const result = await apiCall('get_attendance_report', params);
            hideLoading();

            if (result.success) {
                currentReportData = result.report;
                displayReport(result.report, reportType, currentUserType);
            } else {
                showAlert('Failed to load report: ' + (result.error || 'Unknown error'), 'error');
            }
        }

        function displayReport(report, reportType, userType) {
            const resultsDiv = document.getElementById('reportResults');
            const summaryDiv = document.getElementById('reportSummary');
            const chartDiv = document.getElementById('reportChart');
            const tableBody = document.getElementById('reportTableBody');

            resultsDiv.style.display = 'block';

            if (userType === 'student') {
                if (report.daily_breakdown && report.daily_breakdown.length > 0) {
                    const totalPresent = report.daily_breakdown.reduce((sum, d) => sum + d.present, 0);
                    const totalStudents = report.daily_breakdown[0]?.total || 0;
                    const avgAttendance = report.summary?.average_daily_attendance ||
                        (report.daily_breakdown.reduce((sum, d) => sum + d.percentage, 0) / report.daily_breakdown.length).toFixed(1);

                    summaryDiv.innerHTML = `
                        <div class="report-stat"><div class="value">${totalStudents}</div><div class="label">Total Students</div></div>
                        <div class="report-stat"><div class="value">${totalPresent}</div><div class="label">Total Present Days</div></div>
                        <div class="report-stat"><div class="value">${avgAttendance}%</div><div class="label">Avg Attendance</div></div>
                        <div class="report-stat"><div class="value">${report.daily_breakdown.length}</div><div class="label">School Days</div></div>
                    `;

                    const ctx = document.createElement('canvas');
                    chartDiv.innerHTML = '';
                    chartDiv.appendChild(ctx);

                    if (attendanceChart) attendanceChart.destroy();
                    attendanceChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: report.daily_breakdown.map(d => d.date.substring(5)),
                            datasets: [{
                                label: 'Attendance %',
                                data: report.daily_breakdown.map(d => d.percentage),
                                borderColor: '#3b82f6',
                                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                fill: true,
                                tension: 0.4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: true,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    max: 100,
                                    ticks: {
                                        callback: v => v + '%'
                                    }
                                }
                            }
                        }
                    });

                    tableBody.innerHTML = `<table class="data-table"><thead><tr><th>Date</th><th>Total</th><th>Present</th><th>Absent</th><th>%</th></tr></thead><tbody>
                        ${report.daily_breakdown.map(d => `<tr><td>${d.date}</td><td>${d.total}</td><td>${d.present}</td><td>${d.absent}</td><td>${d.percentage}%</td></tr>`).join('')}
                    </tbody></table>`;

                } else if (report.details && report.details.length > 0) {
                    const presentCount = report.details.filter(s => s.attendance_status === 'present').length;
                    const absentCount = report.details.filter(s => s.attendance_status === 'absent').length;

                    summaryDiv.innerHTML = `
                        <div class="report-stat"><div class="value">${report.details.length}</div><div class="label">Total Students</div></div>
                        <div class="report-stat"><div class="value">${presentCount}</div><div class="label">Present</div></div>
                        <div class="report-stat"><div class="value">${absentCount}</div><div class="label">Absent</div></div>
                        <div class="report-stat"><div class="value">${report.attendance_percentage}%</div><div class="label">Attendance</div></div>
                    `;

                    chartDiv.innerHTML = '';
                    tableBody.innerHTML = `<table class="data-table"><thead><tr><th>Name</th><th>Admission</th><th>Class</th><th>Status</th><th>Check-in</th></tr></thead><tbody>
                        ${report.details.map(s => `<tr>
                            <td>${escapeHtml(s.full_name)}</td>
                            <td>${escapeHtml(s.admission_number)}</td>
                            <td>${escapeHtml(s.class_name)}</td>
                            <td><span class="status-badge status-${s.attendance_status}">${s.attendance_status.toUpperCase()}</span></td>
                            <td>${s.check_in_time || '-'}</td>
                        </tr>`).join('')}
                    </tbody></table>`;
                } else {
                    tableBody.innerHTML = '<p style="padding:20px;text-align:center;">No data available</p>';
                }
            } else if (userType === 'staff_attendance' && report.details) {
                const presentCount = report.details.filter(s => s.attendance_status === 'present').length;
                summaryDiv.innerHTML = `
                    <div class="report-stat"><div class="value">${report.details.length}</div><div class="label">Total Staff</div></div>
                    <div class="report-stat"><div class="value">${presentCount}</div><div class="label">Present</div></div>
                    <div class="report-stat"><div class="value">${report.attendance_percentage}%</div><div class="label">Attendance</div></div>
                `;
                chartDiv.innerHTML = '';
                tableBody.innerHTML = `<table class="data-table"><thead><tr><th>Staff Name</th><th>Staff ID</th><th>Status</th><th>Clock In</th><th>Clock Out</th></tr></thead><tbody>
                    ${report.details.map(s => `<tr>
                        <td>${escapeHtml(s.full_name)}</td>
                        <td>${escapeHtml(s.staff_id)}</td>
                        <td><span class="status-badge status-${s.attendance_status}">${s.attendance_status.toUpperCase()}</span></td>
                        <td>${s.clock_in_time || '-'}</td>
                        <td>${s.clock_out_time || '-'}</td>
                    </tr>`).join('')}
                </tbody></table>`;
            } else if (userType === 'staff_class' && report.details) {
                summaryDiv.innerHTML = `<div class="report-stat"><div class="value">${report.total_scans || report.details.length}</div><div class="label">Total Scans</div></div>`;
                chartDiv.innerHTML = '';
                tableBody.innerHTML = `<table class="data-table"><thead><tr><th>Staff</th><th>Class</th><th>Scan Time</th><th>Duration</th></tr></thead><tbody>
                    ${report.details.map(s => `<tr>
                        <td>${escapeHtml(s.full_name)}</td>
                        <td>${escapeHtml(s.class_name)}</td>
                        <td>${s.scan_time || '-'}</td>
                        <td>${s.duration_minutes || 0} min</td>
                    </tr>`).join('')}
                </tbody></table>`;
            }
        }

        // ==================== EXPORT ====================
        function exportReport(format) {
            if (!currentReportData) {
                showAlert('Please generate a report first', 'error');
                return;
            }

            const reportType = document.querySelector('#reportTypeOptions .duration-btn.active').dataset.type;
            const classId = document.getElementById('reportClassFilter').value;
            const staffId = document.getElementById('reportStaffFilter').value;

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = API_URL;
            form.target = '_blank';

            const fields = {
                action: 'export_attendance_report',
                report_type: reportType,
                user_type: currentUserType,
                format: format
            };

            if (reportType === 'daily') {
                fields.date = document.getElementById('reportDate').value;
            } else {
                fields.start_date = document.getElementById('startDate').value;
                fields.end_date = document.getElementById('endDate').value;
            }

            if (classId) fields.class_id = classId;
            if (staffId && (currentUserType === 'staff_attendance' || currentUserType === 'staff_class')) {
                fields.staff_id = staffId;
            }

            for (const [key, value] of Object.entries(fields)) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                form.appendChild(input);
            }

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }

        // ==================== QR DOWNLOAD/PRINT ====================
        function downloadQR(imgId, filename) {
            const img = document.getElementById(imgId);
            if (!img || !img.src) {
                showAlert('No QR code to download', 'error');
                return;
            }
            const link = document.createElement('a');
            link.download = `${filename}_${Date.now()}.png`;
            link.href = img.src;
            link.click();
            showAlert('QR code downloaded!', 'success');
        }

        function printQR(imgId, title) {
            const img = document.getElementById(imgId);
            if (!img || !img.src) {
                showAlert('No QR code to print', 'error');
                return;
            }
            const win = window.open('', '_blank');
            win.document.write(`
                <!DOCTYPE html>
                <html><head><title>Print QR Code</title>
                <style>body{text-align:center;padding:40px;} img{max-width:300px;}</style>
                </head><body>
                <h2>${escapeHtml(title)}</h2>
                <img src="${img.src}" alt="QR Code">
                <p>School: <?php echo htmlspecialchars($school_name); ?></p>
                <p>Generated: ${new Date().toLocaleString()}</p>
                <button onclick="window.print();window.close();">Print</button>
                </body></html>
            `);
            win.document.close();
        }

        // ==================== ABSENT STUDENTS ====================
        async function loadAbsentStats() {
            const date = document.getElementById('absentDate').value;
            showLoading('Loading statistics...');
            const result = await apiCall('get_absent_stats', {
                date
            });
            hideLoading();

            if (result.success) {
                const container = document.getElementById('classStatsList');
                document.getElementById('absentStatsContainer').style.display = 'block';

                if (result.stats.length === 0) {
                    container.innerHTML = '<p>No classes found</p>';
                } else {
                    container.innerHTML = result.stats.map(stat => `
                        <div class="class-stat-item" onclick="showAbsentStudents(${stat.id}, '${escapeHtml(stat.class_name)}', '${date}')">
                            <div><div class="class-name">${escapeHtml(stat.class_name)}</div><small>Total: ${stat.total}</small></div>
                            <div class="class-absent">${stat.absent} absent</div>
                        </div>
                    `).join('');
                }
            }
        }

        async function showAbsentStudents(classId, className, date) {
            showLoading('Loading absent students...');
            const result = await apiCall('get_absent_students', {
                class_id: classId,
                date
            });
            hideLoading();

            if (result.success) {
                document.getElementById('absentModalTitle').innerHTML = `${className} - Absent (${result.count})`;
                document.getElementById('absentModalBody').innerHTML = result.students.length ? `
                    <table class="data-table"><thead><tr><th>Name</th><th>Admission</th><th>Parent Contact</th></tr></thead><tbody>
                    ${result.students.map(s => `<tr>
                        <td>${escapeHtml(s.full_name)}</td>
                        <td>${escapeHtml(s.admission_number)}</td>
                        <td>${s.parent_phone || s.parent_email || '-'}</td>
                    </tr>`).join('')}
                    </tbody></table>
                ` : '<p>No absent students</p>';
                document.getElementById('absentModal').classList.add('show');
            }
        }

        function closeAbsentModal() {
            document.getElementById('absentModal').classList.remove('show');
        }

        // ==================== EMAIL SETTINGS ====================
        document.getElementById('emailSettingsForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const data = {};
            for (let [key, value] of formData.entries()) data[key] = value;
            data.action = 'save_email_settings';

            showLoading('Saving settings...');
            const result = await apiCall('save_email_settings', data, 'POST');
            hideLoading();
            showAlert(result.success ? 'Settings saved!' : 'Failed to save', result.success ? 'success' : 'error');
        });

        async function testEmailConfig() {
            const testEmail = document.getElementById('testEmail').value;
            if (!testEmail) {
                showAlert('Enter an email address', 'error');
                return;
            }
            showLoading('Sending test email...');
            const result = await apiCall('test_email', {
                test_email: testEmail
            }, 'POST');
            hideLoading();
            showAlert(result.success ? 'Test email sent!' : 'Failed: ' + (result.error || 'Unknown'), result.success ? 'success' : 'error');
        }

        // ==================== NOTIFICATIONS ====================
        async function loadNotifications() {
            const result = await apiCall('get_notifications');
            if (result.success) {
                const list = document.getElementById('notificationList');
                if (!result.notifications.length) {
                    list.innerHTML = '<div style="padding:20px;text-align:center;">No notifications</div>';
                } else {
                    list.innerHTML = result.notifications.map(n => `
                        <div class="notification-item ${n.is_read ? '' : 'unread'}" onclick="markNotificationRead(${n.id})">
                            <div class="notification-title">${escapeHtml(n.title)}</div>
                            <div class="notification-body">${escapeHtml(n.body)}</div>
                            <div class="notification-time">${n.time_ago}</div>
                        </div>
                    `).join('');
                }
            }
        }

        async function loadUnreadCount() {
            const result = await apiCall('get_unread_count');
            if (result.success) {
                const badge = document.getElementById('notificationCount');
                badge.textContent = result.count;
                badge.style.display = result.count > 0 ? 'inline-block' : 'none';
            }
        }

        async function markNotificationRead(id) {
            await apiCall('mark_read', {
                notification_id: id
            }, 'POST');
            loadNotifications();
            loadUnreadCount();
        }

        document.getElementById('markAllReadBtn').addEventListener('click', async () => {
            await apiCall('mark_all_read', {}, 'POST');
            loadNotifications();
            loadUnreadCount();
        });

        // ==================== UI HELPERS ====================
        function showLoading(message) {
            let loader = document.getElementById('globalLoader');
            if (!loader) {
                loader = document.createElement('div');
                loader.id = 'globalLoader';
                loader.className = 'loading-overlay';
                loader.innerHTML = '<div class="spinner"></div><div id="loaderMessage" style="margin-top:16px;">Loading...</div>';
                document.body.appendChild(loader);
            }
            document.getElementById('loaderMessage').textContent = message;
            loader.style.display = 'flex';
        }

        function hideLoading() {
            const loader = document.getElementById('globalLoader');
            if (loader) loader.style.display = 'none';
        }

        function showAlert(message, type) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}`;
            document.body.appendChild(alertDiv);
            setTimeout(() => alertDiv.remove(), 3000);
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ==================== ADMIN TAKE ATTENDANCE ====================
        let admScanner      = null;
        let admScannerActive = false;
        let admCurrentMode  = 'qr';
        let admCurrentScan  = 'check_in';

        function admSetMode(mode) {
            admCurrentMode = mode;
            document.getElementById('admQrMode').style.display    = mode === 'qr'     ? 'block' : 'none';
            document.getElementById('admManualMode').style.display = mode === 'manual' ? 'block' : 'none';
            document.getElementById('admModeQR').className     = 'btn ' + (mode === 'qr'     ? 'btn-primary' : 'btn-secondary');
            document.getElementById('admModeManual').className  = 'btn ' + (mode === 'manual' ? 'btn-primary' : 'btn-secondary');
            if (mode === 'manual') admLoadClassStudents();
        }

        function admSetScanType(type) {
            admCurrentScan = type;
            document.getElementById('admScanTypeIn').className  = 'btn ' + (type === 'check_in'  ? 'btn-success'   : 'btn-secondary');
            document.getElementById('admScanTypeOut').className = 'btn ' + (type === 'check_out' ? 'btn-warning'   : 'btn-secondary');
        }

        function admStartScanner() {
            document.getElementById('admScannerPlaceholder').style.display = 'none';
            const reader = document.getElementById('adm-qr-reader');
            reader.style.display = 'block';
            document.getElementById('admStartScanBtn').style.display = 'none';
            document.getElementById('admStopScanBtn').style.display  = 'inline-flex';

            admScanner = new Html5Qrcode('adm-qr-reader');
            admScanner.start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: { width: 250, height: 250 } },
                (decoded) => admHandleScan(decoded),
                () => {}
            ).then(() => { admScannerActive = true; })
             .catch(() => {
                alert('Camera access denied or not available.');
                admStopScanner();
             });
        }

        function admStopScanner() {
            if (admScanner && admScannerActive) {
                admScanner.stop().then(() => {
                    admScannerActive = false;
                    document.getElementById('adm-qr-reader').style.display = 'none';
                    document.getElementById('admScannerPlaceholder').style.display = 'block';
                    document.getElementById('admStartScanBtn').style.display = 'inline-flex';
                    document.getElementById('admStopScanBtn').style.display  = 'none';
                });
            }
        }

        function admHandleScan(text) {
            try {
                const data = JSON.parse(decodeURIComponent(text));
                const sid  = data.id || data.student_id;
                if (sid) { admRecordStudent(sid); return; }
            } catch (e) {}
            const sid = parseInt(text);
            if (sid > 0) admRecordStudent(sid);
            else admShowScanResult('Invalid QR code', 'error');
        }

        async function admRecordStudent(studentId) {
            const result = await apiCall('admin_record_student_attendance', {
                student_id: studentId,
                scan_type:  admCurrentScan
            }, 'POST');

            if (result.success) {
                const emoji = admCurrentScan === 'check_in' ? '✅' : '👋';
                admShowScanResult(`${emoji} ${result.student_name} (${result.class_name}) — ${result.message} [${result.status}]`, 'success');
                admLoadTodayStats();
                admLoadRecentScans();
            } else {
                admShowScanResult('❌ ' + result.error, 'error');
            }
        }

        function admShowScanResult(msg, type) {
            const div = document.getElementById('admScanResult');
            div.innerHTML = `<div class="alert-${type === 'success' ? 'success' : 'danger'}" style="padding:10px;border-radius:8px;font-size:0.82rem;">${msg}</div>`;
            setTimeout(() => { div.innerHTML = ''; }, 4000);
        }

        async function admLoadTodayStats() {
            const r = await apiCall('admin_today_stats');
            if (r.success) {
                document.getElementById('adm_present').textContent = r.present;
                document.getElementById('adm_absent').textContent  = r.absent;
                document.getElementById('adm_late').textContent    = r.late;
                document.getElementById('adm_total').textContent   = r.total;
            }
        }

        async function admLoadRecentScans() {
            const r = await apiCall('admin_recent_scans', { limit: 20 });
            const tbody = document.getElementById('admRecentScansBody');
            if (!r.success || !r.scans.length) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">No scans today</td></tr>';
                return;
            }
            tbody.innerHTML = r.scans.map(s => `
                <tr>
                    <td>${s.time_formatted}</td>
                    <td><strong>${escapeHtml(s.full_name)}</strong></td>
                    <td>${escapeHtml(s.class_name || '—')}</td>
                    <td><span class="status-badge" style="background:#dbeafe;color:#1e40af;">${s.scan_type === 'check_in' ? 'In' : 'Out'}</span></td>
                    <td><span class="status-badge status-${s.status}">${s.status}</span></td>
                </tr>
            `).join('');
        }

        // Manual / class list mode
        let admStudentData = [];

        async function admLoadClassStudents() {
            const classId = document.getElementById('admClassSelect').value;
            const date    = document.getElementById('admAttDate').value;
            const wrap    = document.getElementById('admClassStudentsWrap');

            if (!classId) {
                wrap.innerHTML = '<p style="color:var(--gray-500);font-size:0.8rem;">Select a class to load students.</p>';
                document.getElementById('admBulkActions').style.display = 'none';
                return;
            }

            wrap.innerHTML = '<p style="color:var(--gray-500);font-size:0.8rem;">Loading…</p>';
            const r = await apiCall('admin_get_class_students', { class_id: classId, date });

            if (!r.success) {
                wrap.innerHTML = `<p style="color:red;font-size:0.8rem;">${r.error}</p>`;
                return;
            }

            admStudentData = r.students;
            const s = r.summary;

            wrap.innerHTML = `
                <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
                    <span class="status-badge status-present">Present: ${s.present}</span>
                    <span class="status-badge status-absent">Absent: ${s.absent}</span>
                    <span class="status-badge status-late">Late: ${s.late}</span>
                    <span class="status-badge" style="background:#e5e7eb;color:#374151;">Total: ${s.total}</span>
                </div>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr><th>#</th><th>Adm. No</th><th>Name</th><th>Status</th><th>Time</th><th>Mark</th></tr>
                        </thead>
                        <tbody id="admStudentListBody">
                            ${r.students.map((st, i) => {
                                const status = st.attendance_status;
                                const time   = st.scan_time
                                    ? new Date(st.scan_time).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})
                                    : '—';
                                const badge  = status === 'present'
                                    ? `<span class="status-badge status-${st.log_status === 'late' ? 'late' : 'present'}">${st.log_status === 'late' ? 'Late' : 'Present'}</span>`
                                    : '<span class="status-badge status-absent">Absent</span>';
                                return `
                                    <tr id="admRow_${st.id}">
                                        <td>${i+1}</td>
                                        <td>${escapeHtml(st.admission_number)}</td>
                                        <td><strong>${escapeHtml(st.full_name)}</strong></td>
                                        <td>${badge}</td>
                                        <td>${time}</td>
                                        <td>
                                            ${status !== 'present'
                                                ? `<button class="btn btn-success btn-sm" onclick="admMarkOne(${st.id})">
                                                       <i class="fas fa-check"></i>
                                                   </button>`
                                                : '<span style="color:var(--gray-400);font-size:0.7rem;">Done</span>'}
                                        </td>
                                    </tr>
                                `;
                            }).join('')}
                        </tbody>
                    </table>
                </div>
            `;
            document.getElementById('admBulkActions').style.display = 'flex';
        }

        async function admMarkOne(studentId) {
            const result = await apiCall('admin_record_student_attendance', {
                student_id: studentId,
                scan_type:  'check_in'
            }, 'POST');
            if (result.success) {
                showAlert(`✅ ${result.student_name} marked present`, 'success');
                admLoadClassStudents();
                admLoadTodayStats();
            } else {
                showAlert('❌ ' + result.error, 'error');
            }
        }

        function admMarkAllPresent() {
            const absent = admStudentData.filter(s => s.attendance_status !== 'present');
            if (!absent.length) { showAlert('All students already marked', 'success'); return; }
            if (!confirm(`Mark all ${absent.length} absent student(s) as present?`)) return;
            absent.forEach(s => admMarkOne(s.id));
        }

        async function admSaveBulk() {
            const classId    = document.getElementById('admClassSelect').value;
            const date       = document.getElementById('admAttDate').value;
            const presentIds = [];
            const absentIds  = [];

            admStudentData.forEach(s => {
                if (s.attendance_status === 'present') presentIds.push(s.id);
                else absentIds.push(s.id);
            });

            const result = await apiCall('admin_bulk_mark_attendance', {
                class_id:    classId,
                date,
                present_ids: presentIds,
                absent_ids:  absentIds
            }, 'POST');

            const div = document.getElementById('admBulkResult');
            if (result.success) {
                div.innerHTML = `<div class="alert-success" style="padding:10px;border-radius:8px;">${result.message}</div>`;
                admLoadTodayStats();
            } else {
                div.innerHTML = `<div class="alert-danger" style="padding:10px;border-radius:8px;">${result.error}</div>`;
            }
            setTimeout(() => { div.innerHTML = ''; }, 4000);
        }

        // ==================== STAFF PERMISSIONS ====================
        async function loadPermissions() {
            const r = await apiCall('get_staff_permissions');
            const tbody = document.getElementById('permTableBody');
            if (!r.success || !r.permissions.length) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">No permissions set yet.</td></tr>';
                return;
            }
            tbody.innerHTML = r.permissions.map(p => `
                <tr>
                    <td><strong>${escapeHtml(p.full_name)}</strong></td>
                    <td>${escapeHtml(p.staff_code)}</td>
                    <td>${p.can_take_attendance ? '<span class="status-badge status-present">Yes</span>' : '<span class="status-badge status-absent">No</span>'}</td>
                    <td>${p.can_view_reports    ? '<span class="status-badge status-present">Yes</span>' : '<span class="status-badge status-absent">No</span>'}</td>
                    <td style="font-size:0.7rem;max-width:200px;">${escapeHtml(p.assigned_class_names)}</td>
                    <td>
                        <button class="btn btn-secondary btn-sm" onclick="editPermission(${p.staff_id})" style="margin-right:4px;">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm" style="background:#fee2e2;color:#991b1b;" onclick="revokePermission(${p.staff_id}, '${escapeHtml(p.full_name)}')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        function editPermission(staffId) {
            document.getElementById('permStaffSelect').value = staffId;
            document.getElementById('permStaffSelect').scrollIntoView({ behavior: 'smooth' });
        }

        async function revokePermission(staffId, name) {
            if (!confirm(`Revoke all attendance permissions for ${name}?`)) return;
            const r = await apiCall('revoke_staff_permissions', { staff_id: staffId }, 'POST');
            if (r.success) { showAlert('Permissions revoked', 'success'); loadPermissions(); }
            else            showAlert('Error: ' + r.error, 'error');
        }

        async function savePermissions() {
            const staffId = document.getElementById('permStaffSelect').value;
            if (!staffId) { showAlert('Please select a staff member', 'error'); return; }

            const canTake = document.getElementById('permCanTake').checked ? 1 : 0;
            const canView = document.getElementById('permCanView').checked ? 1 : 0;
            const classes = [...document.querySelectorAll('.perm-class-cb:checked')].map(cb => cb.value);

            const result = await apiCall('save_staff_permissions', {
                staff_id:            staffId,
                can_take_attendance: canTake,
                can_view_reports:    canView,
                assigned_classes:    classes
            }, 'POST');

            const div = document.getElementById('permSaveResult');
            if (result.success) {
                div.innerHTML = `<div class="alert-success" style="padding:10px;border-radius:8px;">✅ ${result.message}</div>`;
                loadPermissions();
                clearPermForm();
            } else {
                div.innerHTML = `<div class="alert-danger" style="padding:10px;border-radius:8px;">❌ ${result.error}</div>`;
            }
            setTimeout(() => { div.innerHTML = ''; }, 4000);
        }

        function clearPermForm() {
            document.getElementById('permStaffSelect').value = '';
            document.getElementById('permCanTake').checked = true;
            document.getElementById('permCanView').checked = true;
            document.querySelectorAll('.perm-class-cb').forEach(cb => cb.checked = false);
        }

        // ==================== EVENT LISTENERS ====================
        document.getElementById('notificationBell').addEventListener('click', (e) => {
            e.stopPropagation();
            document.getElementById('notificationDropdown').classList.toggle('show');
            loadNotifications();
        });

        document.addEventListener('click', () => {
            document.getElementById('notificationDropdown').classList.remove('show');
        });

        document.getElementById('absentModal').addEventListener('click', (e) => {
            if (e.target === e.currentTarget) closeAbsentModal();
        });

        // ==================== INIT ====================
        loadUnreadCount();
        setInterval(loadUnreadCount, 30000);
        admLoadTodayStats();
        setInterval(() => {
            if (document.getElementById('tab-take_attendance')?.classList.contains('active')) {
                admLoadTodayStats();
                admLoadRecentScans();
            }
        }, 15000);

        // Set default duration
        const defaultBtn = document.querySelector('#qrDurationOptions .duration-btn[data-hours="72"]');
        if (defaultBtn) defaultBtn.classList.add('active');
    </script>
</body>

</html>
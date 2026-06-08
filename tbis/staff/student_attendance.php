<?php
// staff/student_attendance.php - Staff Take Student Attendance via QR Scan
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: /tbis/login.php");
    exit();
}

require_once '../includes/config.php';
require_once '../includes/notification_helper.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$staff_numeric_id = $_SESSION['user_id'];
$staff_name = $_SESSION['user_name'] ?? 'Staff Member';

// Get staff permissions and assigned classes
$stmt = $pdo->prepare("
    SELECT can_take_attendance, can_view_reports, assigned_classes 
    FROM attendance_permissions 
    WHERE staff_id = ? AND school_id = ?
");
$stmt->execute([$staff_numeric_id, $school_id]);
$permission = $stmt->fetch();

$can_take_attendance = $permission && $permission['can_take_attendance'];
$can_view_reports = $permission && $permission['can_view_reports'];

// If staff doesn't have permission, show error
if (!$can_take_attendance && !$can_view_reports) {
    $access_denied = true;
    $access_message = "You do not have permission to take student attendance. Please contact the administrator.";
} else {
    $access_denied = false;
}

// Get assigned class IDs
$assigned_class_ids = [];
if ($permission && $permission['assigned_classes']) {
    $assigned_class_ids = explode(',', $permission['assigned_classes']);
}

// Get classes for dropdown (filtered by permissions)
if (empty($assigned_class_ids)) {
    $stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? AND status = 'active' ORDER BY class_name");
    $stmt->execute([$school_id]);
} else {
    $placeholders = str_repeat('?,', count($assigned_class_ids) - 1) . '?';
    $stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE id IN ($placeholders) AND status = 'active' ORDER BY class_name");
    $stmt->execute($assigned_class_ids);
}
$classes = $stmt->fetchAll();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input) {
        $action = $input['action'] ?? $action;
    }
    
    switch ($action) {
        case 'record_student_attendance':
            if (!$can_take_attendance) {
                echo json_encode(['success' => false, 'error' => 'You do not have permission to take attendance']);
                exit();
            }
            
            $student_id = $_POST['student_id'] ?? $input['student_id'] ?? 0;
            $scan_type = $_POST['scan_type'] ?? $input['scan_type'] ?? 'check_in';
            $staff_id_string = $_POST['staff_id'] ?? $input['staff_id'] ?? null;
            
            if (!$student_id) {
                echo json_encode(['success' => false, 'error' => 'Invalid student ID']);
                exit();
            }
            
            // Get staff_id string if not provided
            if (!$staff_id_string) {
                $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ?");
                $stmt->execute([$staff_numeric_id]);
                $staff_id_string = $stmt->fetchColumn();
            }
            
            // Get student details
            $stmt = $pdo->prepare("
                SELECT s.*, c.class_name 
                FROM students s
                LEFT JOIN classes c ON s.class_id = c.id
                WHERE s.id = ? AND s.school_id = ? AND s.status = 'active'
            ");
            $stmt->execute([$student_id, $school_id]);
            $student = $stmt->fetch();
            
            if (!$student) {
                echo json_encode(['success' => false, 'error' => 'Student not found']);
                exit();
            }
            
            // Check if staff has permission for this student's class
            $has_permission = empty($assigned_class_ids) || in_array($student['class_id'], $assigned_class_ids);
            if (!$has_permission && !$can_take_attendance) {
                echo json_encode(['success' => false, 'error' => 'You do not have permission for this class']);
                exit();
            }
            
            // Check if already checked in today
            $today = date('Y-m-d');
            $stmt = $pdo->prepare("
                SELECT id FROM attendance_logs 
                WHERE student_id = ? AND school_id = ? 
                AND DATE(scan_time) = ? AND scan_type = 'check_in'
            ");
            $stmt->execute([$student_id, $school_id, $today]);
            $already_checked_in = $stmt->fetch();
            
            if ($already_checked_in && $scan_type === 'check_in') {
                echo json_encode([
                    'success' => false, 
                    'error' => $student['full_name'] . ' already checked in today',
                    'student_name' => $student['full_name']
                ]);
                exit();
            }
            
            // Determine status based on time (after 9:00 AM is late)
            $current_time = date('H:i:s');
            $status = 'present';
            if ($scan_type === 'check_in' && $current_time > '09:00:00') {
                $status = 'late';
            }
            
            // Record attendance
            $stmt = $pdo->prepare("
                INSERT INTO attendance_logs (school_id, student_id, staff_id, scan_time, scan_type, status, ip_address, created_at)
                VALUES (?, ?, ?, NOW(), ?, ?, ?, NOW())
            ");
            $stmt->execute([$school_id, $student_id, $staff_numeric_id, $scan_type, $status, $_SERVER['REMOTE_ADDR']]);
            
            // Update main attendance table
            $stmt = $pdo->prepare("
                INSERT INTO attendance (school_id, student_id, date, status, created_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE status = ?, created_at = NOW()
            ");
            $stmt->execute([$school_id, $student_id, $today, $status, $status]);
            
            // Create notification for admin
            $notification_type = $scan_type === 'check_in' ? 
                ($status === 'late' ? 'student_late' : 'student_clock_in') : 
                'student_clock_out';
            
            createAttendanceNotification(
                $pdo, $school_id,
                $notification_type,
                1, 'admin',
                $student_id, 'student',
                $student['full_name'],
                $student['class_name'],
                null, null, null,
                date('H:i:s'), $status,
                true, true  // send_push, send_email
            );
            
            echo json_encode([
                'success' => true,
                'student_name' => $student['full_name'],
                'status' => $status,
                'scan_type' => $scan_type,
                'message' => $scan_type === 'check_in' ? 'Checked In' : 'Checked Out'
            ]);
            break;
            
        case 'get_today_stats':
            $today = date('Y-m-d');
            
            if (empty($assigned_class_ids)) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(DISTINCT al.student_id) as present
                    FROM attendance_logs al
                    WHERE al.school_id = ? AND DATE(al.scan_time) = ? AND al.scan_type = 'check_in'
                ");
                $stmt->execute([$school_id, $today]);
                $present = $stmt->fetch()['present'];
                
                $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM students WHERE school_id = ? AND status = 'active'");
                $stmt->execute([$school_id]);
                $total = $stmt->fetch()['total'];
            } else {
                $placeholders = str_repeat('?,', count($assigned_class_ids) - 1) . '?';
                $stmt = $pdo->prepare("
                    SELECT COUNT(DISTINCT al.student_id) as present
                    FROM attendance_logs al
                    JOIN students s ON al.student_id = s.id
                    WHERE al.school_id = ? AND DATE(al.scan_time) = ? AND al.scan_type = 'check_in'
                    AND s.class_id IN ($placeholders)
                ");
                $stmt->execute(array_merge([$school_id, $today], $assigned_class_ids));
                $present = $stmt->fetch()['present'];
                
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as total 
                    FROM students 
                    WHERE school_id = ? AND status = 'active' AND class_id IN ($placeholders)
                ");
                $stmt->execute(array_merge([$school_id], $assigned_class_ids));
                $total = $stmt->fetch()['total'];
            }
            
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT al.student_id) as late
                FROM attendance_logs al
                JOIN students s ON al.student_id = s.id
                WHERE al.school_id = ? AND DATE(al.scan_time) = ? AND al.status = 'late'
            ");
            $stmt->execute([$school_id, $today]);
            $late = $stmt->fetch()['late'];
            
            $absent = max(0, $total - $present);
            
            echo json_encode([
                'success' => true,
                'present' => (int)$present,
                'late' => (int)$late,
                'absent' => (int)$absent,
                'total' => (int)$total
            ]);
            break;
            
        case 'get_recent_scans':
            $limit = $_GET['limit'] ?? 20;
            
            $query = "
                SELECT al.*, s.full_name, s.admission_number, c.class_name
                FROM attendance_logs al
                JOIN students s ON al.student_id = s.id
                LEFT JOIN classes c ON s.class_id = c.id
                WHERE al.school_id = ?
                ORDER BY al.scan_time DESC
                LIMIT ?
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$school_id, $limit]);
            $scans = $stmt->fetchAll();
            
            foreach ($scans as &$scan) {
                $scan['time_formatted'] = date('h:i A', strtotime($scan['scan_time']));
                $scan['date_formatted'] = date('M j, Y', strtotime($scan['scan_time']));
            }
            
            echo json_encode(['success' => true, 'scans' => $scans]);
            break;
            
        case 'get_class_students':
            $class_id = $_GET['class_id'] ?? 0;
            $date = $_GET['date'] ?? date('Y-m-d');
            
            if (!$class_id) {
                echo json_encode(['success' => false, 'error' => 'Class ID required']);
                exit();
            }
            
            // Check if staff has permission for this class
            $has_permission = empty($assigned_class_ids) || in_array($class_id, $assigned_class_ids);
            if (!$has_permission) {
                echo json_encode(['success' => false, 'error' => 'You do not have permission for this class']);
                exit();
            }
            
            $stmt = $pdo->prepare("
                SELECT s.id, s.full_name, s.admission_number, 
                       al.scan_type, al.status, al.scan_time,
                       CASE WHEN al.id IS NOT NULL THEN 'present' ELSE 'absent' END as attendance_status
                FROM students s
                LEFT JOIN attendance_logs al ON s.id = al.student_id 
                    AND DATE(al.scan_time) = ? AND al.scan_type = 'check_in'
                WHERE s.class_id = ? AND s.school_id = ? AND s.status = 'active'
                ORDER BY s.full_name
            ");
            $stmt->execute([$date, $class_id, $school_id]);
            $students = $stmt->fetchAll();
            
            echo json_encode(['success' => true, 'students' => $students, 'date' => $date]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    exit();
}

// Get today's stats for initial display
$today = date('Y-m-d');
if (empty($assigned_class_ids)) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM students WHERE school_id = ? AND status = 'active'");
    $stmt->execute([$school_id]);
    $total_students = $stmt->fetch()['total'];
} else {
    $placeholders = str_repeat('?,', count($assigned_class_ids) - 1) . '?';
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM students WHERE school_id = ? AND status = 'active' AND class_id IN ($placeholders)");
    $stmt->execute(array_merge([$school_id], $assigned_class_ids));
    $total_students = $stmt->fetch()['total'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title><?php echo htmlspecialchars($school_name); ?> - Student Attendance</title>
    
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        /* Page-specific styles only */
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: 14px;
            padding: 16px;
            text-align: center;
            box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05);
            border: 1px solid #e5e7eb;
        }
        
        .stat-number {
            font-size: 1.8rem;
            font-weight: 800;
            color: <?php echo $primary_color; ?>;
            line-height: 1;
        }
        
        .stat-label {
            font-size: 0.7rem;
            color: #6b7280;
            margin-top: 4px;
        }
        
        .card {
            background: white;
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05);
            border: 1px solid #e5e7eb;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .card-header h2 {
            font-size: 1rem;
            font-weight: 600;
            color: #1f2937;
        }
        
        .card-header h2 i {
            color: <?php echo $primary_color; ?>;
            margin-right: 8px;
        }
        
        .btn {
            padding: 12px 20px;
            border-radius: 10px;
            border: none;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            width: 100%;
            font-family: inherit;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: <?php echo $primary_color; ?>;
            color: white;
        }
        
        .btn-primary:active {
            transform: scale(0.97);
        }
        
        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        
        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 16px;
        }
        
        .scanner-container {
            background: #111827;
            border-radius: 14px;
            overflow: hidden;
            min-height: 400px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        #qr-reader {
            width: 100%;
            min-height: 400px;
            display: none;
        }
        
        #qr-reader.active {
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
        
        .scan-type-selector {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
        }
        
        .scan-type-btn {
            flex: 1;
            padding: 12px;
            border: 2px solid #e5e7eb;
            background: white;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }
        
        .scan-type-btn.active {
            border-color: <?php echo $primary_color; ?>;
            background: <?php echo $primary_color; ?>;
            color: white;
        }
        
        .stop-camera-btn {
            background: #ef4444;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            margin-top: 12px;
            width: 100%;
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
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            padding: 12px;
            border-radius: 10px;
            margin-top: 12px;
        }
        
        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            border-radius: 10px;
            margin-top: 12px;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.75rem;
        }
        
        .data-table th, .data-table td {
            padding: 10px 8px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .data-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #6b7280;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.65rem;
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
        
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            font-size: 0.75rem;
            color: #4b5563;
        }
        
        .form-select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-family: inherit;
            font-size: 0.85rem;
        }
        
        @media (max-width: 767px) {
            .container {
                padding: 0;
            }
            
            .stats-grid {
                gap: 8px;
            }
            
            .stat-number {
                font-size: 1.3rem;
            }
            
            .card {
                padding: 16px;
            }
            
            .scan-type-btn {
                font-size: 0.7rem;
                padding: 10px;
            }
        }
    </style>
</head>
<body>

<div class="main-content">
    <div class="container">
        <!-- Stats Grid -->
        <div class="stats-grid" id="statsGrid">
            <div class="stat-card">
                <div class="stat-number" id="presentCount">-</div>
                <div class="stat-label">Present Today</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="lateCount">-</div>
                <div class="stat-label">Late</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="absentCount">-</div>
                <div class="stat-label">Absent</div>
            </div>
        </div>
        
        <!-- Scanner Card -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-qrcode"></i> Scan Student QR Code</h2>
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
                <div id="qr-reader"></div>
                <div id="scannerPlaceholder" class="scanner-placeholder">
                    <i class="fas fa-camera"></i>
                    <p>Camera is off</p>
                    <button class="btn btn-primary" onclick="startScanner()">
                        <i class="fas fa-video"></i> Start Camera
                    </button>
                </div>
            </div>
            <div id="scanStatus" style="margin-top: 12px; text-align: center; font-size: 12px; color: #6b7280;">
                <span class="live-indicator"></span> Click "Start Camera" to begin scanning
            </div>
            <div id="scanResult"></div>
        </div>
        
        <!-- Class Filter -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-filter"></i> Filter by Class</h2>
            </div>
            <div class="form-group">
                <label>Select Class</label>
                <select id="classFilter" class="form-select" onchange="loadClassStudents()">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <!-- Today's Attendance List -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-list"></i> Today's Attendance</h2>
                <button class="btn btn-secondary" style="width: auto; padding: 6px 12px;" onclick="loadClassStudents()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>S/N</th>
                            <th>Admission No</th>
                            <th>Student Name</th>
                            <th>Status</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody id="attendanceListBody">
                        <tr><td colspan="5" style="text-align:center;">Select a class to view</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Recent Scans -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-clock"></i> Recent Scans</h2>
                <button class="btn btn-secondary" style="width: auto; padding: 6px 12px;" onclick="loadRecentScans()">
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
                        <tr><td colspan="5" style="text-align:center;">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Include staff sidebar -->
<?php require_once 'includes/staff_sidebar.php'; ?>

<script>
    let html5QrcodeScanner = null;
    let currentScanType = 'check_in';
    let isScannerActive = false;
    let canTakeAttendance = <?php echo $can_take_attendance ? 'true' : 'false'; ?>;
    
    <?php if ($access_denied): ?>
        alert('<?php echo addslashes($access_message); ?>');
    <?php endif; ?>
    
    function setScanType(type) {
        if (!canTakeAttendance) {
            alert('You do not have permission to take attendance');
            return;
        }
        currentScanType = type;
        document.querySelectorAll('.scan-type-btn').forEach(btn => btn.classList.remove('active'));
        event.target.classList.add('active');
    }
    
    function startScanner() {
        if (!canTakeAttendance) {
            alert('You do not have permission to take attendance');
            return;
        }
        
        if (html5QrcodeScanner && isScannerActive) {
            showFeedback('Camera is already active', 'info');
            return;
        }
        
        document.getElementById('scannerPlaceholder').style.display = 'none';
        document.getElementById('qr-reader').classList.add('active');
        document.getElementById('qr-reader').style.display = 'block';
        
        html5QrcodeScanner = new Html5Qrcode("qr-reader");
        html5QrcodeScanner.start({
            facingMode: "environment"
        }, {
            fps: 10,
            qrbox: { width: 250, height: 250 }
        },
        (decodedText) => {
            handleScan(decodedText);
        },
        (error) => {
            // Silent error handling
        }
        ).then(() => {
            isScannerActive = true;
            document.getElementById('scanStatus').innerHTML = '<span class="live-indicator"></span> Camera active - Ready to scan student QR codes';
            
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
            document.getElementById('qr-reader').style.display = 'none';
        });
    }
    
    function stopScanner() {
        if (html5QrcodeScanner && isScannerActive) {
            html5QrcodeScanner.stop().then(() => {
                isScannerActive = false;
                document.getElementById('qr-reader').style.display = 'none';
                document.getElementById('qr-reader').classList.remove('active');
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
                recordStudentAttendance(studentId);
            } else {
                showFeedback('Invalid QR code format', 'error');
            }
        } catch (e) {
            showFeedback('Invalid QR code', 'error');
        }
    }
    
    function recordStudentAttendance(studentId) {
        const formData = new URLSearchParams();
        formData.append('action', 'record_student_attendance');
        formData.append('student_id', studentId);
        formData.append('scan_type', currentScanType);
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const emoji = currentScanType === 'check_in' ? '✅' : '👋';
                showFeedback(`${emoji} ${data.student_name} - ${data.message} (${data.status === 'late' ? 'Late' : 'Present'})`, 'success');
                loadTodayStats();
                loadRecentScans();
                loadClassStudents();
            } else {
                showFeedback(`❌ ${data.error}`, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showFeedback('Network error. Please check your connection.', 'error');
        });
    }
    
    function showFeedback(message, type) {
        const resultDiv = document.getElementById('scanResult');
        if (type === 'success') {
            resultDiv.innerHTML = `<div class="alert-success">${message}</div>`;
        } else if (type === 'error') {
            resultDiv.innerHTML = `<div class="alert-danger">${message}</div>`;
        }
        
        setTimeout(() => {
            resultDiv.innerHTML = '';
        }, 3000);
    }
    
    function loadTodayStats() {
        fetch(window.location.href + '?action=get_today_stats', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('presentCount').innerText = data.present || 0;
                document.getElementById('lateCount').innerText = data.late || 0;
                document.getElementById('absentCount').innerText = data.absent || 0;
            }
        })
        .catch(error => console.error('Error loading stats:', error));
    }
    
    function loadRecentScans() {
        fetch(window.location.href + '?action=get_recent_scans&limit=10', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
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
                            <td>${scan.time_formatted}</td>
                            <td><strong>${escapeHtml(scan.full_name)}</strong></td>
                            <td>${escapeHtml(scan.admission_number)}</td>
                            <td><span class="status-badge">${scan.scan_type === 'check_in' ? 'In' : 'Out'}</span></td>
                            <td><span class="status-badge status-${scan.status}">${scan.status === 'late' ? 'Late' : 'Present'}</span></td>
                        </tr>
                    `;
                });
            }
        })
        .catch(error => console.error('Error loading scans:', error));
    }
    
    function loadClassStudents() {
        const classId = document.getElementById('classFilter').value;
        if (!classId) {
            document.getElementById('attendanceListBody').innerHTML = '<tr><td colspan="5" style="text-align:center;">Select a class to view</td></tr>';
            return;
        }
        
        const today = new Date().toISOString().split('T')[0];
        
        fetch(window.location.href + `?action=get_class_students&class_id=${classId}&date=${today}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const tbody = document.getElementById('attendanceListBody');
                if (!data.students || data.students.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">No students found in this class</td></tr>';
                    return;
                }
                
                let sn = 1;
                tbody.innerHTML = '';
                data.students.forEach(student => {
                    const status = student.attendance_status;
                    const time = student.scan_time ? new Date(student.scan_time).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) : '-';
                    
                    tbody.innerHTML += `
                        <tr>
                            <td>${sn++}</td>
                            <td>${escapeHtml(student.admission_number)}</td>
                            <td><strong>${escapeHtml(student.full_name)}</strong></td>
                            <td><span class="status-badge status-${status === 'present' ? (student.status === 'late' ? 'late' : 'present') : 'absent'}">
                                ${status === 'present' ? (student.status === 'late' ? 'Late' : 'Present') : 'Absent'}
                            </span></td>
                            <td>${time}</td>
                        </tr>
                    `;
                });
            }
        })
        .catch(error => console.error('Error loading class students:', error));
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Auto-refresh every 10 seconds
    setInterval(() => {
        loadTodayStats();
        loadRecentScans();
        if (document.getElementById('classFilter').value) {
            loadClassStudents();
        }
    }, 10000);
    
    // Initial loads
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
<?php
// staff/attendance.php - Staff Self Attendance with Self Clock In/Out & Friend Marking
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: /gsa/login.php");
    exit();
}

require_once '../includes/config.php';
require_once '../includes/qr_helper.php';
require_once '../includes/notification_helper.php';
require_once '../includes/image_helper.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$staff_numeric_id = $_SESSION['user_id'];
$staff_name = $_SESSION['user_name'] ?? 'Staff Member';

// Get staff permissions
$stmt = $pdo->prepare("
    SELECT can_take_attendance, can_view_reports, assigned_classes 
    FROM attendance_permissions 
    WHERE staff_id = ? AND school_id = ?
");
$stmt->execute([$staff_numeric_id, $school_id]);
$permission = $stmt->fetch();

$can_take_attendance = $permission && $permission['can_take_attendance'];
$can_view_reports = $permission && $permission['can_view_reports'];

// Get assigned class IDs
$assigned_class_ids = [];
if ($permission && $permission['assigned_classes']) {
    $assigned_class_ids = explode(',', $permission['assigned_classes']);
}

// Get classes for dropdown
if (empty($assigned_class_ids)) {
    $stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? AND status = 'active' ORDER BY class_name");
    $stmt->execute([$school_id]);
} else {
    $placeholders = str_repeat('?,', count($assigned_class_ids) - 1) . '?';
    $stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE id IN ($placeholders) AND status = 'active' ORDER BY class_name");
    $stmt->execute($assigned_class_ids);
}
$classes = $stmt->fetchAll();

// Handle API requests (AJAX)
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && (
    $_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['action'])
)) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input) {
        $action = $input['action'] ?? $action;
    }
    
    switch ($action) {
        case 'clock_self':
            $scan_type = $_POST['scan_type'] ?? $input['scan_type'] ?? 'check_in';
            $current_time = date('H:i:s');
            $current_date = date('Y-m-d');
            
            // Check if already clocked in today
            if ($scan_type === 'check_in') {
                $stmt = $pdo->prepare("SELECT id FROM staff_attendance WHERE staff_id = ? AND date = ? AND school_id = ?");
                $stmt->execute([$staff_numeric_id, $current_date, $school_id]);
                if ($stmt->fetch()) {
                    echo json_encode(['success' => false, 'error' => 'Already clocked in today']);
                    exit();
                }
            }
            
            // Check if already clocked out for check_out
            if ($scan_type === 'check_out') {
                $stmt = $pdo->prepare("SELECT clock_out FROM staff_attendance WHERE staff_id = ? AND date = ? AND school_id = ?");
                $stmt->execute([$staff_numeric_id, $current_date, $school_id]);
                $record = $stmt->fetch();
                if (!$record) {
                    echo json_encode(['success' => false, 'error' => 'Not clocked in yet']);
                    exit();
                }
                if ($record['clock_out']) {
                    echo json_encode(['success' => false, 'error' => 'Already clocked out today']);
                    exit();
                }
            }
            
            $status = 'present';
            if ($scan_type === 'check_in' && $current_time > '09:00:00') {
                $status = 'late';
            }
            
            if ($scan_type === 'check_in') {
                $stmt = $pdo->prepare("
                    INSERT INTO staff_attendance (staff_id, school_id, date, clock_in, status, attendance_source, created_at)
                    VALUES (?, ?, ?, ?, ?, 'self_scan', NOW())
                ");
                $stmt->execute([$staff_numeric_id, $school_id, $current_date, $current_time, $status]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE staff_attendance SET clock_out = ? 
                    WHERE staff_id = ? AND date = ? AND school_id = ?
                ");
                $stmt->execute([$current_time, $staff_numeric_id, $current_date, $school_id]);
            }
            
            // Create notification for admin
            if (function_exists('createAttendanceNotification')) {
                createAttendanceNotification(
                    $pdo, $school_id,
                    $scan_type === 'check_in' ? 'staff_clock_in' : 'staff_clock_out',
                    1, 'admin',
                    $staff_numeric_id, 'staff',
                    $staff_name, null, null, null, null,
                    $status, true, false
                );
            }
            
            echo json_encode(['success' => true, 'message' => ucfirst($scan_type) . ' successful', 'status' => $status]);
            break;
            
        case 'mark_friend':
            $friend_staff_numeric_id = $_POST['friend_staff_id'] ?? $input['friend_staff_id'] ?? '';
            $proof_photo = null;
            
            // Handle photo upload
            if (isset($_FILES['proof_photo']) && $_FILES['proof_photo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/gsa/uploads/attendance_proofs/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                if (function_exists('uploadAndCompressImage')) {
                    $result = uploadAndCompressImage($_FILES['proof_photo'], $upload_dir, 'friend_proof_', 100);
                    if ($result['success']) {
                        $proof_photo = $result['path'];
                    }
                } else {
                    // Simple upload without compression
                    $filename = 'friend_proof_' . time() . '_' . rand(1000, 9999) . '.jpg';
                    $target_path = $upload_dir . $filename;
                    if (move_uploaded_file($_FILES['proof_photo']['tmp_name'], $target_path)) {
                        $proof_photo = '/gsa/uploads/attendance_proofs/' . $filename;
                    }
                }
            } elseif (isset($input['proof_photo_base64'])) {
                $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/gsa/uploads/attendance_proofs/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                if (function_exists('uploadBase64Image')) {
                    $result = uploadBase64Image($input['proof_photo_base64'], $upload_dir, 'friend_proof_', 100);
                    if ($result['success']) {
                        $proof_photo = $result['path'];
                    }
                }
            }
            
            if (!$proof_photo) {
                echo json_encode(['success' => false, 'error' => 'Photo proof required']);
                exit();
            }
            
            // Verify friend staff exists (using numeric ID)
            $stmt = $pdo->prepare("SELECT id, full_name FROM staff WHERE id = ? AND school_id = ? AND is_active = 1");
            $stmt->execute([$friend_staff_numeric_id, $school_id]);
            $friend = $stmt->fetch();
            
            if (!$friend) {
                echo json_encode(['success' => false, 'error' => 'Staff member not found. Please enter numeric Staff ID.']);
                exit();
            }
            
            $current_date = date('Y-m-d');
            $current_time = date('H:i:s');
            $current_staff_numeric_id = $_SESSION['user_id'];
            $current_staff_name = $_SESSION['user_name'] ?? 'Staff Member';
            
            // Check if friend already clocked in
            $stmt = $pdo->prepare("SELECT id FROM staff_attendance WHERE staff_id = ? AND date = ? AND school_id = ?");
            $stmt->execute([$friend['id'], $current_date, $school_id]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => $friend['full_name'] . ' already clocked in today']);
                exit();
            }
            
            // Record friend's attendance
            $status = $current_time > '09:00:00' ? 'late' : 'present';
            $stmt = $pdo->prepare("
                INSERT INTO staff_attendance (staff_id, school_id, date, clock_in, status, marked_by, marked_by_name, proof_photo, attendance_source, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'friend_marked', NOW())
            ");
            $stmt->execute([
                $friend['id'], $school_id, $current_date, $current_time, $status,
                $current_staff_numeric_id, $current_staff_name, $proof_photo
            ]);
            
            // Create notification for admin with photo proof
            if (function_exists('createAttendanceNotification')) {
                createAttendanceNotification(
                    $pdo, $school_id,
                    'friend_marked',
                    1, 'admin',
                    $friend['id'], 'staff',
                    $friend['full_name'], null,
                    $current_staff_numeric_id, $current_staff_name,
                    $proof_photo, $current_time, $status,
                    true, false
                );
            }
            
            echo json_encode(['success' => true, 'message' => 'Marked attendance for ' . $friend['full_name']]);
            break;
            
        case 'get_my_attendance':
            // Simple query using numeric ID
            $stmt = $pdo->prepare("
                SELECT 
                    staff_id,
                    date,
                    clock_in,
                    clock_out,
                    status,
                    attendance_source,
                    marked_by_name,
                    proof_photo,
                    DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as created_at
                FROM staff_attendance
                WHERE staff_id = ? AND school_id = ?
                ORDER BY date DESC
                LIMIT 30
            ");
            $stmt->execute([$staff_numeric_id, $school_id]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'history' => $history]);
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
                
                $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM students WHERE school_id = ? AND status = 'active' AND class_id IN ($placeholders)");
                $stmt->execute(array_merge([$school_id], $assigned_class_ids));
                $total = $stmt->fetch()['total'];
            }
            
            $absent = max(0, $total - $present);
            
            echo json_encode(['success' => true, 'present' => $present, 'absent' => $absent, 'total' => $total]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    exit();
}

// Get today's staff attendance status for current staff
$stmt = $pdo->prepare("
    SELECT sa.* FROM staff_attendance sa
    WHERE sa.staff_id = ? AND sa.date = CURDATE() AND sa.school_id = ?
");
$stmt->execute([$staff_numeric_id, $school_id]);
$today_attendance = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title><?php echo htmlspecialchars($school_name); ?> - Staff Attendance</title>
    
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        /* Page-specific styles only - sidebar styles are in staff_sidebar.php */
        .container {
            max-width: 800px;
            margin: 0 auto;
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
        
        .status-card {
            text-align: center;
            padding: 24px;
            background: linear-gradient(135deg, <?php echo $primary_color; ?>, #1a3a5c);
            color: white;
            border-radius: 14px;
            margin-bottom: 20px;
        }
        
        .status-time {
            font-size: 2rem;
            font-weight: 700;
        }
        
        .status-label {
            font-size: 0.8rem;
            opacity: 0.9;
            margin-top: 4px;
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
        
        .btn-success {
            background: #10b981;
            color: white;
        }
        
        .btn-warning {
            background: #f59e0b;
            color: white;
        }
        
        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        
        .btn-danger {
            background: #ef4444;
            color: white;
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
        
        .form-control, .form-select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-family: inherit;
            font-size: 0.85rem;
        }
        
        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: <?php echo $primary_color; ?>;
        }
        
        .camera-preview {
            width: 100%;
            height: 200px;
            background: #f3f4f6;
            border-radius: 10px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        
        .camera-preview video, .camera-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
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
        
        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            background: white;
            padding: 6px;
            border-radius: 14px;
        }
        
        .tab-btn {
            flex: 1;
            padding: 10px;
            background: none;
            border: none;
            font-weight: 600;
            font-size: 0.8rem;
            border-radius: 10px;
            cursor: pointer;
            font-family: inherit;
            color: #6b7280;
        }
        
        .tab-btn.active {
            background: <?php echo $primary_color; ?>;
            color: white;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            padding: 12px;
            border-radius: 10px;
        }
        
        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            border-radius: 10px;
        }
        
        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 16px;
        }
        
        .staff-clock-section {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .clock-btn {
            padding: 20px;
            font-size: 1.2rem;
            margin: 0 10px;
            width: auto;
            min-width: 150px;
        }
        
        .info-text {
            font-size: 0.7rem;
            color: #6b7280;
            text-align: center;
            margin-top: 8px;
        }
        
        @media (max-width: 767px) {
            .container {
                padding: 0;
            }
            
            .card {
                padding: 16px;
            }
            
            .tabs {
                flex-wrap: wrap;
            }
            
            .tab-btn {
                font-size: 0.7rem;
                padding: 8px;
            }
            
            .status-time {
                font-size: 1.5rem;
            }
            
            .clock-btn {
                padding: 15px;
                font-size: 1rem;
                min-width: 120px;
            }
        }
    </style>
</head>
<body>

<!-- Main content wrapper - staff_sidebar.php will add the sidebar -->
<div class="main-content">
    <div class="container">
        <!-- Status Card -->
        <div class="status-card">
            <div class="status-time" id="currentTime">--:-- --</div>
            <div class="status-label" id="attendanceStatus">
                <?php if ($today_attendance && $today_attendance['clock_in']): ?>
                    Clocked in at <?php echo date('g:i A', strtotime($today_attendance['clock_in'])); ?>
                    <?php if ($today_attendance['clock_out']): ?>
                        <br>Clocked out at <?php echo date('g:i A', strtotime($today_attendance['clock_out'])); ?>
                    <?php else: ?>
                        <br>Not clocked out yet
                    <?php endif; ?>
                <?php else: ?>
                    Not clocked in today
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Staff Self Clock In/Out Section -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-clock"></i> My Attendance</h2>
            </div>
            <div class="staff-clock-section">
                <?php if (!$today_attendance || !$today_attendance['clock_in']): ?>
                    <button class="btn btn-primary clock-btn" onclick="recordSelfAttendance('check_in')">
                        <i class="fas fa-sign-in-alt"></i> Clock In
                    </button>
                <?php elseif ($today_attendance['clock_in'] && !$today_attendance['clock_out']): ?>
                    <button class="btn btn-warning clock-btn" onclick="recordSelfAttendance('check_out')">
                        <i class="fas fa-sign-out-alt"></i> Clock Out
                    </button>
                <?php else: ?>
                    <div class="alert-success" style="padding: 12px; text-align: center;">
                        <i class="fas fa-check-circle"></i> Completed for today
                    </div>
                <?php endif; ?>
            </div>
            <div id="selfClockResult" style="margin-top: 12px;"></div>
        </div>
        
        <!-- Tabs -->
        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('scan')">📷 Scan Student QR</button>
            <button class="tab-btn" onclick="switchTab('friend')">👥 Mark Friend</button>
            <button class="tab-btn" onclick="switchTab('history')">📜 My History</button>
        </div>
        
        <!-- Tab 1: Scan Student QR -->
        <div id="tab-scan" class="tab-content active">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-qrcode"></i> Scan Student QR Code</h2>
                </div>
                <div id="studentScannerContainer" style="display: none;">
                    <div id="qr-reader" style="width: 100%;"></div>
                </div>
                <div class="btn-group">
                    <button class="btn btn-primary" id="startScannerBtn" onclick="startStudentScanner()">
                        <i class="fas fa-camera"></i> Start Camera
                    </button>
                    <button class="btn btn-secondary" id="stopScannerBtn" onclick="stopStudentScanner()" style="display: none;">
                        <i class="fas fa-stop"></i> Stop Camera
                    </button>
                </div>
                <div id="scanResult" style="margin-top: 16px;"></div>
                <div class="info-text">
                    <i class="fas fa-info-circle"></i> Scan the student's QR code to mark their attendance
                </div>
            </div>
        </div>
        
        <!-- Tab 2: Mark Friend -->
        <div id="tab-friend" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-user-friends"></i> Mark Attendance for Colleague</h2>
                </div>
                <div class="form-group">
                    <label>Colleague's Numeric Staff ID</label>
                    <input type="number" id="friendStaffId" class="form-control" placeholder="Enter numeric staff ID (e.g., 1, 2, 3)">
                    <div class="info-text" style="margin-top: 5px;">
                        <i class="fas fa-info-circle"></i> Use the numeric ID, not the staff code (e.g., use "1" not "gsa0030")
                    </div>
                </div>
                <div class="form-group">
                    <label>Take Photo Proof</label>
                    <div class="camera-preview" id="friendCameraPreview">
                        <i class="fas fa-camera" style="font-size: 2rem; color: #9ca3af;"></i>
                    </div>
                    <button class="btn btn-secondary" id="openFriendCameraBtn" style="margin-top: 8px;">
                        <i class="fas fa-camera"></i> Take Photo
                    </button>
                    <input type="file" id="friendPhotoInput" accept="image/*" capture="environment" style="display: none;">
                </div>
                <button class="btn btn-primary" id="markFriendBtn" onclick="markFriendAttendance()">
                    <i class="fas fa-user-check"></i> Mark Attendance
                </button>
            </div>
        </div>
        
        <!-- Tab 3: History -->
        <div id="tab-history" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-history"></i> My Attendance History</h2>
                    <button class="btn btn-secondary" style="width: auto; padding: 6px 12px;" onclick="loadMyHistory()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr><th>Date</th><th>Clock In</th><th>Clock Out</th><th>Status</th><th>Source</th></tr>
                        </thead>
                        <tbody id="historyBody">
                            <tr><td colspan="5" style="text-align:center;">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Include staff sidebar at the bottom -->
<?php require_once 'includes/staff_sidebar.php'; ?>

<script>
    let html5QrScanner = null;
    let isScannerActive = false;
    let friendPhotoData = null;
    
    // Update current time
    function updateTime() {
        const now = new Date();
        document.getElementById('currentTime').innerText = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    }
    updateTime();
    setInterval(updateTime, 1000);
    
    // Tab switching
    function switchTab(tabName) {
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
        event.target.classList.add('active');
        document.getElementById(`tab-${tabName}`).classList.add('active');
        
        if (tabName === 'history') {
            loadMyHistory();
        }
    }
    
    // Self Attendance Recording
    function recordSelfAttendance(scanType) {
        const formData = new URLSearchParams();
        formData.append('action', 'clock_self');
        formData.append('scan_type', scanType);
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.json())
        .then(data => {
            const resultDiv = document.getElementById('selfClockResult');
            if (data.success) {
                resultDiv.innerHTML = `<div class="alert-success">✅ ${data.message}</div>`;
                setTimeout(() => location.reload(), 2000);
            } else {
                resultDiv.innerHTML = `<div class="alert-danger">❌ ${data.error}</div>`;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('selfClockResult').innerHTML = `<div class="alert-danger">❌ Network error. Please try again.</div>`;
        });
    }
    
    // Student QR Scanner
    function startStudentScanner() {
        const scannerContainer = document.getElementById('studentScannerContainer');
        scannerContainer.style.display = 'block';
        document.getElementById('startScannerBtn').style.display = 'none';
        document.getElementById('stopScannerBtn').style.display = 'inline-block';
        
        html5QrScanner = new Html5Qrcode("qr-reader");
        html5QrScanner.start(
            { facingMode: "environment" },
            { fps: 10, qrbox: { width: 250, height: 250 } },
            (decodedText) => handleStudentScan(decodedText),
            (error) => {
                // Silent error - scanning in progress
                if (error && error.includes('NotFoundException')) {
                    // This is normal when no QR is found
                }
            }
        ).then(() => {
            isScannerActive = true;
        }).catch(err => {
            alert("Camera access denied or not supported. Please grant permissions.");
            stopStudentScanner();
        });
    }
    
    function stopStudentScanner() {
        if (html5QrScanner && isScannerActive) {
            html5QrScanner.stop().then(() => {
                isScannerActive = false;
                document.getElementById('studentScannerContainer').style.display = 'none';
                document.getElementById('startScannerBtn').style.display = 'inline-block';
                document.getElementById('stopScannerBtn').style.display = 'none';
            }).catch(err => {
                console.error('Error stopping scanner:', err);
            });
        }
    }
    
    function handleStudentScan(decodedText) {
        try {
            const data = JSON.parse(decodedText);
            if (data.type === 'student_attendance') {
                stopStudentScanner();
                recordStudentAttendance(data.student_id);
            } else {
                alert("Invalid QR code. Please scan a Student QR code.");
            }
        } catch (e) {
            // Try to see if it's just a student ID
            if (decodedText.match(/^[0-9]+$/)) {
                stopStudentScanner();
                recordStudentAttendance(decodedText);
            } else {
                alert("Invalid QR code format. Please scan a valid Student QR code.");
            }
        }
    }
    
    function recordStudentAttendance(studentId) {
        fetch('/gsa/staff/attendance_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                action: 'record_attendance',
                student_id: studentId,
                scan_type: 'check_in'
            })
        })
        .then(response => response.json())
        .then(data => {
            const resultDiv = document.getElementById('scanResult');
            if (data.success) {
                resultDiv.innerHTML = `<div class="alert-success">✅ ${data.student_name} checked in successfully at ${data.time}</div>`;
                setTimeout(() => {
                    resultDiv.innerHTML = '';
                }, 3000);
            } else {
                resultDiv.innerHTML = `<div class="alert-danger">❌ ${data.error}</div>`;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('scanResult').innerHTML = `<div class="alert-danger">❌ Network error. Please try again.</div>`;
        });
    }
    
    // Friend marking with camera
    document.getElementById('openFriendCameraBtn').addEventListener('click', function() {
        const input = document.getElementById('friendPhotoInput');
        input.click();
    });
    
    document.getElementById('friendPhotoInput').addEventListener('change', function(e) {
        if (e.target.files && e.target.files[0]) {
            const file = e.target.files[0];
            const reader = new FileReader();
            reader.onload = function(event) {
                const preview = document.getElementById('friendCameraPreview');
                preview.innerHTML = `<img src="${event.target.result}" style="width:100%; height:100%; object-fit:cover;">`;
                friendPhotoData = event.target.result;
            };
            reader.readAsDataURL(file);
        }
    });
    
    function markFriendAttendance() {
        const friendStaffId = document.getElementById('friendStaffId').value;
        if (!friendStaffId) {
            alert('Please enter colleague\'s numeric Staff ID');
            return;
        }
        
        // Validate it's a number
        if (isNaN(friendStaffId)) {
            alert('Please enter a valid numeric Staff ID');
            return;
        }
        
        if (!friendPhotoData) {
            alert('Please take a photo proof');
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'mark_friend');
        formData.append('friend_staff_id', friendStaffId);
        
        // Convert base64 to blob
        fetch(friendPhotoData)
            .then(res => res.blob())
            .then(blob => {
                formData.append('proof_photo', blob, 'proof.jpg');
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('✅ ' + data.message);
                        document.getElementById('friendStaffId').value = '';
                        document.getElementById('friendCameraPreview').innerHTML = '<i class="fas fa-camera" style="font-size: 2rem; color: #9ca3af;"></i>';
                        friendPhotoData = null;
                        loadMyHistory(); // Refresh history
                    } else {
                        alert('❌ ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('❌ Network error. Please try again.');
                });
            });
    }
    
    function loadMyHistory() {
        const historyBody = document.getElementById('historyBody');
        historyBody.innerHTML = '<tr><td colspan="5" style="text-align:center;">Loading...</td></tr>';
        
        fetch(window.location.href + '?action=get_my_attendance', {
            headers: { 
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            const tbody = document.getElementById('historyBody');
            if (data.success && data.history && data.history.length > 0) {
                tbody.innerHTML = data.history.map(h => {
                    let statusClass = h.status === 'late' ? '#fed7aa' : '#d1fae5';
                    let statusText = h.status === 'late' ? 'Late' : (h.status === 'present' ? 'Present' : h.status);
                    let sourceText = 'Manual';
                    if (h.attendance_source === 'self_scan') sourceText = 'Self';
                    else if (h.attendance_source === 'friend_marked') sourceText = `By ${h.marked_by_name || 'Friend'}`;
                    
                    return `
                        <tr>
                            <td>${new Date(h.date + 'T00:00:00').toLocaleDateString()}</td>
                            <td>${h.clock_in ? new Date('1970-01-01T' + h.clock_in).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) : '—'}</td>
                            <td>${h.clock_out ? new Date('1970-01-01T' + h.clock_out).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) : '—'}</td>
                            <td><span style="background:${statusClass}; padding:2px 8px; border-radius:20px;">${statusText}</span></td>
                            <td>${sourceText}</td>
                        </tr>
                    `;
                }).join('');
            } else {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">No attendance records found. Try clocking in first!</td></tr>';
            }
        })
        .catch(error => {
            console.error('Error loading history:', error);
            document.getElementById('historyBody').innerHTML = '<tr><td colspan="5" style="text-align:center;">Error loading history. Please try again.</td></td>';
        });
    }
    
    // Initial load
    loadMyHistory();
</script>
</body>
</html>
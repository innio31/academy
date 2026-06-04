<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole(['staff']);

$course_id = $_GET['course_id'] ?? 0;
$date = $_GET['date'] ?? date('Y-m-d');
$success = '';
$error = '';
$scanned_student = null;

// Get staff's assigned courses from course_offerings
$stmt = $pdo->prepare("
    SELECT 
        c.*, 
        d.name as department_name,
        co.id as offering_id,
        s.name as semester_name,
        COUNT(DISTINCT scr.student_id) as enrolled_students
    FROM course_offerings co
    JOIN courses c ON co.course_id = c.id
    JOIN departments d ON c.department_id = d.id
    JOIN semesters s ON co.semester_id = s.id
    LEFT JOIN student_course_registrations scr ON co.id = scr.offering_id AND scr.status = 'registered'
    WHERE co.lecturer_id = ? AND s.is_current = 1
    GROUP BY c.id, co.id
    ORDER BY c.code
");
$stmt->execute([$_SESSION['user_id']]);
$courses = $stmt->fetchAll();

// If course selected, get enrolled students
$enrolled_students = [];
$attendance_taken = [];
$offering_id = 0;

if ($course_id) {
    // Get course details
    $stmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
    $stmt->execute([$course_id]);
    $course = $stmt->fetch();
    
    // First get the offering_id for this course
    $stmt = $pdo->prepare("
        SELECT co.id as offering_id
        FROM course_offerings co
        WHERE co.course_id = ? AND co.lecturer_id = ?
        LIMIT 1
    ");
    $stmt->execute([$course_id, $_SESSION['user_id']]);
    $offering = $stmt->fetch();
    $offering_id = $offering ? $offering['offering_id'] : 0;
    
    // Get enrolled students for this course offering
    if ($offering_id) {
        $stmt = $pdo->prepare("
            SELECT 
                s.id, s.reg_number,
                CONCAT(u.first_name, ' ', u.last_name) as student_name,
                u.email
            FROM student_course_registrations scr
            JOIN students s ON scr.student_id = s.id
            JOIN users u ON s.user_id = u.id
            WHERE scr.offering_id = ? AND scr.status = 'registered'
            ORDER BY u.last_name
        ");
        $stmt->execute([$offering_id]);
        $enrolled_students = $stmt->fetchAll();
    }
    
    // Get today's attendance already taken
    $stmt = $pdo->prepare("
        SELECT student_id, status 
        FROM attendance 
        WHERE course_id = ? AND date = ?
    ");
    $stmt->execute([$course_id, $date]);
    foreach($stmt->fetchAll() as $att) {
        $attendance_taken[$att['student_id']] = $att['status'];
    }
}

// Handle QR scan submission (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qr_data'])) {
    header('Content-Type: application/json');
    
    $qr_data = json_decode($_POST['qr_data'], true);
    $student_id = $qr_data['student_id'] ?? 0;
    $reg_number = $qr_data['reg_number'] ?? '';
    $status = $_POST['status'] ?? 'present';
    
    if (!$student_id && $reg_number) {
        // Find student by registration number
        $stmt = $pdo->prepare("SELECT id FROM students WHERE reg_number = ?");
        $stmt->execute([$reg_number]);
        $student = $stmt->fetch();
        $student_id = $student ? $student['id'] : 0;
    }
    
    if (!$student_id) {
        echo json_encode(['success' => false, 'error' => 'Student not found']);
        exit();
    }
    
    // Get offering_id first
    $stmt = $pdo->prepare("
        SELECT id as offering_id FROM course_offerings 
        WHERE course_id = ? AND lecturer_id = ?
        LIMIT 1
    ");
    $stmt->execute([$course_id, $_SESSION['user_id']]);
    $offering = $stmt->fetch();
    $offering_id_check = $offering ? $offering['offering_id'] : 0;
    
    if (!$offering_id_check) {
        echo json_encode(['success' => false, 'error' => 'Course offering not found']);
        exit();
    }
    
    // Check if student is enrolled in this course offering
    $stmt = $pdo->prepare("
        SELECT * FROM student_course_registrations 
        WHERE student_id = ? AND offering_id = ? AND status = 'registered'
    ");
    $stmt->execute([$student_id, $offering_id_check]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Student not enrolled in this course']);
        exit();
    }
    
    // Check if attendance already taken today
    $stmt = $pdo->prepare("
        SELECT id FROM attendance 
        WHERE student_id = ? AND course_id = ? AND date = ?
    ");
    $stmt->execute([$student_id, $course_id, $date]);
    
    if ($stmt->fetch()) {
        // Update existing
        $stmt = $pdo->prepare("
            UPDATE attendance 
            SET status = ?, time = NOW(), staff_id = ?
            WHERE student_id = ? AND course_id = ? AND date = ?
        ");
        $result = $stmt->execute([$status, $_SESSION['user_id'], $student_id, $course_id, $date]);
    } else {
        // Insert new
        $stmt = $pdo->prepare("
            INSERT INTO attendance (student_id, course_id, staff_id, date, time, status)
            VALUES (?, ?, ?, ?, NOW(), ?)
        ");
        $result = $stmt->execute([$student_id, $course_id, $_SESSION['user_id'], $date, $status]);
    }
    
    if ($result) {
        // Get student name
        $stmt = $pdo->prepare("
            SELECT CONCAT(u.first_name, ' ', u.last_name) as name, s.reg_number
            FROM students s
            JOIN users u ON s.user_id = u.id
            WHERE s.id = ?
        ");
        $stmt->execute([$student_id]);
        $student_info = $stmt->fetch();
        
        echo json_encode([
            'success' => true,
            'message' => 'Attendance marked successfully',
            'student_name' => $student_info['name'],
            'reg_number' => $student_info['reg_number'],
            'status' => $status
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to save attendance']);
    }
    exit();
}

// Handle bulk attendance submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_attendance'])) {
    $attendance_data = $_POST['attendance'] ?? [];
    $success_count = 0;
    
    foreach ($attendance_data as $student_id => $status) {
        if ($status) {
            // Check if exists
            $stmt = $pdo->prepare("
                SELECT id FROM attendance 
                WHERE student_id = ? AND course_id = ? AND date = ?
            ");
            $stmt->execute([$student_id, $course_id, $date]);
            
            if ($stmt->fetch()) {
                $stmt = $pdo->prepare("
                    UPDATE attendance 
                    SET status = ?, time = NOW(), staff_id = ?
                    WHERE student_id = ? AND course_id = ? AND date = ?
                ");
                $stmt->execute([$status, $_SESSION['user_id'], $student_id, $course_id, $date]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO attendance (student_id, course_id, staff_id, date, time, status)
                    VALUES (?, ?, ?, ?, NOW(), ?)
                ");
                $stmt->execute([$student_id, $course_id, $_SESSION['user_id'], $date, $status]);
            }
            $success_count++;
        }
    }
    
    $success = "Attendance recorded for $success_count student(s)";
    
    // Refresh attendance taken
    $attendance_taken = [];
    $stmt = $pdo->prepare("SELECT student_id, status FROM attendance WHERE course_id = ? AND date = ?");
    $stmt->execute([$course_id, $date]);
    foreach($stmt->fetchAll() as $att) {
        $attendance_taken[$att['student_id']] = $att['status'];
    }
}

// Get attendance statistics for this course
$stats = [];
if ($course_id) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT a.student_id) as students_present,
            COUNT(DISTINCT CASE WHEN a.status = 'present' THEN a.student_id END) as present_count,
            COUNT(DISTINCT CASE WHEN a.status = 'absent' THEN a.student_id END) as absent_count,
            COUNT(DISTINCT CASE WHEN a.status = 'late' THEN a.student_id END) as late_count
        FROM attendance a
        WHERE a.course_id = ? AND a.date = ?
    ");
    $stmt->execute([$course_id, $date]);
    $stats = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Take Attendance - Staff Portal</title>
    <!-- Include html5-qrcode library -->
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f5f7fb;
            padding: 16px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 24px;
            color: #2d3748;
            margin-bottom: 8px;
        }
        
        .filter-bar {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 180px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-size: 12px;
            font-weight: 600;
            color: #4a5568;
        }
        
        .filter-group select, .filter-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5a67d8;
        }
        
        .btn-success {
            background: #48bb78;
            color: white;
        }
        
        .btn-warning {
            background: #ed8936;
            color: white;
        }
        
        .btn-danger {
            background: #f56565;
            color: white;
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid #667eea;
            color: #667eea;
        }
        
        .qr-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .qr-scanner {
            max-width: 500px;
            margin: 0 auto;
        }
        
        #qr-reader {
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
            border-radius: 12px;
            overflow: hidden;
        }
        
        #qr-reader video {
            width: 100%;
            border-radius: 12px;
        }
        
        .scanner-controls {
            margin-top: 15px;
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .manual-input {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .manual-input input {
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            width: 250px;
        }
        
        .stats-bar {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        
        .stat-box {
            background: white;
            padding: 15px;
            border-radius: 10px;
            flex: 1;
            min-width: 120px;
            text-align: center;
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #667eea;
        }
        
        .students-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
        }
        
        .table-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            font-weight: 600;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        
        th {
            background: #f7fafc;
            font-weight: 600;
        }
        
        .status-select {
            padding: 6px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            background: white;
        }
        
        .status-present {
            background: #c6f6d5;
            color: #22543d;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
            font-size: 12px;
        }
        
        .status-absent {
            background: #fed7d7;
            color: #c53030;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
            font-size: 12px;
        }
        
        .status-late {
            background: #feebc8;
            color: #7c2d12;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
            font-size: 12px;
        }
        
        .flash-message {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 8px;
            color: white;
            z-index: 2000;
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .flash-success { background: #48bb78; }
        .flash-error { background: #f56565; }
        
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .scan-result {
            margin-top: 15px;
            padding: 10px;
            border-radius: 8px;
            background: #f7fafc;
        }
        
        .scan-success {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .scan-error {
            background: #fed7d7;
            color: #c53030;
        }
        
        @media (max-width: 768px) {
            .filter-form {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
            
            th, td {
                padding: 8px;
                font-size: 12px;
            }
        }
        
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                <div>
                    <h1>✅ Take Attendance</h1>
                    <p>Mark student attendance using QR code or manual entry</p>
                </div>
                <a href="index.php" style="color: #667eea; text-decoration: none;">← Back to Dashboard</a>
            </div>
        </div>
        
        <!-- Course Selection -->
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form">
                <div class="filter-group">
                    <label>Select Course</label>
                    <select name="course_id" required onchange="this.form.submit()">
                        <option value="">-- Select Course --</option>
                        <?php foreach($courses as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $course_id == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['code'] . ' - ' . $c['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Date</label>
                    <input type="date" name="date" value="<?php echo $date; ?>" onchange="this.form.submit()">
                </div>
                <?php if($course_id): ?>
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <a href="take_attendance.php?course_id=<?php echo $course_id; ?>&date=<?php echo date('Y-m-d'); ?>" class="btn btn-outline">Today</a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
        
        <?php if($course_id && !empty($enrolled_students)): ?>
        
        <!-- QR Scanner Section -->
        <div class="qr-section">
            <h3>📱 Scan Student QR Code</h3>
            <div class="qr-scanner">
                <div id="qr-reader"></div>
                <div id="qr-reader-results" class="scan-result" style="display: none;"></div>
                <div class="scanner-controls">
                    <button id="startScannerBtn" class="btn btn-primary">📷 Start Scanner</button>
                    <button id="stopScannerBtn" class="btn btn-danger" style="display:none;">⏹️ Stop Scanner</button>
                </div>
                <div class="manual-input">
                    <input type="text" id="manualRegNumber" placeholder="Or enter Registration Number">
                    <button onclick="markManual()" class="btn btn-success">📝 Mark Present</button>
                </div>
            </div>
        </div>
        
        <!-- Statistics -->
        <div class="stats-bar">
            <div class="stat-box">
                <div class="stat-number"><?php echo count($enrolled_students); ?></div>
                <div>Total Students</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['present_count'] ?? 0; ?></div>
                <div>Present</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['absent_count'] ?? 0; ?></div>
                <div>Absent</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['late_count'] ?? 0; ?></div>
                <div>Late</div>
            </div>
        </div>
        
        <!-- Students List with Bulk Attendance -->
        <div class="students-table">
            <div class="table-header">
                📋 Student List - Mark Attendance
            </div>
            <div class="table-responsive">
                <form method="POST" action="">
                    <input type="hidden" name="bulk_attendance" value="1">
                    <table>
                        <thead>
                            <tr>
                                <th>S/N</th>
                                <th>Reg Number</th>
                                <th>Student Name</th>
                                <th>Status</th>
                                <th>Current Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sn = 1; foreach($enrolled_students as $student): ?>
                                <tr>
                                    <td><?php echo $sn++; ?></td>
                                    <td><?php echo htmlspecialchars($student['reg_number']); ?></td>
                                    <td><?php echo htmlspecialchars($student['student_name']); ?>
                                        <?php if(isset($attendance_taken[$student['id']])): ?>
                                            <span class="badge" style="background: #e2e8f0; margin-left: 5px;">✓</span>
                                        <?php endif; ?>
                                     </td>
                                    <td>
                                        <select name="attendance[<?php echo $student['id']; ?>]" class="status-select">
                                            <option value="">-- Select --</option>
                                            <option value="present" <?php echo ($attendance_taken[$student['id']] ?? '') == 'present' ? 'selected' : ''; ?>>✅ Present</option>
                                            <option value="absent" <?php echo ($attendance_taken[$student['id']] ?? '') == 'absent' ? 'selected' : ''; ?>>❌ Absent</option>
                                            <option value="late" <?php echo ($attendance_taken[$student['id']] ?? '') == 'late' ? 'selected' : ''; ?>>⏰ Late</option>
                                        </select>
                                     </td>
                                    <td>
                                        <?php if(isset($attendance_taken[$student['id']])): ?>
                                            <?php if($attendance_taken[$student['id']] == 'present'): ?>
                                                <span class="status-present">✓ Present</span>
                                            <?php elseif($attendance_taken[$student['id']] == 'absent'): ?>
                                                <span class="status-absent">✗ Absent</span>
                                            <?php elseif($attendance_taken[$student['id']] == 'late'): ?>
                                                <span class="status-late">⏰ Late</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="status-absent">Not Marked</span>
                                        <?php endif; ?>
                                     </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div style="padding: 15px; display: flex; gap: 10px; justify-content: flex-end;">
                        <button type="submit" class="btn btn-primary">💾 Save All Changes</button>
                        <button type="button" onclick="markAllPresent()" class="btn btn-success">✅ Mark All Present</button>
                        <button type="button" onclick="markAllAbsent()" class="btn btn-danger">❌ Mark All Absent</button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php elseif($course_id && empty($enrolled_students)): ?>
            <div style="background: #feebc8; padding: 20px; border-radius: 12px; text-align: center;">
                <p>No students enrolled in this course yet.</p>
            </div>
        <?php elseif(!$course_id): ?>
            <div style="background: #e9f5ff; padding: 20px; border-radius: 12px; text-align: center;">
                <p>Please select a course to take attendance.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        let html5QrCode = null;
        let isScanning = false;
        
        // Get the course ID from PHP
        const courseId = <?php echo $course_id; ?>;
        
        // Initialize QR scanner
        function initQrScanner() {
            const qrReaderElement = document.getElementById('qr-reader');
            if (!qrReaderElement) return;
            
            html5QrCode = new Html5Qrcode("qr-reader");
        }
        
        // Start scanning
        async function startScanning() {
            if (!html5QrCode) {
                initQrScanner();
            }
            
            const config = {
                fps: 10,
                qrbox: { width: 250, height: 250 },
                aspectRatio: 1.0
            };
            
            try {
                await html5QrCode.start(
                    { facingMode: "environment" }, // Use back camera
                    config,
                    onScanSuccess,
                    onScanError
                );
                isScanning = true;
                document.getElementById('startScannerBtn').style.display = 'none';
                document.getElementById('stopScannerBtn').style.display = 'inline-flex';
                showFlash('Scanner started. Point camera at student QR code.', 'success');
            } catch (err) {
                console.error('Error starting scanner:', err);
                showFlash('Could not start camera. Please ensure camera permissions are granted.', 'error');
            }
        }
        
        // Stop scanning
        function stopScanning() {
            if (html5QrCode && isScanning) {
                html5QrCode.stop().then(() => {
                    isScanning = false;
                    document.getElementById('startScannerBtn').style.display = 'inline-flex';
                    document.getElementById('stopScannerBtn').style.display = 'none';
                    showFlash('Scanner stopped.', 'success');
                }).catch(err => {
                    console.error('Error stopping scanner:', err);
                });
            }
        }
        
        // Handle successful QR scan
        async function onScanSuccess(decodedText, decodedResult) {
            // Stop scanning temporarily to avoid multiple scans
            if (isScanning) {
                await html5QrCode.stop();
                isScanning = false;
                document.getElementById('startScannerBtn').style.display = 'inline-flex';
                document.getElementById('stopScannerBtn').style.display = 'none';
            }
            
            try {
                // Parse QR data
                let qrData;
                try {
                    qrData = JSON.parse(decodeURIComponent(decodedText));
                } catch (e) {
                    // If not JSON, try as plain text (registration number)
                    qrData = { reg_number: decodedText };
                }
                
                // Show processing message
                const resultDiv = document.getElementById('qr-reader-results');
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = '<div class="loading"></div> Processing...';
                resultDiv.className = 'scan-result';
                
                // Send to server
                const formData = new FormData();
                formData.append('qr_data', JSON.stringify(qrData));
                formData.append('status', 'present');
                
                const response = await fetch(`take_attendance.php?course_id=${courseId}`, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    resultDiv.innerHTML = `✅ ${data.student_name} (${data.reg_number}) marked present!`;
                    resultDiv.className = 'scan-result scan-success';
                    showFlash(`✓ ${data.student_name} (${data.reg_number}) marked present`, 'success');
                    // Reload page after 2 seconds to update the list
                    setTimeout(() => location.reload(), 2000);
                } else {
                    resultDiv.innerHTML = `❌ Error: ${data.error}`;
                    resultDiv.className = 'scan-result scan-error';
                    showFlash(`Error: ${data.error}`, 'error');
                    // Restart scanner after 3 seconds
                    setTimeout(() => {
                        resultDiv.style.display = 'none';
                        startScanning();
                    }, 3000);
                }
            } catch (error) {
                console.error('Error processing QR code:', error);
                const resultDiv = document.getElementById('qr-reader-results');
                resultDiv.innerHTML = '❌ Error processing QR code';
                resultDiv.className = 'scan-result scan-error';
                showFlash('Error processing QR code', 'error');
                setTimeout(() => {
                    resultDiv.style.display = 'none';
                    startScanning();
                }, 3000);
            }
        }
        
        function onScanError(errorMessage) {
            // Silent error handling - don't show every error to user
            console.log('Scan error:', errorMessage);
        }
        
        // Manual entry
        async function markManual() {
            const regNumber = document.getElementById('manualRegNumber').value.trim();
            if (!regNumber) {
                showFlash('Please enter registration number', 'error');
                return;
            }
            
            const qrData = JSON.stringify({ reg_number: regNumber });
            
            const formData = new FormData();
            formData.append('qr_data', qrData);
            formData.append('status', 'present');
            
            showFlash('Processing...', 'success');
            
            try {
                const response = await fetch(`take_attendance.php?course_id=${courseId}`, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showFlash(`✓ ${data.student_name} (${data.reg_number}) marked present`, 'success');
                    document.getElementById('manualRegNumber').value = '';
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showFlash(`Error: ${data.error}`, 'error');
                }
            } catch (error) {
                showFlash('Error marking attendance', 'error');
            }
        }
        
        // Bulk mark functions
        function markAllPresent() {
            const selects = document.querySelectorAll('.status-select');
            selects.forEach(select => {
                select.value = 'present';
            });
        }
        
        function markAllAbsent() {
            const selects = document.querySelectorAll('.status-select');
            selects.forEach(select => {
                select.value = 'absent';
            });
        }
        
        // Flash message
        function showFlash(message, type) {
            const flash = document.createElement('div');
            flash.className = `flash-message flash-${type}`;
            flash.innerHTML = message;
            document.body.appendChild(flash);
            setTimeout(() => flash.remove(), 3000);
        }
        
        // Event listeners
        document.getElementById('startScannerBtn')?.addEventListener('click', startScanning);
        document.getElementById('stopScannerBtn')?.addEventListener('click', stopScanning);
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            initQrScanner();
        });
        
        // Clean up on page unload
        window.addEventListener('beforeunload', function() {
            if (html5QrCode && isScanning) {
                html5QrCode.stop().catch(err => console.log('Error stopping scanner:', err));
            }
        });
        
        // Display any existing flash messages
        <?php if($success): ?>
            showFlash('<?php echo addslashes($success); ?>', 'success');
        <?php endif; ?>
        <?php if($error): ?>
            showFlash('<?php echo addslashes($error); ?>', 'error');
        <?php endif; ?>
    </script>
</body>
</html>
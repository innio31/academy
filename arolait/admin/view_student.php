<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

$student_id = $_GET['id'] ?? 0;

if (!$student_id) {
    header("Location: students.php?error=Invalid student ID");
    exit();
}

// Get student details with JOIN query (no function dependencies)
$stmt = $pdo->prepare("
    SELECT 
        s.*,
        u.email, u.first_name, u.last_name, u.phone, u.created_at as user_created,
        d.name as department_name, d.code as department_code,
        f.name as faculty_name,
        a.name as current_session_name
    FROM students s
    JOIN users u ON s.user_id = u.id
    JOIN departments d ON s.department_id = d.id
    JOIN faculties f ON d.faculty_id = f.id
    LEFT JOIN academic_sessions a ON s.current_session_id = a.id
    WHERE s.id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    header("Location: students.php?error=Student not found");
    exit();
}

// Check if QR code exists, if not generate one
$qr_path = '../assets/qrcodes/' . $student['reg_number'] . '.png';
if (!file_exists($qr_path) && !$student['qr_code']) {
    // Simple QR generation using Google Charts API
    $qr_data = urlencode(json_encode([
        'reg_no' => $student['reg_number'],
        'student_id' => $student_id,
        'name' => $student['first_name'] . ' ' . $student['last_name']
    ]));
    
    $qr_url = "https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=" . $qr_data . "&choe=UTF-8";
    $qr_image = @file_get_contents($qr_url);
    
    if ($qr_image !== false) {
        $qr_dir = '../assets/qrcodes/';
        if (!file_exists($qr_dir)) {
            mkdir($qr_dir, 0777, true);
        }
        file_put_contents($qr_path, $qr_image);
        
        // Update database
        $update = $pdo->prepare("UPDATE students SET qr_code = ? WHERE id = ?");
        $update->execute(['assets/qrcodes/' . $student['reg_number'] . '.png', $student_id]);
        $student['qr_code'] = 'assets/qrcodes/' . $student['reg_number'] . '.png';
    }
}

// Try to get enrolled courses (if table exists, otherwise show message)
$courses = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*, cr.registered_at
        FROM courses c
        JOIN course_registrations cr ON c.id = cr.course_id
        WHERE cr.student_id = ?
        LIMIT 10
    ");
    $stmt->execute([$student_id]);
    $courses = $stmt->fetchAll();
} catch(PDOException $e) {
    // Table might not exist yet
    $courses = [];
}

// Try to get attendance summary (if table exists)
$attendance = ['total_classes' => 0, 'present_count' => 0, 'absent_count' => 0, 'late_count' => 0, 'attendance_percentage' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_classes,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count
        FROM attendance 
        WHERE student_id = ?
    ");
    $stmt->execute([$student_id]);
    $attendance_data = $stmt->fetch();
    if ($attendance_data && $attendance_data['total_classes'] > 0) {
        $attendance = $attendance_data;
        $attendance['attendance_percentage'] = round(($attendance['present_count'] / $attendance['total_classes']) * 100, 2);
    }
} catch(PDOException $e) {
    // Table doesn't exist yet
    $attendance = ['total_classes' => 0, 'present_count' => 0, 'absent_count' => 0, 'late_count' => 0, 'attendance_percentage' => 0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Student Details - <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></title>
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
            max-width: 1200px;
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
        
        .action-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        
        .btn {
            padding: 12px 20px;
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
        
        .btn-primary { background: #667eea; color: white; }
        .btn-secondary { background: #48bb78; color: white; }
        .btn-info { background: #4299e1; color: white; }
        .btn-warning { background: #ed8936; color: white; }
        
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 16px;
            font-weight: 600;
            font-size: 18px;
        }
        
        .card-header.green { background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); }
        .card-header.orange { background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%); }
        .card-header.blue { background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%); }
        
        .card-body {
            padding: 20px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .info-label {
            font-size: 14px;
            color: #718096;
            font-weight: 500;
        }
        
        .info-value {
            font-size: 14px;
            font-weight: 600;
            color: #2d3748;
        }
        
        .profile-section {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .profile-avatar {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50px;
            margin: 0 auto 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            color: white;
        }
        
        .qr-code {
            text-align: center;
            padding: 15px;
            background: #f7fafc;
            border-radius: 8px;
            margin-top: 15px;
        }
        
        .qr-code img {
            max-width: 150px;
            height: auto;
            margin: 10px auto;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .badge-success { background: #c6f6d5; color: #22543d; }
        .badge-warning { background: #feebc8; color: #7c2d12; }
        
        .stat-box {
            text-align: center;
            padding: 15px;
            background: #f7fafc;
            border-radius: 8px;
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #667eea;
        }
        
        .stat-label {
            font-size: 12px;
            color: #718096;
            margin-top: 5px;
        }
        
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 10px;
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
        
        @media (max-width: 640px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                justify-content: center;
            }
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
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h1>👨‍🎓 Student Details</h1>
                    <p>Complete student information</p>
                </div>
                <a href="students.php" style="color: #667eea; text-decoration: none;">← Back to Students List</a>
            </div>
        </div>
        
        <div class="action-buttons">
            <a href="edit_student.php?id=<?php echo $student_id; ?>" class="btn btn-primary">✏️ Edit Student</a>
            <button onclick="window.open('print_id_card.php?id=<?php echo $student_id; ?>', '_blank')" class="btn btn-secondary">🪪 Print ID Card</button>
            <button onclick="window.print()" class="btn btn-info">🖨️ Print Page</button>
        </div>
        
        <div class="grid-2">
            <!-- Personal Information -->
            <div class="card">
                <div class="card-header">📋 Personal Information</div>
                <div class="card-body">
                    <div class="profile-section">
                        <div class="profile-avatar">
                            👨‍🎓
                        </div>
                        <h2 style="margin-bottom: 5px;"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h2>
                        <p style="color: #718096; font-size: 14px;"><?php echo $student['reg_number']; ?></p>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">📧 Email</span>
                        <span class="info-value"><?php echo htmlspecialchars($student['email']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">📞 Phone</span>
                        <span class="info-value"><?php echo $student['phone'] ?: 'Not provided'; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">📅 Enrolled</span>
                        <span class="info-value"><?php echo date('F j, Y', strtotime($student['user_created'])); ?></span>
                    </div>
                    
                    <!-- QR Code Section - Replace the entire QR section -->
<div class="qr-code">
    <strong>📱 QR Code</strong>
    <div>
        <?php 
        // Get the correct QR filename
        $safe_filename = str_replace('/', '_', $student['reg_number']);
        $qr_img_path = '/assets/qrcodes/' . $safe_filename . '.png';
        
        // Check if QR exists
        $qr_file_exists = file_exists($_SERVER['DOCUMENT_ROOT'] . $qr_img_path);
        
        if($qr_file_exists): ?>
            <img src="<?php echo $qr_img_path; ?>?t=<?php echo time(); ?>" alt="QR Code" style="max-width: 150px; margin: 10px auto; display: block; border: 1px solid #ddd; padding: 10px;">
            <div style="margin-top: 10px;">
                <a href="<?php echo $qr_img_path; ?>" download class="btn btn-small" style="background: #48bb78; color: white; padding: 5px 10px; text-decoration: none; border-radius: 5px; font-size: 12px;">📥 Download QR</a>
                <button onclick="regenerateQR(<?php echo $student_id; ?>)" class="btn btn-small" style="background: #667eea; color: white; padding: 5px 10px; border: none; border-radius: 5px; cursor: pointer; font-size: 12px;">🔄 Regenerate</button>
            </div>
        <?php elseif($student['qr_code'] && file_exists($_SERVER['DOCUMENT_ROOT'] . $student['qr_code'])): ?>
            <img src="<?php echo $student['qr_code']; ?>?t=<?php echo time(); ?>" alt="QR Code" style="max-width: 150px; margin: 10px auto; display: block;">
            <div style="margin-top: 10px;">
                <a href="<?php echo $student['qr_code']; ?>" download class="btn btn-small" style="background: #48bb78; color: white; padding: 5px 10px; text-decoration: none; border-radius: 5px;">📥 Download QR</a>
            </div>
        <?php else: ?>
            <p style="color: #f56565; margin: 10px 0;">QR code not generated yet</p>
            <button onclick="regenerateQR(<?php echo $student_id; ?>)" class="btn btn-small" style="background: #667eea; color: white; padding: 8px 16px; border: none; border-radius: 5px; cursor: pointer;">Generate QR Code</button>
        <?php endif; ?>
    </div>
    <p style="font-size: 12px; margin-top: 10px;">Scan for attendance tracking</p>
</div>
                </div>
            </div>
            
            <!-- Academic Information -->
            <div class="card">
                <div class="card-header green">🎓 Academic Information</div>
                <div class="card-body">
                    <div class="info-row">
                        <span class="info-label">🏛️ Faculty</span>
                        <span class="info-value"><?php echo htmlspecialchars($student['faculty_name']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">📚 Department</span>
                        <span class="info-value"><?php echo htmlspecialchars($student['department_name']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">📊 Level</span>
                        <span class="info-value"><?php echo $student['current_level']; ?> Level</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">📅 Session</span>
                        <span class="info-value"><?php echo $student['current_session_name'] ?: 'Not set'; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">🪪 ID Card</span>
                        <span class="info-value">
                            <?php if($student['id_card_issued']): ?>
                                <span class="badge badge-success">✓ Issued</span>
                            <?php else: ?>
                                <span class="badge badge-warning">⚠ Not Issued</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <!-- Guardian Information -->
            <div class="card">
                <div class="card-header orange">👪 Guardian Information</div>
                <div class="card-body">
                    <div class="info-row">
                        <span class="info-label">👤 Name</span>
                        <span class="info-value"><?php echo $student['guardian_name'] ?: 'Not provided'; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">📞 Phone</span>
                        <span class="info-value"><?php echo $student['guardian_phone'] ?: 'Not provided'; ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Attendance Summary -->
            <div class="card">
                <div class="card-header blue">📅 Attendance Summary</div>
                <div class="card-body">
                    <div class="stat-grid">
                        <div class="stat-box">
                            <div class="stat-number"><?php echo $attendance['total_classes']; ?></div>
                            <div class="stat-label">Total Classes</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-number"><?php echo $attendance['present_count']; ?></div>
                            <div class="stat-label">Present</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-number"><?php echo $attendance['attendance_percentage']; ?>%</div>
                            <div class="stat-label">Attendance Rate</div>
                        </div>
                    </div>
                    <?php if($attendance['total_classes'] == 0): ?>
                        <p style="text-align: center; color: #718096; margin-top: 10px;">No attendance records yet</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Enrolled Courses -->
        <div class="card">
            <div class="card-header green">📖 Enrolled Courses</div>
            <div class="card-body">
                <?php if(!empty($courses)): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr><th>Course Code</th><th>Course Title</th><th>Credit Unit</th><th>Registered</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($courses as $course): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($course['code']); ?></td>
                                        <td><?php echo htmlspecialchars($course['title']); ?></td>
                                        <td><?php echo $course['credit_unit']; ?></td>
                                        <td><?php echo date('M j, Y', strtotime($course['registered_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p style="text-align: center; color: #718096;">No courses registered yet</p>
                    <p style="text-align: center; font-size: 12px; margin-top: 5px;">Course registration will appear after creating courses and enrolling students</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        // Show flash message if exists
        const urlParams = new URLSearchParams(window.location.search);
        const message = urlParams.get('message');
        const error = urlParams.get('error');
        
        if(message) {
            const flash = document.createElement('div');
            flash.className = 'flash-message flash-success';
            flash.innerHTML = message;
            document.body.appendChild(flash);
            setTimeout(() => flash.remove(), 3000);
        }
        if(error) {
            const flash = document.createElement('div');
            flash.className = 'flash-message flash-error';
            flash.innerHTML = error;
            document.body.appendChild(flash);
            setTimeout(() => flash.remove(), 3000);
        }
        function regenerateQR(studentId) {
    if(confirm('Generate new QR code for this student?')) {
        window.location.href = 'direct_qr.php?id=' + studentId;
    }
}
    </script>
</body>
</html>
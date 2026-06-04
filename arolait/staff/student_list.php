<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole(['staff']);

$course_id = $_GET['course_id'] ?? 0;
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? 'all';

// Get staff's assigned courses from course_offerings
$stmt = $pdo->prepare("
    SELECT 
        c.*, 
        d.name as department_name,
        s.name as semester_name,
        a.name as session_name,
        co.id as offering_id
    FROM course_offerings co
    JOIN courses c ON co.course_id = c.id
    JOIN departments d ON c.department_id = d.id
    JOIN semesters s ON co.semester_id = s.id
    JOIN academic_sessions a ON s.session_id = a.id
    WHERE co.lecturer_id = ? AND s.is_current = 1
    GROUP BY c.id, co.id
    ORDER BY c.code
");
$stmt->execute([$_SESSION['user_id']]);
$courses = $stmt->fetchAll();

// Variables for students data
$students = [];
$course_info = null;
$offering_id = 0;
$stats = [
    'total_students' => 0,
    'present_today' => 0,
    'absent_today' => 0,
    'late_today' => 0,
    'attendance_rate' => 0,
    'gender_male' => 0,
    'gender_female' => 0
];

if ($course_id) {
    // Get course details
    $stmt = $pdo->prepare("
        SELECT c.*, d.name as department_name 
        FROM courses c
        JOIN departments d ON c.department_id = d.id
        WHERE c.id = ?
    ");
    $stmt->execute([$course_id]);
    $course_info = $stmt->fetch();
    
    if ($course_info) {
        // Get offering_id for this course and staff
        $stmt = $pdo->prepare("
            SELECT co.id as offering_id, co.semester_id
            FROM course_offerings co
            WHERE co.course_id = ? AND co.lecturer_id = ?
            LIMIT 1
        ");
        $stmt->execute([$course_id, $_SESSION['user_id']]);
        $offering = $stmt->fetch();
        $offering_id = $offering ? $offering['offering_id'] : 0;
        
        if ($offering_id) {
            // Build query for enrolled students
            $sql = "
                SELECT 
                    s.id as student_id,
                    s.reg_number,
                    s.current_level,
                    s.guardian_name,
                    s.guardian_phone,
                    s.qr_code,
                    s.id_card_issued,
                    s.enrollment_date,
                    u.id as user_id,
                    u.first_name,
                    u.last_name,
                    u.email,
                    u.phone,
                    u.profile_pic,
                    d.name as department_name,
                    d.code as department_code,
                    f.name as faculty_name,
                    COUNT(DISTINCT a.id) as total_attendance,
                    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                    SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                    SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
                    AVG(r.total_score) as average_score
                FROM student_course_registrations scr
                JOIN students s ON scr.student_id = s.id
                JOIN users u ON s.user_id = u.id
                JOIN departments d ON s.department_id = d.id
                JOIN faculties f ON d.faculty_id = f.id
                LEFT JOIN attendance a ON s.id = a.student_id AND a.course_id = ?
                LEFT JOIN results r ON s.id = r.student_id AND r.course_id = ? AND r.is_approved = 1
                WHERE scr.offering_id = ? AND scr.status = 'registered'
            ";
            
            $params = [$course_id, $course_id, $offering_id];
            
            if ($search) {
                $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR s.reg_number LIKE ?)";
                $search_param = "%$search%";
                $params[] = $search_param;
                $params[] = $search_param;
                $params[] = $search_param;
            }
            
            $sql .= " GROUP BY s.id ORDER BY u.last_name ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $students = $stmt->fetchAll();
            
            // Calculate statistics
            $stats['total_students'] = count($students);
            
            // Get today's attendance
            $today = date('Y-m-d');
            $stmt = $pdo->prepare("
                SELECT 
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late
                FROM attendance
                WHERE course_id = ? AND date = ?
            ");
            $stmt->execute([$course_id, $today]);
            $today_stats = $stmt->fetch();
            $stats['present_today'] = $today_stats['present'] ?? 0;
            $stats['absent_today'] = $today_stats['absent'] ?? 0;
            $stats['late_today'] = $today_stats['late'] ?? 0;
            
            // Calculate overall attendance rate
            $total_attendance_records = array_sum(array_column($students, 'total_attendance'));
            $total_present = array_sum(array_column($students, 'present_count'));
            $stats['attendance_rate'] = $total_attendance_records > 0 ? 
                round(($total_present / $total_attendance_records) * 100, 2) : 0;
        }
    }
}

// Generate QR code for a student
if (isset($_GET['generate_qr']) && isset($_GET['student_id'])) {
    $student_id = $_GET['student_id'];
    $stmt = $pdo->prepare("SELECT reg_number FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();
    
    if ($student) {
        generateStudentQR($student['reg_number'], $student_id, $pdo);
        $success = "QR Code generated successfully!";
    }
    
    // Redirect back to avoid resubmission
    header("Location: student_list.php?course_id=$course_id" . ($search ? "&search=" . urlencode($search) : "") . "&message=" . urlencode($success));
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Student List - Staff Portal</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f5f7fb;
            padding: 16px;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        
        .header {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 24px; color: #2d3748; margin-bottom: 8px; }
        .header p { color: #718096; font-size: 14px; }
        
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
            min-width: 200px;
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
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5a67d8; }
        .btn-success { background: #48bb78; color: white; }
        .btn-success:hover { background: #38a169; }
        .btn-warning { background: #ed8936; color: white; }
        .btn-danger { background: #f56565; color: white; }
        .btn-outline { background: transparent; border: 1px solid #667eea; color: #667eea; }
        .btn-outline:hover { background: #667eea; color: white; }
        .btn-info { background: #4299e1; color: white; }
        .btn-sm { padding: 5px 10px; font-size: 12px; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .stat-number { font-size: 28px; font-weight: bold; color: #667eea; }
        .stat-label { font-size: 11px; color: #718096; margin-top: 5px; }
        
        .cards-grid {
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
            padding: 15px 20px;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .card-body { padding: 20px; }
        
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        th {
            background: #f7fafc;
            font-weight: 600;
            position: sticky;
            top: 0;
        }
        
        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 16px;
        }
        
        .progress-bar {
            width: 100%;
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 5px;
        }
        .progress-fill {
            height: 100%;
            transition: width 0.3s;
        }
        .progress-fill.good { background: #48bb78; }
        .progress-fill.warning { background: #ed8936; }
        .progress-fill.danger { background: #f56565; }
        
        .status-present { background: #c6f6d5; color: #22543d; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .status-absent { background: #fed7d7; color: #c53030; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .status-late { background: #feebc8; color: #7c2d12; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }
        .badge-yes { background: #c6f6d5; color: #22543d; }
        .badge-no { background: #fed7d7; color: #c53030; }
        
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background: white;
            border-radius: 12px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        .modal-header {
            padding: 15px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-body { padding: 20px; }
        .modal-footer { padding: 15px 20px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 10px; }
        .close-modal { cursor: pointer; font-size: 24px; }
        
        .qr-code {
            text-align: center;
            padding: 20px;
        }
        .qr-code img {
            max-width: 200px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px;
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
        
        .empty-state {
            background: white;
            border-radius: 12px;
            padding: 60px;
            text-align: center;
        }
        .empty-state p { color: #718096; margin-bottom: 20px; }
        
        @media (max-width: 768px) {
            .filter-form { flex-direction: column; }
            .filter-group { width: 100%; }
            th, td { padding: 8px; font-size: 12px; }
            .cards-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h1>👨‍🎓 Student List</h1>
                    <p>View students enrolled in your courses</p>
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
                        <?php foreach($courses as $course): ?>
                            <option value="<?php echo $course['id']; ?>" <?php echo $course_id == $course['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($course['code'] . ' - ' . $course['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if($course_id): ?>
                    <div class="filter-group">
                        <label>Search Student</label>
                        <input type="text" name="search" placeholder="Name or Registration Number..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary">🔍 Search</button>
                    </div>
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <a href="student_list.php?course_id=<?php echo $course_id; ?>" class="btn btn-outline">Clear</a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
        
        <?php if($course_id && $course_info): ?>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_students']; ?></div>
                <div class="stat-label">Total Students</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['present_today']; ?></div>
                <div class="stat-label">Present Today</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['absent_today']; ?></div>
                <div class="stat-label">Absent Today</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['late_today']; ?></div>
                <div class="stat-label">Late Today</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['attendance_rate']; ?>%</div>
                <div class="stat-label">Overall Attendance</div>
            </div>
        </div>
        
        <!-- Student List -->
        <div class="card">
            <div class="card-header">
                <span>📋 <?php echo htmlspecialchars($course_info['code'] . ' - ' . $course_info['title']); ?></span>
                <div>
                    <a href="take_attendance.php?course_id=<?php echo $course_id; ?>" class="btn btn-primary btn-sm">✅ Take Attendance</a>
                    <a href="upload_results.php?course_id=<?php echo $course_id; ?>" class="btn btn-success btn-sm">📝 Upload Results</a>
                </div>
            </div>
            <div class="card-body">
                <?php if(empty($students)): ?>
                    <div class="empty-state" style="padding: 40px;">
                        <p>No students enrolled in this course.</p>
                        <?php if($search): ?>
                            <p style="font-size: 13px;">Try clearing the search filter.</p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>S/N</th>
                                    <th>Student</th>
                                    <th>Reg Number</th>
                                    <th>Department</th>
                                    <th>Level</th>
                                    <th>Attendance %</th>
                                    <th>Avg Score</th>
                                    <th>ID Card</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $sn = 1;
                                foreach($students as $student):
                                    $attendance_percentage = $student['total_attendance'] > 0 ? 
                                        round(($student['present_count'] / $student['total_attendance']) * 100, 2) : 0;
                                    $fill_class = $attendance_percentage >= 70 ? 'good' : ($attendance_percentage >= 50 ? 'warning' : 'danger');
                                    $initials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
                                ?>
                                    <tr>
                                        <td><?php echo $sn++; ?></td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <div class="student-avatar"><?php echo $initials; ?></div>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></strong><br>
                                                    <small style="color: #718096;"><?php echo htmlspecialchars($student['email']); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($student['reg_number']); ?></td>
                                        <td><?php echo htmlspecialchars($student['department_code']); ?></td>
                                        <td><?php echo $student['current_level']; ?></td>
                                        <td>
                                            <strong><?php echo $attendance_percentage; ?>%</strong>
                                            <div class="progress-bar">
                                                <div class="progress-fill <?php echo $fill_class; ?>" style="width: <?php echo $attendance_percentage; ?>%;"></div>
                                            </div>
                                         </td>
                                        <td>
                                            <?php 
                                            $avg_score = round($student['average_score'] ?? 0, 2);
                                            $score_color = $avg_score >= 70 ? '#48bb78' : ($avg_score >= 50 ? '#ed8936' : '#f56565');
                                            ?>
                                            <strong style="color: <?php echo $score_color; ?>;"><?php echo $avg_score; ?>%</strong>
                                         </td>
                                        <td>
                                            <?php if($student['id_card_issued']): ?>
                                                <span class="badge badge-yes">✓ Issued</span>
                                            <?php else: ?>
                                                <span class="badge badge-no">✗ Not Issued</span>
                                            <?php endif; ?>
                                         </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button onclick="viewStudent(<?php echo $student['student_id']; ?>)" class="btn btn-info btn-sm">👁️ View</button>
                                                <button onclick="generateQR(<?php echo $student['student_id']; ?>, '<?php echo $student['reg_number']; ?>')" class="btn btn-warning btn-sm">📱 QR</button>
                                                <a href="take_attendance.php?course_id=<?php echo $course_id; ?>&student_id=<?php echo $student['student_id']; ?>" class="btn btn-primary btn-sm">✅ Mark</a>
                                            </div>
                                         </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php elseif($course_id && !$course_info): ?>
            <div class="empty-state">
                <p>Course not found or you don't have access to this course.</p>
                <a href="student_list.php" class="btn btn-primary">Select Another Course</a>
            </div>
        <?php elseif(!$course_id): ?>
            <div class="empty-state">
                <p>Please select a course to view students.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Student Detail Modal -->
    <div id="studentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Student Details</h3>
                <span class="close-modal" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body" id="studentModalBody">
                <div style="text-align: center; padding: 20px;">Loading...</div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal()">Close</button>
            </div>
        </div>
    </div>
    
    <!-- QR Code Modal -->
    <div id="qrModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Student QR Code</h3>
                <span class="close-modal" onclick="closeQRModal()">&times;</span>
            </div>
            <div class="modal-body" id="qrModalBody">
                <div style="text-align: center; padding: 20px;">Loading...</div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" onclick="printQR()">🖨️ Print</button>
                <button class="btn btn-outline" onclick="closeQRModal()">Close</button>
            </div>
        </div>
    </div>
    
    <script>
        function viewStudent(studentId) {
            const modal = document.getElementById('studentModal');
            const modalBody = document.getElementById('studentModalBody');
            
            modal.style.display = 'flex';
            modalBody.innerHTML = '<div style="text-align: center; padding: 20px;">Loading...</div>';
            
            fetch(`get_student_details.php?id=${studentId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        modalBody.innerHTML = `
                            <div style="display: flex; flex-direction: column; gap: 15px;">
                                <div style="display: flex; justify-content: center;">
                                    <div class="student-avatar" style="width: 80px; height: 80px; font-size: 32px;">
                                        ${data.initials}
                                    </div>
                                </div>
                                <div><strong>Full Name:</strong> ${data.full_name}</div>
                                <div><strong>Registration Number:</strong> ${data.reg_number}</div>
                                <div><strong>Email:</strong> ${data.email}</div>
                                <div><strong>Phone:</strong> ${data.phone || 'N/A'}</div>
                                <div><strong>Department:</strong> ${data.department}</div>
                                <div><strong>Faculty:</strong> ${data.faculty}</div>
                                <div><strong>Current Level:</strong> ${data.current_level}</div>
                                <div><strong>Guardian Name:</strong> ${data.guardian_name || 'N/A'}</div>
                                <div><strong>Guardian Phone:</strong> ${data.guardian_phone || 'N/A'}</div>
                                <div><strong>ID Card Issued:</strong> ${data.id_card_issued ? 'Yes' : 'No'}</div>
                                <div><strong>Enrollment Date:</strong> ${data.enrollment_date || 'N/A'}</div>
                            </div>
                        `;
                    } else {
                        modalBody.innerHTML = '<div style="text-align: center; color: #f56565;">Student not found</div>';
                    }
                })
                .catch(error => {
                    modalBody.innerHTML = '<div style="text-align: center; color: #f56565;">Error loading student details</div>';
                });
        }
        
        function generateQR(studentId, regNumber) {
            const modal = document.getElementById('qrModal');
            const modalBody = document.getElementById('qrModalBody');
            
            modal.style.display = 'flex';
            modalBody.innerHTML = '<div style="text-align: center; padding: 20px;">Generating QR Code...</div>';
            
            // First check if QR exists, if not generate it
            fetch(`generate_qr.php?student_id=${studentId}&reg_number=${encodeURIComponent(regNumber)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        modalBody.innerHTML = `
                            <div class="qr-code">
                                <h4>${regNumber}</h4>
                                <img src="${data.qr_url}" alt="QR Code">
                                <p style="margin-top: 15px; font-size: 12px; color: #718096;">
                                    Scan this QR code to mark attendance
                                </p>
                            </div>
                        `;
                    } else {
                        modalBody.innerHTML = '<div style="text-align: center; color: #f56565;">Error generating QR code</div>';
                    }
                })
                .catch(error => {
                    modalBody.innerHTML = '<div style="text-align: center; color: #f56565;">Error generating QR code</div>';
                });
        }
        
        function printQR() {
            const qrContent = document.getElementById('qrModalBody').innerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                    <head>
                        <title>Print QR Code</title>
                        <style>
                            body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
                            img { max-width: 300px; }
                        </style>
                    </head>
                    <body>
                        ${qrContent}
                        <script>window.print();<\/script>
                    </body>
                </html>
            `);
            printWindow.document.close();
        }
        
        function closeModal() {
            document.getElementById('studentModal').style.display = 'none';
        }
        
        function closeQRModal() {
            document.getElementById('qrModal').style.display = 'none';
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const studentModal = document.getElementById('studentModal');
            const qrModal = document.getElementById('qrModal');
            if (event.target === studentModal) studentModal.style.display = 'none';
            if (event.target === qrModal) qrModal.style.display = 'none';
        }
        
        // Flash messages
        <?php if(isset($_GET['message'])): ?>
            const flash = document.createElement('div');
            flash.className = 'flash-message flash-success';
            flash.innerHTML = '<?php echo addslashes($_GET['message']); ?>';
            document.body.appendChild(flash);
            setTimeout(() => flash.remove(), 4000);
        <?php endif; ?>
    </script>
</body>
</html>
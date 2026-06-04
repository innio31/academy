<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole(['student']);

// Get institution settings
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('institution_name', 'app_name', 'app_slogan')");
$stmt->execute();
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$institution_name = $settings['institution_name'] ?? 'Higher Institution of Learning';
$app_name = $settings['app_name'] ?? 'University Portal';
$slogan = $settings['app_slogan'] ?? 'Excellence in Education';

// Get current semester
$stmt = $pdo->prepare("
    SELECT s.*, a.name as session_name
    FROM semesters s
    JOIN academic_sessions a ON s.session_id = a.id
    WHERE s.is_current = 1 AND a.is_current = 1
    LIMIT 1
");
$stmt->execute();
$current_semester = $stmt->fetch();

if (!$current_semester) {
    die("No active semester found");
}

// Get student info
$stmt = $pdo->prepare("
    SELECT s.*, u.email, u.first_name, u.last_name, u.phone, u.profile_pic,
           d.name as department_name, d.code as department_code,
           f.name as faculty_name
    FROM students s
    JOIN users u ON s.user_id = u.id
    JOIN departments d ON s.department_id = d.id
    JOIN faculties f ON d.faculty_id = f.id
    WHERE s.id = ?
");
$stmt->execute([$_SESSION['student_id']]);
$student = $stmt->fetch();

// Get registered courses from student_course_registrations via course_offerings
$stmt = $pdo->prepare("
    SELECT c.*, co.semester_id
    FROM student_course_registrations scr
    JOIN course_offerings co ON scr.offering_id = co.id
    JOIN courses c ON co.course_id = c.id
    WHERE scr.student_id = ? AND co.semester_id = ? AND scr.status = 'registered'
    ORDER BY c.code
");
$stmt->execute([$_SESSION['student_id'], $current_semester['id']]);
$courses = $stmt->fetchAll();

$total_credits = 0;
foreach($courses as $course) {
    $total_credits += $course['credit_unit'];
}

// Get profile picture path
$profile_pic_path = !empty($student['profile_pic']) && file_exists($_SERVER['DOCUMENT_ROOT'] . $student['profile_pic']) 
    ? $student['profile_pic'] 
    : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Registration Form - <?php echo $student['reg_number']; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        @media print {
            body { 
                margin: 0; 
                padding: 0;
            }
            .no-print { 
                display: none; 
            }
            .registration-form { 
                box-shadow: none; 
                border: none; 
                margin: 0; 
                padding: 0.2in;
            }
            .student-photo img {
                max-width: 100px;
            }
            table {
                page-break-inside: avoid;
            }
        }
        
        body {
            font-family: 'Times New Roman', Times, serif;
            background: #f5f7fb;
            padding: 20px;
        }
        
        .registration-form {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .header {
            text-align: center;
            margin-bottom: 15px;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
        }
        
        .institution-name {
            font-size: 22px;
            font-weight: bold;
            text-transform: uppercase;
            color: #2d3748;
        }
        
        .slogan {
            font-size: 10px;
            font-style: italic;
            color: #718096;
            margin-top: 3px;
        }
        
        .form-title {
            font-size: 14px;
            font-weight: bold;
            margin: 5px 0;
        }
        
        .session-info {
            font-size: 12px;
            margin-top: 3px;
        }
        
        .student-info-section {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .student-details {
            flex: 3;
            padding: 10px;
            border: 1px solid #000;
            font-size: 12px;
        }
        
        .student-photo {
            flex: 1;
            border: 1px solid #000;
            text-align: center;
            padding: 8px;
        }
        
        .student-photo img {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 4px;
        }
        
        .photo-placeholder {
            width: 100px;
            height: 100px;
            background: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            font-size: 40px;
            color: #a0aec0;
        }
        
        .photo-label {
            font-size: 9px;
            margin-top: 5px;
            color: #718096;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 6px;
        }
        
        .info-label {
            width: 130px;
            font-weight: bold;
        }
        
        .info-value {
            flex: 1;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 12px 0;
            font-size: 11px;
        }
        
        th, td {
            border: 1px solid #000;
            padding: 6px 8px;
            text-align: left;
        }
        
        th {
            background: #f0f0f0;
            font-weight: bold;
            font-size: 11px;
        }
        
        .total-row {
            font-weight: bold;
            background: #f9f9f9;
        }
        
        .signature-section {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
            padding-top: 15px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .signature-line {
            text-align: center;
            width: 180px;
        }
        
        .signature-line .line {
            border-top: 1px solid #000;
            margin-top: 35px;
            padding-top: 5px;
            width: 100%;
        }
        
        .signature-title {
            font-size: 11px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .declaration {
            margin-top: 15px;
            font-size: 10px;
            text-align: center;
            padding: 6px;
            background: #f9f9f9;
            border: 1px solid #ddd;
        }
        
        .footer {
            text-align: center;
            margin-top: 12px;
            font-size: 9px;
            color: #718096;
        }
        
        .btn {
            padding: 10px 20px;
            margin: 10px;
            cursor: pointer;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 14px;
        }
        
        .btn:hover { background: #5a67d8; }
        
        /* Compact spacing for many courses */
        .compact-table th, .compact-table td {
            padding: 4px 6px;
        }
        
        @media (max-width: 768px) {
            .student-info-section {
                flex-direction: column;
            }
            .signature-section {
                flex-direction: column;
                align-items: center;
            }
            .info-row {
                flex-direction: column;
            }
            .info-label {
                width: 100%;
                margin-bottom: 3px;
            }
        }
    </style>
</head>
<body>
    <div class="registration-form">
        <div class="header">
            <div class="institution-name"><?php echo strtoupper(htmlspecialchars($institution_name)); ?></div>
            <div class="slogan"><?php echo htmlspecialchars($slogan); ?></div>
            <div class="form-title">COURSE REGISTRATION FORM</div>
            <div class="session-info"><?php echo $current_semester['name']; ?> Semester, <?php echo $current_semester['session_name']; ?> Academic Session</div>
        </div>
        
        <div class="student-info-section">
            <div class="student-details">
                <div class="info-row">
                    <div class="info-label">Student Name:</div>
                    <div class="info-value"><?php echo strtoupper(htmlspecialchars($student['first_name'] . ' ' . $student['last_name'])); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Registration Number:</div>
                    <div class="info-value"><?php echo htmlspecialchars($student['reg_number']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Faculty:</div>
                    <div class="info-value"><?php echo htmlspecialchars($student['faculty_name']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Department:</div>
                    <div class="info-value"><?php echo htmlspecialchars($student['department_name']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Level:</div>
                    <div class="info-value"><?php echo $student['current_level']; ?> Level</div>
                </div>
            </div>
            
            <div class="student-photo">
                <?php if($profile_pic_path): ?>
                    <img src="<?php echo $profile_pic_path; ?>" alt="Student Photo">
                <?php else: ?>
                    <div class="photo-placeholder">📸</div>
                <?php endif; ?>
                <div class="photo-label">Passport Photograph</div>
            </div>
        </div>
        
        <table class="<?php echo count($courses) > 10 ? 'compact-table' : ''; ?>">
            <thead>
                <tr>
                    <th width="5%">S/N</th>
                    <th width="20%">Course Code</th>
                    <th width="50%">Course Title</th>
                    <th width="10%">Unit</th>
                    <th width="15%">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($courses)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center;">No courses registered for this semester</td>
                    </tr>
                <?php else: ?>
                    <?php $sn = 1; foreach($courses as $course): ?>
                    <tr>
                        <td><?php echo $sn++; ?></td>
                        <td><?php echo htmlspecialchars($course['code']); ?></td>
                        <td><?php echo htmlspecialchars($course['title']); ?></td>
                        <td style="text-align: center;"><?php echo $course['credit_unit']; ?></td>
                        <td style="text-align: center;">✓ Registered</td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="3"><strong>TOTAL CREDIT UNITS</strong></td>
                        <td style="text-align: center;"><strong><?php echo $total_credits; ?></strong></td>
                        <td></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="declaration">
            <p>I hereby declare that the courses listed above are correctly registered for the <?php echo $current_semester['name']; ?> Semester, <?php echo $current_semester['session_name']; ?> academic session.</p>
        </div>
        
        <div class="signature-section">
            <div class="signature-line">
                <div class="signature-title">STUDENT'S SIGNATURE & DATE</div>
                <div class="line"></div>
            </div>
            <div class="signature-line">
                <div class="signature-title">ACADEMIC ADVISOR'S SIGNATURE</div>
                <div class="line"></div>
            </div>
            <div class="signature-line">
                <div class="signature-title">HOD'S SIGNATURE</div>
                <div class="line"></div>
            </div>
            <div class="signature-line">
                <div class="signature-title">DEAN'S SIGNATURE</div>
                <div class="line"></div>
            </div>
        </div>
        
        <div class="footer">
            <p>Generated on: <?php echo date('F j, Y g:i A'); ?> | <?php echo htmlspecialchars($app_name); ?> - Student Portal</p>
        </div>
    </div>
    
    <div style="text-align: center; margin-top: 15px;" class="no-print">
        <button onclick="window.print()" class="btn">🖨️ Print Registration Form</button>
        <button onclick="window.close()" class="btn" style="background: #718096;">Close</button>
    </div>
    
    <script>
        // Auto-trigger print dialog when page loads (optional)
        // window.print();
    </script>
</body>
</html>
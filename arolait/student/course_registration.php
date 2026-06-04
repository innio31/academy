<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole(['student']);

$message = '';
$error = '';

// Get current semester and registration settings
$stmt = $pdo->prepare("
    SELECT s.*, a.name as session_name,
           s.is_current as semester_current,
           a.is_current as session_current
    FROM semesters s
    JOIN academic_sessions a ON s.session_id = a.id
    WHERE s.is_current = 1 AND a.is_current = 1
    LIMIT 1
");
$stmt->execute();
$current_semester = $stmt->fetch();

if (!$current_semester) {
    header("Location: index.php?error=No active semester found");
    exit();
}

// Get student info
$stmt = $pdo->prepare("
    SELECT s.*, d.name as department_name, d.id as department_id
    FROM students s
    JOIN departments d ON s.department_id = d.id
    WHERE s.id = ?
");
$stmt->execute([$_SESSION['student_id']]);
$student = $stmt->fetch();

if (!$student) {
    header("Location: index.php?error=Student record not found");
    exit();
}

// Get already registered courses from student_course_registrations
$registered_ids = [];
$registered_offerings = [];
$stmt = $pdo->prepare("
    SELECT offering_id, course_id 
    FROM student_course_registrations scr
    JOIN course_offerings co ON scr.offering_id = co.id
    WHERE scr.student_id = ? AND co.semester_id = ? AND scr.status = 'registered'
");
$stmt->execute([$_SESSION['student_id'], $current_semester['id']]);
foreach($stmt->fetchAll() as $reg) {
    $registered_ids[] = $reg['course_id'];
    $registered_offerings[] = $reg['offering_id'];
}

// Get available courses from course_offerings for the current semester
$stmt = $pdo->prepare("
    SELECT 
        c.*, 
        d.name as department_name,
        co.id as offering_id,
        co.lecturer_id,
        CONCAT(u.first_name, ' ', u.last_name) as lecturer_name,
        CASE WHEN scr.student_id IS NOT NULL THEN 1 ELSE 0 END as is_registered,
        CASE WHEN c.department_id = ? THEN 1 ELSE 0 END as is_core_course
    FROM course_offerings co
    JOIN courses c ON co.course_id = c.id
    JOIN departments d ON c.department_id = d.id
    LEFT JOIN users u ON co.lecturer_id = u.id
    LEFT JOIN student_course_registrations scr ON co.id = scr.offering_id 
        AND scr.student_id = ? AND scr.status = 'registered'
    WHERE co.semester_id = ? 
        AND c.level <= ? 
        AND (c.department_id = ? OR c.is_elective = 1)
    GROUP BY c.id, co.id
    ORDER BY c.department_id = ? DESC, c.code
");
$stmt->execute([
    $student['department_id'],
    $_SESSION['student_id'],
    $current_semester['id'],
    $student['current_level'],
    $student['department_id'],
    $student['department_id']
]);
$available_courses = $stmt->fetchAll();

// Calculate current total credits
$current_credits = 0;
foreach($available_courses as $course) {
    if ($course['is_registered']) {
        $current_credits += $course['credit_unit'];
    }
}

// Define default credit limits (you can also add these to database)
$min_credits = 12;
$max_credits = 24;

// Handle registration submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_offerings = $_POST['offerings'] ?? [];
    $new_total_credits = 0;
    
    // Calculate new total credits
    foreach($available_courses as $course) {
        if (in_array($course['offering_id'], $selected_offerings)) {
            $new_total_credits += $course['credit_unit'];
        }
    }
    
    // Validate credit limits
    if ($new_total_credits < $min_credits) {
        $error = "Minimum credit unit required is $min_credits. You selected $new_total_credits credits.";
    } elseif ($new_total_credits > $max_credits) {
        $error = "Maximum credit unit allowed is $max_credits. You selected $new_total_credits credits.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Get current registered offerings
            $stmt = $pdo->prepare("
                SELECT offering_id FROM student_course_registrations 
                WHERE student_id = ? AND status = 'registered'
                AND offering_id IN (SELECT id FROM course_offerings WHERE semester_id = ?)
            ");
            $stmt->execute([$_SESSION['student_id'], $current_semester['id']]);
            $current_offerings = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Remove unselected courses (set status to 'dropped')
            $to_remove = array_diff($current_offerings, $selected_offerings);
            if (!empty($to_remove)) {
                $placeholders = implode(',', array_fill(0, count($to_remove), '?'));
                $stmt = $pdo->prepare("
                    UPDATE student_course_registrations 
                    SET status = 'dropped', dropped_at = NOW()
                    WHERE student_id = ? AND offering_id IN ($placeholders)
                ");
                $stmt->execute(array_merge([$_SESSION['student_id']], $to_remove));
            }
            
            // Add newly selected courses
            $to_add = array_diff($selected_offerings, $current_offerings);
            foreach($to_add as $offering_id) {
                $stmt = $pdo->prepare("
                    INSERT INTO student_course_registrations (student_id, offering_id, registered_at, status)
                    VALUES (?, ?, NOW(), 'registered')
                ");
                $stmt->execute([$_SESSION['student_id'], $offering_id]);
            }
            
            $pdo->commit();
            $message = "Course registration saved successfully! Total credits: $new_total_credits";
            
            // Refresh registered courses
            $registered_ids = [];
            $registered_offerings = $selected_offerings;
            $current_credits = $new_total_credits;
            
            // Update is_registered status in available_courses
            foreach($available_courses as &$course) {
                $course['is_registered'] = in_array($course['offering_id'], $selected_offerings) ? 1 : 0;
            }
            
        } catch(Exception $e) {
            $pdo->rollBack();
            $error = "Registration failed: " . $e->getMessage();
        }
    }
}

// Get registration statistics
$total_courses_selected = count(array_filter($available_courses, function($c) { return $c['is_registered']; }));
$core_courses = array_filter($available_courses, function($c) { return $c['is_core_course'] && $c['is_registered']; });
$elective_courses = array_filter($available_courses, function($c) { return !$c['is_core_course'] && $c['is_registered']; });
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Course Registration - Student Portal</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fb;
            padding: 16px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .header {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 24px; color: #2d3748; margin-bottom: 8px; }
        .header p { color: #718096; font-size: 14px; }
        
        .info-card {
            background: #e9f5ff;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 4px solid #4299e1;
        }
        
        .credit-summary {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .credit-box {
            text-align: center;
            flex: 1;
            padding: 15px;
            background: #f7fafc;
            border-radius: 10px;
        }
        
        .credit-label { font-size: 12px; color: #718096; margin-bottom: 5px; }
        .credit-value { font-size: 28px; font-weight: bold; color: #667eea; }
        .credit-unit { font-size: 12px; color: #718096; }
        
        .courses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .course-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid #e2e8f0;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .course-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .course-card.selected {
            border-color: #48bb78;
            background: #f0fff4;
        }
        
        .course-card.core-course {
            border-left: 4px solid #667eea;
        }
        
        .course-card.elective-course {
            border-left: 4px solid #ed8936;
        }
        
        .course-checkbox {
            padding: 16px;
            cursor: pointer;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        
        .course-checkbox input {
            width: 20px;
            height: 20px;
            margin-top: 2px;
            cursor: pointer;
        }
        
        .course-info { flex: 1; }
        .course-code { 
            font-weight: 700; 
            color: #2d3748;
            font-size: 14px;
        }
        .course-title { 
            font-size: 14px; 
            color: #4a5568; 
            margin: 5px 0;
            font-weight: 500;
        }
        .course-details { 
            font-size: 12px; 
            color: #718096;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 8px;
        }
        
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }
        .badge-core { background: #e0e7ff; color: #4338ca; }
        .badge-elective { background: #fed7aa; color: #7c2d12; }
        .badge-lecturer { background: #e2e8f0; color: #4a5568; }
        
        .btn {
            padding: 12px 24px;
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
        .btn-outline { background: transparent; border: 1px solid #667eea; color: #667eea; }
        .btn-outline:hover { background: #667eea; color: white; }
        
        .message {
            background: #c6f6d5;
            color: #22543d;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #48bb78;
        }
        .error {
            background: #fed7d7;
            color: #c53030;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #f56565;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        
        .registration-summary {
            background: white;
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        @media (max-width: 640px) {
            .courses-grid { grid-template-columns: 1fr; }
            .credit-summary { flex-direction: column; }
            .registration-summary { flex-direction: column; text-align: center; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h1>📝 Course Registration</h1>
                    <p><?php echo $current_semester['name']; ?> Semester, <?php echo $current_semester['session_name']; ?></p>
                    <p style="font-size: 13px; margin-top: 5px;">Level: <?php echo $student['current_level']; ?> | Department: <?php echo htmlspecialchars($student['department_name']); ?></p>
                </div>
                <a href="index.php" style="color: #667eea; text-decoration: none;">← Back to Dashboard</a>
            </div>
        </div>
        
        <div class="info-card">
            <strong>📌 Registration Guidelines:</strong><br>
            • Minimum Credits: <strong><?php echo $min_credits; ?></strong> units<br>
            • Maximum Credits: <strong><?php echo $max_credits; ?></strong> units<br>
            • Core courses (from your department) are highlighted with a blue border<br>
            • Elective courses are highlighted with an orange border<br>
            • Click on a course card to select/deselect<br>
            • Registration is for the current semester only
        </div>
        
        <?php if($message): ?>
            <div class="message">✓ <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if($error): ?>
            <div class="error">✗ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="credit-summary">
            <div class="credit-box">
                <div class="credit-label">Selected Credits</div>
                <div class="credit-value" id="totalCredits"><?php echo $current_credits; ?></div>
                <div class="credit-unit">credit units</div>
            </div>
            <div class="credit-box">
                <div class="credit-label">Minimum Required</div>
                <div class="credit-value"><?php echo $min_credits; ?></div>
                <div class="credit-unit">credit units</div>
            </div>
            <div class="credit-box">
                <div class="credit-label">Maximum Allowed</div>
                <div class="credit-value"><?php echo $max_credits; ?></div>
                <div class="credit-unit">credit units</div>
            </div>
        </div>
        
        <?php if($total_courses_selected > 0): ?>
        <div class="registration-summary">
            <div>📚 <strong><?php echo $total_courses_selected; ?></strong> course(s) selected</div>
            <div>🎓 <strong><?php echo count($core_courses); ?></strong> core course(s)</div>
            <div>⭐ <strong><?php echo count($elective_courses); ?></strong> elective(s)</div>
            <div>📊 Total: <strong><?php echo $current_credits; ?></strong> / <?php echo $max_credits; ?> credits</div>
        </div>
        <?php endif; ?>
        
        <form method="POST" action="" id="registrationForm">
            <div class="courses-grid" id="coursesGrid">
                <?php foreach($available_courses as $course): ?>
                    <div class="course-card <?php echo $course['is_registered'] ? 'selected' : ''; ?> <?php echo $course['is_core_course'] ? 'core-course' : 'elective-course'; ?>" 
                         data-offering-id="<?php echo $course['offering_id']; ?>"
                         data-credits="<?php echo $course['credit_unit']; ?>"
                         onclick="toggleCourse(this, <?php echo $course['offering_id']; ?>)">
                        <div class="course-checkbox">
                            <input type="checkbox" name="offerings[]" value="<?php echo $course['offering_id']; ?>"
                                   id="course_<?php echo $course['offering_id']; ?>"
                                   <?php echo $course['is_registered'] ? 'checked' : ''; ?>
                                   onclick="event.stopPropagation(); updateTotal()">
                            <div class="course-info">
                                <div class="course-code">
                                    <?php echo htmlspecialchars($course['code']); ?>
                                    <span class="badge <?php echo $course['is_core_course'] ? 'badge-core' : 'badge-elective'; ?>">
                                        <?php echo $course['is_core_course'] ? 'Core' : 'Elective'; ?>
                                    </span>
                                </div>
                                <div class="course-title"><?php echo htmlspecialchars($course['title']); ?></div>
                                <div class="course-details">
                                    <span>🎓 <?php echo $course['credit_unit']; ?> Units</span>
                                    <span>🏛️ <?php echo htmlspecialchars($course['department_name']); ?></span>
                                    <?php if($course['lecturer_name']): ?>
                                        <span class="badge badge-lecturer">👨‍🏫 <?php echo htmlspecialchars($course['lecturer_name']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if(empty($available_courses)): ?>
                <div style="background: #feebc8; padding: 40px; text-align: center; border-radius: 12px;">
                    <p>No courses available for registration at this level.</p>
                    <p style="font-size: 13px; margin-top: 10px;">Please contact your HOD for course offerings.</p>
                </div>
            <?php endif; ?>
            
            <div class="action-buttons">
                <button type="button" onclick="selectAll()" class="btn btn-outline">✓ Select All</button>
                <button type="button" onclick="deselectAll()" class="btn btn-outline">✗ Deselect All</button>
                <button type="submit" class="btn btn-primary">💾 Save Registration</button>
                <button type="button" onclick="printRegistration()" class="btn btn-success">🖨️ Print Registration</button>
            </div>
        </form>
    </div>
    
    <script>
        function updateTotal() {
            let total = 0;
            const checkboxes = document.querySelectorAll('input[name="offerings[]"]:checked');
            checkboxes.forEach(checkbox => {
                const card = checkbox.closest('.course-card');
                const credits = parseInt(card.dataset.credits);
                total += credits;
            });
            document.getElementById('totalCredits').innerText = total;
            
            // Update card styling
            document.querySelectorAll('.course-card').forEach(card => {
                const checkbox = card.querySelector('input');
                if (checkbox.checked) {
                    card.classList.add('selected');
                } else {
                    card.classList.remove('selected');
                }
            });
        }
        
        function toggleCourse(cardElement, offeringId) {
            const checkbox = document.getElementById(`course_${offeringId}`);
            if (checkbox) {
                checkbox.checked = !checkbox.checked;
                updateTotal();
            }
        }
        
        function selectAll() {
            document.querySelectorAll('input[name="offerings[]"]').forEach(cb => cb.checked = true);
            updateTotal();
        }
        
        function deselectAll() {
            document.querySelectorAll('input[name="offerings[]"]').forEach(cb => cb.checked = false);
            updateTotal();
        }
        
        function printRegistration() {
            // Get selected courses
            const selectedCourses = [];
            document.querySelectorAll('input[name="offerings[]"]:checked').forEach(checkbox => {
                const card = checkbox.closest('.course-card');
                const courseCode = card.querySelector('.course-code').innerText.split(' ')[0];
                const courseTitle = card.querySelector('.course-title').innerText;
                const credits = card.dataset.credits;
                selectedCourses.push({ code: courseCode, title: courseTitle, credits: credits });
            });
            
            const totalCredits = document.getElementById('totalCredits').innerText;
            const studentName = '<?php echo addslashes($_SESSION['user_name']); ?>';
            const regNumber = '<?php echo addslashes($student['reg_number']); ?>';
            const department = '<?php echo addslashes($student['department_name']); ?>';
            const level = '<?php echo $student['current_level']; ?>';
            const semester = '<?php echo addslashes($current_semester['name']); ?>';
            const session = '<?php echo addslashes($current_semester['session_name']); ?>';
            
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                    <head>
                        <title>Course Registration Form</title>
                        <style>
                            body { font-family: Arial, sans-serif; padding: 40px; }
                            .header { text-align: center; margin-bottom: 30px; }
                            .student-info { margin-bottom: 20px; padding: 10px; background: #f7fafc; }
                            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                            th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
                            th { background: #667eea; color: white; }
                            .total { margin-top: 20px; text-align: right; font-weight: bold; }
                            .footer { margin-top: 40px; text-align: center; font-size: 12px; }
                        </style>
                    </head>
                    <body>
                        <div class="header">
                            <h2>Course Registration Form</h2>
                            <p>${semester} Semester, ${session}</p>
                        </div>
                        <div class="student-info">
                            <p><strong>Student Name:</strong> ${studentName}</p>
                            <p><strong>Registration Number:</strong> ${regNumber}</p>
                            <p><strong>Department:</strong> ${department}</p>
                            <p><strong>Level:</strong> ${level}</p>
                        </div>
                        <table>
                            <thead>
                                <tr><th>S/N</th><th>Course Code</th><th>Course Title</th><th>Credit Units</th></tr>
                            </thead>
                            <tbody>
                                ${selectedCourses.map((c, i) => `<tr><td>${i+1}</td><td>${c.code}</td><td>${c.title}</td><td>${c.credits}</td></tr>`).join('')}
                            </tbody>
                        </table>
                        <div class="total">
                            <p>Total Credit Units: ${totalCredits}</p>
                        </div>
                        <div class="footer">
                            <p>This is a computer-generated registration form. No signature required.</p>
                            <p>Generated on: ${new Date().toLocaleString()}</p>
                        </div>
                        <script>window.print();<\/script>
                    </body>
                </html>
            `);
            printWindow.document.close();
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateTotal();
        });
    </script>
</body>
</html>
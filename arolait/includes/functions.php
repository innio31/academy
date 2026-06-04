<?php
// Generate QR Code for student
function generateStudentQR($reg_number, $student_id, $pdo) {
    $qr_dir = __DIR__ . '/../assets/qrcodes/';
    if (!file_exists($qr_dir)) {
        mkdir($qr_dir, 0777, true);
    }
    
    $qr_data = urlencode(json_encode([
        'reg_no' => $reg_number,
        'student_id' => $student_id,
        'type' => 'student_attendance'
    ]));
    
    $filename = $reg_number . '.png';
    $filepath = $qr_dir . $filename;
    
    // Using Google Charts API
    $qr_url = "https://chart.googleapis.com/chart?chs=250x250&cht=qr&chl=" . $qr_data . "&choe=UTF-8";
    $qr_image = @file_get_contents($qr_url);
    
    if ($qr_image !== false) {
        file_put_contents($filepath, $qr_image);
        
        // Update database with QR path
        $stmt = $pdo->prepare("UPDATE students SET qr_code = ? WHERE id = ?");
        $stmt->execute(['assets/qrcodes/' . $filename, $student_id]);
        
        return 'assets/qrcodes/' . $filename;
    }
    
    return false;
}

// Get student full details
function getStudentDetails($student_id, $pdo) {
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
    return $stmt->fetch();
}

// =============================================
// GRADE CALCULATION FUNCTIONS (PHP-Based)
// =============================================

/**
 * Calculate grade based on total score
 * Follows your institution's grading scale
 */
function calculateGrade($total_score) {
    if ($total_score >= 75) return 'A';
    if ($total_score >= 70) return 'AB';
    if ($total_score >= 65) return 'B';
    if ($total_score >= 60) return 'BC';
    if ($total_score >= 55) return 'C';
    if ($total_score >= 50) return 'CD';
    if ($total_score >= 45) return 'D';
    if ($total_score >= 40) return 'E';
    return 'F';
}

/**
 * Calculate grade point based on total score
 */
function calculateGradePoint($total_score) {
    if ($total_score >= 75) return 4.00;
    if ($total_score >= 70) return 3.50;
    if ($total_score >= 65) return 3.25;
    if ($total_score >= 60) return 3.00;
    if ($total_score >= 55) return 2.75;
    if ($total_score >= 50) return 2.50;
    if ($total_score >= 45) return 2.25;
    if ($total_score >= 40) return 2.00;
    return 0.00;
}

/**
 * Calculate Course Unit Point (CUP) = Grade Point × Credit Unit
 */
function calculateCUP($grade_point, $credit_unit) {
    return $grade_point * $credit_unit;
}

/**
 * Calculate GPA for a semester
 * GPA = Total CUP ÷ Total CU
 */
function calculateGPA($results) {
    $total_cup = 0;
    $total_cu = 0;
    
    foreach ($results as $result) {
        $total_cup += $result['course_unit_point'];
        $total_cu += $result['credit_unit'];
    }
    
    return $total_cu > 0 ? $total_cup / $total_cu : 0;
}

/**
 * Calculate CGPA across multiple semesters
 * CGPA = Sum of all CUP ÷ Sum of all CU
 */
function calculateCGPA($semester_results) {
    $total_cup = 0;
    $total_cu = 0;
    
    foreach ($semester_results as $semester) {
        $total_cup += $semester['total_cup'];
        $total_cu += $semester['total_cu'];
    }
    
    return $total_cu > 0 ? $total_cup / $total_cu : 0;
}

/**
 * Get academic standing based on CGPA
 */
function getAcademicStanding($cgpa) {
    if ($cgpa >= 4.50) return ['First Class Honours', 'first-class'];
    if ($cgpa >= 3.50) return ['Second Class Honours (Upper)', 'second-upper'];
    if ($cgpa >= 2.50) return ['Second Class Honours (Lower)', 'second-lower'];
    if ($cgpa >= 1.50) return ['Third Class Honours', 'third-class'];
    return ['Probation', 'probation'];
}

/**
 * Process and save a single result with grade calculation
 */
function processResult($pdo, $student_id, $course_id, $semester_id, $session_id, $ca_score, $exam_score, $credit_unit) {
    // Calculate total score
    $total_score = ($ca_score + $exam_score) / 2;
    
    // Calculate grade and grade point
    $grade = calculateGrade($total_score);
    $grade_point = calculateGradePoint($total_score);
    $course_unit_point = calculateCUP($grade_point, $credit_unit);
    
    // Check if result exists
    $stmt = $pdo->prepare("SELECT id FROM results WHERE student_id = ? AND course_id = ? AND semester_id = ?");
    $stmt->execute([$student_id, $course_id, $semester_id]);
    
    if ($stmt->fetch()) {
        // Update existing
        $stmt = $pdo->prepare("
            UPDATE results 
            SET ca_score = ?, exam_score = ?, total_score = ?, 
                grade = ?, grade_point = ?, course_unit_point = ?,
                is_approved = 0
            WHERE student_id = ? AND course_id = ? AND semester_id = ?
        ");
        return $stmt->execute([
            $ca_score, $exam_score, $total_score, $grade, $grade_point, $course_unit_point,
            $student_id, $course_id, $semester_id
        ]);
    } else {
        // Insert new
        $stmt = $pdo->prepare("
            INSERT INTO results (student_id, course_id, semester_id, session_id, 
                                ca_score, exam_score, total_score, credit_unit,
                                grade, grade_point, course_unit_point, is_approved)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
        ");
        return $stmt->execute([
            $student_id, $course_id, $semester_id, $session_id,
            $ca_score, $exam_score, $total_score, $credit_unit,
            $grade, $grade_point, $course_unit_point
        ]);
    }
}

/**
 * Get student results for a semester with calculations
 */
function getStudentSemesterResults($pdo, $student_id, $semester_id) {
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            c.code as course_code,
            c.title as course_title,
            c.credit_unit
        FROM results r
        JOIN courses c ON r.course_id = c.id
        WHERE r.student_id = ? AND r.semester_id = ? AND r.is_approved = 1
        ORDER BY c.code
    ");
    $stmt->execute([$student_id, $semester_id]);
    return $stmt->fetchAll();
}

/**
 * Get semester totals (CU and CUP)
 */
function getSemesterTotals($pdo, $student_id, $semester_id) {
    $stmt = $pdo->prepare("
        SELECT 
            SUM(credit_unit) as total_cu,
            SUM(course_unit_point) as total_cup
        FROM results
        WHERE student_id = ? AND semester_id = ? AND is_approved = 1
    ");
    $stmt->execute([$student_id, $semester_id]);
    return $stmt->fetch();
}

/**
 * Get CGPA for a student across a session
 */
function getStudentCGPA($pdo, $student_id, $session_id) {
    $stmt = $pdo->prepare("
        SELECT 
            SUM(credit_unit) as total_cu,
            SUM(course_unit_point) as total_cup
        FROM results r
        JOIN semesters s ON r.semester_id = s.id
        WHERE r.student_id = ? AND s.session_id = ? AND r.is_approved = 1
    ");
    $stmt->execute([$student_id, $session_id]);
    $totals = $stmt->fetch();
    
    if ($totals && $totals['total_cu'] > 0) {
        return $totals['total_cup'] / $totals['total_cu'];
    }
    return 0;
}

?>
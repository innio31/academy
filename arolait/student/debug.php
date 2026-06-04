<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';
require_once '../includes/auth.php';

echo "<h1>Debug Information</h1>";

// Check if user is logged in
echo "<h2>Session:</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

// Check student data
echo "<h2>Student Query:</h2>";
$stmt = $pdo->prepare("
    SELECT s.*, u.email, u.first_name, u.last_name, u.phone, u.profile_pic,
           d.name as department_name
    FROM students s
    JOIN users u ON s.user_id = u.id
    JOIN departments d ON s.department_id = d.id
    WHERE s.id = ?
");
$stmt->execute([$_SESSION['student_id']]);
$student = $stmt->fetch();

if ($student) {
    echo "<p style='color:green'>✓ Student found: " . $student['first_name'] . "</p>";
    echo "<pre>";
    print_r($student);
    echo "</pre>";
} else {
    echo "<p style='color:red'>✗ Student not found</p>";
}

// Check current semester
echo "<h2>Current Semester Query:</h2>";
$stmt = $pdo->prepare("
    SELECT s.*, a.name as session_name
    FROM semesters s
    JOIN academic_sessions a ON s.session_id = a.id
    WHERE s.is_current = 1 AND a.is_current = 1
    LIMIT 1
");
$stmt->execute();
$current_semester = $stmt->fetch();

if ($current_semester) {
    echo "<p style='color:green'>✓ Current semester found: " . $current_semester['name'] . "</p>";
} else {
    echo "<p style='color:red'>✗ No current semester found</p>";
}

// Check registered courses
echo "<h2>Registered Courses Query:</h2>";
if ($current_semester) {
    $stmt = $pdo->prepare("
        SELECT 
            scr.id as registration_id,
            c.id as course_id,
            c.code,
            c.title,
            c.credit_unit,
            scr.registered_at,
            scr.status
        FROM student_course_registrations scr
        JOIN course_offerings co ON scr.offering_id = co.id
        JOIN courses c ON co.course_id = c.id
        WHERE scr.student_id = ? AND co.semester_id = ? AND scr.status = 'registered'
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['student_id'], $current_semester['id']]);
    $courses = $stmt->fetchAll();
    
    if ($courses) {
        echo "<p style='color:green'>✓ Found " . count($courses) . " registered courses</p>";
    } else {
        echo "<p style='color:orange'>⚠ No registered courses found</p>";
    }
}

echo "<h2>Check if index.php has syntax errors:</h2>";
// Test include the actual file to see error
try {
    include 'index.php';
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}
?>
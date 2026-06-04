<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

// Get the previous semester
$stmt = $pdo->query("
    SELECT s.*, a.name as session_name 
    FROM semesters s 
    JOIN academic_sessions a ON s.session_id = a.id 
    WHERE s.is_current = 0 
    ORDER BY a.start_date DESC, s.id DESC 
    LIMIT 1
");
$previous_semester = $stmt->fetch();

if (!$previous_semester) {
    header("Location: courses.php?view=offerings&error=No previous semester found to copy from");
    exit();
}

// Get current semester
$current_semester = $pdo->query("
    SELECT s.*, a.name as session_name 
    FROM semesters s 
    JOIN academic_sessions a ON s.session_id = a.id 
    WHERE s.is_current = 1 
    LIMIT 1
")->fetch();

if (!$current_semester) {
    header("Location: courses.php?view=offerings&error=No current semester found. Please set a current semester first.");
    exit();
}

// Get offerings from previous semester
$stmt = $pdo->prepare("
    SELECT course_id, lecturer_id, max_students 
    FROM course_offerings 
    WHERE semester_id = ?
");
$stmt->execute([$previous_semester['id']]);
$offerings = $stmt->fetchAll();

$copied = 0;
$skipped = 0;

foreach ($offerings as $offering) {
    // Check if already exists in current semester
    $check = $pdo->prepare("SELECT id FROM course_offerings WHERE course_id = ? AND semester_id = ?");
    $check->execute([$offering['course_id'], $current_semester['id']]);
    
    if ($check->rowCount() == 0) {
        // Copy the offering
        $insert = $pdo->prepare("
            INSERT INTO course_offerings (course_id, semester_id, lecturer_id, max_students)
            VALUES (?, ?, ?, ?)
        ");
        $insert->execute([$offering['course_id'], $current_semester['id'], 
                         $offering['lecturer_id'], $offering['max_students']]);
        $copied++;
    } else {
        $skipped++;
    }
}

header("Location: courses.php?view=offerings&message=Copied {$copied} courses from {$previous_semester['session_name']} {$previous_semester['name']} Semester. Skipped {$skipped} duplicates.");
exit();
?>
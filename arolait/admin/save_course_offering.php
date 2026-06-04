<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: courses.php?view=offerings&error=Invalid request");
    exit();
}

$course_id = $_POST['course_id'] ?? 0;
$semester_id = $_POST['semester_id'] ?? 0;
$lecturer_id = $_POST['lecturer_id'] ?: null;
$max_students = $_POST['max_students'] ?: null;

// Validation
if (empty($course_id) || empty($semester_id)) {
    header("Location: courses.php?view=offerings&error=Please select both course and semester");
    exit();
}

try {
    // Check if this course is already offered in this semester
    $check = $pdo->prepare("SELECT id FROM course_offerings WHERE course_id = ? AND semester_id = ?");
    $check->execute([$course_id, $semester_id]);
    if ($check->rowCount() > 0) {
        header("Location: courses.php?view=offerings&error=This course is already offered in the selected semester");
        exit();
    }
    
    // Get course details to verify it exists and is active
    $stmt = $pdo->prepare("SELECT id, code, title FROM courses WHERE id = ? AND is_active = 1");
    $stmt->execute([$course_id]);
    $course = $stmt->fetch();
    
    if (!$course) {
        header("Location: courses.php?view=offerings&error=Course not found or inactive");
        exit();
    }
    
    // Insert course offering
    $stmt = $pdo->prepare("
        INSERT INTO course_offerings (course_id, semester_id, lecturer_id, max_students)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$course_id, $semester_id, $lecturer_id, $max_students]);
    
    header("Location: courses.php?view=offerings&message=Course offering created successfully for " . htmlspecialchars($course['code']));
} catch(PDOException $e) {
    header("Location: courses.php?view=offerings&error=" . urlencode($e->getMessage()));
}
exit();
?>
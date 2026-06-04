<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: courses.php?view=templates&error=Invalid request");
    exit();
}

$course_id = $_POST['course_id'] ?? 0;
$code = strtoupper(trim($_POST['code']));
$title = trim($_POST['title']);
$credit_unit = $_POST['credit_unit'];
$level = $_POST['level'];
$department_id = $_POST['department_id'];
$is_elective = isset($_POST['is_elective']) ? 1 : 0;
$is_active = isset($_POST['is_active']) ? 1 : 0;

// Validation
if (empty($code) || empty($title) || empty($credit_unit) || empty($department_id)) {
    header("Location: courses.php?view=templates&error=Please fill all required fields");
    exit();
}

try {
    if ($course_id) {
        // Update existing course template
        $stmt = $pdo->prepare("
            UPDATE courses 
            SET code = ?, title = ?, credit_unit = ?, level = ?, 
                department_id = ?, is_elective = ?, is_active = ?
            WHERE id = ?
        ");
        $stmt->execute([$code, $title, $credit_unit, $level, $department_id, 
                       $is_elective, $is_active, $course_id]);
        header("Location: courses.php?view=templates&message=Course template updated successfully");
    } else {
        // Check if course code already exists
        $check = $pdo->prepare("SELECT id FROM courses WHERE code = ?");
        $check->execute([$code]);
        if ($check->rowCount() > 0) {
            header("Location: courses.php?view=templates&error=Course code already exists");
            exit();
        }
        
        // Insert new course template
        $stmt = $pdo->prepare("
            INSERT INTO courses (code, title, credit_unit, level, department_id, is_elective, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$code, $title, $credit_unit, $level, $department_id, $is_elective, $is_active]);
        header("Location: courses.php?view=templates&message=Course template added successfully");
    }
} catch(PDOException $e) {
    header("Location: courses.php?view=templates&error=" . urlencode($e->getMessage()));
}
exit();
?>
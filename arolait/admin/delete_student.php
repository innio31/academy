<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin']); // Only super admin can delete students

$student_id = $_GET['id'] ?? 0;

if (!$student_id) {
    header("Location: students.php?error=Invalid student ID");
    exit();
}

try {
    // Get user_id before deleting student
    $stmt = $pdo->prepare("SELECT user_id FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    $user_id = $stmt->fetch()['user_id'];
    
    if ($user_id) {
        // Start transaction
        $pdo->beginTransaction();
        
        // Delete student record (will cascade to related records if set up)
        $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
        $stmt->execute([$student_id]);
        
        // Delete user account
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        
        $pdo->commit();
        
        header("Location: students.php?message=Student deleted successfully");
    } else {
        header("Location: students.php?error=Student not found");
    }
} catch(PDOException $e) {
    header("Location: students.php?error=Cannot delete student with existing records: " . urlencode($e->getMessage()));
}
exit();
?>
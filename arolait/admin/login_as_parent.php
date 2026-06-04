<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

$user_id = $_GET['user_id'] ?? 0;

if (!$user_id) {
    header("Location: parents.php?error=Invalid user ID");
    exit();
}

// Get parent details
$stmt = $pdo->prepare("
    SELECT u.*, p.student_id 
    FROM users u 
    JOIN parents p ON u.id = p.user_id 
    WHERE u.id = ? AND u.role = 'parent'
");
$stmt->execute([$user_id]);
$parent = $stmt->fetch();

if (!$parent) {
    header("Location: parents.php?error=Parent not found");
    exit();
}

// Store current admin session to restore later (optional)
$_SESSION['impersonating'] = $_SESSION['user_id'];
$_SESSION['impersonating_role'] = $_SESSION['role'];

// Set parent session
$_SESSION['user_id'] = $parent['id'];
$_SESSION['user_email'] = $parent['email'];
$_SESSION['user_name'] = $parent['first_name'] . ' ' . $parent['last_name'];
$_SESSION['role'] = 'parent';
$_SESSION['parent_id'] = $parent['id'];
$_SESSION['monitored_student_id'] = $parent['student_id'];

header("Location: ../parent/index.php");
exit();
?>
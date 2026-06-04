<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

// Get department ID from query string
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;

if ($department_id <= 0) {
    header("Location: departments.php?error=Invalid department ID");
    exit();
}

// Remove HOD assignment
$stmt = $pdo->prepare("UPDATE departments SET hod_id = NULL WHERE id = ?");
$result = $stmt->execute([$department_id]);

if ($result) {
    header("Location: departments.php?message=HOD removed successfully");
} else {
    header("Location: departments.php?error=Failed to remove HOD");
}
exit();
?>
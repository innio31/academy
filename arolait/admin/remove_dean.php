<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

// Get faculty ID from query string
$faculty_id = isset($_GET['faculty_id']) ? (int)$_GET['faculty_id'] : 0;

if ($faculty_id <= 0) {
    header("Location: faculties.php?error=Invalid faculty ID");
    exit();
}

// Remove dean assignment
$stmt = $pdo->prepare("UPDATE faculties SET dean_id = NULL WHERE id = ?");
$result = $stmt->execute([$faculty_id]);

if ($result) {
    header("Location: faculties.php?message=Dean removed successfully");
} else {
    header("Location: faculties.php?error=Failed to remove dean");
}
exit();
?>
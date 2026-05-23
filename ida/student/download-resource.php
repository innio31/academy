<?php
// ida/student/download-resource.php - Download Resource
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: /ida/login.php");
    exit();
}

require_once '../includes/config.php';

$resource_id = $_GET['id'] ?? 0;
$student_id = $_SESSION['user_id'];
$student_class = $_SESSION['user_class'] ?? '';

// Get resource
$stmt = $pdo->prepare("
    SELECT * FROM library_resources 
    WHERE id = ? AND school_id = ? AND (class = ? OR class = 'All' OR class = '')
");
$stmt->execute([$resource_id, SCHOOL_ID, $student_class]);
$resource = $stmt->fetch();

if (!$resource) {
    die("Resource not found or access denied");
}

// Track download (optional)
$file_path = '../' . $resource['file_path'];

if (file_exists($file_path)) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($resource['title']) . '.' . $resource['file_type'] . '"');
    header('Content-Length: ' . filesize($file_path));
    readfile($file_path);
    exit;
} else {
    die("File not found");
}

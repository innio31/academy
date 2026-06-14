<?php
// eagles/student/download-resource.php - Download Resource (Updated for Multi-Class Support)
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: /eagles/login.php");
    exit();
}

require_once '../includes/config.php';

$resource_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$student_id = $_SESSION['user_id'];

// Get student's class
$stmt = $pdo->prepare("SELECT class FROM students WHERE id = ? AND school_id = ?");
$stmt->execute([$student_id, SCHOOL_ID]);
$student = $stmt->fetch();

if (!$student) {
    die("Student record not found");
}

$student_class = $student['class'];

// Get resource - handle comma-separated classes (e.g., "JSS 1,SSS 1,Primary 5")
$stmt = $pdo->prepare("
    SELECT * FROM library_resources 
    WHERE id = ? AND school_id = ?
");
$stmt->execute([$resource_id, SCHOOL_ID]);
$resource = $stmt->fetch();

if (!$resource) {
    die("Resource not found");
}

// Check if student has access to this resource
$has_access = false;

// Check if resource is for 'All' classes
if ($resource['class'] === 'All') {
    $has_access = true;
}
// Check if resource class is empty
elseif (empty($resource['class'])) {
    $has_access = true;
}
// Check if student's class is in the comma-separated list
elseif (strpos($resource['class'], ',') !== false) {
    // Multiple classes - check if student's class is in the list
    $classes = array_map('trim', explode(',', $resource['class']));
    if (in_array($student_class, $classes)) {
        $has_access = true;
    }
}
// Single class - direct comparison
elseif ($resource['class'] === $student_class) {
    $has_access = true;
}

if (!$has_access) {
    die("Access denied. This resource is not available for your class.");
}

// Track download (optional - update download count)
try {
    $stmt = $pdo->prepare("UPDATE library_resources SET download_count = download_count + 1 WHERE id = ?");
    $stmt->execute([$resource_id]);
} catch (Exception $e) {
    // Column might not exist, ignore
}

// Get file path
$file_path = '../' . $resource['file_path'];

// Check if file exists
if (!file_exists($file_path)) {
    // Try alternative path
    $alt_path = dirname(__DIR__) . '/' . $resource['file_path'];
    if (file_exists($alt_path)) {
        $file_path = $alt_path;
    } else {
        die("File not found on server. Path: " . htmlspecialchars($resource['file_path']));
    }
}

// Get file extension for content type
$file_ext = strtolower(pathinfo($resource['file_path'], PATHINFO_EXTENSION));
$content_types = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'ppt' => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'mp4' => 'video/mp4',
    'mp3' => 'audio/mpeg',
    'zip' => 'application/zip',
    'txt' => 'text/plain'
];

$content_type = $content_types[$file_ext] ?? 'application/octet-stream';

// Force download
header('Content-Type: ' . $content_type);
header('Content-Disposition: attachment; filename="' . basename($resource['title']) . '.' . $file_ext . '"');
header('Content-Length: ' . filesize($file_path));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: public');

// Clear output buffer
if (ob_get_level()) {
    ob_end_clean();
}

readfile($file_path);
exit;

<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: faculties.php?error=Invalid request");
    exit();
}

$name = trim($_POST['name'] ?? '');
$code = trim($_POST['code'] ?? '');
$description = trim($_POST['description'] ?? '');
$dean_user_id = isset($_POST['dean_user_id']) ? (int)$_POST['dean_user_id'] : null;

// Validate required fields
if (empty($name) || empty($code)) {
    header("Location: faculties.php?error=Faculty name and code are required");
    exit();
}

// Check if code already exists
$stmt = $pdo->prepare("SELECT id FROM faculties WHERE code = ?");
$stmt->execute([$code]);
if ($stmt->fetch()) {
    header("Location: faculties.php?error=Faculty code already exists");
    exit();
}

// Verify dean if provided
if ($dean_user_id && $dean_user_id > 0) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'staff' AND is_active = 1");
    $stmt->execute([$dean_user_id]);
    if (!$stmt->fetch()) {
        header("Location: faculties.php?error=Selected dean is not a valid staff member");
        exit();
    }
}

// Insert new faculty
if ($dean_user_id && $dean_user_id > 0) {
    $stmt = $pdo->prepare("INSERT INTO faculties (name, code, description, dean_id) VALUES (?, ?, ?, ?)");
    $result = $stmt->execute([$name, $code, $description, $dean_user_id]);
} else {
    $stmt = $pdo->prepare("INSERT INTO faculties (name, code, description) VALUES (?, ?, ?)");
    $result = $stmt->execute([$name, $code, $description]);
}

if ($result) {
    header("Location: faculties.php?message=Faculty added successfully");
} else {
    header("Location: faculties.php?error=Failed to add faculty");
}
exit();
?>
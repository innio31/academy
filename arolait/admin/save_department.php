<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: departments.php?error=Invalid request");
    exit();
}

$faculty_id = (int)$_POST['faculty_id'];
$name = trim($_POST['name']);
$code = trim($_POST['code']);
$hod_user_id = isset($_POST['hod_user_id']) ? (int)$_POST['hod_user_id'] : null;

// Validate required fields
if ($faculty_id <= 0 || empty($name) || empty($code)) {
    header("Location: departments.php?error=All fields are required");
    exit();
}

// Check if code already exists
$stmt = $pdo->prepare("SELECT id FROM departments WHERE code = ?");
$stmt->execute([$code]);
if ($stmt->fetch()) {
    header("Location: departments.php?error=Department code already exists");
    exit();
}

// Verify faculty exists
$stmt = $pdo->prepare("SELECT id FROM faculties WHERE id = ?");
$stmt->execute([$faculty_id]);
if (!$stmt->fetch()) {
    header("Location: departments.php?error=Selected faculty does not exist");
    exit();
}

// Verify HOD if provided
if ($hod_user_id && $hod_user_id > 0) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'staff' AND is_active = 1");
    $stmt->execute([$hod_user_id]);
    if (!$stmt->fetch()) {
        header("Location: departments.php?error=Selected HOD is not a valid staff member");
        exit();
    }
}

// Insert new department
if ($hod_user_id && $hod_user_id > 0) {
    $stmt = $pdo->prepare("INSERT INTO departments (faculty_id, name, code, hod_id) VALUES (?, ?, ?, ?)");
    $result = $stmt->execute([$faculty_id, $name, $code, $hod_user_id]);
} else {
    $stmt = $pdo->prepare("INSERT INTO departments (faculty_id, name, code) VALUES (?, ?, ?)");
    $result = $stmt->execute([$faculty_id, $name, $code]);
}

if ($result) {
    header("Location: departments.php?message=Department added successfully");
} else {
    header("Location: departments.php?error=Failed to add department");
}
exit();
?>
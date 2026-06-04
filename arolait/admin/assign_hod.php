<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: departments.php?error=Invalid request");
    exit();
}

// Get form data
$department_id = isset($_POST['department_id']) ? (int)$_POST['department_id'] : 0;
$staff_id = isset($_POST['staff_id']) ? (int)$_POST['staff_id'] : 0;
$user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

// Validate department exists
if ($department_id <= 0) {
    header("Location: departments.php?error=Invalid department selected");
    exit();
}

// Determine which ID to use (staff_id or user_id)
$hod_user_id = 0;

if ($staff_id > 0) {
    // Get user_id from staff table using staff_id
    $stmt = $pdo->prepare("SELECT user_id FROM staff WHERE id = ?");
    $stmt->execute([$staff_id]);
    $result = $stmt->fetch();
    
    if ($result) {
        $hod_user_id = $result['user_id'];
    } else {
        header("Location: departments.php?error=Staff member not found");
        exit();
    }
} elseif ($user_id > 0) {
    // Verify that this user_id belongs to a staff member
    $stmt = $pdo->prepare("SELECT id FROM staff WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    
    if ($result) {
        $hod_user_id = $user_id;
    } else {
        header("Location: departments.php?error=User ID does not belong to a staff member");
        exit();
    }
} else {
    header("Location: departments.php?error=No staff member selected");
    exit();
}

// Verify the user exists and is active
$stmt = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE id = ? AND is_active = 1");
$stmt->execute([$hod_user_id]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: departments.php?error=Selected user not found or inactive");
    exit();
}

// Update the department with the new HOD
$stmt = $pdo->prepare("UPDATE departments SET hod_id = ? WHERE id = ?");
$result = $stmt->execute([$hod_user_id, $department_id]);

if ($result) {
    $hod_name = $user['first_name'] . ' ' . $user['last_name'];
    header("Location: departments.php?message=HOD {$hod_name} assigned successfully to department");
} else {
    header("Location: departments.php?error=Failed to assign HOD. Please try again.");
}
exit();
?>
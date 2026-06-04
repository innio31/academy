<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: parents.php?error=Invalid request");
    exit();
}

$parent_id = $_POST['parent_id'] ?? 0;
$first_name = trim($_POST['first_name']);
$last_name = trim($_POST['last_name']);
$email = trim($_POST['email']);
$phone = trim($_POST['phone']);
$password = $_POST['password'] ?? '';
$student_id = $_POST['student_id'];
$relationship = $_POST['relationship'] ?? null;
$can_view_results = isset($_POST['can_view_results']) ? 1 : 0;
$can_view_attendance = isset($_POST['can_view_attendance']) ? 1 : 0;

// Validation
if (empty($first_name) || empty($last_name) || empty($email) || empty($student_id)) {
    header("Location: parents.php?error=Please fill all required fields");
    exit();
}

if (!$parent_id && empty($password)) {
    header("Location: parents.php?error=Password is required for new parent");
    exit();
}

try {
    $pdo->beginTransaction();
    
    // Check if student exists
    $check = $pdo->prepare("SELECT id FROM students WHERE id = ?");
    $check->execute([$student_id]);
    if (!$check->fetch()) {
        throw new Exception("Selected student does not exist");
    }
    
    if ($parent_id) {
        // Get existing parent
        $stmt = $pdo->prepare("SELECT user_id FROM parents WHERE id = ?");
        $stmt->execute([$parent_id]);
        $user_id = $stmt->fetch()['user_id'];
        
        // Update user
        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?, password = ? WHERE id = ?");
            $stmt->execute([$first_name, $last_name, $email, $phone, $hashed_password, $user_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE id = ?");
            $stmt->execute([$first_name, $last_name, $email, $phone, $user_id]);
        }
        
        // Update parent
        $stmt = $pdo->prepare("
            UPDATE parents 
            SET student_id = ?, relationship = ?, can_view_results = ?, can_view_attendance = ?
            WHERE id = ?
        ");
        $stmt->execute([$student_id, $relationship, $can_view_results, $can_view_attendance, $parent_id]);
        
        header("Location: parents.php?message=Parent updated successfully");
    } else {
        // Check if email exists
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->rowCount() > 0) {
            throw new Exception("Email already exists");
        }
        
        // Check if parent already linked to this student
        $check = $pdo->prepare("SELECT id FROM parents WHERE student_id = ?");
        $check->execute([$student_id]);
        if ($check->rowCount() > 0) {
            throw new Exception("A parent is already linked to this student. Each student can have one parent account for monitoring.");
        }
        
        // Create user account
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            INSERT INTO users (email, password, role, first_name, last_name, phone, is_active) 
            VALUES (?, ?, 'parent', ?, ?, ?, 1)
        ");
        $stmt->execute([$email, $hashed_password, $first_name, $last_name, $phone]);
        $user_id = $pdo->lastInsertId();
        
        // Create parent record
        $stmt = $pdo->prepare("
            INSERT INTO parents (user_id, student_id, relationship, can_view_results, can_view_attendance) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $student_id, $relationship, $can_view_results, $can_view_attendance]);
        
        header("Location: parents.php?message=Parent added successfully for " . urlencode($first_name . ' ' . $last_name));
    }
    
    $pdo->commit();
} catch(Exception $e) {
    $pdo->rollBack();
    header("Location: parents.php?error=" . urlencode($e->getMessage()));
}
exit();
?>
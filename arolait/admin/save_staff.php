<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: staff.php?error=Invalid request");
    exit();
}

$staff_id = $_POST['staff_id'] ?? 0;
$first_name = trim($_POST['first_name']);
$last_name = trim($_POST['last_name']);
$email = trim($_POST['email']);
$phone = trim($_POST['phone']);
$password = $_POST['password'] ?? '';
$staff_number = trim($_POST['staff_number']);
$hire_date = $_POST['hire_date'] ?: null;
$department_id = $_POST['department_id'];
$designation = $_POST['designation'] ?: null;
$specialization = trim($_POST['specialization']) ?: null;

// Validation
if (empty($first_name) || empty($last_name) || empty($email) || empty($department_id)) {
    header("Location: staff.php?error=Please fill all required fields");
    exit();
}

if (!$staff_id && empty($password)) {
    header("Location: staff.php?error=Password is required for new staff");
    exit();
}

try {
    $pdo->beginTransaction();
    
    if ($staff_id) {
        // Get existing staff
        $stmt = $pdo->prepare("SELECT user_id FROM staff WHERE id = ?");
        $stmt->execute([$staff_id]);
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
        
        // Update staff
        $stmt = $pdo->prepare("
            UPDATE staff 
            SET staff_number = ?, department_id = ?, designation = ?, 
                specialization = ?, hire_date = ?
            WHERE id = ?
        ");
        $stmt->execute([$staff_number, $department_id, $designation, $specialization, $hire_date, $staff_id]);
        
        header("Location: staff.php?message=Staff updated successfully");
    } else {
        // Check if email exists
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->rowCount() > 0) {
            throw new Exception("Email already exists");
        }
        
        // Generate staff number if not provided
        if (empty($staff_number)) {
            $year = date('Y');
            $dept = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
            $dept->execute([$department_id]);
            $dept_code = $dept->fetch()['code'];
            
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM staff WHERE staff_number LIKE ?");
            $pattern = $dept_code . '/' . $year . '/%';
            $stmt->execute([$pattern]);
            $count = $stmt->fetch()['count'] + 1;
            $staff_number = $dept_code . '/' . $year . '/' . str_pad($count, 4, '0', STR_PAD_LEFT);
        }
        
        // Create user account
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            INSERT INTO users (email, password, role, first_name, last_name, phone, is_active) 
            VALUES (?, ?, 'staff', ?, ?, ?, 1)
        ");
        $stmt->execute([$email, $hashed_password, $first_name, $last_name, $phone]);
        $user_id = $pdo->lastInsertId();
        
        // Create staff record
        $stmt = $pdo->prepare("
            INSERT INTO staff (user_id, staff_number, department_id, designation, specialization, hire_date) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $staff_number, $department_id, $designation, $specialization, $hire_date]);
        
        header("Location: staff.php?message=Staff added successfully. Staff Number: " . urlencode($staff_number));
    }
    
    $pdo->commit();
} catch(Exception $e) {
    $pdo->rollBack();
    header("Location: staff.php?error=" . urlencode($e->getMessage()));
}
exit();
?>
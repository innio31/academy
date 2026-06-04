<?php
require_once 'includes/config.php';

$email = 'admin@university.edu';
$password = 'Admin@123';
$hashed_password = password_hash($password, PASSWORD_BCRYPT);

// Check if admin exists
$check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$check->execute([$email]);

if ($check->rowCount() == 0) {
    $stmt = $pdo->prepare("INSERT INTO users (email, password, role, first_name, last_name, is_active) 
                           VALUES (?, ?, 'super_admin', 'System', 'Administrator', 1)");
    $stmt->execute([$email, $hashed_password]);
    echo "<h2 style='color:green'>✓ Super Admin created successfully!</h2>";
    echo "<p>Email: admin@university.edu</p>";
    echo "<p>Password: Admin@123</p>";
    echo "<p><a href='index.php'>Click here to login</a></p>";
} else {
    echo "<h2 style='color:orange'>⚠ Admin already exists!</h2>";
    echo "<p><a href='index.php'>Go to login</a></p>";
}
?>
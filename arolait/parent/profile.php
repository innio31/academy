<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole(['parent']);

$message = '';
$error = '';

// Get parent information
$stmt = $pdo->prepare("
    SELECT p.*, u.email, u.first_name, u.last_name, u.phone,
           s.reg_number, CONCAT(su.first_name, ' ', su.last_name) as student_name
    FROM parents p
    JOIN users u ON p.user_id = u.id
    JOIN students s ON p.student_id = s.id
    JOIN users su ON s.user_id = su.id
    WHERE p.id = ?
");
$stmt->execute([$_SESSION['parent_id']]);
$parent = $stmt->fetch();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $phone = trim($_POST['phone']);
    
    $stmt = $pdo->prepare("UPDATE users SET phone = ? WHERE id = ?");
    $stmt->execute([$phone, $parent['user_id']]);
    
    $message = "Profile updated successfully!";
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Verify current password
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$parent['user_id']]);
    $user = $stmt->fetch();
    
    if (!password_verify($current_password, $user['password'])) {
        $error = "Current password is incorrect";
    } elseif (strlen($new_password) < 6) {
        $error = "New password must be at least 6 characters";
    } elseif ($new_password !== $confirm_password) {
        $error = "New passwords do not match";
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashed_password, $parent['user_id']]);
        $message = "Password changed successfully!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>My Profile - Parent Portal</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fb;
            padding: 16px;
        }
        .container { max-width: 800px; margin: 0 auto; }
        .header {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .header h1 { font-size: 24px; color: #2d3748; margin-bottom: 8px; }
        
        .card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 20px;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            font-weight: 600;
        }
        .card-body { padding: 20px; }
        
        .info-row {
            display: flex;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        .info-label { width: 140px; font-weight: 600; color: #4a5568; }
        .info-value { flex: 1; color: #2d3748; }
        
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 14px; }
        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-warning { background: #ed8936; color: white; }
        
        .message {
            background: #c6f6d5;
            color: #22543d;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .error {
            background: #fed7d7;
            color: #c53030;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 640px) {
            .info-row { flex-direction: column; }
            .info-label { width: 100%; margin-bottom: 5px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                <div>
                    <h1>👤 My Profile</h1>
                    <p>View and update your profile information</p>
                </div>
                <a href="index.php" style="color: #667eea; text-decoration: none;">← Back to Dashboard</a>
            </div>
        </div>
        
        <?php if($message): ?>
            <div class="message">✓ <?php echo $message; ?></div>
        <?php endif; ?>
        <?php if($error): ?>
            <div class="error">✗ <?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Personal Information -->
        <div class="card">
            <div class="card-header">📋 Personal Information</div>
            <div class="card-body">
                <div class="info-row">
                    <div class="info-label">Full Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($parent['first_name'] . ' ' . $parent['last_name']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Email Address</div>
                    <div class="info-value"><?php echo htmlspecialchars($parent['email']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Phone Number</div>
                    <div class="info-value"><?php echo $parent['phone'] ?: 'Not provided'; ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Relationship to Student</div>
                    <div class="info-value"><?php echo $parent['relationship'] ?: 'Parent/Guardian'; ?></div>
                </div>
            </div>
        </div>
        
        <!-- Ward Information -->
        <div class="card">
            <div class="card-header">👨‍🎓 Ward Information</div>
            <div class="card-body">
                <div class="info-row">
                    <div class="info-label">Student Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($parent['student_name']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Registration Number</div>
                    <div class="info-value"><?php echo $parent['reg_number']; ?></div>
                </div>
            </div>
        </div>
        
        <!-- Update Profile -->
        <div class="card">
            <div class="card-header">✏️ Update Profile</div>
            <div class="card-body">
                <form method="POST">
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" name="phone" value="<?php echo htmlspecialchars($parent['phone']); ?>">
                    </div>
                    <button type="submit" name="update_profile" class="btn btn-primary">💾 Update Profile</button>
                </form>
            </div>
        </div>
        
        <!-- Change Password -->
        <div class="card">
            <div class="card-header">🔒 Change Password</div>
            <div class="card-body">
                <form method="POST">
                    <div class="form-group">
                        <label>Current Password</label>
                        <input type="password" name="current_password" required>
                    </div>
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" required>
                        <small style="color: #718096;">Minimum 6 characters</small>
                    </div>
                    <div class="form-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" required>
                    </div>
                    <button type="submit" name="change_password" class="btn btn-warning">🔑 Change Password</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
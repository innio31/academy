<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

// Get departments for dropdown
$departments = $pdo->query("SELECT id, name, code FROM departments ORDER BY name")->fetchAll();

// Get academic sessions
$sessions = $pdo->query("SELECT id, name, is_current FROM academic_sessions ORDER BY start_date DESC")->fetchAll();

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $department_id = $_POST['department_id'];
    $level = $_POST['level'];
    $guardian_name = trim($_POST['guardian_name']);
    $guardian_phone = trim($_POST['guardian_phone']);
    $session_id = $_POST['session_id'] ?? null;
    
    // Validation
    if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
        $error = "Please fill all required fields";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters";
    } else {
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // Check if email exists
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $check->execute([$email]);
            if ($check->rowCount() > 0) {
                throw new Exception("Email already exists");
            }
            
            // Get department code for reg number
            $dept = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
            $dept->execute([$department_id]);
            $dept_code = $dept->fetch()['code'];
            $year = date('Y');
            
            // Generate registration number
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM students WHERE reg_number LIKE ?");
            $pattern = $dept_code . '/' . $year . '/%';
            $stmt->execute([$pattern]);
            $count = $stmt->fetch()['count'] + 1;
            $reg_number = $dept_code . '/' . $year . '/' . str_pad($count, 4, '0', STR_PAD_LEFT);
            
            // Create user account
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("
                INSERT INTO users (email, password, role, first_name, last_name, phone, is_active) 
                VALUES (?, ?, 'student', ?, ?, ?, 1)
            ");
            $stmt->execute([$email, $hashed_password, $first_name, $last_name, $phone]);
            $user_id = $pdo->lastInsertId();
            
            // Create student record
            $stmt = $pdo->prepare("
                INSERT INTO students (user_id, reg_number, department_id, current_level, current_session_id, guardian_name, guardian_phone) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$user_id, $reg_number, $department_id, $level, $session_id, $guardian_name, $guardian_phone]);
            $student_id = $pdo->lastInsertId();
            
            $pdo->commit();
            
            // Try to generate QR code if directory exists
            $qr_dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/qrcodes/';
            if (file_exists($qr_dir) && is_writable($qr_dir)) {
                // Try to include phpqrcode
                if (file_exists('../includes/phpqrcode/qrlib.php')) {
                    require_once '../includes/phpqrcode/qrlib.php';
                    $qr_data = json_encode([
                        'student_id' => $student_id,
                        'reg_number' => $reg_number,
                        'name' => $first_name . ' ' . $last_name
                    ]);
                    $qr_file = $qr_dir . $reg_number . '.png';
                    QRcode::png($qr_data, $qr_file, QR_ECLEVEL_L, 10);
                    
                    if (file_exists($qr_file)) {
                        $update = $pdo->prepare("UPDATE students SET qr_code = ? WHERE id = ?");
                        $update->execute(['/assets/qrcodes/' . $reg_number . '.png', $student_id]);
                    }
                }
            }
            
            header("Location: students.php?message=Student added successfully! Registration Number: " . urlencode($reg_number));
            exit();
            
        } catch(Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Add Student - University Portal</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f5f7fb;
            padding: 16px;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .form-header {
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .form-header h1 {
            font-size: 24px;
            color: #2d3748;
            margin-bottom: 8px;
        }
        
        .form-header p {
            color: #718096;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2d3748;
            font-size: 14px;
        }
        
        .required:after {
            content: " *";
            color: #f56565;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        input:focus, select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
            width: 100%;
        }
        
        .btn-primary:hover {
            background: #5a67d8;
        }
        
        .btn-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }
        
        .error {
            background: #fed7d7;
            color: #c53030;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .success {
            background: #c6f6d5;
            color: #22543d;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .info-text {
            font-size: 12px;
            color: #718096;
            margin-top: 4px;
        }
        
        .warning-box {
            background: #feebc8;
            border-left: 4px solid #ed8936;
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        
        .warning-box p {
            color: #7c2d12;
            font-size: 14px;
        }
        
        @media (max-width: 640px) {
            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
            
            .form-card {
                padding: 16px;
            }
        }
        
        .password-strength {
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }
        
        .strength-bar {
            height: 100%;
            width: 0;
            transition: width 0.3s;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="form-card">
            <div class="form-header">
                <h1>➕ Add New Student</h1>
                <p>Create student account and generate registration number automatically</p>
                <div style="margin-top: 10px;">
                    <a href="students.php" style="color: #667eea; text-decoration: none;">← Back to Students List</a>
                </div>
            </div>
            
            <?php if($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if(empty($departments)): ?>
                <div class="warning-box">
                    <p>⚠️ <strong>No departments found!</strong> Please create a department first before adding students.</p>
                    <a href="departments.php" style="color: #667eea;">Go to Department Management →</a>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="studentForm">
                <div class="form-row">
                    <div class="form-group">
                        <label class="required">First Name</label>
                        <input type="text" name="first_name" required>
                    </div>
                    <div class="form-group">
                        <label class="required">Last Name</label>
                        <input type="text" name="last_name" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="required">Email Address</label>
                        <input type="email" name="email" required>
                        <div class="info-text">This will be used for login</div>
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" name="phone">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="required">Password</label>
                        <input type="password" name="password" id="password" required>
                        <div class="password-strength">
                            <div class="strength-bar" id="strengthBar"></div>
                        </div>
                        <div class="info-text">Minimum 6 characters</div>
                    </div>
                    <div class="form-group">
                        <label class="required">Confirm Password</label>
                        <input type="password" name="confirm_password" id="confirmPassword" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="required">Department</label>
                        <select name="department_id" required>
                            <option value="">Select Department</option>
                            <?php foreach($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>">
                                    <?php echo htmlspecialchars($dept['name']); ?> (<?php echo $dept['code']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="required">Current Level</label>
                        <select name="level" required>
                            <option value="100">100 Level</option>
                            <option value="200">200 Level</option>
                            <option value="300">300 Level</option>
                            <option value="400">400 Level</option>
                            <option value="500">500 Level</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Academic Session</label>
                    <select name="session_id">
                        <option value="">Select Session</option>
                        <?php foreach($sessions as $session): ?>
                            <option value="<?php echo $session['id']; ?>" <?php echo $session['is_current'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($session['name']); ?>
                                <?php echo $session['is_current'] ? '(Current)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Guardian/Parent Name</label>
                        <input type="text" name="guardian_name" placeholder="Optional">
                    </div>
                    <div class="form-group">
                        <label>Guardian Phone</label>
                        <input type="tel" name="guardian_phone" placeholder="Optional">
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary" <?php echo empty($departments) ? 'disabled' : ''; ?>>Create Student Account →</button>
            </form>
        </div>
    </div>
    
    <script>
        // Password strength checker
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirmPassword');
        const strengthBar = document.getElementById('strengthBar');
        
        if(password) {
            password.addEventListener('input', function() {
                const val = this.value;
                let strength = 0;
                
                if(val.length >= 6) strength = 25;
                if(val.length >= 8) strength = 50;
                if(/[A-Z]/.test(val)) strength += 25;
                if(/[0-9]/.test(val)) strength += 25;
                
                strengthBar.style.width = strength + '%';
                if(strength < 50) strengthBar.style.background = '#f56565';
                else if(strength < 75) strengthBar.style.background = '#ed8936';
                else strengthBar.style.background = '#48bb78';
            });
        }
        
        // Password confirmation
        const form = document.getElementById('studentForm');
        if(form) {
            form.addEventListener('submit', function(e) {
                if(password.value !== confirmPassword.value) {
                    e.preventDefault();
                    alert('Passwords do not match!');
                }
            });
        }
    </script>
</body>
</html>
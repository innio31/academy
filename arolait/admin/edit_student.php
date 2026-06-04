<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole(['super_admin', 'admin']);

$student_id = $_GET['id'] ?? 0;

if (!$student_id) {
    header("Location: students.php?error=Invalid student ID");
    exit();
}

// Get student details
$student = getStudentDetails($student_id, $pdo);

if (!$student) {
    header("Location: students.php?error=Student not found");
    exit();
}

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
    $department_id = $_POST['department_id'];
    $level = $_POST['level'];
    $guardian_name = trim($_POST['guardian_name']);
    $guardian_phone = trim($_POST['guardian_phone']);
    $session_id = $_POST['session_id'];
    $id_card_issued = isset($_POST['id_card_issued']) ? 1 : 0;
    $new_password = $_POST['new_password'] ?? '';
    
    // Validation
    if (empty($first_name) || empty($last_name) || empty($email)) {
        $error = "Please fill all required fields";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Update user account
            $sql = "UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE id = ?";
            $params = [$first_name, $last_name, $email, $phone, $student['user_id']];
            
            // Update password if provided
            if (!empty($new_password)) {
                $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                $sql = "UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?, password = ? WHERE id = ?";
                $params = [$first_name, $last_name, $email, $phone, $hashed_password, $student['user_id']];
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            // Update student record
            $stmt = $pdo->prepare("
                UPDATE students 
                SET department_id = ?, current_level = ?, current_session_id = ?, 
                    guardian_name = ?, guardian_phone = ?, id_card_issued = ?
                WHERE id = ?
            ");
            $stmt->execute([$department_id, $level, $session_id, $guardian_name, $guardian_phone, $id_card_issued, $student_id]);
            
            $pdo->commit();
            
            header("Location: view_student.php?id=" . $student_id . "&message=Student updated successfully");
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
    <title>Edit Student - <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></title>
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
        
        input:focus, select:focus, textarea:focus {
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
        
        .info-text {
            font-size: 12px;
            color: #718096;
            margin-top: 4px;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .checkbox-group input {
            width: auto;
            width: 20px;
            height: 20px;
        }
        
        .checkbox-group label {
            margin: 0;
            cursor: pointer;
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
    </style>
</head>
<body>
    <div class="container">
        <div class="form-card">
            <div class="form-header">
                <h1>✏️ Edit Student</h1>
                <p>Update student information and academic details</p>
                <div style="margin-top: 10px;">
                    <a href="view_student.php?id=<?php echo $student_id; ?>" style="color: #667eea; text-decoration: none;">← Back to Student Profile</a>
                    <span style="margin: 0 10px">|</span>
                    <a href="students.php" style="color: #667eea; text-decoration: none;">← Back to Students List</a>
                </div>
            </div>
            
            <?php if($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <div class="warning-box">
                <p>⚠️ <strong>Note:</strong> Registration number cannot be changed. To regenerate QR code, go to the student's profile page.</p>
            </div>
            
            <form method="POST" action="" id="studentForm">
                <div class="form-row">
                    <div class="form-group">
                        <label class="required">First Name</label>
                        <input type="text" name="first_name" required value="<?php echo htmlspecialchars($student['first_name']); ?>">
                    </div>
                    <div class="form-group">
                        <label class="required">Last Name</label>
                        <input type="text" name="last_name" required value="<?php echo htmlspecialchars($student['last_name']); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="required">Email Address</label>
                        <input type="email" name="email" required value="<?php echo htmlspecialchars($student['email']); ?>">
                        <div class="info-text">Used for login</div>
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" name="phone" value="<?php echo htmlspecialchars($student['phone']); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Registration Number (Read Only)</label>
                    <input type="text" value="<?php echo $student['reg_number']; ?>" disabled style="background: #f7fafc;">
                </div>
                
                <div class="form-group">
                    <label>Change Password (Optional)</label>
                    <input type="password" name="new_password" id="password" placeholder="Leave blank to keep current password">
                    <div class="password-strength">
                        <div class="strength-bar" id="strengthBar"></div>
                    </div>
                    <div class="info-text">Minimum 6 characters. Only fill if you want to change password.</div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="required">Department</label>
                        <select name="department_id" required>
                            <option value="">Select Department</option>
                            <?php foreach($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>" 
                                    <?php echo $student['department_id'] == $dept['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['name']); ?> (<?php echo $dept['code']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="required">Current Level</label>
                        <select name="level" required>
                            <option value="100" <?php echo $student['current_level'] == 100 ? 'selected' : ''; ?>>100 Level</option>
                            <option value="200" <?php echo $student['current_level'] == 200 ? 'selected' : ''; ?>>200 Level</option>
                            <option value="300" <?php echo $student['current_level'] == 300 ? 'selected' : ''; ?>>300 Level</option>
                            <option value="400" <?php echo $student['current_level'] == 400 ? 'selected' : ''; ?>>400 Level</option>
                            <option value="500" <?php echo $student['current_level'] == 500 ? 'selected' : ''; ?>>500 Level</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Academic Session</label>
                    <select name="session_id">
                        <option value="">Select Session</option>
                        <?php foreach($sessions as $session): ?>
                            <option value="<?php echo $session['id']; ?>" 
                                <?php echo $student['current_session_id'] == $session['id'] ? 'selected' : ''; ?>
                                <?php echo $session['is_current'] ? 'style="font-weight: bold;"' : ''; ?>>
                                <?php echo htmlspecialchars($session['name']); ?>
                                <?php echo $session['is_current'] ? '(Current)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Guardian/Parent Name</label>
                        <input type="text" name="guardian_name" value="<?php echo htmlspecialchars($student['guardian_name']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Guardian Phone</label>
                        <input type="tel" name="guardian_phone" value="<?php echo htmlspecialchars($student['guardian_phone']); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" name="id_card_issued" id="id_card_issued" value="1" 
                            <?php echo $student['id_card_issued'] ? 'checked' : ''; ?>>
                        <label for="id_card_issued">ID Card has been issued</label>
                    </div>
                    <div class="info-text">Check this box when ID card is printed and given to student</div>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn btn-primary">💾 Save Changes</button>
                    <a href="view_student.php?id=<?php echo $student_id; ?>" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Password strength checker
        const password = document.getElementById('password');
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
    </script>
</body>
</html>
<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole(['student']);

$message = '';
$error = '';

// Get student information
$stmt = $pdo->prepare("
    SELECT s.*, u.email, u.first_name, u.last_name, u.phone, u.profile_pic,
           d.name as department_name, d.code as department_code,
           f.name as faculty_name,
           a.name as current_session_name
    FROM students s
    JOIN users u ON s.user_id = u.id
    JOIN departments d ON s.department_id = d.id
    JOIN faculties f ON d.faculty_id = f.id
    LEFT JOIN academic_sessions a ON s.current_session_id = a.id
    WHERE s.id = ?
");
$stmt->execute([$_SESSION['student_id']]);
$student = $stmt->fetch();

// Handle profile picture upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_picture'])) {
    $file = $_FILES['profile_picture'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        if (!in_array($file['type'], $allowed_types)) {
            $error = "Only JPG, PNG, and WEBP images are allowed.";
        } else {
            // Create directory if not exists
            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/uploads/students/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'student_' . $_SESSION['student_id'] . '_' . time() . '.' . $extension;
            $filepath = $upload_dir . $filename;
            
            // Compress and resize image to 100KB max
            $compressed = compressImage($file['tmp_name'], $filepath, 100, 500); // 100KB max, 500px width
            
            if ($compressed) {
                // Delete old profile picture if exists
                if (!empty($student['profile_pic']) && file_exists($_SERVER['DOCUMENT_ROOT'] . $student['profile_pic'])) {
                    unlink($_SERVER['DOCUMENT_ROOT'] . $student['profile_pic']);
                }
                
                // Update database
                $web_path = '/assets/uploads/students/' . $filename;
                $stmt = $pdo->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
                $stmt->execute([$web_path, $student['user_id']]);
                
                $message = "Profile picture updated successfully!";
                
                // Refresh student data
                $stmt->execute([$_SESSION['student_id']]);
                $student = $stmt->fetch();
            } else {
                $error = "Failed to process image. Please try again.";
            }
        }
    } else {
        $error = "Please select a file to upload.";
    }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $phone = trim($_POST['phone']);
    $guardian_name = trim($_POST['guardian_name']);
    $guardian_phone = trim($_POST['guardian_phone']);
    $address = trim($_POST['address']);
    $blood_group = trim($_POST['blood_group']);
    $dob = trim($_POST['dob']);
    
    $stmt = $pdo->prepare("
        UPDATE students 
        SET guardian_name = ?, guardian_phone = ?, address = ?, blood_group = ?, date_of_birth = ?
        WHERE id = ?
    ");
    $stmt->execute([$guardian_name, $guardian_phone, $address, $blood_group, $dob, $_SESSION['student_id']]);
    
    $stmt = $pdo->prepare("UPDATE users SET phone = ? WHERE id = ?");
    $stmt->execute([$phone, $student['user_id']]);
    
    $message = "Profile updated successfully!";
    
    // Refresh student data
    $stmt = $pdo->prepare("
        SELECT s.*, u.email, u.first_name, u.last_name, u.phone, u.profile_pic,
               d.name as department_name, d.code as department_code,
               f.name as faculty_name,
               a.name as current_session_name
        FROM students s
        JOIN users u ON s.user_id = u.id
        JOIN departments d ON s.department_id = d.id
        JOIN faculties f ON d.faculty_id = f.id
        LEFT JOIN academic_sessions a ON s.current_session_id = a.id
        WHERE s.id = ?
    ");
    $stmt->execute([$_SESSION['student_id']]);
    $student = $stmt->fetch();
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Verify current password
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$student['user_id']]);
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
        $stmt->execute([$hashed_password, $student['user_id']]);
        $message = "Password changed successfully!";
    }
}

// Handle picture removal
if (isset($_GET['remove_pic'])) {
    if (!empty($student['profile_pic']) && file_exists($_SERVER['DOCUMENT_ROOT'] . $student['profile_pic'])) {
        unlink($_SERVER['DOCUMENT_ROOT'] . $student['profile_pic']);
    }
    $stmt = $pdo->prepare("UPDATE users SET profile_pic = NULL WHERE id = ?");
    $stmt->execute([$student['user_id']]);
    header("Location: profile.php?message=Profile picture removed");
    exit();
}

// Function to compress image
function compressImage($source, $destination, $maxSizeKB = 100, $maxWidth = 500) {
    // Get image info
    list($width, $height, $type) = getimagesize($source);
    
    // Calculate new dimensions
    $ratio = $width / $height;
    if ($width > $maxWidth) {
        $newWidth = $maxWidth;
        $newHeight = $maxWidth / $ratio;
    } else {
        $newWidth = $width;
        $newHeight = $height;
    }
    
    // Create image resource based on type
    switch ($type) {
        case IMAGETYPE_JPEG:
            $src = imagecreatefromjpeg($source);
            break;
        case IMAGETYPE_PNG:
            $src = imagecreatefrompng($source);
            break;
        case IMAGETYPE_WEBP:
            $src = imagecreatefromwebp($source);
            break;
        default:
            return false;
    }
    
    // Create new blank image
    $dst = imagecreatetruecolor($newWidth, $newHeight);
    
    // Preserve transparency for PNG
    if ($type == IMAGETYPE_PNG) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
        imagefilledrectangle($dst, 0, 0, $newWidth, $newHeight, $transparent);
    }
    
    // Resize image
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    // Compress to target size
    $quality = 85;
    $tempFile = $destination . '.tmp';
    
    do {
        // Save with current quality
        switch ($type) {
            case IMAGETYPE_JPEG:
                imagejpeg($dst, $tempFile, $quality);
                break;
            case IMAGETYPE_PNG:
                $pngQuality = round(9 - ($quality / 100) * 9);
                imagepng($dst, $tempFile, $pngQuality);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($dst, $tempFile, $quality);
                break;
        }
        
        $fileSize = filesize($tempFile) / 1024; // Size in KB
        $quality -= 10;
        
    } while ($fileSize > $maxSizeKB && $quality > 10);
    
    // Rename temp file to destination
    rename($tempFile, $destination);
    
    // Clean up
    imagedestroy($src);
    imagedestroy($dst);
    
    return true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>My Profile - Student Portal</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fb;
            padding: 16px;
        }
        .container { max-width: 1000px; margin: 0 auto; }
        .header {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .header h1 { font-size: 24px; color: #2d3748; margin-bottom: 8px; }
        
        .profile-grid {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 20px;
        }
        
        @media (max-width: 768px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }
        }
        
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
        .card-header.green { background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); }
        .card-header.orange { background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%); }
        .card-body { padding: 20px; }
        
        .profile-picture {
            text-align: center;
            padding: 20px;
        }
        .profile-avatar {
            width: 150px;
            height: 150px;
            margin: 0 auto 15px;
            border-radius: 50%;
            overflow: hidden;
            background: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 3px solid #667eea;
        }
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .profile-avatar .no-photo {
            font-size: 64px;
            opacity: 0.5;
        }
        
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
        input, select, textarea {
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
            transition: all 0.3s;
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5a67d8; }
        .btn-warning { background: #ed8936; color: white; }
        .btn-danger { background: #f56565; color: white; }
        .btn-outline {
            background: transparent;
            border: 1px solid #667eea;
            color: #667eea;
        }
        
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
        
        .file-input-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
        }
        .file-input-wrapper input {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            cursor: pointer;
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
                    <p>View and update your personal information</p>
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
        
        <div class="profile-grid">
            <!-- Left Column - Profile Picture -->
            <div>
                <div class="card">
                    <div class="card-header">📸 Profile Picture</div>
                    <div class="card-body">
                        <div class="profile-picture">
                            <div class="profile-avatar">
                                <?php if($student['profile_pic'] && file_exists($_SERVER['DOCUMENT_ROOT'] . $student['profile_pic'])): ?>
                                    <img src="<?php echo $student['profile_pic']; ?>" alt="Profile Picture">
                                <?php else: ?>
                                    <div class="no-photo">👤</div>
                                <?php endif; ?>
                            </div>
                            
                            <form method="POST" enctype="multipart/form-data" style="margin-top: 15px;">
                                <div class="file-input-wrapper">
                                    <button type="button" class="btn btn-primary" onclick="document.getElementById('profileInput').click()">📷 Upload Picture</button>
                                    <input type="file" id="profileInput" name="profile_picture" accept="image/jpeg,image/png,image/webp" style="display: none;" onchange="this.form.submit()">
                                </div>
                                <small style="display: block; margin-top: 8px; color: #718096;">Max size: 100KB | Format: JPG, PNG, WEBP</small>
                            </form>
                            
                            <?php if($student['profile_pic']): ?>
                                <a href="?remove_pic=1" class="btn btn-outline" style="margin-top: 10px; display: inline-block;" onclick="return confirm('Remove profile picture?')">🗑️ Remove Picture</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Personal Information Card -->
                <div class="card">
                    <div class="card-header green">📋 Personal Information</div>
                    <div class="card-body">
                        <div class="info-row">
                            <div class="info-label">Full Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Registration Number</div>
                            <div class="info-value"><?php echo $student['reg_number']; ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Email Address</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['email']); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Phone Number</div>
                            <div class="info-value"><?php echo $student['phone'] ?: 'Not provided'; ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Date Enrolled</div>
                            <div class="info-value"><?php echo date('F j, Y', strtotime($student['enrollment_date'] ?? $student['created_at'])); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right Column - Forms -->
            <div>
                <!-- Academic Information -->
                <div class="card">
                    <div class="card-header orange">🎓 Academic Information</div>
                    <div class="card-body">
                        <div class="info-row">
                            <div class="info-label">Faculty</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['faculty_name']); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Department</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['department_name']); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Current Level</div>
                            <div class="info-value"><?php echo $student['current_level']; ?> Level</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Current Session</div>
                            <div class="info-value"><?php echo $student['current_session_name'] ?: 'Not set'; ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">ID Card Status</div>
                            <div class="info-value">
                                <?php if($student['id_card_issued']): ?>
                                    <span style="color: #48bb78;">✓ Issued</span>
                                <?php else: ?>
                                    <span style="color: #ed8936;">⚠ Pending</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Update Profile Form -->
                <div class="card">
                    <div class="card-header">✏️ Update Profile</div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="form-group">
                                <label>Phone Number</label>
                                <input type="tel" name="phone" value="<?php echo htmlspecialchars($student['phone']); ?>">
                            </div>
                            <div class="form-group">
                                <label>Guardian/Parent Name</label>
                                <input type="text" name="guardian_name" value="<?php echo htmlspecialchars($student['guardian_name']); ?>">
                            </div>
                            <div class="form-group">
                                <label>Guardian Phone</label>
                                <input type="tel" name="guardian_phone" value="<?php echo htmlspecialchars($student['guardian_phone']); ?>">
                            </div>
                            <div class="form-group">
                                <label>Address</label>
                                <textarea name="address" rows="2"><?php echo htmlspecialchars($student['address'] ?? ''); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label>Blood Group</label>
                                <select name="blood_group">
                                    <option value="">Select Blood Group</option>
                                    <option value="A+" <?php echo ($student['blood_group'] ?? '') == 'A+' ? 'selected' : ''; ?>>A+</option>
                                    <option value="A-" <?php echo ($student['blood_group'] ?? '') == 'A-' ? 'selected' : ''; ?>>A-</option>
                                    <option value="B+" <?php echo ($student['blood_group'] ?? '') == 'B+' ? 'selected' : ''; ?>>B+</option>
                                    <option value="B-" <?php echo ($student['blood_group'] ?? '') == 'B-' ? 'selected' : ''; ?>>B-</option>
                                    <option value="O+" <?php echo ($student['blood_group'] ?? '') == 'O+' ? 'selected' : ''; ?>>O+</option>
                                    <option value="O-" <?php echo ($student['blood_group'] ?? '') == 'O-' ? 'selected' : ''; ?>>O-</option>
                                    <option value="AB+" <?php echo ($student['blood_group'] ?? '') == 'AB+' ? 'selected' : ''; ?>>AB+</option>
                                    <option value="AB-" <?php echo ($student['blood_group'] ?? '') == 'AB-' ? 'selected' : ''; ?>>AB-</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Date of Birth</label>
                                <input type="date" name="dob" value="<?php echo $student['date_of_birth'] ?? ''; ?>">
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
        </div>
    </div>
    
    <script>
        // Preview image before upload
        document.getElementById('profileInput')?.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    const img = document.querySelector('.profile-avatar img');
                    if (img) {
                        img.src = event.target.result;
                    } else {
                        const avatar = document.querySelector('.profile-avatar');
                        avatar.innerHTML = `<img src="${event.target.result}" alt="Profile Picture">`;
                    }
                };
                reader.readAsDataURL(file);
            }
        });
    </script>
</body>
</html>
<?php
// gos/student/profile.php - Student Profile with Profile Picture
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: /gos/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'] ?? 'Student';

// Create uploads directory if not exists
$upload_dir = dirname(__DIR__, 2) . '/uploads/profiles/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Create assets directory if not exists for default avatar
$assets_dir = dirname(__DIR__, 2) . '/assets/images/';
if (!file_exists($assets_dir)) {
    mkdir($assets_dir, 0777, true);
}

// Get student details
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND school_id = ?");
$stmt->execute([$student_id, $school_id]);
$student = $stmt->fetch();

if (!$student) {
    header("Location: /gos/login.php");
    exit();
}

// Update profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $parent_phone = trim($_POST['parent_phone'] ?? '');
    $parent_email = trim($_POST['parent_email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $guardian_name = trim($_POST['guardian_name'] ?? '');
    $guardian_phone = trim($_POST['guardian_phone'] ?? '');

    $stmt = $pdo->prepare("UPDATE students SET parent_phone = ?, parent_email = ?, address = ?, guardian_name = ?, guardian_phone = ? WHERE id = ? AND school_id = ?");
    $stmt->execute([$parent_phone, $parent_email, $address, $guardian_name, $guardian_phone, $student_id, $school_id]);

    $message = "Profile updated successfully!";

    // Refresh student data
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND school_id = ?");
    $stmt->execute([$student_id, $school_id]);
    $student = $stmt->fetch();
}

// Upload profile picture
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_picture'])) {
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['profile_picture'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp'];
        $max_size = 2 * 1024 * 1024; // 2MB

        if (in_array($file['type'], $allowed_types)) {
            if ($file['size'] <= $max_size) {
                $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $file_name = 'student_' . $student_id . '_' . time() . '.' . $file_ext;
                $target_file = $upload_dir . $file_name;
                $relative_path = 'uploads/profiles/' . $file_name;

                if (move_uploaded_file($file['tmp_name'], $target_file)) {
                    // Delete old profile picture if exists
                    if (!empty($student['profile_picture']) && file_exists(dirname(__DIR__, 2) . '/' . $student['profile_picture'])) {
                        @unlink(dirname(__DIR__, 2) . '/' . $student['profile_picture']);
                    }

                    $stmt = $pdo->prepare("UPDATE students SET profile_picture = ? WHERE id = ?");
                    $stmt->execute([$relative_path, $student_id]);

                    $message = "Profile picture updated successfully!";

                    // Refresh student data
                    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND school_id = ?");
                    $stmt->execute([$student_id, $school_id]);
                    $student = $stmt->fetch();
                } else {
                    $error = "Failed to upload image. Please try again.";
                }
            } else {
                $error = "Image size should be less than 2MB.";
            }
        } else {
            $error = "Only JPG, PNG, GIF, and WebP images are allowed.";
        }
    } else {
        $error = "Please select an image to upload.";
    }
}

// Remove profile picture
if (isset($_GET['remove_picture'])) {
    if (!empty($student['profile_picture']) && file_exists(dirname(__DIR__, 2) . '/' . $student['profile_picture'])) {
        @unlink(dirname(__DIR__, 2) . '/' . $student['profile_picture']);
    }
    $stmt = $pdo->prepare("UPDATE students SET profile_picture = NULL WHERE id = ?");
    $stmt->execute([$student_id]);

    $message = "Profile picture removed successfully!";

    // Refresh student data
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND school_id = ?");
    $stmt->execute([$student_id, $school_id]);
    $student = $stmt->fetch();
}

// Change password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (password_verify($current_password, $student['password'])) {
        if ($new_password === $confirm_password && strlen($new_password) >= 6) {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE students SET password = ? WHERE id = ?");
            $stmt->execute([$hashed, $student_id]);
            $message = "Password changed successfully!";
        } else {
            $error = "Passwords do not match or are too short (min 6 characters)";
        }
    } else {
        $error = "Current password is incorrect";
    }
}

// Get QR code
$qr_url = null;
if (!empty($student['qr_code'])) {
    $qr_url = $student['qr_code'];
} else {
    // Generate QR code if not exists
    $qr_data = json_encode([
        'id' => $student['id'],
        'admission' => $student['admission_number'],
        'name' => $student['full_name'],
        'class' => $student['class'],
        'type' => 'student'
    ]);
    $qr_url = "https://quickchart.io/qr?text=" . urlencode($qr_data) . "&size=250";
}

// Get profile picture URL - use a reliable default
$profile_picture_url = null;
if (!empty($student['profile_picture']) && file_exists(dirname(__DIR__, 2) . '/' . $student['profile_picture'])) {
    $profile_picture_url = '/gos/' . $student['profile_picture'];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - My Profile</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --sidebar-width: 260px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-color), #1a2a3a);
            color: white;
            padding: 20px 0;
            z-index: 100;
            transform: translateX(-100%);
            overflow-y: auto;
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 20px;
            margin-bottom: 15px;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: #d4af7a;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .student-info {
            text-align: center;
            padding: 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            margin: 0 15px 20px;
        }

        .nav-links {
            list-style: none;
            padding: 0 15px;
        }

        .nav-links li {
            margin-bottom: 5px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            border-radius: 8px;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255, 255, 255, 0.2);
        }

        .main-content {
            margin-left: 0;
            padding: 20px;
            min-height: 100vh;
        }

        .mobile-menu-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 101;
            background: var(--primary-color);
            color: white;
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 10px;
            font-size: 20px;
            cursor: pointer;
        }

        .top-header {
            background: white;
            padding: 15px 25px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.6rem;
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .card-header {
            border-bottom: 2px solid #ecf0f1;
            padding-bottom: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .profile-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 20px;
        }

        .profile-avatar {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            color: white;
            font-size: 4rem;
            overflow: hidden;
            position: relative;
            cursor: pointer;
            border: 3px solid var(--primary-color);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-avatar i {
            font-size: 4rem;
        }

        .avatar-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            border-radius: 50%;
        }

        .profile-avatar:hover .avatar-overlay {
            opacity: 1;
        }

        .avatar-overlay i {
            font-size: 2rem;
            color: white;
        }

        .profile-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 15px;
        }

        .info-row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }

        .info-label {
            width: 150px;
            font-weight: 500;
            color: #555;
        }

        .info-value {
            flex: 1;
            color: #333;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #555;
            font-size: 0.85rem;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-family: inherit;
            transition: border-color 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-danger {
            background: #e74c3c;
            color: white;
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.75rem;
        }

        .alert-success {
            background: #d5f4e6;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            color: #155724;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-error {
            background: #f8d7da;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            color: #721c24;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .qr-code {
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .qr-code img {
            width: 180px;
            height: 180px;
            margin-bottom: 10px;
        }

        /* Hidden file input */
        #profile_picture_input {
            display: none;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
        }

        @media (min-width: 769px) {
            .sidebar {
                transform: translateX(0);
            }

            .main-content {
                margin-left: var(--sidebar-width);
            }

            .mobile-menu-btn {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .top-header {
                flex-direction: column;
                text-align: center;
            }

            .info-row {
                flex-direction: column;
            }

            .info-label {
                width: 100%;
                margin-bottom: 5px;
            }

            .profile-avatar {
                width: 120px;
                height: 120px;
            }

            .profile-avatar i {
                font-size: 3rem;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>

    <div class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-user-graduate"></i></div>
            <div class="logo-text">
                <h3><?php echo htmlspecialchars($school_name); ?></h3>
                <p>Student Portal</p>
            </div>
        </div>
        <div class="student-info">
            <h4><?php echo htmlspecialchars($student_name); ?></h4>
            <p><?php echo htmlspecialchars($student['admission_number']); ?></p>
            <p><?php echo htmlspecialchars($student['class']); ?></p>
        </div>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="take-exam.php"><i class="fas fa-file-alt"></i> Take Exam</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> My Results</a></li>
            <li><a href="assignments.php"><i class="fas fa-tasks"></i> Assignments</a></li>
            <li><a href="profile.php" class="active"><i class="fas fa-user-cog"></i> My Profile</a></li>
            <li><a href="/gos/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-user-cog"></i> My Profile</h1>
                <p>Manage your personal information and account settings</p>
            </div>
            <button class="btn" onclick="window.location.href='/gos/logout.php'"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>

        <?php if (isset($message)): ?>
            <div class="alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Profile Picture Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-camera"></i> Profile Picture</h3>
            </div>
            <div class="profile-section">
                <div class="profile-avatar" onclick="document.getElementById('profile_picture_input').click();">
                    <?php if ($profile_picture_url): ?>
                        <img src="<?php echo $profile_picture_url; ?>" alt="Profile Picture">
                    <?php else: ?>
                        <i class="fas fa-user-graduate"></i>
                    <?php endif; ?>
                    <div class="avatar-overlay">
                        <i class="fas fa-camera"></i>
                    </div>
                </div>
                <div class="profile-actions">
                    <form method="POST" enctype="multipart/form-data" id="profilePictureForm" style="display: inline;">
                        <input type="file" name="profile_picture" id="profile_picture_input" accept="image/jpeg,image/png,image/jpg,image/gif,image/webp" style="display: none;" onchange="this.form.submit()">
                        <input type="hidden" name="upload_picture" value="1">
                        <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('profile_picture_input').click();">
                            <i class="fas fa-upload"></i> Upload Photo
                        </button>
                    </form>
                    <?php if ($profile_picture_url): ?>
                        <a href="?remove_picture=1" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to remove your profile picture?')">
                            <i class="fas fa-trash"></i> Remove
                        </a>
                    <?php endif; ?>
                </div>
                <p style="font-size: 12px; color: #666; margin-top: 10px;">Click on the avatar to upload. Max size: 2MB (JPG, PNG, GIF, WebP)</p>
            </div>
        </div>

        <!-- Personal Information Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-id-card"></i> Personal Information</h3>
            </div>
            <div class="info-grid">
                <div>
                    <div class="info-row">
                        <div class="info-label">Full Name:</div>
                        <div class="info-value"><?php echo htmlspecialchars($student['full_name']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Admission Number:</div>
                        <div class="info-value"><?php echo htmlspecialchars($student['admission_number']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Class:</div>
                        <div class="info-value"><?php echo htmlspecialchars($student['class']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Date of Birth:</div>
                        <div class="info-value"><?php echo $student['dob'] ? date('F j, Y', strtotime($student['dob'])) : 'Not set'; ?></div>
                    </div>
                </div>
                <div>
                    <div class="info-row">
                        <div class="info-label">Gender:</div>
                        <div class="info-value"><?php echo htmlspecialchars($student['gender'] ?? 'Not set'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Date of Admission:</div>
                        <div class="info-value"><?php echo $student['date_of_admission'] ? date('F j, Y', strtotime($student['date_of_admission'])) : 'Not set'; ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Status:</div>
                        <div class="info-value">
                            <span class="status-badge" style="background: <?php echo $student['status'] == 'active' ? '#d5f4e6' : '#f8d7da'; ?>; color: <?php echo $student['status'] == 'active' ? '#27ae60' : '#e74c3c'; ?>;">
                                <?php echo ucfirst($student['status']); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Guardian Information Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-users"></i> Guardian Information</h3>
            </div>
            <form method="POST">
                <div class="info-grid">
                    <div>
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Guardian Name</label>
                            <input type="text" name="guardian_name" class="form-control" value="<?php echo htmlspecialchars($student['guardian_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Guardian Phone</label>
                            <input type="text" name="guardian_phone" class="form-control" value="<?php echo htmlspecialchars($student['guardian_phone'] ?? ''); ?>">
                        </div>
                    </div>
                    <div>
                        <div class="form-group">
                            <label><i class="fas fa-phone-alt"></i> Parent/Guardian Phone (Alternative)</label>
                            <input type="text" name="parent_phone" class="form-control" value="<?php echo htmlspecialchars($student['parent_phone'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Parent/Guardian Email</label>
                            <input type="email" name="parent_email" class="form-control" value="<?php echo htmlspecialchars($student['parent_email'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-map-marker-alt"></i> Address</label>
                    <textarea name="address" class="form-control" rows="3"><?php echo htmlspecialchars($student['address'] ?? ''); ?></textarea>
                </div>
                <button type="submit" name="update_profile" class="btn btn-primary">
                    <i class="fas fa-save"></i> Update Information
                </button>
            </form>
        </div>

        <!-- Change Password Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-key"></i> Change Password</h3>
            </div>
            <form method="POST">
                <div class="form-group">
                    <label>Current Password</label>
                    <input type="password" name="current_password" class="form-control" required>
                </div>
                <div class="info-grid">
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                </div>
                <button type="submit" name="change_password" class="btn btn-primary">
                    <i class="fas fa-key"></i> Change Password
                </button>
            </form>
        </div>

        <!-- QR Code Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-qrcode"></i> My QR Code</h3>
            </div>
            <div class="qr-code">
                <img src="<?php echo $qr_url; ?>" alt="Student QR Code">
                <p><strong>Student ID:</strong> <?php echo htmlspecialchars($student['admission_number']); ?></p>
                <p style="margin-top: 5px; font-size: 12px; color: #666;">Scan this QR code for attendance and identification</p>
                <div style="margin-top: 15px; display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                    <button class="btn btn-primary btn-sm" onclick="window.print()">
                        <i class="fas fa-print"></i> Print QR Code
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="downloadQRCode()">
                        <i class="fas fa-download"></i> Download QR Code
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('mobileMenuBtn').onclick = () => document.getElementById('sidebar').classList.toggle('active');

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768) {
                const sidebar = document.getElementById('sidebar');
                const menuBtn = document.getElementById('mobileMenuBtn');
                if (!sidebar.contains(e.target) && !menuBtn.contains(e.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // Auto-submit profile picture form when file is selected
        document.getElementById('profile_picture_input')?.addEventListener('change', function() {
            if (this.files.length > 0) {
                const file = this.files[0];
                // Validate file size (2MB)
                if (file.size > 2 * 1024 * 1024) {
                    alert('File size exceeds 2MB limit!');
                    this.value = '';
                    return;
                }
                // Validate file type
                const allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Only JPG, PNG, GIF, and WebP images are allowed!');
                    this.value = '';
                    return;
                }
                this.form.submit();
            }
        });

        // Download QR Code function
        function downloadQRCode() {
            const qrImg = document.querySelector('.qr-code img');
            if (qrImg) {
                const link = document.createElement('a');
                link.download = 'student_qr_<?php echo $student['admission_number']; ?>.png';
                link.href = qrImg.src;
                link.click();
            }
        }
    </script>
</body>

</html>
<?php
// gos/student/profile.php - Student Profile
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

// Get student details
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND school_id = ?");
$stmt->execute([$student_id, $school_id]);
$student = $stmt->fetch();

// Update profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $parent_phone = trim($_POST['parent_phone']);
    $parent_email = trim($_POST['parent_email']);
    $address = trim($_POST['address']);

    $stmt = $pdo->prepare("UPDATE students SET parent_phone = ?, parent_email = ?, address = ? WHERE id = ? AND school_id = ?");
    $stmt->execute([$parent_phone, $parent_email, $address, $student_id, $school_id]);

    $message = "Profile updated successfully!";
}

// Change password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

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
if ($student['qr_code']) {
    $qr_url = $student['qr_code'];
} else {
    // Generate QR code if not exists
    $qr_data = json_encode([
        'id' => $student['id'],
        'admission' => $student['admission_number'],
        'name' => $student['full_name'],
        'type' => 'student'
    ]);
    $qr_url = "https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=" . urlencode($qr_data);
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
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            color: white;
            font-size: 3rem;
        }

        .qr-code {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .qr-code img {
            width: 150px;
            height: 150px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #555;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
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
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .alert-success {
            background: #d5f4e6;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            color: #155724;
        }

        .alert-error {
            background: #f8d7da;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            color: #721c24;
        }

        .info-row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }

        .info-label {
            width: 130px;
            font-weight: 500;
            color: #555;
        }

        .info-value {
            flex: 1;
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
                gap: 15px;
            }

            .info-row {
                flex-direction: column;
            }

            .info-label {
                width: 100%;
                margin-bottom: 5px;
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
            </div>
            <button class="btn" onclick="window.location.href='/gos/logout.php'"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>

        <?php if (isset($message)): ?>
            <div class="alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-id-card"></i> Personal Information</h3>
            </div>
            <div class="profile-avatar"><i class="fas fa-user-graduate"></i></div>
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
            <div class="info-row">
                <div class="info-label">Gender:</div>
                <div class="info-value"><?php echo htmlspecialchars($student['gender'] ?? 'Not set'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Guardian Name:</div>
                <div class="info-value"><?php echo htmlspecialchars($student['guardian_name'] ?? 'Not set'); ?></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-address-card"></i> Contact Information</h3>
            </div>
            <form method="POST">
                <div class="form-group"><label>Parent/Guardian Phone</label><input type="text" name="parent_phone" class="form-control" value="<?php echo htmlspecialchars($student['parent_phone'] ?? ''); ?>"></div>
                <div class="form-group"><label>Parent/Guardian Email</label><input type="email" name="parent_email" class="form-control" value="<?php echo htmlspecialchars($student['parent_email'] ?? ''); ?>"></div>
                <div class="form-group"><label>Address</label><textarea name="address" class="form-control" rows="3"><?php echo htmlspecialchars($student['address'] ?? ''); ?></textarea></div>
                <button type="submit" name="update_profile" class="btn btn-primary"><i class="fas fa-save"></i> Update Profile</button>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-key"></i> Change Password</h3>
            </div>
            <form method="POST">
                <div class="form-group"><label>Current Password</label><input type="password" name="current_password" class="form-control" required></div>
                <div class="form-group"><label>New Password</label><input type="password" name="new_password" class="form-control" required></div>
                <div class="form-group"><label>Confirm New Password</label><input type="password" name="confirm_password" class="form-control" required></div>
                <button type="submit" name="change_password" class="btn btn-primary"><i class="fas fa-key"></i> Change Password</button>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-qrcode"></i> My QR Code</h3>
            </div>
            <div class="qr-code">
                <img src="<?php echo $qr_url; ?>" alt="Student QR Code">
                <p style="margin-top: 10px;">Scan this QR code for attendance and identification</p>
                <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print QR Code</button>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('mobileMenuBtn').onclick = () => document.getElementById('sidebar').classList.toggle('active');
    </script>
</body>

</html>
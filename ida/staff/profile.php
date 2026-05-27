<?php
// ida/staff/profile.php - Staff Profile
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: /ida/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$staff_id = $_SESSION['user_id'];
$staff_name = $_SESSION['user_name'] ?? 'Staff Member';
$staff_role = $_SESSION['staff_role'] ?? 'staff';
$staff_id_string = $_SESSION['staff_id'] ?? $staff_id;

// Get staff details
$stmt = $pdo->prepare("SELECT * FROM staff WHERE id = ? AND school_id = ?");
$stmt->execute([$staff_id, $school_id]);
$staff = $stmt->fetch();

// Get staff_id string if not already set
if ($staff && !$staff_id_string) {
    $staff_id_string = $staff['staff_id'];
}

// Update profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);

    $stmt = $pdo->prepare("UPDATE staff SET full_name = ?, email = ? WHERE id = ? AND school_id = ?");
    $stmt->execute([$full_name, $email, $staff_id, $school_id]);

    $_SESSION['user_name'] = $full_name;
    $message = "Profile updated successfully!";
    $message_type = "success";

    // Refresh staff data
    $stmt = $pdo->prepare("SELECT * FROM staff WHERE id = ? AND school_id = ?");
    $stmt->execute([$staff_id, $school_id]);
    $staff = $stmt->fetch();
}

// Change password - LOGOUT AFTER SUCCESSFUL CHANGE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (password_verify($current_password, $staff['password'])) {
        if ($new_password === $confirm_password && strlen($new_password) >= 6) {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE staff SET password = ? WHERE id = ?");
            $stmt->execute([$hashed, $staff_id]);

            // Store a message to show on login page
            $_SESSION['password_changed_message'] = "Your password has been changed successfully. Please login again.";

            // Destroy session and redirect to login
            session_destroy();
            header("Location: /ida/login.php?message=password_changed");
            exit();
        } else {
            $error = "Passwords do not match or are too short (min 6 characters)";
            $message_type = "error";
        }
    } else {
        $error = "Current password is incorrect";
        $message_type = "error";
    }
}

// Get assigned subjects count
$assigned_subjects_count = 0;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM staff_subjects WHERE staff_id = ? AND school_id = ?");
$stmt->execute([$staff_id_string, $school_id]);
$assigned_subjects_count = $stmt->fetchColumn();

// Get assigned classes count
$assigned_classes_count = 0;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM staff_classes WHERE staff_id = ? AND school_id = ?");
$stmt->execute([$staff_id_string, $school_id]);
$assigned_classes_count = $stmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - My Profile</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --primary-dark: #1a5a8a;
            --secondary-color: #d4af7a;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #3498db;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --gray-50: #f9fafb;
            --gray-100: #f0f2f5;
            --gray-200: #e4e7eb;
            --gray-400: #9ca3af;
            --gray-600: #6b7280;
            --gray-800: #1f2937;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.08);
            --sidebar-width: 280px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--gray-100);
            color: var(--gray-800);
            min-height: 100vh;
        }

        /* Main Content */
        .main-content {
            margin-left: 0;
            padding: 20px;
            min-height: 100vh;
            transition: margin-left 0.28s ease;
        }

        /* Top Header */
        .top-header {
            background: white;
            padding: 20px 25px;
            border-radius: var(--radius-lg);
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: var(--shadow-sm);
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.6rem;
            margin-bottom: 5px;
            font-weight: 700;
        }

        .header-title h1 i {
            margin-right: 10px;
        }

        .header-title p {
            color: var(--gray-600);
            font-size: 0.85rem;
        }

        .header-title p i {
            color: var(--primary-color);
            font-size: 0.7rem;
            margin: 0 4px;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--gray-200);
        }

        .card-header h3 {
            color: var(--gray-800);
            font-size: 1.1rem;
            font-weight: 600;
        }

        .card-header h3 i {
            color: var(--primary-color);
            margin-right: 8px;
        }

        /* Profile Overview */
        .profile-overview {
            display: flex;
            align-items: center;
            gap: 25px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: 700;
            color: white;
        }

        .profile-info {
            flex: 1;
        }

        .profile-info h2 {
            font-size: 1.4rem;
            margin-bottom: 5px;
        }

        .profile-info p {
            color: var(--gray-600);
            margin-bottom: 10px;
        }

        .profile-badges {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .badge-primary {
            background: var(--gray-100);
            color: var(--primary-color);
        }

        .badge-success {
            background: #d5f4e6;
            color: var(--success-color);
        }

        .badge-info {
            background: #eaf6ff;
            color: var(--info-color);
        }

        /* Forms */
        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-600);
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-size: 0.9rem;
            font-family: inherit;
            transition: all 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .form-control:disabled {
            background: var(--gray-50);
            cursor: not-allowed;
        }

        /* Buttons */
        .btn {
            padding: 10px 24px;
            border-radius: var(--radius-md);
            border: none;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8rem;
            transition: all 0.2s;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background: #c0392b;
            transform: translateY(-2px);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--gray-200);
            color: var(--gray-800);
        }

        .btn-outline:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        /* Alerts */
        .alert {
            padding: 15px 20px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d5f4e6;
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }

        .alert-error {
            background: #f8d7da;
            color: var(--danger-color);
            border-left: 4px solid var(--danger-color);
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid var(--warning-color);
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 10px;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--gray-200);
        }

        .info-label {
            font-weight: 600;
            color: var(--gray-600);
            font-size: 0.8rem;
        }

        .info-value {
            font-weight: 500;
            color: var(--gray-800);
            font-size: 0.85rem;
        }

        /* Info Item for top bar */
        .info-item-badge {
            background: var(--gray-100);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            color: var(--gray-800);
            font-weight: 500;
        }

        .info-item-badge i {
            margin-right: 6px;
            color: var(--primary-color);
        }

        /* Password Change Notice */
        .password-notice {
            background: #fff3cd;
            padding: 12px 16px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            font-size: 0.8rem;
            color: #856404;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .password-notice i {
            font-size: 1rem;
        }

        /* Responsive */
        @media (min-width: 768px) {
            .main-content {
                margin-left: var(--sidebar-width);
            }
        }

        @media (max-width: 767px) {
            .main-content {
                padding-top: 70px;
            }

            .top-header {
                flex-direction: column;
                text-align: center;
            }

            .profile-overview {
                flex-direction: column;
                text-align: center;
            }

            .card-header {
                flex-direction: column;
                gap: 10px;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Include Staff Sidebar -->
    <?php include_once 'includes/staff_sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-user-cog"></i> My Profile</h1>
                <p><i class="fas fa-chevron-right"></i> Manage your personal information and account settings</p>
            </div>
            <div>
                <span class="info-item-badge"><i class="fas fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></span>
            </div>
        </div>

        <?php if (isset($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Password Change Notice -->
        <div class="password-notice">
            <i class="fas fa-info-circle"></i>
            <strong>Note:</strong> After changing your password, you will be logged out and required to login again with your new password.
        </div>

        <!-- Profile Overview Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-id-card"></i> Profile Overview</h3>
            </div>
            <div class="profile-overview">
                <div class="profile-avatar">
                    <?php echo strtoupper(substr($staff['full_name'], 0, 1)); ?>
                </div>
                <div class="profile-info">
                    <h2><?php echo htmlspecialchars($staff['full_name']); ?></h2>
                    <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($staff['email'] ?? 'No email set'); ?></p>
                    <div class="profile-badges">
                        <span class="badge badge-primary"><i class="fas fa-id-badge"></i> ID: <?php echo htmlspecialchars($staff['staff_id']); ?></span>
                        <span class="badge badge-success"><i class="fas fa-user-tag"></i> <?php echo ucfirst($staff['role']); ?></span>
                        <span class="badge badge-info"><i class="fas fa-book"></i> <?php echo $assigned_subjects_count; ?> Subjects</span>
                        <span class="badge badge-info"><i class="fas fa-users"></i> <?php echo $assigned_classes_count; ?> Classes</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Profile Information -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-edit"></i> Profile Information</h3>
                <span class="badge badge-primary"><i class="fas fa-info-circle"></i> Edit your details</span>
            </div>
            <form method="POST">
                <div class="info-grid">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Full Name</label>
                        <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($staff['full_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Email Address</label>
                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($staff['email'] ?? ''); ?>" placeholder="your@email.com">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-id-card"></i> Staff ID</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($staff['staff_id']); ?>" disabled>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-user-tag"></i> Role</label>
                        <input type="text" class="form-control" value="<?php echo ucfirst($staff['role']); ?>" disabled>
                    </div>
                </div>
                <div style="margin-top: 10px;">
                    <button type="submit" name="update_profile" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Profile
                    </button>
                </div>
            </form>
        </div>

        <!-- Change Password -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-key"></i> Change Password</h3>
                <span class="badge badge-primary"><i class="fas fa-shield-alt"></i> Keep your account secure</span>
            </div>
            <form method="POST" onsubmit="return confirmPasswordChange()">
                <div class="info-grid">
                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> Current Password</label>
                        <div style="position: relative;">
                            <input type="password" name="current_password" id="current_password" class="form-control" required>
                            <span class="password-toggle" onclick="togglePassword('current_password')" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--gray-600);">
                                <i class="far fa-eye" id="current_password_icon"></i>
                            </span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-key"></i> New Password</label>
                        <div style="position: relative;">
                            <input type="password" name="new_password" id="new_password" class="form-control" required>
                            <span class="password-toggle" onclick="togglePassword('new_password')" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--gray-600);">
                                <i class="far fa-eye" id="new_password_icon"></i>
                            </span>
                        </div>
                        <small style="color: var(--gray-600); font-size: 0.7rem;">Minimum 6 characters</small>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-check-circle"></i> Confirm New Password</label>
                        <div style="position: relative;">
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                            <span class="password-toggle" onclick="togglePassword('confirm_password')" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--gray-600);">
                                <i class="far fa-eye" id="confirm_password_icon"></i>
                            </span>
                        </div>
                        <small id="password_match_error" style="color: var(--danger-color); font-size: 0.7rem; display: none;">
                            <i class="fas fa-exclamation-circle"></i> Passwords do not match
                        </small>
                    </div>
                </div>
                <div style="margin-top: 10px;">
                    <button type="submit" name="change_password" class="btn btn-danger">
                        <i class="fas fa-key"></i> Change Password
                    </button>
                </div>
            </form>
        </div>

        <!-- Account Information -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-info-circle"></i> Account Information</h3>
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Account Created:</span>
                    <span class="info-value"><?php echo date('F j, Y', strtotime($staff['created_at'])); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Last Login:</span>
                    <span class="info-value"><?php echo isset($_SESSION['last_login']) ? date('F j, Y g:i A', strtotime($_SESSION['last_login'])) : 'Not recorded'; ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Account Status:</span>
                    <span class="info-value">
                        <span class="badge" style="background: #d5f4e6; color: var(--success-color);">
                            <i class="fas fa-circle" style="font-size: 0.5rem;"></i> Active
                        </span>
                    </span>
                </div>
                <div class="info-item">
                    <span class="info-label">School:</span>
                    <span class="info-value"><?php echo htmlspecialchars($school_name); ?></span>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu toggle - handled in staff_sidebar.php
        const mobileBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('staffSidebar');
        const overlay = document.getElementById('sidebarOverlay');

        if (mobileBtn) {
            mobileBtn.onclick = () => {
                sidebar.classList.toggle('active');
                if (overlay) overlay.classList.toggle('active');
            };
        }

        if (overlay) {
            overlay.onclick = () => {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            };
        }

        // Toggle password visibility
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = document.getElementById(fieldId + '_icon');
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Real-time password match validation
        const newPassword = document.getElementById('new_password');
        const confirmPassword = document.getElementById('confirm_password');
        const matchError = document.getElementById('password_match_error');

        if (newPassword && confirmPassword) {
            function checkPasswordMatch() {
                if (newPassword.value !== confirmPassword.value && confirmPassword.value !== '') {
                    matchError.style.display = 'block';
                } else {
                    matchError.style.display = 'none';
                }
            }
            newPassword.addEventListener('keyup', checkPasswordMatch);
            confirmPassword.addEventListener('keyup', checkPasswordMatch);
        }

        // Confirm password change with warning about logout
        function confirmPasswordChange() {
            const newPwd = document.getElementById('new_password').value;
            const confirmPwd = document.getElementById('confirm_password').value;

            if (newPwd !== confirmPwd) {
                alert('Passwords do not match!');
                return false;
            }

            if (newPwd.length < 6) {
                alert('Password must be at least 6 characters long!');
                return false;
            }

            return confirm('WARNING: After changing your password, you will be logged out and will need to login again with your new password. Continue?');
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768 && sidebar && sidebar.classList.contains('active')) {
                if (!sidebar.contains(e.target) && !mobileBtn.contains(e.target)) {
                    sidebar.classList.remove('active');
                    if (overlay) overlay.classList.remove('active');
                }
            }
        });
    </script>
</body>

</html>
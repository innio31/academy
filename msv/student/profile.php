<?php
// msv/student/profile.php - Student Profile (View-Only except Password)
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: /msv/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'] ?? 'Student';

// Get student details including class
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND school_id = ?");
$stmt->execute([$student_id, $school_id]);
$student = $stmt->fetch();

if (!$student) {
    header("Location: /msv/login.php");
    exit();
}

$student_class = $student['class'];
$admission_number = $student['admission_number'];

// Get profile picture path
$profile_picture = !empty($student['profile_picture']) ? $student['profile_picture'] : '/assets/uploads/default-avatar.png';
if (!empty($student['profile_picture']) && strpos($student['profile_picture'], '/') !== 0) {
    $profile_picture = '/uploads/' . $student['profile_picture'];
}

// Change password only - this is the only editable feature
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($current_password)) {
        $error = "Current password is required";
    } elseif (empty($new_password)) {
        $error = "New password is required";
    } elseif ($new_password !== $confirm_password) {
        $error = "New password and confirm password do not match";
    } elseif (strlen($new_password) < 6) {
        $error = "New password must be at least 6 characters long";
    } elseif (password_verify($current_password, $student['password'])) {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE students SET password = ? WHERE id = ?");
        $stmt->execute([$hashed, $student_id]);
        $message = "Password changed successfully!";
    } else {
        $error = "Current password is incorrect";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($school_name); ?> - My Profile</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: <?php echo $secondary_color; ?>;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --light: #ecf0f1;
            --dark: #2c3e50;
            --sidebar-width: 280px;
            --shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            --radius: 12px;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Mobile Menu Button */
        .mobile-menu-btn {
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            width: 44px;
            height: 44px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 20px;
            cursor: pointer;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .sidebar-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        /* Main Content */
        .main-content {
            min-height: 100vh;
            padding: 20px;
            transition: var(--transition);
        }

        /* Top Header */
        .top-header {
            background: white;
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 25px;
            background: linear-gradient(135deg, var(--primary-color), var(--dark));
            color: white;
        }

        .welcome-banner {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .welcome-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #d4af7a;
            background: #f0f0f0;
        }

        .welcome-text h1 {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .welcome-text p {
            opacity: 0.9;
            font-size: 0.85rem;
        }

        /* Cards */
        .content-card {
            background: white;
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 25px;
            box-shadow: var(--shadow);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--light);
        }

        .card-header h3 {
            color: var(--primary-color);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-header i {
            font-size: 1.2rem;
        }

        /* Profile Section */
        .profile-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 10px;
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
            overflow: hidden;
            border: 3px solid var(--primary-color);
            box-shadow: var(--shadow);
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .info-card {
            background: #f8f9fa;
            border-radius: var(--radius);
            padding: 20px;
        }

        .info-card-title {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 8px;
        }

        .info-row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            width: 130px;
            font-weight: 500;
            color: #666;
            font-size: 0.8rem;
        }

        .info-value {
            flex: 1;
            color: #333;
            font-size: 0.85rem;
            font-weight: 500;
        }

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Form Styles for Password Change */
        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #555;
            font-size: 0.8rem;
        }

        .form-group label i {
            margin-right: 6px;
            color: var(--primary-color);
        }

        .form-control {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid #e0e0e0;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .btn-block {
            width: 100%;
        }

        /* Alerts */
        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid var(--success);
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid var(--danger);
        }

        .alert-info {
            background: #eef2ff;
            color: #1e3a8a;
            border-left: 4px solid var(--info);
        }

        /* Footer */
        .footer {
            text-align: center;
            padding: 20px;
            color: #666;
            font-size: 0.8rem;
            border-top: 1px solid var(--light);
            margin-top: 20px;
        }

        /* Desktop */
        @media (min-width: 769px) {

            .mobile-menu-btn,
            .sidebar-overlay {
                display: none;
            }

            .main-content {
                margin-left: var(--sidebar-width);
            }
        }

        /* Mobile */
        @media (max-width: 768px) {
            .main-content {
                padding: 70px 15px 20px;
            }

            .welcome-banner {
                flex-direction: column;
                text-align: center;
            }

            .info-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .info-row {
                flex-direction: column;
                gap: 5px;
            }

            .info-label {
                width: 100%;
            }
        }
    </style>
</head>

<body>

    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Include Student Sidebar -->
    <?php require_once 'includes/student_sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">

        <div class="top-header">
            <div class="welcome-banner">
                <img src="<?php echo htmlspecialchars($profile_picture); ?>"
                    alt="Profile Picture"
                    class="welcome-avatar"
                    onerror="this.src='/assets/uploads/default-avatar.png'">
                <div class="welcome-text">
                    <h1><i class="fas fa-user-circle"></i> My Profile</h1>
                    <p><i class="fas fa-graduation-cap"></i> Class: <?php echo htmlspecialchars($student_class); ?> | <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($admission_number); ?></p>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Profile Picture Card (View Only) -->
        <div class="content-card">
            <div class="card-header">
                <i class="fas fa-camera" style="color: var(--primary-color);"></i>
                <h3>Profile Picture</h3>
            </div>
            <div class="profile-section">
                <div class="profile-avatar">
                    <?php if (!empty($student['profile_picture']) && file_exists(dirname(__DIR__, 2) . '/' . $student['profile_picture'])): ?>
                        <img src="/msv/<?php echo $student['profile_picture']; ?>" alt="Profile Picture">
                    <?php else: ?>
                        <i class="fas fa-user-graduate"></i>
                    <?php endif; ?>
                </div>
                <p style="font-size: 0.75rem; color: #666; text-align: center;">
                    <i class="fas fa-info-circle"></i> Profile picture can only be updated by school administration
                </p>
            </div>
        </div>

        <!-- Personal Information (View Only) -->
        <div class="content-card">
            <div class="card-header">
                <i class="fas fa-id-card" style="color: var(--primary-color);"></i>
                <h3>Personal Information</h3>
            </div>
            <div class="info-grid">
                <div class="info-card">
                    <div class="info-card-title">
                        <i class="fas fa-user"></i> Basic Details
                    </div>
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
                <div class="info-card">
                    <div class="info-card-title">
                        <i class="fas fa-flag-checkered"></i> Academic Details
                    </div>
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
                            <span class="status-badge <?php echo $student['status'] == 'active' ? 'status-active' : 'status-inactive'; ?>">
                                <?php echo ucfirst($student['status']); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Guardian Information (View Only) -->
        <div class="content-card">
            <div class="card-header">
                <i class="fas fa-users" style="color: var(--primary-color);"></i>
                <h3>Guardian Information</h3>
            </div>
            <div class="info-grid">
                <div class="info-card">
                    <div class="info-card-title">
                        <i class="fas fa-user-tie"></i> Primary Guardian
                    </div>
                    <div class="info-row">
                        <div class="info-label">Guardian Name:</div>
                        <div class="info-value"><?php echo htmlspecialchars($student['guardian_name'] ?? 'Not set'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Guardian Phone:</div>
                        <div class="info-value"><?php echo htmlspecialchars($student['guardian_phone'] ?? 'Not set'); ?></div>
                    </div>
                </div>
                <div class="info-card">
                    <div class="info-card-title">
                        <i class="fas fa-phone-alt"></i> Contact Details
                    </div>
                    <div class="info-row">
                        <div class="info-label">Parent/Guardian Phone:</div>
                        <div class="info-value"><?php echo htmlspecialchars($student['parent_phone'] ?? 'Not set'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Parent/Guardian Email:</div>
                        <div class="info-value"><?php echo htmlspecialchars($student['parent_email'] ?? 'Not set'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Address:</div>
                        <div class="info-value"><?php echo htmlspecialchars($student['address'] ?? 'Not set'); ?></div>
                    </div>
                </div>
            </div>
            <div class="alert alert-info" style="margin-top: 15px;">
                <i class="fas fa-info-circle"></i>
                <span>To update guardian information, please contact the school administration.</span>
            </div>
        </div>

        <!-- Change Password (Only Editable Section) -->
        <div class="content-card">
            <div class="card-header">
                <i class="fas fa-key" style="color: var(--primary-color);"></i>
                <h3>Change Password</h3>
            </div>
            <form method="POST" id="passwordForm">
                <div class="form-group">
                    <label><i class="fas fa-lock"></i> Current Password</label>
                    <input type="password" name="current_password" id="current_password" class="form-control" required>
                </div>
                <div class="info-grid" style="margin-bottom: 0;">
                    <div class="form-group">
                        <label><i class="fas fa-key"></i> New Password</label>
                        <input type="password" name="new_password" id="new_password" class="form-control" required minlength="6">
                        <small style="font-size: 0.7rem; color: #666;">Minimum 6 characters</small>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-check-circle"></i> Confirm New Password</label>
                        <input type="password" name="confirm_password" id="confirm_password" class="form-control" required minlength="6">
                    </div>
                </div>
                <button type="submit" name="change_password" class="btn btn-primary btn-block" id="submitBtn">
                    <i class="fas fa-save"></i> Update Password
                </button>
            </form>
        </div>

        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> - Student Portal</p>
        </div>
    </div>

    <script>
        // Handle window resize - auto close sidebar on desktop
        let resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                if (window.innerWidth >= 768 && sidebar) {
                    sidebar.classList.remove('active');
                    if (overlay) overlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            }, 250);
        });

        // Password form validation
        const passwordForm = document.getElementById('passwordForm');
        const submitBtn = document.getElementById('submitBtn');

        if (passwordForm) {
            passwordForm.addEventListener('submit', function(e) {
                const newPassword = document.getElementById('new_password').value;
                const confirmPassword = document.getElementById('confirm_password').value;
                const currentPassword = document.getElementById('current_password').value;

                if (!currentPassword.trim()) {
                    e.preventDefault();
                    alert('Please enter your current password');
                    document.getElementById('current_password').focus();
                    return false;
                }

                if (newPassword.length < 6) {
                    e.preventDefault();
                    alert('New password must be at least 6 characters long');
                    document.getElementById('new_password').focus();
                    return false;
                }

                if (newPassword !== confirmPassword) {
                    e.preventDefault();
                    alert('New password and confirm password do not match');
                    document.getElementById('confirm_password').focus();
                    return false;
                }

                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
                }
            });
        }
    </script>
</body>

</html>
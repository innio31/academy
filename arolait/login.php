<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/config.php';
require_once 'includes/auth.php';

// ============================================
// CHECK FOR PASSWORD CHANGED MESSAGE
// ============================================
if (isset($_GET['message']) && $_GET['message'] === 'password_changed') {
    $_SESSION['login_message'] = "Your password has been changed successfully. Please login with your new password.";
    $_SESSION['login_message_type'] = "success";
}

// Check for message in session
$login_message = isset($_SESSION['login_message']) ? $_SESSION['login_message'] : '';
$login_message_type = isset($_SESSION['login_message_type']) ? $_SESSION['login_message_type'] : '';
unset($_SESSION['login_message']);
unset($_SESSION['login_message_type']);

// Check if user is already logged in
if (isLoggedIn()) {
    $dashboardUrl = getDashboardUrl();
    $currentFile = basename($_SERVER['PHP_SELF']);
    if ($currentFile != 'dashboard.php' && $currentFile != 'index.php') {
        $fullPath = __DIR__ . '/' . $dashboardUrl;
        if (file_exists($fullPath)) {
            header("Location: " . $dashboardUrl);
            exit();
        } else {
            session_destroy();
            session_start();
        }
    }
}

$error = '';
$success = '';
$reset_whatsapp_url = null;
$reset_username = null;
$reset_user_type = null;

// Get school info from database
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;
$logo_path = SCHOOL_LOGO;

// Ensure logo path is correct
if (!empty($logo_path) && $logo_path[0] !== '/') {
    $logo_path = '/' . $logo_path;
}

// Handle forgot password request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forgot_password'])) {
    $identifier = trim($_POST['identifier'] ?? '');
    $school_id = getCurrentSchoolId();

    if (empty($identifier)) {
        $error = "Please enter your Email, Staff ID, or Registration Number";
    } else {
        $user_data = null;
        $user_name = '';
        $user_role = '';
        $school_whatsapp = '';

        // Get school WhatsApp number from settings
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE school_id = ? AND setting_key = 'school_whatsapp' LIMIT 1");
            $stmt->execute([$school_id]);
            $whatsapp_result = $stmt->fetch();
            $school_whatsapp = $whatsapp_result ? $whatsapp_result['setting_value'] : '2349035535827';
        } catch (Exception $e) {
            $school_whatsapp = '2349035535827';
        }

        // Find user by email, reg_number, or staff_number
        $stmt = $pdo->prepare("
            SELECT u.first_name, u.last_name, u.email, u.role,
                   s.reg_number, st.staff_number
            FROM users u
            LEFT JOIN students s ON u.id = s.user_id AND u.school_id = s.school_id
            LEFT JOIN staff st ON u.id = st.user_id AND u.school_id = st.school_id
            WHERE u.school_id = ? 
            AND (u.email = ? OR s.reg_number = ? OR st.staff_number = ?)
            AND u.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$school_id, $identifier, $identifier, $identifier]);
        $user_data = $stmt->fetch();

        if ($user_data) {
            $user_name = ($user_data['first_name'] ?? '') . ' ' . ($user_data['last_name'] ?? '');
            $user_role = $user_data['role'] ?? 'unknown';
            
            // Prepare WhatsApp message
            $whatsapp_message = "🔐 PASSWORD RESET REQUEST\n\n"
                . "School: " . $school_name . "\n"
                . "User Role: " . ucfirst($user_role) . "\n"
                . "Email: " . ($user_data['email'] ?? 'N/A') . "\n"
                . "Reg Number: " . ($user_data['reg_number'] ?? 'N/A') . "\n"
                . "Staff ID: " . ($user_data['staff_number'] ?? 'N/A') . "\n"
                . "User Name: " . $user_name . "\n\n"
                . "Please help reset the password for this user.";

            $whatsapp_url = "https://wa.me/{$school_whatsapp}?text=" . urlencode($whatsapp_message);

            $success = "Reset request sent! Click the WhatsApp button below to message the school admin.";

            $_SESSION['reset_whatsapp_url'] = $whatsapp_url;
            $_SESSION['reset_identifier'] = $identifier;
        } else {
            $error = "No account found with this Email, Staff ID, or Registration Number. Please check and try again.";
        }
    }
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';
    $school_id = getCurrentSchoolId();

    if (empty($identifier) || empty($password)) {
        $error = "Please enter your login ID/Email and password";
    } else {
        if (loginUser($identifier, $password, $pdo, $school_id)) {
            $dashboardUrl = getDashboardUrl();
            header("Location: " . $dashboardUrl);
            exit();
        } else {
            $error = "Invalid login credentials or account is inactive";
        }
    }
}

// Clear reset session data after displaying
$reset_whatsapp_url = $_SESSION['reset_whatsapp_url'] ?? null;
$reset_identifier = $_SESSION['reset_identifier'] ?? null;
unset($_SESSION['reset_whatsapp_url'], $_SESSION['reset_identifier']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo htmlspecialchars($school_name); ?> - Portal</title>

    <!-- PWA Meta Tags -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="<?php echo $primary_color; ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="<?php echo $logo_path; ?>">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, <?php echo $primary_color; ?> 0%, <?php echo $secondary_color; ?> 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .container {
            max-width: 450px;
            width: 100%;
        }

        .login-card {
            background: white;
            border-radius: 32px;
            padding: 40px 32px;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }

        .school-logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: <?php echo $primary_color; ?>;
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .school-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .school-logo .fa-school {
            font-size: 40px;
            color: white;
        }

        h2 {
            font-size: 1.5rem;
            color: #1a1a2e;
            margin-bottom: 8px;
        }

        .subtitle {
            color: #666;
            font-size: 0.85rem;
            margin-bottom: 28px;
        }

        .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px;
            border-radius: 16px;
            margin-bottom: 20px;
            font-size: 0.85rem;
        }

        .success {
            background: #dcfce7;
            color: #16a34a;
            padding: 12px;
            border-radius: 16px;
            margin-bottom: 20px;
            font-size: 0.85rem;
        }

        .input-group {
            position: relative;
            margin-bottom: 16px;
        }

        .input-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e8e8e8;
            border-radius: 16px;
            font-size: 0.95rem;
            font-family: inherit;
            transition: all 0.3s;
        }

        .input-group input:focus {
            outline: none;
            border-color: <?php echo $primary_color; ?>;
        }

        .password-wrapper {
            position: relative;
        }

        .password-wrapper input {
            padding-right: 45px;
        }

        .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #999;
            font-size: 1.1rem;
        }

        .toggle-password:hover {
            color: <?php echo $primary_color; ?>;
        }

        .login-btn {
            width: 100%;
            padding: 14px;
            background: <?php echo $primary_color; ?>;
            color: white;
            border: none;
            border-radius: 16px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            margin-bottom: 16px;
        }

        .login-btn:hover {
            opacity: 0.9;
        }

        .forgot-link {
            text-align: right;
            margin-bottom: 20px;
        }

        .forgot-link a {
            color: <?php echo $primary_color; ?>;
            font-size: 0.8rem;
            text-decoration: none;
        }

        .forgot-link a:hover {
            text-decoration: underline;
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 20px 0;
            color: #ccc;
            font-size: 0.8rem;
        }

        .divider::before,
        .divider::after {
            content: "";
            flex: 1;
            height: 1px;
            background: #e8e8e8;
        }

        .install-btn,
        .whatsapp-btn {
            width: 100%;
            padding: 14px;
            background: transparent;
            border: 2px solid <?php echo $primary_color; ?>;
            color: <?php echo $primary_color; ?>;
            border-radius: 16px;
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 12px;
            text-decoration: none;
        }

        .install-btn:hover,
        .whatsapp-btn:hover {
            background: <?php echo $primary_color; ?>;
            color: white;
        }

        .whatsapp-btn {
            background: #25D366;
            border-color: #25D366;
            color: white;
        }

        .whatsapp-btn:hover {
            background: #128C7E;
            border-color: #128C7E;
            color: white;
        }

        .features {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-top: 24px;
        }

        .feature {
            background: white;
            border-radius: 16px;
            padding: 12px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .feature i {
            font-size: 1.2rem;
            color: <?php echo $primary_color; ?>;
            margin-bottom: 6px;
        }

        .feature span {
            font-size: 0.7rem;
            color: #666;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background: white;
            padding: 32px;
            border-radius: 24px;
            max-width: 400px;
            width: 90%;
            text-align: center;
        }

        .modal-content h3 {
            margin-bottom: 16px;
            color: #1a1a2e;
        }

        .modal-content p {
            margin-bottom: 20px;
            color: #666;
            font-size: 0.9rem;
        }

        .modal-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        .modal-buttons button {
            padding: 10px 20px;
            border-radius: 12px;
            cursor: pointer;
            font-family: inherit;
        }

        .modal-cancel {
            background: #e8e8e8;
            border: none;
        }

        .modal-confirm {
            background: <?php echo $primary_color; ?>;
            color: white;
            border: none;
        }

        @media (max-width: 480px) {
            .login-card {
                padding: 32px 24px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="login-card">
            <div class="school-logo">
                <?php
                $logo_full_path = $_SERVER['DOCUMENT_ROOT'] . $logo_path;
                if (!empty($logo_path) && file_exists($logo_full_path)):
                ?>
                    <img src="<?php echo $logo_path; ?>" alt="<?php echo htmlspecialchars($school_name); ?>">
                <?php else: ?>
                    <i class="fas fa-school"></i>
                <?php endif; ?>
            </div>
            <h2><?php echo htmlspecialchars($school_name); ?></h2>
            <p class="subtitle">Parent, Student & Staff Portal</p>

            <?php if ($login_message): ?>
                <div class="success"><?php echo htmlspecialchars($login_message); ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="error"><?php echo $error; ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success"><?php echo $success; ?></div>
            <?php endif; ?>

            <!-- Login Form -->
            <div id="loginForm">
                <form method="POST">
                    <div class="input-group">
                        <input type="text" name="identifier" id="identifier" placeholder="Email / Staff ID / Registration Number" required>
                    </div>
                    <div class="input-group">
                        <div class="password-wrapper">
                            <input type="password" name="password" id="password" placeholder="Password" required>
                            <i class="fas fa-eye toggle-password" onclick="togglePasswordVisibility()"></i>
                        </div>
                    </div>
                    <div class="forgot-link">
                        <a href="#" onclick="showForgotPasswordModal(event)">Forgot Password?</a>
                    </div>
                    <input type="hidden" name="login" value="1">
                    <button type="submit" class="login-btn">Login</button>
                </form>
            </div>

            <!-- Forgot Password Form (hidden by default) -->
            <div id="forgotForm" style="display: none;">
                <form method="POST">
                    <div class="input-group">
                        <input type="text" name="identifier" id="forgot_identifier" placeholder="Email / Staff ID / Registration Number" required>
                    </div>
                    <input type="hidden" name="forgot_password" value="1">
                    <button type="submit" class="login-btn" style="background: #25D366; margin-bottom: 12px;">
                        <i class="fab fa-whatsapp"></i> Request Reset via WhatsApp
                    </button>
                    <button type="button" class="install-btn" onclick="showLoginForm()" style="margin-bottom: 0;">
                        <i class="fas fa-arrow-left"></i> Back to Login
                    </button>
                </form>
            </div>

            <?php if ($reset_whatsapp_url): ?>
                <div style="margin-top: 16px; margin-bottom: 16px;">
                    <a href="<?php echo $reset_whatsapp_url; ?>" target="_blank" class="whatsapp-btn">
                        <i class="fab fa-whatsapp"></i> Message Admin on WhatsApp
                    </a>
                    <p style="font-size: 0.7rem; color: #666; margin-top: 8px;">
                        <i class="fas fa-info-circle"></i>
                        Click the button above to message the school admin. They will help reset your password.
                    </p>
                </div>
            <?php endif; ?>

            <div class="divider">
                <span>Get the App</span>
            </div>

            <button class="install-btn" id="installBtn" style="display: none;">
                <i class="fas fa-download"></i> Install App
            </button>
        </div>

        <div class="features">
            <div class="feature">
                <i class="fas fa-chart-line"></i>
                <span>Instant Results</span>
            </div>
            <div class="feature">
                <i class="fas fa-tasks"></i>
                <span>Assignments</span>
            </div>
            <div class="feature">
                <i class="fas fa-file-alt"></i>
                <span>CBT Exams</span>
            </div>
            <div class="feature">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmModal" class="modal">
        <div class="modal-content">
            <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #f59e0b; margin-bottom: 16px;"></i>
            <h3>Reset Password</h3>
            <p>You will be redirected to WhatsApp to request a password reset from the school administrator.</p>
            <div class="modal-buttons">
                <button class="modal-cancel" onclick="closeModal()">Cancel</button>
                <button class="modal-confirm" onclick="proceedToForgot()">Continue</button>
            </div>
        </div>
    </div>

    <script>
        let deferredPrompt;

        // Toggle password visibility
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.querySelector('.toggle-password');
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        // Show forgot password modal
        function showForgotPasswordModal(event) {
            event.preventDefault();
            const modal = document.getElementById('confirmModal');
            modal.style.display = 'flex';
        }

        function closeModal() {
            const modal = document.getElementById('confirmModal');
            modal.style.display = 'none';
        }

        function proceedToForgot() {
            closeModal();
            document.getElementById('loginForm').style.display = 'none';
            document.getElementById('forgotForm').style.display = 'block';
        }

        function showLoginForm() {
            document.getElementById('forgotForm').style.display = 'none';
            document.getElementById('loginForm').style.display = 'block';
        }

        // PWA Installation
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            const installBtn = document.getElementById('installBtn');
            if (installBtn) {
                installBtn.style.display = 'flex';
                installBtn.innerHTML = '<i class="fas fa-download"></i> Install App';
            }
        });

        function installPWA() {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then((choiceResult) => {
                    console.log('User accepted the install prompt');
                    deferredPrompt = null;
                });
            }
        }

        document.getElementById('installBtn')?.addEventListener('click', installPWA);

        // Service Worker registration
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js')
                .then(reg => console.log('SW registered'))
                .catch(err => console.log('SW error:', err));
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('confirmModal');
            if (event.target === modal) {
                closeModal();
            }
        }
    </script>
</body>

</html>
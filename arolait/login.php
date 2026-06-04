<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Force destroy any existing session on login page load (optional - for debugging)
if (isset($_GET['force_logout'])) {
    session_destroy();
    session_start();
}

// Redirect if already logged in
if (isLoggedIn()) {
    // Verify user still belongs to school context
    if (isset($_SESSION['user_id']) && isset($_SESSION['school_id'])) {
        if (verifyUserSchool($_SESSION['user_id'], $_SESSION['school_id'])) {
            header("Location: " . getDashboardUrl());
            exit();
        } else {
            // User doesn't belong to this school anymore, logout
            logout();
        }
    } else {
        header("Location: " . getDashboardUrl());
        exit();
    }
}

$error = '';
$success = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($identifier) || empty($password)) {
        $error = 'Please enter both login ID/Email and password';
    } else {
        // Debug: Log what we're trying
        error_log("Attempting login with identifier: " . $identifier);
        
        // Get current school_id (from subdomain or default)
        $school_id = getCurrentSchoolId();
        error_log("Using school_id: " . ($school_id ?? 'default'));
        
        if (loginUser($identifier, $password, $pdo, $school_id)) {
            error_log("Login successful for: " . $identifier);
            header("Location: " . getDashboardUrl());
            exit();
        } else {
            error_log("Login failed for: " . $identifier);
            $error = 'Invalid login credentials or account is inactive';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Login | Arolait Global College of Health Technology</title>
    <!-- Google Fonts & Font Awesome -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #fef9ef 0%, #fff6e8 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        :root {
            --primary: #915F07;
            --primary-dark: #6e4505;
            --secondary: #FFC333;
            --secondary-light: #ffe2a4;
            --shadow-sm: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.02);
            --shadow-md: 0 20px 25px -12px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease;
        }

        .login-container {
            background: white;
            border-radius: 32px;
            box-shadow: var(--shadow-md);
            width: 100%;
            max-width: 460px;
            padding: 44px 40px;
            animation: fadeIn 0.5s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .logo {
            text-align: center;
            margin-bottom: 32px;
        }

        .logo img {
            height: 70px;
            object-fit: contain;
            margin-bottom: 16px;
        }

        .logo h1 {
            color: #1f2937;
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .logo p {
            color: #6b7280;
            font-size: 0.9rem;
        }

        .form-group {
            margin-bottom: 24px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #374151;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            color: #9ca3af;
            font-size: 1rem;
            pointer-events: none;
        }

        input {
            width: 100%;
            padding: 14px 16px 14px 46px;
            border: 1.5px solid #e5e7eb;
            border-radius: 16px;
            font-size: 0.95rem;
            font-family: 'Inter', sans-serif;
            transition: var(--transition);
            background: #f9fafb;
        }

        .password-wrapper input {
            padding-right: 50px;
        }

        input:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
            box-shadow: 0 0 0 3px rgba(145, 95, 7, 0.1);
        }

        .toggle-password {
            position: absolute;
            right: 16px;
            background: transparent;
            border: none;
            cursor: pointer;
            font-size: 1.1rem;
            color: #9ca3af;
            padding: 0;
            width: auto;
            transition: color 0.2s;
        }

        .toggle-password:hover {
            color: var(--primary);
            background: transparent;
        }

        button[type="submit"] {
            width: 100%;
            padding: 14px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 40px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 8px;
        }

        button[type="submit"]:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(145, 95, 7, 0.25);
        }

        .error {
            background: #fef2f2;
            color: #dc2626;
            padding: 14px 18px;
            border-radius: 16px;
            margin-bottom: 24px;
            text-align: center;
            font-size: 0.85rem;
            border-left: 4px solid #dc2626;
        }

        .info {
            text-align: center;
            margin-top: 28px;
            padding-top: 24px;
            border-top: 1px solid #e5e7eb;
            font-size: 0.75rem;
            color: #9ca3af;
        }

        .login-hint {
            background: #fefbf5;
            padding: 18px;
            border-radius: 20px;
            margin-top: 24px;
            border: 1px solid #f0ede8;
        }

        .login-hint h4 {
            color: var(--primary);
            margin-bottom: 12px;
            font-size: 0.85rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .login-hint p {
            margin: 6px 0;
            color: #4b5563;
            font-size: 0.8rem;
        }

        .login-hint .role {
            font-weight: 600;
            color: var(--primary);
        }

        .back-link {
            text-align: center;
            margin-top: 16px;
        }

        .back-link a {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: color 0.2s;
        }

        .back-link a:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 32px 24px;
            }
            .logo img {
                height: 55px;
            }
            .logo h1 {
                font-size: 1.5rem;
            }
            input {
                padding: 12px 14px 12px 42px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <img src="https://arolait.com.ng/storage/images/1731350187.jpg" alt="Arolait Logo" onerror="this.src='https://placehold.co/400x120?text=AROLAIT+COLLEGE'">
            <h1>Welcome Back</h1>
            <p>Login to access your dashboard</p>
        </div>
        
        <?php if ($error): ?>
            <div class="error">
                <i class="fas fa-exclamation-circle" style="margin-right: 8px;"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label><i class="fas fa-envelope" style="margin-right: 6px;"></i> Email / Staff ID / Student Reg Number</label>
                <div class="input-wrapper">
                    <i class="fas fa-user input-icon"></i>
                    <input type="text" name="identifier" required placeholder="Enter your email, staff ID or registration number" value="<?php echo htmlspecialchars($_POST['identifier'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-lock" style="margin-right: 6px;"></i> Password</label>
                <div class="input-wrapper password-wrapper">
                    <i class="fas fa-key input-icon"></i>
                    <input type="password" name="password" id="password" required placeholder="Enter your password">
                    <button type="button" class="toggle-password" onclick="togglePassword()">
                        <i class="far fa-eye" id="toggleIcon"></i>
                    </button>
                </div>
            </div>
            
            <button type="submit">
                Login <i class="fas fa-arrow-right"></i>
            </button>
        </form>
        
        
        
        <div class="back-link">
            <a href="/"><i class="fas fa-home"></i> Back to Homepage</a>
        </div>
        
        <div class="info">
            <i class="far fa-copyright"></i> <span id="currentYear"></span> Arolait Global College of Health Technology. All rights reserved.
        </div>
    </div>

    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.className = 'far fa-eye-slash';
            } else {
                passwordInput.type = 'password';
                toggleIcon.className = 'far fa-eye';
            }
        }
        
        document.getElementById('currentYear').textContent = new Date().getFullYear();
    </script>
</body>
</html>
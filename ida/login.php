<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Include config - this creates $pdo as global
require_once __DIR__ . '/includes/config.php';

// Get $pdo from global
global $pdo;

// Verify $pdo works
try {
    $pdo->query("SELECT 1");
} catch (Exception $e) {
    die("Database not connected: " . $e->getMessage());
}

$error = '';
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;
$logo_path = SCHOOL_LOGO;

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $user_type = $_POST['user_type'] ?? 'student';
    
    if (empty($username) || empty($password)) {
        $error = "Please enter your username and password";
    } else {
        // Student login
        if ($user_type === 'student') {
            $stmt = $pdo->prepare("SELECT * FROM students WHERE admission_number = ? AND school_id = ?");
            $stmt->execute([$username, SCHOOL_ID]);
            $user = $stmt->fetch();
            
            if ($user && (password_verify($password, $user['password']) || $user['password'] === $password)) {
                if ($user['password'] === $password) {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $updateStmt = $pdo->prepare("UPDATE students SET password = ? WHERE id = ?");
                    $updateStmt->execute([$hashed, $user['id']]);
                }
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_type'] = 'student';
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['selected_school_id'] = SCHOOL_ID;
                header("Location: student/index.php");
                exit();
            } else {
                $error = "Invalid admission number or password";
            }
        }
        // Staff login
        elseif ($user_type === 'staff') {
            $stmt = $pdo->prepare("SELECT * FROM staff WHERE staff_id = ? AND school_id = ?");
            $stmt->execute([$username, SCHOOL_ID]);
            $user = $stmt->fetch();
            
            if ($user && (password_verify($password, $user['password']) || $user['password'] === $password)) {
                if ($user['password'] === $password) {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $updateStmt = $pdo->prepare("UPDATE staff SET password = ? WHERE id = ?");
                    $updateStmt->execute([$hashed, $user['id']]);
                }
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_type'] = 'staff';
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['selected_school_id'] = SCHOOL_ID;
                header("Location: staff/index.php");
                exit();
            } else {
                $error = "Invalid staff ID or password";
            }
        }
        // Admin login
        elseif ($user_type === 'admin') {
            $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ? AND school_id = ?");
            $stmt->execute([$username, SCHOOL_ID]);
            $user = $stmt->fetch();
            
            if ($user && (password_verify($password, $user['password']) || $user['password'] === $password)) {
                if ($user['password'] === $password) {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $updateStmt = $pdo->prepare("UPDATE admin_users SET password = ? WHERE id = ?");
                    $updateStmt->execute([$hashed, $user['id']]);
                }
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_type'] = 'admin';
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['selected_school_id'] = SCHOOL_ID;
                header("Location: admin/index.php");
                exit();
            } else {
                $error = "Invalid admin credentials";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo htmlspecialchars($school_name); ?> - Portal</title>
    
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="<?php echo $primary_color; ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="<?php echo $logo_path; ?>">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
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
        }
        .school-logo img {
            width: 50px;
            height: 50px;
            object-fit: contain;
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
        input, select {
            width: 100%;
            padding: 14px 16px;
            margin-bottom: 16px;
            border: 2px solid #e8e8e8;
            border-radius: 16px;
            font-size: 0.95rem;
            font-family: inherit;
            transition: all 0.3s;
        }
        input:focus, select:focus {
            outline: none;
            border-color: <?php echo $primary_color; ?>;
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
            margin-bottom: 20px;
        }
        .login-btn:hover {
            opacity: 0.9;
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
        .install-btn {
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
        }
        .install-btn:hover {
            background: <?php echo $primary_color; ?>;
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
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
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
        @media (max-width: 480px) {
            .login-card { padding: 32px 24px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-card">
            <div class="school-logo">
                <img src="<?php echo $logo_path; ?>" alt="<?php echo htmlspecialchars($school_name); ?>">
            </div>
            <h2><?php echo htmlspecialchars($school_name); ?></h2>
            <p class="subtitle">Parent & Student Portal</p>
            
            <?php if ($error): ?>
                <div class="error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <select name="user_type" required>
                    <option value="student">🎓 Student Login</option>
                    <option value="staff">👨‍🏫 Staff Login</option>
                    <option value="admin">👑 Admin Login</option>
                </select>
                <input type="text" name="username" placeholder="Admission Number / Staff ID / Username" required>
                <input type="password" name="password" placeholder="Password" required>
                <input type="hidden" name="login" value="1">
                <button type="submit" class="login-btn">Login</button>
            </form>
            
            <div class="divider">
                <span>Get the App</span>
            </div>
            
            <button class="install-btn" id="installBtn">
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
    
    <script>
        let deferredPrompt;
        
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            const installBtn = document.getElementById('installBtn');
            installBtn.innerHTML = '<i class="fas fa-download"></i> Install Now';
        });
        
        document.getElementById('installBtn').addEventListener('click', async () => {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                const { outcome } = await deferredPrompt.userChoice;
                console.log(`Install: ${outcome}`);
                deferredPrompt = null;
            } else {
                alert('To install:\n• Chrome: Tap menu (⋮) → Install App\n• Safari: Share → Add to Home Screen');
            }
        });
        
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js')
                .then(reg => console.log('SW registered'))
                .catch(err => console.log('SW error:', err));
        }
    </script>
</body>
</html>
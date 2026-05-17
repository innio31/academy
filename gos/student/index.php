<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/theme.php';

// Check if logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

$student_name = $_SESSION['user_name'];
$school_name = SCHOOL_NAME;
?>
<!DOCTYPE html>
<html>

<head>
    <title><?php echo $school_name; ?> - Student Dashboard</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- PWA Meta Tags -->
    <link rel="manifest" href="/manifest-dynamic.php?v=<?php echo time(); ?>">
    <meta name="theme-color" content="<?php echo SCHOOL_PRIMARY; ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?php echo SCHOOL_NAME; ?>">
    <link rel="apple-touch-icon" href="<?php echo SCHOOL_LOGO; ?>">

    <?php require_once '../includes/theme-css.php'; ?>
</head>

<body>
    <div class="school-header" style="background: var(--primary); color: white; padding: 20px;">
        <h1><?php echo $school_name; ?></h1>
        <p>Welcome, <?php echo htmlspecialchars($student_name); ?>!</p>
    </div>

    <div style="max-width: 800px; margin: 40px auto; padding: 20px;">
        <h2>Student Dashboard</h2>
        <p>This is your dashboard. More features coming soon.</p>

        <div style="margin-top: 30px;">
            <a href="../logout.php" class="btn-primary" style="padding: 10px 20px; text-decoration: none;">Logout</a>
        </div>
    </div>

    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js')
                    .then(reg => console.log('Service Worker registered'))
                    .catch(err => console.log('Service Worker failed:', err));
            });
        }

        let deferredPrompt;
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            showInstallButton();
        });

        function showInstallButton() {
            const btn = document.createElement('button');
            btn.innerHTML = '📱 Install App';
            btn.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--primary);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 30px;
            cursor: pointer;
            z-index: 9999;
        `;
            btn.onclick = () => {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then(() => btn.remove());
            };
            document.body.appendChild(btn);
        }
    </script>
    <script>
        // Force service worker update on school change
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.getRegistrations().then(function(registrations) {
                for (let registration of registrations) {
                    registration.update();
                }
            });
        }
    </script>
</body>

</html>
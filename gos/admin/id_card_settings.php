<?php
// gos/admin/id_card_settings.php - Configure ID Card Design
session_start();

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /gos/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;

// Get current settings
$stmt = $pdo->prepare("SELECT * FROM id_card_settings WHERE school_id = ?");
$stmt->execute([$school_id]);
$settings = $stmt->fetch();

if (!$settings) {
    // Insert default settings
    $stmt = $pdo->prepare("
        INSERT INTO id_card_settings (school_id, card_back_text, card_template, primary_color, secondary_color, show_motto, show_qr) 
        VALUES (?, ?, 'modern', ?, ?, 1, 1)
    ");
    $card_back_text = "This ID Card is the property of " . $school_name . ". If found, please return to the school administration office.";
    $stmt->execute([$school_id, $card_back_text, $primary_color, '#d4af7a']);

    $stmt = $pdo->prepare("SELECT * FROM id_card_settings WHERE school_id = ?");
    $stmt->execute([$school_id]);
    $settings = $stmt->fetch();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $card_back_text = $_POST['card_back_text'] ?? '';
    $card_template = $_POST['card_template'] ?? 'modern';
    $primary_color = $_POST['primary_color'] ?? '#722F37';
    $secondary_color = $_POST['secondary_color'] ?? '#d4af7a';
    $show_motto = isset($_POST['show_motto']) ? 1 : 0;
    $show_qr = isset($_POST['show_qr']) ? 1 : 0;

    $stmt = $pdo->prepare("
        UPDATE id_card_settings SET 
            card_back_text = ?, card_template = ?, primary_color = ?, secondary_color = ?, 
            show_motto = ?, show_qr = ?, updated_at = NOW()
        WHERE school_id = ?
    ");
    $stmt->execute([$card_back_text, $card_template, $primary_color, $secondary_color, $show_motto, $show_qr, $school_id]);

    $message = "Settings saved successfully!";
    $message_type = "success";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - ID Card Settings</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: #d4af7a;
            --success-color: #27ae60;
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
            min-height: 100vh;
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
            overflow-y: auto;
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
            background: var(--secondary-color);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .admin-info {
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
            padding: 20px;
            border-radius: 15px;
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
            margin-bottom: 5px;
        }

        .dashboard-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
        }

        .dashboard-card h2 {
            color: var(--primary-color);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #555;
        }

        .form-control,
        .form-select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
        }

        .color-input {
            width: 60px;
            height: 50px;
            padding: 5px;
            cursor: pointer;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--primary-color);
            color: white;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .preview-card {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
        }

        .card-preview {
            width: 100%;
            max-width: 350px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }

        .card-front,
        .card-back {
            padding: 20px;
            position: relative;
        }

        .card-front {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .card-back {
            background: #f0f0f0;
            color: #333;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d5f4e6;
            color: #155724;
            border-left: 4px solid var(--success-color);
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
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>

    <div class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
            <div class="logo-text">
                <h3><?php echo htmlspecialchars($school_name); ?></h3>
                <p>Admin Panel</p>
            </div>
        </div>
        <div class="admin-info">
            <h4><?php echo $_SESSION['admin_name'] ?? 'Admin'; ?></h4>
        </div>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-students.php"><i class="fas fa-users"></i> Students</a></li>
            <li><a href="id_card_generator.php"><i class="fas fa-id-card"></i> ID Cards</a></li>
            <li><a href="id_card_settings.php" class="active"><i class="fas fa-cog"></i> ID Card Settings</a></li>
            <li><a href="/gos/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-id-card"></i> ID Card Settings</h1>
            </div>
            <button class="logout-btn" onclick="window.location.href='/gos/logout.php'"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>

        <?php if (isset($message)): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
        <?php endif; ?>

        <div class="dashboard-card">
            <h2><i class="fas fa-sliders-h"></i> Card Design Settings</h2>
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Card Template</label>
                        <select name="card_template" class="form-select">
                            <option value="modern" <?php echo ($settings['card_template'] ?? 'modern') == 'modern' ? 'selected' : ''; ?>>Modern</option>
                            <option value="classic" <?php echo ($settings['card_template'] ?? '') == 'classic' ? 'selected' : ''; ?>>Classic</option>
                            <option value="premium" <?php echo ($settings['card_template'] ?? '') == 'premium' ? 'selected' : ''; ?>>Premium</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Primary Color</label>
                        <input type="color" name="primary_color" class="color-input" value="<?php echo $settings['primary_color'] ?? '#722F37'; ?>">
                    </div>
                    <div class="form-group">
                        <label>Secondary Color</label>
                        <input type="color" name="secondary_color" class="color-input" value="<?php echo $settings['secondary_color'] ?? '#d4af7a'; ?>">
                    </div>
                    <div class="form-group">
                        <label><input type="checkbox" name="show_motto" value="1" <?php echo ($settings['show_motto'] ?? 1) ? 'checked' : ''; ?>> Show School Motto</label>
                    </div>
                    <div class="form-group">
                        <label><input type="checkbox" name="show_qr" value="1" <?php echo ($settings['show_qr'] ?? 1) ? 'checked' : ''; ?>> Show QR Code</label>
                    </div>
                </div>
                <div class="form-group">
                    <label>Back of Card Text</label>
                    <textarea name="card_back_text" class="form-control" rows="4"><?php echo htmlspecialchars($settings['card_back_text'] ?? ''); ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Settings</button>
            </form>
        </div>

        <div class="dashboard-card">
            <h2><i class="fas fa-eye"></i> Card Preview</h2>
            <div class="preview-card">
                <div class="card-preview">
                    <div class="card-front" style="background: linear-gradient(135deg, <?php echo $settings['primary_color'] ?? '#722F37'; ?>, <?php echo $settings['secondary_color'] ?? '#d4af7a'; ?>);">
                        <div style="text-align:center;">
                            <div style="font-size:14px; font-weight:bold;"><?php echo htmlspecialchars($school_name); ?></div>
                            <div style="font-size:10px; margin:5px 0;">Student ID Card</div>
                            <div style="width:80px; height:80px; background:white; margin:10px auto; border-radius:10px; display:flex; align-items:center; justify-content:center; color:#999;">Photo</div>
                            <div style="font-weight:bold; font-size:12px;">John Doe</div>
                            <div style="font-size:10px;">STU001</div>
                            <div style="margin-top:10px; font-size:10px;">Class: JSS 1</div>
                            <?php if ($settings['show_qr'] ?? 1): ?>
                                <div style="margin-top:10px;">[QR Code]</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-back" style="padding:15px; font-size:10px; line-height:1.4;">
                        <?php echo nl2br(htmlspecialchars($settings['card_back_text'] ?? '')); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        document.getElementById('mobileMenuBtn').onclick = () => document.getElementById('sidebar').classList.toggle('active');
    </script>
</body>

</html>
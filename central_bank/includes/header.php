<?php
// /central_bank/includes/header.php - Common Header

if (!isset($page_title)) $page_title = 'Central Bank';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Central Question Bank</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #667eea;
            --primary-dark: #5a67d8;
            --secondary: #764ba2;
            --success: #27ae60;
            --danger: #e74c3c;
            --warning: #f39c12;
            --info: #3498db;
            --dark: #2c3e50;
            --light: #f8f9fa;
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
            background: linear-gradient(180deg, var(--primary), var(--secondary));
            color: white;
            transition: all 0.3s;
            z-index: 100;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header .logo-icon {
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
        }

        .sidebar-header .logo-icon i {
            font-size: 24px;
        }

        .sidebar-header h3 {
            font-size: 1.2rem;
            margin-bottom: 5px;
        }

        .sidebar-header p {
            font-size: 0.75rem;
            opacity: 0.8;
        }

        .nav-menu {
            list-style: none;
            padding: 20px 0;
        }

        .nav-menu li {
            margin-bottom: 5px;
        }

        .nav-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            transition: all 0.3s;
        }

        .nav-menu a:hover,
        .nav-menu a.active {
            background: rgba(255, 255, 255, 0.15);
        }

        .nav-menu i {
            width: 20px;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px;
            min-height: 100vh;
        }

        .top-bar {
            background: white;
            padding: 15px 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .page-title h1 {
            font-size: 1.5rem;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .page-title p {
            color: #666;
            font-size: 0.85rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-name {
            font-weight: 500;
            color: var(--dark);
        }

        .logout-btn {
            background: var(--danger);
            color: white;
            padding: 8px 15px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 8px;
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
            border-left: 4px solid var(--success);
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger);
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
            text-decoration: none;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .data-table {
            width: 100%;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .data-table th {
            background: var(--light);
            font-weight: 600;
        }

        .data-table tr:hover {
            background: #f9f9f9;
        }

        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .badge-active {
            background: #d5f4e6;
            color: var(--success);
        }

        .badge-inactive {
            background: #fee;
            color: var(--danger);
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            max-height: 85vh;
            overflow-y: auto;
        }

        .modal-header,
        .modal-footer {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
        }

        .modal-footer {
            border-top: 1px solid #eee;
            border-bottom: none;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 0.9rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .main-content {
                margin-left: 0;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .mobile-menu-btn {
                display: block;
                position: fixed;
                top: 15px;
                right: 15px;
                z-index: 101;
                background: var(--primary);
                color: white;
                border: none;
                width: 40px;
                height: 40px;
                border-radius: 8px;
                cursor: pointer;
            }
        }

        @media (min-width: 769px) {
            .mobile-menu-btn {
                display: none;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo-icon"><i class="fas fa-database"></i></div>
            <h3>Central Bank</h3>
            <p>Question Repository</p>
        </div>
        <ul class="nav-menu">
            <li><a href="index.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage_schools.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_schools.php' ? 'active' : ''; ?>"><i class="fas fa-school"></i> Manage Schools</a></li>
            <li><a href="manage_subjects.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_subjects.php' ? 'active' : ''; ?>"><i class="fas fa-book"></i> Manage Subjects</a></li>
            <li><a href="manage_topics.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_topics.php' ? 'active' : ''; ?>"><i class="fas fa-list"></i> Manage Topics</a></li>
            <li><a href="manage_questions.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_questions.php' ? 'active' : ''; ?>"><i class="fas fa-question-circle"></i> Manage Questions</a></li>
            <li><a href="id_cards/index.php" class="<?php echo strpos($_SERVER['REQUEST_URI'], 'id_cards') !== false ? 'active' : ''; ?>"><i class="fas fa-id-card"></i> ID Cards</a></li>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><?php echo $page_title; ?></h1>
                <p><?php echo $page_subtitle ?? 'Central Question Bank Management'; ?></p>
            </div>
            <div class="user-info">
                <span class="user-name"><i class="fas fa-user-shield"></i> <?php echo htmlspecialchars($_SESSION['central_admin_name'] ?? 'Admin'); ?></span>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
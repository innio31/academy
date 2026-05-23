<?php
// ida/admin/report_card_dashboard.php - Report Card Dashboard
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /ida/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$admin_name = $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? 'Administrator';
$admin_role = $_SESSION['admin_role'] ?? 'admin';

// Get current session and term
$current_session = date('Y') . '/' . (date('Y') + 1);
$current_term = 'First';

// Check if settings exist for this school
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM report_card_settings WHERE school_id = ?");
$stmt->execute([$school_id]);
$settings_count = $stmt->fetch()['count'];
$settings_exist = $settings_count > 0;

// Get progress stats for this school
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) as count FROM student_scores ss 
                       JOIN students s ON ss.student_id = s.id 
                       WHERE s.school_id = ?");
$stmt->execute([$school_id]);
$students_with_scores = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM students WHERE school_id = ? AND status = 'active'");
$stmt->execute([$school_id]);
$total_students = $stmt->fetch()['count'];
$completion_percentage = $total_students > 0 ? round(($students_with_scores / $total_students) * 100) : 0;

$stmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) as count FROM student_comments sc 
                       JOIN students s ON sc.student_id = s.id 
                       WHERE s.school_id = ?");
$stmt->execute([$school_id]);
$students_with_comments = $stmt->fetch()['count'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Report Card Dashboard</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: #d4af7a;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #3498db;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
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
            color: #333;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-color), var(--dark-color));
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

        /* Main Content */
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
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.8rem;
            margin-bottom: 10px;
        }

        .logout-btn {
            background: var(--danger-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
        }

        .dashboard-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
        }

        .dashboard-card h2 {
            color: var(--primary-color);
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .workflow-steps {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
            margin: 30px 0;
            counter-reset: step;
        }

        .step {
            flex: 1;
            min-width: 180px;
            background: #f8f9fa;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            border: 2px solid transparent;
        }

        .step:before {
            counter-increment: step;
            content: counter(step);
            width: 40px;
            height: 40px;
            background: #ddd;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-weight: bold;
        }

        .step.completed:before {
            background: var(--success-color);
            color: white;
        }

        .step.current:before {
            background: var(--warning-color);
            color: white;
        }

        .step h3 {
            margin-bottom: 10px;
            font-size: 1rem;
        }

        .step p {
            font-size: 0.8rem;
            color: #666;
            margin-bottom: 15px;
        }

        .step .btn {
            padding: 8px 16px;
            font-size: 0.8rem;
        }

        .progress-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
        }

        .progress-bar {
            background: #e0e0e0;
            border-radius: 10px;
            height: 20px;
            overflow: hidden;
            margin: 15px 0;
        }

        .progress-fill {
            background: var(--success-color);
            height: 100%;
            width: 0;
            border-radius: 10px;
            transition: width 0.5s ease;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .stat-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .action-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            text-align: center;
            text-decoration: none;
            color: var(--primary-color);
            border: 2px solid #e0e0e0;
            transition: all 0.3s ease;
        }

        .action-card:hover {
            border-color: var(--primary-color);
            transform: translateY(-5px);
        }

        .action-icon {
            font-size: 2rem;
            margin-bottom: 15px;
            display: block;
        }

        .action-title {
            font-weight: 600;
            margin-bottom: 8px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--info-color);
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
            .workflow-steps {
                flex-direction: column;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
            <div class="logo-text">
                <h3><?php echo htmlspecialchars($school_name); ?></h3>
                <p>Admin Panel</p>
            </div>
        </div>
        <div class="admin-info">
            <h4><?php echo htmlspecialchars($admin_name); ?></h4>
            <p><?php echo ucfirst($admin_role); ?></p>
        </div>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="report_card_dashboard.php" class="active"><i class="fas fa-file-contract"></i> Report Cards</a></li>
            <li><a href="report_card_settings.php"><i class="fas fa-users"></i> Settings</a></li>
            <li><a href="enter_scores.php"><i class="fas fa-chalkboard-teacher"></i> Enter Scores</a></li>
            <li><a href="enter_comments.php"><i class="fas fa-book"></i> Add Comments</a></li>
            <li><a href="calculate_positions.php"><i class="fas fa-file-alt"></i> Calculate</a></li>
            <li><a href="generate_report_card.php"><i class="fas fa-file-contract"></i> Generate Report Cards</a></li>
            <li><a href="../ida/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-file-contract"></i> Report Card Dashboard</h1>
                <p>Prepare and generate student report cards for <?php echo $current_session; ?> - <?php echo $current_term; ?> Term</p>
            </div>
            <button class="logout-btn" onclick="window.location.href='../ida/logout.php'">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </div>

        <div class="dashboard-card">
            <h2><i class="fas fa-project-diagram"></i> Report Card Workflow</h2>
            <div class="workflow-steps">
                <div class="step <?php echo $settings_exist ? 'completed' : 'current'; ?>">
                    <h3>1. Settings</h3>
                    <p>Configure grading and score types</p>
                    <a href="report_card_settings.php" class="btn btn-primary">Configure</a>
                </div>
                <div class="step <?php echo $students_with_scores > 0 ? 'completed' : ''; ?>">
                    <h3>2. Enter Scores</h3>
                    <p>Input student scores</p>
                    <a href="enter_scores.php" class="btn btn-primary">Enter Scores</a>
                </div>
                <div class="step <?php echo $students_with_comments > 0 ? 'completed' : ''; ?>">
                    <h3>3. Comments & Traits</h3>
                    <p>Add behavioral ratings</p>
                    <a href="enter_comments.php" class="btn btn-primary">Add Comments</a>
                </div>
                <div class="step">
                    <h3>4. Calculate Positions</h3>
                    <p>Compute rankings</p>
                    <a href="calculate_positions.php" class="btn btn-primary">Calculate</a>
                </div>
                <div class="step">
                    <h3>5. Generate Reports</h3>
                    <p>Produce final report cards</p>
                    <a href="generate_report_cards.php" class="btn btn-success">Generate</a>
                </div>
            </div>
        </div>

        <div class="progress-section">
            <h2><i class="fas fa-chart-line"></i> Progress Overview</h2>
            <div class="progress-text">Overall Completion: <?php echo $completion_percentage; ?>%</div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo $completion_percentage; ?>%;"></div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_students; ?></div>
                    <div>Total Students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $students_with_scores; ?></div>
                    <div>With Scores</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $students_with_comments; ?></div>
                    <div>With Comments</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $settings_exist ? '✓' : '✗'; ?></div>
                    <div>Settings</div>
                </div>
            </div>
        </div>

        <div class="dashboard-card">
            <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
            <div class="quick-actions">
                <a href="report_card_settings.php" class="action-card"><span class="action-icon">⚙️</span>
                    <div class="action-title">Settings</div>
                </a>
                <a href="enter_scores.php" class="action-card"><span class="action-icon">📝</span>
                    <div class="action-title">Enter Scores</div>
                </a>
                <a href="enter_comments.php" class="action-card"><span class="action-icon">💬</span>
                    <div class="action-title">Comments & Traits</div>
                </a>
                <a href="calculate_positions.php" class="action-card"><span class="action-icon">📊</span>
                    <div class="action-title">Calculate Positions</div>
                </a>
                <a href="generate_report_cards.php" class="action-card"><span class="action-icon">📄</span>
                    <div class="action-title">Generate Report Cards</div>
                </a>
            </div>
        </div>
    </div>

    <script>
        const mobileBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        if (mobileBtn) mobileBtn.onclick = () => sidebar.classList.toggle('active');

        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768 && sidebar && mobileBtn) {
                if (!sidebar.contains(e.target) && !mobileBtn.contains(e.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });
    </script>
</body>

</html>
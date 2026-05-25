<?php
// tbis/admin/report_card_settings.php - Report Card Settings
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /tbis/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$admin_name = $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? 'Administrator';
$admin_role = $_SESSION['admin_role'] ?? 'admin';

$message = '';
$message_type = '';

// Get available classes for this school
$stmt = $pdo->prepare("SELECT DISTINCT class FROM students WHERE school_id = ? AND status = 'active' ORDER BY class");
$stmt->execute([$school_id]);
$available_classes = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $session = $_POST['session'];
    $term = $_POST['term'];
    $classes = $_POST['classes'] ?? [];
    $max_score = intval($_POST['max_score']);
    $grading_system = $_POST['grading_system'];
    $next_resumption_date = $_POST['next_resumption_date'];
    $current_resumption_date = $_POST['current_resumption_date'];
    $current_closing_date = $_POST['current_closing_date'];
    $days_school_opened = intval($_POST['days_school_opened'] ?? 0);
    $template = $_POST['template'] ?? 'default';

    $show_class_position = isset($_POST['show_class_position']) ? 1 : 0;
    $show_subject_position = isset($_POST['show_subject_position']) ? 1 : 0;
    $show_promoted_to = isset($_POST['show_promoted_to']) ? 1 : 0;
    $show_lowest_highest_avg = isset($_POST['show_lowest_highest_avg']) ? 1 : 0;
    $show_lowest_highest_class = isset($_POST['show_lowest_highest_class']) ? 1 : 0;

    // Get score types
    $score_types = [];
    if (isset($_POST['score_type_name']) && isset($_POST['score_type_max'])) {
        $names = $_POST['score_type_name'];
        $max_scores = $_POST['score_type_max'];
        for ($i = 0; $i < count($names); $i++) {
            if (!empty(trim($names[$i])) && !empty($max_scores[$i])) {
                $score_types[] = [
                    'name' => trim($names[$i]),
                    'max_score' => intval($max_scores[$i])
                ];
            }
        }
    }

    // Validate total matches max score
    $total_score_types = array_sum(array_column($score_types, 'max_score'));
    if ($total_score_types != $max_score && $max_score > 0) {
        $message = "Error: Total of score types ($total_score_types) must equal maximum score ($max_score)";
        $message_type = "error";
    } elseif (empty($classes)) {
        $message = "Error: Please select at least one class";
        $message_type = "error";
    } else {
        try {
            $score_types_json = json_encode($score_types);
            $success_count = 0;

            foreach ($classes as $class) {
                // Check if settings exist
                $stmt = $pdo->prepare("SELECT id FROM report_card_settings WHERE school_id = ? AND session = ? AND term = ? AND class = ?");
                $stmt->execute([$school_id, $session, $term, $class]);

                if ($stmt->fetch()) {
                    $stmt = $pdo->prepare("UPDATE report_card_settings SET 
                        max_score = ?, score_types = ?, grading_system = ?,
                        next_resumption_date = ?, current_resumption_date = ?, current_closing_date = ?,
                        days_school_opened = ?, template = ?, show_class_position = ?,
                        show_subject_position = ?, show_promoted_to = ?, show_lowest_highest_avg = ?,
                        show_lowest_highest_class = ?, updated_at = NOW()
                        WHERE school_id = ? AND session = ? AND term = ? AND class = ?");
                    $stmt->execute([
                        $max_score,
                        $score_types_json,
                        $grading_system,
                        $next_resumption_date,
                        $current_resumption_date,
                        $current_closing_date,
                        $days_school_opened,
                        $template,
                        $show_class_position,
                        $show_subject_position,
                        $show_promoted_to,
                        $show_lowest_highest_avg,
                        $show_lowest_highest_class,
                        $school_id,
                        $session,
                        $term,
                        $class
                    ]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO report_card_settings (
                        school_id, session, term, class, max_score, score_types, grading_system,
                        next_resumption_date, current_resumption_date, current_closing_date,
                        days_school_opened, template, show_class_position, show_subject_position,
                        show_promoted_to, show_lowest_highest_avg, show_lowest_highest_class,
                        created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    $stmt->execute([
                        $school_id,
                        $session,
                        $term,
                        $class,
                        $max_score,
                        $score_types_json,
                        $grading_system,
                        $next_resumption_date,
                        $current_resumption_date,
                        $current_closing_date,
                        $days_school_opened,
                        $template,
                        $show_class_position,
                        $show_subject_position,
                        $show_promoted_to,
                        $show_lowest_highest_avg,
                        $show_lowest_highest_class
                    ]);
                }
                $success_count++;
            }

            $message = "Settings saved successfully for $success_count class(es)!";
            $message_type = "success";
        } catch (Exception $e) {
            $message = "Error saving settings: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Get current settings for default/preview
$current_session = date('Y') . '/' . (date('Y') + 1);
$current_term = 'First';
$selected_class = $_GET['class'] ?? ($available_classes[0] ?? '');

$current_settings = null;
$score_types = [['name' => 'CA 1', 'max_score' => 20], ['name' => 'CA 2', 'max_score' => 20], ['name' => 'Exam', 'max_score' => 60]];
$max_score = 100;
$grading_system = 'simple';
$template = 'default';
$show_class_position = 1;
$show_subject_position = 1;
$show_promoted_to = 1;
$show_lowest_highest_avg = 1;
$show_lowest_highest_class = 1;
$next_resumption_date = '';
$current_resumption_date = '';
$current_closing_date = '';
$days_school_opened = 90;

if ($selected_class) {
    $stmt = $pdo->prepare("SELECT * FROM report_card_settings WHERE school_id = ? AND session = ? AND term = ? AND class = ?");
    $stmt->execute([$school_id, $current_session, $current_term, $selected_class]);
    $current_settings = $stmt->fetch();

    if ($current_settings) {
        $max_score = $current_settings['max_score'];
        $score_types = json_decode($current_settings['score_types'], true) ?: $score_types;
        $grading_system = $current_settings['grading_system'];
        $template = $current_settings['template'] ?? 'default';
        $show_class_position = $current_settings['show_class_position'];
        $show_subject_position = $current_settings['show_subject_position'];
        $show_promoted_to = $current_settings['show_promoted_to'];
        $show_lowest_highest_avg = $current_settings['show_lowest_highest_avg'];
        $show_lowest_highest_class = $current_settings['show_lowest_highest_class'];
        $next_resumption_date = $current_settings['next_resumption_date'];
        $current_resumption_date = $current_settings['current_resumption_date'];
        $current_closing_date = $current_settings['current_closing_date'];
        $days_school_opened = $current_settings['days_school_opened'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Report Card Settings</title>

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

        .settings-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
        }

        .settings-card h2 {
            color: var(--primary-color);
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-section {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }

        .form-section h3 {
            color: var(--primary-color);
            margin-bottom: 15px;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
            font-size: 0.85rem;
        }

        .form-control,
        .form-select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.9rem;
            font-family: 'Poppins', sans-serif;
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .class-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }

        .class-option {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .class-option input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .class-option label {
            margin: 0;
            cursor: pointer;
            font-weight: normal;
        }

        .score-breakdown {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin: 15px 0;
        }

        .score-item {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
            padding: 10px;
            background: white;
            border-radius: 8px;
        }

        .score-item input[type="text"] {
            flex: 2;
        }

        .score-item input[type="number"] {
            flex: 1;
            text-align: center;
        }

        .score-total {
            text-align: center;
            padding: 12px;
            border-radius: 8px;
            margin-top: 15px;
            font-weight: 600;
        }

        .score-total.valid {
            background: #d5f4e6;
            color: var(--success-color);
        }

        .score-total.invalid {
            background: #f8d7da;
            color: var(--danger-color);
        }

        .toggle-options {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
        }

        .toggle-option {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .toggle-option input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .toggle-option label {
            margin: 0;
            cursor: pointer;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .alert {
            padding: 12px 20px;
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

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger-color);
        }

        .grading-info {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
            font-size: 0.85rem;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary-color);
            text-decoration: none;
            margin-top: 20px;
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
            .form-grid {
                grid-template-columns: 1fr;
            }

            .class-grid {
                grid-template-columns: 1fr;
            }

            .toggle-options {
                grid-template-columns: 1fr;
            }

            .score-item {
                flex-direction: column;
            }

            .score-item input {
                width: 100%;
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
            <li><a href="report_card_dashboard.php"><i class="fas fa-file-contract"></i> Report Cards</a></li>
            <li><a href="report_card_settings.php" class="active"><i class="fas fa-users"></i> Settings</a></li>
            <li><a href="enter_scores.php"><i class="fas fa-chalkboard-teacher"></i> Enter Scores</a></li>
            <li><a href="enter_comments.php"><i class="fas fa-book"></i> Add Comments</a></li>
            <li><a href="calculate_positions.php"><i class="fas fa-file-alt"></i> Calculate</a></li>
            <li><a href="report_cards.php"><i class="fas fa-file-contract"></i> Generate Report Cards</a></li>
            <li><a href="../tbis/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-cogs"></i> Report Card Settings</h1>
                <p>Configure grading system, score types, and display options</p>
            </div>
            <button class="logout-btn" onclick="window.location.href='/tbis/logout.php'">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="settings-card">
            <h2><i class="fas fa-sliders-h"></i> Report Card Configuration</h2>

            <form method="POST" id="settingsForm">
                <div class="form-section">
                    <h3><i class="fas fa-info-circle"></i> Academic Information</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Academic Session</label>
                            <input type="text" name="session" class="form-control" value="<?php echo htmlspecialchars($current_session); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Term</label>
                            <select name="term" class="form-select" required>
                                <option value="First" <?php echo $current_term == 'First' ? 'selected' : ''; ?>>First Term</option>
                                <option value="Second" <?php echo $current_term == 'Second' ? 'selected' : ''; ?>>Second Term</option>
                                <option value="Third" <?php echo $current_term == 'Third' ? 'selected' : ''; ?>>Third Term</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3><i class="fas fa-chalkboard"></i> Select Classes</h3>
                    <div class="class-grid">
                        <?php foreach ($available_classes as $class): ?>
                            <div class="class-option">
                                <input type="checkbox" name="classes[]" id="class_<?php echo md5($class); ?>" value="<?php echo htmlspecialchars($class); ?>"
                                    <?php echo ($current_settings && $current_settings['class'] == $class) ? 'checked' : ''; ?>>
                                <label for="class_<?php echo md5($class); ?>"><?php echo htmlspecialchars($class); ?></label>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($available_classes)): ?>
                            <p style="color: #999;">No classes found. Add students first.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-section">
                    <h3><i class="fas fa-calendar-alt"></i> Important Dates</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Current Term Resumption</label>
                            <input type="date" name="current_resumption_date" class="form-control" value="<?php echo htmlspecialchars($current_resumption_date); ?>">
                        </div>
                        <div class="form-group">
                            <label>Current Term Closing</label>
                            <input type="date" name="current_closing_date" class="form-control" value="<?php echo htmlspecialchars($current_closing_date); ?>">
                        </div>
                        <div class="form-group">
                            <label>Next Term Resumption</label>
                            <input type="date" name="next_resumption_date" class="form-control" value="<?php echo htmlspecialchars($next_resumption_date); ?>">
                        </div>
                        <div class="form-group">
                            <label>Days School Opened</label>
                            <input type="number" name="days_school_opened" class="form-control" value="<?php echo $days_school_opened; ?>" min="1" max="365">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3><i class="fas fa-chart-line"></i> Score Configuration</h3>
                    <div class="form-group">
                        <label>Maximum Obtainable Score</label>
                        <input type="number" name="max_score" id="max_score" class="form-control" value="<?php echo $max_score; ?>" min="1" max="200" required>
                    </div>

                    <div class="score-breakdown">
                        <h4 style="margin-bottom: 15px;">Score Types Breakdown</h4>
                        <div id="score-types-container">
                            <?php foreach ($score_types as $index => $type): ?>
                                <div class="score-item">
                                    <input type="text" name="score_type_name[]" placeholder="e.g., CA 1" value="<?php echo htmlspecialchars($type['name']); ?>" required>
                                    <input type="number" name="score_type_max[]" class="score-type-max" value="<?php echo $type['max_score']; ?>" min="0" max="100" required>
                                    <button type="button" class="btn btn-warning" style="padding: 8px 12px;" onclick="removeScoreType(this)"><i class="fas fa-trash"></i></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-secondary" style="margin-top: 10px;" onclick="addScoreType()"><i class="fas fa-plus"></i> Add Score Type</button>

                        <div class="score-total" id="score-total-display">
                            Total: <span id="total-score-types">0</span> / <span id="max-score-display"><?php echo $max_score; ?></span>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3><i class="fas fa-star"></i> Grading System</h3>
                    <div class="form-group">
                        <select name="grading_system" id="grading_system" class="form-select">
                            <option value="simple" <?php echo $grading_system == 'simple' ? 'selected' : ''; ?>>Simple Letter Grading (A-F)</option>
                            <option value="american" <?php echo $grading_system == 'american' ? 'selected' : ''; ?>>American Grading (A+-F)</option>
                            <option value="waec" <?php echo $grading_system == 'waec' ? 'selected' : ''; ?>>WAEC Grading (A1-F9)</option>
                        </select>
                    </div>
                    <div class="grading-info" id="grading-info">
                        <!-- Will be populated by JavaScript -->
                    </div>
                </div>

                <div class="form-section">
                    <h3><i class="fas fa-eye"></i> Display Options</h3>
                    <div class="toggle-options">
                        <div class="toggle-option">
                            <input type="checkbox" name="show_class_position" id="show_class_position" <?php echo $show_class_position ? 'checked' : ''; ?>>
                            <label for="show_class_position">Show Class Position</label>
                        </div>
                        <div class="toggle-option">
                            <input type="checkbox" name="show_subject_position" id="show_subject_position" <?php echo $show_subject_position ? 'checked' : ''; ?>>
                            <label for="show_subject_position">Show Subject Position</label>
                        </div>
                        <div class="toggle-option">
                            <input type="checkbox" name="show_promoted_to" id="show_promoted_to" <?php echo $show_promoted_to ? 'checked' : ''; ?>>
                            <label for="show_promoted_to">Show Promoted To</label>
                        </div>
                        <div class="toggle-option">
                            <input type="checkbox" name="show_lowest_highest_avg" id="show_lowest_highest_avg" <?php echo $show_lowest_highest_avg ? 'checked' : ''; ?>>
                            <label for="show_lowest_highest_avg">Show Lowest/Highest Average</label>
                        </div>
                        <div class="toggle-option">
                            <input type="checkbox" name="show_lowest_highest_class" id="show_lowest_highest_class" <?php echo $show_lowest_highest_class ? 'checked' : ''; ?>>
                            <label for="show_lowest_highest_class">Show Lowest/Highest in Class</label>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3><i class="fas fa-palette"></i> Report Card Template</h3>
                    <div class="form-group">
                        <select name="template" id="template" class="form-select">
                            <option value="default" <?php echo $template == 'default' ? 'selected' : ''; ?>>Default Template</option>
                            <option value="modern" <?php echo $template == 'modern' ? 'selected' : ''; ?>>Modern Template</option>
                            <option value="classic" <?php echo $template == 'classic' ? 'selected' : ''; ?>>Classic Template</option>
                            <option value="minimal" <?php echo $template == 'minimal' ? 'selected' : ''; ?>>Minimal Template</option>
                        </select>
                    </div>
                </div>

                <div style="display: flex; gap: 15px; margin-top: 20px;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Settings</button>
                    <a href="report_card_dashboard.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>

            <a href="report_card_dashboard.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>

    <script>
        // Mobile menu
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

        // Score type management
        let scoreTypeIndex = <?php echo count($score_types); ?>;

        function addScoreType() {
            const container = document.getElementById('score-types-container');
            const newItem = document.createElement('div');
            newItem.className = 'score-item';
            newItem.innerHTML = `
                <input type="text" name="score_type_name[]" placeholder="e.g., CA 1" required>
                <input type="number" name="score_type_max[]" class="score-type-max" value="0" min="0" max="100" required>
                <button type="button" class="btn btn-warning" style="padding: 8px 12px;" onclick="removeScoreType(this)"><i class="fas fa-trash"></i></button>
            `;
            container.appendChild(newItem);
            const inputs = newItem.querySelectorAll('input');
            inputs.forEach(input => input.addEventListener('input', updateTotalScoreTypes));
            updateTotalScoreTypes();
        }

        function removeScoreType(btn) {
            const container = document.getElementById('score-types-container');
            if (container.children.length > 1) {
                btn.closest('.score-item').remove();
                updateTotalScoreTypes();
            } else {
                alert('You must have at least one score type.');
            }
        }

        function updateTotalScoreTypes() {
            const maxScore = parseInt(document.getElementById('max_score').value) || 0;
            const scoreInputs = document.querySelectorAll('.score-type-max');
            let total = 0;
            scoreInputs.forEach(input => {
                total += parseInt(input.value) || 0;
            });

            document.getElementById('total-score-types').textContent = total;
            document.getElementById('max-score-display').textContent = maxScore;

            const display = document.getElementById('score-total-display');
            if (total === maxScore && maxScore > 0) {
                display.className = 'score-total valid';
            } else {
                display.className = 'score-total invalid';
            }
        }

        // Grading system info
        function updateGradingInfo() {
            const system = document.getElementById('grading_system').value;
            const infoDiv = document.getElementById('grading-info');
            const systems = {
                simple: '<strong>Simple Letter Grading:</strong><br>A: 80-100% | B: 70-79% | C: 60-69% | D: 50-59% | E: 40-49% | F: 0-39%',
                american: '<strong>American Grading System:</strong><br>A+: 97-100% | A: 93-96% | A-: 90-92%<br>B+: 87-89% | B: 83-86% | B-: 80-82%<br>C+: 77-79% | C: 73-76% | C-: 70-72%<br>D+: 67-69% | D: 63-66% | D-: 60-62%<br>F: 0-59%',
                waec: '<strong>WAEC Grading System:</strong><br>A1: 75-100% | B2: 70-74% | B3: 65-69% | C4: 60-64% | C5: 55-59% | C6: 50-54% | D7: 45-49% | E8: 40-44% | F9: 0-39%'
            };
            infoDiv.innerHTML = systems[system] || systems.simple;
        }

        // Form validation
        document.getElementById('settingsForm').addEventListener('submit', function(e) {
            const classes = document.querySelectorAll('input[name="classes[]"]:checked');
            if (classes.length === 0) {
                e.preventDefault();
                alert('Please select at least one class.');
                return false;
            }

            const maxScore = parseInt(document.getElementById('max_score').value) || 0;
            const scoreInputs = document.querySelectorAll('.score-type-max');
            let total = 0;
            scoreInputs.forEach(input => {
                total += parseInt(input.value) || 0;
            });

            if (total !== maxScore) {
                e.preventDefault();
                alert(`Total score types (${total}) must equal maximum score (${maxScore}).`);
                return false;
            }
            return true;
        });

        // Event listeners
        document.getElementById('max_score').addEventListener('input', updateTotalScoreTypes);
        document.getElementById('grading_system').addEventListener('change', updateGradingInfo);
        document.querySelectorAll('.score-type-max').forEach(input => input.addEventListener('input', updateTotalScoreTypes));

        // Initialize
        updateTotalScoreTypes();
        updateGradingInfo();
    </script>
</body>

</html>
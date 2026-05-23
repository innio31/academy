<?php
// ida/admin/enter_scores.php - Enter Student Scores with Multi-School Support
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

// Get classes for this school
$stmt = $pdo->prepare("SELECT DISTINCT class FROM students WHERE school_id = ? AND status = 'active' ORDER BY class");
$stmt->execute([$school_id]);
$classes = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get subjects for this school
$stmt = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE school_id = ? ORDER BY subject_name");
$stmt->execute([$school_id]);
$subjects = $stmt->fetchAll();

// Handle form input
$selected_class = $_POST['class'] ?? ($_GET['class'] ?? '');
$selected_subject_id = $_POST['subject_id'] ?? ($_GET['subject_id'] ?? '');
$session = $_POST['session'] ?? $current_session;
$term = $_POST['term'] ?? $current_term;

$students = [];
$settings = null;
$score_types = [];
$message = '';
$message_type = '';

// Load settings for selected class
if ($selected_class) {
    $stmt = $pdo->prepare("SELECT * FROM report_card_settings WHERE class = ? AND school_id = ?");
    $stmt->execute([$selected_class, $school_id]);
    $settings = $stmt->fetch();

    if ($settings) {
        if (!empty($settings['score_types'])) {
            $score_types = json_decode($settings['score_types'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $score_types = [
                    ['name' => 'CA 1', 'max_score' => 20],
                    ['name' => 'CA 2', 'max_score' => 20],
                    ['name' => 'Exam', 'max_score' => 60]
                ];
            }
        } else {
            $score_types = [
                ['name' => 'CA 1', 'max_score' => 20],
                ['name' => 'CA 2', 'max_score' => 20],
                ['name' => 'Exam', 'max_score' => 60]
            ];
        }
    }

    // Get students only if subject is selected
    if ($selected_subject_id) {
        $stmt = $pdo->prepare("SELECT id, full_name, admission_number FROM students WHERE class = ? AND school_id = ? AND status = 'active' ORDER BY full_name");
        $stmt->execute([$selected_class, $school_id]);
        $students = $stmt->fetchAll();
    }
}

// Handle score submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_scores'])) {
    $session = $_POST['session'];
    $term = $_POST['term'];
    $subject_id = $_POST['subject_id'];
    $class = $_POST['class'];

    // Get settings for this class
    $stmt = $pdo->prepare("SELECT * FROM report_card_settings WHERE class = ? AND school_id = ?");
    $stmt->execute([$class, $school_id]);
    $settings = $stmt->fetch();

    if (!$settings) {
        $message = "No settings found for class: $class. Please configure report card settings first.";
        $message_type = "error";
    } else {
        $score_types = json_decode($settings['score_types'], true);
        if (empty($score_types)) {
            $score_types = [
                ['name' => 'CA 1', 'max_score' => 20],
                ['name' => 'CA 2', 'max_score' => 20],
                ['name' => 'Exam', 'max_score' => 60]
            ];
        }

        $success_count = 0;
        $error_count = 0;
        $skipped_count = 0;

        // Get subject name
        $stmt = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ? AND school_id = ?");
        $stmt->execute([$subject_id, $school_id]);
        $subject_name = $stmt->fetchColumn();

        if (isset($_POST['scores']) && is_array($_POST['scores'])) {
            foreach ($_POST['scores'] as $student_id => $score_data) {
                try {
                    $scores = [];
                    $total_score = 0;
                    $has_scores = false;

                    foreach ($score_types as $score_type) {
                        $score_key = str_replace(' ', '_', strtolower($score_type['name']));
                        $score = isset($score_data[$score_key]) ? trim($score_data[$score_key]) : '';

                        if ($score === '' || $score === 'skip' || $score === 'NA' || $score === 'N/A') {
                            continue 2;
                        }

                        $score_value = floatval($score);
                        $scores[$score_type['name']] = $score_value;
                        $total_score += $score_value;
                        $has_scores = true;
                    }

                    if ($has_scores) {
                        // Calculate percentage
                        $percentage = ($total_score / $settings['max_score']) * 100;
                        $grade = calculateGrade($percentage, $settings['grading_system']);

                        // Check if record exists
                        $stmt = $pdo->prepare("SELECT id FROM student_scores WHERE student_id = ? AND subject_id = ? AND session = ? AND term = ? AND school_id = ?");
                        $stmt->execute([$student_id, $subject_id, $session, $term, $school_id]);

                        if ($stmt->fetch()) {
                            $stmt = $pdo->prepare("UPDATE student_scores SET score_data = ?, total_score = ?, percentage = ?, grade = ?, updated_at = NOW() WHERE student_id = ? AND subject_id = ? AND session = ? AND term = ? AND school_id = ?");
                            $stmt->execute([json_encode($scores), $total_score, $percentage, $grade, $student_id, $subject_id, $session, $term, $school_id]);
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO student_scores (student_id, subject_id, subject_name, session, term, score_data, total_score, percentage, grade, school_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                            $stmt->execute([$student_id, $subject_id, $subject_name, $session, $term, json_encode($scores), $total_score, $percentage, $grade, $school_id]);
                        }
                        $success_count++;
                    } else {
                        $skipped_count++;
                    }
                } catch (Exception $e) {
                    $error_count++;
                    error_log("Error saving score: " . $e->getMessage());
                }
            }
        }

        $message = "✅ Saved scores for $success_count students";
        if ($skipped_count > 0) $message .= " | $skipped_count skipped";
        if ($error_count > 0) $message .= " | $error_count errors";
        $message_type = $success_count > 0 ? "success" : "warning";

        // Reload students
        $stmt = $pdo->prepare("SELECT id, full_name, admission_number FROM students WHERE class = ? AND school_id = ? AND status = 'active' ORDER BY full_name");
        $stmt->execute([$selected_class, $school_id]);
        $students = $stmt->fetchAll();
    }
}

function calculateGrade($percentage, $grading_system)
{
    switch ($grading_system) {
        case 'simple':
            if ($percentage >= 80) return 'A';
            if ($percentage >= 70) return 'B';
            if ($percentage >= 60) return 'C';
            if ($percentage >= 50) return 'D';
            if ($percentage >= 40) return 'E';
            return 'F';
        case 'american':
            if ($percentage >= 97) return 'A+';
            if ($percentage >= 93) return 'A';
            if ($percentage >= 90) return 'A-';
            if ($percentage >= 87) return 'B+';
            if ($percentage >= 83) return 'B';
            if ($percentage >= 80) return 'B-';
            if ($percentage >= 77) return 'C+';
            if ($percentage >= 73) return 'C';
            if ($percentage >= 70) return 'C-';
            if ($percentage >= 67) return 'D+';
            if ($percentage >= 63) return 'D';
            if ($percentage >= 60) return 'D-';
            return 'F';
        case 'waec':
            if ($percentage >= 75) return 'A1';
            if ($percentage >= 70) return 'B2';
            if ($percentage >= 65) return 'B3';
            if ($percentage >= 60) return 'C4';
            if ($percentage >= 55) return 'C5';
            if ($percentage >= 50) return 'C6';
            if ($percentage >= 45) return 'D7';
            if ($percentage >= 40) return 'E8';
            return 'F9';
        default:
            return 'F';
    }
}

// Get existing scores for display
$existing_scores = [];
if ($selected_class && $selected_subject_id && !empty($students)) {
    $student_ids = array_column($students, 'id');
    $placeholders = str_repeat('?,', count($student_ids) - 1) . '?';
    $stmt = $pdo->prepare("SELECT student_id, score_data, total_score, percentage, grade FROM student_scores WHERE student_id IN ($placeholders) AND subject_id = ? AND session = ? AND term = ? AND school_id = ?");
    $params = array_merge($student_ids, [$selected_subject_id, $session, $term, $school_id]);
    $stmt->execute($params);
    $scores_data = $stmt->fetchAll();
    foreach ($scores_data as $score) {
        $existing_scores[$score['student_id']] = [
            'data' => json_decode($score['score_data'], true),
            'total' => $score['total_score'],
            'percentage' => $score['percentage'],
            'grade' => $score['grade']
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Enter Scores</title>

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

        .form-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--primary-color);
        }

        .form-control,
        .form-select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .settings-info {
            background: #e8f4fc;
            border-left: 4px solid var(--primary-color);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .score-types {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 10px;
        }

        .score-type-badge {
            background: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            border: 1px solid var(--primary-color);
        }

        .scores-table {
            width: 100%;
            border-collapse: collapse;
            overflow-x: auto;
            display: block;
        }

        .scores-table th,
        .scores-table td {
            padding: 12px;
            text-align: center;
            border-bottom: 1px solid #eee;
        }

        .scores-table th {
            background: var(--primary-color);
            color: white;
            font-weight: 600;
        }

        .scores-table tr:hover {
            background: #f9f9f9;
        }

        .student-name {
            text-align: left;
            font-weight: 500;
        }

        .student-admission {
            font-size: 0.7rem;
            color: #999;
            margin-top: 3px;
        }

        .score-input {
            width: 90px;
            padding: 8px;
            text-align: center;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
        }

        .score-input:focus {
            border-color: var(--primary-color);
            outline: none;
        }

        .total-cell {
            font-weight: 600;
            color: var(--primary-color);
        }

        .percentage-cell {
            font-weight: 600;
        }

        .grade-cell {
            font-weight: 600;
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

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger-color);
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid var(--warning-color);
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

            .scores-table {
                font-size: 0.8rem;
            }

            .score-input {
                width: 60px;
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
            <li><a href="report_card_settings.php"><i class="fas fa-users"></i> Settings</a></li>
            <li><a href="enter_scores.php" class="active"><i class="fas fa-chalkboard-teacher"></i> Enter Scores</a></li>
            <li><a href="enter_comments.php"><i class="fas fa-book"></i> Add Comments</a></li>
            <li><a href="calculate_positions.php"><i class="fas fa-file-alt"></i> Calculate</a></li>
            <li><a href="report_cards.php"><i class="fas fa-file-contract"></i> Generate Report Cards</a></li>
            <li><a href="../ida/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-edit"></i> Enter Student Scores</h1>
                <p>Enter scores for students by class and subject</p>
            </div>
            <button class="logout-btn" onclick="window.location.href='../ida/logout.php'">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'error' ? 'exclamation-triangle' : 'exclamation-circle'); ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Selection Form -->
        <div class="form-section">
            <h2 style="margin-bottom: 20px;"><i class="fas fa-filter"></i> Select Class & Subject</h2>
            <form method="POST" id="selectionForm">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Class</label>
                        <select name="class" class="form-select" required>
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo htmlspecialchars($class); ?>" <?php echo $selected_class == $class ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Subject</label>
                        <select name="subject_id" class="form-select" required>
                            <option value="">Select Subject</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>" <?php echo $selected_subject_id == $subject['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Session</label>
                        <input type="text" name="session" class="form-control" value="<?php echo htmlspecialchars($session); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Term</label>
                        <select name="term" class="form-select" required>
                            <option value="First" <?php echo $term == 'First' ? 'selected' : ''; ?>>First Term</option>
                            <option value="Second" <?php echo $term == 'Second' ? 'selected' : ''; ?>>Second Term</option>
                            <option value="Third" <?php echo $term == 'Third' ? 'selected' : ''; ?>>Third Term</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top: 20px;">
                    <button type="submit" name="load_students" class="btn btn-primary"><i class="fas fa-users"></i> Load Students</button>
                </div>
            </form>
        </div>

        <?php if ($selected_class && !$settings): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                No report card settings found for <strong><?php echo htmlspecialchars($selected_class); ?></strong>.
                <a href="report_card_settings.php" style="color: var(--warning-color);">Configure settings first →</a>
            </div>
        <?php elseif ($selected_class && $settings && $selected_subject_id): ?>

            <!-- Settings Info -->
            <div class="settings-info">
                <strong><i class="fas fa-info-circle"></i> Settings for <?php echo htmlspecialchars($selected_class); ?></strong><br>
                Max Score: <?php echo $settings['max_score']; ?> |
                Grading: <?php echo ucfirst($settings['grading_system']); ?>
                <div class="score-types">
                    <?php foreach ($score_types as $type): ?>
                        <span class="score-type-badge"><?php echo htmlspecialchars($type['name']); ?>: <?php echo $type['max_score']; ?></span>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Scores Entry -->
            <div class="form-section">
                <h2 style="margin-bottom: 20px;"><i class="fas fa-user-graduate"></i> Scores for <?php echo htmlspecialchars($selected_class); ?></h2>

                <?php if (empty($students)): ?>
                    <div style="text-align:center; padding: 40px;">
                        <i class="fas fa-users" style="font-size: 48px; color: #ccc; margin-bottom: 15px;"></i>
                        <p>No active students found in <?php echo htmlspecialchars($selected_class); ?></p>
                    </div>
                <?php else: ?>
                    <form method="POST" id="scoresForm">
                        <input type="hidden" name="class" value="<?php echo htmlspecialchars($selected_class); ?>">
                        <input type="hidden" name="subject_id" value="<?php echo $selected_subject_id; ?>">
                        <input type="hidden" name="session" value="<?php echo htmlspecialchars($session); ?>">
                        <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">

                        <div style="overflow-x: auto;">
                            <table class="scores-table">
                                <thead>
                                    <tr>
                                        <th>Student Name</th>
                                        <?php foreach ($score_types as $type): ?>
                                            <th><?php echo htmlspecialchars($type['name']); ?><br><small>Max: <?php echo $type['max_score']; ?></small></th>
                                        <?php endforeach; ?>
                                        <th>Total</th>
                                        <th>%</th>
                                        <th>Grade</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($students as $student):
                                        $existing = $existing_scores[$student['id']] ?? null;
                                        $total = $existing['total'] ?? '';
                                        $percentage = $existing['percentage'] ?? '';
                                        $grade = $existing['grade'] ?? '';
                                    ?>
                                        <tr>
                                            <td class="student-name">
                                                <?php echo htmlspecialchars($student['full_name']); ?>
                                                <div class="student-admission"><?php echo htmlspecialchars($student['admission_number']); ?></div>
                                            </td>
                                            <?php foreach ($score_types as $type):
                                                $score_key = str_replace(' ', '_', strtolower($type['name']));
                                                $value = isset($existing['data'][$type['name']]) ? $existing['data'][$type['name']] : '';
                                            ?>
                                                <td>
                                                    <input type="number" step="0.5"
                                                        name="scores[<?php echo $student['id']; ?>][<?php echo $score_key; ?>]"
                                                        class="score-input"
                                                        value="<?php echo htmlspecialchars($value); ?>"
                                                        data-student="<?php echo $student['id']; ?>"
                                                        data-max="<?php echo $type['max_score']; ?>"
                                                        onchange="calculateTotal(this)">
                                                </td>
                                            <?php endforeach; ?>
                                            <td class="total-cell" id="total_<?php echo $student['id']; ?>"><?php echo $total ? number_format($total, 1) : '-'; ?></td>
                                            <td class="percentage-cell" id="percent_<?php echo $student['id']; ?>"><?php echo $percentage ? number_format($percentage, 1) . '%' : '-'; ?></td>
                                            <td class="grade-cell" id="grade_<?php echo $student['id']; ?>"><?php echo $grade ?: '-'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div style="margin-top: 30px; display: flex; gap: 15px; justify-content: center;">
                            <button type="submit" name="save_scores" class="btn btn-success"><i class="fas fa-save"></i> Save All Scores</button>
                            <button type="button" class="btn btn-warning" onclick="clearAllScores()"><i class="fas fa-trash-alt"></i> Clear All</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        <?php elseif ($selected_class && $settings && !$selected_subject_id): ?>
            <div class="alert alert-info">Please select a subject to enter scores.</div>
        <?php endif; ?>
    </div>

    <script>
        function calculateTotal(input) {
            const studentId = input.dataset.student;
            const maxScore = <?php echo $settings['max_score'] ?? 100; ?>;
            const row = input.closest('tr');
            const inputs = row.querySelectorAll('.score-input');
            let total = 0;
            inputs.forEach(inp => {
                let val = parseFloat(inp.value);
                if (!isNaN(val)) total += val;
            });

            document.getElementById(`total_${studentId}`).textContent = total.toFixed(1);

            const percentage = (total / maxScore) * 100;
            const percentDisplay = percentage.toFixed(1);
            document.getElementById(`percent_${studentId}`).textContent = percentDisplay + '%';

            const grade = calculateGrade(percentage);
            document.getElementById(`grade_${studentId}`).textContent = grade;
        }

        function calculateGrade(percentage) {
            const system = '<?php echo $settings['grading_system'] ?? 'simple'; ?>';
            if (system === 'simple') {
                if (percentage >= 80) return 'A';
                if (percentage >= 70) return 'B';
                if (percentage >= 60) return 'C';
                if (percentage >= 50) return 'D';
                if (percentage >= 40) return 'E';
                return 'F';
            } else if (system === 'american') {
                if (percentage >= 97) return 'A+';
                if (percentage >= 93) return 'A';
                if (percentage >= 90) return 'A-';
                if (percentage >= 87) return 'B+';
                if (percentage >= 83) return 'B';
                if (percentage >= 80) return 'B-';
                if (percentage >= 77) return 'C+';
                if (percentage >= 73) return 'C';
                if (percentage >= 70) return 'C-';
                if (percentage >= 67) return 'D+';
                if (percentage >= 63) return 'D';
                if (percentage >= 60) return 'D-';
                return 'F';
            } else if (system === 'waec') {
                if (percentage >= 75) return 'A1';
                if (percentage >= 70) return 'B2';
                if (percentage >= 65) return 'B3';
                if (percentage >= 60) return 'C4';
                if (percentage >= 55) return 'C5';
                if (percentage >= 50) return 'C6';
                if (percentage >= 45) return 'D7';
                if (percentage >= 40) return 'E8';
                return 'F9';
            }
            return 'F';
        }

        function clearAllScores() {
            if (confirm('Clear all scores on this page? This cannot be undone.')) {
                document.querySelectorAll('.score-input').forEach(inp => inp.value = '');
                document.querySelectorAll('[id^="total_"]').forEach(span => span.textContent = '0');
                document.querySelectorAll('[id^="percent_"]').forEach(span => span.textContent = '0%');
                document.querySelectorAll('[id^="grade_"]').forEach(span => span.textContent = 'F');
            }
        }

        // Mobile menu toggle
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
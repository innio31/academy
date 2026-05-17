<?php
// gos/admin/calculate_positions.php - Calculate Class and Subject Positions
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /gos/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$admin_name = $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? 'Administrator';
$admin_role = $_SESSION['admin_role'] ?? 'admin';

// Get current session and term from settings
$stmt = $pdo->prepare("SELECT session, term FROM report_card_settings WHERE school_id = ? GROUP BY session, term ORDER BY session DESC, term DESC LIMIT 1");
$stmt->execute([$school_id]);
$latest = $stmt->fetch();

$current_session = $latest ? $latest['session'] : date('Y') . '/' . (date('Y') + 1);
$current_term = $latest ? $latest['term'] : 'First';

// Get all classes for this school
$stmt = $pdo->prepare("SELECT DISTINCT class FROM students WHERE school_id = ? AND status = 'active' ORDER BY class");
$stmt->execute([$school_id]);
$classes = $stmt->fetchAll(PDO::FETCH_COLUMN);

$selected_class = $_POST['class'] ?? $_GET['class'] ?? '';
$session = $_POST['session'] ?? $_GET['session'] ?? $current_session;
$term = $_POST['term'] ?? $_GET['term'] ?? $current_term;
$message = '';
$message_type = '';

// Calculate positions when form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calculate_positions'])) {
    $selected_class = $_POST['class'];
    $session = $_POST['session'];
    $term = $_POST['term'];
    
    if (empty($selected_class)) {
        $message = "Please select a class to calculate positions.";
        $message_type = "error";
    } else {
        try {
            // Get all students in the class
            $stmt = $pdo->prepare("
                SELECT id, full_name, admission_number 
                FROM students 
                WHERE class = ? AND school_id = ? AND status = 'active'
            ");
            $stmt->execute([$selected_class, $school_id]);
            $students = $stmt->fetchAll();
            
            if (empty($students)) {
                $message = "No active students found in $selected_class.";
                $message_type = "warning";
            } else {
                // Calculate subject positions first
                $subject_positions_calculated = calculateSubjectPositions($pdo, $selected_class, $session, $term, $school_id);
                
                // Calculate class positions
                $class_positions_calculated = calculateClassPositions($pdo, $selected_class, $session, $term, $school_id);
                
                if ($subject_positions_calculated && $class_positions_calculated) {
                    $message = "Positions calculated successfully for $selected_class!";
                    $message_type = "success";
                    
                    // Log activity
                    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, user_type, activity, school_id) VALUES (?, 'admin', ?, ?)");
                    $stmt->execute([$_SESSION['admin_id'] ?? $_SESSION['user_id'], "Calculated positions for $selected_class - $session $term", $school_id]);
                } else {
                    $message = "Error calculating positions. Please check if scores have been entered.";
                    $message_type = "error";
                }
            }
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "error";
            error_log("Position calculation error: " . $e->getMessage());
        }
    }
}

// Function to calculate subject positions
function calculateSubjectPositions($pdo, $class, $session, $term, $school_id) {
    // Get all subjects for this class
    $stmt = $pdo->prepare("
        SELECT DISTINCT ss.subject_id, sub.subject_name 
        FROM student_scores ss
        JOIN subjects sub ON ss.subject_id = sub.id
        JOIN students s ON ss.student_id = s.id
        WHERE s.class = ? AND s.school_id = ? AND ss.session = ? AND ss.term = ?
    ");
    $stmt->execute([$class, $school_id, $session, $term]);
    $subjects = $stmt->fetchAll();
    
    if (empty($subjects)) {
        return false;
    }
    
    $success = true;
    
    foreach ($subjects as $subject) {
        // Get all students with scores for this subject
        $stmt = $pdo->prepare("
            SELECT s.id as student_id, s.full_name, ss.percentage
            FROM student_scores ss
            JOIN students s ON ss.student_id = s.id
            WHERE s.class = ? AND s.school_id = ? 
            AND ss.subject_id = ? AND ss.session = ? AND ss.term = ?
            AND ss.percentage IS NOT NULL AND ss.percentage > 0
            ORDER BY ss.percentage DESC
        ");
        $stmt->execute([$class, $school_id, $subject['subject_id'], $session, $term]);
        $student_scores = $stmt->fetchAll();
        
        if (!empty($student_scores)) {
            // Assign positions
            $position = 1;
            $prev_percentage = null;
            
            foreach ($student_scores as $index => $student) {
                // Handle ties (same percentage gets same position)
                if ($prev_percentage !== null && $student['percentage'] == $prev_percentage) {
                    $current_position = $position;
                } else {
                    $current_position = $index + 1;
                    $position = $current_position;
                }
                
                // Update or insert position
                $checkStmt = $pdo->prepare("
                    SELECT id FROM student_subject_positions 
                    WHERE student_id = ? AND subject_id = ? AND session = ? AND term = ?
                ");
                $checkStmt->execute([$student['student_id'], $subject['subject_id'], $session, $term]);
                
                if ($checkStmt->fetch()) {
                    $updateStmt = $pdo->prepare("
                        UPDATE student_subject_positions 
                        SET subject_position = ?, updated_at = NOW()
                        WHERE student_id = ? AND subject_id = ? AND session = ? AND term = ?
                    ");
                    $updateStmt->execute([$current_position, $student['student_id'], $subject['subject_id'], $session, $term]);
                } else {
                    $insertStmt = $pdo->prepare("
                        INSERT INTO student_subject_positions 
                        (student_id, subject_id, session, term, subject_position, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $insertStmt->execute([$student['student_id'], $subject['subject_id'], $session, $term, $current_position]);
                }
                
                $prev_percentage = $student['percentage'];
            }
        }
    }
    
    return $success;
}

// Function to calculate class positions
function calculateClassPositions($pdo, $class, $session, $term, $school_id) {
    // Get all students with their total scores and averages
    $stmt = $pdo->prepare("
        SELECT s.id as student_id, s.full_name, s.admission_number,
               COALESCE(SUM(ss.total_score), 0) as total_score,
               COALESCE(AVG(ss.percentage), 0) as average
        FROM students s
        LEFT JOIN student_scores ss ON s.id = ss.student_id AND ss.session = ? AND ss.term = ?
        WHERE s.class = ? AND s.school_id = ? AND s.status = 'active'
        GROUP BY s.id
        ORDER BY average DESC, total_score DESC
    ");
    $stmt->execute([$session, $term, $class, $school_id]);
    $students = $stmt->fetchAll();
    
    if (empty($students)) {
        return false;
    }
    
    // Calculate class statistics (highest/lowest average)
    $averages = array_column($students, 'average');
    $highest_average = !empty($averages) ? max($averages) : 0;
    $lowest_average = !empty($averages) ? min($averages) : 0;
    
    // Assign positions
    $position = 1;
    $prev_average = null;
    
    foreach ($students as $index => $student) {
        // Handle ties
        if ($prev_average !== null && $student['average'] == $prev_average) {
            $current_position = $position;
        } else {
            $current_position = $index + 1;
            $position = $current_position;
        }
        
        // Determine promoted to (for promotion logic)
        $promoted_to = null;
        if ($current_position <= 3) {
            // Top 3 students get promotion
            $promoted_to = getNextClass($class);
        }
        
        // Update or insert position
        $checkStmt = $pdo->prepare("
            SELECT id FROM student_positions 
            WHERE student_id = ? AND session = ? AND term = ?
        ");
        $checkStmt->execute([$student['student_id'], $session, $term]);
        
        if ($checkStmt->fetch()) {
            $updateStmt = $pdo->prepare("
                UPDATE student_positions 
                SET class_position = ?, total_marks = ?, average = ?, promoted_to = ?, updated_at = NOW()
                WHERE student_id = ? AND session = ? AND term = ?
            ");
            $updateStmt->execute([
                $current_position, 
                $student['total_score'], 
                round($student['average'], 2), 
                $promoted_to,
                $student['student_id'], 
                $session, 
                $term
            ]);
        } else {
            $insertStmt = $pdo->prepare("
                INSERT INTO student_positions 
                (student_id, session, term, class_position, total_marks, average, promoted_to, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $insertStmt->execute([
                $student['student_id'], 
                $session, 
                $term, 
                $current_position, 
                $student['total_score'], 
                round($student['average'], 2), 
                $promoted_to
            ]);
        }
        
        // Also update student_scores table with subject positions
        $updateScoreStmt = $pdo->prepare("
            UPDATE student_scores 
            SET subject_position = (
                SELECT subject_position FROM student_subject_positions 
                WHERE student_id = ? AND subject_id = student_scores.subject_id 
                AND session = ? AND term = ?
            )
            WHERE student_id = ? AND session = ? AND term = ?
        ");
        $updateScoreStmt->execute([$student['student_id'], $session, $term, $student['student_id'], $session, $term]);
        
        $prev_average = $student['average'];
    }
    
    // Update student_scores with class positions
    $updateClassStmt = $pdo->prepare("
        UPDATE student_scores ss
        JOIN student_positions sp ON ss.student_id = sp.student_id 
            AND ss.session = sp.session AND ss.term = sp.term
        SET ss.class_position = sp.class_position
        WHERE ss.session = ? AND ss.term = ? AND ss.student_id IN (
            SELECT student_id FROM student_positions WHERE session = ? AND term = ?
        )
    ");
    $updateClassStmt->execute([$session, $term, $session, $term]);
    
    return true;
}

// Helper function to get next class for promotion
function getNextClass($current_class) {
    $class_order = [
        'Nursery 1' => 'Nursery 2',
        'Nursery 2' => 'Kindergarten',
        'Kindergarten' => 'Basic 1',
        'Basic 1' => 'Basic 2',
        'Basic 2' => 'Basic 3',
        'Basic 3' => 'Basic 4',
        'Basic 4' => 'Basic 5',
        'Basic 5' => 'Basic 6',
        'Basic 6' => 'JSS 1',
        'JSS 1' => 'JSS 2',
        'JSS 2' => 'JSS 3',
        'JSS 3' => 'SS 1',
        'SS 1' => 'SS 2',
        'SS 2' => 'SS 3',
        'SS 3' => 'Graduated'
    ];
    
    return $class_order[$current_class] ?? null;
}

// Get current position data for display
$position_data = [];
$subject_positions = [];
$class_stats = [];

if ($selected_class && $session && $term) {
    // Get class positions
    $stmt = $pdo->prepare("
        SELECT s.id, s.full_name, s.admission_number, 
               sp.class_position, sp.total_marks, sp.average, sp.promoted_to
        FROM students s
        JOIN student_positions sp ON s.id = sp.student_id
        WHERE s.class = ? AND s.school_id = ? AND sp.session = ? AND sp.term = ?
        ORDER BY sp.class_position
    ");
    $stmt->execute([$selected_class, $school_id, $session, $term]);
    $position_data = $stmt->fetchAll();
    
    // Get subject positions for a sample student (first one)
    if (!empty($position_data)) {
        $first_student = $position_data[0];
        $stmt = $pdo->prepare("
            SELECT sub.subject_name, ssp.subject_position, ss.percentage
            FROM student_subject_positions ssp
            JOIN subjects sub ON ssp.subject_id = sub.id
            JOIN student_scores ss ON ss.student_id = ssp.student_id 
                AND ss.subject_id = ssp.subject_id 
                AND ss.session = ssp.session AND ss.term = ssp.term
            WHERE ssp.student_id = ? AND ssp.session = ? AND ssp.term = ?
            ORDER BY ssp.subject_position
        ");
        $stmt->execute([$first_student['id'], $session, $term]);
        $subject_positions = $stmt->fetchAll();
    }
    
    // Get class statistics
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_students,
            MAX(average) as highest_average,
            MIN(average) as lowest_average,
            AVG(average) as class_average
        FROM student_positions sp
        JOIN students s ON sp.student_id = s.id
        WHERE s.class = ? AND s.school_id = ? AND sp.session = ? AND sp.term = ?
    ");
    $stmt->execute([$selected_class, $school_id, $session, $term]);
    $class_stats = $stmt->fetch();
}

// Get all available sessions and terms for this school
$stmt = $pdo->prepare("
    SELECT DISTINCT session, term FROM report_card_settings 
    WHERE school_id = ? 
    ORDER BY session DESC, 
    CASE term WHEN 'Third' THEN 3 WHEN 'Second' THEN 2 WHEN 'First' THEN 1 END DESC
");
$stmt->execute([$school_id]);
$available_periods = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Calculate Positions</title>

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
            --sidebar-width: 260px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
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
            background: linear-gradient(180deg, var(--primary-color), #1a2a3a);
            color: white;
            padding: 20px 0;
            z-index: 100;
            overflow-y: auto;
            transform: translateX(-100%);
        }
        .sidebar.active { transform: translateX(0); }

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
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
            margin: 0 15px 20px;
        }
        .nav-links { list-style: none; padding: 0 15px; }
        .nav-links li { margin-bottom: 5px; }
        .nav-links a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            border-radius: 8px;
        }
        .nav-links a:hover, .nav-links a.active { background: rgba(255,255,255,0.2); }

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
        .header-title h1 { color: var(--primary-color); font-size: 1.8rem; margin-bottom: 10px; }
        .logout-btn {
            background: var(--danger-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
        }

        .card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .card h2 {
            color: var(--primary-color);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
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
            color: #555;
        }
        .form-control, .form-select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
        }
        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            background: var(--info-color);
            color: white;
        }
        .btn-primary { background: var(--primary-color); color: white; }
        .btn-success { background: var(--success-color); color: white; }
        .btn-warning { background: var(--warning-color); color: white; }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success { background: #d5f4e6; color: #155724; border-left: 4px solid var(--success-color); }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid var(--danger-color); }
        .alert-warning { background: #fff3cd; color: #856404; border-left: 4px solid var(--warning-color); }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        .data-table th, .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .data-table th {
            background: var(--primary-color);
            color: white;
            font-weight: 600;
        }
        .data-table tr:hover { background: #f9f9f9; }
        .data-table tr:first-child { background: #fff8e1; font-weight: bold; }

        .position-1 { background: #fff8e1; }
        .position-2 { background: #f3e5f5; }
        .position-3 { background: #e8f5e9; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .stat-box {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        .stat-value { font-size: 2rem; font-weight: 700; color: var(--primary-color); }
        .stat-label { color: #666; font-size: 0.85rem; }

        @media (min-width: 769px) {
            .sidebar { transform: translateX(0); }
            .main-content { margin-left: var(--sidebar-width); }
            .mobile-menu-btn { display: none; }
        }
        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
            .data-table { font-size: 0.85rem; }
            .data-table th, .data-table td { padding: 8px; }
        }
    </style>
</head>
<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
            <div class="logo-text"><h3><?php echo htmlspecialchars($school_name); ?></h3><p>Admin Panel</p></div>
        </div>
        <div class="admin-info">
            <h4><?php echo htmlspecialchars($admin_name); ?></h4>
            <p><?php echo ucfirst($admin_role); ?></p>
        </div>
        <ul class="nav-links">
           <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
			<li><a href="report_card_dashboard.php"><i class="fas fa-file-contract"></i> Report Cards</a></li>
            <li><a href="report_card_settings.php"><i class="fas fa-users"></i> Settings</a></li>
            <li><a href="enter_scores.php"><i class="fas fa-chalkboard-teacher"></i> Enter Scores</a></li>
            <li><a href="enter_comments.php"><i class="fas fa-book"></i> Add Comments</a></li>
            <li><a href="calculate_positions.php"  class="active"><i class="fas fa-file-alt"></i> Calculate</a></li>
            <li><a href="report_cards.php"><i class="fas fa-file-contract"></i> Generate Report Cards</a></li>
            <li><a href="/gos/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-chart-bar"></i> Calculate Positions</h1>
                <p>Calculate class rankings and subject positions for report cards</p>
            </div>
            <button class="logout-btn" onclick="window.location.href='/gos/logout.php'">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'error' ? 'exclamation-triangle' : 'exclamation-circle'); ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Calculation Form -->
        <div class="card">
            <h2><i class="fas fa-calculator"></i> Calculate Positions</h2>
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="class">Select Class *</label>
                        <select name="class" id="class" class="form-select" required>
                            <option value="">-- Select Class --</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo htmlspecialchars($class); ?>" <?php echo $selected_class == $class ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="session">Session</label>
                        <input type="text" name="session" id="session" class="form-control" value="<?php echo htmlspecialchars($session); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="term">Term</label>
                        <select name="term" id="term" class="form-select" required>
                            <option value="First" <?php echo $term == 'First' ? 'selected' : ''; ?>>First Term</option>
                            <option value="Second" <?php echo $term == 'Second' ? 'selected' : ''; ?>>Second Term</option>
                            <option value="Third" <?php echo $term == 'Third' ? 'selected' : ''; ?>>Third Term</option>
                        </select>
                    </div>
                    
                    <div class="form-group" style="display: flex; align-items: flex-end;">
                        <button type="submit" name="calculate_positions" class="btn btn-primary">
                            <i class="fas fa-calculator"></i> Calculate Positions
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <?php if (!empty($position_data)): ?>
            <!-- Class Statistics -->
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-value"><?php echo $class_stats['total_students'] ?? 0; ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?php echo number_format($class_stats['highest_average'] ?? 0, 1); ?>%</div>
                    <div class="stat-label">Highest Average</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?php echo number_format($class_stats['lowest_average'] ?? 0, 1); ?>%</div>
                    <div class="stat-label">Lowest Average</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?php echo number_format($class_stats['class_average'] ?? 0, 1); ?>%</div>
                    <div class="stat-label">Class Average</div>
                </div>
            </div>

            <!-- Class Positions Table -->
            <div class="card">
                <h2><i class="fas fa-trophy"></i> Class Positions - <?php echo htmlspecialchars($selected_class); ?></h2>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Position</th>
                                <th>Student Name</th>
                                <th>Admission No</th>
                                <th>Total Score</th>
                                <th>Average (%)</th>
                                <th>Promoted To</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($position_data as $pos): 
                                $row_class = '';
                                if ($pos['class_position'] == 1) $row_class = 'position-1';
                                elseif ($pos['class_position'] == 2) $row_class = 'position-2';
                                elseif ($pos['class_position'] == 3) $row_class = 'position-3';
                            ?>
                                <tr> class="<?php echo $row_class; ?>">
                                    <td><strong><?php echo ordinal($pos['class_position']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($pos['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($pos['admission_number']); ?></td>
                                    <td><?php echo number_format($pos['total_marks'], 1); ?></td>
                                    <td><?php echo number_format($pos['average'], 1); ?>%</strong></td>
                                    <td><?php echo $pos['promoted_to'] ? htmlspecialchars($pos['promoted_to']) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Subject Positions Sample -->
            <?php if (!empty($subject_positions)): ?>
                <div class="card">
                    <h2><i class="fas fa-book"></i> Subject Positions - <?php echo htmlspecialchars($position_data[0]['full_name']); ?></h2>
                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr><th>Subject</th><th>Score (%)</th><th>Position in Class</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($subject_positions as $sub): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($sub['subject_name']); ?></td>
                                        <td><?php echo number_format($sub['percentage'], 1); ?>%</strong></td>
                                        <td><strong><?php echo ordinal($sub['subject_position']); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Action Buttons -->
            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                <a href="report_cards.php?class=<?php echo urlencode($selected_class); ?>&session=<?php echo urlencode($session); ?>&term=<?php echo urlencode($term); ?>" class="btn btn-success">
                    <i class="fas fa-file-pdf"></i> Generate Report Cards
                </a>
                <button onclick="window.print()" class="btn btn-warning">
                    <i class="fas fa-print"></i> Print Positions
                </button>
            </div>
        <?php elseif ($selected_class && $session && $term && empty($position_data)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-info-circle"></i>
                No position data available for <?php echo htmlspecialchars($selected_class); ?> - <?php echo $session; ?> <?php echo $term; ?> Term.
                Please ensure scores have been entered and click "Calculate Positions".
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Helper function for ordinal numbers
        function ordinal(n) {
            const s = ["th", "st", "nd", "rd"];
            const v = n % 100;
            return n + (s[(v - 20) % 10] || s[v] || s[0]);
        }

        // Mobile menu toggle
        const mobileBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        if(mobileBtn) mobileBtn.onclick = () => sidebar.classList.toggle('active');
        
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768 && sidebar && mobileBtn) {
                if (!sidebar.contains(e.target) && !mobileBtn.contains(e.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>

<?php
// Helper function for ordinal numbers in PHP
function ordinal($number) {
    if (!is_numeric($number)) return $number;
    
    $ends = array('th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th');
    if ((($number % 100) >= 11) && (($number % 100) <= 13))
        return $number . 'th';
    else
        return $number . $ends[$number % 10];
}
?>
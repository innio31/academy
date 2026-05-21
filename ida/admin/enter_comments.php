<?php
// ida/admin/enter_comments.php - Enter Comments & Traits
session_start();

// Check if admin is logged in (support both session styles)
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /ida/login.php");
    exit();
}

// Get admin info
if (isset($_SESSION['admin_id'])) {
    $admin_id = $_SESSION['admin_id'];
    $admin_name = $_SESSION['admin_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'super_admin';
} else {
    $admin_id = $_SESSION['user_id'];
    $admin_name = $_SESSION['user_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;

// --- Determine the most recent session and term from settings ---
$stmt = $pdo->prepare("SELECT session, term FROM report_card_settings WHERE school_id = ? ORDER BY session DESC, 
                       CASE term WHEN 'Third' THEN 3 WHEN 'Second' THEN 2 WHEN 'First' THEN 1 END DESC LIMIT 1");
$stmt->execute([$school_id]);
$most_recent = $stmt->fetch();

if ($most_recent) {
    $default_session = $most_recent['session'];
    $default_term = $most_recent['term'];
} else {
    $default_session = date('Y') . '/' . (date('Y') + 1);
    $default_term = 'First';
}

// Get classes for this school only
$stmt = $pdo->prepare("SELECT DISTINCT class FROM students WHERE school_id = ? AND status = 'active' ORDER BY class");
$stmt->execute([$school_id]);
$classes = $stmt->fetchAll();

$selected_class = $_POST['class'] ?? ($_GET['class'] ?? '');
$selected_student_id = $_POST['student_id'] ?? ($_GET['student_id'] ?? '');
$session = $_POST['session'] ?? $default_session;
$term = $_POST['term'] ?? $default_term;
$students = [];
$selected_student = null;

if ($selected_class) {
    $stmt = $pdo->prepare("SELECT id, full_name, admission_number FROM students WHERE class = ? AND school_id = ? AND status = 'active' ORDER BY full_name");
    $stmt->execute([$selected_class, $school_id]);
    $students = $stmt->fetchAll();

    if ($selected_student_id) {
        foreach ($students as $student) {
            if ($student['id'] == $selected_student_id) {
                $selected_student = $student;
                break;
            }
        }
    }
}

// Get class teacher and principal names (if already saved)
$class_teacher_name = '';
$principal_name = '';
if ($selected_class) {
    $stmt = $pdo->prepare("SELECT DISTINCT class_teachers_name, principals_name FROM student_comments 
                          WHERE student_id IN (SELECT id FROM students WHERE class = ? AND school_id = ?) 
                          AND session = ? AND term = ? LIMIT 1");
    $stmt->execute([$selected_class, $school_id, $session, $term]);
    $existing_names = $stmt->fetch();
    if ($existing_names) {
        $class_teacher_name = $existing_names['class_teachers_name'] ?? '';
        $principal_name = $existing_names['principals_name'] ?? '';
    }
}

// Handle form submission
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_comments'])) {
    $session = $_POST['session'];
    $term = $_POST['term'];
    $class_teacher_name = $_POST['class_teacher_name'] ?? '';
    $principal_name = $_POST['principal_name'] ?? '';

    if ($selected_student_id) {
        try {
            // Save comments for selected student
            $teachers_comment = $_POST['teachers_comment'] ?? '';
            $principals_comment = $_POST['principals_comment'] ?? '';
            $days_present = $_POST['days_present'] ?? 0;
            $days_absent = $_POST['days_absent'] ?? 0;

            // Check if comments already exist
            $stmt = $pdo->prepare("SELECT id FROM student_comments WHERE student_id = ? AND session = ? AND term = ? AND school_id = ?");
            $stmt->execute([$selected_student_id, $session, $term, $school_id]);

            if ($stmt->fetch()) {
                // Update existing
                $stmt = $pdo->prepare("UPDATE student_comments SET 
                    teachers_comment = ?, principals_comment = ?, class_teachers_name = ?, principals_name = ?,
                    days_present = ?, days_absent = ?, updated_at = NOW()
                    WHERE student_id = ? AND session = ? AND term = ? AND school_id = ?");
                $stmt->execute([
                    $teachers_comment,
                    $principals_comment,
                    $class_teacher_name,
                    $principal_name,
                    $days_present,
                    $days_absent,
                    $selected_student_id,
                    $session,
                    $term,
                    $school_id
                ]);
            } else {
                // Insert new
                $stmt = $pdo->prepare("INSERT INTO student_comments 
                    (student_id, session, term, teachers_comment, principals_comment, 
                     class_teachers_name, principals_name, days_present, days_absent, school_id, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $stmt->execute([
                    $selected_student_id,
                    $session,
                    $term,
                    $teachers_comment,
                    $principals_comment,
                    $class_teacher_name,
                    $principal_name,
                    $days_present,
                    $days_absent,
                    $school_id
                ]);
            }

            // Save affective traits
            if (isset($_POST['affective'])) {
                $affective_data = $_POST['affective'];

                $stmt = $pdo->prepare("SELECT id FROM affective_traits WHERE student_id = ? AND session = ? AND term = ? AND school_id = ?");
                $stmt->execute([$selected_student_id, $session, $term, $school_id]);

                if ($stmt->fetch()) {
                    $stmt = $pdo->prepare("UPDATE affective_traits SET 
                        punctuality = ?, attendance = ?, politeness = ?, honesty = ?, 
                        neatness = ?, reliability = ?, relationship = ?, self_control = ?, updated_at = NOW()
                        WHERE student_id = ? AND session = ? AND term = ? AND school_id = ?");
                    $stmt->execute([
                        $affective_data['punctuality'] ?? '',
                        $affective_data['attendance'] ?? '',
                        $affective_data['politeness'] ?? '',
                        $affective_data['honesty'] ?? '',
                        $affective_data['neatness'] ?? '',
                        $affective_data['reliability'] ?? '',
                        $affective_data['relationship'] ?? '',
                        $affective_data['self_control'] ?? '',
                        $selected_student_id,
                        $session,
                        $term,
                        $school_id
                    ]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO affective_traits 
                        (student_id, session, term, punctuality, attendance, politeness, honesty, 
                         neatness, reliability, relationship, self_control, school_id, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([
                        $selected_student_id,
                        $session,
                        $term,
                        $affective_data['punctuality'] ?? '',
                        $affective_data['attendance'] ?? '',
                        $affective_data['politeness'] ?? '',
                        $affective_data['honesty'] ?? '',
                        $affective_data['neatness'] ?? '',
                        $affective_data['reliability'] ?? '',
                        $affective_data['relationship'] ?? '',
                        $affective_data['self_control'] ?? '',
                        $school_id
                    ]);
                }
            }

            // Save psychomotor skills
            if (isset($_POST['psychomotor'])) {
                $psychomotor_data = $_POST['psychomotor'];

                $stmt = $pdo->prepare("SELECT id FROM psychomotor_skills WHERE student_id = ? AND session = ? AND term = ? AND school_id = ?");
                $stmt->execute([$selected_student_id, $session, $term, $school_id]);

                if ($stmt->fetch()) {
                    $stmt = $pdo->prepare("UPDATE psychomotor_skills SET 
                        handwriting = ?, verbal_fluency = ?, sports = ?, handling_tools = ?, 
                        drawing_painting = ?, musical_skills = ?, updated_at = NOW()
                        WHERE student_id = ? AND session = ? AND term = ? AND school_id = ?");
                    $stmt->execute([
                        $psychomotor_data['handwriting'] ?? '',
                        $psychomotor_data['verbal_fluency'] ?? '',
                        $psychomotor_data['sports'] ?? '',
                        $psychomotor_data['handling_tools'] ?? '',
                        $psychomotor_data['drawing_painting'] ?? '',
                        $psychomotor_data['musical_skills'] ?? '',
                        $selected_student_id,
                        $session,
                        $term,
                        $school_id
                    ]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO psychomotor_skills 
                        (student_id, session, term, handwriting, verbal_fluency, sports, 
                         handling_tools, drawing_painting, musical_skills, school_id, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([
                        $selected_student_id,
                        $session,
                        $term,
                        $psychomotor_data['handwriting'] ?? '',
                        $psychomotor_data['verbal_fluency'] ?? '',
                        $psychomotor_data['sports'] ?? '',
                        $psychomotor_data['handling_tools'] ?? '',
                        $psychomotor_data['drawing_painting'] ?? '',
                        $psychomotor_data['musical_skills'] ?? '',
                        $school_id
                    ]);
                }
            }

            $message = "Successfully saved comments and traits for " . ($selected_student ? $selected_student['full_name'] : 'student') . "!";
            $message_type = "success";
        } catch (Exception $e) {
            $message = "Error saving comments: " . $e->getMessage();
            $message_type = "error";
            error_log("Error saving comments for student $selected_student_id: " . $e->getMessage());
        }
    } else {
        $message = "Please select a student first!";
        $message_type = "warning";
    }
}

// Helper functions to get existing data
function getStudentComments($pdo, $student_id, $session, $term, $school_id)
{
    $stmt = $pdo->prepare("SELECT * FROM student_comments WHERE student_id = ? AND session = ? AND term = ? AND school_id = ?");
    $stmt->execute([$student_id, $session, $term, $school_id]);
    return $stmt->fetch();
}

function getAffectiveTraits($pdo, $student_id, $session, $term, $school_id)
{
    $stmt = $pdo->prepare("SELECT * FROM affective_traits WHERE student_id = ? AND session = ? AND term = ? AND school_id = ?");
    $stmt->execute([$student_id, $session, $term, $school_id]);
    return $stmt->fetch();
}

function getPsychomotorSkills($pdo, $student_id, $session, $term, $school_id)
{
    $stmt = $pdo->prepare("SELECT * FROM psychomotor_skills WHERE student_id = ? AND session = ? AND term = ? AND school_id = ?");
    $stmt->execute([$student_id, $session, $term, $school_id]);
    return $stmt->fetch();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Enter Comments & Traits</title>

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
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1rem;
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
        }

        .form-control,
        .form-select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem;
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        .traits-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .trait-item {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }

        .trait-item label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 0.85rem;
        }

        .rating-select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 6px;
        }

        .student-info {
            background: #e8f4fc;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid var(--primary-color);
        }

        .attendance-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .attendance-item {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }

        .attendance-summary {
            margin-top: 15px;
            padding: 10px;
            background: #e8f5e9;
            border-radius: 8px;
            text-align: center;
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

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .alert {
            padding: 12px 15px;
            border-radius: 8px;
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

            .traits-grid {
                grid-template-columns: 1fr;
            }

            .attendance-grid {
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
            <li><a href="report_card_dashboard.php"><i class="fas fa-file-contract"></i> Report Cards</a></li>
            <li><a href="report_card_settings.php"><i class="fas fa-users"></i> Settings</a></li>
            <li><a href="enter_scores.php"><i class="fas fa-chalkboard-teacher"></i> Enter Scores</a></li>
            <li><a href="enter_comments.php" class="active"><i class="fas fa-book"></i> Add Comments</a></li>
            <li><a href="calculate_positions.php"><i class="fas fa-file-alt"></i> Calculate</a></li>
            <li><a href="report_cards.php"><i class="fas fa-file-contract"></i> Generate Report Cards</a></li>
            <li><a href="/ida/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-comment-dots"></i> Enter Comments & Traits</h1>
                <p>Add comments, attendance, and behavioral ratings for students</p>
            </div>
            <button class="logout-btn" onclick="window.location.href='/ida/logout.php'">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </div>

        <div class="settings-card">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <h2><i class="fas fa-user-edit"></i> Student Comments & Traits</h2>

            <form method="POST" id="commentsForm">
                <!-- Selection Section -->
                <div class="form-section">
                    <h3><i class="fas fa-search"></i> Select Student</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Select Class:</label>
                            <select name="class" id="class" class="form-select" onchange="this.form.submit()">
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo htmlspecialchars($class['class']); ?>" <?php echo $selected_class == $class['class'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($selected_class): ?>
                            <div class="form-group">
                                <label>Select Student:</label>
                                <select name="student_id" id="student_id" class="form-select" onchange="this.form.submit()">
                                    <option value="">Select Student</option>
                                    <?php foreach ($students as $student): ?>
                                        <option value="<?php echo $student['id']; ?>" <?php echo $selected_student_id == $student['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($student['full_name']); ?> (<?php echo htmlspecialchars($student['admission_number']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label>Academic Session:</label>
                            <input type="text" name="session" class="form-control" value="<?php echo htmlspecialchars($session); ?>" required>
                        </div>

                        <div class="form-group">
                            <label>Term:</label>
                            <select name="term" class="form-select" required>
                                <option value="First" <?php echo $term == 'First' ? 'selected' : ''; ?>>First Term</option>
                                <option value="Second" <?php echo $term == 'Second' ? 'selected' : ''; ?>>Second Term</option>
                                <option value="Third" <?php echo $term == 'Third' ? 'selected' : ''; ?>>Third Term</option>
                            </select>
                        </div>
                    </div>
                </div>

                <?php if ($selected_student):
                    $existing_comments = getStudentComments($pdo, $selected_student_id, $session, $term, $school_id);
                    $existing_affective = getAffectiveTraits($pdo, $selected_student_id, $session, $term, $school_id);
                    $existing_psychomotor = getPsychomotorSkills($pdo, $selected_student_id, $session, $term, $school_id);
                ?>
                    <!-- Student Information -->
                    <div class="student-info">
                        <h3><?php echo htmlspecialchars($selected_student['full_name']); ?></h3>
                        <p><strong>Admission Number:</strong> <?php echo htmlspecialchars($selected_student['admission_number']); ?></p>
                        <p><strong>Class:</strong> <?php echo htmlspecialchars($selected_class); ?> | <strong>Session:</strong> <?php echo htmlspecialchars($session); ?> | <strong>Term:</strong> <?php echo htmlspecialchars($term); ?></p>
                    </div>

                    <!-- Staff Names Section -->
                    <div class="form-section">
                        <h3><i class="fas fa-chalkboard-teacher"></i> Staff Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Class Teacher's Name:</label>
                                <input type="text" name="class_teacher_name" class="form-control"
                                    value="<?php echo htmlspecialchars($class_teacher_name); ?>"
                                    placeholder="Enter Class Teacher's Name" required>
                            </div>
                            <div class="form-group">
                                <label>Principal's Name:</label>
                                <input type="text" name="principal_name" class="form-control"
                                    value="<?php echo htmlspecialchars($principal_name); ?>"
                                    placeholder="Enter Principal's Name" required>
                            </div>
                        </div>
                    </div>

                    <!-- Attendance Section -->
                    <div class="form-section">
                        <h3><i class="fas fa-calendar-check"></i> Attendance</h3>
                        <div class="attendance-grid">
                            <div class="attendance-item">
                                <label>Days Present:</label>
                                <input type="number" name="days_present" id="days_present" class="form-control"
                                    value="<?php echo $existing_comments['days_present'] ?? 0; ?>" min="0" max="365" required>
                            </div>
                            <div class="attendance-item">
                                <label>Days Absent:</label>
                                <input type="number" name="days_absent" id="days_absent" class="form-control"
                                    value="<?php echo $existing_comments['days_absent'] ?? 0; ?>" min="0" max="365" required>
                            </div>
                        </div>
                        <?php
                        $days_present_val = $existing_comments['days_present'] ?? 0;
                        $days_absent_val = $existing_comments['days_absent'] ?? 0;
                        $total_days = $days_present_val + $days_absent_val;
                        $attendance_rate = $total_days > 0 ? round(($days_present_val / $total_days) * 100) : 0;
                        ?>
                        <div class="attendance-summary" id="attendance-summary">
                            Total Days: <?php echo $total_days; ?> | Attendance Rate: <?php echo $attendance_rate; ?>%
                        </div>
                    </div>

                    <!-- Comments Section -->
                    <div class="form-section">
                        <h3><i class="fas fa-comments"></i> Comments</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Teacher's Comment:</label>
                                <textarea name="teachers_comment" class="form-control" rows="4" placeholder="Enter teacher's comment..."><?php echo htmlspecialchars($existing_comments['teachers_comment'] ?? ''); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label>Principal's Comment:</label>
                                <textarea name="principals_comment" class="form-control" rows="4" placeholder="Enter principal's comment..."><?php echo htmlspecialchars($existing_comments['principals_comment'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Affective Traits -->
                    <div class="form-section">
                        <h3><i class="fas fa-heart"></i> Affective Traits (Rate A-E)</h3>
                        <div class="traits-grid">
                            <?php
                            $affective_traits = [
                                'punctuality' => 'Punctuality',
                                'attendance' => 'Attendance',
                                'politeness' => 'Politeness',
                                'honesty' => 'Honesty',
                                'neatness' => 'Neatness',
                                'reliability' => 'Reliability',
                                'relationship' => 'Relationship with Others',
                                'self_control' => 'Self Control'
                            ];
                            foreach ($affective_traits as $key => $label):
                            ?>
                                <div class="trait-item">
                                    <label><?php echo $label; ?>:</label>
                                    <select name="affective[<?php echo $key; ?>]" class="rating-select">
                                        <option value="">Select Grade</option>
                                        <option value="A" <?php echo ($existing_affective[$key] ?? '') == 'A' ? 'selected' : ''; ?>>A - Excellent</option>
                                        <option value="B" <?php echo ($existing_affective[$key] ?? '') == 'B' ? 'selected' : ''; ?>>B - Very Good</option>
                                        <option value="C" <?php echo ($existing_affective[$key] ?? '') == 'C' ? 'selected' : ''; ?>>C - Good</option>
                                        <option value="D" <?php echo ($existing_affective[$key] ?? '') == 'D' ? 'selected' : ''; ?>>D - Fair</option>
                                        <option value="E" <?php echo ($existing_affective[$key] ?? '') == 'E' ? 'selected' : ''; ?>>E - Poor</option>
                                    </select>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Psychomotor Skills -->
                    <div class="form-section">
                        <h3><i class="fas fa-running"></i> Psychomotor Skills (Rate A-E)</h3>
                        <div class="traits-grid">
                            <?php
                            $psychomotor_skills = [
                                'handwriting' => 'Handwriting',
                                'verbal_fluency' => 'Verbal Fluency',
                                'sports' => 'Sports',
                                'handling_tools' => 'Handling of Tools',
                                'drawing_painting' => 'Drawing & Painting',
                                'musical_skills' => 'Musical Skills'
                            ];
                            foreach ($psychomotor_skills as $key => $label):
                            ?>
                                <div class="trait-item">
                                    <label><?php echo $label; ?>:</label>
                                    <select name="psychomotor[<?php echo $key; ?>]" class="rating-select">
                                        <option value="">Select Grade</option>
                                        <option value="A" <?php echo ($existing_psychomotor[$key] ?? '') == 'A' ? 'selected' : ''; ?>>A - Excellent</option>
                                        <option value="B" <?php echo ($existing_psychomotor[$key] ?? '') == 'B' ? 'selected' : ''; ?>>B - Very Good</option>
                                        <option value="C" <?php echo ($existing_psychomotor[$key] ?? '') == 'C' ? 'selected' : ''; ?>>C - Good</option>
                                        <option value="D" <?php echo ($existing_psychomotor[$key] ?? '') == 'D' ? 'selected' : ''; ?>>D - Fair</option>
                                        <option value="E" <?php echo ($existing_psychomotor[$key] ?? '') == 'E' ? 'selected' : ''; ?>>E - Poor</option>
                                    </select>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div style="margin-top: 20px;">
                        <button type="submit" name="save_comments" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Comments & Traits
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <script>
        // Update attendance summary
        function updateAttendanceSummary() {
            const daysPresent = document.getElementById('days_present');
            const daysAbsent = document.getElementById('days_absent');
            if (daysPresent && daysAbsent) {
                const present = parseInt(daysPresent.value) || 0;
                const absent = parseInt(daysAbsent.value) || 0;
                const total = present + absent;
                const rate = total > 0 ? Math.round((present / total) * 100) : 0;
                const summaryDiv = document.getElementById('attendance-summary');
                if (summaryDiv) {
                    summaryDiv.innerHTML = `Total Days: ${total} | Attendance Rate: ${rate}%`;
                }
            }
        }

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

        document.addEventListener('DOMContentLoaded', function() {
            const daysPresent = document.getElementById('days_present');
            const daysAbsent = document.getElementById('days_absent');
            if (daysPresent) daysPresent.addEventListener('input', updateAttendanceSummary);
            if (daysAbsent) daysAbsent.addEventListener('input', updateAttendanceSummary);
        });
    </script>
</body>

</html>
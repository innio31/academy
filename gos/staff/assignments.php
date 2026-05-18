<?php
// gos/staff/assignments.php - Staff Assignments Management
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: /gos/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$staff_id = $_SESSION['user_id'];
$staff_name = $_SESSION['user_name'] ?? 'Staff Member';

// Get staff assigned subjects and classes
$stmt = $pdo->prepare("SELECT subject_id, subject_name FROM subjects s JOIN staff_subjects ss ON s.id = ss.subject_id WHERE ss.staff_id = ? AND ss.school_id = ?");
$stmt->execute([$staff_id, $school_id]);
$subjects = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT class FROM staff_classes WHERE staff_id = ? AND school_id = ?");
$stmt->execute([$staff_id, $school_id]);
$classes = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Handle assignment creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_assignment'])) {
    $title = trim($_POST['title']);
    $subject_id = intval($_POST['subject_id']);
    $class = trim($_POST['class']);
    $instructions = trim($_POST['instructions']);
    $deadline = $_POST['deadline'];
    $max_marks = intval($_POST['max_marks'] ?? 0);

    $stmt = $pdo->prepare("
        INSERT INTO assignments (title, subject_id, class, instructions, deadline, max_marks, staff_id, school_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$title, $subject_id, $class, $instructions, $deadline, $max_marks, $staff_id, $school_id]);

    $message = "Assignment created successfully!";
    $message_type = "success";
}

// Get assignments
$assignments = [];
if (!empty($classes)) {
    $placeholders = str_repeat('?,', count($classes) - 1) . '?';
    $stmt = $pdo->prepare("
        SELECT a.*, s.subject_name,
               (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id) as submissions_count
        FROM assignments a
        JOIN subjects s ON a.subject_id = s.id
        WHERE a.school_id = ? AND a.staff_id = ? AND a.class IN ($placeholders)
        ORDER BY a.created_at DESC
    ");
    $stmt->execute(array_merge([$school_id, $staff_id], $classes));
    $assignments = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Assignments</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
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
            background: #d4af7a;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .staff-info {
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
            padding: 15px 25px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.6rem;
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            border-bottom: 2px solid #ecf0f1;
            padding-bottom: 10px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 12px;
            margin-bottom: 5px;
            font-weight: 500;
            color: #555;
        }

        .form-control,
        .form-select {
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            width: 100%;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
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

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .data-table th {
            background: #f5f5f5;
            font-weight: 600;
        }

        .status-pending {
            background: #fff3cd;
            color: #f39c12;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            display: inline-block;
        }

        .status-graded {
            background: #d5f4e6;
            color: #27ae60;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            display: inline-block;
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

            .top-header {
                flex-direction: column;
            }

            .data-table {
                font-size: 12px;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>

    <div class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-chalkboard-teacher"></i></div>
            <div class="logo-text">
                <h3><?php echo htmlspecialchars($school_name); ?></h3>
                <p>Staff Portal</p>
            </div>
        </div>
        <div class="staff-info">
            <h4><?php echo htmlspecialchars($staff_name); ?></h4>
        </div>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-students.php"><i class="fas fa-users"></i> My Students</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="assignments.php" class="active"><i class="fas fa-tasks"></i> Assignments</a></li>
            <li><a href="/gos/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-tasks"></i> Assignments</h1>
            </div>
            <button class="btn" onclick="window.location.href='/gos/logout.php'"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>

        <?php if (isset($message)): ?>
            <div class="alert" style="background:#d5f4e6; padding:15px; border-radius:10px; margin-bottom:20px;"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Create Assignment -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-plus-circle"></i> Create New Assignment</h3>
            </div>
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group"><label>Title *</label><input type="text" name="title" class="form-control" required></div>
                    <div class="form-group"><label>Subject *</label><select name="subject_id" class="form-select" required><?php foreach ($subjects as $subject): ?><option value="<?php echo $subject['subject_id']; ?>"><?php echo htmlspecialchars($subject['subject_name']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Class *</label><select name="class" class="form-select" required><?php foreach ($classes as $class): ?><option value="<?php echo htmlspecialchars($class); ?>"><?php echo htmlspecialchars($class); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Deadline *</label><input type="datetime-local" name="deadline" class="form-control" required></div>
                    <div class="form-group"><label>Max Marks</label><input type="number" name="max_marks" class="form-control" value="100"></div>
                </div>
                <div class="form-group"><label>Instructions</label><textarea name="instructions" class="form-control" rows="3" placeholder="Enter assignment instructions..."></textarea></div>
                <button type="submit" name="create_assignment" class="btn btn-primary"><i class="fas fa-save"></i> Create Assignment</button>
            </form>
        </div>

        <!-- Assignments List -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> My Assignments</h3>
            </div>
            <?php if (empty($assignments)): ?>
                <p style="text-align:center; padding:30px; color:#999;">No assignments created yet.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Subject</th>
                            <th>Class</th>
                            <th>Deadline</th>
                            <th>Submissions</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignments as $assignment): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($assignment['title']); ?></strong></td>
                                <td><?php echo htmlspecialchars($assignment['subject_name']); ?></td>
                                <td><?php echo htmlspecialchars($assignment['class']); ?></td>
                                <td><?php echo date('M d, Y H:i', strtotime($assignment['deadline'])); ?></td>
                                <td><?php echo $assignment['submissions_count']; ?> submissions</td>
                                <td><a href="view-submissions.php?id=<?php echo $assignment['id']; ?>" class="btn btn-primary btn-sm"><i class="fas fa-eye"></i> View</a>
                                    <a href="edit-assignment.php?id=<?php echo $assignment['id']; ?>" class="btn btn-primary btn-sm"><i class="fas fa-edit"></i> Edit</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <script>
        document.getElementById('mobileMenuBtn').onclick = () => document.getElementById('sidebar').classList.toggle('active');
    </script>
</body>

</html>
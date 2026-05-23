<?php
// gos/staff/manage-exams.php - Staff Exam Management
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

// Initialize variables
$subject_ids = [];
$class_names = [];
$subjects = [];
$exams = [];
$message = null;
$message_type = null;

try {
    // Get the staff_id string from the staff table
    $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
    $stmt->execute([$staff_id, $school_id]);
    $staff_id_string = $stmt->fetchColumn();

    if (!$staff_id_string) {
        $message = "Staff record not found. Please contact administrator.";
        $message_type = "error";
    } else {
        // Get staff assigned subjects using the string staff_id
        $stmt = $pdo->prepare("SELECT subject_id FROM staff_subjects WHERE staff_id = ? AND school_id = ?");
        $stmt->execute([$staff_id_string, $school_id]);
        $subject_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Get staff assigned classes using the string staff_id
        $stmt = $pdo->prepare("SELECT class FROM staff_classes WHERE staff_id = ? AND school_id = ?");
        $stmt->execute([$staff_id_string, $school_id]);
        $class_names = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) {
    error_log("Staff data fetch error: " . $e->getMessage());
    $message = "An error occurred while loading your data.";
    $message_type = "error";
}

// Handle exam creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_exam'])) {
    $exam_name = trim($_POST['exam_name']);
    $class = trim($_POST['class']);
    $subject_id = intval($_POST['subject_id']);
    $duration_minutes = intval($_POST['duration_minutes']);
    $objective_count = intval($_POST['objective_count'] ?? 0);
    $subjective_count = intval($_POST['subjective_count'] ?? 0);
    $theory_count = intval($_POST['theory_count'] ?? 0);
    $exam_type = $_POST['exam_type'];
    $instructions = trim($_POST['instructions']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO exams (exam_name, class, subject_id, duration_minutes, objective_count, 
                              subjective_count, theory_count, exam_type, instructions, is_active, 
                              school_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $exam_name,
            $class,
            $subject_id,
            $duration_minutes,
            $objective_count,
            $subjective_count,
            $theory_count,
            $exam_type,
            $instructions,
            $is_active,
            $school_id
        ]);

        $message = "Exam created successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        error_log("Exam creation error: " . $e->getMessage());
        $message = "Failed to create exam: " . $e->getMessage();
        $message_type = "error";
    }
}

// Get subjects for dropdown (only after we have subject_ids)
if (!empty($subject_ids)) {
    try {
        $placeholders = str_repeat('?,', count($subject_ids) - 1) . '?';
        $stmt = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE id IN ($placeholders) ORDER BY subject_name");
        $stmt->execute($subject_ids);
        $subjects = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Subjects fetch error: " . $e->getMessage());
        $subjects = [];
    }
}

// Get exams created by this staff
if (!empty($subject_ids) && !empty($class_names)) {
    try {
        $subject_placeholders = str_repeat('?,', count($subject_ids) - 1) . '?';
        $class_placeholders = str_repeat('?,', count($class_names) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT e.*, s.subject_name 
            FROM exams e
            JOIN subjects s ON e.subject_id = s.id
            WHERE e.school_id = ? AND e.subject_id IN ($subject_placeholders) 
            AND e.class IN ($class_placeholders)
            ORDER BY e.created_at DESC
        ");
        $params = array_merge([$school_id], $subject_ids, $class_names);
        $stmt->execute($params);
        $exams = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Exams fetch error: " . $e->getMessage());
        $exams = [];
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Manage Exams</title>
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
            flex-wrap: wrap;
            gap: 15px;
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
            background: #27ae60;
            color: white;
        }

        .btn-sm {
            padding: 5px 12px;
            font-size: 12px;
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

        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
        }

        .status-active {
            background: #d5f4e6;
            color: #27ae60;
        }

        .status-inactive {
            background: #f8d7da;
            color: #e74c3c;
        }

        .alert-success {
            background: #d5f4e6;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            color: #27ae60;
        }

        .alert-error {
            background: #f8d7da;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            color: #e74c3c;
        }

        .empty-state {
            text-align: center;
            padding: 30px;
            color: #999;
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
                text-align: center;
            }

            .data-table {
                font-size: 12px;
            }

            .data-table th,
            .data-table td {
                padding: 8px;
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
            <li><a href="manage-exams.php" class="active"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="assignments.php"><i class="fas fa-tasks"></i> Assignments</a></li>
            <li><a href="../ida/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-file-alt"></i> Manage Exams</h1>
            </div>
            <button class="btn" onclick="window.location.href='../ida/logout.php'"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>

        <?php if (isset($message)): ?>
            <div class="alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Create Exam Form -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-plus-circle"></i> Create New Exam</h3>
            </div>
            <?php if (empty($class_names) || empty($subjects)): ?>
                <div class="empty-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>You need to be assigned to classes and subjects before creating exams.</p>
                    <p style="margin-top: 10px;">Please contact the administrator to assign you to classes and subjects.</p>
                </div>
            <?php else: ?>
                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Exam Name *</label>
                            <input type="text" name="exam_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Class *</label>
                            <select name="class" class="form-select" required>
                                <option value="">Select Class</option>
                                <?php foreach ($class_names as $class): ?>
                                    <option value="<?php echo htmlspecialchars($class); ?>"><?php echo htmlspecialchars($class); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Subject *</label>
                            <select name="subject_id" class="form-select" required>
                                <option value="">Select Subject</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['subject_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Exam Type</label>
                            <select name="exam_type" class="form-select">
                                <option value="objective">Objective</option>
                                <option value="subjective">Subjective</option>
                                <option value="theory">Theory</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Duration (minutes)</label>
                            <input type="number" name="duration_minutes" class="form-control" value="60" required>
                        </div>
                        <div class="form-group">
                            <label>Objective Questions</label>
                            <input type="number" name="objective_count" class="form-control" value="0">
                        </div>
                        <div class="form-group">
                            <label>Subjective Questions</label>
                            <input type="number" name="subjective_count" class="form-control" value="0">
                        </div>
                        <div class="form-group">
                            <label>Theory Questions</label>
                            <input type="number" name="theory_count" class="form-control" value="0">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Instructions</label>
                        <textarea name="instructions" class="form-control" rows="3" placeholder="Enter exam instructions..."></textarea>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="is_active" value="1" checked>
                            Active (Students can take this exam)
                        </label>
                    </div>
                    <button type="submit" name="create_exam" class="btn btn-primary">
                        <i class="fas fa-save"></i> Create Exam
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Exams List -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> My Exams</h3>
            </div>
            <?php if (empty($exams)): ?>
                <div class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <p>No exams created yet.</p>
                    <p style="margin-top: 10px;">Use the form above to create your first exam.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Exam Name</th>
                                <th>Class</th>
                                <th>Subject</th>
                                <th>Type</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($exams as $exam): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($exam['exam_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($exam['class']); ?></td>
                                    <td><?php echo htmlspecialchars($exam['subject_name']); ?></td>
                                    <td><?php echo ucfirst($exam['exam_type']); ?></td>
                                    <td><?php echo $exam['duration_minutes']; ?> min</td>
                                    <td><span class="status-badge status-<?php echo $exam['is_active'] ? 'active' : 'inactive'; ?>"><?php echo $exam['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                    <td>
                                        <a href="edit-exam.php?id=<?php echo $exam['id']; ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.getElementById('mobileMenuBtn').onclick = () => document.getElementById('sidebar').classList.toggle('active');

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768) {
                const sidebar = document.getElementById('sidebar');
                const menuBtn = document.getElementById('mobileMenuBtn');
                if (!sidebar.contains(e.target) && !menuBtn.contains(e.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });
    </script>
</body>

</html>
<?php
// ida/staff/view-results.php - Staff View Results
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: /ida/login.php");
    exit();
}
s
require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$staff_id = $_SESSION['user_id'];
$staff_name = $_SESSION['user_name'] ?? 'Staff Member';

// Initialize variables
$classes = [];
$exams = [];
$results = [];
$selected_class = '';
$selected_exam = '';

try {
    // Get the staff_id string from the staff table
    $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
    $stmt->execute([$staff_id, $school_id]);
    $staff_id_string = $stmt->fetchColumn();

    if (!$staff_id_string) {
        $error = "Staff record not found. Please contact administrator.";
    } else {
        // Get assigned classes using the string staff_id
        $stmt = $pdo->prepare("SELECT class FROM staff_classes WHERE staff_id = ? AND school_id = ?");
        $stmt->execute([$staff_id_string, $school_id]);
        $classes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) {
    error_log("Staff data fetch error: " . $e->getMessage());
    $error = "An error occurred while loading your data.";
}

// Get filters
$selected_class = $_GET['class'] ?? ($classes[0] ?? '');
$selected_exam = $_GET['exam'] ?? '';

// Get exams for this staff's classes
if (!empty($classes)) {
    try {
        $placeholders = str_repeat('?,', count($classes) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT id, exam_name, class FROM exams 
            WHERE school_id = ? AND class IN ($placeholders)
            ORDER BY created_at DESC
        ");
        $stmt->execute(array_merge([$school_id], $classes));
        $exams = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Exams fetch error: " . $e->getMessage());
        $exams = [];
    }
}

// Get results
if ($selected_class && !empty($selected_exam)) {
    try {
        $stmt = $pdo->prepare("
            SELECT r.*, s.full_name, s.admission_number
            FROM results r
            JOIN students s ON r.student_id = s.id
            WHERE r.school_id = ? AND r.exam_id = ? AND s.class = ?
            ORDER BY r.percentage DESC
        ");
        $stmt->execute([$school_id, $selected_exam, $selected_class]);
        $results = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Results fetch error: " . $e->getMessage());
        $results = [];
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - View Results</title>
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

        .filter-bar {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 12px;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .form-control,
        .form-select {
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            width: 200px;
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

        .grade-A {
            color: #27ae60;
            font-weight: bold;
        }

        .grade-B {
            color: #2ecc71;
            font-weight: bold;
        }

        .grade-C {
            color: #f39c12;
            font-weight: bold;
        }

        .grade-D {
            color: #e67e22;
            font-weight: bold;
        }

        .grade-F {
            color: #e74c3c;
            font-weight: bold;
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
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
            .filter-bar {
                flex-direction: column;
            }

            .form-control,
            .form-select {
                width: 100%;
            }

            .data-table {
                font-size: 12px;
            }

            .top-header {
                flex-direction: column;
                text-align: center;
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
            <li><a href="view-results.php" class="active"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="assignments.php"><i class="fas fa-tasks"></i> Assignments</a></li>
            <li><a href="../ida/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-chart-bar"></i> View Results</h1>
            </div>
            <button class="btn" onclick="window.location.href='../ida/logout.php'"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>

        <?php if (isset($error)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if (empty($classes)): ?>
            <div class="card">
                <div class="empty-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>You have not been assigned to any class.</p>
                    <p>Please contact the administrator to assign you to classes.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="filter-bar">
                <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
                    <div class="form-group">
                        <label>Class</label>
                        <select name="class" class="form-select" onchange="this.form.submit()">
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo htmlspecialchars($class); ?>" <?php echo $selected_class == $class ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Exam</label>
                        <select name="exam" class="form-select" onchange="this.form.submit()">
                            <option value="">Select Exam</option>
                            <?php foreach ($exams as $exam): ?>
                                <?php if ($exam['class'] == $selected_class): ?>
                                    <option value="<?php echo $exam['id']; ?>" <?php echo $selected_exam == $exam['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($exam['exam_name']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>

            <?php if ($selected_class && $selected_exam && !empty($results)): ?>
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-line"></i> Results Summary</h3>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>S/N</th>
                                    <th>Admission No</th>
                                    <th>Student Name</th>
                                    <th>Score</th>
                                    <th>Percentage</th>
                                    <th>Grade</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $sn = 1;
                                foreach ($results as $result):
                                    $grade_class = 'grade-' . ($result['grade'] ?? 'F');
                                ?>
                                    <tr>
                                        <td><?php echo $sn++; ?></td>
                                        <td><?php echo htmlspecialchars($result['admission_number']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($result['student_name']); ?></strong></td>
                                        <td><?php echo $result['total_score']; ?> / <?php echo $result['total_questions']; ?></td>
                                        <td><?php echo number_format($result['percentage'] ?? 0, 1); ?>%</td>
                                        <td class="<?php echo $grade_class; ?>"><?php echo $result['grade'] ?? 'N/A'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php elseif ($selected_class && $selected_exam): ?>
                <div class="card">
                    <div class="empty-state">
                        <i class="fas fa-folder-open"></i>
                        <p>No results found for this exam.</p>
                    </div>
                </div>
            <?php elseif ($selected_class): ?>
                <div class="card">
                    <div class="empty-state">
                        <i class="fas fa-info-circle"></i>
                        <p>Select an exam to view results.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="empty-state">
                        <i class="fas fa-info-circle"></i>
                        <p>Select a class to view results.</p>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
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
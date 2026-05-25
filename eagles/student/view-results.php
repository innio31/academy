<?php
// tbis/student/view-results.php - View Results
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: /tbis/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$student_id = $_SESSION['user_id'];

$exam_id = $_GET['exam_id'] ?? 0;

// Get all exams taken by student
$stmt = $pdo->prepare("
    SELECT r.*, e.exam_name, s.subject_name, e.exam_type, e.duration_minutes
    FROM results r
    JOIN exams e ON r.exam_id = e.id
    JOIN subjects s ON e.subject_id = s.id
    WHERE r.student_id = ? AND r.school_id = ?
    ORDER BY r.submitted_at DESC
");
$stmt->execute([$student_id, $school_id]);
$results = $stmt->fetchAll();

// Get specific exam details if selected
$exam_result = null;
if ($exam_id) {
    foreach ($results as $r) {
        if ($r['exam_id'] == $exam_id) {
            $exam_result = $r;
            break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - My Results</title>
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

        .student-info {
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
            border-bottom: 2px solid #ecf0f1;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        .result-summary {
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .result-score {
            font-size: 3rem;
            font-weight: 700;
            color: var(--primary-color);
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
            .top-header {
                flex-direction: column;
                gap: 15px;
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
            <div class="logo-icon"><i class="fas fa-user-graduate"></i></div>
            <div class="logo-text">
                <h3><?php echo htmlspecialchars($school_name); ?></h3>
                <p>Student Portal</p>
            </div>
        </div>
        <div class="student-info">
            <h4><?php echo $_SESSION['user_name'] ?? 'Student'; ?></h4>
        </div>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="take-exam.php"><i class="fas fa-file-alt"></i> Take Exam</a></li>
            <li><a href="view-results.php" class="active"><i class="fas fa-chart-bar"></i> My Results</a></li>
            <li><a href="assignments.php"><i class="fas fa-tasks"></i> Assignments</a></li>
            <li><a href="profile.php"><i class="fas fa-user-cog"></i> My Profile</a></li>
            <li><a href="../tbis/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-chart-bar"></i> My Results</h1>
            </div>
            <button class="btn" onclick="window.location.href='/gos/logout.php'"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>

        <?php if ($exam_result): ?>
            <div class="card">
                <div class="card-header">
                    <h3><?php echo htmlspecialchars($exam_result['exam_name']); ?> - Results</h3>
                </div>
                <div class="result-summary">
                    <div class="result-score"><?php echo number_format($exam_result['percentage'] ?? 0, 1); ?>%</div>
                    <div>Score: <?php echo $exam_result['total_score']; ?> / <?php echo $exam_result['total_questions']; ?></div>
                    <div>Grade: <span class="grade-<?php echo $exam_result['grade']; ?>"><?php echo $exam_result['grade']; ?></span></div>
                    <div>Date: <?php echo date('F j, Y g:i A', strtotime($exam_result['submitted_at'])); ?></div>
                </div>
                <a href="view-results.php" class="btn btn-primary" style="display: inline-block; margin-top: 10px;"><i class="fas fa-arrow-left"></i> Back to All Results</a>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-history"></i> All Exam Results</h3>
            </div>
            <?php if (empty($results)): ?>
                <p style="text-align:center; padding:30px; color:#999;">No results available yet. Take some exams to see your performance.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Exam Name</th>
                            <th>Subject</th>
                            <th>Score</th>
                            <th>Percentage</th>
                            <th>Grade</th>
                            <th>Date</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $result): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($result['exam_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($result['subject_name']); ?></td>
                                <td><?php echo $result['total_score']; ?> / <?php echo $result['total_questions']; ?></td>
                                <td><?php echo number_format($result['percentage'] ?? 0, 1); ?>%</td>
                                <td class="grade-<?php echo $result['grade']; ?>"><?php echo $result['grade']; ?></td>
                                <td><?php echo date('M d, Y', strtotime($result['submitted_at'])); ?></td>
                                <td><a href="view-results.php?exam_id=<?php echo $result['exam_id']; ?>" class="btn btn-primary btn-sm">View</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <style>
        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.8rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.75rem;
        }
    </style>

    <script>
        document.getElementById('mobileMenuBtn').onclick = () => document.getElementById('sidebar').classList.toggle('active');
    </script>
</body>

</html>
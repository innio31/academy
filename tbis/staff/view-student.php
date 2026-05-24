<?php
// tbis/staff/view-student.php - Staff View Student Details (Limited to Assigned Subjects)
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: /tbis/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$staff_id = $_SESSION['user_id'];
$staff_name = $_SESSION['user_name'] ?? 'Staff Member';
$student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$active_tab = $_GET['tab'] ?? 'info';

if (!$student_id) {
    header("Location: manage-students.php?message=Invalid+student&type=error");
    exit();
}

// Initialize variables
$student = null;
$assigned_subject_ids = [];
$exams = [];
$results = [];
$assignments = [];
$submission_stats = [];
$message = null;
$message_type = null;

try {
    // Get staff_id string
    $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
    $stmt->execute([$staff_id, $school_id]);
    $staff_id_string = $stmt->fetchColumn();

    if (!$staff_id_string) {
        $message = "Staff record not found.";
        $message_type = "error";
    } else {
        // Get staff's assigned subjects
        $stmt = $pdo->prepare("
            SELECT subject_id FROM staff_subjects 
            WHERE staff_id = ? AND school_id = ?
        ");
        $stmt->execute([$staff_id_string, $school_id]);
        $assigned_subject_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Get student details
        $stmt = $pdo->prepare("
            SELECT * FROM students 
            WHERE id = ? AND school_id = ?
        ");
        $stmt->execute([$student_id, $school_id]);
        $student = $stmt->fetch();

        if (!$student) {
            header("Location: manage-students.php?message=Student+not+found&type=error");
            exit();
        }

        // Verify staff can access this student (must be in their assigned class)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM staff_classes 
            WHERE staff_id = ? AND school_id = ? AND class = ?
        ");
        $stmt->execute([$staff_id_string, $school_id, $student['class']]);
        if ($stmt->fetchColumn() == 0) {
            header("Location: manage-students.php?message=You+do+not+have+access+to+this+student&type=error");
            exit();
        }

        // Get exams for this student's class and assigned subjects
        if (!empty($assigned_subject_ids)) {
            $subject_placeholders = str_repeat('?,', count($assigned_subject_ids) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT e.*, s.subject_name,
                       (SELECT COUNT(*) FROM exam_sessions WHERE exam_id = e.id AND student_id = ?) as attempts
                FROM exams e
                JOIN subjects s ON e.subject_id = s.id
                WHERE e.school_id = ? 
                AND e.class = ?
                AND e.subject_id IN ($subject_placeholders)
                ORDER BY e.created_at DESC
            ");
            $stmt->execute(array_merge([$student_id, $school_id, $student['class']], $assigned_subject_ids));
            $exams = $stmt->fetchAll();
        }

        // Get results for this student
        if (!empty($assigned_subject_ids)) {
            $subject_placeholders = str_repeat('?,', count($assigned_subject_ids) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT r.*, e.exam_name, e.exam_type, s.subject_name,
                       e.max_marks,
                       (r.objective_score + r.theory_score) as total_earned
                FROM results r
                JOIN exams e ON r.exam_id = e.id
                JOIN subjects s ON e.subject_id = s.id
                WHERE r.school_id = ? 
                AND r.student_id = ?
                AND e.subject_id IN ($subject_placeholders)
                ORDER BY r.submitted_at DESC
            ");
            $stmt->execute(array_merge([$school_id, $student_id], $assigned_subject_ids));
            $results = $stmt->fetchAll();
        }

        // Get assignments for this student's class and assigned subjects
        if (!empty($assigned_subject_ids)) {
            $subject_placeholders = str_repeat('?,', count($assigned_subject_ids) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT a.*, s.subject_name,
                       (SELECT COUNT(*) FROM assignment_submissions 
                        WHERE assignment_id = a.id AND student_id = ?) as submitted
                FROM assignments a
                JOIN subjects s ON a.subject_id = s.id
                WHERE a.school_id = ? 
                AND a.class = ?
                AND a.subject_id IN ($subject_placeholders)
                ORDER BY a.deadline ASC
            ");
            $stmt->execute(array_merge([$student_id, $school_id, $student['class']], $assigned_subject_ids));
            $assignments = $stmt->fetchAll();
        }

        // Get submission stats for assignments
        foreach ($assignments as &$assignment) {
            $stmt = $pdo->prepare("
                SELECT submitted_at, status, grade, teacher_feedback 
                FROM assignment_submissions 
                WHERE assignment_id = ? AND student_id = ?
                ORDER BY submitted_at DESC LIMIT 1
            ");
            $stmt->execute([$assignment['id'], $student_id]);
            $assignment['submission'] = $stmt->fetch();
        }
    }
} catch (Exception $e) {
    error_log("View student error: " . $e->getMessage());
    $message = "An error occurred while loading student data.";
    $message_type = "error";
}

// Helper function to get grade color
function getGradeColor($grade)
{
    switch ($grade) {
        case 'A':
            return '#27ae60';
        case 'B':
            return '#2ecc71';
        case 'C':
            return '#f39c12';
        case 'D':
            return '#e67e22';
        case 'F':
            return '#e74c3c';
        default:
            return '#666';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Student Details</title>
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
            flex-wrap: wrap;
            gap: 10px;
        }

        .tabs {
            display: flex;
            gap: 5px;
            border-bottom: 2px solid #ecf0f1;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .tab {
            padding: 10px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 500;
            color: #666;
            transition: all 0.3s ease;
        }

        .tab.active {
            color: var(--primary-color);
            border-bottom: 2px solid var(--primary-color);
            margin-bottom: -2px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .info-item {
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .info-label {
            font-size: 11px;
            color: #999;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 14px;
            font-weight: 500;
            color: #333;
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

        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }

        .badge-success {
            background: #d5f4e6;
            color: #27ae60;
        }

        .badge-warning {
            background: #fff3cd;
            color: #f39c12;
        }

        .badge-danger {
            background: #f8d7da;
            color: #e74c3c;
        }

        .badge-info {
            background: #d1ecf1;
            color: #17a2b8;
        }

        .btn {
            padding: 8px 16px;
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

        .btn-sm {
            padding: 5px 12px;
            font-size: 12px;
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

        .grade-circle {
            display: inline-block;
            width: 35px;
            height: 35px;
            line-height: 35px;
            text-align: center;
            border-radius: 50%;
            font-weight: bold;
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
            .info-grid {
                grid-template-columns: 1fr;
            }

            .data-table {
                font-size: 12px;
            }

            .data-table th,
            .data-table td {
                padding: 8px;
            }

            .tabs {
                flex-direction: column;
            }

            .tab {
                text-align: left;
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
            <li><a href="manage-students.php" class="active"><i class="fas fa-users"></i> My Students</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="assignments.php"><i class="fas fa-tasks"></i> Assignments</a></li>
            <li><a href="../tbis/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-user-graduate"></i> Student Details</h1>
                <p><?php echo htmlspecialchars($student['full_name'] ?? 'Student'); ?></p>
            </div>
            <div>
                <a href="manage-students.php" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Back to Students</a>
            </div>
        </div>

        <?php if (isset($message)): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($student): ?>
            <!-- Student Info Card -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-info-circle"></i> Student Information</h3>
                </div>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Admission Number</div>
                        <div class="info-value"><?php echo htmlspecialchars($student['admission_number']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Full Name</div>
                        <div class="info-value"><?php echo htmlspecialchars($student['full_name']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Class</div>
                        <div class="info-value"><?php echo htmlspecialchars($student['class']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Parent Phone</div>
                        <div class="info-value"><?php echo htmlspecialchars($student['parent_phone'] ?? '—'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Parent Email</div>
                        <div class="info-value"><?php echo htmlspecialchars($student['parent_email'] ?? '—'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Date of Admission</div>
                        <div class="info-value"><?php echo $student['date_of_admission'] ? date('M d, Y', strtotime($student['date_of_admission'])) : '—'; ?></div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab <?php echo $active_tab == 'info' ? 'active' : ''; ?>" onclick="window.location.href='?id=<?php echo $student_id; ?>&tab=info'"><i class="fas fa-user"></i> Profile</button>
                <button class="tab <?php echo $active_tab == 'exams' ? 'active' : ''; ?>" onclick="window.location.href='?id=<?php echo $student_id; ?>&tab=exams'"><i class="fas fa-file-alt"></i> Exams</button>
                <button class="tab <?php echo $active_tab == 'assignments' ? 'active' : ''; ?>" onclick="window.location.href='?id=<?php echo $student_id; ?>&tab=assignments'"><i class="fas fa-tasks"></i> Assignments</button>
                <button class="tab <?php echo $active_tab == 'performance' ? 'active' : ''; ?>" onclick="window.location.href='?id=<?php echo $student_id; ?>&tab=performance'"><i class="fas fa-chart-line"></i> Performance</button>
            </div>

            <!-- Profile Tab -->
            <?php if ($active_tab == 'info'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-address-card"></i> Full Profile</h3>
                    </div>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Gender</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['gender'] ?? '—'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Date of Birth</div>
                            <div class="info-value"><?php echo $student['dob'] ? date('M d, Y', strtotime($student['dob'])) : '—'; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Guardian Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['guardian_name'] ?? '—'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Guardian Phone</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['guardian_phone'] ?? '—'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Address</div>
                            <div class="info-value"><?php echo nl2br(htmlspecialchars($student['address'] ?? '—')); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Status</div>
                            <div class="info-value"><span class="badge <?php echo $student['status'] == 'active' ? 'badge-success' : 'badge-danger'; ?>"><?php echo ucfirst($student['status']); ?></span></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Exams Tab -->
            <?php if ($active_tab == 'exams'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-file-alt"></i> Available Exams</h3>
                    </div>
                    <?php if (empty($exams)): ?>
                        <div class="empty-state"><i class="fas fa-folder-open"></i>
                            <p>No exams available for this student in your subjects.</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Exam Name</th>
                                        <th>Subject</th>
                                        <th>Type</th>
                                        <th>Duration</th>
                                        <th>Attempts</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($exams as $exam): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($exam['exam_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($exam['subject_name']); ?></td>
                                            <td><span class="badge badge-info"><?php echo ucfirst($exam['exam_type']); ?></span></td>
                                            <td><?php echo $exam['duration_minutes']; ?> min</td>
                                            <td><?php echo $exam['attempts']; ?> / Unlimited</td>
                                            <td><a href="view-exam-results.php?exam_id=<?php echo $exam['id']; ?>&student_id=<?php echo $student_id; ?>" class="btn btn-primary btn-sm"><i class="fas fa-eye"></i> View Results</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Assignments Tab -->
            <?php if ($active_tab == 'assignments'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-tasks"></i> Assignments</h3>
                    </div>
                    <?php if (empty($assignments)): ?>
                        <div class="empty-state"><i class="fas fa-folder-open"></i>
                            <p>No assignments available for this student in your subjects.</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Subject</th>
                                        <th>Deadline</th>
                                        <th>Status</th>
                                        <th>Grade</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assignments as $assignment):
                                        $submission = $assignment['submission'];
                                        $is_submitted = $submission && $submission['status'] == 'submitted';
                                        $is_graded = $submission && $submission['status'] == 'graded';
                                        $is_past_deadline = strtotime($assignment['deadline']) < time();
                                    ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($assignment['title']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($assignment['subject_name']); ?></td>
                                            <td><?php echo date('M d, Y H:i', strtotime($assignment['deadline'])); ?></td>
                                            <td>
                                                <?php if ($is_graded): ?>
                                                    <span class="badge badge-success"><i class="fas fa-check-circle"></i> Graded</span>
                                                <?php elseif ($is_submitted): ?>
                                                    <span class="badge badge-info"><i class="fas fa-clock"></i> Submitted</span>
                                                <?php elseif ($is_past_deadline): ?>
                                                    <span class="badge badge-danger"><i class="fas fa-times-circle"></i> Missed</span>
                                                <?php else: ?>
                                                    <span class="badge badge-warning"><i class="fas fa-hourglass-half"></i> Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($is_graded && $submission['grade']): ?>
                                                    <strong style="color: <?php echo getGradeColor($submission['grade']); ?>"><?php echo $submission['grade']; ?></strong>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($is_graded): ?>
                                                    <a href="view-submission.php?id=<?php echo $submission['id']; ?>" class="btn btn-primary btn-sm"><i class="fas fa-eye"></i> View Feedback</a>
                                                <?php elseif ($is_submitted): ?>
                                                    <a href="view-submission.php?id=<?php echo $submission['id']; ?>" class="btn btn-primary btn-sm"><i class="fas fa-eye"></i> View</a>
                                                <?php else: ?>
                                                    <span class="badge badge-warning">Not Submitted</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Performance Tab -->
            <?php if ($active_tab == 'performance'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-line"></i> Academic Performance</h3>
                    </div>
                    <?php if (empty($results)): ?>
                        <div class="empty-state"><i class="fas fa-chart-line"></i>
                            <p>No results available for this student in your subjects.</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Exam Name</th>
                                        <th>Subject</th>
                                        <th>Score</th>
                                        <th>Percentage</th>
                                        <th>Grade</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $total_percentage = 0;
                                    $result_count = 0;
                                    foreach ($results as $result):
                                        $total_percentage += $result['percentage'];
                                        $result_count++;
                                    ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($result['exam_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($result['subject_name']); ?></td>
                                            <td><?php echo $result['total_score']; ?> / <?php echo $result['total_questions']; ?></td>
                                            <td><?php echo number_format($result['percentage'] ?? 0, 1); ?>%</td>
                                            <td><span class="grade-circle" style="background: <?php echo getGradeColor($result['grade']); ?>"><?php echo $result['grade'] ?? 'N/A'; ?></span></td>
                                            <td><?php echo date('M d, Y', strtotime($result['submitted_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <?php if ($result_count > 0): ?>
                                    <tfoot>
                                        <tr style="background: #f5f5f5;">
                                            <td colspan="2"><strong>Average Performance</strong></td>
                                            <td colspan="4"><strong><?php echo number_format($total_percentage / $result_count, 1); ?>%</strong></td>
                                        </tr>
                                    </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Performance Summary Cards -->
                <?php if (!empty($results)): ?>
                    <div class="info-grid" style="margin-top: 20px;">
                        <?php
                        $best_result = null;
                        $worst_result = null;
                        foreach ($results as $result) {
                            if (!$best_result || $result['percentage'] > $best_result['percentage']) $best_result = $result;
                            if (!$worst_result || $result['percentage'] < $worst_result['percentage']) $worst_result = $result;
                        }
                        ?>
                        <div class="info-item">
                            <div class="info-label">Best Performance</div>
                            <div class="info-value">
                                <?php if ($best_result): ?>
                                    <strong><?php echo htmlspecialchars($best_result['subject_name']); ?></strong><br>
                                    <?php echo number_format($best_result['percentage'], 1); ?>% (Grade <?php echo $best_result['grade']; ?>)
                                    <?php else: ?>—<?php endif; ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Area for Improvement</div>
                            <div class="info-value">
                                <?php if ($worst_result): ?>
                                    <strong><?php echo htmlspecialchars($worst_result['subject_name']); ?></strong><br>
                                    <?php echo number_format($worst_result['percentage'], 1); ?>% (Grade <?php echo $worst_result['grade']; ?>)
                                    <?php else: ?>—<?php endif; ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Total Exams Taken</div>
                            <div class="info-value"><?php echo $result_count; ?></div>
                        </div>
                    </div>
                <?php endif; ?>
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
<?php
// gsa/staff/view-submissions.php - View Student Assignment Submissions
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: /gsa/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$staff_id = $_SESSION['user_id'];
$staff_name = $_SESSION['user_name'] ?? 'Staff Member';
$staff_role = $_SESSION['staff_role'] ?? 'staff';
$staff_id_string = $_SESSION['staff_id'] ?? $staff_id;

// Check if assignment ID is provided
$assignment_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$assignment = null;
$submissions = [];
$error = null;

try {
    // Get staff_id string
    $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
    $stmt->execute([$staff_id, $school_id]);
    $staff_id_string_db = $stmt->fetchColumn();

    if (!$staff_id_string_db) {
        $error = "Staff record not found. Please contact administrator.";
    } else {
        $staff_id_string = $staff_id_string_db;

        // Get assignment details and verify ownership
        $stmt = $pdo->prepare("
            SELECT a.*, s.subject_name 
            FROM assignments a
            JOIN subjects s ON a.subject_id = s.id
            WHERE a.id = ? AND a.school_id = ? AND a.staff_id = ?
        ");
        $stmt->execute([$assignment_id, $school_id, $staff_id]);
        $assignment = $stmt->fetch();

        if (!$assignment) {
            $error = "Assignment not found or you don't have permission to view it.";
        } else {
            // Get all submissions for this assignment
            $stmt = $pdo->prepare("
                SELECT sub.*, stu.full_name, stu.admission_number, stu.class
                FROM assignment_submissions sub
                JOIN students stu ON sub.student_id = stu.id
                WHERE sub.assignment_id = ? AND sub.school_id = ?
                ORDER BY sub.submitted_at DESC
            ");
            $stmt->execute([$assignment_id, $school_id]);
            $submissions = $stmt->fetchAll();

            // Get list of students in the class who haven't submitted
            $stmt = $pdo->prepare("
                SELECT stu.id, stu.full_name, stu.admission_number
                FROM students stu
                WHERE stu.class = ? AND stu.school_id = ? AND stu.status = 'active'
                AND stu.id NOT IN (
                    SELECT student_id FROM assignment_submissions 
                    WHERE assignment_id = ? AND school_id = ?
                )
                ORDER BY stu.full_name
            ");
            $stmt->execute([$assignment['class'], $school_id, $assignment_id, $school_id]);
            $non_submitters = $stmt->fetchAll();
        }
    }
} catch (Exception $e) {
    error_log("View submissions error: " . $e->getMessage());
    $error = "An error occurred while loading submissions.";
}

// Handle grading submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grade_submission'])) {
    $submission_id = intval($_POST['submission_id']);
    $grade = trim($_POST['grade']);
    $teacher_feedback = trim($_POST['teacher_feedback']);

    try {
        $stmt = $pdo->prepare("
            UPDATE assignment_submissions 
            SET grade = ?, teacher_feedback = ?, status = 'graded', graded_at = NOW()
            WHERE id = ? AND school_id = ?
        ");
        $stmt->execute([$grade, $teacher_feedback, $submission_id, $school_id]);

        $success_message = "Submission graded successfully!";

        // Refresh submissions list
        header("Location: view-submissions.php?id=" . $assignment_id . "&success=" . urlencode($success_message));
        exit();
    } catch (Exception $e) {
        error_log("Grading error: " . $e->getMessage());
        $error = "Failed to grade submission.";
    }
}

// Get success message from URL
$success_message = isset($_GET['success']) ? $_GET['success'] : '';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - View Submissions</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --primary-dark: #1a5a8a;
            --secondary-color: #d4af7a;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #3498db;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --gray-50: #f9fafb;
            --gray-100: #f0f2f5;
            --gray-200: #e4e7eb;
            --gray-400: #9ca3af;
            --gray-600: #6b7280;
            --gray-800: #1f2937;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--gray-100);
            color: var(--gray-800);
            min-height: 100vh;
        }

        .main-content {
            margin-left: 0;
            padding: 20px;
            min-height: 100vh;
            transition: margin-left 0.28s ease;
        }

        .top-header {
            background: white;
            padding: 20px 25px;
            border-radius: var(--radius-lg);
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: var(--shadow-sm);
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.6rem;
            margin-bottom: 5px;
            font-weight: 700;
        }

        .header-title h1 i {
            margin-right: 10px;
        }

        .header-title p {
            color: var(--gray-600);
            font-size: 0.85rem;
        }

        .card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--gray-200);
        }

        .card-header h3 {
            color: var(--gray-800);
            font-size: 1.1rem;
            font-weight: 600;
        }

        .card-header h3 i {
            color: var(--primary-color);
            margin-right: 8px;
        }

        .info-item {
            background: var(--gray-100);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            color: var(--gray-800);
            font-weight: 500;
        }

        .info-item i {
            margin-right: 6px;
            color: var(--primary-color);
        }

        .btn {
            padding: 8px 18px;
            border-radius: var(--radius-md);
            border: none;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8rem;
            transition: all 0.2s;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background: #219a52;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.75rem;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--gray-200);
            color: var(--gray-800);
        }

        .btn-outline:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        .data-table th {
            text-align: left;
            padding: 14px 16px;
            background: var(--gray-50);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-600);
            border-bottom: 2px solid var(--gray-200);
        }

        .data-table td {
            padding: 14px 16px;
            font-size: 0.85rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .data-table tr:hover td {
            background: var(--gray-50);
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-submitted {
            background: #d1ecf1;
            color: #0c5460;
        }

        .status-graded {
            background: #d5f4e6;
            color: var(--success-color);
        }

        .alert-success {
            background: #d5f4e6;
            padding: 15px 20px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            color: var(--success-color);
            display: flex;
            align-items: center;
            gap: 10px;
            border-left: 4px solid var(--success-color);
        }

        .alert-error {
            background: #f8d7da;
            padding: 15px 20px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            color: var(--danger-color);
            display: flex;
            align-items: center;
            gap: 10px;
            border-left: 4px solid var(--danger-color);
        }

        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: var(--gray-600);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: var(--gray-400);
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1rem;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.3rem;
            cursor: pointer;
            color: var(--gray-600);
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            font-size: 0.8rem;
        }

        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            font-family: inherit;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .file-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--gray-100);
            padding: 6px 12px;
            border-radius: 20px;
            text-decoration: none;
            color: var(--primary-color);
            font-size: 0.75rem;
        }

        .file-link:hover {
            background: var(--gray-200);
        }

        @media (min-width: 768px) {
            .main-content {
                margin-left: 280px;
            }
        }

        @media (max-width: 767px) {
            .main-content {
                padding-top: 70px;
            }

            .top-header {
                flex-direction: column;
                text-align: center;
            }

            .card-header {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>

    <?php include_once 'includes/staff_sidebar.php'; ?>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-tasks"></i> Assignment Submissions</h1>
                <p><i class="fas fa-chevron-right"></i> Review and grade student submissions</p>
            </div>
            <div>
                <span class="info-item"><i class="fas fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></span>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($assignment): ?>
            <!-- Assignment Details -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-info-circle"></i> Assignment Details</h3>
                    <a href="assignments.php" class="btn btn-outline btn-sm">
                        <i class="fas fa-arrow-left"></i> Back to Assignments
                    </a>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                    <div>
                        <strong>Title:</strong><br>
                        <?php echo htmlspecialchars($assignment['title']); ?>
                    </div>
                    <div>
                        <strong>Subject:</strong><br>
                        <?php echo htmlspecialchars($assignment['subject_name']); ?>
                    </div>
                    <div>
                        <strong>Class:</strong><br>
                        <?php echo htmlspecialchars($assignment['class']); ?>
                    </div>
                    <div>
                        <strong>Deadline:</strong><br>
                        <?php echo date('M d, Y H:i', strtotime($assignment['deadline'])); ?>
                    </div>
                    <div>
                        <strong>Submission Type:</strong><br>
                        <?php
                        $type_label = $assignment['submission_type'] == 'online' ? 'Online' : ($assignment['submission_type'] == 'written' ? 'Written' : 'Both');
                        echo $type_label;
                        ?>
                    </div>
                    <div>
                        <strong>Max Marks:</strong><br>
                        <?php echo $assignment['max_marks'] ?? 'Not specified'; ?>
                    </div>
                </div>
                <?php if ($assignment['instructions']): ?>
                    <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--gray-200);">
                        <strong>Instructions:</strong><br>
                        <?php echo nl2br(htmlspecialchars($assignment['instructions'])); ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Submitted Submissions -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-check-circle"></i> Submitted Work (<?php echo count($submissions); ?> submissions)</h3>
                </div>
                <?php if (empty($submissions)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>No submissions yet.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Admission No</th>
                                    <th>Submitted At</th>
                                    <th>Attachment</th>
                                    <th>Status</th>
                                    <th>Grade</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($submissions as $sub): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($sub['full_name']); ?></strong></td>
                                        <td><code><?php echo htmlspecialchars($sub['admission_number']); ?></code></td>
                                        <td>
                                            <?php echo date('M d, Y H:i', strtotime($sub['submitted_at'])); ?>
                                        </td>
                                        <td>
                                            <?php if ($sub['file_path']): ?>
                                                <a href="/gsa/<?php echo $sub['file_path']; ?>" target="_blank" class="file-link">
                                                    <i class="fas fa-download"></i> View File
                                                </a>
                                            <?php else: ?>
                                                <span class="file-link" style="background: none;">
                                                    <i class="fas fa-ban"></i> No file
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($sub['submitted_text']): ?>
                                                <button class="btn btn-outline btn-sm" onclick="viewTextSubmission(<?php echo $sub['id']; ?>, '<?php echo addslashes($sub['full_name']); ?>', `<?php echo addslashes($sub['submitted_text']); ?>`)">
                                                    <i class="fas fa-align-left"></i> View Text
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $sub['status']; ?>">
                                                <?php echo ucfirst($sub['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($sub['grade']): ?>
                                                <span style="font-weight: 600; color: var(--success-color);">
                                                    <?php echo htmlspecialchars($sub['grade']); ?>
                                                    <?php if ($assignment['max_marks']): ?>
                                                        / <?php echo $assignment['max_marks']; ?>
                                                    <?php endif; ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: var(--gray-400);">Not graded</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-primary btn-sm" onclick="openGradeModal(<?php echo $sub['id']; ?>, '<?php echo addslashes($sub['full_name']); ?>', '<?php echo addslashes($sub['grade']); ?>', '<?php echo addslashes($sub['teacher_feedback']); ?>')">
                                                <i class="fas fa-star"></i> Grade
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Non-Submitters -->
            <?php if (!empty($non_submitters)): ?>
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-clock"></i> Pending Submissions (<?php echo count($non_submitters); ?> students)</h3>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Admission No</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($non_submitters as $student): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($student['full_name']); ?></strong></td>
                                        <td><code><?php echo htmlspecialchars($student['admission_number']); ?></code></td>
                                        <td><span class="status-badge" style="background: #f8d7da; color: var(--danger-color);">Not Submitted</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        <?php elseif (!$error): ?>
            <div class="card">
                <div class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <p>Assignment not found.</p>
                    <a href="assignments.php" class="btn btn-primary" style="margin-top: 10px;">
                        <i class="fas fa-arrow-left"></i> Back to Assignments
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Grade Modal -->
    <div class="modal" id="gradeModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-star"></i> Grade Submission</h3>
                <button class="close-modal" onclick="closeGradeModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="grade_submission" value="1">
                <input type="hidden" name="submission_id" id="grade_submission_id">
                <div class="modal-body">
                    <p style="margin-bottom: 15px;"><strong id="grade_student_name"></strong></p>
                    <div class="form-group">
                        <label>Grade / Score</label>
                        <input type="text" name="grade" id="grade_value" class="form-control" placeholder="e.g., 85, A, Pass, etc.">
                        <?php if ($assignment && $assignment['max_marks']): ?>
                            <small style="color: var(--gray-600);">Maximum marks: <?php echo $assignment['max_marks']; ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>Teacher Feedback</label>
                        <textarea name="teacher_feedback" id="teacher_feedback" class="form-control" rows="4" placeholder="Enter feedback for the student..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeGradeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Grade</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Text Submission Modal -->
    <div class="modal" id="textModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-align-left"></i> Text Submission</h3>
                <button class="close-modal" onclick="closeTextModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p><strong id="text_student_name"></strong></p>
                <div style="margin-top: 15px; padding: 15px; background: var(--gray-50); border-radius: var(--radius-md);">
                    <p id="text_submission_content" style="white-space: pre-wrap;"></p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeTextModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        const mobileBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('staffSidebar');
        const overlay = document.getElementById('sidebarOverlay');

        if (mobileBtn) {
            mobileBtn.onclick = () => {
                sidebar.classList.toggle('active');
                if (overlay) overlay.classList.toggle('active');
            };
        }

        if (overlay) {
            overlay.onclick = () => {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            };
        }

        function openGradeModal(submissionId, studentName, currentGrade, currentFeedback) {
            document.getElementById('grade_submission_id').value = submissionId;
            document.getElementById('grade_student_name').innerHTML = studentName;
            document.getElementById('grade_value').value = currentGrade || '';
            document.getElementById('teacher_feedback').value = currentFeedback || '';
            document.getElementById('gradeModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeGradeModal() {
            document.getElementById('gradeModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        function viewTextSubmission(submissionId, studentName, submissionText) {
            document.getElementById('text_student_name').innerHTML = studentName;
            document.getElementById('text_submission_content').innerHTML = submissionText.replace(/\n/g, '<br>');
            document.getElementById('textModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeTextModal() {
            document.getElementById('textModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        window.onclick = function(event) {
            const gradeModal = document.getElementById('gradeModal');
            const textModal = document.getElementById('textModal');
            if (event.target === gradeModal) closeGradeModal();
            if (event.target === textModal) closeTextModal();
        }
    </script>
</body>

</html>
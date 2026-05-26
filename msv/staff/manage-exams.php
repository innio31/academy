<?php
// msv/staff/manage-exams.php - Staff Exam Management
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: /msv/login.php");
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
    $staff_id_string_db = $stmt->fetchColumn();

    if (!$staff_id_string_db) {
        $message = "Staff record not found. Please contact administrator.";
        $message_type = "error";
    } else {
        $staff_id_string = $staff_id_string_db;

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

        // Redirect to refresh the page and show the new exam
        header("Location: manage-exams.php?message=" . urlencode($message) . "&type=success");
        exit();
    } catch (Exception $e) {
        error_log("Exam creation error: " . $e->getMessage());
        $message = "Failed to create exam: " . $e->getMessage();
        $message_type = "error";
    }
}

// Check for message from redirect
if (isset($_GET['message'])) {
    $message = $_GET['message'];
    $message_type = $_GET['type'] ?? 'success';
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

        /* Main Content */
        .main-content {
            margin-left: 0;
            padding: 20px;
            min-height: 100vh;
            transition: margin-left 0.28s ease;
        }

        /* Top Header */
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

        .header-title p i {
            color: var(--primary-color);
            font-size: 0.7rem;
            margin: 0 4px;
        }

        /* Cards */
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

        /* Forms */
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
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-600);
            margin-bottom: 6px;
        }

        .form-control,
        .form-select {
            padding: 10px 14px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            font-family: inherit;
            width: 100%;
            transition: all 0.2s;
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

        /* Buttons */
        .btn {
            padding: 10px 20px;
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

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.75rem;
        }

        /* Table */
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

        /* Make exam name bigger */
        .data-table td strong {
            font-size: 0.9rem;
            font-weight: 600;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-active {
            background: #d5f4e6;
            color: var(--success-color);
        }

        .status-inactive {
            background: #f8d7da;
            color: var(--danger-color);
        }

        /* Alerts */
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

        /* Empty State */
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

        .empty-state p {
            margin-top: 8px;
        }

        /* Checkbox styling */
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
        }

        .checkbox-label input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        /* Info Item */
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

        /* Responsive */
        @media (min-width: 768px) {
            .main-content {
                margin-left: 280px;
            }
        }

        @media (max-width: 767px) {
            .main-content {
                padding-top: 70px;
            }

            .form-grid {
                grid-template-columns: 1fr;
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
    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Include Staff Sidebar -->
    <?php include_once 'includes/staff_sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-file-alt"></i> Manage Exams</h1>
                <p><i class="fas fa-chevron-right"></i> Create and manage examinations for your classes</p>
            </div>
            <div>
                <span class="info-item"><i class="fas fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></span>
            </div>
        </div>

        <?php if ($message): ?>
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
                    <p>Please contact the administrator to assign you to classes and subjects.</p>
                </div>
            <?php else: ?>
                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Exam Name *</label>
                            <input type="text" name="exam_name" class="form-control" placeholder="e.g., First Term Examination" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-layer-group"></i> Class *</label>
                            <select name="class" class="form-select" required>
                                <option value="">Select Class</option>
                                <?php foreach ($class_names as $class): ?>
                                    <option value="<?php echo htmlspecialchars($class); ?>"><?php echo htmlspecialchars($class); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-book"></i> Subject *</label>
                            <select name="subject_id" class="form-select" required>
                                <option value="">Select Subject</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['subject_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-list-alt"></i> Exam Type</label>
                            <select name="exam_type" class="form-select">
                                <option value="objective">📝 Objective (Multiple Choice)</option>
                                <option value="subjective">✍️ Subjective (Short Answer)</option>
                                <option value="theory">📖 Theory (Essay)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hourglass-half"></i> Duration (minutes)</label>
                            <input type="number" name="duration_minutes" class="form-control" value="60" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-check-circle"></i> Objective Questions</label>
                            <input type="number" name="objective_count" class="form-control" value="0" min="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-paragraph"></i> Subjective Questions</label>
                            <input type="number" name="subjective_count" class="form-control" value="0" min="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-pen-fancy"></i> Theory Questions</label>
                            <input type="number" name="theory_count" class="form-control" value="0" min="0">
                        </div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-info-circle"></i> Instructions</label>
                        <textarea name="instructions" class="form-control" rows="3" placeholder="Enter exam instructions for students..."></textarea>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_active" value="1" checked>
                            <span><i class="fas fa-toggle-on"></i> Active (Students can take this exam)</span>
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
                <?php if (!empty($exams)): ?>
                    <span class="info-item"><i class="fas fa-chart-line"></i> Total: <?php echo count($exams); ?> exams</span>
                <?php endif; ?>
            </div>
            <?php if (empty($exams)): ?>
                <div class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <p>No exams created yet.</p>
                    <p>Use the form above to create your first exam.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
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
                                    <td>
                                        <?php
                                        $type_icon = $exam['exam_type'] == 'objective' ? '📝' : ($exam['exam_type'] == 'subjective' ? '✍️' : '📖');
                                        echo $type_icon . ' ' . ucfirst($exam['exam_type']);
                                        ?>
                                    </td>
                                    <td><?php echo $exam['duration_minutes']; ?> min</td>
                                    <td>
                                        <span class="status-badge status-<?php echo $exam['is_active'] ? 'active' : 'inactive'; ?>">
                                            <?php echo $exam['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
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
        // Mobile menu toggle is handled in staff_sidebar.php
        document.addEventListener('DOMContentLoaded', function() {
            // Any page-specific initialization
        });
    </script>
</body>

</html>
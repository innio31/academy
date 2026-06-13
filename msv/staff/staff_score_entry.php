<?php
// msv/staff/staff_score_entry.php - Staff Score Entry (Only assigned subjects)
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: /msv/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id       = SCHOOL_ID;
$school_name     = SCHOOL_NAME;
$primary_color   = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;
$staff_id        = $_SESSION['user_id'];
$staff_name      = $_SESSION['user_name'] ?? 'Staff Member';
$staff_role      = $_SESSION['staff_role'] ?? 'staff';

// Get staff_id string from staff table
$stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
$stmt->execute([$staff_id, $school_id]);
$staff_id_string = $stmt->fetchColumn();

if (!$staff_id_string) {
    die("Staff record not found. Please contact administrator.");
}

// Get assigned classes for this staff
$assigned_classes = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.id, c.class_name 
        FROM staff_classes sc
        JOIN classes c ON sc.class_id = c.id
        WHERE sc.staff_id = ? AND sc.school_id = ?
        ORDER BY c.class_name ASC
    ");
    $stmt->execute([$staff_id_string, $school_id]);
    $assigned_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching assigned classes: " . $e->getMessage());
}

// Get selected class and record
$selected_class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$selected_record_id = isset($_GET['record_id']) ? (int)$_GET['record_id'] : 0;

// Get exam records for selected class
$exam_records = [];
if ($selected_class_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT rcs.id, rcs.record_name, rcs.session, rcs.term, rcs.status, 
                   c.class_name
            FROM report_card_settings rcs
            JOIN classes c ON rcs.class_id = c.id
            WHERE rcs.school_id = ? AND rcs.class_id = ? AND rcs.status != 'archived'
            ORDER BY rcs.created_at DESC
        ");
        $stmt->execute([$school_id, $selected_class_id]);
        $exam_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching exam records: " . $e->getMessage());
    }
}

// If record is selected, verify staff has access to that class
$record_access_verified = false;
if ($selected_record_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT rcs.*, c.class_name 
            FROM report_card_settings rcs
            JOIN classes c ON rcs.class_id = c.id
            WHERE rcs.id = ? AND rcs.school_id = ? AND rcs.status != 'archived'
        ");
        $stmt->execute([$selected_record_id, $school_id]);
        $record_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($record_data) {
            // Verify staff has access to this class
            $stmt2 = $pdo->prepare("
                SELECT 1 FROM staff_classes 
                WHERE staff_id = ? AND school_id = ? AND class_id = ?
            ");
            $stmt2->execute([$staff_id_string, $school_id, $record_data['class_id']]);
            if ($stmt2->fetch()) {
                $record_access_verified = true;
                $selected_class_id = $record_data['class_id'];
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching record data: " . $e->getMessage());
    }
}

// Handle POST save scores (existing logic - kept for compatibility)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_scores']) && $record_access_verified) {
    // ... (keep your existing POST handling code here)
    // For brevity, I'm showing the full structure but you can keep your existing POST logic
    $post_subject_id = (int)($_POST['subject_id'] ?? 0);
    // ... rest of your existing POST handling code
    // (Copy your existing POST logic from the original file here)
}

// Helper function for grade calculation (keep your existing one)
function getGradeInfoStaffDisplay($total, $scale)
{
    foreach ($scale as $row) {
        if ($total >= (float)$row['min'] && $total <= (float)$row['max'])
            return ['grade' => $row['grade'], 'remark' => $row['remark']];
    }
    return ['grade' => 'F', 'remark' => 'Fail'];
}

$success_message = $_SESSION['flash_success'] ?? '';
$error_message = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success']);
unset($_SESSION['flash_error']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($school_name); ?> — Score Entry (Staff)</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: <?php echo $secondary_color; ?>;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --sidebar-width: 280px;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.12);
            --radius-md: 12px;
            --radius-sm: 8px;
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

        .main-content {
            margin-left: 0;
            padding: 20px;
            min-height: 100vh;
            transition: margin-left 0.28s ease;
        }

        .top-header {
            background: white;
            padding: 20px 25px;
            border-radius: var(--radius-md);
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
            font-size: 1.4rem;
            margin-bottom: 5px;
            font-weight: 700;
        }

        .header-title h1 i {
            margin-right: 10px;
        }

        .header-title p {
            color: #666;
            font-size: 0.8rem;
        }

        .header-title .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.75rem;
            margin-top: 8px;
            flex-wrap: wrap;
        }

        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
        }

        .breadcrumb i {
            font-size: 0.7rem;
            color: #999;
        }

        .info-item {
            background: var(--light-color);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        /* Class Cards */
        .section-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 16px;
            color: var(--dark-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-title i {
            color: var(--primary-color);
        }

        .class-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
            margin-bottom: 30px;
        }

        .class-card {
            background: white;
            border-radius: var(--radius-md);
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-sm);
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .class-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary-color);
        }

        .class-card.selected {
            border-color: var(--primary-color);
            background: linear-gradient(135deg, rgba(<?php echo hexdec(substr($primary_color, 1, 2)); ?>, <?php echo hexdec(substr($primary_color, 3, 2)); ?>, <?php echo hexdec(substr($primary_color, 5, 2)); ?>, 0.05) 0%, white 100%);
        }

        .class-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .class-icon i {
            font-size: 24px;
            color: white;
        }

        .class-name {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .class-meta {
            font-size: 0.75rem;
            color: #888;
            display: flex;
            gap: 12px;
            margin-top: 8px;
        }

        /* Exam Records List */
        .records-container {
            background: white;
            border-radius: var(--radius-md);
            padding: 20px;
            margin-top: 20px;
            box-shadow: var(--shadow-sm);
        }

        .record-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px;
            border-bottom: 1px solid var(--light-color);
            transition: background 0.2s;
            cursor: pointer;
        }

        .record-item:last-child {
            border-bottom: none;
        }

        .record-item:hover {
            background: #f9f9f9;
        }

        .record-info h4 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .record-info p {
            font-size: 0.75rem;
            color: #888;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-draft {
            background: #fef5e7;
            color: #856404;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            max-width: 400px;
            width: 100%;
            overflow: hidden;
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 20px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .modal-header h3 {
            font-size: 1.2rem;
            margin-bottom: 4px;
        }

        .modal-header p {
            font-size: 0.8rem;
            opacity: 0.9;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-options {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .option-btn {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px;
            border: 2px solid var(--light-color);
            border-radius: var(--radius-md);
            text-decoration: none;
            color: #333;
            transition: all 0.2s;
            background: white;
            cursor: pointer;
        }

        .option-btn:hover {
            border-color: var(--primary-color);
            background: rgba(<?php echo hexdec(substr($primary_color, 1, 2)); ?>, <?php echo hexdec(substr($primary_color, 3, 2)); ?>, <?php echo hexdec(substr($primary_color, 5, 2)); ?>, 0.05);
            transform: translateX(5px);
        }

        .option-icon {
            width: 48px;
            height: 48px;
            background: var(--light-color);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .option-icon i {
            font-size: 24px;
            color: var(--primary-color);
        }

        .option-text h4 {
            font-size: 1rem;
            margin-bottom: 4px;
        }

        .option-text p {
            font-size: 0.7rem;
            color: #888;
        }

        .modal-close {
            position: absolute;
            top: 16px;
            right: 16px;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: white;
            border: 1px solid var(--light-color);
            border-radius: var(--radius-sm);
            text-decoration: none;
            color: #666;
            font-size: 0.8rem;
            margin-bottom: 20px;
            transition: all 0.2s;
        }

        .btn-back:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.3;
        }

        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid var(--success-color);
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger-color);
        }

        @media (min-width: 768px) {
            .main-content {
                margin-left: var(--sidebar-width);
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

            .class-grid {
                grid-template-columns: 1fr;
            }

            .modal-content {
                margin: 20px;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn" style="position:fixed;top:16px;left:16px;z-index:1001;width:44px;height:44px;background:var(--primary-color);color:white;border:none;border-radius:10px;font-size:20px;cursor:pointer;">
        <i class="fas fa-bars"></i>
    </button>

    <?php include_once 'includes/staff_sidebar.php'; ?>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-pencil-alt"></i> Score Entry & Comments</h1>
                <p>Select a class to manage scores and comments</p>
                <?php if ($selected_record_id > 0 && $record_access_verified): ?>
                    <div class="breadcrumb">
                        <a href="staff_score_entry.php">Classes</a>
                        <i class="fas fa-chevron-right"></i>
                        <a href="staff_score_entry.php?class_id=<?php echo $selected_class_id; ?>"><?php echo htmlspecialchars($record_data['class_name'] ?? ''); ?></a>
                        <i class="fas fa-chevron-right"></i>
                        <span><?php echo htmlspecialchars($record_data['record_name'] ?? ''); ?></span>
                    </div>
                <?php elseif ($selected_class_id > 0): ?>
                    <div class="breadcrumb">
                        <a href="staff_score_entry.php">Classes</a>
                        <i class="fas fa-chevron-right"></i>
                        <span><?php echo htmlspecialchars($assigned_classes[array_search($selected_class_id, array_column($assigned_classes, 'id'))]['class_name'] ?? ''); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <span class="info-item"><i class="fas fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></span>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <?php if (empty($assigned_classes)): ?>
            <div class="empty-state">
                <i class="fas fa-chalkboard-teacher"></i>
                <h3>No Classes Assigned</h3>
                <p>You haven't been assigned to any classes yet.</p>
                <p style="margin-top: 8px;">Please contact the administrator.</p>
            </div>
        <?php else: ?>

            <!-- Show back button if class is selected -->
            <?php if ($selected_class_id > 0 && !$record_access_verified): ?>
                <a href="staff_score_entry.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Back to Classes
                </a>
            <?php endif; ?>

            <?php if ($selected_class_id === 0): ?>
                <!-- Step 1: Show all assigned classes -->
                <div class="section-title">
                    <i class="fas fa-chalkboard"></i>
                    <span>My Assigned Classes</span>
                </div>
                <div class="class-grid">
                    <?php foreach ($assigned_classes as $class): ?>
                        <div class="class-card" onclick="window.location.href='staff_score_entry.php?class_id=<?php echo $class['id']; ?>'">
                            <div class="class-icon">
                                <i class="fas fa-graduation-cap"></i>
                            </div>
                            <div class="class-name"><?php echo htmlspecialchars($class['class_name']); ?></div>
                            <div class="class-meta">
                                <span><i class="fas fa-book"></i> Click to view exam records</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($selected_class_id > 0 && empty($exam_records)): ?>
                <!-- No exam records for selected class -->
                <div class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <h3>No Exam Records Found</h3>
                    <p>No exam records available for <?php echo htmlspecialchars($assigned_classes[array_search($selected_class_id, array_column($assigned_classes, 'id'))]['class_name'] ?? ''); ?></p>
                    <p style="margin-top: 8px;">
                        <a href="staff_score_entry.php" class="btn-back" style="margin-top: 16px;">
                            <i class="fas fa-arrow-left"></i> Back to Classes
                        </a>
                    </p>
                </div>
            <?php elseif ($selected_class_id > 0 && !$record_access_verified): ?>
                <!-- Step 2: Show exam records for selected class -->
                <div class="section-title">
                    <i class="fas fa-file-alt"></i>
                    <span>Exam Records - <?php echo htmlspecialchars($assigned_classes[array_search($selected_class_id, array_column($assigned_classes, 'id'))]['class_name'] ?? ''); ?></span>
                </div>
                <div class="records-container">
                    <?php foreach ($exam_records as $record): ?>
                        <div class="record-item" onclick="openModal(<?php echo $record['id']; ?>, '<?php echo htmlspecialchars($record['record_name']); ?>', '<?php echo htmlspecialchars($record['session']); ?>', '<?php echo htmlspecialchars($record['term']); ?>')">
                            <div class="record-info">
                                <h4><?php echo htmlspecialchars($record['record_name']); ?></h4>
                                <p><i class="fas fa-calendar"></i> <?php echo htmlspecialchars($record['session']); ?> · <?php echo htmlspecialchars($record['term']); ?> Term</p>
                            </div>
                            <div>
                                <span class="status-badge status-<?php echo $record['status']; ?>">
                                    <?php echo ucfirst($record['status']); ?>
                                </span>
                                <i class="fas fa-chevron-right" style="margin-left: 12px; color: #ccc;"></i>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($record_access_verified): ?>
                <!-- Step 3: Score entry page (your existing score entry UI) -->
                <?php
                // Load the score entry interface here
                // Include your existing score entry code for the selected record
                $class_name = $record_data['class_name'];
                $record = $record_data;
                $class_id = $record_data['class_id'];
                $session = $record_data['session'];
                $term = $record_data['term'];

                // Decode score types & grading
                $decoded = json_decode($record['score_types'] ?? '{}', true);
                $score_types = $decoded['score_types'] ?? (is_array($decoded) && isset($decoded[0]['label']) ? $decoded : []);
                $grading_scale = $decoded['grading_scale'] ?? [];

                if (empty($grading_scale)) {
                    $grading_scale = [
                        ['grade' => 'A', 'min' => 75, 'max' => 100, 'remark' => 'Excellent'],
                        ['grade' => 'B', 'min' => 65, 'max' => 74, 'remark' => 'Very Good'],
                        ['grade' => 'C', 'min' => 50, 'max' => 64, 'remark' => 'Good'],
                        ['grade' => 'D', 'min' => 40, 'max' => 49, 'remark' => 'Pass'],
                        ['grade' => 'F', 'min' => 0, 'max' => 39, 'remark' => 'Fail'],
                    ];
                }

                // Get subjects assigned to this staff for this class
                $subjects = [];
                try {
                    $stmt = $pdo->prepare("
                        SELECT DISTINCT s.id, s.subject_name
                        FROM subjects s
                        JOIN staff_subjects ss ON ss.subject_id = s.id AND ss.school_id = ?
                        JOIN subject_classes sc ON sc.subject_id = s.id AND sc.school_id = ? AND sc.class_id = ?
                        WHERE ss.staff_id = ? AND (s.school_id = ? OR s.is_central = 1)
                        ORDER BY s.subject_name ASC
                    ");
                    $stmt->execute([$school_id, $school_id, $class_id, $staff_id_string, $school_id]);
                    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    error_log("staff_score_entry subjects: " . $e->getMessage());
                }

                // Get students
                $students = [];
                if ($class_id > 0) {
                    try {
                        $stmt = $pdo->prepare("
                            SELECT id, full_name, admission_number, gender
                            FROM students
                            WHERE school_id = ? AND class_id = ? AND status = 'active'
                            ORDER BY full_name ASC
                        ");
                        $stmt->execute([$school_id, $class_id]);
                        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {
                        error_log("staff_score_entry students: " . $e->getMessage());
                    }
                }

                $active_subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : (count($subjects) > 0 ? (int)$subjects[0]['id'] : 0);
                $active_subject_name = '';
                foreach ($subjects as $sub) {
                    if ((int)$sub['id'] === $active_subject_id) {
                        $active_subject_name = $sub['subject_name'];
                        break;
                    }
                }

                // Load existing scores
                $existing_scores = [];
                if ($active_subject_id > 0 && !empty($students)) {
                    try {
                        $stmt = $pdo->prepare("
                            SELECT student_id, score_data, total_score, grade, subject_position
                            FROM student_scores
                            WHERE school_id=? AND subject_id=? AND session=? AND term=?
                        ");
                        $stmt->execute([$school_id, $active_subject_id, $session, $term]);
                        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                            $row['score_data'] = json_decode($row['score_data'] ?? '[]', true) ?: [];
                            $existing_scores[(int)$row['student_id']] = $row;
                        }
                    } catch (Exception $e) {
                        error_log("staff_score_entry existing: " . $e->getMessage());
                    }
                }

                // Get completed subjects count
                $subjects_with_scores = [];
                if (!empty($subjects)) {
                    $sub_ids = array_column($subjects, 'id');
                    $ph = implode(',', array_fill(0, count($sub_ids), '?'));
                    $stmt = $pdo->prepare("
                        SELECT DISTINCT subject_id FROM student_scores
                        WHERE school_id=? AND session=? AND term=? AND subject_id IN ($ph)
                    ");
                    $stmt->execute(array_merge([$school_id, $session, $term], $sub_ids));
                    $subjects_with_scores = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
                }

                $total_subjects = count($subjects);
                $completed_subjects = count($subjects_with_scores);
                $progress_pct = $total_subjects > 0 ? round(($completed_subjects / $total_subjects) * 100) : 0;
                ?>

                <div class="score-entry-interface">
                    <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-bottom: 20px;">
                        <div class="stat-card" style="background: white; padding: 15px; border-radius: 12px; text-align: center;">
                            <div class="stat-value" style="font-size: 1.5rem; font-weight: 700;"><?php echo $total_subjects; ?></div>
                            <div class="stat-label" style="font-size: 0.75rem; color: #888;">My Subjects</div>
                        </div>
                        <div class="stat-card" style="background: white; padding: 15px; border-radius: 12px; text-align: center;">
                            <div class="stat-value" style="font-size: 1.5rem; font-weight: 700; color: #27ae60;"><?php echo $completed_subjects; ?></div>
                            <div class="stat-label" style="font-size: 0.75rem; color: #888;">Completed</div>
                        </div>
                        <div class="stat-card" style="background: white; padding: 15px; border-radius: 12px; text-align: center;">
                            <div class="stat-value" style="font-size: 1.5rem; font-weight: 700; color: #f39c12;"><?php echo $total_subjects - $completed_subjects; ?></div>
                            <div class="stat-label" style="font-size: 0.75rem; color: #888;">Pending</div>
                        </div>
                    </div>

                    <div style="background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; font-size: 0.8rem; margin-bottom: 8px;">
                            <span>Score entry progress</span>
                            <span><?php echo $completed_subjects; ?> / <?php echo $total_subjects; ?> subjects · <?php echo $progress_pct; ?>%</span>
                        </div>
                        <div style="height: 6px; background: #ecf0f1; border-radius: 20px; overflow: hidden;">
                            <div style="height: 100%; width: <?php echo $progress_pct; ?>%; background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));"></div>
                        </div>
                    </div>

                    <!-- Subject selection -->
                    <div class="subject-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; margin-bottom: 20px;">
                        <?php foreach ($subjects as $sub):
                            $is_done = isset($subjects_with_scores[(int)$sub['id']]);
                            $is_active = (int)$sub['id'] === $active_subject_id;
                        ?>
                            <a href="staff_score_entry.php?record_id=<?php echo $selected_record_id; ?>&subject_id=<?php echo $sub['id']; ?>"
                                style="background: <?php echo $is_active ? 'var(--primary-color)' : 'white'; ?>; padding: 12px 16px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; text-decoration: none; color: <?php echo $is_active ? 'white' : '#333'; ?>; border: 1px solid var(--light-color); transition: all 0.2s;">
                                <span style="font-weight: 500; font-size: 0.85rem;"><?php echo htmlspecialchars($sub['subject_name']); ?></span>
                                <div style="width: 10px; height: 10px; border-radius: 50%; background: <?php echo $is_done ? '#27ae60' : '#ccc'; ?>;"></div>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <!-- Score entry form (simplified version) -->
                    <?php if ($active_subject_id > 0 && $active_subject_name && !empty($students)): ?>
                        <form method="POST" style="background: white; border-radius: 12px; overflow: hidden;">
                            <input type="hidden" name="subject_id" value="<?php echo $active_subject_id; ?>">
                            <input type="hidden" name="save_scores" value="1">

                            <div style="padding: 20px; background: var(--primary-color); color: white;">
                                <h3 style="font-size: 1rem;"><i class="fas fa-chalkboard-teacher"></i> <?php echo htmlspecialchars($active_subject_name); ?></h3>
                                <p style="font-size: 0.7rem; opacity: 0.9; margin-top: 5px;">
                                    <?php echo htmlspecialchars($class_name); ?> · <?php echo htmlspecialchars($term); ?> Term · <?php echo htmlspecialchars($session); ?>
                                </p>
                            </div>

                            <div style="padding: 16px; max-height: 60vh; overflow-y: auto;">
                                <?php foreach ($students as $stu):
                                    $stu_id = (int)$stu['id'];
                                    $saved = $existing_scores[$stu_id] ?? null;
                                ?>
                                    <div style="background: #f9f9f9; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                                        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #ddd;">
                                            <div style="width: 36px; height: 36px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600;">
                                                <?php echo strtoupper(substr($stu['full_name'], 0, 2)); ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 600; font-size: 0.85rem;"><?php echo htmlspecialchars($stu['full_name']); ?></div>
                                                <div style="font-size: 0.7rem; color: #888;">Adm: <?php echo htmlspecialchars($stu['admission_number']); ?></div>
                                            </div>
                                        </div>

                                        <div style="display: flex; flex-wrap: wrap; gap: 12px;">
                                            <?php foreach ($score_types as $st):
                                                $lbl = $st['label'];
                                                $maxVal = (int)$st['max'];
                                                $val = $saved ? ($saved['score_data'][$lbl] ?? '') : '';
                                            ?>
                                                <div style="flex: 1; min-width: 80px;">
                                                    <label style="font-size: 0.7rem; color: #666;"><?php echo htmlspecialchars($lbl); ?> / <?php echo $maxVal; ?></label>
                                                    <input type="number"
                                                        name="scores[<?php echo $stu_id; ?>][<?php echo htmlspecialchars($lbl); ?>]"
                                                        value="<?php echo htmlspecialchars((string)$val); ?>"
                                                        min="0" max="<?php echo $maxVal; ?>" step="0.5"
                                                        style="width: 100%; padding: 8px; text-align: center; border: 1.5px solid #ddd; border-radius: 8px;">
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div style="padding: 16px; border-top: 1px solid #ddd; display: flex; gap: 12px;">
                                <button type="submit" style="flex: 1; padding: 12px; background: var(--primary-color); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">
                                    <i class="fas fa-save"></i> Save Scores
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if ($completed_subjects >= $total_subjects && $total_subjects > 0): ?>
                        <div style="margin-top: 20px; text-align: center;">
                            <a href="staff_traits_comments.php?record_id=<?php echo $selected_record_id; ?>" style="display: inline-block; padding: 12px 24px; background: #27ae60; color: white; text-decoration: none; border-radius: 8px; font-weight: 600;">
                                <i class="fas fa-arrow-right"></i> Continue to Traits & Comments
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Modal for selecting score entry or comments -->
    <div class="modal" id="actionModal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal()">
                <i class="fas fa-times"></i>
            </button>
            <div class="modal-header">
                <h3 id="modalTitle">Exam Record</h3>
                <p id="modalSubtitle"></p>
            </div>
            <div class="modal-body">
                <div class="modal-options">
                    <a href="#" id="scoreEntryLink" class="option-btn">
                        <div class="option-icon">
                            <i class="fas fa-pencil-alt"></i>
                        </div>
                        <div class="option-text">
                            <h4>Enter Scores</h4>
                            <p>Input and manage student scores</p>
                        </div>
                    </a>
                    <a href="#" id="commentsLink" class="option-btn">
                        <div class="option-icon">
                            <i class="fas fa-comment-dots"></i>
                        </div>
                        <div class="option-text">
                            <h4>Enter Comments</h4>
                            <p>Add traits and comments for students</p>
                        </div>
                    </a>
                </div>
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

        let currentRecordId = null;

        function openModal(recordId, recordName, session, term) {
            currentRecordId = recordId;
            document.getElementById('modalTitle').textContent = recordName;
            document.getElementById('modalSubtitle').textContent = session + ' · ' + term + ' Term';

            const scoreEntryLink = document.getElementById('scoreEntryLink');
            const commentsLink = document.getElementById('commentsLink');

            scoreEntryLink.href = 'score_entry.php?record_id=' + recordId;
            commentsLink.href = 'staff_traits_comments.php?record_id=' + recordId;

            document.getElementById('actionModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('actionModal').classList.remove('active');
            currentRecordId = null;
        }

        // Close modal when clicking outside
        document.getElementById('actionModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    </script>
</body>

</html>
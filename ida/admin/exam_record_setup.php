<?php
// gos/admin/exam_record_setup.php - Create / Edit Exam Record Setup
// ─────────────────────────────────────────────────────────────────────────────
// RUN THIS SQL ONCE ON YOUR DATABASE BEFORE USING THIS PAGE:
//
// ALTER TABLE `report_card_settings`
//   ADD COLUMN IF NOT EXISTS `record_name`           VARCHAR(150)  DEFAULT NULL,
//   ADD COLUMN IF NOT EXISTS `status`                ENUM('draft','active','published','archived') DEFAULT 'draft',
//   ADD COLUMN IF NOT EXISTS `show_cumulative_avg`   TINYINT(1)    DEFAULT 1,
//   ADD COLUMN IF NOT EXISTS `sequential_positions`  TINYINT(1)    DEFAULT 0,
//   ADD COLUMN IF NOT EXISTS `show_attendance`       TINYINT(1)    DEFAULT 1,
//   ADD COLUMN IF NOT EXISTS `show_affective_traits` TINYINT(1)    DEFAULT 1,
//   ADD COLUMN IF NOT EXISTS `show_psychomotor`      TINYINT(1)    DEFAULT 1,
//   ADD COLUMN IF NOT EXISTS `created_by`            INT(11)       DEFAULT NULL,
//   ADD COLUMN IF NOT EXISTS `default_class_teacher_name` VARCHAR(150) DEFAULT NULL,
//   ADD COLUMN IF NOT EXISTS `principal_comments_per_grade` LONGTEXT DEFAULT NULL;
// ─────────────────────────────────────────────────────────────────────────────

error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../includes/config.php';

// ── Auth check ────────────────────────────────────────────────────────────────
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /gos/login.php");
    exit();
}

if (isset($_SESSION['admin_id'])) {
    $admin_id   = $_SESSION['admin_id'];
    $admin_name = $_SESSION['admin_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'super_admin';
} else {
    $admin_id   = $_SESSION['user_id'];
    $admin_name = $_SESSION['user_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

// Only admin roles may access this page
if (!in_array($admin_role, ['super_admin', 'admin'])) {
    header("Location: index.php");
    exit();
}

$school_id       = SCHOOL_ID;
$school_name     = SCHOOL_NAME;
$primary_color   = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;

// ── Are we editing an existing record? ───────────────────────────────────────
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$record  = null;

// ── Fetch supporting data ─────────────────────────────────────────────────────
$classes        = [];
$existing_sessions = [];
$success_message   = '';
$error_message     = '';

try {
    // Active classes for this school (using 'classes' table, NOT 'school_classes')
    $stmt = $pdo->prepare(
        "SELECT class_name FROM classes
          WHERE school_id = ? AND status = 'active'
          ORDER BY sort_order ASC, class_name ASC"
    );
    $stmt->execute([$school_id]);
    $classes = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Distinct academic years already used
    $stmt = $pdo->prepare(
        "SELECT DISTINCT session FROM report_card_settings
          WHERE school_id = ?
          ORDER BY session DESC LIMIT 10"
    );
    $stmt->execute([$school_id]);
    $existing_sessions = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Load record if editing
    if ($edit_id > 0) {
        $stmt = $pdo->prepare(
            "SELECT * FROM report_card_settings WHERE id = ? AND school_id = ?"
        );
        $stmt->execute([$edit_id, $school_id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$record) {
            $error_message = "Exam record not found.";
            $edit_id = 0;
        }
    }
} catch (Exception $e) {
    error_log("exam_record_setup.php fetch error: " . $e->getMessage());
    $error_message = "Error loading page data. Please try again.";
}

// ── Grading system presets ────────────────────────────────────────────────────
$grading_presets = [
    'simple' => [
        ['grade' => 'A', 'min' => 75, 'max' => 100, 'remark' => 'Excellent'],
        ['grade' => 'B', 'min' => 65, 'max' => 74,  'remark' => 'Very Good'],
        ['grade' => 'C', 'min' => 50, 'max' => 64,  'remark' => 'Good'],
        ['grade' => 'D', 'min' => 40, 'max' => 49,  'remark' => 'Pass'],
        ['grade' => 'F', 'min' => 0,  'max' => 39,  'remark' => 'Fail'],
    ],
    'waec' => [
        ['grade' => 'A1', 'min' => 75, 'max' => 100, 'remark' => 'Excellent'],
        ['grade' => 'B2', 'min' => 70, 'max' => 74,  'remark' => 'Very Good'],
        ['grade' => 'B3', 'min' => 65, 'max' => 69,  'remark' => 'Good'],
        ['grade' => 'C4', 'min' => 60, 'max' => 64,  'remark' => 'Credit'],
        ['grade' => 'C5', 'min' => 55, 'max' => 59,  'remark' => 'Credit'],
        ['grade' => 'C6', 'min' => 50, 'max' => 54,  'remark' => 'Credit'],
        ['grade' => 'D7', 'min' => 45, 'max' => 49,  'remark' => 'Pass'],
        ['grade' => 'E8', 'min' => 40, 'max' => 44,  'remark' => 'Pass'],
        ['grade' => 'F9', 'min' => 0,  'max' => 39,  'remark' => 'Fail'],
    ],
    'american' => [
        ['grade' => 'A',  'min' => 90, 'max' => 100, 'remark' => 'Excellent (4.0)'],
        ['grade' => 'A-', 'min' => 85, 'max' => 89,  'remark' => 'Very Good (3.7)'],
        ['grade' => 'B+', 'min' => 80, 'max' => 84,  'remark' => 'Good (3.3)'],
        ['grade' => 'B',  'min' => 75, 'max' => 79,  'remark' => 'Good (3.0)'],
        ['grade' => 'B-', 'min' => 70, 'max' => 74,  'remark' => 'Above Average (2.7)'],
        ['grade' => 'C+', 'min' => 65, 'max' => 69,  'remark' => 'Average (2.3)'],
        ['grade' => 'C',  'min' => 60, 'max' => 64,  'remark' => 'Average (2.0)'],
        ['grade' => 'D',  'min' => 50, 'max' => 59,  'remark' => 'Pass (1.0)'],
        ['grade' => 'F',  'min' => 0,  'max' => 49,  'remark' => 'Fail (0.0)'],
    ],
];

// ── Handle form POST ──────────────────────────────────────────────────────────
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])
    && $_POST['action'] === 'save_exam_record'
) {

    // ── Sanitise inputs ───────────────────────────────────────────────────────
    $record_name     = trim($_POST['record_name'] ?? '');
    $session         = trim($_POST['session'] ?? '');
    $term            = $_POST['term'] ?? '';
    $class           = trim($_POST['class'] ?? '');
    $template        = $_POST['template'] ?? 'classic';
    $grading_system  = $_POST['grading_system'] ?? 'simple';
    $save_as         = $_POST['save_as'] ?? 'draft';
    $default_class_teacher = trim($_POST['default_class_teacher_name'] ?? '');

    // Dates
    $resumption_date      = $_POST['current_resumption_date'] ?? null;
    $closing_date         = $_POST['current_closing_date']   ?? null;
    $next_resumption      = $_POST['next_resumption_date']   ?? null;
    $days_opened          = (int)($_POST['days_school_opened'] ?? 62);

    // Toggles
    $show_class_pos       = isset($_POST['show_class_position'])     ? 1 : 0;
    $show_subject_pos     = isset($_POST['show_subject_position'])   ? 1 : 0;
    $show_promoted        = isset($_POST['show_promoted_to'])        ? 1 : 0;
    $show_cum_avg         = isset($_POST['show_cumulative_avg'])     ? 1 : 0;
    $show_low_high_avg    = isset($_POST['show_lowest_highest_avg']) ? 1 : 0;
    $show_low_high_class  = isset($_POST['show_lowest_highest_class']) ? 1 : 0;
    $sequential_pos       = isset($_POST['sequential_positions'])    ? 1 : 0;
    $show_attendance      = isset($_POST['show_attendance'])         ? 1 : 0;
    $show_affective       = isset($_POST['show_affective_traits'])   ? 1 : 0;
    $show_psychomotor     = isset($_POST['show_psychomotor'])        ? 1 : 0;

    // ── Score types ───────────────────────────────────────────────────────────
    $score_labels = $_POST['score_label'] ?? [];
    $score_maxes  = $_POST['score_max']   ?? [];
    $score_types  = [];
    $max_score    = 0;

    foreach ($score_labels as $i => $label) {
        $label = trim($label);
        $max   = (int)($score_maxes[$i] ?? 0);
        if ($label !== '' && $max > 0) {
            $score_types[] = ['label' => $label, 'max' => $max];
            $max_score    += $max;
        }
    }

    // ── Grading rows ──────────────────────────────────────────────────────────
    $grading_data = [];
    if ($grading_system === 'custom' || isset($_POST['grade_letter'])) {
        $grade_letters  = $_POST['grade_letter']  ?? [];
        $grade_mins     = $_POST['grade_min']      ?? [];
        $grade_maxes_g  = $_POST['grade_max']      ?? [];
        $grade_remarks  = $_POST['grade_remark']   ?? [];
        foreach ($grade_letters as $i => $letter) {
            $letter = trim($letter);
            if ($letter !== '') {
                $grading_data[] = [
                    'grade'  => $letter,
                    'min'    => (int)($grade_mins[$i]    ?? 0),
                    'max'    => (int)($grade_maxes_g[$i] ?? 100),
                    'remark' => trim($grade_remarks[$i]  ?? ''),
                ];
            }
        }
    } else {
        $grading_data = $grading_presets[$grading_system]
            ?? $grading_presets['simple'];
    }

    // ── Principal comments per grade ──────────────────────────────────────────
    $principal_grades = $_POST['principal_grade'] ?? [];
    $principal_comments = $_POST['principal_comment'] ?? [];
    $principal_comments_map = [];
    foreach ($principal_grades as $idx => $grade) {
        $grade = trim($grade);
        if ($grade !== '') {
            $principal_comments_map[$grade] = trim($principal_comments[$idx] ?? '');
        }
    }
    $principal_comments_json = json_encode($principal_comments_map);

    // ── Validation ────────────────────────────────────────────────────────────
    $errors = [];
    if ($record_name === '')  $errors[] = "Record name is required.";
    if ($session === '')      $errors[] = "Academic year / session is required.";
    if (!in_array($term, ['First', 'Second', 'Third'])) $errors[] = "Please select a valid term.";
    if ($class === '')        $errors[] = "Please select a class.";
    if (empty($score_types))  $errors[] = "At least one score type is required.";
    if ($max_score !== 100)   $errors[] = "Score types must add up to exactly 100 (currently {$max_score}).";
    if (empty($grading_data)) $errors[] = "Grading scale cannot be empty.";

    if (empty($errors)) {
        $combined = json_encode([
            'score_types'   => $score_types,
            'grading_scale' => $grading_data,
        ]);

        try {
            $status = ($save_as === 'active') ? 'active' : 'draft';

            if ($edit_id > 0) {
                // ── UPDATE existing record ────────────────────────────────────
                $stmt = $pdo->prepare("
                    UPDATE report_card_settings SET
                        record_name              = ?,
                        session                  = ?,
                        term                     = ?,
                        class                    = ?,
                        template                 = ?,
                        max_score                = ?,
                        score_types              = ?,
                        grading_system           = ?,
                        default_class_teacher_name = ?,
                        principal_comments_per_grade = ?,
                        current_resumption_date  = ?,
                        current_closing_date     = ?,
                        next_resumption_date     = ?,
                        days_school_opened       = ?,
                        show_class_position      = ?,
                        show_subject_position    = ?,
                        show_promoted_to         = ?,
                        show_cumulative_avg      = ?,
                        show_lowest_highest_avg  = ?,
                        show_lowest_highest_class= ?,
                        sequential_positions     = ?,
                        show_attendance          = ?,
                        show_affective_traits    = ?,
                        show_psychomotor         = ?,
                        status                   = ?,
                        updated_at               = NOW()
                    WHERE id = ? AND school_id = ?
                ");
                $stmt->execute([
                    $record_name,
                    $session,
                    $term,
                    $class,
                    $template,
                    $max_score,
                    $combined,
                    $grading_system,
                    $default_class_teacher,
                    $principal_comments_json,
                    $resumption_date ?: null,
                    $closing_date ?: null,
                    $next_resumption ?: null,
                    $days_opened,
                    $show_class_pos,
                    $show_subject_pos,
                    $show_promoted,
                    $show_cum_avg,
                    $show_low_high_avg,
                    $show_low_high_class,
                    $sequential_pos,
                    $show_attendance,
                    $show_affective,
                    $show_psychomotor,
                    $status,
                    $edit_id,
                    $school_id
                ]);
                $record_id = $edit_id;
                $success_message = "Exam record updated successfully.";
            } else {
                // ── INSERT new record ─────────────────────────────────────────
                $stmt = $pdo->prepare("
                    SELECT id FROM report_card_settings
                     WHERE school_id = ? AND session = ? AND term = ? AND class = ?
                     LIMIT 1
                ");
                $stmt->execute([$school_id, $session, $term, $class]);
                $existing = $stmt->fetch();

                if ($existing) {
                    $error_message = "An exam record for <strong>{$class} — {$term} Term — {$session}</strong> already exists. 
                                      <a href='exam_record_setup.php?edit={$existing['id']}' class='alert-link'>Edit it instead →</a>";
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO report_card_settings
                            (school_id, record_name, session, term, class, template,
                             max_score, score_types, grading_system,
                             default_class_teacher_name, principal_comments_per_grade,
                             current_resumption_date, current_closing_date,
                             next_resumption_date, days_school_opened,
                             show_class_position, show_subject_position,
                             show_promoted_to, show_cumulative_avg,
                             show_lowest_highest_avg, show_lowest_highest_class,
                             sequential_positions, show_attendance,
                             show_affective_traits, show_psychomotor,
                             status, created_by, created_at, updated_at)
                        VALUES
                            (?, ?, ?, ?, ?, ?,
                             ?, ?, ?,
                             ?, ?,
                             ?, ?,
                             ?, ?,
                             ?, ?,
                             ?, ?,
                             ?, ?,
                             ?, ?,
                             ?, ?,
                             ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([
                        $school_id,
                        $record_name,
                        $session,
                        $term,
                        $class,
                        $template,
                        $max_score,
                        $combined,
                        $grading_system,
                        $default_class_teacher,
                        $principal_comments_json,
                        $resumption_date ?: null,
                        $closing_date ?: null,
                        $next_resumption ?: null,
                        $days_opened,
                        $show_class_pos,
                        $show_subject_pos,
                        $show_promoted,
                        $show_cum_avg,
                        $show_low_high_avg,
                        $show_low_high_class,
                        $sequential_pos,
                        $show_attendance,
                        $show_affective,
                        $show_psychomotor,
                        $status,
                        $admin_id,
                    ]);

                    $record_id = $pdo->lastInsertId();
                    $success_message = "Exam record created successfully.";

                    // Activity log
                    try {
                        $pdo->prepare("
                            INSERT INTO activity_logs (user_id, user_type, activity, school_id)
                            VALUES (?, 'admin', ?, ?)
                        ")->execute([
                            $admin_id,
                            "Created exam record: {$record_name} ({$class} — {$term} Term)",
                            $school_id,
                        ]);
                    } catch (Exception $e) { /* non-fatal */
                    }
                }
            }

            // Redirect to score entry after saving if 'active'
            if ($status === 'active' && !empty($record_id) && empty($error_message)) {
                header("Location: exam_score_entry.php?record_id={$record_id}&created=1");
                exit();
            }
        } catch (Exception $e) {
            error_log("exam_record_setup.php save error: " . $e->getMessage());
            $error_message = "Database error: " . htmlspecialchars($e->getMessage());
        }
    } else {
        $error_message = implode('<br>', $errors);
    }
}

// ── Decode saved values when editing ─────────────────────────────────────────
$saved_score_types  = [];
$saved_grading      = [];
$saved_principal_comments = [];

if ($record) {
    $decoded = json_decode($record['score_types'] ?? '{}', true);
    if (isset($decoded['score_types'])) {
        $saved_score_types = $decoded['score_types'];
        $saved_grading     = $decoded['grading_scale'] ?? [];
    } else {
        $saved_score_types = $decoded ?? [];
    }
    if (empty($saved_grading)) {
        $gs = $record['grading_system'] ?? 'simple';
        $saved_grading = $grading_presets[$gs] ?? $grading_presets['simple'];
    }

    // Load principal comments
    if (!empty($record['principal_comments_per_grade'])) {
        $saved_principal_comments = json_decode($record['principal_comments_per_grade'], true) ?: [];
    }
}

// Defaults for new record
if (empty($saved_score_types)) {
    $saved_score_types = [
        ['label' => 'CA 1 (Test)',    'max' => 20],
        ['label' => 'CA 2 (Assignment)', 'max' => 10],
        ['label' => 'Exam Score',     'max' => 70],
    ];
}
if (empty($saved_grading)) {
    $saved_grading = $grading_presets['simple'];
}

$page_title = $edit_id > 0 ? "Edit Exam Record" : "Create Exam Record";
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $school_name; ?> — <?php echo $page_title; ?></title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: <?php echo $secondary_color; ?>;
            --accent-color: #e74c3c;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --sidebar-width: 260px;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.1);
            --radius-sm: 8px;
            --radius-md: 12px;
            --transition: all 0.3s ease;
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
            overflow-x: hidden;
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
            transition: transform 0.3s ease;
            z-index: 1000;
            overflow-y: auto;
            transform: translateX(-100%);
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            width: 44px;
            height: 44px;
            background: var(--secondary-color);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .logo-text h3 {
            font-size: 1rem;
            font-weight: 600;
        }

        .logo-text p {
            font-size: 0.7rem;
            opacity: 0.8;
        }

        .admin-info {
            text-align: center;
            padding: 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            margin: 15px;
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
            transition: var(--transition);
        }

        .nav-links a:hover {
            background: rgba(255, 255, 255, 0.15);
        }

        .nav-links a.active {
            background: rgba(255, 255, 255, 0.2);
            border-left: 3px solid var(--secondary-color);
        }

        .nav-links i {
            width: 20px;
            text-align: center;
        }

        /* Layout */
        .mobile-menu-toggle {
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            width: 44px;
            height: 44px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 20px;
            cursor: pointer;
        }

        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .sidebar-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .main-content {
            min-height: 100vh;
            padding: 20px;
            transition: var(--transition);
        }

        .top-header {
            background: white;
            padding: 20px;
            border-radius: var(--radius-md);
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: var(--shadow-sm);
        }

        .top-header h1 {
            color: var(--primary-color);
            font-size: 1.5rem;
            margin-bottom: 4px;
        }

        .top-header p {
            color: #666;
            font-size: 0.85rem;
        }

        .back-btn {
            background: white;
            border: 1px solid var(--light-color);
            color: var(--primary-color);
            padding: 9px 18px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.85rem;
            text-decoration: none;
        }

        .alert {
            padding: 14px 18px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            font-size: 0.88rem;
            display: flex;
            align-items: flex-start;
            gap: 10px;
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

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid #17a2b8;
        }

        .alert-link {
            color: inherit;
            font-weight: 600;
        }

        .alert i {
            margin-top: 2px;
            flex-shrink: 0;
        }

        /* Step bar */
        .step-bar {
            display: flex;
            background: white;
            border-radius: var(--radius-md);
            padding: 16px 20px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
            overflow-x: auto;
            gap: 0;
        }

        .step-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 80px;
            position: relative;
        }

        .step-item:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 14px;
            left: 50%;
            width: 100%;
            height: 2px;
            background: var(--light-color);
            z-index: 0;
        }

        .step-circle {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
            z-index: 1;
            position: relative;
            border: 2px solid transparent;
        }

        .step-circle.done {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .step-circle.current {
            background: #fff;
            color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .step-circle.todo {
            background: var(--light-color);
            color: #999;
            border-color: var(--light-color);
        }

        .step-label {
            font-size: 10px;
            color: #999;
            margin-top: 5px;
            text-align: center;
        }

        /* Form cards */
        .form-card {
            background: white;
            border-radius: var(--radius-md);
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
        }

        .form-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 14px;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--light-color);
        }

        .form-card-header h2 {
            font-size: 1rem;
            color: var(--primary-color);
            font-weight: 600;
        }

        .form-card-header .card-icon {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-sm);
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        /* Form elements */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }

        .form-row-3 {
            grid-template-columns: 1fr 1fr 1fr;
        }

        .form-row-4 {
            grid-template-columns: 1fr 1fr 1fr 1fr;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        label {
            font-size: 0.8rem;
            font-weight: 500;
            color: #555;
        }

        input[type="text"],
        input[type="number"],
        input[type="date"],
        select,
        textarea {
            padding: 10px 12px;
            border: 1.5px solid #e0e0e0;
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 0.88rem;
            color: #333;
            background: #fafafa;
            transition: border-color 0.2s;
            width: 100%;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            background: #fff;
        }

        textarea {
            resize: vertical;
        }

        /* Template chooser */
        .template-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 12px;
        }

        .tpl-option {
            position: relative;
        }

        .tpl-option input[type="radio"] {
            display: none;
        }

        .tpl-label-wrap {
            display: flex;
            flex-direction: column;
            cursor: pointer;
            border: 2px solid #e0e0e0;
            border-radius: var(--radius-sm);
            overflow: hidden;
            transition: border-color 0.2s;
        }

        .tpl-option input[type="radio"]:checked+.tpl-label-wrap {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0, 80, 160, 0.1);
        }

        .tpl-preview {
            height: 76px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }

        .tpl-classic .tpl-preview {
            background: #1a3c5e;
        }

        .tpl-modern .tpl-preview {
            background: linear-gradient(135deg, #2d6a4f, #1a3c5e);
        }

        .tpl-vibrant .tpl-preview {
            background: linear-gradient(135deg, #c0392b, #8e44ad);
        }

        .tpl-minimal .tpl-preview {
            background: #2c3e50;
        }

        .tpl-elegant .tpl-preview {
            background: linear-gradient(135deg, #b8860b, #8b4513);
        }

        .tpl-name {
            font-size: 11px;
            font-weight: 500;
            padding: 7px;
            text-align: center;
            background: white;
            color: #333;
        }

        /* Score builder */
        .score-builder {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .score-row {
            display: grid;
            grid-template-columns: 1fr 110px auto;
            gap: 10px;
            align-items: center;
        }

        .score-total-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 14px;
            background: #f5f6fa;
            border-radius: var(--radius-sm);
            margin-top: 8px;
            font-size: 0.88rem;
        }

        .score-sum {
            font-size: 1.1rem;
            font-weight: 700;
        }

        .score-sum.ok {
            color: var(--success-color);
        }

        .score-sum.error {
            color: var(--danger-color);
        }

        /* Grading table */
        .grading-select-row {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 16px;
        }

        .grading-select-row select {
            max-width: 260px;
        }

        .grading-table {
            width: 100%;
            border-collapse: collapse;
            font
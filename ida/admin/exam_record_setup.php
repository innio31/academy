<?php
// ida/admin/exam_record_setup.php - Create / Edit Exam Record Setup


error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../includes/config.php';

// ── Auth check ────────────────────────────────────────────────────────────────
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /ida/login.php");
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
            font-size: 0.85rem;
        }

        .grading-table th {
            background: var(--light-color);
            padding: 9px 12px;
            font-weight: 600;
            text-align: left;
            border-bottom: 2px solid #ddd;
            font-size: 0.82rem;
        }

        .grading-table td {
            padding: 7px 10px;
            border-bottom: 1px solid #eee;
        }

        .grading-table td input,
        .grading-table td textarea {
            padding: 5px 8px;
            font-size: 0.82rem;
        }

        /* Toggle switches */
        .toggle-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .toggle-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 14px;
            background: #f9f9f9;
            border-radius: var(--radius-sm);
            border: 1px solid #eee;
        }

        .toggle-label {
            font-size: 0.83rem;
            color: #444;
        }

        .switch {
            position: relative;
            width: 38px;
            height: 20px;
            flex-shrink: 0;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
            position: absolute;
        }

        .slider {
            position: absolute;
            inset: 0;
            background: #ccc;
            border-radius: 20px;
            cursor: pointer;
            transition: background 0.2s;
        }

        .slider::before {
            content: '';
            position: absolute;
            width: 14px;
            height: 14px;
            left: 3px;
            top: 3px;
            background: white;
            border-radius: 50%;
            transition: transform 0.2s;
        }

        .switch input:checked+.slider {
            background: var(--primary-color);
        }

        .switch input:checked+.slider::before {
            transform: translateX(18px);
        }

        /* Buttons */
        .btn {
            padding: 10px 20px;
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 0.88rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: var(--transition);
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            opacity: 0.9;
        }

        .btn-secondary {
            background: white;
            color: var(--primary-color);
            border: 1.5px solid var(--primary-color);
        }

        .btn-secondary:hover {
            background: var(--primary-color);
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.8rem;
        }

        .btn-icon {
            width: 34px;
            height: 34px;
            padding: 0;
            justify-content: center;
            border: 1px solid #e0e0e0;
            background: white;
            color: var(--danger-color);
            border-radius: var(--radius-sm);
        }

        .btn-icon:hover {
            background: var(--danger-color);
            color: white;
        }

        /* Action bar */
        .action-bar {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            padding: 20px 0;
            flex-wrap: wrap;
        }

        small {
            font-size: 0.7rem;
            color: #888;
        }

        /* Responsive */
        @media (min-width: 768px) {

            .mobile-menu-toggle,
            .sidebar-overlay {
                display: none;
            }

            .sidebar {
                transform: translateX(0);
            }

            .main-content {
                margin-left: var(--sidebar-width);
            }
        }

        @media (max-width: 767px) {
            .main-content {
                padding-top: 70px;
            }

            .form-row,
            .form-row-3,
            .form-row-4 {
                grid-template-columns: 1fr;
            }

            .template-grid {
                grid-template-columns: repeat(3, 1fr);
            }

            .toggle-grid {
                grid-template-columns: 1fr;
            }

            .score-row {
                grid-template-columns: 1fr 80px auto;
            }
        }
    </style>
</head>

<body>

    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
                <div class="logo-text">
                    <h3><?php echo htmlspecialchars($school_name); ?></h3>
                    <p>Admin Panel</p>
                </div>
            </div>
        </div>
        <div class="admin-info">
            <h4><?php echo htmlspecialchars($admin_name); ?></h4>
            <p><?php echo ucfirst(str_replace('_', ' ', $admin_role)); ?></p>
        </div>
        <div class="sidebar-content">
            <ul class="nav-links">
                <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="manage-students.php"><i class="fas fa-users"></i> Manage Students</a></li>
                <li><a href="manage-staff.php"><i class="fas fa-chalkboard-teacher"></i> Manage Staff</a></li>
                <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Manage Subjects</a></li>
                <li><a href="manage-classes.php"><i class="fas fa-book"></i> Manage Classes</a></li>
                <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
                <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
                <li><a href="attendance.php"><i class="fas fa-calendar-check"></i> Attendance Reports</a></li>
                <li><a href="report_card_dashboard.php" class="active"><i class="fas fa-file-invoice"></i> Process Results</a></li>
                <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
                <li><a href="sync.php"><i class="fas fa-sync-alt"></i> Sync to Cloud</a></li>
                <li><a href="/ida/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
    </div>

    <!-- Main content -->
    <div class="main-content" id="mainContent">

        <div class="top-header">
            <div>
                <h1><?php echo $page_title; ?></h1>
                <p><?php echo $edit_id > 0
                        ? "Editing: " . htmlspecialchars($record['record_name'] ?? '')
                        : "Set up a new exam record — template, scoring, grading, and options"; ?>
                </p>
            </div>
            <a href="report_card_dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to dashboard
            </a>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo $success_message; ?></span>
            </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <span><?php echo $error_message; ?></span>
            </div>
        <?php endif; ?>

        <!-- Step progress bar -->
        <div class="step-bar">
            <div class="step-item">
                <div class="step-circle current">1</div>
                <div class="step-label">Setup record</div>
            </div>
            <div class="step-item">
                <div class="step-circle todo">2</div>
                <div class="step-label">Enter scores</div>
            </div>
            <div class="step-item">
                <div class="step-circle todo">3</div>
                <div class="step-label">Traits &amp; comments</div>
            </div>
            <div class="step-item">
                <div class="step-circle todo">4</div>
                <div class="step-label">Generate cards</div>
            </div>
            <div class="step-item">
                <div class="step-circle todo">5</div>
                <div class="step-label">Publish</div>
            </div>
        </div>

        <form method="POST" id="setupForm">
            <input type="hidden" name="action" value="save_exam_record">

            <!-- Template selection -->
            <div class="form-card">
                <div class="form-card-header">
                    <div class="card-icon"><i class="fas fa-palette"></i></div>
                    <h2>Report card template</h2>
                </div>
                <div class="template-grid">
                    <?php
                    $templates = ['classic' => 'Classic', 'modern' => 'Modern', 'vibrant' => 'Vibrant', 'minimal' => 'Minimal', 'elegant' => 'Elegant'];
                    $selected_tpl = $record['template'] ?? 'classic';
                    $tpl_icons = [
                        'classic' => '<div style="width:30px;height:4px;background:rgba(255,255,255,0.5);border-radius:2px;margin-bottom:5px"></div><div style="width:42px;height:34px;border:1.5px solid rgba(255,255,255,0.4);border-radius:3px"></div>',
                        'modern'  => '<div style="width:20px;height:20px;background:rgba(255,255,255,0.25);border-radius:50%;margin-bottom:4px"></div><div style="width:36px;height:2px;background:rgba(255,255,255,0.5);border-radius:2px"></div>',
                        'vibrant' => '<div style="display:flex;gap:3px;align-items:flex-end"><div style="width:9px;height:30px;background:rgba(255,255,255,0.3);border-radius:2px"></div><div style="width:9px;height:22px;background:rgba(255,255,255,0.2);border-radius:2px"></div><div style="width:9px;height:26px;background:rgba(255,255,255,0.25);border-radius:2px"></div></div>',
                        'minimal' => '<div style="width:36px;height:1px;background:rgba(255,255,255,0.6)"></div><div style="width:26px;height:1px;background:rgba(255,255,255,0.35);margin-top:7px"></div><div style="width:30px;height:1px;background:rgba(255,255,255,0.35);margin-top:5px"></div>',
                        'elegant' => '<div style="width:30px;height:30px;border:2px solid rgba(255,255,255,0.5);border-radius:50%;display:flex;align-items:center;justify-content:center"><div style="width:13px;height:13px;background:rgba(255,255,255,0.4);border-radius:50%"></div></div>',
                    ];
                    foreach ($templates as $key => $label):
                    ?>
                        <div class="tpl-option tpl-<?php echo $key; ?>">
                            <input type="radio" name="template" id="tpl_<?php echo $key; ?>" value="<?php echo $key; ?>" <?php echo $selected_tpl === $key ? 'checked' : ''; ?>>
                            <label class="tpl-label-wrap" for="tpl_<?php echo $key; ?>">
                                <div class="tpl-preview"><?php echo $tpl_icons[$key]; ?></div>
                                <div class="tpl-name"><?php echo $label; ?></div>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Record details -->
            <div class="form-card">
                <div class="form-card-header">
                    <div class="card-icon"><i class="fas fa-info-circle"></i></div>
                    <h2>Exam record details</h2>
                </div>

                <div class="form-row" style="margin-bottom:16px">
                    <div class="form-group full">
                        <label for="record_name">Record name <span style="color:red">*</span></label>
                        <input type="text" id="record_name" name="record_name"
                            placeholder="e.g. 2024/2025 Second Term Examination — JSS 3"
                            value="<?php echo htmlspecialchars($record['record_name'] ?? ''); ?>" required>
                    </div>
                </div>

                <div class="form-row form-row-3">
                    <div class="form-group">
                        <label for="session">Academic year <span style="color:red">*</span></label>
                        <input type="text" id="session" name="session" placeholder="e.g. 2024/2025"
                            list="session_list" value="<?php echo htmlspecialchars($record['session'] ?? ''); ?>" required>
                        <datalist id="session_list">
                            <?php foreach ($existing_sessions as $s): ?>
                                <option value="<?php echo htmlspecialchars($s); ?>">
                                <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="form-group">
                        <label for="term">Term <span style="color:red">*</span></label>
                        <select id="term" name="term" required>
                            <option value="">— Select term —</option>
                            <?php foreach (['First', 'Second', 'Third'] as $t): ?>
                                <option value="<?php echo $t; ?>" <?php echo ($record['term'] ?? '') === $t ? 'selected' : ''; ?>>
                                    <?php echo $t; ?> Term
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="class">Class <span style="color:red">*</span></label>
                        <select id="class" name="class" required>
                            <option value="">— Select class —</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?php echo htmlspecialchars($c); ?>" <?php echo ($record['class'] ?? '') === $c ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Score settings -->
            <div class="form-card">
                <div class="form-card-header">
                    <div class="card-icon"><i class="fas fa-sliders-h"></i></div>
                    <h2>Score settings &nbsp;<small>— must total exactly 100</small></h2>
                </div>

                <div class="score-builder" id="scoreBuilder">
                    <?php foreach ($saved_score_types as $i => $st): ?>
                        <div class="score-row" id="scoreRow<?php echo $i; ?>">
                            <input type="text" name="score_label[]" placeholder="Score label e.g. CA 1 (Test)"
                                value="<?php echo htmlspecialchars($st['label']); ?>" oninput="recalcTotal()">
                            <input type="number" name="score_max[]" min="1" max="100"
                                value="<?php echo (int)$st['max']; ?>" oninput="recalcTotal()">
                            <button type="button" class="btn btn-icon" onclick="removeScoreRow(this)" title="Remove">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:12px;display:flex;align-items:center;gap:12px">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="addScoreRow()">
                        <i class="fas fa-plus"></i> Add score type
                    </button>
                </div>

                <div class="score-total-bar" style="margin-top:14px">
                    <span style="font-size:0.85rem;color:#555">Total obtainable score</span>
                    <div>
                        <span id="scoreSum" class="score-sum ok">100</span>
                        <span style="font-size:0.8rem;color:#999"> / 100</span>
                        <span id="scoreSumError" style="color:#e74c3c;font-size:0.78rem;margin-left:8px;display:none">
                            <i class="fas fa-exclamation-triangle"></i> Must equal 100
                        </span>
                    </div>
                </div>
            </div>

            <!-- Grading system -->
            <div class="form-card">
                <div class="form-card-header">
                    <div class="card-icon"><i class="fas fa-award"></i></div>
                    <h2>Grading system</h2>
                </div>

                <div class="grading-select-row">
                    <label for="grading_system">Grading scale:</label>
                    <select id="grading_system" name="grading_system" onchange="loadGradingPreset(this.value)">
                        <option value="simple" <?php echo ($record['grading_system'] ?? 'simple') === 'simple' ? 'selected' : ''; ?>>Simple letter grading (A – F)</option>
                        <option value="waec" <?php echo ($record['grading_system'] ?? '') === 'waec' ? 'selected' : ''; ?>>WAEC grading (A1 – F9)</option>
                        <option value="american" <?php echo ($record['grading_system'] ?? '') === 'american' ? 'selected' : ''; ?>>American GPA grading</option>
                        <option value="custom" <?php echo ($record['grading_system'] ?? '') === 'custom' ? 'selected' : ''; ?>>Custom (edit table below)</option>
                    </select>
                </div>

                <div style="overflow-x:auto">
                    <table class="grading-table" id="gradingTable">
                        <thead>
                            <tr>
                                <th style="width:90px">Grade</th>
                                <th style="width:110px">Min score</th>
                                <th style="width:110px">Max score</th>
                                <th>Remark</th>
                                <th style="width:50px"></th>
                            </tr>
                        </thead>
                        <tbody id="gradingBody">
                            <?php foreach ($saved_grading as $row): ?>
                                <tr>
                                    <td><input type="text" name="grade_letter[]" value="<?php echo htmlspecialchars($row['grade']); ?>" style="width:70px;text-align:center;font-weight:600"></td>
                                    <td><input type="number" name="grade_min[]" value="<?php echo (int)$row['min']; ?>" min="0" max="100"></td>
                                    <td><input type="number" name="grade_max[]" value="<?php echo (int)$row['max']; ?>" min="0" max="100"></td>
                                    <td><input type="text" name="grade_remark[]" value="<?php echo htmlspecialchars($row['remark']); ?>"></td>
                                    <td><button type="button" class="btn btn-icon btn-sm" onclick="this.closest('tr').remove()" title="Remove"><i class="fas fa-times"></i></button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top:10px">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="addGradeRow()">
                        <i class="fas fa-plus"></i> Add grade row
                    </button>
                </div>
            </div>

            <!-- Principal Comments by Grade & Default Class Teacher -->
            <div class="form-card">
                <div class="form-card-header">
                    <div class="card-icon"><i class="fas fa-comment-dots"></i></div>
                    <h2>Comments Setup</h2>
                </div>

                <div class="form-group full" style="margin-bottom: 20px;">
                    <label for="default_class_teacher_name">Default Class Teacher Name</label>
                    <input type="text" id="default_class_teacher_name" name="default_class_teacher_name"
                        placeholder="e.g. Mrs. Oluwaseun Adebayo"
                        value="<?php echo htmlspecialchars($record['default_class_teacher_name'] ?? ''); ?>">
                    <small>This name will be auto-filled for all students in the traits & comments page</small>
                </div>

                <div class="alert alert-info" style="margin-bottom: 15px;">
                    <i class="fas fa-info-circle"></i>
                    <span>Set principal's comments for each grade. These will automatically appear for students who achieve that grade.</span>
                </div>

                <div style="overflow-x:auto">
                    <table class="grading-table" id="principalCommentsTable">
                        <thead>
                            <tr>
                                <th style="width:100px">Grade</th>
                                <th>Principal's Comment (auto for students with this grade)</th>
                            </tr>
                        </thead>
                        <tbody id="principalCommentsBody">
                            <?php foreach ($saved_grading as $idx => $grade_row):
                                $grade_letter = $grade_row['grade'];
                                $comment = $saved_principal_comments[$grade_letter] ?? '';
                            ?>
                                <tr data-grade="<?php echo htmlspecialchars($grade_letter); ?>">
                                    <td style="text-align:center; font-weight:600;">
                                        <?php echo htmlspecialchars($grade_letter); ?>
                                        <input type="hidden" name="principal_grade[]" value="<?php echo htmlspecialchars($grade_letter); ?>">
                                    </td>
                                    <td>
                                        <textarea name="principal_comment[]" rows="2"
                                            placeholder="e.g. Excellent performance! Keep up the good work."
                                            style="width:100%;"><?php echo htmlspecialchars($comment); ?></textarea>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <small style="display:block; margin-top:10px;">
                    <i class="fas fa-info-circle"></i>
                    When generating report cards, the principal's comment will be automatically selected based on the student's overall grade.
                </small>
            </div>

            <!-- Term dates -->
            <div class="form-card">
                <div class="form-card-header">
                    <div class="card-icon"><i class="fas fa-calendar-alt"></i></div>
                    <h2>Term dates</h2>
                </div>
                <div class="form-row form-row-4">
                    <div class="form-group">
                        <label for="current_resumption_date">Term resumption date</label>
                        <input type="date" id="current_resumption_date" name="current_resumption_date"
                            value="<?php echo htmlspecialchars($record['current_resumption_date'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="current_closing_date">Term closing date</label>
                        <input type="date" id="current_closing_date" name="current_closing_date"
                            value="<?php echo htmlspecialchars($record['current_closing_date'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="next_resumption_date">Next term resumption</label>
                        <input type="date" id="next_resumption_date" name="next_resumption_date"
                            value="<?php echo htmlspecialchars($record['next_resumption_date'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="days_school_opened">Days school opened</label>
                        <input type="number" id="days_school_opened" name="days_school_opened"
                            min="1" max="300" value="<?php echo (int)($record['days_school_opened'] ?? 62); ?>">
                    </div>
                </div>
            </div>

            <!-- Display options -->
            <div class="form-card">
                <div class="form-card-header">
                    <div class="card-icon"><i class="fas fa-cog"></i></div>
                    <h2>Report card display options</h2>
                </div>

                <?php
                $toggles = [
                    'show_class_position'      => ['label' => 'Show class position', 'default' => 1],
                    'show_subject_position'    => ['label' => 'Show subject position', 'default' => 1],
                    'show_promoted_to'         => ['label' => 'Show "promoted to" next class', 'default' => 1],
                    'show_cumulative_avg'      => ['label' => 'Show cumulative average', 'default' => 1],
                    'show_lowest_highest_avg'  => ['label' => 'Show lowest & highest average', 'default' => 1],
                    'show_lowest_highest_class' => ['label' => 'Show lowest & highest in class', 'default' => 0],
                    'sequential_positions'     => ['label' => 'Sequential positions (1st, 1st, 2nd)', 'default' => 0],
                    'show_attendance'          => ['label' => 'Show attendance record', 'default' => 1],
                    'show_affective_traits'    => ['label' => 'Show affective traits', 'default' => 1],
                    'show_psychomotor'         => ['label' => 'Show psychomotor skills', 'default' => 1],
                ];
                ?>
                <div class="toggle-grid">
                    <?php foreach ($toggles as $name => $cfg):
                        $checked = $record ? (int)($record[$name] ?? $cfg['default']) : $cfg['default'];
                    ?>
                        <div class="toggle-row">
                            <span class="toggle-label"><?php echo htmlspecialchars($cfg['label']); ?></span>
                            <label class="switch">
                                <input type="checkbox" name="<?php echo $name; ?>" value="1" <?php echo $checked ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Action bar -->
            <div class="action-bar">
                <button type="submit" name="save_as" value="draft" class="btn btn-secondary">
                    <i class="fas fa-save"></i>
                    <?php echo $edit_id > 0 ? 'Update draft' : 'Save as draft'; ?>
                </button>
                <button type="submit" name="save_as" value="active" class="btn btn-primary">
                    <i class="fas fa-arrow-right"></i>
                    <?php echo $edit_id > 0 ? 'Update & go to scores' : 'Save & proceed to score entry'; ?>
                </button>
            </div>

        </form>

        <!-- Existing exam records -->
        <?php
        try {
            $stmt = $pdo->prepare("
                SELECT id, record_name, session, term, class, template, status, created_at, updated_at
                FROM report_card_settings WHERE school_id = ? ORDER BY created_at DESC LIMIT 20
            ");
            $stmt->execute([$school_id]);
            $all_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $all_records = [];
        }
        if (!empty($all_records)):
        ?>
            <div class="form-card">
                <div class="form-card-header">
                    <div class="card-icon"><i class="fas fa-list"></i></div>
                    <h2>Exam records for this school</h2>
                </div>
                <div style="overflow-x:auto">
                    <table class="grading-table">
                        <thead>
                            <tr>
                                <th>Record name</th>
                                <th>Session</th>
                                <th>Term</th>
                                <th>Class</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_records as $r):
                                $badge_class = 'badge-' . ($r['status'] ?? 'draft');
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($r['record_name'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($r['session']); ?></td>
                                    <td><?php echo htmlspecialchars($r['term']); ?> Term</td>
                                    <td><?php echo htmlspecialchars($r['class']); ?></td>
                                    <td><span class="status-badge" style="padding:3px 10px;border-radius:20px;font-size:0.72rem;background:#ecf0f1;"><?php echo ucfirst($r['status'] ?? 'draft'); ?></span></td>
                                    <td><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
                                    <td>
                                        <div style="display:flex;gap:6px">
                                            <a href="exam_record_setup.php?edit=<?php echo $r['id']; ?>" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i></a>
                                            <a href="exam_score_entry.php?record_id=<?php echo $r['id']; ?>" class="btn btn-primary btn-sm"><i class="fas fa-pencil-alt"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div style="text-align:center;padding:20px;color:#999;font-size:0.8rem;border-top:1px solid var(--light-color);margin-top:10px">
            &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> — Online Portal
        </div>

    </div>

    <script>
        const GRADING_PRESETS = <?php echo json_encode($grading_presets); ?>;

        // Sidebar
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const menuToggle = document.getElementById('mobileMenuToggle');

        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        });
        sidebarOverlay.addEventListener('click', () => {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
            document.body.style.overflow = '';
        });

        // Score total calculator
        function recalcTotal() {
            const maxInputs = document.querySelectorAll('#scoreBuilder input[name="score_max[]"]');
            let total = 0;
            maxInputs.forEach(i => total += parseInt(i.value) || 0);
            const sumEl = document.getElementById('scoreSum');
            const errEl = document.getElementById('scoreSumError');
            sumEl.textContent = total;
            sumEl.className = 'score-sum ' + (total === 100 ? 'ok' : 'error');
            errEl.style.display = total !== 100 ? 'inline' : 'none';
        }

        let scoreRowCount = <?php echo count($saved_score_types); ?>;

        function addScoreRow() {
            const builder = document.getElementById('scoreBuilder');
            const div = document.createElement('div');
            div.className = 'score-row';
            div.id = 'scoreRow' + scoreRowCount++;
            div.innerHTML = `<input type="text" name="score_label[]" placeholder="Score label e.g. CA 3" oninput="recalcTotal()">
                <input type="number" name="score_max[]" min="1" max="100" value="10" oninput="recalcTotal()">
                <button type="button" class="btn btn-icon" onclick="removeScoreRow(this)" title="Remove"><i class="fas fa-trash-alt"></i></button>`;
            builder.appendChild(div);
            recalcTotal();
        }

        function removeScoreRow(btn) {
            const rows = document.querySelectorAll('#scoreBuilder .score-row');
            if (rows.length <= 1) {
                alert('You must have at least one score type.');
                return;
            }
            btn.closest('.score-row').remove();
            recalcTotal();
        }

        function loadGradingPreset(system) {
            if (system === 'custom') return;
            const rows = GRADING_PRESETS[system] || GRADING_PRESETS['simple'];
            const tbody = document.getElementById('gradingBody');
            tbody.innerHTML = rows.map(r => `
                <tr>
                    <td><input type="text" name="grade_letter[]" value="${r.grade}" style="width:70px;text-align:center;font-weight:600"></td>
                    <td><input type="number" name="grade_min[]" value="${r.min}" min="0" max="100"></td>
                    <td><input type="number" name="grade_max[]" value="${r.max}" min="0" max="100"></td>
                    <td><input type="text" name="grade_remark[]" value="${r.remark}"></td>
                    <td><button type="button" class="btn btn-icon btn-sm" onclick="this.closest('tr').remove()"><i class="fas fa-times"></i></button></td>
                </tr>`).join('');

            // Also update principal comments table with new grades
            updatePrincipalCommentsTable(rows);
        }

        function updatePrincipalCommentsTable(grades) {
            const tbody = document.getElementById('principalCommentsBody');
            if (!tbody) return;
            tbody.innerHTML = grades.map(g => `
                <tr data-grade="${g.grade}">
                    <td style="text-align:center; font-weight:600;">
                        ${g.grade}
                        <input type="hidden" name="principal_grade[]" value="${g.grade}">
                    </td>
                    <td>
                        <textarea name="principal_comment[]" rows="2" 
                            placeholder="e.g. Excellent performance! Keep up the good work."
                            style="width:100%;"></textarea>
                    </td>
                </tr>`).join('');
        }

        function addGradeRow() {
            const tbody = document.getElementById('gradingBody');
            const tr = document.createElement('tr');
            tr.innerHTML = `<td><input type="text" name="grade_letter[]" placeholder="F+" style="width:70px;text-align:center;font-weight:600"></td>
                <td><input type="number" name="grade_min[]" value="0" min="0" max="100"></td>
                <td><input type="number" name="grade_max[]" value="49" min="0" max="100"></td>
                <td><input type="text" name="grade_remark[]" placeholder="Remark"></td>
                <td><button type="button" class="btn btn-icon btn-sm" onclick="this.closest('tr').remove()"><i class="fas fa-times"></i></button></td>`;
            tbody.appendChild(tr);

            // Also add to principal comments table
            const pcTbody = document.getElementById('principalCommentsBody');
            if (pcTbody) {
                const pcTr = document.createElement('tr');
                pcTr.innerHTML = `<td style="text-align:center; font-weight:600;">
                        F+
                        <input type="hidden" name="principal_grade[]" value="F+">
                    </td>
                    <td>
                        <textarea name="principal_comment[]" rows="2" 
                            placeholder="e.g. Excellent performance! Keep up the good work."
                            style="width:100%;"></textarea>
                    </td>`;
                pcTbody.appendChild(pcTr);
            }
        }

        // Form submit guard
        document.getElementById('setupForm').addEventListener('submit', function(e) {
            const maxInputs = document.querySelectorAll('#scoreBuilder input[name="score_max[]"]');
            let total = 0;
            maxInputs.forEach(i => total += parseInt(i.value) || 0);
            if (total !== 100) {
                e.preventDefault();
                document.getElementById('scoreSumError').style.display = 'inline';
                document.getElementById('scoreSum').scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
                alert(`Score types must add up to exactly 100. Current total: ${total}`);
            }
        });

        function autoFillName() {
            const nameEl = document.getElementById('record_name');
            const sessionEl = document.getElementById('session');
            const termEl = document.getElementById('term');
            const classEl = document.getElementById('class');
            if (nameEl.value.trim() !== '') return;
            const parts = [
                sessionEl.value.trim(),
                termEl.options[termEl.selectedIndex]?.text || '',
                classEl.options[classEl.selectedIndex]?.text || '',
                'Examination'
            ].filter(Boolean);
            if (parts.length >= 3) nameEl.value = parts.join(' — ');
        }
        document.getElementById('session')?.addEventListener('change', autoFillName);
        document.getElementById('term')?.addEventListener('change', autoFillName);
        document.getElementById('class')?.addEventListener('change', autoFillName);

        recalcTotal();
    </script>

</body>

</html>
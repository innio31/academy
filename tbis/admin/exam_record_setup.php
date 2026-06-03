<?php
// tbis/admin/exam_record_setup.php - Create / Edit Exam Record Setup (Class-first UX)

error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../includes/config.php';

// ── Auth check ────────────────────────────────────────────────────────────────
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /tbis/login.php");
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

// ── Get selected class from URL ───────────────────────────────────────────────
$selected_class = isset($_GET['class']) ? trim($_GET['class']) : '';
$selected_class_id = 0;

// ── Fetch all classes for this school ─────────────────────────────────────────
$classes = [];
try {
    $stmt = $pdo->prepare(
        "SELECT id, class_name FROM classes
          WHERE school_id = ? AND status = 'active'
          ORDER BY sort_order ASC, class_name ASC"
    );
    $stmt->execute([$school_id]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("exam_record_setup.php fetch classes error: " . $e->getMessage());
}

// If class is selected, get its ID
if (!empty($selected_class)) {
    foreach ($classes as $c) {
        if ($c['class_name'] === $selected_class) {
            $selected_class_id = $c['id'];
            break;
        }
    }
}

// ── Fetch exam records for selected class ─────────────────────────────────────
$class_records = [];
if (!empty($selected_class)) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, record_name, session, term, template, status, created_at, updated_at
            FROM report_card_settings 
            WHERE school_id = ? AND class = ? 
            ORDER BY session DESC, 
                     FIELD(term, 'First', 'Second', 'Third') ASC
        ");
        $stmt->execute([$school_id, $selected_class]);
        $class_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("exam_record_setup.php fetch records error: " . $e->getMessage());
    }
}

// ── Fetch existing sessions for dropdown ──────────────────────────────────────
$existing_sessions = [];
try {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT session FROM report_card_settings
          WHERE school_id = ?
          ORDER BY session DESC LIMIT 10"
    );
    $stmt->execute([$school_id]);
    $existing_sessions = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("exam_record_setup.php fetch sessions error: " . $e->getMessage());
}

// ── Flash messages ───────────────────────────────────────────────────────────
$success_message = '';
$error_message = '';

if (isset($_SESSION['flash_success'])) {
    $success_message = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error_message = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false, 'message' => '', 'record_id' => 0];
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_exam_record') {
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
            $grading_data = $grading_presets[$grading_system] ?? $grading_presets['simple'];
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
                $record_id = $_POST['record_id'] ?? 0;
                
                if ($record_id > 0) {
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
                        $record_name, $session, $term, $class, $template,
                        $max_score, $combined, $grading_system,
                        $default_class_teacher, $principal_comments_json,
                        $resumption_date ?: null, $closing_date ?: null,
                        $next_resumption ?: null, $days_opened,
                        $show_class_pos, $show_subject_pos, $show_promoted,
                        $show_cum_avg, $show_low_high_avg, $show_low_high_class,
                        $sequential_pos, $show_attendance, $show_affective,
                        $show_psychomotor, $status, $record_id, $school_id
                    ]);
                    $response['message'] = "Exam record updated successfully.";
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
                        $response['message'] = "An exam record for <strong>{$class} — {$term} Term — {$session}</strong> already exists.";
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
                            $school_id, $record_name, $session, $term, $class, $template,
                            $max_score, $combined, $grading_system,
                            $default_class_teacher, $principal_comments_json,
                            $resumption_date ?: null, $closing_date ?: null,
                            $next_resumption ?: null, $days_opened,
                            $show_class_pos, $show_subject_pos, $show_promoted,
                            $show_cum_avg, $show_low_high_avg, $show_low_high_class,
                            $sequential_pos, $show_attendance, $show_affective,
                            $show_psychomotor, $status, $admin_id
                        ]);
                        
                        $record_id = $pdo->lastInsertId();
                        $response['message'] = "Exam record created successfully.";
                        
                        // Activity log
                        try {
                            $pdo->prepare("
                                INSERT INTO activity_logs (user_id, user_type, activity, school_id)
                                VALUES (?, 'admin', ?, ?)
                            ")->execute([$admin_id, "Created exam record: {$record_name}", $school_id]);
                        } catch (Exception $e) { }
                    }
                }
                
                $response['success'] = true;
                $response['record_id'] = $record_id;
                $response['redirect'] = ($status === 'active' && $record_id) ? "exam_score_entry.php?record_id={$record_id}&created=1" : '';
                
            } catch (Exception $e) {
                $response['message'] = "Database error: " . htmlspecialchars($e->getMessage());
            }
        } else {
            $response['message'] = implode('<br>', $errors);
        }
        
        // Return JSON for AJAX
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode($response);
            exit();
        } else {
            if ($response['success'] && !empty($response['redirect'])) {
                header("Location: " . $response['redirect']);
                exit();
            } else {
                if ($response['success']) {
                    $_SESSION['flash_success'] = $response['message'];
                } else {
                    $_SESSION['flash_error'] = $response['message'];
                }
                header("Location: exam_record_setup.php" . (!empty($class) ? "?class=" . urlencode($class) : ""));
                exit();
            }
        }
    } elseif ($action === 'get_record') {
        // AJAX endpoint to get record data for editing
        $record_id = (int)$_POST['record_id'];
        $stmt = $pdo->prepare("SELECT * FROM report_card_settings WHERE id = ? AND school_id = ?");
        $stmt->execute([$record_id, $school_id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($record) {
            $decoded = json_decode($record['score_types'] ?? '{}', true);
            $score_types = $decoded['score_types'] ?? [];
            $grading_scale = $decoded['grading_scale'] ?? $grading_presets[$record['grading_system'] ?? 'simple'];
            $principal_comments = json_decode($record['principal_comments_per_grade'] ?? '{}', true) ?: [];
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'record' => $record,
                'score_types' => $score_types,
                'grading_scale' => $grading_scale,
                'principal_comments' => $principal_comments
            ]);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Record not found']);
        }
        exit();
    } elseif ($action === 'delete_record') {
        $record_id = (int)$_POST['record_id'];
        $stmt = $pdo->prepare("SELECT status FROM report_card_settings WHERE id = ? AND school_id = ?");
        $stmt->execute([$record_id, $school_id]);
        $record = $stmt->fetch();
        
        if ($record && in_array($record['status'], ['draft', 'active'])) {
            $stmt = $pdo->prepare("DELETE FROM report_card_settings WHERE id = ? AND school_id = ?");
            $stmt->execute([$record_id, $school_id]);
            $response = ['success' => true, 'message' => 'Record deleted successfully'];
        } else {
            $response = ['success' => false, 'message' => 'Cannot delete published/archived records'];
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
}

$page_title = "Exam Records Management";
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
            --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.12);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --transition: all 0.3s ease;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            color: #333;
            min-height: 100vh;
            overflow-x: hidden;
        }
        
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
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }
        
        .sidebar-overlay.active { opacity: 1; visibility: visible; }
        
        .main-content {
            min-height: 100vh;
            padding: 20px;
            transition: var(--transition);
        }
        
        @media (min-width: 768px) {
            .mobile-menu-toggle, .sidebar-overlay { display: none; }
            .main-content { margin-left: var(--sidebar-width); }
        }
        
        @media (max-width: 767px) { .main-content { padding-top: 70px; } }
        
        /* Header */
        .top-header {
            background: white;
            padding: 20px 24px;
            border-radius: var(--radius-md);
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: var(--shadow-sm);
        }
        
        .top-header h1 { color: var(--primary-color); font-size: 1.5rem; margin-bottom: 4px; }
        .top-header p { color: #666; font-size: 0.85rem; }
        
        .btn-create {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 10px 20px;
            border-radius: var(--radius-sm);
            border: none;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
        }
        
        .btn-create:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        
        /* Classes Grid */
        .classes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .class-card {
            background: white;
            border-radius: var(--radius-md);
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid transparent;
            box-shadow: var(--shadow-sm);
            position: relative;
            overflow: hidden;
        }
        
        .class-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }
        
        .class-card.selected {
            border-color: var(--primary-color);
            background: #f0f7ff;
        }
        
        .class-card.selected::before {
            content: '\f00c';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            top: 10px;
            right: 10px;
            color: var(--success-color);
            font-size: 14px;
        }
        
        .class-icon {
            width: 60px;
            height: 60px;
            background: var(--primary-color);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin: 0 auto 12px;
        }
        
        .class-card h3 { font-size: 1rem; margin-bottom: 5px; color: var(--dark-color); }
        .class-card p { font-size: 0.7rem; color: #888; }
        
        .record-count {
            display: inline-block;
            background: var(--light-color);
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            margin-top: 8px;
        }
        
        /* Records Section */
        .records-section {
            background: white;
            border-radius: var(--radius-md);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            margin-top: 20px;
        }
        
        .records-header {
            padding: 20px 24px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            background: var(--primary-color);
            color: white;
        }
        
        .records-header h2 { font-size: 1.2rem; }
        .records-header h2 i { margin-right: 8px; }
        
        .records-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .records-table th {
            background: #f8f9fa;
            padding: 12px 16px;
            text-align: left;
            font-size: 0.8rem;
            font-weight: 600;
            color: #666;
            border-bottom: 1px solid #eee;
        }
        
        .records-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 0.85rem;
        }
        
        .records-table tr:hover { background: #fafafa; }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
        }
        
        .status-active { background: #d4edda; color: #155724; }
        .status-draft { background: #e2e3e5; color: #383d41; }
        .status-published { background: #cce5ff; color: #004085; }
        
        .action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            width: 30px;
            height: 30px;
            border-radius: var(--radius-sm);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            font-size: 12px;
        }
        
        .action-btn.edit { background: #e3f2fd; color: #1976d2; }
        .action-btn.scores { background: #e8f5e9; color: #388e3c; }
        .action-btn.results { background: #fff3e0; color: #f57c00; }
        .action-btn.clone { background: #e0f7fa; color: #0097a7; }
        .action-btn.delete { background: #ffebee; color: #d32f2f; }
        .action-btn:hover { transform: scale(1.05); }
        
        .alert {
            padding: 14px 18px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid var(--success-color); }
        .alert-danger { background: #f8d7da; color: #721c24; border-left: 4px solid var(--danger-color); }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        
        .empty-state i { font-size: 48px; margin-bottom: 14px; opacity: 0.3; display: block; }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active { display: flex; }
        
        .modal-container {
            background: white;
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 800px;
            max-height: 85vh;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .modal-header {
            padding: 18px 24px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--primary-color);
            color: white;
        }
        
        .modal-header h3 { font-size: 1.1rem; font-weight: 500; }
        .modal-close { background: none; border: none; color: white; font-size: 20px; cursor: pointer; }
        
        .modal-body {
            padding: 24px;
            max-height: calc(85vh - 140px);
            overflow-y: auto;
        }
        
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: #fafafa;
        }
        
        /* Section List */
        .sections-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .section-item {
            background: #f8f9fa;
            border-radius: var(--radius-sm);
            border: 1px solid #e0e0e0;
            transition: var(--transition);
        }
        
        .section-item.completed {
            border-left: 4px solid var(--success-color);
            background: #f0f9f0;
        }
        
        .section-header {
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
        }
        
        .section-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .section-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-sm);
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        .section-icon.completed { background: var(--success-color); }
        
        .section-info h4 { font-size: 0.95rem; font-weight: 600; color: var(--dark-color); }
        .section-info p { font-size: 0.75rem; color: #888; margin-top: 2px; }
        
        .section-status {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .status-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #ffc107;
        }
        
        .status-indicator.completed { background: var(--success-color); }
        
        .section-content {
            display: none;
            padding: 20px;
            border-top: 1px solid #eee;
            background: white;
        }
        
        .section-content.active { display: block; }
        
        /* Form Elements */
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 0.8rem; font-weight: 500; color: #555; margin-bottom: 5px; }
        
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1.5px solid #e0e0e0;
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 0.85rem;
            transition: border-color 0.2s;
        }
        
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .form-row-3 { grid-template-columns: 1fr 1fr 1fr; }
        
        /* Template Grid */
        .template-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .tpl-option { cursor: pointer; }
        .tpl-option input { display: none; }
        
        .tpl-preview {
            border: 2px solid #e0e0e0;
            border-radius: var(--radius-sm);
            padding: 15px;
            text-align: center;
            transition: all 0.2s;
        }
        
        .tpl-option input:checked + .tpl-preview {
            border-color: var(--primary-color);
            background: #f0f7ff;
        }
        
        .tpl-preview i { font-size: 24px; margin-bottom: 8px; display: block; }
        .tpl-preview span { font-size: 0.75rem; }
        
        /* Score Builder */
        .score-row {
            display: grid;
            grid-template-columns: 1fr 100px 40px;
            gap: 10px;
            margin-bottom: 10px;
            align-items: center;
        }
        
        .score-total {
            margin-top: 15px;
            padding: 10px;
            background: #f5f6fa;
            border-radius: var(--radius-sm);
            text-align: right;
        }
        
        /* Toggle Switch */
        .toggle-switch {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        
        .toggle-switch label { margin-bottom: 0; }
        
        .switch {
            position: relative;
            width: 44px;
            height: 24px;
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
            border-radius: 24px;
            cursor: pointer;
            transition: 0.2s;
        }
        
        .slider:before {
            content: '';
            position: absolute;
            width: 18px;
            height: 18px;
            left: 3px;
            top: 3px;
            background: white;
            border-radius: 50%;
            transition: 0.2s;
        }
        
        .switch input:checked + .slider { background: var(--primary-color); }
        .switch input:checked + .slider:before { transform: translateX(20px); }
        
        /* Buttons */
        .btn {
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: var(--transition);
        }
        
        .btn-primary { background: var(--primary-color); color: white; }
        .btn-secondary { background: white; color: var(--primary-color); border: 1px solid var(--primary-color); }
        .btn-success { background: var(--success-color); color: white; }
        .btn-danger { background: var(--danger-color); color: white; }
        .btn-sm { padding: 6px 12px; font-size: 0.75rem; }
        .btn-icon { padding: 6px 10px; }
        
        .footer {
            text-align: center;
            padding: 20px;
            color: #999;
            font-size: 0.75rem;
            border-top: 1px solid var(--light-color);
            margin-top: 30px;
        }
        
        @media (max-width: 767px) {
            .form-row, .form-row-3, .template-grid {
                grid-template-columns: 1fr;
            }
            .classes-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .records-table {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>

<button class="mobile-menu-toggle" id="mobileMenuToggle">
    <i class="fas fa-bars"></i>
</button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php require_once 'includes/sidebar.php'; ?>

<div class="main-content" id="mainContent">
    
    <div class="top-header">
        <div>
            <h1><i class="fas fa-clipboard-list"></i> <?php echo $page_title; ?></h1>
            <p>Select a class to view or manage its exam records</p>
        </div>
        <?php if (!empty($selected_class)): ?>
            <a href="exam_record_setup.php" class="btn-create" style="background: #6c757d;">
                <i class="fas fa-arrow-left"></i> Back to All Classes
            </a>
        <?php endif; ?>
    </div>
    
    <?php if ($success_message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success_message; ?></div>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo $error_message; ?></div>
    <?php endif; ?>
    
    <?php if (empty($selected_class)): ?>
        <!-- Class Selection View -->
        <div class="classes-grid">
            <?php 
            // Get record counts per class
            $record_counts = [];
            try {
                $stmt = $pdo->prepare("
                    SELECT class, COUNT(*) as count FROM report_card_settings 
                    WHERE school_id = ? 
                    GROUP BY class
                ");
                $stmt->execute([$school_id]);
                $counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($counts as $c) {
                    $record_counts[$c['class']] = $c['count'];
                }
            } catch (Exception $e) { }
            
            foreach ($classes as $class): 
                $count = $record_counts[$class['class_name']] ?? 0;
            ?>
                <div class="class-card" onclick="selectClass('<?php echo htmlspecialchars($class['class_name']); ?>')">
                    <div class="class-icon"><i class="fas fa-chalkboard"></i></div>
                    <h3><?php echo htmlspecialchars($class['class_name']); ?></h3>
                    <span class="record-count">
                        <i class="fas fa-file-alt"></i> <?php echo $count; ?> record(s)
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="footer">
            &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> — Online Portal
        </div>
        
    <?php else: ?>
        <!-- Records View for Selected Class -->
        <div class="records-section">
            <div class="records-header">
                <h2><i class="fas fa-book-open"></i> <?php echo htmlspecialchars($selected_class); ?> - Exam Records</h2>
                <button class="btn-create" onclick="openCreateModal('<?php echo htmlspecialchars($selected_class); ?>')">
                    <i class="fas fa-plus"></i> New Exam Record
                </button>
            </div>
            
            <?php if (empty($class_records)): ?>
                <div class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <h3>No exam records found for <?php echo htmlspecialchars($selected_class); ?></h3>
                    <p>Click "New Exam Record" to create one.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="records-table">
                        <thead>
                            <tr>
                                <th>Record Name</th>
                                <th>Session</th>
                                <th>Term</th>
                                <th>Template</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($class_records as $r): ?>
                            <tr data-record-id="<?php echo $r['id']; ?>">
                                <td><strong><?php echo htmlspecialchars($r['record_name'] ?? '—'); ?></strong></td>
                                <td><?php echo htmlspecialchars($r['session']); ?></td>
                                <td><?php echo htmlspecialchars($r['term']); ?> Term</td>
                                <td><i class="fas fa-palette"></i> <?php echo ucfirst($r['template']); ?></td>
                                <td><span class="status-badge status-<?php echo $r['status']; ?>"><?php echo ucfirst($r['status']); ?></span></td>
                                <td><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="action-btn edit" onclick="editRecord(<?php echo $r['id']; ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                                        <button class="action-btn scores" onclick="goToScores(<?php echo $r['id']; ?>)" title="Enter Scores"><i class="fas fa-pencil-alt"></i></button>
                                        <button class="action-btn results" onclick="goToResults(<?php echo $r['id']; ?>)" title="Generate Results"><i class="fas fa-id-card"></i></button>
                                        <button class="action-btn clone" onclick="cloneRecord(<?php echo $r['id']; ?>)" title="Clone"><i class="fas fa-copy"></i></button>
                                        <button class="action-btn delete" onclick="deleteRecord(<?php echo $r['id']; ?>)" title="Delete"><i class="fas fa-trash-alt"></i></button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="footer">
            &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> — Online Portal
        </div>
    <?php endif; ?>
</div>

<!-- Main Create/Edit Modal -->
<div id="examModal" class="modal">
    <div class="modal-container">
        <div class="modal-header">
            <h3 id="modalTitle"><i class="fas fa-plus-circle"></i> Create New Exam Record</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="sections-list" id="sectionsList"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" id="finalSaveBtn" onclick="saveAllSections()">
                <i class="fas fa-save"></i> Save & Proceed
            </button>
        </div>
    </div>
</div>

<!-- Hidden form template for storing data -->
<form id="recordForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="save_exam_record">
    <input type="hidden" name="record_id" id="record_id" value="0">
</form>

<script>
// Form data storage
let formData = {
    record_id: 0,
    record_name: '',
    session: '',
    term: '',
    class: '',
    template: 'classic',
    grading_system: 'simple',
    default_class_teacher_name: '',
    current_resumption_date: '',
    current_closing_date: '',
    next_resumption_date: '',
    days_school_opened: 62,
    show_class_position: true,
    show_subject_position: true,
    show_promoted_to: true,
    show_cumulative_avg: true,
    show_lowest_highest_avg: true,
    show_lowest_highest_class: false,
    sequential_positions: false,
    show_attendance: true,
    show_affective_traits: true,
    show_psychomotor: true,
    score_types: [
        { label: 'CA 1 (Test)', max: 20 },
        { label: 'CA 2 (Assignment)', max: 10 },
        { label: 'Exam Score', max: 70 }
    ],
    grading_scale: <?php echo json_encode($grading_presets['simple']); ?>,
    principal_comments: {}
};

let currentRecordId = 0;
let isEditMode = false;
let completedSections = {
    template: false,
    record_details: false,
    score_settings: false,
    grading_system: false,
    comments_setup: false,
    term_dates: false,
    display_options: false
};

const gradingPresets = <?php echo json_encode($grading_presets); ?>;
const classesList = <?php echo json_encode(array_column($classes, 'class_name')); ?>;
const existingSessions = <?php echo json_encode($existing_sessions); ?>;

// Section definitions
const sections = [
    { id: 'template', title: 'Report Card Template', icon: 'fa-palette', description: 'Choose a visual style for report cards' },
    { id: 'record_details', title: 'Exam Record Details', icon: 'fa-info-circle', description: 'Basic information about this exam record' },
    { id: 'score_settings', title: 'Score Settings', icon: 'fa-sliders-h', description: 'Configure score components (must total 100)' },
    { id: 'grading_system', title: 'Grading System', icon: 'fa-award', description: 'Set grade boundaries and remarks' },
    { id: 'comments_setup', title: 'Comments Setup', icon: 'fa-comment-dots', description: 'Default class teacher and principal comments' },
    { id: 'term_dates', title: 'Term Dates', icon: 'fa-calendar-alt', description: 'Set term resumption and closing dates' },
    { id: 'display_options', title: 'Display Options', icon: 'fa-cog', description: 'Toggle report card sections' }
];

function selectClass(className) {
    window.location.href = 'exam_record_setup.php?class=' + encodeURIComponent(className);
}

function openCreateModal(className = '') {
    isEditMode = false;
    currentRecordId = 0;
    formData.record_id = 0;
    formData.record_name = '';
    formData.session = '';
    formData.term = '';
    formData.class = className || '';
    formData.template = 'classic';
    formData.grading_system = 'simple';
    formData.default_class_teacher_name = '';
    formData.current_resumption_date = '';
    formData.current_closing_date = '';
    formData.next_resumption_date = '';
    formData.days_school_opened = 62;
    formData.show_class_position = true;
    formData.show_subject_position = true;
    formData.show_promoted_to = true;
    formData.show_cumulative_avg = true;
    formData.show_lowest_highest_avg = true;
    formData.show_lowest_highest_class = false;
    formData.sequential_positions = false;
    formData.show_attendance = true;
    formData.show_affective_traits = true;
    formData.show_psychomotor = true;
    formData.score_types = [
        { label: 'CA 1 (Test)', max: 20 },
        { label: 'CA 2 (Assignment)', max: 10 },
        { label: 'Exam Score', max: 70 }
    ];
    formData.grading_scale = [...gradingPresets.simple];
    formData.principal_comments = {};
    
    completedSections = {
        template: false, record_details: false, score_settings: false,
        grading_system: false, comments_setup: false, term_dates: false, display_options: false
    };
    
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Create New Exam Record';
    document.getElementById('record_id').value = '0';
    renderSections();
    document.getElementById('examModal').classList.add('active');
}

function editRecord(id) {
    isEditMode = true;
    currentRecordId = id;
    document.getElementById('record_id').value = id;
    
    // Fetch record data via AJAX
    fetch('exam_record_setup.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: 'action=get_record&record_id=' + id
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const r = data.record;
            formData.record_id = r.id;
            formData.record_name = r.record_name || '';
            formData.session = r.session || '';
            formData.term = r.term || '';
            formData.class = r.class || '';
            formData.template = r.template || 'classic';
            formData.grading_system = r.grading_system || 'simple';
            formData.default_class_teacher_name = r.default_class_teacher_name || '';
            formData.current_resumption_date = r.current_resumption_date || '';
            formData.current_closing_date = r.current_closing_date || '';
            formData.next_resumption_date = r.next_resumption_date || '';
            formData.days_school_opened = r.days_school_opened || 62;
            formData.show_class_position = r.show_class_position == 1;
            formData.show_subject_position = r.show_subject_position == 1;
            formData.show_promoted_to = r.show_promoted_to == 1;
            formData.show_cumulative_avg = r.show_cumulative_avg == 1;
            formData.show_lowest_highest_avg = r.show_lowest_highest_avg == 1;
            formData.show_lowest_highest_class = r.show_lowest_highest_class == 1;
            formData.sequential_positions = r.sequential_positions == 1;
            formData.show_attendance = r.show_attendance == 1;
            formData.show_affective_traits = r.show_affective_traits == 1;
            formData.show_psychomotor = r.show_psychomotor == 1;
            formData.score_types = data.score_types.length ? data.score_types : formData.score_types;
            formData.grading_scale = data.grading_scale;
            formData.principal_comments = data.principal_comments || {};
            
            // Mark all sections as completed for edit mode
            completedSections = {
                template: true, record_details: true, score_settings: true,
                grading_system: true, comments_setup: true, term_dates: true, display_options: true
            };
            
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Exam Record: ' + r.record_name;
            renderSections();
            document.getElementById('examModal').classList.add('active');
        } else {
            alert('Error loading record: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(err => {
        console.error('Error:', err);
        alert('Error loading record data');
    });
}

function renderSections() {
    const container = document.getElementById('sectionsList');
    container.innerHTML = '';
    
    sections.forEach(section => {
        const isCompleted = completedSections[section.id];
        const sectionDiv = document.createElement('div');
        sectionDiv.className = 'section-item' + (isCompleted ? ' completed' : '');
        sectionDiv.innerHTML = `
            <div class="section-header" onclick="toggleSection('${section.id}')">
                <div class="section-title">
                    <div class="section-icon ${isCompleted ? 'completed' : ''}"><i class="fas ${section.icon}"></i></div>
                    <div class="section-info">
                        <h4>${section.title}</h4>
                        <p>${section.description}</p>
                    </div>
                </div>
                <div class="section-status">
                    ${isCompleted ? '<i class="fas fa-check-circle" style="color: #27ae60;"></i>' : '<div class="status-indicator"></div>'}
                    <i class="fas fa-chevron-down"></i>
                </div>
            </div>
            <div class="section-content" id="section-${section.id}"></div>
        `;
        container.appendChild(sectionDiv);
    });
    
    // Render each section's content
    renderTemplateSection();
    renderRecordDetailsSection();
    renderScoreSettingsSection();
    renderGradingSystemSection();
    renderCommentsSetupSection();
    renderTermDatesSection();
    renderDisplayOptionsSection();
}

function toggleSection(sectionId) {
    const content = document.getElementById(`section-${sectionId}`);
    if (content) {
        content.classList.toggle('active');
    }
}

function markSectionCompleted(sectionId, value = true) {
    completedSections[sectionId] = value;
    // Find the section item and update its appearance
    const sectionsContainer = document.getElementById('sectionsList');
    const sectionItems = sectionsContainer.querySelectorAll('.section-item');
    for (let item of sectionItems) {
        const header = item.querySelector('.section-header h4');
        if (header && header.innerText.toLowerCase().includes(sectionId.replace('_', ' '))) {
            if (value) {
                item.classList.add('completed');
                const iconDiv = item.querySelector('.section-icon');
                if (iconDiv) iconDiv.classList.add('completed');
                const statusSpan = item.querySelector('.section-status');
                if (statusSpan) statusSpan.innerHTML = '<i class="fas fa-check-circle" style="color: #27ae60;"></i> <i class="fas fa-chevron-down"></i>';
            }
            break;
        }
    }
}

function renderTemplateSection() {
    const container = document.getElementById('section-template');
    if (!container) return;
    
    const templates = [
        { id: 'classic', name: 'Classic', icon: 'fa-book', color: '#1a3c5e' },
        { id: 'modern', name: 'Modern', icon: 'fa-chart-line', color: '#2d6a4f' },
        { id: 'vibrant', name: 'Vibrant', icon: 'fa-palette', color: '#c0392b' },
        { id: 'minimal', name: 'Minimal', icon: 'fa-square', color: '#2c3e50' },
        { id: 'elegant', name: 'Elegant', icon: 'fa-crown', color: '#b8860b' }
    ];
    
    let html = `<div class="template-grid">`;
    templates.forEach(tpl => {
        html += `
            <div class="tpl-option" onclick="selectTemplate('${tpl.id}')">
                <input type="radio" name="template_radio" value="${tpl.id}" ${formData.template === tpl.id ? 'checked' : ''}>
                <div class="tpl-preview">
                    <i class="fas ${tpl.icon}" style="font-size: 28px; color: ${tpl.color};"></i>
                    <span>${tpl.name}</span>
                </div>
            </div>
        `;
    });
    html += `</div><div style="margin-top: 10px;"><button class="btn btn-primary btn-sm" onclick="saveTemplate()">Save Template</button></div>`;
    container.innerHTML = html;
}

function selectTemplate(templateId) {
    formData.template = templateId;
    document.querySelectorAll('.tpl-option .tpl-preview').forEach(preview => {
        preview.style.borderColor = '#e0e0e0';
    });
    const selected = document.querySelector(`.tpl-option input[value="${templateId}"]`);
    if (selected && selected.parentElement) {
        selected.parentElement.querySelector('.tpl-preview').style.borderColor = '#006';
    }
}

function saveTemplate() {
    markSectionCompleted('template', true);
    toggleSection('template');
    showToast('Template saved!', 'success');
}

function renderRecordDetailsSection() {
    const container = document.getElementById('section-record_details');
    if (!container) return;
    
    let sessionOptions = '';
    existingSessions.forEach(s => { sessionOptions += `<option value="${s.replace(/"/g, '&quot;')}">${s}</option>`; });
    
    let classOptions = '<option value="">— Select class —</option>';
    classesList.forEach(c => { classOptions += `<option value="${c.replace(/"/g, '&quot;')}" ${formData.class === c ? 'selected' : ''}>${c}</option>`; });
    
    container.innerHTML = `
        <div class="form-group">
            <label>Record Name <span style="color:red">*</span></label>
            <input type="text" id="record_name_input" placeholder="e.g. 2024/2025 Second Term Examination — JSS 3" value="${formData.record_name.replace(/"/g, '&quot;')}">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Academic Year <span style="color:red">*</span></label>
                <input type="text" id="session_input" list="session_list" placeholder="e.g. 2024/2025" value="${formData.session.replace(/"/g, '&quot;')}">
                <datalist id="session_list">${sessionOptions}</datalist>
            </div>
            <div class="form-group">
                <label>Term <span style="color:red">*</span></label>
                <select id="term_input">
                    <option value="">— Select term —</option>
                    <option value="First" ${formData.term === 'First' ? 'selected' : ''}>First Term</option>
                    <option value="Second" ${formData.term === 'Second' ? 'selected' : ''}>Second Term</option>
                    <option value="Third" ${formData.term === 'Third' ? 'selected' : ''}>Third Term</option>
                </select>
            </div>
            <div class="form-group">
                <label>Class <span style="color:red">*</span></label>
                <select id="class_input">${classOptions}</select>
            </div>
        </div>
        <div style="margin-top: 15px;"><button class="btn btn-primary btn-sm" onclick="saveRecordDetails()">Save Details</button></div>
    `;
}

function saveRecordDetails() {
    const name = document.getElementById('record_name_input')?.value.trim();
    const session = document.getElementById('session_input')?.value.trim();
    const term = document.getElementById('term_input')?.value;
    const className = document.getElementById('class_input')?.value;
    
    if (!name) { alert('Record name is required'); return; }
    if (!session) { alert('Academic year is required'); return; }
    if (!term) { alert('Term is required'); return; }
    if (!className) { alert('Class is required'); return; }
    
    formData.record_name = name;
    formData.session = session;
    formData.term = term;
    formData.class = className;
    
    markSectionCompleted('record_details', true);
    toggleSection('record_details');
    showToast('Record details saved!', 'success');
}

function renderScoreSettingsSection() {
    const container = document.getElementById('section-score_settings');
    if (!container) return;
    
    let scoreRowsHtml = '';
    formData.score_types.forEach((st, idx) => {
        scoreRowsHtml += `
            <div class="score-row">
                <input type="text" class="score-label" value="${st.label.replace(/"/g, '&quot;')}" placeholder="Score label">
                <input type="number" class="score-max" value="${st.max}" min="1" max="100">
                <button class="btn btn-icon btn-sm" onclick="removeScoreRow(this)"><i class="fas fa-trash-alt"></i></button>
            </div>
        `;
    });
    
    container.innerHTML = `
        <div id="scoreBuilderContainer">${scoreRowsHtml}</div>
        <div style="margin: 10px 0;">
            <button class="btn btn-secondary btn-sm" onclick="addScoreRowUI()"><i class="fas fa-plus"></i> Add Score Type</button>
        </div>
        <div class="score-total">
            Total: <span id="scoreTotalDisplay">0</span> / 100
            <span id="scoreTotalError" style="color: red; display: none;"> (Must equal 100)</span>
        </div>
        <div style="margin-top: 15px;"><button class="btn btn-primary btn-sm" onclick="saveScoreSettings()">Save Score Settings</button></div>
    `;
    recalcScoreTotalUI();
}

function addScoreRowUI() {
    const container = document.getElementById('scoreBuilderContainer');
    const div = document.createElement('div');
    div.className = 'score-row';
    div.innerHTML = `
        <input type="text" class="score-label" value="New Score" placeholder="Score label">
        <input type="number" class="score-max" value="10" min="1" max="100">
        <button class="btn btn-icon btn-sm" onclick="removeScoreRow(this)"><i class="fas fa-trash-alt"></i></button>
    `;
    container.appendChild(div);
    recalcScoreTotalUI();
}

function removeScoreRow(btn) {
    const rows = document.querySelectorAll('#scoreBuilderContainer .score-row');
    if (rows.length <= 1) {
        alert('You must have at least one score type');
        return;
    }
    btn.closest('.score-row').remove();
    recalcScoreTotalUI();
}

function recalcScoreTotalUI() {
    const maxInputs = document.querySelectorAll('#scoreBuilderContainer .score-max');
    let total = 0;
    maxInputs.forEach(input => { total += parseInt(input.value) || 0; });
    const displaySpan = document.getElementById('scoreTotalDisplay');
    const errorSpan = document.getElementById('scoreTotalError');
    if (displaySpan) displaySpan.textContent = total;
    if (errorSpan) errorSpan.style.display = total === 100 ? 'none' : 'inline';
    return total;
}

function saveScoreSettings() {
    const labels = document.querySelectorAll('#scoreBuilderContainer .score-label');
    const maxes = document.querySelectorAll('#scoreBuilderContainer .score-max');
    const newScoreTypes = [];
    for (let i = 0; i < labels.length; i++) {
        const label = labels[i].value.trim();
        const max = parseInt(maxes[i].value) || 0;
        if (label && max > 0) newScoreTypes.push({ label, max });
    }
    const total = newScoreTypes.reduce((sum, st) => sum + st.max, 0);
    if (total !== 100) {
        alert(`Score types must total exactly 100. Current total: ${total}`);
        return;
    }
    formData.score_types = newScoreTypes;
    markSectionCompleted('score_settings', true);
    toggleSection('score_settings');
    showToast('Score settings saved!', 'success');
}

function renderGradingSystemSection() {
    const container = document.getElementById('section-grading_system');
    if (!container) return;
    
    let gradingRowsHtml = '';
    formData.grading_scale.forEach((grade, idx) => {
        gradingRowsHtml += `
            <tr>
                <td><input type="text" class="grade-letter" value="${grade.grade}" style="width:70px; text-align:center;"></td>
                <td><input type="number" class="grade-min" value="${grade.min}" min="0" max="100" style="width:80px;"></td>
                <td><input type="number" class="grade-max" value="${grade.max}" min="0" max="100" style="width:80px;"></td>
                <td><input type="text" class="grade-remark" value="${grade.remark.replace(/"/g, '&quot;')}" style="width:100%;"></td>
                <td><button class="btn btn-icon btn-sm" onclick="removeGradeRow(this)"><i class="fas fa-times"></i></button></td>
            </tr>
        `;
    });
    
    container.innerHTML = `
        <div class="form-group">
            <label>Grading Preset:</label>
            <select id="gradingPresetSelect" onchange="loadGradingPresetUI(this.value)">
                <option value="simple" ${formData.grading_system === 'simple' ? 'selected' : ''}>Simple letter grading (A – F)</option>
                <option value="waec" ${formData.grading_system === 'waec' ? 'selected' : ''}>WAEC grading (A1 – F9)</option>
                <option value="american" ${formData.grading_system === 'american' ? 'selected' : ''}>American GPA grading</option>
                <option value="custom" ${formData.grading_system === 'custom' ? 'selected' : ''}>Custom (edit table below)</option>
            </select>
        </div>
        <div style="overflow-x: auto;">
            <table class="records-table" style="width: 100%;">
                <thead><tr><th>Grade</th><th>Min</th><th>Max</th><th>Remark</th><th></th></tr></thead>
                <tbody id="gradingTableBody">${gradingRowsHtml}</tbody>
            </table>
        </div>
        <div style="margin: 10px 0;"><button class="btn btn-secondary btn-sm" onclick="addGradeRowUI()"><i class="fas fa-plus"></i> Add Grade</button></div>
        <div><button class="btn btn-primary btn-sm" onclick="saveGradingSystem()">Save Grading System</button></div>
    `;
}

function loadGradingPresetUI(preset) {
    formData.grading_system = preset;
    if (preset !== 'custom') {
        formData.grading_scale = JSON.parse(JSON.stringify(gradingPresets[preset] || gradingPresets.simple));
        const tbody = document.getElementById('gradingTableBody');
        if (tbody) {
            tbody.innerHTML = '';
            formData.grading_scale.forEach(grade => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td><input type="text" class="grade-letter" value="${grade.grade}" style="width:70px; text-align:center;"></td>
                    <td><input type="number" class="grade-min" value="${grade.min}" min="0" max="100" style="width:80px;"></td>
                    <td><input type="number" class="grade-max" value="${grade.max}" min="0" max="100" style="width:80px;"></td>
                    <td><input type="text" class="grade-remark" value="${grade.remark.replace(/"/g, '&quot;')}" style="width:100%;"></td>
                    <td><button class="btn btn-icon btn-sm" onclick="removeGradeRow(this)"><i class="fas fa-times"></i></button></td>
                `;
                tbody.appendChild(tr);
            });
        }
    }
}

function addGradeRowUI() {
    const tbody = document.getElementById('gradingTableBody');
    if (tbody) {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="text" class="grade-letter" value="New" style="width:70px; text-align:center;"></td>
            <td><input type="number" class="grade-min" value="0" min="0" max="100" style="width:80px;"></td>
            <td><input type="number" class="grade-max" value="0" min="0" max="100" style="width:80px;"></td>
            <td><input type="text" class="grade-remark" value="" style="width:100%;"></td>
            <td><button class="btn btn-icon btn-sm" onclick="removeGradeRow(this)"><i class="fas fa-times"></i></button></td>
        `;
        tbody.appendChild(tr);
    }
}

function removeGradeRow(btn) {
    const rows = document.querySelectorAll('#gradingTableBody tr');
    if (rows.length <= 1) {
        alert('You must have at least one grade');
        return;
    }
    btn.closest('tr').remove();
}

function saveGradingSystem() {
    const gradeLetters = document.querySelectorAll('#gradingTableBody .grade-letter');
    const gradeMins = document.querySelectorAll('#gradingTableBody .grade-min');
    const gradeMaxes = document.querySelectorAll('#gradingTableBody .grade-max');
    const gradeRemarks = document.querySelectorAll('#gradingTableBody .grade-remark');
    
    const newGradingScale = [];
    for (let i = 0; i < gradeLetters.length; i++) {
        const letter = gradeLetters[i].value.trim();
        if (letter) {
            newGradingScale.push({
                grade: letter,
                min: parseInt(gradeMins[i].value) || 0,
                max: parseInt(gradeMaxes[i].value) || 100,
                remark: gradeRemarks[i].value.trim()
            });
        }
    }
    
    if (newGradingScale.length === 0) {
        alert('Grading scale cannot be empty');
        return;
    }
    
    formData.grading_scale = newGradingScale;
    markSectionCompleted('grading_system', true);
    toggleSection('grading_system');
    showToast('Grading system saved!', 'success');
}

function renderCommentsSetupSection() {
    const container = document.getElementById('section-comments_setup');
    if (!container) return;
    
    let commentsRowsHtml = '';
    formData.grading_scale.forEach(grade => {
        const comment = formData.principal_comments[grade.grade] || '';
        commentsRowsHtml += `
            <div class="form-group">
                <label><strong>${grade.grade}</strong> - ${grade.remark}</label>
                <textarea class="principal-comment" data-grade="${grade.grade}" rows="2" placeholder="Principal's comment for grade ${grade.grade}..." style="width:100%; padding:10px; border:1.5px solid #e0e0e0; border-radius:8px;">${comment.replace(/"/g, '&quot;')}</textarea>
            </div>
        `;
    });
    
    container.innerHTML = `
        <div class="form-group">
            <label>Default Class Teacher Name</label>
            <input type="text" id="defaultTeacherName" value="${formData.default_class_teacher_name.replace(/"/g, '&quot;')}" placeholder="e.g. Mrs. Oluwaseun Adebayo" style="width:100%; padding:10px; border:1.5px solid #e0e0e0; border-radius:8px;">
            <small style="color:#666;">This name will be auto-filled for all students</small>
        </div>
        <div style="margin: 20px 0;">
            <h4 style="font-size: 0.9rem; margin-bottom: 10px;">Principal's Comments by Grade</h4>
            ${commentsRowsHtml}
        </div>
        <button class="btn btn-primary btn-sm" onclick="saveCommentsSetup()">Save Comments Setup</button>
    `;
}

function saveCommentsSetup() {
    formData.default_class_teacher_name = document.getElementById('defaultTeacherName')?.value.trim() || '';
    
    const comments = {};
    document.querySelectorAll('.principal-comment').forEach(textarea => {
        const grade = textarea.getAttribute('data-grade');
        if (grade) comments[grade] = textarea.value.trim();
    });
    formData.principal_comments = comments;
    
    markSectionCompleted('comments_setup', true);
    toggleSection('comments_setup');
    showToast('Comments setup saved!', 'success');
}

function renderTermDatesSection() {
    const container = document.getElementById('section-term_dates');
    if (!container) return;
    
    container.innerHTML = `
        <div class="form-row">
            <div class="form-group">
                <label>Term Resumption Date</label>
                <input type="date" id="resumptionDate" value="${formData.current_resumption_date}">
            </div>
            <div class="form-group">
                <label>Term Closing Date</label>
                <input type="date" id="closingDate" value="${formData.current_closing_date}">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Next Term Resumption</label>
                <input type="date" id="nextResumptionDate" value="${formData.next_resumption_date}">
            </div>
            <div class="form-group">
                <label>Days School Opened</label>
                <input type="number" id="daysOpened" value="${formData.days_school_opened}" min="1" max="300">
            </div>
        </div>
        <button class="btn btn-primary btn-sm" onclick="saveTermDates()">Save Term Dates</button>
    `;
}

function saveTermDates() {
    formData.current_resumption_date = document.getElementById('resumptionDate')?.value || '';
    formData.current_closing_date = document.getElementById('closingDate')?.value || '';
    formData.next_resumption_date = document.getElementById('nextResumptionDate')?.value || '';
    formData.days_school_opened = parseInt(document.getElementById('daysOpened')?.value) || 62;
    
    markSectionCompleted('term_dates', true);
    toggleSection('term_dates');
    showToast('Term dates saved!', 'success');
}

function renderDisplayOptionsSection() {
    const container = document.getElementById('section-display_options');
    if (!container) return;
    
    const options = [
        { id: 'show_class_position', label: 'Show class position' },
        { id: 'show_subject_position', label: 'Show subject position' },
        { id: 'show_promoted_to', label: 'Show "promoted to" next class' },
        { id: 'show_cumulative_avg', label: 'Show cumulative average' },
        { id: 'show_lowest_highest_avg', label: 'Show lowest & highest average' },
        { id: 'show_lowest_highest_class', label: 'Show lowest & highest in class' },
        { id: 'sequential_positions', label: 'Sequential positions (1st, 1st, 2nd)' },
        { id: 'show_attendance', label: 'Show attendance record' },
        { id: 'show_affective_traits', label: 'Show affective traits' },
        { id: 'show_psychomotor', label: 'Show psychomotor skills' }
    ];
    
    let optionsHtml = '';
    options.forEach(opt => {
        optionsHtml += `
            <div class="toggle-switch">
                <label>${opt.label}</label>
                <label class="switch">
                    <input type="checkbox" class="display-option" data-option="${opt.id}" ${formData[opt.id] ? 'checked' : ''}>
                    <span class="slider"></span>
                </label>
            </div>
        `;
    });
    
    container.innerHTML = `
        ${optionsHtml}
        <div style="margin-top: 15px;"><button class="btn btn-primary btn-sm" onclick="saveDisplayOptions()">Save Display Options</button></div>
    `;
}

function saveDisplayOptions() {
    const checkboxes = document.querySelectorAll('.display-option');
    checkboxes.forEach(cb => {
        const option = cb.getAttribute('data-option');
        if (option) formData[option] = cb.checked;
    });
    
    markSectionCompleted('display_options', true);
    toggleSection('display_options');
    showToast('Display options saved!', 'success');
}

function saveAllSections() {
    // Check if all sections are completed
    const allCompleted = Object.values(completedSections).every(v => v === true);
    if (!allCompleted) {
        alert('Please complete all sections before saving.');
        return;
    }
    
    // Submit via AJAX
    const formDataObj = new FormData();
    formDataObj.append('action', 'save_exam_record');
    formDataObj.append('record_id', formData.record_id);
    formDataObj.append('record_name', formData.record_name);
    formDataObj.append('session', formData.session);
    formDataObj.append('term', formData.term);
    formDataObj.append('class', formData.class);
    formDataObj.append('template', formData.template);
    formDataObj.append('grading_system', formData.grading_system);
    formDataObj.append('default_class_teacher_name', formData.default_class_teacher_name);
    formDataObj.append('current_resumption_date', formData.current_resumption_date);
    formDataObj.append('current_closing_date', formData.current_closing_date);
    formDataObj.append('next_resumption_date', formData.next_resumption_date);
    formDataObj.append('days_school_opened', formData.days_school_opened);
    formDataObj.append('show_class_position', formData.show_class_position ? '1' : '0');
    formDataObj.append('show_subject_position', formData.show_subject_position ? '1' : '0');
    formDataObj.append('show_promoted_to', formData.show_promoted_to ? '1' : '0');
    formDataObj.append('show_cumulative_avg', formData.show_cumulative_avg ? '1' : '0');
    formDataObj.append('show_lowest_highest_avg', formData.show_lowest_highest_avg ? '1' : '0');
    formDataObj.append('show_lowest_highest_class', formData.show_lowest_highest_class ? '1' : '0');
    formDataObj.append('sequential_positions', formData.sequential_positions ? '1' : '0');
    formDataObj.append('show_attendance', formData.show_attendance ? '1' : '0');
    formDataObj.append('show_affective_traits', formData.show_affective_traits ? '1' : '0');
    formDataObj.append('show_psychomotor', formData.show_psychomotor ? '1' : '0');
    formDataObj.append('save_as', 'active');
    
    // Add score types
    formData.score_types.forEach((st) => {
        formDataObj.append('score_label[]', st.label);
        formDataObj.append('score_max[]', st.max);
    });
    
    // Add grading scale
    formData.grading_scale.forEach(grade => {
        formDataObj.append('grade_letter[]', grade.grade);
        formDataObj.append('grade_min[]', grade.min);
        formDataObj.append('grade_max[]', grade.max);
        formDataObj.append('grade_remark[]', grade.remark);
    });
    
    // Add principal comments
    Object.keys(formData.principal_comments).forEach(grade => {
        formDataObj.append('principal_grade[]', grade);
        formDataObj.append('principal_comment[]', formData.principal_comments[grade]);
    });
    
    fetch('exam_record_setup.php', {
        method: 'POST',
        body: formDataObj,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            if (data.redirect) {
                window.location.href = data.redirect;
            } else {
                closeModal();
                window.location.reload();
            }
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => {
        console.error('Error:', err);
        alert('Error saving record: ' + err.message);
    });
}

function closeModal() {
    document.getElementById('examModal').classList.remove('active');
}

function goToScores(id) {
    window.location.href = `exam_score_entry.php?record_id=${id}`;
}

function goToResults(id) {
    window.location.href = `exam_generate_cards.php?record_id=${id}`;
}

function cloneRecord(id) {
    if (confirm('Clone this exam record? All settings will be copied.')) {
        window.location.href = `exam_record_clone.php?id=${id}`;
    }
}

function deleteRecord(id) {
    if (confirm('Are you sure you want to delete this exam record? This action cannot be undone.')) {
        fetch('exam_record_setup.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: `action=delete_record&record_id=${id}`
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => window.location.reload(), 1000);
            } else {
                alert(data.message);
            }
        })
        .catch(err => alert('Error deleting record'));
    }
}

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed; bottom: 20px; right: 20px; background: ${type === 'success' ? '#27ae60' : '#e74c3c'};
        color: white; padding: 12px 20px; border-radius: 8px; z-index: 10000;
        font-size: 14px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        animation: slideIn 0.3s ease;
    `;
    toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${message}`;
    document.body.appendChild(toast);
    setTimeout(() => { toast.remove(); }, 3000);
}

// Add animation styles
const styleAnim = document.createElement('style');
styleAnim.textContent = `
    @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
`;
document.head.appendChild(styleAnim);

// Mobile menu toggle
(function() {
    const sidebar = document.getElementById('sidebar') || document.querySelector('.sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggleBtn = document.getElementById('mobileMenuToggle');
    
    if (toggleBtn && sidebar) {
        sidebar.style.transition = 'transform 0.3s ease';
        
        function setMobileState(show) {
            if (window.innerWidth <= 768) {
                sidebar.style.transform = show ? 'translateX(0)' : 'translateX(-100%)';
                if (overlay) overlay.classList.toggle('active', show);
                document.body.style.overflow = show ? 'hidden' : '';
            }
        }
        
        if (window.innerWidth <= 768) {
            sidebar.style.position = 'fixed';
            sidebar.style.top = '0';
            sidebar.style.left = '0';
            sidebar.style.bottom = '0';
            sidebar.style.zIndex = '1000';
            sidebar.style.transform = 'translateX(-100%)';
        }
        
        toggleBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const isVisible = sidebar.style.transform === 'translateX(0)';
            setMobileState(!isVisible);
        });
        
        if (overlay) {
            overlay.addEventListener('click', () => setMobileState(false));
        }
        
        window.addEventListener('resize', () => {
            if (window.innerWidth > 768) {
                sidebar.style.transform = '';
                sidebar.style.position = '';
                if (overlay) overlay.classList.remove('active');
                document.body.style.overflow = '';
            } else {
                sidebar.style.position = 'fixed';
                sidebar.style.transform = 'translateX(-100%)';
            }
        });
    }
})();
</script>

</body>
</html>
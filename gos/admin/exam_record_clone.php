<?php
// gos/admin/exam_record_clone.php — Clone an exam record (Admin only)
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../includes/config.php';

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /gos/login.php");
    exit();
}

if (isset($_SESSION['admin_id'])) {
    $admin_id   = $_SESSION['admin_id'];
    $admin_role = $_SESSION['admin_role'] ?? 'super_admin';
} else {
    $admin_id   = $_SESSION['user_id'];
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

if (!in_array($admin_role, ['super_admin', 'admin'])) {
    header("Location: index.php");
    exit();
}

$school_id = SCHOOL_ID;
$record_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($record_id <= 0) {
    $_SESSION['flash_error'] = "Invalid record ID for cloning.";
    header("Location: exam_record_setup.php");
    exit();
}

try {
    // Fetch the record to clone
    $stmt = $pdo->prepare("
        SELECT * FROM report_card_settings 
        WHERE id = ? AND school_id = ?
    ");
    $stmt->execute([$record_id, $school_id]);
    $original = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$original) {
        $_SESSION['flash_error'] = "Record not found for cloning.";
        header("Location: exam_record_setup.php");
        exit();
    }

    // Generate new record name with "(Clone)" suffix
    $new_record_name = $original['record_name'] . " (Clone)";

    // Decode score_types to ensure proper format
    $score_types_json = $original['score_types'];

    // Prepare the cloned record data (exclude id, created_at, updated_at)
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
             'draft', ?, NOW(), NOW())
    ");

    $stmt->execute([
        $school_id,
        $new_record_name,
        $original['session'],
        $original['term'],
        $original['class'],
        $original['template'],
        $original['max_score'],
        $score_types_json,
        $original['grading_system'],
        $original['default_class_teacher_name'],
        $original['principal_comments_per_grade'],
        $original['current_resumption_date'],
        $original['current_closing_date'],
        $original['next_resumption_date'],
        $original['days_school_opened'],
        $original['show_class_position'],
        $original['show_subject_position'],
        $original['show_promoted_to'],
        $original['show_cumulative_avg'],
        $original['show_lowest_highest_avg'],
        $original['show_lowest_highest_class'],
        $original['sequential_positions'],
        $original['show_attendance'],
        $original['show_affective_traits'],
        $original['show_psychomotor'],
        $admin_id,
    ]);

    $new_id = $pdo->lastInsertId();

    // Activity log
    try {
        $pdo->prepare("
            INSERT INTO activity_logs (user_id, user_type, activity, school_id)
            VALUES (?, 'admin', ?, ?)
        ")->execute([
            $admin_id,
            "Cloned exam record from '{$original['record_name']}' to '{$new_record_name}'",
            $school_id
        ]);
    } catch (Exception $e) { /* non-fatal */
    }

    $_SESSION['flash_success'] = "Exam record cloned successfully. You can now edit the cloned copy.";
    header("Location: exam_record_setup.php?edit={$new_id}");
    exit();
} catch (Exception $e) {
    error_log("exam_record_clone.php error: " . $e->getMessage());
    $_SESSION['flash_error'] = "Error cloning record: " . htmlspecialchars($e->getMessage());
    header("Location: exam_record_setup.php");
    exit();
}

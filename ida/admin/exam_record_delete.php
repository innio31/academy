<?php
// ida/admin/exam_record_delete.php — Delete an exam record (Admin only)
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../includes/config.php';

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /ida/login.php");
    exit();
}
$admin_id   = $_SESSION['admin_id']   ?? $_SESSION['user_id'];
$admin_role = $_SESSION['admin_role'] ?? 'admin';

if (!in_array($admin_role, ['super_admin', 'admin'])) {
    header("Location: index.php");
    exit();
}

$school_id = SCHOOL_ID;
$record_id = isset($_POST['record_id']) ? (int)$_POST['record_id'] : 0;

if ($record_id > 0) {
    try {
        // Only allow deleting draft or active records — never published/archived
        $stmt = $pdo->prepare("
            SELECT id, record_name, status FROM report_card_settings
             WHERE id = ? AND school_id = ?
        ");
        $stmt->execute([$record_id, $school_id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($record && in_array($record['status'] ?? 'draft', ['draft', 'active'])) {
            $pdo->prepare("DELETE FROM report_card_settings WHERE id = ? AND school_id = ?")
                ->execute([$record_id, $school_id]);

            // Activity log
            try {
                $pdo->prepare("
                    INSERT INTO activity_logs (user_id, user_type, activity, school_id)
                    VALUES (?, 'admin', ?, ?)
                ")->execute([$admin_id, "Deleted exam record: " . ($record['record_name'] ?? $record_id), $school_id]);
            } catch (Exception $e) { /* non-fatal */
            }

            $_SESSION['flash_success'] = "Exam record deleted successfully.";
        } else {
            $_SESSION['flash_error'] = "Cannot delete a published or archived exam record.";
        }
    } catch (Exception $e) {
        error_log("exam_record_delete error: " . $e->getMessage());
        $_SESSION['flash_error'] = "Error deleting record. Please try again.";
    }
}

header("Location: exam_record_setup.php");
exit();

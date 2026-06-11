<?php
// admin/ajax/get_waec_question.php - Get WAEC question details for modal

session_start();
require_once '../../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Question ID required']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT wq.*, ws.subject_name, wt.topic_name 
        FROM waec_questions wq
        LEFT JOIN waec_subjects ws ON wq.waec_subject_id = ws.id
        LEFT JOIN waec_topics wt ON wq.waec_topic_id = wt.id
        WHERE wq.id = ? AND wq.is_active = 1
    ");
    $stmt->execute([$id]);
    $question = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($question) {
        echo json_encode(['success' => true, 'question' => $question]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Question not found']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
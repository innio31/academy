<?php
// tbis/student/api/get_topics.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

require_once '../../includes/config.php';
global $pdo;

$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;

if (!$subject_id) {
    echo json_encode(['success' => false, 'error' => 'Subject ID required']);
    exit();
}

try {
    $query = "SELECT id, topic_name FROM waec_topics WHERE waec_subject_id = ? AND is_active = 1 ORDER BY sort_order, topic_name";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$subject_id]);
    $topics = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'topics' => $topics]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
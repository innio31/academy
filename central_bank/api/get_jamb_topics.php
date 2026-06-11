<?php
// /central_bank/api/get_jamb_topics.php

require_once '../includes/config.php';

header('Content-Type: application/json');

$subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;

if ($subject_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'subject_id is required']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, topic_name, description 
        FROM jamb_topics 
        WHERE jamb_subject_id = ? AND is_active = 1
        ORDER BY topic_name
    ");
    $stmt->execute([$subject_id]);
    $topics = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'subject_id' => $subject_id,
        'topics' => $topics,
        'total' => count($topics)
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Failed to fetch topics: ' . $e->getMessage()]);
}
?>
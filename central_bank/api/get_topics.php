<?php
// /central_bank/api/get_topics.php - Get topics by subject

$subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;

if ($subject_id <= 0) {
    json_error('subject_id is required');
}

try {
    $stmt = $pdo->prepare("
        SELECT id, topic_name, description, created_at 
        FROM topics 
        WHERE subject_id = ? AND (is_central = 1 OR school_id IS NULL)
        ORDER BY topic_name
    ");
    $stmt->execute([$subject_id]);
    $topics = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    json_response([
        'success' => true,
        'subject_id' => $subject_id,
        'topics' => $topics,
        'total' => count($topics)
    ]);
} catch (Exception $e) {
    json_error('Failed to fetch topics: ' . $e->getMessage());
}
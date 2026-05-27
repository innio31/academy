<?php
// /central_bank/api/handlers/get_topics.php

$subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;

if ($subject_id <= 0) {
    json_error('subject_id is required');
}

try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT t.id, t.topic_name 
        FROM topics t
        WHERE t.subject_id = ? AND EXISTS (
            SELECT 1 FROM objective_questions q 
            WHERE q.topic_id = t.id AND (q.is_central = 1 OR q.school_id IS NULL)
            UNION
            SELECT 1 FROM subjective_questions q 
            WHERE q.topic_id = t.id AND (q.is_central = 1 OR q.school_id IS NULL)
            UNION
            SELECT 1 FROM theory_questions q 
            WHERE q.topic_id = t.id AND (q.is_central = 1 OR q.school_id IS NULL)
        )
        ORDER BY t.topic_name
    ");
    $stmt->execute([$subject_id]);
    $topics = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    json_response([
        'success' => true,
        'topics' => $topics
    ]);
} catch (Exception $e) {
    json_error('Failed to fetch topics: ' . $e->getMessage());
}
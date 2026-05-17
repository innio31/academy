<?php
// /central_bank/api/get_stats.php - Get central bank statistics

try {
    // Count subjects
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM subjects WHERE is_central = 1 OR school_id IS NULL");
    $subjects = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Count topics
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM topics WHERE is_central = 1 OR school_id IS NULL");
    $topics = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Count objective questions
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM objective_questions WHERE is_central = 1 OR school_id IS NULL");
    $objective = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Count subjective questions
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM subjective_questions WHERE is_central = 1 OR school_id IS NULL");
    $subjective = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Count theory questions
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM theory_questions WHERE is_central = 1 OR school_id IS NULL");
    $theory = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    json_response([
        'success' => true,
        'stats' => [
            'subjects' => $subjects,
            'topics' => $topics,
            'objective_questions' => $objective,
            'subjective_questions' => $subjective,
            'theory_questions' => $theory,
            'total_questions' => $objective + $subjective + $theory
        ]
    ]);
} catch (Exception $e) {
    json_error('Failed to get stats: ' . $e->getMessage());
}
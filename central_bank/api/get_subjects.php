<?php
// /central_bank/api/get_subjects.php - Get all central subjects

try {
    $stmt = $pdo->prepare("
        SELECT id, subject_name, description, created_at 
        FROM subjects 
        WHERE is_central = 1 OR school_id IS NULL
        ORDER BY subject_name
    ");
    $stmt->execute();
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    json_response([
        'success' => true,
        'subjects' => $subjects,
        'total' => count($subjects)
    ]);
} catch (Exception $e) {
    json_error('Failed to fetch subjects: ' . $e->getMessage());
}
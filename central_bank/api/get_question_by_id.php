<?php
// /central_bank/api/get_question_by_id.php - Get a single central question by ID

$question_id = isset($_GET['question_id']) ? (int)$_GET['question_id'] : 0;
$question_type = isset($_GET['type']) ? $_GET['type'] : 'objective';

if ($question_id <= 0) {
    json_error('question_id is required');
}

try {
    // Determine which table to query
    $table = '';
    $select_fields = '';
    
    if ($question_type === 'objective') {
        $table = 'objective_questions';
        $select_fields = 'id, question_text, option_a, option_b, option_c, option_d, correct_answer, difficulty_level, marks, question_image, created_at';
    } elseif ($question_type === 'subjective') {
        $table = 'subjective_questions';
        $select_fields = 'id, question_text, correct_answer, difficulty_level, marks, created_at';
    } elseif ($question_type === 'theory') {
        $table = 'theory_questions';
        $select_fields = 'id, question_text, question_file, marks, created_at';
    } else {
        json_error('Invalid question type. Use: objective, subjective, or theory');
    }
    
    $stmt = $pdo->prepare("
        SELECT $select_fields FROM $table 
        WHERE id = ? AND (is_central = 1 OR school_id IS NULL)
    ");
    $stmt->execute([$question_id]);
    $question = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$question) {
        json_error('Question not found');
    }
    
    json_response([
        'success' => true,
        'question_type' => $question_type,
        'question' => $question
    ]);
} catch (Exception $e) {
    json_error('Failed to fetch question: ' . $e->getMessage());
}
<?php
// /central_bank/api/get_questions.php - Get central questions

$subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
$topic_id = isset($_GET['topic_id']) ? (int)$_GET['topic_id'] : 0;
$question_type = isset($_GET['type']) ? $_GET['type'] : 'objective';
$difficulty = isset($_GET['difficulty']) ? $_GET['difficulty'] : '';
$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

if ($subject_id <= 0) {
    json_error('subject_id is required');
}

try {
    // Determine which table to query
    $table = '';
    $select_fields = '';
    
    if ($question_type === 'objective') {
        $table = 'objective_questions';
        $select_fields = 'id, question_text, option_a, option_b, option_c, option_d, correct_answer, difficulty_level, marks, created_at';
    } elseif ($question_type === 'subjective') {
        $table = 'subjective_questions';
        $select_fields = 'id, question_text, correct_answer, difficulty_level, marks, created_at';
    } elseif ($question_type === 'theory') {
        $table = 'theory_questions';
        $select_fields = 'id, question_text, question_file, marks, created_at';
    } else {
        json_error('Invalid question type. Use: objective, subjective, or theory');
    }
    
    // Build query
    $sql = "SELECT $select_fields FROM $table 
            WHERE subject_id = ? AND (is_central = 1 OR school_id IS NULL)";
    $params = [$subject_id];
    
    if ($topic_id > 0) {
        $sql .= " AND topic_id = ?";
        $params[] = $topic_id;
    }
    
    if (!empty($difficulty) && in_array($difficulty, ['easy', 'medium', 'hard'])) {
        $sql .= " AND difficulty_level = ?";
        $params[] = $difficulty;
    }
    
    // Get total count
    $count_sql = str_replace($select_fields, 'COUNT(*) as total', $sql);
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get paginated results
    $sql .= " ORDER BY id DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // For each question, check if it already exists in the requesting school's database
    // School can pass school_id in request to check existence
    $school_id = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
    
    if ($school_id > 0 && !empty($questions)) {
        foreach ($questions as &$q) {
            // Check if this central question has been imported by this school
            $check_stmt = $pdo->prepare("
                SELECT id FROM $table 
                WHERE central_source_id = ? AND school_id = ?
                LIMIT 1
            ");
            $check_stmt->execute([$q['id'], $school_id]);
            $q['already_imported'] = $check_stmt->rowCount() > 0;
        }
    }
    
    json_response([
        'success' => true,
        'question_type' => $question_type,
        'subject_id' => $subject_id,
        'topic_id' => $topic_id ?: null,
        'questions' => $questions,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => (int)$total,
            'total_pages' => ceil($total / $limit)
        ]
    ]);
} catch (Exception $e) {
    json_error('Failed to fetch questions: ' . $e->getMessage());
}
<?php
// Add to school's /includes/functions.php

/**
 * Import questions from central bank
 */
function import_from_central_bank($central_question_id, $question_type, $target_topic_id, $target_subject_id, $class, $school_id, $pdo) {
    $central_url = 'https://acad.com.ng/central_bank/api/';
    $api_key = 'YOUR_SECURE_API_KEY_HERE_32_CHARS_MIN'; // Same as in central config
    
    // First, fetch the question from central bank
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $central_url . 'get_questions.php?action=get_single&id=' . $central_question_id . '&type=' . $question_type);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-API-Key: ' . $api_key,
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        return ['success' => false, 'error' => 'Failed to fetch question from central bank'];
    }
    
    $data = json_decode($response, true);
    if (!$data['success'] || empty($data['questions'])) {
        return ['success' => false, 'error' => 'Question not found in central bank'];
    }
    
    $question = $data['questions'][0];
    $table = $question_type . '_questions';
    
    // Check if already imported
    $check = $pdo->prepare("SELECT id FROM $table WHERE central_source_id = ? AND school_id = ? LIMIT 1");
    $check->execute([$central_question_id, $school_id]);
    if ($check->fetch()) {
        return ['success' => false, 'error' => 'Question already imported'];
    }
    
    // Insert copy
    if ($question_type === 'objective') {
        $stmt = $pdo->prepare("
            INSERT INTO objective_questions 
            (question_text, option_a, option_b, option_c, option_d, correct_answer, 
             difficulty_level, marks, subject_id, topic_id, class, school_id, 
             question_image, central_source_id, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $question['question_text'], $question['option_a'], $question['option_b'],
            $question['option_c'] ?? '', $question['option_d'] ?? '', $question['correct_answer'],
            $question['difficulty_level'] ?? 'medium', $question['marks'] ?? 1,
            $target_subject_id, $target_topic_id, $class, $school_id,
            $question['question_image'] ?? null, $central_question_id
        ]);
    } elseif ($question_type === 'subjective') {
        $stmt = $pdo->prepare("
            INSERT INTO subjective_questions 
            (question_text, correct_answer, difficulty_level, marks, subject_id, 
             topic_id, class, school_id, central_source_id, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $question['question_text'], $question['correct_answer'] ?? '',
            $question['difficulty_level'] ?? 'medium', $question['marks'] ?? 1,
            $target_subject_id, $target_topic_id, $class, $school_id, $central_question_id
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO theory_questions 
            (question_text, question_file, marks, subject_id, topic_id, class, 
             school_id, central_source_id, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $question['question_text'], $question['question_file'] ?? null, $question['marks'] ?? 5,
            $target_subject_id, $target_topic_id, $class, $school_id, $central_question_id
        ]);
    }
    
    return ['success' => true, 'id' => $pdo->lastInsertId()];
}
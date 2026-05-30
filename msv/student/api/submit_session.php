<?php
// File: msv/student/api/submit_session.php

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$session_id = $data['session_id'] ?? 0;
$student_id = $_SESSION['user_id'];

if (!$session_id) {
    echo json_encode(['success' => false, 'error' => 'Missing session ID']);
    exit();
}

require_once '../../includes/config.php';

try {
    $conn = getDbConnection();
    
    // Get all answers for this session
    $query = "SELECT waec_practice_answers.*, waec_questions.correct_answer, waec_questions.waec_subject_id, waec_questions.waec_topic_id
              FROM waec_practice_answers
              JOIN waec_questions ON waec_practice_answers.waec_question_id = waec_questions.id
              WHERE waec_practice_answers.session_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $answers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Calculate score
    $score = 0;
    $total_attempted = 0;
    $topic_stats = [];
    
    foreach ($answers as $answer) {
        if ($answer['student_answer']) {
            $total_attempted++;
            if ($answer['student_answer'] === $answer['correct_answer']) {
                $score++;
                $is_correct = 1;
            } else {
                $is_correct = 0;
            }
            
            // Update answer with is_correct
            $update_query = "UPDATE waec_practice_answers SET is_correct = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("ii", $is_correct, $answer['id']);
            $update_stmt->execute();
            
            // Track topic performance
            $topic_key = $answer['waec_subject_id'] . '_' . $answer['waec_topic_id'];
            if (!isset($topic_stats[$topic_key])) {
                $topic_stats[$topic_key] = ['correct' => 0, 'total' => 0, 'subject_id' => $answer['waec_subject_id'], 'topic_id' => $answer['waec_topic_id']];
            }
            $topic_stats[$topic_key]['total']++;
            if ($is_correct) $topic_stats[$topic_key]['correct']++;
        }
    }
    
    $percentage = $total_attempted > 0 ? ($score / $total_attempted) * 100 : 0;
    
    // Update session
    $update_session = "UPDATE waec_practice_sessions 
                       SET status = 'completed', 
                           submitted_at = NOW(), 
                           end_time = NOW(),
                           score = ?, 
                           total_attempted = ?, 
                           percentage = ?
                       WHERE id = ? AND student_id = ?";
    $stmt = $conn->prepare($update_session);
    $stmt->bind_param("iidii", $score, $total_attempted, $percentage, $session_id, $student_id);
    $stmt->execute();
    
    // Update subject performance
    foreach ($topic_stats as $stat) {
        // Update or insert topic performance
        $topic_query = "INSERT INTO waec_topic_performance 
                        (student_id, school_id, waec_subject_id, waec_topic_id, total_attempts, total_correct, avg_percentage, last_practiced_at, updated_at)
                        VALUES (?, ?, ?, ?, 1, ?, ?, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE
                        total_attempts = total_attempts + 1,
                        total_correct = total_correct + VALUES(total_correct),
                        avg_percentage = (total_correct / total_attempts) * 100,
                        last_practiced_at = NOW(),
                        updated_at = NOW()";
        
        $avg_pct = ($stat['correct'] / 1) * 100;
        $stmt = $conn->prepare($topic_query);
        $stmt->bind_param("iiiid", $student_id, $_SESSION['school_id'], $stat['subject_id'], $stat['topic_id'], $stat['correct']);
        $stmt->execute();
        
        // Update mastery level based on avg_percentage
        $mastery_query = "UPDATE waec_topic_performance 
                          SET mastery_level = CASE
                              WHEN avg_percentage >= 80 THEN 'mastered'
                              WHEN avg_percentage >= 60 THEN 'strong'
                              WHEN avg_percentage >= 40 THEN 'progressing'
                              WHEN avg_percentage >= 20 THEN 'needs_work'
                              ELSE 'not_started'
                          END
                          WHERE student_id = ? AND waec_subject_id = ? AND waec_topic_id = ?";
        $stmt = $conn->prepare($mastery_query);
        $stmt->bind_param("iii", $student_id, $stat['subject_id'], $stat['topic_id']);
        $stmt->execute();
    }
    
    // Update subject performance summary
    $subject_query = "INSERT INTO waec_subject_performance 
                      (student_id, school_id, waec_subject_id, total_sessions, total_questions_attempted, total_correct, avg_percentage, highest_score, last_practiced_at, updated_at)
                      VALUES (?, ?, ?, 1, ?, ?, ?, ?, NOW(), NOW())
                      ON DUPLICATE KEY UPDATE
                      total_sessions = total_sessions + 1,
                      total_questions_attempted = total_questions_attempted + VALUES(total_questions_attempted),
                      total_correct = total_correct + VALUES(total_correct),
                      avg_percentage = (total_correct / total_questions_attempted) * 100,
                      highest_score = GREATEST(highest_score, VALUES(highest_score)),
                      last_practiced_at = NOW(),
                      updated_at = NOW()";
    
    $first_subject = array_values($topic_stats)[0]['subject_id'] ?? null;
    if ($first_subject) {
        $stmt = $conn->prepare($subject_query);
        $stmt->bind_param("iiiiid", $student_id, $_SESSION['school_id'], $first_subject, $total_attempted, $score, $percentage, $score);
        $stmt->execute();
    }
    
    $conn->close();
    echo json_encode(['success' => true, 'score' => $score, 'total' => $total_attempted, 'percentage' => $percentage]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
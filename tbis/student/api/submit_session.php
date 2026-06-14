<?php
// tbis/student/api/submit_session.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

require_once '../../includes/config.php';
global $pdo;

$data = json_decode(file_get_contents('php://input'), true);
$session_id = $data['session_id'] ?? 0;
$student_id = $_SESSION['user_id'];
$school_id = $_SESSION['school_id'] ?? SCHOOL_ID;

if (!$session_id) {
    echo json_encode(['success' => false, 'error' => 'Missing session ID']);
    exit();
}

try {
    $pdo->beginTransaction();
    
    // Get all answers for this session
    $query = "SELECT waec_practice_answers.*, waec_questions.correct_answer, 
                     waec_questions.waec_subject_id, waec_questions.waec_topic_id
              FROM waec_practice_answers
              JOIN waec_questions ON waec_practice_answers.waec_question_id = waec_questions.id
              WHERE waec_practice_answers.session_id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$session_id]);
    $answers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate score
    $score = 0;
    $total_attempted = 0;
    $topic_stats = [];
    
    foreach ($answers as $answer) {
        if ($answer['student_answer']) {
            $total_attempted++;
            $is_correct = ($answer['student_answer'] === $answer['correct_answer']) ? 1 : 0;
            if ($is_correct) $score++;
            
            // Update answer with is_correct
            $update_query = "UPDATE waec_practice_answers SET is_correct = ? WHERE id = ?";
            $update_stmt = $pdo->prepare($update_query);
            $update_stmt->execute([$is_correct, $answer['id']]);
            
            // Track topic performance
            if ($answer['waec_topic_id']) {
                $topic_key = $answer['waec_subject_id'] . '_' . $answer['waec_topic_id'];
                if (!isset($topic_stats[$topic_key])) {
                    $topic_stats[$topic_key] = [
                        'correct' => 0, 
                        'total' => 0, 
                        'subject_id' => $answer['waec_subject_id'], 
                        'topic_id' => $answer['waec_topic_id']
                    ];
                }
                $topic_stats[$topic_key]['total']++;
                if ($is_correct) $topic_stats[$topic_key]['correct']++;
            }
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
    $stmt = $pdo->prepare($update_session);
    $stmt->execute([$score, $total_attempted, $percentage, $session_id, $student_id]);
    
    // Update topic performance
    foreach ($topic_stats as $stat) {
        if (!$stat['topic_id']) continue;
        
        // Check if exists
        $check_query = "SELECT id FROM waec_topic_performance 
                        WHERE student_id = ? AND waec_subject_id = ? AND waec_topic_id = ?";
        $check = $pdo->prepare($check_query);
        $check->execute([$student_id, $stat['subject_id'], $stat['topic_id']]);
        
        if ($check->fetch()) {
            // Update existing
            $topic_query = "UPDATE waec_topic_performance 
                            SET total_attempts = total_attempts + ?,
                                total_correct = total_correct + ?,
                                avg_percentage = (total_correct / total_attempts) * 100,
                                last_practiced_at = NOW(),
                                updated_at = NOW()
                            WHERE student_id = ? AND waec_subject_id = ? AND waec_topic_id = ?";
            $stmt = $pdo->prepare($topic_query);
            $stmt->execute([$stat['total'], $stat['correct'], $student_id, $stat['subject_id'], $stat['topic_id']]);
        } else {
            // Insert new
            $topic_query = "INSERT INTO waec_topic_performance 
                            (student_id, school_id, waec_subject_id, waec_topic_id, total_attempts, total_correct, avg_percentage, last_practiced_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $avg_pct = ($stat['correct'] / $stat['total']) * 100;
            $stmt = $pdo->prepare($topic_query);
            $stmt->execute([$student_id, $school_id, $stat['subject_id'], $stat['topic_id'], $stat['total'], $stat['correct'], $avg_pct]);
        }
        
        // Update mastery level
        $mastery_query = "UPDATE waec_topic_performance 
                          SET mastery_level = CASE
                              WHEN avg_percentage >= 80 THEN 'mastered'
                              WHEN avg_percentage >= 60 THEN 'strong'
                              WHEN avg_percentage >= 40 THEN 'progressing'
                              WHEN avg_percentage >= 20 THEN 'needs_work'
                              ELSE 'not_started'
                          END
                          WHERE student_id = ? AND waec_subject_id = ? AND waec_topic_id = ?";
        $stmt = $pdo->prepare($mastery_query);
        $stmt->execute([$student_id, $stat['subject_id'], $stat['topic_id']]);
    }
    
    // Update subject performance summary
    $first_subject = !empty($topic_stats) ? array_values($topic_stats)[0]['subject_id'] : null;
    if ($first_subject) {
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
        
        $stmt = $pdo->prepare($subject_query);
        $stmt->execute([$student_id, $school_id, $first_subject, $total_attempted, $score, $percentage, $score]);
    }
    
    $pdo->commit();
    echo json_encode(['success' => true, 'score' => $score, 'total' => $total_attempted, 'percentage' => round($percentage, 2)]);
    
} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
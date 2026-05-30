<?php
// File: msv/student/waec-session.php
// WAEC Practice Session - CBT Interface

session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: /msv/login.php");
    exit();
}

$student_id = $_SESSION['user_id'];
$school_id = $_SESSION['school_id'] ?? 1;

// Get practice parameters
$mode = $_POST['mode'] ?? $_GET['mode'] ?? '';
$session_mode = $_POST['session_mode'] ?? 'standard';
$subject_id = intval($_POST['subject_id'] ?? 0);
$topic_id = isset($_POST['topic_id']) ? intval($_POST['topic_id']) : null;
$exam_year = isset($_POST['exam_year']) ? intval($_POST['exam_year']) : null;
$custom_questions = intval($_POST['custom_questions'] ?? 20);
$custom_duration = intval($_POST['custom_duration'] ?? 30);

if (!$mode || !$subject_id) {
    header("Location: waec-practices.php?error=invalid_parameters");
    exit();
}

try {
    $conn = getDbConnection();
    
    // Get subject details
    $subject_query = "SELECT * FROM waec_subjects WHERE id = ?";
    $stmt = $conn->prepare($subject_query);
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    $subject = $stmt->get_result()->fetch_assoc();
    
    if (!$subject) {
        throw new Exception("Subject not found");
    }
    
    // Determine number of questions and duration
    if ($session_mode === 'standard') {
        $total_questions = $subject['standard_question_count'];
        $duration_minutes = $subject['standard_duration_minutes'];
    } else {
        $total_questions = min($custom_questions, 100);
        $duration_minutes = min($custom_duration, 180);
    }
    
    // Build query to fetch questions
    $questions_query = "SELECT * FROM waec_questions WHERE waec_subject_id = ? AND is_active = 1";
    $params = [$subject_id];
    $types = "i";
    
    if ($mode === 'topical' && $topic_id) {
        $questions_query .= " AND waec_topic_id = ?";
        $params[] = $topic_id;
        $types .= "i";
    } elseif ($mode === 'year' && $exam_year) {
        $questions_query .= " AND exam_year = ?";
        $params[] = $exam_year;
        $types .= "i";
    }
    
    // Shuffle and limit questions
    $stmt = $conn->prepare($questions_query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $all_questions = $result->fetch_all(MYSQLI_ASSOC);
    
    if (count($all_questions) < $total_questions) {
        $total_questions = count($all_questions);
    }
    
    // Shuffle and select questions
    shuffle($all_questions);
    $selected_questions = array_slice($all_questions, 0, $total_questions);
    
    // Create practice session
    $subject_ids_json = json_encode([$subject_id]);
    $start_time = date('Y-m-d H:i:s');
    
    $insert_query = "INSERT INTO waec_practice_sessions 
                     (student_id, school_id, waec_subject_id, waec_topic_id, practice_mode, 
                      session_mode, exam_year, subject_ids_json, total_questions, duration_minutes, 
                      start_time, status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'in_progress', NOW())";
    
    $stmt = $conn->prepare($insert_query);
    $practice_mode = $mode;
    $waec_topic_id = $topic_id;
    $waec_subject_id = $subject_id;
    
    $stmt->bind_param("iiiiisssiis", 
        $student_id, $school_id, $waec_subject_id, $waec_topic_id, $practice_mode,
        $session_mode, $exam_year, $subject_ids_json, $total_questions, $duration_minutes,
        $start_time
    );
    $stmt->execute();
    $session_id = $conn->insert_id;
    
    // Store questions for this session
    $insert_question = "INSERT INTO waec_practice_answers 
                        (session_id, waec_question_id, question_order, correct_answer) 
                        VALUES (?, ?, ?, ?)";
    $stmt_q = $conn->prepare($insert_question);
    
    $order = 1;
    foreach ($selected_questions as $question) {
        $stmt_q->bind_param("iiis", $session_id, $question['id'], $order, $question['correct_answer']);
        $stmt_q->execute();
        $order++;
    }
    
    $conn->close();
    
    // Store session data for the practice page
    $_SESSION['waec_session'] = [
        'session_id' => $session_id,
        'total_questions' => $total_questions,
        'duration_minutes' => $duration_minutes,
        'subject_name' => $subject['subject_name']
    ];
    
    header("Location: waec-practice-take.php?session_id=" . $session_id);
    exit();
    
} catch (Exception $e) {
    error_log("WAEC Session Error: " . $e->getMessage());
    header("Location: waec-practices.php?error=" . urlencode($e->getMessage()));
    exit();
}
?>
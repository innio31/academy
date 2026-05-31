<?php
// Add this for debugging - remove after testing
error_log("WAEC Session Debug - POST data: " . print_r($_POST, true));
// tbis/student/waec-session.php - Fixed with proper error handling
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: /tbis/login.php");
    exit();
}

global $pdo;

$student_id = $_SESSION['user_id'];
$school_id = $_SESSION['school_id'] ?? SCHOOL_ID;

// Get practice parameters
$mode = $_POST['mode'] ?? '';
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
    // Get subject details
    $subject_query = "SELECT * FROM waec_subjects WHERE id = ?";
    $stmt = $pdo->prepare($subject_query);
    $stmt->execute([$subject_id]);
    $subject = $stmt->fetch(PDO::FETCH_ASSOC);

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

    if ($mode === 'topical' && $topic_id) {
        $questions_query .= " AND waec_topic_id = ?";
        $params[] = $topic_id;
    } elseif ($mode === 'year' && $exam_year) {
        $questions_query .= " AND exam_year = ?";
        $params[] = $exam_year;
    }

    // Get questions
    $stmt = $pdo->prepare($questions_query);
    $stmt->execute($params);
    $all_questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // CHECK IF NO QUESTIONS FOUND
    if (count($all_questions) == 0) {
        // Store error message in session and redirect back
        $_SESSION['waec_error'] = "No questions available for this selection yet. We're still building our question bank. Please check back later!";
        header("Location: waec-practices.php?error=no_questions");
        exit();
    }

    // Check if enough questions exist
    if (count($all_questions) < $total_questions) {
        $total_questions = count($all_questions);
        $_SESSION['waec_warning'] = "Only " . $total_questions . " questions available for this selection. Using all available questions.";
    }

    // Shuffle and select questions
    shuffle($all_questions);
    $selected_questions = array_slice($all_questions, 0, $total_questions);

    // Check if we have any questions to practice
    if ($total_questions == 0) {
        $_SESSION['waec_error'] = "No questions available for this selection yet. Please try a different subject or topic.";
        header("Location: waec-practices.php?error=no_questions");
        exit();
    }

    // Create practice session - REMOVED subject_ids_json column
    $start_time = date('Y-m-d H:i:s');

    // First, check if the table has the columns we need
    // Some tables might not have all columns, so we'll use a basic insert
    $insert_query = "INSERT INTO waec_practice_sessions 
                     (student_id, school_id, waec_subject_id, waec_topic_id, practice_mode, 
                      session_mode, exam_year, total_questions, duration_minutes, 
                      start_time, status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'in_progress', NOW())";

    $stmt = $pdo->prepare($insert_query);
    $stmt->execute([
        $student_id,
        $school_id,
        $subject_id,
        $topic_id,
        $mode,
        $session_mode,
        $exam_year,
        $total_questions,
        $duration_minutes,
        $start_time
    ]);
    $session_id = $pdo->lastInsertId();

    // Store questions for this session
    $insert_question = "INSERT INTO waec_practice_answers 
                        (session_id, waec_question_id, question_order, correct_answer) 
                        VALUES (?, ?, ?, ?)";
    $stmt_q = $pdo->prepare($insert_question);

    $order = 1;
    foreach ($selected_questions as $question) {
        $stmt_q->execute([$session_id, $question['id'], $order, $question['correct_answer']]);
        $order++;
    }

    // Store session data for the practice page
    $_SESSION['waec_session'] = [
        'session_id' => $session_id,
        'total_questions' => $total_questions,
        'duration_minutes' => $duration_minutes,
        'subject_name' => $subject['subject_name']
    ];

    header("Location: waec-practice-take.php?session_id=" . $session_id);
    exit();
} catch (PDOException $e) {
    error_log("WAEC Session Error: " . $e->getMessage());

    // Check if it's a missing column error
    if (strpos($e->getMessage(), 'subject_ids_json') !== false) {
        // Try again without the problematic column
        try {
            $start_time = date('Y-m-d H:i:s');
            $insert_query = "INSERT INTO waec_practice_sessions 
                             (student_id, school_id, waec_subject_id, waec_topic_id, practice_mode, 
                              session_mode, exam_year, total_questions, duration_minutes, 
                              start_time, status, created_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'in_progress', NOW())";

            $stmt = $pdo->prepare($insert_query);
            $stmt->execute([
                $student_id,
                $school_id,
                $subject_id,
                $topic_id,
                $mode,
                $session_mode,
                $exam_year,
                $total_questions,
                $duration_minutes,
                $start_time
            ]);
            $session_id = $pdo->lastInsertId();

            // Store questions...
            $insert_question = "INSERT INTO waec_practice_answers 
                                (session_id, waec_question_id, question_order, correct_answer) 
                                VALUES (?, ?, ?, ?)";
            $stmt_q = $pdo->prepare($insert_question);

            $order = 1;
            foreach ($selected_questions as $question) {
                $stmt_q->execute([$session_id, $question['id'], $order, $question['correct_answer']]);
                $order++;
            }

            header("Location: waec-practice-take.php?session_id=" . $session_id);
            exit();
        } catch (Exception $e2) {
            $_SESSION['waec_error'] = "Database error: " . $e2->getMessage();
            header("Location: waec-practices.php?error=db_error");
            exit();
        }
    } else {
        $_SESSION['waec_error'] = "Error: " . $e->getMessage();
        header("Location: waec-practices.php?error=" . urlencode($e->getMessage()));
        exit();
    }
} catch (Exception $e) {
    error_log("WAEC Session Error: " . $e->getMessage());
    $_SESSION['waec_error'] = $e->getMessage();
    header("Location: waec-practices.php?error=" . urlencode($e->getMessage()));
    exit();
}

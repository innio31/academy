<?php
// staff/update_question.php - Handle question updates (Staff version)
session_start();

// Check if staff is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: /msv/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$staff_id = $_SESSION['user_id'];

// Get the staff_id string from staff table
$staff_id_string = '';
try {
    $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
    $stmt->execute([$staff_id, $school_id]);
    $staff_id_string = $stmt->fetchColumn();
    if (!$staff_id_string) {
        $staff_id_string = $_SESSION['staff_id'] ?? $staff_id;
    }
} catch (Exception $e) {
    $staff_id_string = $_SESSION['staff_id'] ?? $staff_id;
}

// Get assigned subject IDs for this staff member
$assigned_subject_ids = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT subject_id 
        FROM staff_subjects 
        WHERE staff_id = ? AND school_id = ?
    ");
    $stmt->execute([$staff_id_string, $school_id]);
    $assigned_subject_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Error fetching staff subjects: " . $e->getMessage());
}

// Redirect if no subjects assigned
if (empty($assigned_subject_ids)) {
    header("Location: index.php?message=No+subjects+assigned&type=error");
    exit();
}

$message = '';
$message_type = '';

// Helper function to verify staff has access to a question
function verifyStaffQuestionAccess($pdo, $question_id, $question_type, $assigned_subject_ids, $school_id) {
    $table = '';
    switch ($question_type) {
        case 'objective':
            $table = 'objective_questions';
            break;
        case 'subjective':
            $table = 'subjective_questions';
            break;
        case 'theory':
            $table = 'theory_questions';
            break;
        default:
            throw new Exception("Invalid question type");
    }
    
    $stmt = $pdo->prepare("
        SELECT q.*, t.subject_id 
        FROM $table q
        JOIN topics t ON q.topic_id = t.id
        WHERE q.id = ? AND q.school_id = ?
    ");
    $stmt->execute([$question_id, $school_id]);
    $question = $stmt->fetch();
    
    if (!$question) {
        throw new Exception("Question not found");
    }
    
    if (!in_array($question['subject_id'], $assigned_subject_ids)) {
        throw new Exception("You don't have permission to edit this question");
    }
    
    return $question;
}

// Handle Objective Question Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_question']) && isset($_POST['question_type']) && $_POST['question_type'] === 'objective') {
    try {
        $question_id = (int)$_POST['question_id'];
        $question_text = trim($_POST['question_text']);
        $option_a = trim($_POST['option_a']);
        $option_b = trim($_POST['option_b']);
        $option_c = trim($_POST['option_c'] ?? '');
        $option_d = trim($_POST['option_d'] ?? '');
        $correct_answer = $_POST['correct_answer'];
        $difficulty_level = $_POST['difficulty_level'] ?? 'medium';
        $marks = (int)$_POST['marks'];
        
        // Verify access
        verifyStaffQuestionAccess($pdo, $question_id, 'objective', $assigned_subject_ids, $school_id);
        
        if (empty($question_text)) throw new Exception("Question text is required");
        if (empty($option_a) || empty($option_b)) throw new Exception("At least options A and B are required");
        if (empty($correct_answer)) throw new Exception("Correct answer is required");
        
        $stmt = $pdo->prepare("
            UPDATE objective_questions 
            SET question_text = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?, 
                correct_answer = ?, difficulty_level = ?, marks = ?
            WHERE id = ? AND school_id = ?
        ");
        $stmt->execute([$question_text, $option_a, $option_b, $option_c, $option_d, $correct_answer, $difficulty_level, $marks, $question_id, $school_id]);
        
        $message = "Objective question updated successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Handle Subjective Question Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_question']) && isset($_POST['question_type']) && $_POST['question_type'] === 'subjective') {
    try {
        $question_id = (int)$_POST['question_id'];
        $question_text = trim($_POST['question_text']);
        $correct_answer = trim($_POST['correct_answer'] ?? '');
        $difficulty_level = $_POST['difficulty_level'] ?? 'medium';
        $marks = (int)$_POST['marks'];
        
        // Verify access
        verifyStaffQuestionAccess($pdo, $question_id, 'subjective', $assigned_subject_ids, $school_id);
        
        if (empty($question_text)) throw new Exception("Question text is required");
        
        $stmt = $pdo->prepare("
            UPDATE subjective_questions 
            SET question_text = ?, correct_answer = ?, difficulty_level = ?, marks = ?
            WHERE id = ? AND school_id = ?
        ");
        $stmt->execute([$question_text, $correct_answer, $difficulty_level, $marks, $question_id, $school_id]);
        
        $message = "Subjective question updated successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Handle Theory Question Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_question']) && isset($_POST['question_type']) && $_POST['question_type'] === 'theory') {
    try {
        $question_id = (int)$_POST['question_id'];
        $question_text = trim($_POST['question_text'] ?? '');
        $difficulty_level = $_POST['difficulty_level'] ?? 'medium';
        $marks = (int)$_POST['marks'];
        
        // Verify access
        $question = verifyStaffQuestionAccess($pdo, $question_id, 'theory', $assigned_subject_ids, $school_id);
        
        $question_file = $question['question_file']; // Keep existing file by default
        
        // Handle file upload
        if (isset($_FILES['question_file']) && $_FILES['question_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/questions/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Delete old file if exists
            if ($question_file && file_exists('../' . $question_file)) {
                unlink('../' . $question_file);
            }
            
            $ext = pathinfo($_FILES['question_file']['name'], PATHINFO_EXTENSION);
            $filename = 'theory_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            move_uploaded_file($_FILES['question_file']['tmp_name'], $upload_dir . $filename);
            $question_file = 'uploads/questions/' . $filename;
        }
        
        $stmt = $pdo->prepare("
            UPDATE theory_questions 
            SET question_text = ?, question_file = ?, difficulty_level = ?, marks = ?
            WHERE id = ? AND school_id = ?
        ");
        $stmt->execute([$question_text, $question_file, $difficulty_level, $marks, $question_id, $school_id]);
        
        $message = "Theory question updated successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Get topic_id from the question to redirect back
$redirect_topic_id = 0;
if (isset($_POST['question_id']) && isset($_POST['question_type'])) {
    try {
        $type = $_POST['question_type'];
        $qid = (int)$_POST['question_id'];
        $table = '';
        switch ($type) {
            case 'objective': $table = 'objective_questions'; break;
            case 'subjective': $table = 'subjective_questions'; break;
            case 'theory': $table = 'theory_questions'; break;
        }
        if ($table) {
            $stmt = $pdo->prepare("SELECT topic_id FROM $table WHERE id = ? AND school_id = ?");
            $stmt->execute([$qid, $school_id]);
            $redirect_topic_id = $stmt->fetchColumn();
        }
    } catch (Exception $e) {
        // Ignore, just won't redirect to specific topic
    }
}

// Redirect back
if ($redirect_topic_id) {
    header("Location: manage-questions.php?topic_id=$redirect_topic_id&message=" . urlencode($message) . "&type=" . $message_type);
} else {
    header("Location: manage-questions.php?message=" . urlencode($message) . "&type=" . $message_type);
}
exit();
?>
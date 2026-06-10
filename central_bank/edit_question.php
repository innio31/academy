<?php
// /central_bank/edit_question.php - Unified edit handler for all question types
// Receives POST requests from edit modals

require_once 'includes/config.php';
require_once 'includes/auth.php';
require_super_admin();

$message = '';
$message_type = '';
$redirect_tab = $_POST['tab'] ?? 'objective';
$redirect_page = isset($_POST['page']) ? (int)$_POST['page'] : 1;

// Build redirect URL parameters
$redirect_params = [];
if (isset($_POST['filter_subject']) && $_POST['filter_subject']) $redirect_params['filter_subject'] = $_POST['filter_subject'];
if (isset($_POST['filter_topic']) && $_POST['filter_topic']) $redirect_params['filter_topic'] = $_POST['filter_topic'];
if (isset($_POST['filter_year']) && $_POST['filter_year']) $redirect_params['filter_year'] = $_POST['filter_year'];
if (isset($_POST['filter_difficulty']) && $_POST['filter_difficulty']) $redirect_params['filter_difficulty'] = $_POST['filter_difficulty'];
if (isset($_POST['search']) && $_POST['search']) $redirect_params['search'] = $_POST['search'];
$redirect_params['page'] = $redirect_page;

$redirect_url = "manage_questions.php?tab=" . urlencode($redirect_tab) . "&" . http_build_query($redirect_params);

// ============================================
// EDIT OBJECTIVE QUESTION
// ============================================
if (isset($_POST['edit_objective'])) {
    try {
        $id = (int)$_POST['id'];
        $question_text = trim($_POST['question_text']);
        $option_a = trim($_POST['option_a']);
        $option_b = trim($_POST['option_b']);
        $option_c = trim($_POST['option_c'] ?? '');
        $option_d = trim($_POST['option_d'] ?? '');
        $correct_answer = strtoupper(trim($_POST['correct_answer']));
        $subject_id = (int)$_POST['subject_id'];
        $topic_id = (int)$_POST['topic_id'];
        $difficulty = $_POST['difficulty'];
        $marks = (int)$_POST['marks'];
        
        $stmt = $pdo->prepare("
            UPDATE objective_questions 
            SET question_text = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?,
                correct_answer = ?, subject_id = ?, topic_id = ?, difficulty_level = ?, marks = ?
            WHERE id = ? AND (is_central = 1 OR school_id IS NULL)
        ");
        $stmt->execute([$question_text, $option_a, $option_b, $option_c, $option_d, 
                       $correct_answer, $subject_id, $topic_id, $difficulty, $marks, $id]);
        
        $_SESSION['success_message'] = "Objective question updated successfully!";
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error updating objective question: " . $e->getMessage();
    }
    header("Location: " . $redirect_url);
    exit();
}

// ============================================
// EDIT SUBJECTIVE QUESTION
// ============================================
if (isset($_POST['edit_subjective'])) {
    try {
        $id = (int)$_POST['id'];
        $question_text = trim($_POST['question_text']);
        $correct_answer = trim($_POST['correct_answer'] ?? '');
        $subject_id = (int)$_POST['subject_id'];
        $topic_id = (int)$_POST['topic_id'];
        $difficulty = $_POST['difficulty'];
        $marks = (int)$_POST['marks'];
        
        $stmt = $pdo->prepare("
            UPDATE subjective_questions 
            SET question_text = ?, correct_answer = ?, subject_id = ?, topic_id = ?, 
                difficulty_level = ?, marks = ?
            WHERE id = ? AND (is_central = 1 OR school_id IS NULL)
        ");
        $stmt->execute([$question_text, $correct_answer, $subject_id, $topic_id, $difficulty, $marks, $id]);
        
        $_SESSION['success_message'] = "Subjective question updated successfully!";
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error updating subjective question: " . $e->getMessage();
    }
    header("Location: " . $redirect_url);
    exit();
}

// ============================================
// EDIT THEORY QUESTION
// ============================================
if (isset($_POST['edit_theory'])) {
    try {
        $id = (int)$_POST['id'];
        $question_text = trim($_POST['question_text']);
        $subject_id = (int)$_POST['subject_id'];
        $topic_id = (int)$_POST['topic_id'];
        $marks = (int)$_POST['marks'];
        
        $stmt = $pdo->prepare("
            UPDATE theory_questions 
            SET question_text = ?, subject_id = ?, topic_id = ?, marks = ?
            WHERE id = ? AND (is_central = 1 OR school_id IS NULL)
        ");
        $stmt->execute([$question_text, $subject_id, $topic_id, $marks, $id]);
        
        // Handle file upload if provided
        if (isset($_FILES['question_file']) && $_FILES['question_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/central_questions/';
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
            
            // Get old file to delete
            $stmt = $pdo->prepare("SELECT question_file FROM theory_questions WHERE id = ?");
            $stmt->execute([$id]);
            $old_file = $stmt->fetchColumn();
            if ($old_file && file_exists('../' . $old_file)) {
                unlink('../' . $old_file);
            }
            
            $ext = strtolower(pathinfo($_FILES['question_file']['name'], PATHINFO_EXTENSION));
            $filename = 'theory_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            move_uploaded_file($_FILES['question_file']['tmp_name'], $upload_dir . $filename);
            $question_file = 'uploads/central_questions/' . $filename;
            
            $stmt = $pdo->prepare("UPDATE theory_questions SET question_file = ? WHERE id = ?");
            $stmt->execute([$question_file, $id]);
        }
        
        $_SESSION['success_message'] = "Theory question updated successfully!";
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error updating theory question: " . $e->getMessage();
    }
    header("Location: " . $redirect_url);
    exit();
}

// ============================================
// EDIT WAEC QUESTION
// ============================================
if (isset($_POST['edit_waec'])) {
    try {
        $id = (int)$_POST['id'];
        $subject_id = (int)$_POST['subject_id'];
        $topic_id = (int)$_POST['topic_id'];
        $exam_year = (int)$_POST['exam_year'];
        $question_text = trim($_POST['question_text']);
        $option_a = trim($_POST['option_a']);
        $option_b = trim($_POST['option_b']);
        $option_c = trim($_POST['option_c']);
        $option_d = trim($_POST['option_d'] ?? '');
        $option_e = trim($_POST['option_e'] ?? '');
        $correct_answer = strtoupper(trim($_POST['correct_answer']));
        $explanation = trim($_POST['explanation'] ?? '');
        $difficulty = $_POST['difficulty'] ?? 'medium';
        
        $stmt = $pdo->prepare("
            UPDATE waec_questions 
            SET waec_subject_id = ?, waec_topic_id = ?, exam_year = ?, question_text = ?,
                option_a = ?, option_b = ?, option_c = ?, option_d = ?, option_e = ?,
                correct_answer = ?, explanation = ?, difficulty_level = ?
            WHERE id = ?
        ");
        $stmt->execute([$subject_id, $topic_id, $exam_year, $question_text, 
                       $option_a, $option_b, $option_c, $option_d, $option_e,
                       $correct_answer, $explanation, $difficulty, $id]);
        
        // Handle image upload
        if (isset($_FILES['question_image']) && $_FILES['question_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/central_questions/';
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $stmt = $pdo->prepare("SELECT question_image FROM waec_questions WHERE id = ?");
            $stmt->execute([$id]);
            $old_image = $stmt->fetchColumn();
            if ($old_image && file_exists('../' . $old_image)) {
                unlink('../' . $old_image);
            }
            
            $ext = strtolower(pathinfo($_FILES['question_image']['name'], PATHINFO_EXTENSION));
            $filename = 'waec_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            move_uploaded_file($_FILES['question_image']['tmp_name'], $upload_dir . $filename);
            $question_image = 'uploads/central_questions/' . $filename;
            
            $stmt = $pdo->prepare("UPDATE waec_questions SET question_image = ? WHERE id = ?");
            $stmt->execute([$question_image, $id]);
        }
        
        $_SESSION['success_message'] = "WAEC question updated successfully!";
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error updating WAEC question: " . $e->getMessage();
    }
    header("Location: " . $redirect_url);
    exit();
}

// ============================================
// EDIT JAMB QUESTION
// ============================================
if (isset($_POST['edit_jamb'])) {
    try {
        $id = (int)$_POST['id'];
        $subject_id = (int)$_POST['subject_id'];
        $topic_id = (int)$_POST['topic_id'];
        $exam_year = (int)$_POST['exam_year'];
        $question_text = trim($_POST['question_text']);
        $option_a = trim($_POST['option_a']);
        $option_b = trim($_POST['option_b']);
        $option_c = trim($_POST['option_c']);
        $option_d = trim($_POST['option_d']);
        $correct_answer = strtoupper(trim($_POST['correct_answer']));
        $explanation = trim($_POST['explanation'] ?? '');
        $difficulty = $_POST['difficulty'] ?? 'medium';
        
        $stmt = $pdo->prepare("
            UPDATE jamb_questions 
            SET jamb_subject_id = ?, jamb_topic_id = ?, exam_year = ?, question_text = ?,
                option_a = ?, option_b = ?, option_c = ?, option_d = ?,
                correct_answer = ?, explanation = ?, difficulty_level = ?
            WHERE id = ?
        ");
        $stmt->execute([$subject_id, $topic_id, $exam_year, $question_text, 
                       $option_a, $option_b, $option_c, $option_d,
                       $correct_answer, $explanation, $difficulty, $id]);
        
        // Handle image upload
        if (isset($_FILES['question_image']) && $_FILES['question_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/central_questions/';
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $stmt = $pdo->prepare("SELECT question_image FROM jamb_questions WHERE id = ?");
            $stmt->execute([$id]);
            $old_image = $stmt->fetchColumn();
            if ($old_image && file_exists('../' . $old_image)) {
                unlink('../' . $old_image);
            }
            
            $ext = strtolower(pathinfo($_FILES['question_image']['name'], PATHINFO_EXTENSION));
            $filename = 'jamb_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            move_uploaded_file($_FILES['question_image']['tmp_name'], $upload_dir . $filename);
            $question_image = 'uploads/central_questions/' . $filename;
            
            $stmt = $pdo->prepare("UPDATE jamb_questions SET question_image = ? WHERE id = ?");
            $stmt->execute([$question_image, $id]);
        }
        
        $_SESSION['success_message'] = "JAMB question updated successfully!";
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error updating JAMB question: " . $e->getMessage();
    }
    header("Location: " . $redirect_url);
    exit();
}

// If no valid action, redirect back
header("Location: manage_questions.php");
exit();
?>
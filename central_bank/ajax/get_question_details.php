<?php
// /central_bank/ajax/get_question_details.php - AJAX endpoint to fetch question details
// Supports objective, subjective, theory, waec, jamb question types

require_once '../includes/config.php';
require_once '../includes/auth.php';

// Only allow AJAX requests
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) && !isset($_GET['ajax'])) {
    header('HTTP/1.1 403 Forbidden');
    exit('Direct access not allowed');
}

header('Content-Type: application/json');

// Check if user is logged in and is super admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'central_admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$type = isset($_GET['type']) ? $_GET['type'] : (isset($_POST['type']) ? $_POST['type'] : '');
$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);

if (!$type || !$id) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters (type and id)']);
    exit();
}

try {
    $question = null;
    
    switch ($type) {
        case 'objective':
            $stmt = $pdo->prepare("
                SELECT oq.*, s.subject_name, t.topic_name 
                FROM objective_questions oq
                LEFT JOIN subjects s ON oq.subject_id = s.id
                LEFT JOIN topics t ON oq.topic_id = t.id
                WHERE oq.id = ? AND (oq.is_central = 1 OR oq.school_id IS NULL)
            ");
            $stmt->execute([$id]);
            $question = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
            
        case 'subjective':
            $stmt = $pdo->prepare("
                SELECT sq.*, s.subject_name, t.topic_name 
                FROM subjective_questions sq
                LEFT JOIN subjects s ON sq.subject_id = s.id
                LEFT JOIN topics t ON sq.topic_id = t.id
                WHERE sq.id = ? AND (sq.is_central = 1 OR sq.school_id IS NULL)
            ");
            $stmt->execute([$id]);
            $question = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
            
        case 'theory':
            $stmt = $pdo->prepare("
                SELECT tq.*, s.subject_name, t.topic_name 
                FROM theory_questions tq
                LEFT JOIN subjects s ON tq.subject_id = s.id
                LEFT JOIN topics t ON tq.topic_id = t.id
                WHERE tq.id = ? AND (tq.is_central = 1 OR tq.school_id IS NULL)
            ");
            $stmt->execute([$id]);
            $question = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
            
        case 'waec':
            $stmt = $pdo->prepare("
                SELECT wq.*, ws.subject_name, wt.topic_name 
                FROM waec_questions wq
                LEFT JOIN waec_subjects ws ON wq.waec_subject_id = ws.id
                LEFT JOIN waec_topics wt ON wq.waec_topic_id = wt.id
                WHERE wq.id = ? AND wq.is_active = 1
            ");
            $stmt->execute([$id]);
            $question = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
            
        case 'jamb':
            $stmt = $pdo->prepare("
                SELECT jq.*, js.subject_name, jt.topic_name 
                FROM jamb_questions jq
                LEFT JOIN jamb_subjects js ON jq.jamb_subject_id = js.id
                LEFT JOIN jamb_topics jt ON jq.jamb_topic_id = jt.id
                WHERE jq.id = ? AND jq.is_active = 1
            ");
            $stmt->execute([$id]);
            $question = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid question type']);
            exit();
    }
    
    if (!$question) {
        echo json_encode(['success' => false, 'message' => 'Question not found']);
        exit();
    }
    
    // Sanitize output
    $question['question_text'] = html_entity_decode($question['question_text']);
    if (isset($question['option_a'])) $question['option_a'] = html_entity_decode($question['option_a']);
    if (isset($question['option_b'])) $question['option_b'] = html_entity_decode($question['option_b']);
    if (isset($question['option_c'])) $question['option_c'] = html_entity_decode($question['option_c']);
    if (isset($question['option_d'])) $question['option_d'] = html_entity_decode($question['option_d']);
    if (isset($question['option_e'])) $question['option_e'] = html_entity_decode($question['option_e']);
    if (isset($question['explanation'])) $question['explanation'] = html_entity_decode($question['explanation']);
    if (isset($question['correct_answer'])) $question['correct_answer'] = html_entity_decode($question['correct_answer']);
    
    echo json_encode([
        'success' => true,
        'question' => $question
    ]);
    
} catch (Exception $e) {
    error_log("Error fetching question details: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
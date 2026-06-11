<?php
// admin/ajax/get_question.php - Get question details for modal

session_start();
require_once '../../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$type = isset($_GET['type']) ? $_GET['type'] : '';

if (!$id || !$type) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

$school_id = SCHOOL_ID;
$table = match($type) {
    'objective' => 'objective_questions',
    'subjective' => 'subjective_questions',
    'theory' => 'theory_questions',
    default => ''
};

if (!$table) {
    echo json_encode(['success' => false, 'message' => 'Invalid question type']);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ? AND school_id = ?");
    $stmt->execute([$id, $school_id]);
    $question = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($question) {
        // Fix image path if needed
        if (!empty($question['question_image']) && strpos($question['question_image'], '../') !== 0 && strpos($question['question_image'], 'uploads/') !== 0) {
            $question['question_image'] = 'uploads/questions/' . basename($question['question_image']);
        }
        echo json_encode(['success' => true, 'question' => $question]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Question not found']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
<?php
// tbis/student/api/save_flag.php
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
$question_order = $data['question_order'] ?? 0;
$flagged = $data['flagged'] ?? 0;

if (!$session_id || !$question_order) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit();
}

try {
    $query = "UPDATE waec_practice_answers 
              SET is_flagged = ? 
              WHERE session_id = ? AND question_order = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$flagged, $session_id, $question_order]);
    
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
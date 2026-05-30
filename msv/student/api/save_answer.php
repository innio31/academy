<?php
// File: msv/student/api/save_answer.php

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$session_id = $data['session_id'] ?? 0;
$question_order = $data['question_order'] ?? 0;
$answer = $data['answer'] ?? null;

if (!$session_id || !$question_order) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit();
}

require_once '../../includes/config.php';

try {
    global $pdo;
$conn = $pdo;
    
    $query = "UPDATE waec_practice_answers 
              SET student_answer = ? 
              WHERE session_id = ? AND question_order = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sii", $answer, $session_id, $question_order);
    $stmt->execute();
    
    $conn->close();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
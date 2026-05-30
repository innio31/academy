<?php
// File: msv/student/api/get_topics.php
// API endpoint for fetching WAEC topics by subject

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

require_once '../../includes/config.php';

$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;

if (!$subject_id) {
    echo json_encode(['success' => false, 'error' => 'Subject ID required']);
    exit();
}

try {
    $conn = getDbConnection();
    $query = "SELECT id, topic_name FROM waec_topics WHERE waec_subject_id = ? AND is_active = 1 ORDER BY sort_order, topic_name";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $topics = $result->fetch_all(MYSQLI_ASSOC);
    $conn->close();
    
    echo json_encode(['success' => true, 'topics' => $topics]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
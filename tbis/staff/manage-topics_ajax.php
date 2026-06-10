<?php
// staff/manage-topics_ajax.php - AJAX endpoint for topic details (Staff version)
session_start();

// Check if staff is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'staff') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

require_once '../includes/config.php';

header('Content-Type: application/json');

$school_id = SCHOOL_ID;
$staff_id = $_SESSION['user_id'];

// Get the staff_id string from staff table (used in staff_subjects)
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

// If no subjects assigned, deny access
if (empty($assigned_subject_ids)) {
    echo json_encode(['success' => false, 'message' => 'No subjects assigned to you']);
    exit();
}

// Get topic_id from request
$topic_id = isset($_GET['topic_id']) ? (int)$_GET['topic_id'] : 0;

if (!$topic_id) {
    echo json_encode(['success' => false, 'message' => 'Topic ID required']);
    exit();
}

try {
    // Fetch topic details with counts
    $stmt = $pdo->prepare("
        SELECT 
            t.*,
            s.subject_name,
            s.id as subject_id,
            (SELECT COUNT(*) FROM objective_questions WHERE topic_id = t.id AND school_id = t.school_id) as objective_count,
            (SELECT COUNT(*) FROM subjective_questions WHERE topic_id = t.id AND school_id = t.school_id) as subjective_count,
            (SELECT COUNT(*) FROM theory_questions WHERE topic_id = t.id AND school_id = t.school_id) as theory_count
        FROM topics t
        JOIN subjects s ON t.subject_id = s.id
        WHERE t.id = ? AND t.school_id = ?
    ");
    $stmt->execute([$topic_id, $school_id]);
    $topic = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$topic) {
        echo json_encode(['success' => false, 'message' => 'Topic not found']);
        exit();
    }

    // Verify staff has access to this topic's subject
    if (!in_array($topic['subject_id'], $assigned_subject_ids)) {
        echo json_encode(['success' => false, 'message' => 'You do not have access to this topic']);
        exit();
    }

    echo json_encode([
        'success' => true,
        'topic' => [
            'id' => $topic['id'],
            'topic_name' => $topic['topic_name'],
            'subject_name' => $topic['subject_name'],
            'subject_id' => $topic['subject_id'],
            'term' => $topic['term'],
            'description' => $topic['description'],
            'objective_count' => (int)$topic['objective_count'],
            'subjective_count' => (int)$topic['subjective_count'],
            'theory_count' => (int)$topic['theory_count']
        ]
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
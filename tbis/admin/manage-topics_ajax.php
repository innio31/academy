<?php
// admin/manage-topics_ajax.php - AJAX endpoint for topic details
session_start();

require_once '../includes/config.php';

header('Content-Type: application/json');

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

if (isset($_SESSION['admin_id'])) {
    $admin_id = $_SESSION['admin_id'];
    $admin_role = $_SESSION['admin_role'] ?? 'super_admin';
} else {
    $admin_id = $_SESSION['user_id'];
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

if ($admin_role !== 'super_admin' && $admin_role !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

$school_id = SCHOOL_ID;

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

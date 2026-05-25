<?php
// admin/mark_attendance_api.php - API for marking attendance
session_start();
require_once '../includes/config.php';

header('Content-Type: application/json');

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit();
}

$student_id = $_POST['student_id'] ?? 0;
$status = $_POST['status'] ?? 'present';
$date = $_POST['date'] ?? date('Y-m-d');
$school_id = SCHOOL_ID;

if (!$student_id) {
    echo json_encode(['success' => false, 'error' => 'Student ID required']);
    exit();
}

// Validate status
$allowed_status = ['present', 'absent', 'late'];
if (!in_array($status, $allowed_status)) {
    echo json_encode(['success' => false, 'error' => 'Invalid status']);
    exit();
}

try {
    // Check if attendance already exists
    $stmt = $pdo->prepare("SELECT id FROM attendance WHERE student_id = ? AND date = ? AND school_id = ?");
    $stmt->execute([$student_id, $date, $school_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Update existing
        $stmt = $pdo->prepare("UPDATE attendance SET status = ?, created_at = NOW() WHERE student_id = ? AND date = ? AND school_id = ?");
        $result = $stmt->execute([$status, $student_id, $date, $school_id]);
    } else {
        // Insert new
        $stmt = $pdo->prepare("INSERT INTO attendance (student_id, date, status, school_id) VALUES (?, ?, ?, ?)");
        $result = $stmt->execute([$student_id, $date, $status, $school_id]);
    }
    
    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Attendance marked successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
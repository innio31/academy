<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'Student ID required']);
    exit();
}

$student_id = $_GET['id'];

$stmt = $pdo->prepare("
    SELECT 
        s.*,
        u.first_name, u.last_name, u.email, u.phone,
        d.name as department_name,
        f.name as faculty_name
    FROM students s
    JOIN users u ON s.user_id = u.id
    JOIN departments d ON s.department_id = d.id
    JOIN faculties f ON d.faculty_id = f.id
    WHERE s.id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if ($student) {
    echo json_encode([
        'success' => true,
        'full_name' => $student['first_name'] . ' ' . $student['last_name'],
        'initials' => strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)),
        'reg_number' => $student['reg_number'],
        'email' => $student['email'],
        'phone' => $student['phone'],
        'department' => $student['department_name'],
        'faculty' => $student['faculty_name'],
        'current_level' => $student['current_level'],
        'guardian_name' => $student['guardian_name'],
        'guardian_phone' => $student['guardian_phone'],
        'id_card_issued' => $student['id_card_issued'],
        'enrollment_date' => $student['enrollment_date']
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Student not found']);
}
?>
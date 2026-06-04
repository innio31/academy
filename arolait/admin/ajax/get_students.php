<?php
require_once '../../includes/config.php';
require_once '../../includes/auth.php';
requireRole(['super_admin', 'admin']);

header('Content-Type: application/json');

// Get parameters
$search = $_GET['search'] ?? '';
$department_id = $_GET['department_id'] ?? '';
$level = $_GET['level'] ?? '';
$page = $_GET['page'] ?? 1;
$limit = $_GET['limit'] ?? 20;
$offset = ($page - 1) * $limit;

// Build query
$sql = "SELECT 
            s.id, s.reg_number, s.current_level, s.id_card_issued,
            u.first_name, u.last_name, u.email, u.phone,
            d.name as department_name, d.code as department_code,
            f.name as faculty_name
        FROM students s
        JOIN users u ON s.user_id = u.id
        JOIN departments d ON s.department_id = d.id
        JOIN faculties f ON d.faculty_id = f.id
        WHERE 1=1";
$countSql = "SELECT COUNT(*) as total FROM students s WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR s.reg_number LIKE ?)";
    $countSql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR s.reg_number LIKE ?)";
    $searchParam = "%$search%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam]);
}

if ($department_id) {
    $sql .= " AND s.department_id = ?";
    $countSql .= " AND s.department_id = ?";
    $params[] = $department_id;
}

if ($level) {
    $sql .= " AND s.current_level = ?";
    $countSql .= " AND s.current_level = ?";
    $params[] = $level;
}

// Get total count
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = $stmt->fetch()['total'];

// Get paginated results
$sql .= " ORDER BY u.last_name ASC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Format response
$response = [
    'success' => true,
    'data' => [],
    'pagination' => [
        'current_page' => $page,
        'per_page' => $limit,
        'total' => $total,
        'last_page' => ceil($total / $limit)
    ]
];

foreach ($students as $student) {
    $response['data'][] = [
        'id' => $student['id'],
        'reg_number' => $student['reg_number'],
        'name' => $student['first_name'] . ' ' . $student['last_name'],
        'first_name' => $student['first_name'],
        'last_name' => $student['last_name'],
        'email' => $student['email'],
        'phone' => $student['phone'],
        'level' => $student['current_level'],
        'department' => $student['department_name'],
        'faculty' => $student['faculty_name'],
        'id_card_issued' => (bool)$student['id_card_issued'],
        'qr_code_exists' => file_exists('../../assets/qrcodes/' . $student['reg_number'] . '.png')
    ];
}

echo json_encode($response);
?>
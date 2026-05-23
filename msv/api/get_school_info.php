<?php
// gos/api/get_school_info.php - Get school info by code
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'db_config.php';

$school_code = $_GET['school_code'] ?? '';

if (!$school_code) {
    echo json_encode(['error' => 'School code required']);
    exit;
}

try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT id, school_code, school_name FROM schools WHERE school_code = ? AND status = 'active'");
    $stmt->execute([$school_code]);
    $school = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($school) {
        echo json_encode([
            'school_id' => $school['id'],
            'school_code' => $school['school_code'],
            'school_name' => $school['school_name']
        ]);
    } else {
        echo json_encode(['error' => 'School not found']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

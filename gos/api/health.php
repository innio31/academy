<?php
// api/health.php - Health check endpoint
// Location: acad.com.ng/gos/api/health.php

header('Content-Type: application/json');

try {
    require_once 'db_config.php';
    $pdo = getDBConnection();

    // Test database connection
    $stmt = $pdo->query("SELECT 1");

    echo json_encode([
        'status' => 'healthy',
        'database' => 'connected',
        'timestamp' => date('Y-m-d H:i:s'),
        'version' => '1.0.0'
    ]);
} catch (Exception $e) {
    http_response_code(503);
    echo json_encode([
        'status' => 'unhealthy',
        'database' => 'disconnected',
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

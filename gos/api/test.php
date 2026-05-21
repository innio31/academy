<?php
// api/test.php - Test API connection
// Location: acad.com.ng/gos/api/test.php

header('Content-Type: application/json');

echo json_encode([
    'status' => 'success',
    'message' => 'API is working',
    'timestamp' => date('Y-m-d H:i:s'),
    'endpoints' => [
        'sync' => '/gos/api/sync.php',
        'health' => '/gos/api/health.php',
        'test' => '/gos/api/test.php'
    ]
]);

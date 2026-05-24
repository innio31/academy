<?php
// gos/api/test.php - Simple test endpoint
header('Content-Type: application/json');

echo json_encode([
    'status' => 'success',
    'message' => 'API is working',
    'timestamp' => date('Y-m-d H:i:s'),
    'server' => $_SERVER['SERVER_NAME'],
    'php_version' => PHP_VERSION
]);

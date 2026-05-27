<?php
// /central_bank/api/includes/functions.php

function json_response($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

function json_error($message, $status_code = 400) {
    json_response([
        'success' => false,
        'error' => $message
    ], $status_code);
}

function verify_api_key() {
    // Check for API key in headers
    $headers = getallheaders();
    $api_key = $headers['X-API-Key'] ?? $_GET['api_key'] ?? null;
    
    // Your valid API key
    $valid_key = '33118913968799983134133712965617';
    
    if ($api_key === $valid_key) {
        return true;
    }
    
    // Also check if it's coming from localhost (for testing)
    $allowed_origins = [
        'https://acad.com.ng',
        'https://www.acad.com.ng',
        'http://localhost',
        'http://127.0.0.1'
    ];
    
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $allowed_origins)) {
        return true;
    }
    
    return false;
}

function getDbConnection() {
    // Use your central bank database connection
    // Adjust these credentials to match your central bank database
    $host = 'localhost';
    $dbname = 'impactdi_school_portal'; // Change this!
    $username = 'impactdi_school_portal'; // Change this!
    $password = 'Innioluwa@1995'; // Change this!
    
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        json_error('Database connection failed: ' . $e->getMessage(), 500);
    }
}

// Global PDO connection
$pdo = getDbConnection();
?>
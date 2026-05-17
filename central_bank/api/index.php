<?php
// /central_bank/api/index.php - API Router

require_once '../includes/config.php';

// Get request parameters
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// CORS preflight
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST');
    header('Access-Control-Allow-Headers: X-API-Key, Content-Type');
    exit();
}

// Verify API key for all requests (except maybe public endpoints)
if (!verify_api_key()) {
    json_error('Invalid or missing API key', 401);
}

// Route to appropriate handler
switch ($action) {
    case 'get_subjects':
        require_once 'get_subjects.php';
        break;
    case 'get_topics':
        require_once 'get_topics.php';
        break;
    case 'get_questions':
        require_once 'get_questions.php';
        break;
    case 'get_question_count':
        require_once 'get_question_count.php';
        break;
    case 'get_stats':
        require_once 'get_stats.php';
        break;
    default:
        json_error('Invalid action. Available: get_subjects, get_topics, get_questions, get_question_count, get_stats');
}
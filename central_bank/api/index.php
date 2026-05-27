<?php
// /central_bank/api/index.php - API Router (COMPLETE REWRITE)

// ============================================
// CORS HEADERS - MUST BE FIRST
// ============================================
// Allow from specific origins
$allowed_origins = [
    'https://acad.com.ng',
    'https://www.acad.com.ng',
    'http://localhost:3000',
    'http://localhost:8080',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:8080'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowed_origins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: X-API-Key, Content-Type, Authorization, Accept, Origin');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json');

// Handle preflight OPTIONS request immediately - NO EXECUTION OF REST OF SCRIPT
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// ============================================
// LOAD FUNCTIONS
// ============================================
require_once __DIR__ . '/includes/functions.php';

// ============================================
// VERIFY API KEY (BEFORE ANY OTHER PROCESSING)
// ============================================
if (!verify_api_key()) {
    json_error('Invalid or missing API key. Please provide a valid X-API-Key header.', 401);
}

// ============================================
// ROUTE TO HANDLERS
// ============================================
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_subjects':
        require_once __DIR__ . '/handlers/get_subjects.php';
        break;
    case 'get_topics':
        require_once __DIR__ . '/handlers/get_topics.php';
        break;
    case 'get_questions':
        require_once __DIR__ . '/handlers/get_questions.php';
        break;
    case 'get_question_by_id':
        require_once __DIR__ . '/handlers/get_question_by_id.php';
        break;
    case 'get_question_count':
        require_once __DIR__ . '/handlers/get_question_count.php';
        break;
    case 'get_stats':
        require_once __DIR__ . '/handlers/get_stats.php';
        break;
    default:
        json_error('Invalid action. Available: get_subjects, get_topics, get_questions, get_question_by_id, get_question_count, get_stats');
}
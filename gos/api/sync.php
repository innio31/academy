<?php
// gos/api/sync.php - Main synchronization endpoint
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Allow both POST and GET for testing
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed. Use POST or GET']);
    exit();
}

// Get request data (handle both POST and GET)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        // Try to get from $_POST if not JSON
        $input = $_POST;
    }
} else {
    // GET request for testing
    $input = $_GET;
}

if (!$input || empty($input)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No data received', 'received' => $input]);
    exit();
}

// Validate API key
$api_key = $input['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';

// Valid API keys (add your keys here)
$valid_keys = [
    '8bdcc8a17b9db0e62ec5b3ba0a3be1c7c5d73eb0f60f58a77bece780a36e7d2f',
    'GOS_LOCAL_SYNC_KEY_2024',
    'test_key_123'
];

if (!in_array($api_key, $valid_keys)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid API key', 'provided_key' => substr($api_key, 0, 10) . '...']);
    exit();
}

// Get school info
$school_id = $input['school_id'] ?? 0;
$school_code = $input['school_code'] ?? '';

if (!$school_id || !$school_code) {
    echo json_encode(['status' => 'error', 'message' => 'School ID and code required']);
    exit();
}

// Process based on action
$action = $input['action'] ?? 'test';
$table_name = $input['table_name'] ?? '';

// Database connection
try {
    require_once 'db_config.php';
    $pdo = getDBConnection();
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

switch ($action) {
    case 'test':
        echo json_encode([
            'status' => 'success',
            'message' => 'API is working correctly',
            'data' => [
                'school_id' => $school_id,
                'school_code' => $school_code,
                'timestamp' => date('Y-m-d H:i:s'),
                'method' => $_SERVER['REQUEST_METHOD']
            ]
        ]);
        break;

    case 'push':
        handlePush($pdo, $input, $school_id);
        break;

    case 'pull':
        handlePull($pdo, $table_name, $school_id, $input);
        break;

    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid action. Available: test, push, pull',
            'received_action' => $action
        ]);
}

// Function to handle push (receiving data from local system)
function handlePush($pdo, $data, $school_id)
{
    $table = $data['table_name'];
    $records = $data['records'] ?? [];

    if (empty($records)) {
        echo json_encode(['status' => 'success', 'message' => 'No records to sync', 'data' => ['synced' => 0]]);
        return;
    }

    $synced_count = 0;
    $errors = [];

    try {
        foreach ($records as $record) {
            // Ensure school_id matches
            $record['school_id'] = $school_id;

            // Check if record exists
            $stmt = $pdo->prepare("SELECT id FROM $table WHERE id = ? AND school_id = ?");
            $stmt->execute([$record['id'], $school_id]);

            if ($stmt->fetch()) {
                // Update existing record
                $result = updateRecord($pdo, $table, $record);
            } else {
                // Insert new record
                $result = insertRecord($pdo, $table, $record);
            }

            if ($result) {
                $synced_count++;
            } else {
                $errors[] = "Failed to sync record ID: {$record['id']}";
            }
        }

        echo json_encode([
            'status' => 'success',
            'message' => "Synced $synced_count records successfully",
            'data' => [
                'synced' => $synced_count,
                'total' => count($records),
                'errors' => $errors
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
}

// Function to handle pull (sending data to local system)
function handlePull($pdo, $table, $school_id, $data)
{
    $last_sync = $data['last_sync'] ?? null;

    try {
        // Check if table exists
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() == 0) {
            echo json_encode([
                'status' => 'success',
                'message' => "Table '$table' not found, returning empty",
                'data' => [],
                'count' => 0
            ]);
            return;
        }

        // Get records for this school
        if ($last_sync) {
            $stmt = $pdo->prepare("
                SELECT * FROM $table 
                WHERE school_id = ? AND (updated_at > ? OR created_at > ?)
                ORDER BY id
            ");
            $stmt->execute([$school_id, $last_sync, $last_sync]);
        } else {
            $stmt = $pdo->prepare("
                SELECT * FROM $table 
                WHERE school_id = ?
                ORDER BY id
                LIMIT 1000
            ");
            $stmt->execute([$school_id]);
        }

        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'message' => 'Data retrieved successfully',
            'data' => $records,
            'count' => count($records)
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
}

// Helper function to insert record
function insertRecord($pdo, $table, $record)
{
    try {
        $columns = array_keys($record);
        $placeholders = ':' . implode(', :', $columns);
        $sql = "INSERT INTO $table (" . implode(',', $columns) . ") VALUES ($placeholders)";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($record);
    } catch (Exception $e) {
        error_log("Insert error in $table: " . $e->getMessage());
        return false;
    }
}

// Helper function to update record
function updateRecord($pdo, $table, $record)
{
    try {
        $id = $record['id'];
        unset($record['id']);

        $set_parts = [];
        foreach (array_keys($record) as $column) {
            $set_parts[] = "$column = :$column";
        }
        $set_clause = implode(', ', $set_parts);

        $sql = "UPDATE $table SET $set_clause WHERE id = :id";
        $record['id'] = $id;
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($record);
    } catch (Exception $e) {
        error_log("Update error in $table: " . $e->getMessage());
        return false;
    }
}

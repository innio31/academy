<?php
// api/sync.php - Main synchronization endpoint
// Location: acad.com.ng/gos/api/sync.php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

// Get request data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON data']);
    exit();
}

// Validate API key
$api_key = $input['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';

if (!validateApiKey($api_key)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid API key']);
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
$action = $input['action'] ?? '';
$table_name = $input['table_name'] ?? '';

switch ($action) {
    case 'push':
        handlePush($input, $school_id, $school_code);
        break;
    case 'pull':
        handlePull($table_name, $school_id, $school_code, $input);
        break;
    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
}

// Function to validate API key
function validateApiKey($api_key)
{
    // You can store valid API keys in database or config
    $valid_keys = [
        '8bdcc8a17b9db0e62ec5b3ba0a3be1c7c5d73eb0f60f58a77bece780a36e7d2f',
        'GOS_LOCAL_SYNC_KEY_2024',
        'GOS_CLOUD_SYNC_KEY'
    ];

    return in_array($api_key, $valid_keys);
}

// Function to handle push (receiving data from local system)
function handlePush($data, $school_id, $school_code)
{
    require_once 'db_config.php';

    $table = $data['table_name'];
    $records = $data['records'] ?? [];

    if (empty($records)) {
        echo json_encode(['status' => 'success', 'message' => 'No records to sync', 'data' => []]);
        return;
    }

    $synced_count = 0;
    $errors = [];

    try {
        $pdo = getDBConnection();

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

        // Log sync activity
        logSyncActivity($pdo, $school_id, $table, $synced_count, count($records) - $synced_count, 'push');

        echo json_encode([
            'status' => 'success',
            'message' => "Synced $synced_count records successfully",
            'data' => ['synced' => $synced_count, 'total' => count($records), 'errors' => $errors]
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
}

// Function to handle pull (sending data to local system)
function handlePull($table, $school_id, $school_code, $data)
{
    require_once 'db_config.php';

    $last_sync = $data['last_sync'] ?? null;

    try {
        $pdo = getDBConnection();

        // Get records for this school
        if ($last_sync) {
            $stmt = $pdo->prepare("
                SELECT * FROM $table 
                WHERE school_id = ? AND updated_at > ?
                ORDER BY id
            ");
            $stmt->execute([$school_id, $last_sync]);
        } else {
            $stmt = $pdo->prepare("
                SELECT * FROM $table 
                WHERE school_id = ?
                ORDER BY id
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

// Function to log sync activity
function logSyncActivity($pdo, $school_id, $table, $synced, $failed, $type)
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO sync_log (school_id, table_name, records_synced, records_failed, sync_type, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'success', NOW())
        ");
        $stmt->execute([$school_id, $table, $synced, $failed, $type]);
    } catch (Exception $e) {
        error_log("Failed to log sync activity: " . $e->getMessage());
    }
}

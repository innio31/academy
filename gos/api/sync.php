<?php
// gos/api/sync.php - Main synchronization endpoint

// ============================================
// ALLOWED TABLES FOR SYNC (Security)
// ============================================
// Find this section in your sync.php (around line 10-35)
$ALLOWED_TABLES = [
    // Core Academic
    'students',
    'staff',
    'classes',
    'subjects',
    'topics',
    'passages',           // ADD THIS LINE
    'library_resources',  // ADD THIS LINE

    // Exams & Questions
    'exams',
    'exam_questions',
    'exam_assignments',
    'exam_sessions',
    'exam_session_questions',
    'objective_questions',
    'subjective_questions',
    'theory_questions',

    // Results Processing
    'student_scores',
    'student_positions',
    'student_comments',
    'psychomotor_skills',
    'affective_traits',
    'report_card_settings',
    'results',
    'result_pins',

    // Assignments
    'assignments',
    'assignment_submissions',

    // Attendance
    'attendance',
    'attendance_logs'
];

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

// Validate table is allowed (except for test action)
if ($action !== 'test' && !in_array($table_name, $ALLOWED_TABLES)) {
    echo json_encode([
        'status' => 'error',
        'message' => "Table '$table_name' is not allowed for sync. Allowed tables: " . implode(', ', $ALLOWED_TABLES)
    ]);
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
                'method' => $_SERVER['REQUEST_METHOD'],
                'allowed_tables' => $ALLOWED_TABLES,
                'total_allowed_tables' => count($ALLOWED_TABLES)
            ]
        ]);
        break;

    case 'push':
        handlePush($pdo, $input, $school_id, $ALLOWED_TABLES);
        break;

    case 'pull':
        handlePull($pdo, $table_name, $school_id, $input, $ALLOWED_TABLES);
        break;

    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid action. Available: test, push, pull',
            'received_action' => $action
        ]);
}

// Function to handle push (receiving data from local system)
function handlePush($pdo, $data, $school_id, $ALLOWED_TABLES)
{
    $table = $data['table_name'];
    $records = $data['records'] ?? [];

    // Double-check table is allowed
    if (!in_array($table, $ALLOWED_TABLES)) {
        echo json_encode([
            'status' => 'error',
            'message' => "Table '$table' is not allowed for sync"
        ]);
        return;
    }

    if (empty($records)) {
        echo json_encode(['status' => 'success', 'message' => 'No records to sync', 'data' => ['synced' => 0]]);
        return;
    }

    $synced_count = 0;
    $errors = [];

    // Get table columns to filter valid fields
    $table_columns = getTableColumns($pdo, $table);

    try {
        foreach ($records as $record) {
            // Ensure school_id matches
            $record['school_id'] = $school_id;

            // Filter record to only include columns that exist in the table
            $filtered_record = [];
            foreach ($record as $key => $value) {
                if (in_array($key, $table_columns)) {
                    $filtered_record[$key] = $value;
                }
            }

            if (empty($filtered_record)) {
                $errors[] = "No valid columns for record ID: {$record['id']}";
                continue;
            }

            // Check if record exists
            $check_sql = "SELECT id FROM $table WHERE id = ? AND school_id = ?";
            $stmt = $pdo->prepare($check_sql);
            $stmt->execute([$filtered_record['id'], $school_id]);

            if ($stmt->fetch()) {
                // Update existing record - remove id from update
                $update_record = $filtered_record;
                unset($update_record['id']);
                if (!empty($update_record)) {
                    $result = updateRecord($pdo, $table, $update_record, $filtered_record['id']);
                } else {
                    $result = true;
                }
            } else {
                // Insert new record
                $result = insertRecord($pdo, $table, $filtered_record);
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
        error_log("Push error for table $table: " . $e->getMessage());
        echo json_encode([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
}

// Function to handle pull (sending data to local system)
function handlePull($pdo, $table, $school_id, $data, $ALLOWED_TABLES)
{
    $last_sync = $data['last_sync'] ?? null;

    // Double-check table is allowed
    if (!in_array($table, $ALLOWED_TABLES)) {
        echo json_encode([
            'status' => 'error',
            'message' => "Table '$table' is not allowed for sync"
        ]);
        return;
    }

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

        // Get table columns to check which date columns exist
        $columns = getTableColumns($pdo, $table);
        $has_updated_at = in_array('updated_at', $columns);
        $has_created_at = in_array('created_at', $columns);

        // Build query based on available columns
        if ($last_sync && ($has_updated_at || $has_created_at)) {
            if ($has_updated_at && $has_created_at) {
                $sql = "SELECT * FROM $table 
                        WHERE school_id = ? AND (updated_at > ? OR created_at > ?)
                        ORDER BY id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$school_id, $last_sync, $last_sync]);
            } elseif ($has_updated_at) {
                $sql = "SELECT * FROM $table 
                        WHERE school_id = ? AND updated_at > ?
                        ORDER BY id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$school_id, $last_sync]);
            } elseif ($has_created_at) {
                $sql = "SELECT * FROM $table 
                        WHERE school_id = ? AND created_at > ?
                        ORDER BY id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$school_id, $last_sync]);
            } else {
                // No date columns, return all records
                $sql = "SELECT * FROM $table WHERE school_id = ? ORDER BY id LIMIT 1000";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$school_id]);
            }
        } else {
            // No last_sync or no date columns, return all records
            $sql = "SELECT * FROM $table WHERE school_id = ? ORDER BY id LIMIT 1000";
            $stmt = $pdo->prepare($sql);
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
        error_log("Pull error for table $table: " . $e->getMessage());
        echo json_encode([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
}

// Helper function to get table columns
function getTableColumns($pdo, $table)
{
    try {
        $stmt = $pdo->query("DESCRIBE $table");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
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
function updateRecord($pdo, $table, $record, $id)
{
    try {
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

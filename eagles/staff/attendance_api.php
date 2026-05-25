<?php
// tbis/staff/attendance_api.php - Attendance API for AJAX requests
session_start();

require_once '../includes/config.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Determine user type and permissions
$is_admin = isset($_SESSION['admin_id']);
$is_staff = isset($_SESSION['user_id']) && $_SESSION['user_type'] === 'staff';

$school_id = SCHOOL_ID;
$user_id = $is_admin ? $_SESSION['admin_id'] : $_SESSION['user_id'];

// Get action from request
$action = $_GET['action'] ?? $_POST['action'] ?? null;

// For POST requests with JSON body
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($action)) {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? null;
}

if (!$action) {
    echo json_encode(['success' => false, 'error' => 'No action specified']);
    exit();
}

// Helper function to check staff permissions
function checkStaffPermission($pdo, $staff_id, $school_id, $required_permission)
{
    $stmt = $pdo->prepare("
        SELECT can_take_attendance, can_view_reports, assigned_classes 
        FROM attendance_permissions 
        WHERE staff_id = ? AND school_id = ?
    ");
    $stmt->execute([$staff_id, $school_id]);
    $permission = $stmt->fetch();

    if (!$permission) {
        return false;
    }

    if ($required_permission === 'take_attendance' && !$permission['can_take_attendance']) {
        return false;
    }

    if ($required_permission === 'view_reports' && !$permission['can_view_reports']) {
        return false;
    }

    return $permission;
}

// Process different actions
switch ($action) {
    case 'record_attendance':
        // Only staff can record attendance
        if (!$is_staff) {
            echo json_encode(['success' => false, 'error' => 'Only staff can record attendance']);
            exit();
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $student_id = $input['student_id'] ?? 0;
        $scan_type = $input['scan_type'] ?? 'check_in';
        $session_id = $input['session_id'] ?? null;
        $latitude = $input['latitude'] ?? null;
        $longitude = $input['longitude'] ?? null;

        if (!$student_id) {
            echo json_encode(['success' => false, 'error' => 'Invalid student ID']);
            exit();
        }

        // Check staff permissions
        $staff_numeric_id = $_SESSION['user_id'];
        $permission = checkStaffPermission($pdo, $staff_numeric_id, $school_id, 'take_attendance');

        if (!$permission) {
            echo json_encode(['success' => false, 'error' => 'You do not have permission to take attendance']);
            exit();
        }

        // Get staff_id string
        $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
        $stmt->execute([$staff_numeric_id, $school_id]);
        $staff_id_string = $stmt->fetchColumn();

        // Get student info
        $stmt = $pdo->prepare("
            SELECT s.*, c.id as class_id, c.class_name 
            FROM students s
            LEFT JOIN classes c ON s.class_id = c.id
            WHERE s.id = ? AND s.school_id = ?
        ");
        $stmt->execute([$student_id, $school_id]);
        $student = $stmt->fetch();

        if (!$student) {
            echo json_encode(['success' => false, 'error' => 'Student not found']);
            exit();
        }

        // Check if staff has permission for this student's class
        $assigned_classes = $permission['assigned_classes'];
        $has_permission = false;

        if (empty($assigned_classes)) {
            // Can take attendance for all classes
            $has_permission = true;
        } else {
            $assigned_class_ids = explode(',', $assigned_classes);
            if (in_array($student['class_id'], $assigned_class_ids)) {
                $has_permission = true;
            }
        }

        if (!$has_permission) {
            echo json_encode(['success' => false, 'error' => 'You do not have permission to take attendance for this class']);
            exit();
        }

        // Check if already checked in today
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("
            SELECT * FROM attendance_logs 
            WHERE student_id = ? AND school_id = ? 
            AND DATE(scan_time) = ? AND scan_type = ?
            ORDER BY scan_time DESC LIMIT 1
        ");
        $stmt->execute([$student_id, $school_id, $today, $scan_type]);
        $existing = $stmt->fetch();

        if ($existing && $scan_type === 'check_in') {
            echo json_encode([
                'success' => false,
                'error' => 'Student already checked in today',
                'student_name' => $student['full_name']
            ]);
            exit();
        }

        // Determine status based on time
        $current_time = date('H:i:s');
        $status = 'present';
        if ($scan_type === 'check_in' && $current_time > '09:00:00') {
            $status = 'late';
        }

        try {
            // Record attendance in logs table
            $stmt = $pdo->prepare("
                INSERT INTO attendance_logs (school_id, session_id, student_id, staff_id, scan_time, scan_type, status, latitude, longitude, ip_address, created_at)
                VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $school_id,
                $session_id,
                $student_id,
                $staff_numeric_id,
                $scan_type,
                $status,
                $latitude,
                $longitude,
                $_SERVER['REMOTE_ADDR']
            ]);

            // Update main attendance table
            $stmt = $pdo->prepare("
                INSERT INTO attendance (school_id, student_id, date, status, created_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE status = ?, created_at = NOW()
            ");
            $stmt->execute([$school_id, $student_id, $today, $status, $status]);

            // Log activity
            $stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, user_type, activity, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $staff_numeric_id,
                'staff',
                "Recorded $scan_type for student: {$student['full_name']} (Status: $status)",
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);

            echo json_encode([
                'success' => true,
                'student_name' => $student['full_name'],
                'status' => $status,
                'scan_type' => $scan_type,
                'time' => date('h:i A')
            ]);
        } catch (Exception $e) {
            error_log("Attendance record error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
        break;

    case 'get_today_stats':
        $today = date('Y-m-d');

        // Get total students (based on permissions for staff)
        if ($is_staff) {
            $staff_numeric_id = $_SESSION['user_id'];
            $permission = checkStaffPermission($pdo, $staff_numeric_id, $school_id, 'view_reports');

            if ($permission) {
                $assigned_classes = $permission['assigned_classes'];

                if (empty($assigned_classes)) {
                    // Can see all classes
                    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM students WHERE school_id = ? AND status = 'active'");
                    $stmt->execute([$school_id]);
                } else {
                    $class_ids = explode(',', $assigned_classes);
                    $placeholders = str_repeat('?,', count($class_ids) - 1) . '?';
                    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM students WHERE school_id = ? AND status = 'active' AND class_id IN ($placeholders)");
                    $stmt->execute(array_merge([$school_id], $class_ids));
                }
                $total = $stmt->fetch()['total'];

                // Get present count
                $stmt = $pdo->prepare("
                    SELECT COUNT(DISTINCT student_id) as count 
                    FROM attendance_logs 
                    WHERE school_id = ? AND DATE(scan_time) = ? AND scan_type = 'check_in'
                ");
                $stmt->execute([$school_id, $today]);
                $present = $stmt->fetch()['count'];

                // Get late count
                $stmt = $pdo->prepare("
                    SELECT COUNT(DISTINCT student_id) as count 
                    FROM attendance_logs 
                    WHERE school_id = ? AND DATE(scan_time) = ? AND scan_type = 'check_in' AND status = 'late'
                ");
                $stmt->execute([$school_id, $today]);
                $late = $stmt->fetch()['count'];
            } else {
                $total = 0;
                $present = 0;
                $late = 0;
            }
        } else {
            // Admin - can see all
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM students WHERE school_id = ? AND status = 'active'");
            $stmt->execute([$school_id]);
            $total = $stmt->fetch()['total'];

            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT student_id) as count 
                FROM attendance_logs 
                WHERE school_id = ? AND DATE(scan_time) = ? AND scan_type = 'check_in'
            ");
            $stmt->execute([$school_id, $today]);
            $present = $stmt->fetch()['count'];

            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT student_id) as count 
                FROM attendance_logs 
                WHERE school_id = ? AND DATE(scan_time) = ? AND scan_type = 'check_in' AND status = 'late'
            ");
            $stmt->execute([$school_id, $today]);
            $late = $stmt->fetch()['count'];
        }

        $absent = max(0, $total - $present);

        echo json_encode([
            'success' => true,
            'present' => $present,
            'late' => $late,
            'absent' => $absent,
            'total' => $total
        ]);
        break;

    case 'get_recent_scans':
        $today = date('Y-m-d');

        $query = "
            SELECT al.*, s.full_name, s.admission_number, c.class_name
            FROM attendance_logs al
            JOIN students s ON al.student_id = s.id
            LEFT JOIN classes c ON s.class_id = c.id
            WHERE al.school_id = ? AND DATE(al.scan_time) = ?
            ORDER BY al.scan_time DESC LIMIT 20
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$school_id, $today]);
        $scans = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'scans' => $scans
        ]);
        break;

    case 'get_daily_stats':
        $date = $_GET['date'] ?? date('Y-m-d');
        $class_id = $_GET['class_id'] ?? null;

        $query = "
            SELECT s.id, s.full_name, s.admission_number, c.class_name, c.id as class_id,
                   al.scan_type, al.status, al.scan_time
            FROM students s
            LEFT JOIN classes c ON s.class_id = c.id
            LEFT JOIN attendance_logs al ON s.id = al.student_id AND DATE(al.scan_time) = ? AND al.scan_type = 'check_in'
            WHERE s.school_id = ? AND s.status = 'active'
        ";
        $params = [$date, $school_id];

        // Apply class filter
        if ($class_id && $class_id !== 'all' && $class_id !== '') {
            $query .= " AND s.class_id = ?";
            $params[] = $class_id;
        }

        // Apply staff permission filter
        if ($is_staff) {
            $staff_numeric_id = $_SESSION['user_id'];
            $permission = checkStaffPermission($pdo, $staff_numeric_id, $school_id, 'view_reports');

            if ($permission && !empty($permission['assigned_classes'])) {
                $assigned_class_ids = explode(',', $permission['assigned_classes']);
                $placeholders = str_repeat('?,', count($assigned_class_ids) - 1) . '?';
                $query .= " AND s.class_id IN ($placeholders)";
                $params = array_merge($params, $assigned_class_ids);
            }
        }

        $query .= " ORDER BY c.class_name, s.full_name";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $attendance = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'date' => $date,
            'attendance' => $attendance
        ]);
        break;

    case 'get_history':
        $date = $_GET['date'] ?? date('Y-m-d');
        $class_id = $_GET['class_id'] ?? null;

        $query = "
            SELECT al.*, s.full_name, s.admission_number, c.class_name
            FROM attendance_logs al
            JOIN students s ON al.student_id = s.id
            LEFT JOIN classes c ON s.class_id = c.id
            WHERE al.school_id = ? AND DATE(al.scan_time) = ?
        ";
        $params = [$school_id, $date];

        if ($class_id && $class_id !== 'all') {
            $query .= " AND s.class_id = ?";
            $params[] = $class_id;
        }

        $query .= " ORDER BY al.scan_time DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $logs = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'date' => $date,
            'logs' => $logs
        ]);
        break;

    case 'create_event':
        // Only admin can create events
        if (!$is_admin) {
            echo json_encode(['success' => false, 'error' => 'Only administrators can create events']);
            exit();
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $event_name = $input['event_name'] ?? '';
        $event_type = $input['event_type'] ?? 'check_in';
        $class_id = $input['class_id'] ?? null;

        if (empty($event_name)) {
            echo json_encode(['success' => false, 'error' => 'Event name is required']);
            exit();
        }

        $stmt = $pdo->prepare("
            INSERT INTO attendance_sessions (school_id, session_name, session_type, class_id, start_time, status, created_by, created_at)
            VALUES (?, ?, ?, ?, NOW(), 'active', ?, NOW())
        ");
        $stmt->execute([$school_id, $event_name, $event_type, $class_id, $user_id]);

        echo json_encode([
            'success' => true,
            'event_id' => $pdo->lastInsertId(),
            'message' => 'Event created successfully'
        ]);
        break;

    case 'close_event':
        // Only admin can close events
        if (!$is_admin) {
            echo json_encode(['success' => false, 'error' => 'Only administrators can close events']);
            exit();
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $event_id = $input['event_id'] ?? 0;

        $stmt = $pdo->prepare("
            UPDATE attendance_sessions 
            SET status = 'closed', end_time = NOW() 
            WHERE id = ? AND school_id = ?
        ");
        $stmt->execute([$event_id, $school_id]);

        echo json_encode(['success' => true, 'message' => 'Event closed']);
        break;

    case 'get_active_events':
        $stmt = $pdo->prepare("
            SELECT * FROM attendance_sessions 
            WHERE school_id = ? AND status = 'active' 
            ORDER BY start_time DESC
        ");
        $stmt->execute([$school_id]);
        $events = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'events' => $events
        ]);
        break;

    case 'get_staff_permission':
        if (!$is_staff) {
            echo json_encode(['success' => false, 'error' => 'Not authorized']);
            exit();
        }

        $staff_numeric_id = $_SESSION['user_id'];
        $stmt = $pdo->prepare("
            SELECT can_take_attendance, can_view_reports, assigned_classes 
            FROM attendance_permissions 
            WHERE staff_id = ? AND school_id = ?
        ");
        $stmt->execute([$staff_numeric_id, $school_id]);
        $permission = $stmt->fetch();

        echo json_encode([
            'success' => true,
            'can_take_attendance' => $permission ? (bool)$permission['can_take_attendance'] : false,
            'can_view_reports' => $permission ? (bool)$permission['can_view_reports'] : false,
            'assigned_classes' => $permission ? $permission['assigned_classes'] : null
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action: ' . $action]);
        break;
}

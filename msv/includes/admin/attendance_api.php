<?php
session_start();
header('Content-Type: application/json');

require_once '../includes/config.php';

// Check if logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$school_id = SCHOOL_ID;

// Get action from GET or POST
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// If POST with JSON body, also check there
if (empty($action)) {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
}

try {
    switch($action) {
        case 'record_attendance':
            $input = json_decode(file_get_contents('php://input'), true);
            $student_id = $input['student_id'] ?? 0;
            $scan_type = $input['scan_type'] ?? 'check_in';
            $session_id = $input['event_id'] ?? $input['session_id'] ?? null; // Support both names
            
            if (!$student_id) {
                echo json_encode(['success' => false, 'error' => 'Student ID required']);
                exit();
            }
            
            // Get student details
            $stmt = $pdo->prepare("SELECT s.*, c.class_name FROM students s LEFT JOIN classes c ON s.class_id = c.id WHERE s.id = ? AND s.school_id = ?");
            $stmt->execute([$student_id, $school_id]);
            $student = $stmt->fetch();
            
            if (!$student) {
                echo json_encode(['success' => false, 'error' => 'Student not found']);
                exit();
            }
            
            // Check if already checked in today for check_in
            $today = date('Y-m-d');
            if ($scan_type === 'check_in') {
                $stmt = $pdo->prepare("SELECT id FROM attendance_logs WHERE student_id = ? AND DATE(scan_time) = ? AND scan_type = 'check_in' AND school_id = ?");
                $stmt->execute([$student_id, $today, $school_id]);
                if ($stmt->fetch()) {
                    echo json_encode(['success' => false, 'error' => 'Already checked in today']);
                    exit();
                }
            }
            
            // Determine status based on time (after 8:30 AM is late)
            $current_time = date('H:i');
            $status = 'present';
            if ($scan_type === 'check_in' && $current_time > '08:30') {
                $status = 'late';
            }
            
            // Insert attendance record - using session_id instead of event_id
            if ($session_id) {
                $stmt = $pdo->prepare("INSERT INTO attendance_logs (school_id, student_id, scan_time, scan_type, status, session_id) VALUES (?, ?, NOW(), ?, ?, ?)");
                $stmt->execute([$school_id, $student_id, $scan_type, $status, $session_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO attendance_logs (school_id, student_id, scan_time, scan_type, status) VALUES (?, ?, NOW(), ?, ?)");
                $stmt->execute([$school_id, $student_id, $scan_type, $status]);
            }
            
            echo json_encode([
                'success' => true, 
                'student_name' => $student['full_name'],
                'status' => $status,
                'message' => "$scan_type recorded successfully"
            ]);
            break;
            
        case 'today_stats':
            $today = date('Y-m-d');
            
            // Get present count (checked in today)
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) as count FROM attendance_logs WHERE school_id = ? AND DATE(scan_time) = ? AND scan_type = 'check_in' AND status IN ('present', 'late')");
            $stmt->execute([$school_id, $today]);
            $present = $stmt->fetch()['count'];
            
            // Get late count
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) as count FROM attendance_logs WHERE school_id = ? AND DATE(scan_time) = ? AND status = 'late'");
            $stmt->execute([$school_id, $today]);
            $late = $stmt->fetch()['count'];
            
            // Get total students
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM students WHERE school_id = ? AND status = 'active'");
            $stmt->execute([$school_id]);
            $total = $stmt->fetch()['count'];
            
            $absent = $total - $present;
            
            echo json_encode([
                'success' => true,
                'present' => (int)$present,
                'late' => (int)$late,
                'absent' => max(0, (int)$absent),
                'total' => (int)$total
            ]);
            break;
            
        case 'recent_scans':
            $stmt = $pdo->prepare("
                SELECT al.*, s.full_name as student_name, s.admission_number,
                       DATE_FORMAT(al.scan_time, '%h:%i:%s %p') as time
                FROM attendance_logs al
                JOIN students s ON al.student_id = s.id
                WHERE al.school_id = ?
                ORDER BY al.scan_time DESC
                LIMIT 20
            ");
            $stmt->execute([$school_id]);
            $scans = $stmt->fetchAll();
            
            // Format the data for display
            foreach ($scans as &$scan) {
                $scan['scan_type_display'] = $scan['scan_type'] === 'check_in' ? 'In' : 'Out';
            }
            
            echo json_encode(['success' => true, 'scans' => $scans]);
            break;
            
        case 'create_event':
            $input = json_decode(file_get_contents('php://input'), true);
            $event_name = $input['event_name'] ?? '';
            $event_type = $input['event_type'] ?? 'check_in';
            $class_id = !empty($input['class_id']) ? (int)$input['class_id'] : null;
            
            if (empty($event_name)) {
                echo json_encode(['success' => false, 'error' => 'Event name required']);
                exit();
            }
            
            $stmt = $pdo->prepare("INSERT INTO attendance_sessions (school_id, session_name, session_type, class_id, start_time, status) VALUES (?, ?, ?, ?, NOW(), 'active')");
            $stmt->execute([$school_id, $event_name, $event_type, $class_id]);
            $event_id = $pdo->lastInsertId();
            
            echo json_encode(['success' => true, 'event_id' => $event_id, 'message' => 'Event created successfully']);
            break;
            
        case 'close_event':
            $input = json_decode(file_get_contents('php://input'), true);
            $event_id = $input['event_id'] ?? 0;
            
            $stmt = $pdo->prepare("UPDATE attendance_sessions SET end_time = NOW(), status = 'closed' WHERE id = ? AND school_id = ?");
            $stmt->execute([$event_id, $school_id]);
            
            echo json_encode(['success' => true, 'message' => 'Event closed']);
            break;
            
        case 'get_active_events':
            $stmt = $pdo->prepare("SELECT * FROM attendance_sessions WHERE school_id = ? AND status = 'active' ORDER BY start_time DESC");
            $stmt->execute([$school_id]);
            $events = $stmt->fetchAll();
            
            echo json_encode(['success' => true, 'events' => $events]);
            break;
            
        case 'get_daily_stats':
            $date = $_GET['date'] ?? date('Y-m-d');
            
            $stmt = $pdo->prepare("
                SELECT 
                    s.id, s.full_name, s.admission_number, s.parent_phone, s.class_id,
                    al.scan_type, al.status, al.scan_time,
                    c.class_name
                FROM students s
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN attendance_logs al ON s.id = al.student_id AND DATE(al.scan_time) = ? AND al.school_id = ? AND al.scan_type = 'check_in'
                WHERE s.school_id = ? AND s.status = 'active'
                ORDER BY c.class_name, s.full_name
            ");
            $stmt->execute([$date, $school_id, $school_id]);
            $attendance = $stmt->fetchAll();
            
            echo json_encode(['success' => true, 'attendance' => $attendance, 'date' => $date]);
            break;
            
        case 'get_history':
            $date = $_GET['date'] ?? date('Y-m-d');
            $class_id = $_GET['class_id'] ?? '';
            $status = $_GET['status'] ?? '';
            
            $sql = "
                SELECT al.*, s.full_name as student_name, s.admission_number, c.class_name, ass.session_name
                FROM attendance_logs al
                JOIN students s ON al.student_id = s.id
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN attendance_sessions ass ON al.session_id = ass.id
                WHERE al.school_id = ? AND DATE(al.scan_time) = ?
            ";
            $params = [$school_id, $date];
            
            if (!empty($class_id)) {
                $sql .= " AND s.class_id = ?";
                $params[] = $class_id;
            }
            
            if (!empty($status)) {
                $sql .= " AND al.status = ?";
                $params[] = $status;
            }
            
            $sql .= " ORDER BY al.scan_time DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $logs = $stmt->fetchAll();
            
            // Format datetime for display
            foreach ($logs as &$log) {
                $log['datetime'] = date('Y-m-d h:i:s A', strtotime($log['scan_time']));
            }
            
            echo json_encode(['success' => true, 'logs' => $logs]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action: ' . $action]);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
<?php
// eagles/staff/attendance.php - Staff Attendance Tracking with QR Scanning
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: /eagles/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$staff_id = $_SESSION['user_id'];
$staff_name = $_SESSION['user_name'] ?? 'Staff Member';
$staff_role = $_SESSION['staff_role'] ?? 'staff';

// Get staff permissions and assigned classes from admin settings
$staff_permission = null;
$assigned_class_ids = [];
$can_take_attendance = false;
$can_view_reports = false;
$has_permission_error = false;

try {
    // Get staff_id string
    $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
    $stmt->execute([$staff_id, $school_id]);
    $staff_id_string = $stmt->fetchColumn();

    if (!$staff_id_string) {
        $has_permission_error = true;
        $permission_error = "Staff record not found. Please contact administrator.";
    } else {
        // Check attendance permissions from admin settings
        $stmt = $pdo->prepare("
            SELECT can_take_attendance, can_view_reports, assigned_classes 
            FROM attendance_permissions 
            WHERE staff_id = ? AND school_id = ?
        ");
        $stmt->execute([$staff_id, $school_id]);
        $staff_permission = $stmt->fetch();

        if ($staff_permission) {
            $can_take_attendance = (bool)$staff_permission['can_take_attendance'];
            $can_view_reports = (bool)$staff_permission['can_view_reports'];

            if ($staff_permission['assigned_classes']) {
                $assigned_class_ids = explode(',', $staff_permission['assigned_classes']);
            }
        } else {
            $can_take_attendance = false;
            $can_view_reports = false;
            $has_permission_error = true;
            $permission_error = "You have not been granted attendance permissions. Please contact administrator.";
        }

        // Also check staff_classes as backup (for backward compatibility)
        if (empty($assigned_class_ids)) {
            $stmt = $pdo->prepare("SELECT class FROM staff_classes WHERE staff_id = ? AND school_id = ?");
            $stmt->execute([$staff_id_string, $school_id]);
            $staff_classes = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($staff_classes)) {
                // Convert class names to IDs
                $placeholders = str_repeat('?,', count($staff_classes) - 1) . '?';
                $stmt = $pdo->prepare("SELECT id FROM classes WHERE school_id = ? AND class_name IN ($placeholders)");
                $stmt->execute(array_merge([$school_id], $staff_classes));
                $assigned_class_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
        }
    }
} catch (Exception $e) {
    error_log("Staff permission error: " . $e->getMessage());
    $has_permission_error = true;
    $permission_error = "An error occurred while loading your permissions.";
}

// Get all classes for dropdown (filtered by permissions if not all classes)
$all_classes = [];
$stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? AND status = 'active' ORDER BY sort_order, class_name");
$stmt->execute([$school_id]);
$all_classes = $stmt->fetchAll();

// Filter classes based on permissions (if assigned_classes is set, otherwise can access all)
$accessible_classes = [];
if ($can_take_attendance || $can_view_reports) {
    if (empty($assigned_class_ids)) {
        // Can access all classes
        $accessible_classes = $all_classes;
    } else {
        // Can only access assigned classes
        foreach ($all_classes as $class) {
            if (in_array($class['id'], $assigned_class_ids)) {
                $accessible_classes[] = $class;
            }
        }
    }
}

// Get active attendance sessions
$active_sessions = [];
$stmt = $pdo->prepare("
    SELECT * FROM attendance_sessions 
    WHERE school_id = ? AND status = 'active' 
    ORDER BY start_time DESC
");
$stmt->execute([$school_id]);
$active_sessions = $stmt->fetchAll();

// Process API requests (AJAX) - API endpoint
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';

    if (!$can_take_attendance && $action !== 'get_stats' && $action !== 'get_history') {
        echo json_encode(['success' => false, 'error' => 'You do not have permission to take attendance']);
        exit();
    }

    switch ($action) {
        case 'record_attendance':
            $student_id = $input['student_id'] ?? 0;
            $scan_type = $input['scan_type'] ?? 'check_in';
            $session_id = $input['session_id'] ?? null;
            $latitude = $input['latitude'] ?? null;
            $longitude = $input['longitude'] ?? null;

            if (!$student_id) {
                echo json_encode(['success' => false, 'error' => 'Invalid student ID']);
                exit();
            }

            // Get student info and verify staff has permission for this student's class
            $stmt = $pdo->prepare("
                SELECT s.*, c.class_name 
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

            // Check if staff has permission for this class
            $has_class_permission = empty($assigned_class_ids) || in_array($student['class_id'], $assigned_class_ids);
            if (!$has_class_permission && !$can_take_attendance) {
                echo json_encode(['success' => false, 'error' => 'You do not have permission to take attendance for this class']);
                exit();
            }

            // Check if already checked in/out for today
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
                echo json_encode(['success' => false, 'error' => 'Student already checked in today', 'student_name' => $student['full_name']]);
                exit();
            }

            // Determine status based on time
            $current_time = date('H:i:s');
            $status = 'present';
            if ($scan_type === 'check_in' && $current_time > '09:00:00') {
                $status = 'late';
            }

            // Record attendance
            $stmt = $pdo->prepare("
                INSERT INTO attendance_logs (school_id, session_id, student_id, staff_id, scan_time, scan_type, status, latitude, longitude, ip_address, created_at)
                VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $school_id,
                $session_id,
                $student_id,
                $staff_id,
                $scan_type,
                $status,
                $latitude,
                $longitude,
                $_SERVER['REMOTE_ADDR']
            ]);

            // Also update the main attendance table
            $stmt = $pdo->prepare("
                INSERT INTO attendance (school_id, student_id, date, status, created_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE status = ?, created_at = NOW()
            ");
            $stmt->execute([$school_id, $student_id, $today, $status, $status]);

            echo json_encode([
                'success' => true,
                'student_name' => $student['full_name'],
                'status' => $status,
                'scan_type' => $scan_type,
                'time' => date('h:i A')
            ]);
            break;

        case 'get_today_stats':
            $today = date('Y-m-d');

            // Get total students (filtered by accessible classes)
            if (empty($assigned_class_ids)) {
                $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM students WHERE school_id = ? AND status = 'active'");
                $stmt->execute([$school_id]);
            } else {
                $placeholders = str_repeat('?,', count($assigned_class_ids) - 1) . '?';
                $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM students WHERE school_id = ? AND status = 'active' AND class_id IN ($placeholders)");
                $stmt->execute(array_merge([$school_id], $assigned_class_ids));
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
            $date = $input['date'] ?? $_GET['date'] ?? date('Y-m-d');
            $class_id = $input['class_id'] ?? $_GET['class_id'] ?? null;

            $query = "
                SELECT s.id, s.full_name, s.admission_number, c.class_name,
                       al.scan_type, al.status, al.scan_time, al.latitude, al.longitude
                FROM students s
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN attendance_logs al ON s.id = al.student_id AND DATE(al.scan_time) = ? AND al.scan_type = 'check_in'
                WHERE s.school_id = ? AND s.status = 'active'
            ";
            $params = [$date, $school_id];

            if ($class_id && $class_id != 'all') {
                $query .= " AND s.class_id = ?";
                $params[] = $class_id;
            } elseif (!empty($assigned_class_ids) && !$class_id) {
                $placeholders = str_repeat('?,', count($assigned_class_ids) - 1) . '?';
                $query .= " AND s.class_id IN ($placeholders)";
                $params = array_merge($params, $assigned_class_ids);
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
            $date = $input['date'] ?? $_GET['date'] ?? date('Y-m-d');

            $query = "
                SELECT al.*, s.full_name, s.admission_number, c.class_name
                FROM attendance_logs al
                JOIN students s ON al.student_id = s.id
                LEFT JOIN classes c ON s.class_id = c.id
                WHERE al.school_id = ? AND DATE(al.scan_time) = ?
                ORDER BY al.scan_time DESC
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$school_id, $date]);
            $logs = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'date' => $date,
                'logs' => $logs
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title><?php echo htmlspecialchars($school_name); ?> - Attendance</title>

    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --primary-dark: #1a5a8a;
            --secondary-color: #d4af7a;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --warning-color: #f59e0b;
            --info-color: #3b82f6;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --sidebar-width: 280px;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--gray-100);
            color: var(--gray-800);
            min-height: 100vh;
        }

        /* Main Content */
        .main-content {
            margin-left: 0;
            padding: 0;
            min-height: 100vh;
            transition: margin-left 0.28s ease;
        }

        /* Top Bar */
        .top-bar {
            background: white;
            padding: 16px 24px;
            display: flex;
            align-items: center;
            gap: 20px;
            box-shadow: var(--shadow-sm);
            position: sticky;
            top: 0;
            z-index: 99;
        }

        .menu-toggle {
            background: none;
            border: none;
            font-size: 22px;
            cursor: pointer;
            color: var(--gray-700);
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .menu-toggle:hover {
            background: var(--gray-100);
        }

        .page-title {
            flex: 1;
        }

        .page-title h1 {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 2px;
        }

        .page-title h1 i {
            color: var(--primary-color);
            margin-right: 10px;
        }

        .page-title p {
            font-size: 0.75rem;
            color: var(--gray-500);
        }

        .info-item {
            background: var(--gray-100);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            color: var(--gray-800);
            font-weight: 500;
        }

        .info-item i {
            margin-right: 6px;
            color: var(--primary-color);
        }

        /* Container */
        .container {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }

        .card-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--gray-200);
        }

        .card-title i {
            color: var(--primary-color);
            margin-right: 8px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px;
            text-align: center;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            transition: transform 0.2s ease;
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary-color);
            line-height: 1;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--gray-500);
            margin-top: 8px;
            font-weight: 500;
        }

        /* Scanner */
        .scanner-container {
            background: var(--gray-900);
            border-radius: var(--radius-lg);
            overflow: hidden;
            position: relative;
            min-height: 400px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #reader {
            width: 100%;
            min-height: 400px;
            display: none;
        }

        #reader.active {
            display: block;
        }

        .scanner-placeholder {
            text-align: center;
            padding: 80px 20px;
            color: white;
        }

        .scanner-placeholder i {
            font-size: 64px;
            margin-bottom: 16px;
            opacity: 0.8;
        }

        .scanner-placeholder p {
            font-size: 14px;
            opacity: 0.8;
            margin-bottom: 20px;
        }

        /* Buttons */
        .btn {
            padding: 10px 20px;
            border-radius: var(--radius-md);
            border: none;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8rem;
            font-family: inherit;
            transition: all 0.2s ease;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-secondary {
            background: var(--gray-200);
            color: var(--gray-700);
        }

        .btn-secondary:hover {
            background: var(--gray-300);
        }

        .camera-btn {
            background: var(--primary-color);
            color: white;
            padding: 14px 28px;
            border: none;
            border-radius: var(--radius-md);
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            font-family: inherit;
        }

        .stop-camera-btn {
            background: var(--danger-color);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius-md);
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            margin-top: 12px;
            font-family: inherit;
        }

        /* Scan Type Selector */
        .scan-type-selector {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
        }

        .scan-type-btn {
            flex: 1;
            padding: 12px;
            border: 2px solid var(--gray-200);
            background: white;
            border-radius: var(--radius-md);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: inherit;
        }

        .scan-type-btn.active {
            border-color: var(--primary-color);
            background: var(--primary-color);
            color: white;
        }

        .scan-type-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Tables */
        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
        }

        .data-table th {
            font-weight: 600;
            color: var(--gray-600);
            background: var(--gray-50);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .data-table tr:hover td {
            background: var(--gray-50);
        }

        .data-table td strong {
            font-size: 0.85rem;
            font-weight: 600;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-present {
            background: #d1fae5;
            color: #065f46;
        }

        .status-late {
            background: #fed7aa;
            color: #92400e;
        }

        .status-absent {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Feedback Toast */
        .scan-feedback {
            position: fixed;
            bottom: 20px;
            right: 20px;
            left: 20px;
            max-width: 400px;
            margin: 0 auto;
            background: var(--gray-900);
            color: white;
            padding: 16px;
            border-radius: var(--radius-md);
            text-align: center;
            animation: slideUp 0.3s ease;
            z-index: 1100;
            box-shadow: var(--shadow-lg);
        }

        @keyframes slideUp {
            from {
                transform: translateY(100%);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1050;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 500px;
            max-height: 85vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
        }

        .modal-header h3 {
            font-size: 1rem;
            font-weight: 600;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--gray-200);
            display: flex;
            gap: 12px;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .close-modal:hover {
            background: var(--gray-100);
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 0.8rem;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 0.85rem;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        /* Alert */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-md);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid var(--success-color);
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid var(--danger-color);
        }

        .alert-warning {
            background: #fed7aa;
            color: #92400e;
            border-left: 4px solid var(--warning-color);
        }

        .permission-denied {
            text-align: center;
            padding: 60px 20px;
        }

        .permission-denied i {
            font-size: 64px;
            color: var(--danger-color);
            margin-bottom: 20px;
        }

        .permission-denied h3 {
            font-size: 1.2rem;
            margin-bottom: 10px;
        }

        .live-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10b981;
            margin-right: 8px;
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }
        }

        /* Responsive */
        @media (min-width: 768px) {
            .main-content {
                margin-left: var(--sidebar-width);
            }

            .scan-feedback {
                left: auto;
                right: 20px;
            }
        }

        @media (max-width: 767px) {
            .container {
                padding: 16px;
            }

            .card {
                padding: 16px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            .top-bar {
                padding: 12px 16px;
            }

            .page-title h1 {
                font-size: 1.1rem;
            }
        }
    </style>
</head>

<body>
    <!-- Mobile Menu Button -->
    <button class="menu-toggle" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Include Staff Sidebar -->
    <?php include_once 'includes/staff_sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-calendar-check"></i> Attendance Management</h1>
                <p><i class="fas fa-chevron-right"></i> Scan QR codes to mark student attendance</p>
            </div>
            <div>
                <span class="info-item"><i class="fas fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></span>
            </div>
        </div>

        <div class="container">
            <?php if ($has_permission_error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($permission_error); ?>
                </div>
                <div class="card permission-denied">
                    <i class="fas fa-lock"></i>
                    <h3>Access Denied</h3>
                    <p>You do not have permission to access the attendance system.</p>
                    <p style="margin-top: 10px; font-size: 13px;">Please contact the administrator to request attendance permissions.</p>
                </div>
            <?php elseif (!$can_take_attendance && !$can_view_reports): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> You have limited access. You can only view reports but cannot take attendance.
                </div>
            <?php endif; ?>

            <?php if (!$has_permission_error): ?>
                <!-- Scanner Card -->
                <div class="card">
                    <div class="card-title">
                        <span><i class="fas fa-qrcode"></i> QR Scanner</span>
                        <?php if (!$can_take_attendance): ?>
                            <span class="status-badge status-late"><i class="fas fa-lock"></i> View Only</span>
                        <?php endif; ?>
                    </div>

                    <div class="scan-type-selector">
                        <button class="scan-type-btn active" onclick="setScanType('check_in')" <?php echo !$can_take_attendance ? 'disabled' : ''; ?>>
                            <i class="fas fa-sign-in-alt"></i> Check In
                        </button>
                        <button class="scan-type-btn" onclick="setScanType('check_out')" <?php echo !$can_take_attendance ? 'disabled' : ''; ?>>
                            <i class="fas fa-sign-out-alt"></i> Check Out
                        </button>
                    </div>

                    <div class="scanner-container">
                        <div id="reader"></div>
                        <div id="scannerPlaceholder" class="scanner-placeholder">
                            <i class="fas fa-camera"></i>
                            <p>Camera is off</p>
                            <button class="camera-btn" onclick="startScanner()" <?php echo !$can_take_attendance ? 'disabled style="opacity:0.5;"' : ''; ?>>
                                <i class="fas fa-video"></i> Start Camera
                            </button>
                        </div>
                    </div>
                    <div id="scanStatus" style="margin-top: 12px; text-align: center; font-size: 12px; color: var(--gray-500);">
                        <span class="live-indicator"></span> Click "Start Camera" to begin scanning
                    </div>
                </div>

                <!-- Today's Stats -->
                <div class="card">
                    <div class="card-title">
                        <span><i class="fas fa-chart-line"></i> Today's Summary</span>
                    </div>
                    <div class="stats-grid">
                        <div class="stat-card" onclick="showAttendanceList('present')">
                            <div class="stat-number" id="totalPresent">-</div>
                            <div class="stat-label">Present</div>
                        </div>
                        <div class="stat-card" onclick="showAttendanceList('late')">
                            <div class="stat-number" id="totalLate">-</div>
                            <div class="stat-label">Late</div>
                        </div>
                        <div class="stat-card" onclick="showAttendanceList('absent')">
                            <div class="stat-number" id="totalAbsent">-</div>
                            <div class="stat-label">Absent</div>
                        </div>
                        <div class="stat-card" onclick="showAttendanceList('all')">
                            <div class="stat-number" id="totalStudents">-</div>
                            <div class="stat-label">Total Enrolled</div>
                        </div>
                    </div>
                </div>

                <!-- Recent Scans -->
                <div class="card">
                    <div class="card-title">
                        <span><i class="fas fa-clock"></i> Recent Scans</span>
                        <button class="btn btn-secondary" style="padding: 6px 12px; font-size: 0.7rem;" onclick="loadRecentScans()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Student Name</th>
                                    <th>Admission No</th>
                                    <th>Class</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="recentScansBody">
                                <tr>
                                    <td colspan="6" style="text-align:center;">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Class Filter for Reports -->
                <div class="card">
                    <div class="card-title">
                        <span><i class="fas fa-filter"></i> Filter by Class</span>
                    </div>
                    <select id="classFilter" class="form-control" onchange="loadDailyStats()">
                        <option value="all">All Classes</option>
                        <?php foreach ($accessible_classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Daily Attendance Table -->
                <div class="card">
                    <div class="card-title">
                        <span><i class="fas fa-calendar-day"></i> Today's Attendance</span>
                        <button class="btn btn-secondary" style="padding: 6px 12px; font-size: 0.7rem;" onclick="loadDailyStats()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>S/N</th>
                                    <th>Admission No</th>
                                    <th>Student Name</th>
                                    <th>Class</th>
                                    <th>Status</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody id="dailyStatsBody">
                                <tr>
                                    <td colspan="6" style="text-align:center;">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Attendance List Modal -->
    <div class="modal" id="attendanceListModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="attendanceListTitle">Attendance List</h3>
                <button class="close-modal" onclick="closeAttendanceListModal()">&times;</button>
            </div>
            <div class="modal-body" id="attendanceListBody"></div>
        </div>
    </div>

    <script>
        let html5QrcodeScanner = null;
        let currentScanType = 'check_in';
        let isScannerActive = false;
        let canTakeAttendance = <?php echo $can_take_attendance ? 'true' : 'false'; ?>;

        // Sidebar toggle - handled in staff_sidebar.php
        document.getElementById('mobileMenuBtn').onclick = () => {
            document.getElementById('staffSidebar').classList.toggle('active');
            document.getElementById('sidebarOverlay').classList.toggle('active');
        };

        function setScanType(type) {
            if (!canTakeAttendance) {
                showFeedback('You do not have permission to take attendance', 'error');
                return;
            }
            currentScanType = type;
            document.querySelectorAll('.scan-type-btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
        }

        function startScanner() {
            if (!canTakeAttendance) {
                showFeedback('You do not have permission to take attendance', 'error');
                return;
            }

            if (html5QrcodeScanner && isScannerActive) {
                showFeedback('Camera is already active', 'info');
                return;
            }

            document.getElementById('scannerPlaceholder').style.display = 'none';
            document.getElementById('reader').classList.add('active');
            document.getElementById('reader').style.display = 'block';

            html5QrcodeScanner = new Html5Qrcode("reader");
            html5QrcodeScanner.start({
                    facingMode: "environment"
                }, {
                    fps: 10,
                    qrbox: {
                        width: 250,
                        height: 250
                    }
                },
                (decodedText) => {
                    handleScan(decodedText);
                },
                (error) => {
                    // Silent error handling
                }
            ).then(() => {
                isScannerActive = true;
                document.getElementById('scanStatus').innerHTML = '<span class="live-indicator"></span> Camera active - Ready to scan QR codes';

                if (!document.getElementById('stopCameraBtn')) {
                    const container = document.querySelector('.scanner-container');
                    const stopBtn = document.createElement('button');
                    stopBtn.id = 'stopCameraBtn';
                    stopBtn.className = 'stop-camera-btn';
                    stopBtn.innerHTML = '<i class="fas fa-stop"></i> Stop Camera';
                    stopBtn.onclick = stopScanner;
                    container.appendChild(stopBtn);
                }
            }).catch(err => {
                console.error("Failed to start scanner:", err);
                document.getElementById('scanStatus').innerHTML = '<span style="color: #ef4444;">⚠️ Camera access denied. Please check permissions.</span>';
                document.getElementById('scannerPlaceholder').style.display = 'block';
                document.getElementById('reader').style.display = 'none';
            });
        }

        function stopScanner() {
            if (html5QrcodeScanner && isScannerActive) {
                html5QrcodeScanner.stop().then(() => {
                    isScannerActive = false;
                    document.getElementById('reader').style.display = 'none';
                    document.getElementById('reader').classList.remove('active');
                    document.getElementById('scannerPlaceholder').style.display = 'block';
                    document.getElementById('scanStatus').innerHTML = '<span class="live-indicator"></span> Camera stopped - Click "Start Camera" to begin scanning';

                    const stopBtn = document.getElementById('stopCameraBtn');
                    if (stopBtn) stopBtn.remove();
                }).catch(err => {
                    console.error("Error stopping scanner:", err);
                });
            }
        }

        function handleScan(decodedText) {
            try {
                let studentId;
                try {
                    const data = JSON.parse(decodeURIComponent(decodedText));
                    if (data.type === 'student' && data.id) {
                        studentId = data.id;
                    } else if (data.id) {
                        studentId = data.id;
                    } else {
                        studentId = parseInt(decodedText);
                    }
                } catch (e) {
                    studentId = parseInt(decodedText);
                }

                if (studentId && studentId > 0) {
                    recordAttendance(studentId);
                } else {
                    showFeedback('Invalid QR code format', 'error');
                }
            } catch (e) {
                showFeedback('Invalid QR code', 'error');
            }
        }

        function recordAttendance(studentId) {
            const requestData = {
                action: 'record_attendance',
                student_id: studentId,
                scan_type: currentScanType
            };

            fetch('attendance.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(requestData)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const emoji = currentScanType === 'check_in' ? '✅' : '👋';
                        showFeedback(`${emoji} ${data.student_name} - ${currentScanType === 'check_in' ? 'Checked In' : 'Checked Out'} (${data.status})`, 'success');
                        loadTodayStats();
                        loadRecentScans();
                        loadDailyStats();
                    } else {
                        showFeedback(`❌ ${data.error || 'Failed to record attendance'}`, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showFeedback('Network error. Please check your connection.', 'error');
                });
        }

        function showFeedback(message, type) {
            const feedback = document.createElement('div');
            feedback.className = 'scan-feedback';
            if (type === 'success') {
                feedback.style.background = '#10b981';
            } else if (type === 'error') {
                feedback.style.background = '#ef4444';
            } else {
                feedback.style.background = '#3b82f6';
            }
            feedback.innerHTML = message;
            document.body.appendChild(feedback);

            setTimeout(() => {
                feedback.remove();
            }, 3000);
        }

        function loadTodayStats() {
            fetch('attendance.php?action=get_today_stats', {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('totalPresent').innerText = data.present || 0;
                        document.getElementById('totalLate').innerText = data.late || 0;
                        document.getElementById('totalAbsent').innerText = data.absent || 0;
                        document.getElementById('totalStudents').innerText = data.total || 0;
                    }
                })
                .catch(error => {
                    console.error('Error loading stats:', error);
                });
        }

        function loadRecentScans() {
            fetch('attendance.php?action=get_recent_scans', {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.scans) {
                        const tbody = document.getElementById('recentScansBody');
                        if (!data.scans || data.scans.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">No scans yet today</td></tr>';
                            return;
                        }
                        tbody.innerHTML = '';
                        data.scans.forEach(scan => {
                            tbody.innerHTML += `
                            <tr>
                                <td>${new Date(scan.scan_time).toLocaleTimeString()}</td>
                                <td><strong>${escapeHtml(scan.full_name)}</strong></td>
                                <td>${escapeHtml(scan.admission_number)}</td>
                                <td>${escapeHtml(scan.class_name || '-')}</td>
                                <td><span class="status-badge">${scan.scan_type === 'check_in' ? 'In' : 'Out'}</span></td>
                                <td><span class="status-badge status-${scan.status}">${scan.status}</span></td>
                            </tr>
                        `;
                        });
                    }
                })
                .catch(error => {
                    console.error('Error loading scans:', error);
                });
        }

        function loadDailyStats() {
            const classId = document.getElementById('classFilter').value;
            const today = new Date().toISOString().split('T')[0];

            fetch(`attendance.php?action=get_daily_stats&date=${today}&class_id=${classId}`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const tbody = document.getElementById('dailyStatsBody');
                        if (!data.attendance || data.attendance.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">No students found</td></tr>';
                            return;
                        }

                        let sn = 1;
                        tbody.innerHTML = '';
                        data.attendance.forEach(student => {
                            const status = student.scan_type ? student.status : 'absent';
                            const time = student.scan_time ? new Date(student.scan_time).toLocaleTimeString() : '-';

                            tbody.innerHTML += `
                            <tr>
                                <td>${sn++}</td>
                                <td>${escapeHtml(student.admission_number)}</td>
                                <td><strong>${escapeHtml(student.full_name)}</strong></td>
                                <td>${escapeHtml(student.class_name || '-')}</td>
                                <td><span class="status-badge status-${status}">${status === 'present' ? 'Present' : (status === 'late' ? 'Late' : 'Absent')}</span></td>
                                <td>${time}</td>
                            </tr>
                        `;
                        });
                    }
                })
                .catch(error => {
                    console.error('Error loading daily stats:', error);
                });
        }

        function showAttendanceList(type) {
            const classId = document.getElementById('classFilter').value;
            const today = new Date().toISOString().split('T')[0];

            fetch(`attendance.php?action=get_daily_stats&date=${today}&class_id=${classId}`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const modal = document.getElementById('attendanceListModal');
                        const title = document.getElementById('attendanceListTitle');
                        const body = document.getElementById('attendanceListBody');

                        let filteredStudents = data.attendance;
                        if (type === 'present') {
                            filteredStudents = data.attendance.filter(s => s.scan_type && s.status !== 'late');
                            title.innerHTML = `Present Students (${filteredStudents.length})`;
                        } else if (type === 'late') {
                            filteredStudents = data.attendance.filter(s => s.status === 'late');
                            title.innerHTML = `Late Students (${filteredStudents.length})`;
                        } else if (type === 'absent') {
                            filteredStudents = data.attendance.filter(s => !s.scan_type);
                            title.innerHTML = `Absent Students (${filteredStudents.length})`;
                        } else {
                            title.innerHTML = `All Students (${filteredStudents.length})`;
                        }

                        if (filteredStudents.length === 0) {
                            body.innerHTML = '<p style="text-align:center; padding: 20px;">No students found</p>';
                        } else {
                            body.innerHTML = `
                            <div class="table-container">
                                <table class="data-table">
                                    <thead>
                                        <tr><th>Admission No</th><th>Student Name</th><th>Class</th><th>Status</th></tr>
                                    </thead>
                                    <tbody>
                                        ${filteredStudents.map(s => `
                                            <tr>
                                                <td>${escapeHtml(s.admission_number)}</td>
                                                <td><strong>${escapeHtml(s.full_name)}</strong></td>
                                                <td>${escapeHtml(s.class_name || '-')}</td>
                                                <td><span class="status-badge status-${s.scan_type ? (s.status === 'late' ? 'late' : 'present') : 'absent'}">
                                                    ${s.scan_type ? (s.status === 'late' ? 'Late' : 'Present') : 'Absent'}
                                                </span></td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        `;
                        }

                        modal.classList.add('show');
                    }
                });
        }

        function closeAttendanceListModal() {
            document.getElementById('attendanceListModal').classList.remove('show');
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Auto-refresh every 10 seconds
        setInterval(() => {
            loadTodayStats();
            loadRecentScans();
            loadDailyStats();
        }, 10000);

        // Close modal on outside click
        window.onclick = function(event) {
            const listModal = document.getElementById('attendanceListModal');
            if (event.target === listModal) {
                closeAttendanceListModal();
            }
        };

        // Close sidebar overlay
        const overlay = document.getElementById('sidebarOverlay');
        if (overlay) {
            overlay.onclick = () => {
                document.getElementById('staffSidebar').classList.remove('active');
                overlay.classList.remove('active');
            };
        }

        // Initial load
        loadTodayStats();
        loadRecentScans();
        loadDailyStats();

        // Clean up on page unload
        window.addEventListener('beforeunload', function() {
            if (html5QrcodeScanner && isScannerActive) {
                html5QrcodeScanner.stop();
            }
        });
    </script>
</body>

</html>
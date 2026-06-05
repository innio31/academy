<?php
// admin/attendance_api.php - Admin Attendance API Endpoints
// Handles all AJAX requests for attendance management

session_start();
header('Content-Type: application/json');

require_once '../includes/config.php';
require_once '../includes/qr_helper.php';
require_once '../includes/notification_helper.php';
require_once '../includes/email_helper.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$school_id = SCHOOL_ID;
$admin_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// For POST requests with JSON body
$input = json_decode(file_get_contents('php://input'), true);
if ($input && isset($input['action'])) {
    $action = $input['action'];
}

switch ($action) {
    // ============================================================
    // DASHBOARD STATISTICS
    // ============================================================
    
    case 'get_dashboard_stats':
        $today = date('Y-m-d');
        $week_start = date('Y-m-d', strtotime('monday this week'));
        $month_start = date('Y-m-d', strtotime('first day of this month'));
        
        // Today's student attendance
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT student_id) as present
            FROM attendance_logs
            WHERE school_id = ? AND DATE(scan_time) = ? AND scan_type = 'check_in'
        ");
        $stmt->execute([$school_id, $today]);
        $present = $stmt->fetch()['present'];
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM students WHERE school_id = ? AND status = 'active'");
        $stmt->execute([$school_id]);
        $total_students = $stmt->fetch()['total'];
        
        // Today's staff attendance
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as clocked_in
            FROM staff_attendance
            WHERE school_id = ? AND date = ? AND clock_in IS NOT NULL
        ");
        $stmt->execute([$school_id, $today]);
        $staff_present = $stmt->fetch()['clocked_in'];
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM staff WHERE school_id = ? AND is_active = 1");
        $stmt->execute([$school_id]);
        $total_staff = $stmt->fetch()['total'];
        
        // Weekly attendance trend
        $stmt = $pdo->prepare("
            SELECT DATE(scan_time) as date, COUNT(DISTINCT student_id) as count
            FROM attendance_logs
            WHERE school_id = ? AND DATE(scan_time) >= ? AND scan_type = 'check_in'
            GROUP BY DATE(scan_time)
            ORDER BY date ASC
        ");
        $stmt->execute([$school_id, $week_start]);
        $weekly_trend = $stmt->fetchAll();
        
        // Late arrivals today
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT al.student_id) as late_count
            FROM attendance_logs al
            WHERE al.school_id = ? AND DATE(al.scan_time) = ? AND al.status = 'late'
        ");
        $stmt->execute([$school_id, $today]);
        $late_count = $stmt->fetch()['late_count'];
        
        // Absent students today
        $absent_count = max(0, $total_students - $present);
        
        echo json_encode([
            'success' => true,
            'stats' => [
                'present_today' => (int)$present,
                'absent_today' => (int)$absent_count,
                'late_today' => (int)$late_count,
                'total_students' => (int)$total_students,
                'staff_present_today' => (int)$staff_present,
                'total_staff' => (int)$total_staff,
                'attendance_percentage' => $total_students > 0 ? round(($present / $total_students) * 100, 1) : 0,
                'weekly_trend' => $weekly_trend
            ]
        ]);
        break;
    
    // ============================================================
    // STUDENT ATTENDANCE
    // ============================================================
    
    case 'get_student_attendance':
        $date = $_GET['date'] ?? date('Y-m-d');
        $class_id = $_GET['class_id'] ?? null;
        
        $query = "
            SELECT 
                s.id, s.full_name, s.admission_number, c.class_name,
                al.scan_type, al.status, al.scan_time,
                CASE WHEN al.id IS NOT NULL THEN 
                    CASE WHEN al.status = 'late' THEN 'late' ELSE 'present' END
                ELSE 'absent' END as attendance_status
            FROM students s
            LEFT JOIN classes c ON s.class_id = c.id
            LEFT JOIN attendance_logs al ON s.id = al.student_id 
                AND DATE(al.scan_time) = ? AND al.scan_type = 'check_in'
            WHERE s.school_id = ? AND s.status = 'active'
        ";
        $params = [$date, $school_id];
        
        if ($class_id && $class_id !== 'all') {
            $query .= " AND s.class_id = ?";
            $params[] = $class_id;
        }
        
        $query .= " ORDER BY c.class_name, s.full_name";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $attendance = $stmt->fetchAll();
        
        // Calculate stats
        $present = 0;
        $late = 0;
        $absent = 0;
        foreach ($attendance as $a) {
            if ($a['attendance_status'] === 'present') $present++;
            elseif ($a['attendance_status'] === 'late') $late++;
            else $absent++;
        }
        
        echo json_encode([
            'success' => true,
            'date' => $date,
            'attendance' => $attendance,
            'stats' => [
                'present' => $present,
                'late' => $late,
                'absent' => $absent,
                'total' => count($attendance)
            ]
        ]);
        break;
    
    case 'get_student_history':
        $student_id = $_GET['student_id'] ?? 0;
        $limit = $_GET['limit'] ?? 30;
        
        if (!$student_id) {
            echo json_encode(['success' => false, 'error' => 'Student ID required']);
            break;
        }
        
        $stmt = $pdo->prepare("
            SELECT al.*, DATE(al.scan_time) as scan_date, TIME(al.scan_time) as scan_time_only
            FROM attendance_logs al
            WHERE al.student_id = ? AND al.school_id = ?
            ORDER BY al.scan_time DESC
            LIMIT ?
        ");
        $stmt->execute([$student_id, $school_id, $limit]);
        $history = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'history' => $history]);
        break;
    
    // ============================================================
    // STAFF ATTENDANCE
    // ============================================================
    
    case 'get_staff_attendance':
        $date = $_GET['date'] ?? date('Y-m-d');
        $staff_id = $_GET['staff_id'] ?? null;
        
        $query = "
            SELECT sa.*, s.full_name, s.staff_id as staff_id_num, s.email, s.role
            FROM staff_attendance sa
            JOIN staff s ON sa.staff_id = s.staff_id
            WHERE sa.school_id = ? AND sa.date = ?
        ";
        $params = [$school_id, $date];
        
        if ($staff_id) {
            $query .= " AND s.id = ?";
            $params[] = $staff_id;
        }
        
        $query .= " ORDER BY sa.clock_in DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $attendance = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'attendance' => $attendance, 'date' => $date]);
        break;
    
    case 'get_staff_history':
        $staff_id = $_GET['staff_id'] ?? 0;
        $limit = $_GET['limit'] ?? 30;
        
        if (!$staff_id) {
            echo json_encode(['success' => false, 'error' => 'Staff ID required']);
            break;
        }
        
        $stmt = $pdo->prepare("
            SELECT sa.*, s.full_name
            FROM staff_attendance sa
            JOIN staff s ON sa.staff_id = s.staff_id
            WHERE s.id = ? AND sa.school_id = ?
            ORDER BY sa.date DESC
            LIMIT ?
        ");
        $stmt->execute([$staff_id, $school_id, $limit]);
        $history = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'history' => $history]);
        break;
    
    // ============================================================
    // QR CODE MANAGEMENT
    // ============================================================
    
    case 'get_school_qr':
        $active_qr = getActiveSchoolQRCode($pdo, $school_id);
        echo json_encode(['success' => true, 'qr' => $active_qr]);
        break;
    
    case 'regenerate_school_qr':
        $result = regenerateSchoolQRCode($pdo, $school_id, $admin_id);
        if ($result) {
            echo json_encode(['success' => true, 'qr' => $result]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to generate QR code']);
        }
        break;
    
    case 'get_class_qr':
        $class_id = $_GET['class_id'] ?? 0;
        
        if (!$class_id) {
            echo json_encode(['success' => false, 'error' => 'Class ID required']);
            break;
        }
        
        $stmt = $pdo->prepare("
            SELECT * FROM class_qr_codes 
            WHERE school_id = ? AND class_id = ? AND status = 'active'
        ");
        $stmt->execute([$school_id, $class_id]);
        $qr = $stmt->fetch();
        
        echo json_encode(['success' => true, 'qr' => $qr]);
        break;
    
    case 'generate_class_qr':
        $class_id = $_POST['class_id'] ?? $input['class_id'] ?? 0;
        $class_name = $_POST['class_name'] ?? $input['class_name'] ?? '';
        $expiry_hours = $_POST['expiry_hours'] ?? $input['expiry_hours'] ?? null;
        
        if (!$class_id || !$class_name) {
            echo json_encode(['success' => false, 'error' => 'Class ID and name required']);
            break;
        }
        
        $result = generateClassQRCode($pdo, $school_id, $class_id, $class_name, $admin_id, $expiry_hours);
        echo json_encode(['success' => true, 'qr' => $result]);
        break;
    
    // ============================================================
    // ABSENTEE ALERTS
    // ============================================================
    
    case 'get_absent_stats':
        $date = $_GET['date'] ?? date('Y-m-d');
        
        $stmt = $pdo->prepare("
            SELECT 
                c.id, c.class_name, 
                COUNT(DISTINCT s.id) as total,
                COUNT(DISTINCT CASE WHEN al.id IS NOT NULL THEN s.id END) as present,
                COUNT(DISTINCT CASE WHEN al.id IS NULL THEN s.id END) as absent
            FROM classes c
            LEFT JOIN students s ON s.class_id = c.id AND s.school_id = ? AND s.status = 'active'
            LEFT JOIN attendance_logs al ON s.id = al.student_id 
                AND DATE(al.scan_time) = ? AND al.scan_type = 'check_in'
            WHERE c.school_id = ? AND c.status = 'active'
            GROUP BY c.id, c.class_name
            ORDER BY c.class_name
        ");
        $stmt->execute([$school_id, $date, $school_id]);
        $stats = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'stats' => $stats, 'date' => $date]);
        break;
    
    case 'get_absent_students':
        $date = $_GET['date'] ?? date('Y-m-d');
        $class_id = $_GET['class_id'] ?? 0;
        
        if (!$class_id) {
            echo json_encode(['success' => false, 'error' => 'Class ID required']);
            break;
        }
        
        $stmt = $pdo->prepare("
            SELECT s.id, s.full_name, s.admission_number, s.parent_phone, s.parent_email,
                   CASE WHEN s.email_notifications_enabled THEN 'Yes' ELSE 'No' END as email_opt_in
            FROM students s
            WHERE s.class_id = ? AND s.school_id = ? AND s.status = 'active'
            AND NOT EXISTS (
                SELECT 1 FROM attendance_logs al 
                WHERE al.student_id = s.id AND DATE(al.scan_time) = ? AND al.scan_type = 'check_in'
            )
            ORDER BY s.full_name
        ");
        $stmt->execute([$class_id, $school_id, $date]);
        $students = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'students' => $students, 'count' => count($students), 'date' => $date]);
        break;
    
    case 'send_absent_notification':
        $date = $_GET['date'] ?? date('Y-m-d');
        $class_id = $_GET['class_id'] ?? 0;
        
        if (!$class_id) {
            echo json_encode(['success' => false, 'error' => 'Class ID required']);
            break;
        }
        
        // Get absent students
        $stmt = $pdo->prepare("
            SELECT s.id, s.full_name, s.admission_number, s.parent_email, s.class, c.class_name
            FROM students s
            LEFT JOIN classes c ON s.class_id = c.id
            WHERE s.class_id = ? AND s.school_id = ? AND s.status = 'active'
            AND s.parent_email IS NOT NULL AND s.parent_email != ''
            AND NOT EXISTS (
                SELECT 1 FROM attendance_logs al 
                WHERE al.student_id = s.id AND DATE(al.scan_time) = ? AND al.scan_type = 'check_in'
            )
        ");
        $stmt->execute([$class_id, $school_id, $date]);
        $students = $stmt->fetchAll();
        
        $sent = 0;
        $failed = 0;
        
        foreach ($students as $student) {
            $result = sendParentAbsenceNotification(
                $pdo, $school_id, $student['id'], 
                $student['full_name'], $student['admission_number'], 
                $student['class_name'], $date
            );
            if ($result) $sent++;
            else $failed++;
        }
        
        echo json_encode(['success' => true, 'sent' => $sent, 'failed' => $failed, 'total' => count($students)]);
        break;
    
    // ============================================================
    // NOTIFICATIONS
    // ============================================================
    
    case 'get_notifications':
        $notifications = getUserNotifications($pdo, $school_id, $admin_id, 'admin', 50);
        echo json_encode(['success' => true, 'notifications' => $notifications]);
        break;
    
    case 'get_unread_count':
        $count = getUnreadNotificationCount($pdo, $school_id, $admin_id, 'admin');
        echo json_encode(['success' => true, 'count' => $count]);
        break;
    
    case 'mark_read':
        $notification_id = $_POST['notification_id'] ?? $input['notification_id'] ?? 0;
        $result = markNotificationRead($pdo, $notification_id, $admin_id);
        echo json_encode(['success' => $result]);
        break;
    
    case 'mark_all_read':
        $result = markAllNotificationsRead($pdo, $school_id, $admin_id, 'admin');
        echo json_encode(['success' => $result]);
        break;
    
    // ============================================================
    // EMAIL SETTINGS
    // ============================================================
    
    case 'get_email_settings':
        $stmt = $pdo->prepare("SELECT * FROM attendance_settings WHERE school_id = ?");
        $stmt->execute([$school_id]);
        $settings = $stmt->fetch();
        
        echo json_encode(['success' => true, 'settings' => $settings]);
        break;
    
    case 'save_email_settings':
        $email_enabled = $_POST['email_notifications_enabled'] ?? 0;
        $from_name = $_POST['email_from_name'] ?? SCHOOL_NAME;
        $from_address = $_POST['email_from_address'] ?? '';
        $smtp_host = $_POST['smtp_host'] ?? '';
        $smtp_port = $_POST['smtp_port'] ?? 587;
        $smtp_encryption = $_POST['smtp_encryption'] ?? 'tls';
        $smtp_username = $_POST['smtp_username'] ?? '';
        $smtp_password = $_POST['smtp_password'] ?? '';
        
        $stmt = $pdo->prepare("
            UPDATE attendance_settings 
            SET email_notifications_enabled = ?, email_from_name = ?, email_from_address = ?,
                smtp_host = ?, smtp_port = ?, smtp_encryption = ?, smtp_username = ?, smtp_password = ?
            WHERE school_id = ?
        ");
        $stmt->execute([
            $email_enabled, $from_name, $from_address,
            $smtp_host, $smtp_port, $smtp_encryption, $smtp_username, $smtp_password,
            $school_id
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Email settings saved']);
        break;
    
    case 'test_email':
        $test_email = $_POST['test_email'] ?? $input['test_email'] ?? '';
        
        if (!$test_email) {
            echo json_encode(['success' => false, 'error' => 'Test email address required']);
            break;
        }
        
        $result = testEmailConfiguration($pdo, $school_id, $test_email);
        echo json_encode($result);
        break;
    
    // ============================================================
    // EXPORTS
    // ============================================================
    
    case 'export_attendance':
        $date = $_GET['date'] ?? date('Y-m-d');
        $format = $_GET['format'] ?? 'json';
        
        $stmt = $pdo->prepare("
            SELECT 
                s.full_name, s.admission_number, c.class_name,
                al.scan_time, al.status,
                CASE WHEN al.id IS NOT NULL THEN 
                    CASE WHEN al.status = 'late' THEN 'Late' ELSE 'Present' END
                ELSE 'Absent' END as attendance_status
            FROM students s
            LEFT JOIN classes c ON s.class_id = c.id
            LEFT JOIN attendance_logs al ON s.id = al.student_id 
                AND DATE(al.scan_time) = ? AND al.scan_type = 'check_in'
            WHERE s.school_id = ? AND s.status = 'active'
            ORDER BY c.class_name, s.full_name
        ");
        $stmt->execute([$date, $school_id]);
        $data = $stmt->fetchAll();
        
        if ($format === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="attendance_' . $date . '.csv"');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Name', 'Admission No', 'Class', 'Scan Time', 'Status', 'Attendance']);
            
            foreach ($data as $row) {
                fputcsv($output, [
                    $row['full_name'],
                    $row['admission_number'],
                    $row['class_name'],
                    $row['scan_time'] ?? '',
                    $row['status'] ?? '',
                    $row['attendance_status']
                ]);
            }
            fclose($output);
        } else {
            echo json_encode(['success' => true, 'data' => $data, 'date' => $date]);
        }
        break;
    
    // ============================================================
    // DEFAULT
    // ============================================================
    
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action: ' . $action]);
        break;
}
?>
<?php
// admin/manage_attendance.php - Complete Attendance Management with Custom QR Duration & Reports
// Enhanced version with attendance reports and mobile-friendly design

session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /msv/login.php");
    exit();
}

if (isset($_SESSION['admin_id'])) {
    $admin_id = $_SESSION['admin_id'];
    $admin_name = $_SESSION['admin_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'super_admin';
} else {
    $admin_id = $_SESSION['user_id'];
    $admin_name = $_SESSION['user_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

if ($admin_role !== 'super_admin' && $admin_role !== 'admin') {
    header("Location: index.php?message=Access+denied&type=error");
    exit();
}

require_once '../includes/config.php';
require_once '../includes/qr_helper.php';
require_once '../includes/notification_helper.php';
require_once '../includes/email_helper.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input) {
        $action = $input['action'] ?? $action;
    }

    switch ($action) {
        case 'regenerate_school_qr':
            $duration_hours = $_POST['duration_hours'] ?? $input['duration_hours'] ?? 24;
            $result = regenerateSchoolQRCode($pdo, $school_id, $admin_id, $duration_hours);
            echo json_encode(['success' => true, 'qr' => $result]);
            break;

        case 'generate_class_qr':
            $class_id = $_POST['class_id'] ?? $input['class_id'] ?? 0;
            $class_name = $_POST['class_name'] ?? $input['class_name'] ?? '';
            $expiry_hours = $_POST['expiry_hours'] ?? $input['expiry_hours'] ?? null;

            $result = generateClassQRCode($pdo, $school_id, $class_id, $class_name, $admin_id, $expiry_hours);
            echo json_encode(['success' => true, 'qr' => $result]);
            break;

        case 'get_attendance_report':
            $report_type = $_GET['report_type'] ?? 'daily';
            $date = $_GET['date'] ?? date('Y-m-d');
            $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
            $end_date = $_GET['end_date'] ?? date('Y-m-d');
            $class_id = $_GET['class_id'] ?? '';
            $user_type = $_GET['user_type'] ?? 'student';

            if ($report_type === 'daily') {
                $report = getDailyAttendanceReport($pdo, $school_id, $date, $class_id, $user_type);
            } elseif ($report_type === 'weekly') {
                $report = getWeeklyAttendanceReport($pdo, $school_id, $start_date, $end_date, $class_id, $user_type);
            } elseif ($report_type === 'monthly') {
                $report = getMonthlyAttendanceReport($pdo, $school_id, $start_date, $end_date, $class_id, $user_type);
            } elseif ($report_type === 'student') {
                $student_id = $_GET['student_id'] ?? 0;
                $report = getStudentAttendanceReport($pdo, $school_id, $student_id, $start_date, $end_date);
            } elseif ($report_type === 'staff') {
                $staff_id = $_GET['staff_id'] ?? 0;
                $report = getStaffAttendanceReport($pdo, $school_id, $staff_id, $start_date, $end_date);
            } else {
                $report = getAttendanceSummary($pdo, $school_id, $start_date, $end_date, $class_id, $user_type);
            }

            echo json_encode(['success' => true, 'report' => $report]);
            break;

        case 'export_attendance_report':
            $report_type = $_POST['report_type'] ?? 'daily';
            $date = $_POST['date'] ?? date('Y-m-d');
            $start_date = $_POST['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
            $end_date = $_POST['end_date'] ?? date('Y-m-d');
            $class_id = $_POST['class_id'] ?? '';
            $user_type = $_POST['user_type'] ?? 'student';
            $format = $_POST['format'] ?? 'csv';

            $report = getAttendanceReportData($pdo, $school_id, $report_type, $date, $start_date, $end_date, $class_id, $user_type);
            $filename = "attendance_report_{$report_type}_{$start_date}_to_{$end_date}.{$format}";

            if ($format === 'csv') {
                exportToCSV($report, $filename);
            } elseif ($format === 'pdf') {
                exportToPDF($report, $filename, $school_name);
            }
            break;

        case 'get_unread_count':
            $count = getUnreadNotificationCount($pdo, $school_id, $admin_id, 'admin');
            echo json_encode(['success' => true, 'count' => $count]);
            break;

        case 'get_notifications':
            $notifications = getUserNotifications($pdo, $school_id, $admin_id, 'admin', 20);
            echo json_encode(['success' => true, 'notifications' => $notifications]);
            break;

        case 'mark_read':
            $notification_id = $_POST['notification_id'] ?? $input['notification_id'] ?? 0;
            markNotificationRead($pdo, $notification_id, $admin_id);
            echo json_encode(['success' => true]);
            break;

        case 'mark_all_read':
            markAllNotificationsRead($pdo, $school_id, $admin_id, 'admin');
            echo json_encode(['success' => true]);
            break;

        case 'test_email':
            $test_email = $_POST['test_email'] ?? $input['test_email'] ?? '';
            $result = testEmailConfiguration($pdo, $school_id, $test_email);
            echo json_encode($result);
            break;

        case 'save_email_settings':
            $settings = [
                'email_notifications_enabled' => $_POST['email_notifications_enabled'] ?? 0,
                'email_from_name' => $_POST['email_from_name'] ?? $school_name,
                'email_from_address' => $_POST['email_from_address'] ?? '',
                'smtp_host' => $_POST['smtp_host'] ?? '',
                'smtp_port' => $_POST['smtp_port'] ?? 587,
                'smtp_encryption' => $_POST['smtp_encryption'] ?? 'tls',
                'smtp_username' => $_POST['smtp_username'] ?? '',
                'smtp_password' => $_POST['smtp_password'] ?? ''
            ];

            $stmt = $pdo->prepare("
                UPDATE attendance_settings 
                SET email_notifications_enabled = ?, email_from_name = ?, email_from_address = ?,
                    smtp_host = ?, smtp_port = ?, smtp_encryption = ?, smtp_username = ?, smtp_password = ?
                WHERE school_id = ?
            ");
            $stmt->execute([
                $settings['email_notifications_enabled'],
                $settings['email_from_name'],
                $settings['email_from_address'],
                $settings['smtp_host'],
                $settings['smtp_port'],
                $settings['smtp_encryption'],
                $settings['smtp_username'],
                $settings['smtp_password'],
                $school_id
            ]);
            echo json_encode(['success' => true, 'message' => 'Email settings saved']);
            break;

        case 'get_absent_stats':
            $date = $_GET['date'] ?? date('Y-m-d');
            $stmt = $pdo->prepare("
                SELECT c.id, c.class_name, 
                    (SELECT COUNT(*) FROM students WHERE class_id = c.id AND school_id = ? AND status = 'active') as total,
                    (SELECT COUNT(*) FROM attendance_logs al 
                     JOIN students s ON al.student_id = s.id 
                     WHERE s.class_id = c.id AND DATE(al.scan_time) = ? AND al.scan_type = 'check_in') as present
                FROM classes c
                WHERE c.school_id = ? AND c.status = 'active'
                ORDER BY c.class_name
            ");
            $stmt->execute([$school_id, $date, $school_id]);
            $stats = $stmt->fetchAll();

            foreach ($stats as &$stat) {
                $stat['absent'] = max(0, $stat['total'] - $stat['present']);
            }

            echo json_encode(['success' => true, 'stats' => $stats, 'date' => $date]);
            break;

        case 'get_absent_students':
            $date = $_GET['date'] ?? date('Y-m-d');
            $class_id = $_GET['class_id'] ?? 0;

            if ($class_id) {
                $stmt = $pdo->prepare("
                    SELECT s.id, s.full_name, s.admission_number, s.parent_phone, s.parent_email
                    FROM students s
                    WHERE s.class_id = ? AND s.school_id = ? AND s.status = 'active'
                    AND NOT EXISTS (
                        SELECT 1 FROM attendance_logs al 
                        WHERE al.student_id = s.id AND DATE(al.scan_time) = ? AND al.scan_type = 'check_in'
                    )
                    ORDER BY s.full_name
                ");
                $stmt->execute([$class_id, $school_id, $date]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT s.id, s.full_name, s.admission_number, s.parent_phone, s.parent_email, c.class_name
                    FROM students s
                    LEFT JOIN classes c ON s.class_id = c.id
                    WHERE s.school_id = ? AND s.status = 'active'
                    AND NOT EXISTS (
                        SELECT 1 FROM attendance_logs al 
                        WHERE al.student_id = s.id AND DATE(al.scan_time) = ? AND al.scan_type = 'check_in'
                    )
                    ORDER BY c.class_name, s.full_name
                ");
                $stmt->execute([$school_id, $date]);
            }
            $students = $stmt->fetchAll();

            echo json_encode(['success' => true, 'students' => $students, 'count' => count($students)]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    exit();
}

// Helper function: Get Daily Attendance Report
function getDailyAttendanceReport($pdo, $school_id, $date, $class_id = '', $user_type = 'student')
{
    $report = [
        'date' => $date,
        'user_type' => $user_type,
        'summary' => [],
        'details' => []
    ];

    if ($user_type === 'student') {
        // Student attendance summary by class
        $classCondition = $class_id ? "AND c.id = ?" : "";
        $params = [$school_id, $date, $school_id];
        if ($class_id) $params[] = $class_id;

        $stmt = $pdo->prepare("
            SELECT 
                c.id as class_id,
                c.class_name,
                COUNT(DISTINCT s.id) as total_students,
                COUNT(DISTINCT CASE WHEN DATE(al.scan_time) = ? AND al.scan_type = 'check_in' THEN s.id END) as present_count,
                COUNT(DISTINCT CASE WHEN DATE(al.scan_time) = ? AND al.scan_type = 'check_in' AND al.status = 'late' THEN s.id END) as late_count
            FROM classes c
            JOIN students s ON s.class_id = c.id
            LEFT JOIN attendance_logs al ON al.student_id = s.id AND DATE(al.scan_time) = ?
            WHERE c.school_id = ? AND c.status = 'active' AND s.status = 'active'
            {$classCondition}
            GROUP BY c.id, c.class_name
            ORDER BY c.class_name
        ");
        $stmt->execute($params);
        $summary = $stmt->fetchAll();

        $report['summary'] = $summary;
        $report['total_present'] = array_sum(array_column($summary, 'present_count'));
        $report['total_students'] = array_sum(array_column($summary, 'total_students'));
        $report['attendance_percentage'] = $report['total_students'] > 0 ?
            round(($report['total_present'] / $report['total_students']) * 100, 2) : 0;

        // Get detailed student attendance
        $detailCondition = $class_id ? "AND s.class_id = ?" : "";
        $detailParams = [$school_id, $date, $date, $school_id];
        if ($class_id) $detailParams[] = $class_id;

        $stmt = $pdo->prepare("
            SELECT 
                s.id, s.full_name, s.admission_number, c.class_name,
                CASE WHEN DATE(al.scan_time) = ? THEN 'present' 
                     WHEN DATE(al.scan_time) = ? AND al.status = 'late' THEN 'late'
                     ELSE 'absent' END as attendance_status,
                TIME(al.scan_time) as check_in_time,
                al.latitude, al.longitude
            FROM students s
            JOIN classes c ON s.class_id = c.id
            LEFT JOIN attendance_logs al ON al.student_id = s.id AND DATE(al.scan_time) = ? AND al.scan_type = 'check_in'
            WHERE s.school_id = ? AND s.status = 'active'
            {$detailCondition}
            ORDER BY c.class_name, s.full_name
        ");
        $stmt->execute($detailParams);
        $report['details'] = $stmt->fetchAll();
    } else {
        // Staff attendance report
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_staff,
                SUM(CASE WHEN DATE(sa.date) = ? AND sa.status = 'present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN DATE(sa.date) = ? AND sa.status = 'late' THEN 1 ELSE 0 END) as late_count,
                SUM(CASE WHEN DATE(sa.date) = ? AND sa.status = 'absent' THEN 1 ELSE 0 END) as absent_count
            FROM staff st
            LEFT JOIN staff_attendance sa ON sa.staff_id = st.staff_id AND DATE(sa.date) = ?
            WHERE st.school_id = ? AND st.is_active = 1
        ");
        $stmt->execute([$date, $date, $date, $date, $school_id]);
        $report['summary'] = $stmt->fetch();

        $detailParams = [$date, $school_id];
        if ($class_id) $detailParams[] = $class_id;

        $classConditionStaff = $class_id ? "AND sc.class_id = ?" : "";
        $stmt = $pdo->prepare("
            SELECT 
                st.id, st.full_name, st.staff_id, st.role,
                COALESCE(sa.status, 'absent') as attendance_status,
                TIME(sa.clock_in) as clock_in_time,
                TIME(sa.clock_out) as clock_out_time,
                sa.late_minutes,
                GROUP_CONCAT(DISTINCT c.class_name) as assigned_classes
            FROM staff st
            LEFT JOIN staff_attendance sa ON sa.staff_id = st.staff_id AND DATE(sa.date) = ?
            LEFT JOIN staff_classes sc ON sc.staff_id = st.staff_id
            LEFT JOIN classes c ON c.id = sc.class_id
            WHERE st.school_id = ? AND st.is_active = 1
            {$classConditionStaff}
            GROUP BY st.id
            ORDER BY st.full_name
        ");
        $stmt->execute($detailParams);
        $report['details'] = $stmt->fetchAll();
    }

    return $report;
}

function getWeeklyAttendanceReport($pdo, $school_id, $start_date, $end_date, $class_id = '', $user_type = 'student')
{
    $report = [
        'start_date' => $start_date,
        'end_date' => $end_date,
        'user_type' => $user_type,
        'daily_breakdown' => [],
        'summary' => []
    ];

    $dates = [];
    $current = strtotime($start_date);
    $end = strtotime($end_date);
    while ($current <= $end) {
        $dates[] = date('Y-m-d', $current);
        $current = strtotime('+1 day', $current);
    }

    if ($user_type === 'student') {
        $classCondition = $class_id ? "AND s.class_id = ?" : "";
        $params = [$school_id, $school_id];
        if ($class_id) $params[] = $class_id;

        $stmt = $pdo->prepare("
            SELECT s.id, s.full_name, c.class_name
            FROM students s
            JOIN classes c ON s.class_id = c.id
            WHERE s.school_id = ? AND s.status = 'active'
            {$classCondition}
            ORDER BY c.class_name, s.full_name
        ");
        $stmt->execute($params);
        $students = $stmt->fetchAll();

        foreach ($dates as $date) {
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(DISTINCT s.id) as total,
                    COUNT(DISTINCT CASE WHEN DATE(al.scan_time) = ? AND al.scan_type = 'check_in' THEN s.id END) as present
                FROM students s
                JOIN classes c ON s.class_id = c.id
                LEFT JOIN attendance_logs al ON al.student_id = s.id AND DATE(al.scan_time) = ?
                WHERE s.school_id = ? AND s.status = 'active'
                {$classCondition}
            ");
            $params2 = [$date, $date, $school_id];
            if ($class_id) $params2[] = $class_id;
            $stmt->execute($params2);
            $daily = $stmt->fetch();

            $report['daily_breakdown'][] = [
                'date' => $date,
                'total' => $daily['total'],
                'present' => $daily['present'],
                'absent' => $daily['total'] - $daily['present'],
                'percentage' => $daily['total'] > 0 ? round(($daily['present'] / $daily['total']) * 100, 2) : 0
            ];
        }

        // Weekly summary
        $total_present = array_sum(array_column($report['daily_breakdown'], 'present'));
        $total_days = count($dates);
        $avg_students = count($students);

        $report['summary'] = [
            'total_students' => $avg_students,
            'total_present_days' => $total_present,
            'average_daily_attendance' => $avg_students > 0 ? round(($total_present / ($avg_students * $total_days)) * 100, 2) : 0,
            'best_day' => !empty($report['daily_breakdown']) ?
                array_reduce($report['daily_breakdown'], function ($carry, $item) {
                    return (!$carry || $item['percentage'] > $carry['percentage']) ? $item : $carry;
                }) : null
        ];
    }

    return $report;
}

function getMonthlyAttendanceReport($pdo, $school_id, $start_date, $end_date, $class_id = '', $user_type = 'student')
{
    $report = getWeeklyAttendanceReport($pdo, $school_id, $start_date, $end_date, $class_id, $user_type);
    $report['report_type'] = 'monthly';

    // Calculate monthly totals
    $total_present = array_sum(array_column($report['daily_breakdown'], 'present'));
    $total_days = count($report['daily_breakdown']);
    $total_students = $report['summary']['total_students'] ?? 0;

    $report['monthly_summary'] = [
        'total_school_days' => $total_days,
        'total_attendance_records' => $total_present,
        'overall_attendance_percentage' => ($total_students * $total_days) > 0 ?
            round(($total_present / ($total_students * $total_days)) * 100, 2) : 0
    ];

    return $report;
}

function getStudentAttendanceReport($pdo, $school_id, $student_id, $start_date, $end_date)
{
    $stmt = $pdo->prepare("
        SELECT s.id, s.full_name, s.admission_number, c.class_name,
            COUNT(DISTINCT DATE(al.scan_time)) as days_present,
            COUNT(DISTINCT CASE WHEN al.status = 'late' THEN DATE(al.scan_time) END) as days_late,
            GROUP_CONCAT(DISTINCT DATE(al.scan_time) ORDER BY al.scan_time SEPARATOR ', ') as present_dates
        FROM students s
        JOIN classes c ON s.class_id = c.id
        LEFT JOIN attendance_logs al ON al.student_id = s.id 
            AND DATE(al.scan_time) BETWEEN ? AND ?
            AND al.scan_type = 'check_in'
        WHERE s.id = ? AND s.school_id = ?
        GROUP BY s.id
    ");
    $stmt->execute([$start_date, $end_date, $student_id, $school_id]);
    $student = $stmt->fetch();

    // Get daily breakdown
    $stmt = $pdo->prepare("
        SELECT 
            DATE(al.scan_time) as date,
            TIME(al.scan_time) as check_in_time,
            al.status,
            al.latitude, al.longitude
        FROM attendance_logs al
        WHERE al.student_id = ? AND DATE(al.scan_time) BETWEEN ? AND ? AND al.scan_type = 'check_in'
        ORDER BY al.scan_time DESC
    ");
    $stmt->execute([$student_id, $start_date, $end_date]);
    $daily_logs = $stmt->fetchAll();

    return [
        'student' => $student,
        'daily_logs' => $daily_logs,
        'total_days' => count($daily_logs),
        'date_range' => ['start' => $start_date, 'end' => $end_date]
    ];
}

function getStaffAttendanceReport($pdo, $school_id, $staff_id, $start_date, $end_date)
{
    $stmt = $pdo->prepare("
        SELECT st.id, st.full_name, st.staff_id, st.role,
            COUNT(DISTINCT DATE(sa.date)) as days_present,
            SUM(sa.late_minutes) as total_late_minutes,
            AVG(CASE WHEN sa.late_minutes > 0 THEN sa.late_minutes END) as avg_late_minutes
        FROM staff st
        LEFT JOIN staff_attendance sa ON sa.staff_id = st.staff_id 
            AND DATE(sa.date) BETWEEN ? AND ?
            AND sa.status = 'present'
        WHERE st.id = ? AND st.school_id = ?
        GROUP BY st.id
    ");
    $stmt->execute([$start_date, $end_date, $staff_id, $school_id]);
    $staff = $stmt->fetch();

    // Get daily breakdown
    $stmt = $pdo->prepare("
        SELECT 
            DATE(sa.date) as date,
            TIME(sa.clock_in) as clock_in_time,
            TIME(sa.clock_out) as clock_out_time,
            sa.status,
            sa.late_minutes,
            sa.attendance_source
        FROM staff_attendance sa
        WHERE sa.staff_id = ? AND DATE(sa.date) BETWEEN ? AND ?
        ORDER BY sa.date DESC
    ");
    $stmt->execute([$staff_id, $start_date, $end_date]);
    $daily_logs = $stmt->fetchAll();

    return [
        'staff' => $staff,
        'daily_logs' => $daily_logs,
        'total_days' => count($daily_logs),
        'date_range' => ['start' => $start_date, 'end' => $end_date]
    ];
}

function getAttendanceSummary($pdo, $school_id, $start_date, $end_date, $class_id = '', $user_type = 'student')
{
    if ($user_type === 'student') {
        $classCondition = $class_id ? "AND s.class_id = ?" : "";
        $params = [$school_id, $start_date, $end_date, $school_id];
        if ($class_id) $params[] = $class_id;

        $stmt = $pdo->prepare("
            SELECT 
                c.class_name,
                COUNT(DISTINCT s.id) as total_students,
                COUNT(DISTINCT CASE WHEN DATE(al.scan_time) BETWEEN ? AND ? THEN s.id END) as students_with_attendance,
                COUNT(DISTINCT al.id) as total_scans
            FROM classes c
            JOIN students s ON s.class_id = c.id
            LEFT JOIN attendance_logs al ON al.student_id = s.id 
                AND DATE(al.scan_time) BETWEEN ? AND ?
                AND al.scan_type = 'check_in'
            WHERE c.school_id = ? AND c.status = 'active' AND s.status = 'active'
            {$classCondition}
            GROUP BY c.id, c.class_name
            ORDER BY c.class_name
        ");
        $stmt->execute($params);
        $summary = $stmt->fetchAll();

        return [
            'summary_by_class' => $summary,
            'total_students' => array_sum(array_column($summary, 'total_students')),
            'date_range' => ['start' => $start_date, 'end' => $end_date]
        ];
    }

    return [];
}

function getAttendanceReportData($pdo, $school_id, $report_type, $date, $start_date, $end_date, $class_id, $user_type)
{
    if ($report_type === 'daily') {
        return getDailyAttendanceReport($pdo, $school_id, $date, $class_id, $user_type);
    } elseif ($report_type === 'weekly') {
        return getWeeklyAttendanceReport($pdo, $school_id, $start_date, $end_date, $class_id, $user_type);
    } elseif ($report_type === 'monthly') {
        return getMonthlyAttendanceReport($pdo, $school_id, $start_date, $end_date, $class_id, $user_type);
    } else {
        return getAttendanceSummary($pdo, $school_id, $start_date, $end_date, $class_id, $user_type);
    }
}

function exportToCSV($data, $filename)
{
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    // Write headers based on data structure
    if (isset($data['details']) && !empty($data['details'])) {
        $headers = array_keys((array)$data['details'][0]);
        fputcsv($output, $headers);

        foreach ($data['details'] as $row) {
            fputcsv($output, (array)$row);
        }
    } elseif (isset($data['daily_breakdown'])) {
        $headers = ['Date', 'Total Students', 'Present', 'Absent', 'Percentage'];
        fputcsv($output, $headers);

        foreach ($data['daily_breakdown'] as $row) {
            fputcsv($output, [
                $row['date'],
                $row['total'],
                $row['present'],
                $row['absent'],
                $row['percentage'] . '%'
            ]);
        }
    }

    fclose($output);
    exit();
}

function exportToPDF($data, $filename, $school_name)
{
    // Simple HTML-based PDF export (can be enhanced with DOMPDF or similar)
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="' . str_replace('.pdf', '.html', $filename) . '"');

    echo '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Attendance Report - ' . htmlspecialchars($school_name) . '</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            h1 { color: #333; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; }
            .summary { margin: 20px 0; padding: 10px; background: #f9f9f9; }
        </style>
    </head>
    <body>
        <h1>' . htmlspecialchars($school_name) . ' - Attendance Report</h1>';

    if (isset($data['details'])) {
        echo '<table>';
        if (!empty($data['details'])) {
            $headers = array_keys((array)$data['details'][0]);
            echo '<tr>';
            foreach ($headers as $header) {
                echo '<th>' . htmlspecialchars($header) . '</th>';
            }
            echo '</tr>';

            foreach ($data['details'] as $row) {
                echo '<tr>';
                foreach ((array)$row as $cell) {
                    echo '<td>' . htmlspecialchars($cell ?? '') . '</td>';
                }
                echo '</tr>';
            }
        }
        echo '</table>';
    }

    echo '</body></html>';
    exit();
}

// Get current active school QR
$active_school_qr = getActiveSchoolQRCode($pdo, $school_id);

// Get all classes for filters
$classes = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? AND status = 'active' ORDER BY class_name");
$classes->execute([$school_id]);
$all_classes = $classes->fetchAll();

// Get all staff for report filters
$staff = $pdo->prepare("SELECT id, full_name, staff_id, role FROM staff WHERE school_id = ? AND is_active = 1 ORDER BY full_name");
$staff->execute([$school_id]);
$all_staff = $staff->fetchAll();

// Get attendance settings
$settings = $pdo->prepare("SELECT * FROM attendance_settings WHERE school_id = ?");
$settings->execute([$school_id]);
$attendance_settings = $settings->fetch();
if (!$attendance_settings) {
    $stmt = $pdo->prepare("INSERT INTO attendance_settings (school_id) VALUES (?)");
    $stmt->execute([$school_id]);
    $attendance_settings = ['email_notifications_enabled' => 1, 'email_from_name' => $school_name];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title><?php echo htmlspecialchars($school_name); ?> - Attendance Management</title>

    <!-- QR Scanner -->
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

    <!-- Chart.js for reports -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <!-- Font Awesome & Google Fonts -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: <?php echo $secondary_color; ?>;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
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
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--gray-100);
            color: var(--gray-800);
            min-height: 100vh;
        }

        /* Main Content */
        .main-content {
            min-height: 100vh;
            padding: 16px;
            transition: margin-left 0.3s ease;
        }

        /* Top Bar */
        .top-bar {
            background: white;
            border-radius: var(--radius-lg);
            padding: 12px 16px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: var(--shadow-sm);
        }

        .header-title h1 {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 2px;
        }

        .header-title h1 i {
            color: var(--primary-color);
            margin-right: 8px;
        }

        .header-title p {
            font-size: 0.7rem;
            color: var(--gray-500);
        }

        /* Notification Bell */
        .notification-bell {
            position: relative;
            cursor: pointer;
        }

        .notification-bell i {
            font-size: 1.3rem;
            color: var(--gray-600);
        }

        .notification-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--danger);
            color: white;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 20px;
            min-width: 18px;
            text-align: center;
        }

        /* Notification Dropdown */
        .notification-dropdown {
            position: absolute;
            top: 50px;
            right: 10px;
            width: 320px;
            max-width: calc(100vw - 20px);
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            z-index: 1000;
            display: none;
            max-height: 400px;
            overflow-y: auto;
        }

        .notification-dropdown.show {
            display: block;
        }

        .notification-header {
            padding: 12px 16px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
        }

        .notification-item {
            padding: 12px 16px;
            border-bottom: 1px solid var(--gray-100);
            cursor: pointer;
            transition: background 0.2s;
        }

        .notification-item:hover {
            background: var(--gray-50);
        }

        .notification-item.unread {
            background: rgba(59, 130, 246, 0.05);
            border-left: 3px solid var(--primary-color);
        }

        .notification-title {
            font-weight: 600;
            font-size: 0.8rem;
            margin-bottom: 4px;
        }

        .notification-body {
            font-size: 0.7rem;
            color: var(--gray-600);
        }

        .notification-time {
            font-size: 0.6rem;
            color: var(--gray-400);
            margin-top: 4px;
        }

        /* Container */
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Tabs - Mobile Friendly Scrollable */
        .tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 16px;
            background: white;
            padding: 8px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .tab-btn {
            flex: 0 0 auto;
            padding: 10px 12px;
            background: none;
            border: none;
            font-weight: 600;
            font-size: 0.75rem;
            cursor: pointer;
            border-radius: var(--radius-md);
            transition: all 0.2s;
            font-family: inherit;
            color: var(--gray-600);
            white-space: nowrap;
        }

        .tab-btn i {
            margin-right: 6px;
            font-size: 0.9rem;
        }

        .tab-btn.active {
            background: var(--primary-color);
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--gray-200);
        }

        .card-header h2 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--gray-800);
        }

        .card-header h2 i {
            color: var(--primary-color);
            margin-right: 8px;
        }

        /* QR Display */
        .qr-display {
            text-align: center;
            padding: 20px;
            background: var(--gray-50);
            border-radius: var(--radius-lg);
        }

        .qr-image {
            max-width: 180px;
            width: 100%;
            height: auto;
            margin: 0 auto;
            border: 3px solid white;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-md);
        }

        .qr-info {
            margin-top: 12px;
            font-size: 0.75rem;
            color: var(--gray-500);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }

        @media (min-width: 640px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .stat-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 16px;
            text-align: center;
            box-shadow: var(--shadow-sm);
            cursor: pointer;
            transition: transform 0.2s;
        }

        .stat-card:active {
            transform: scale(0.98);
        }

        .stat-number {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--primary-color);
            line-height: 1;
        }

        .stat-label {
            font-size: 0.7rem;
            color: var(--gray-500);
            margin-top: 4px;
        }

        /* Report Cards */
        .report-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }

        .report-stat {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 16px;
            border-radius: var(--radius-lg);
            text-align: center;
        }

        .report-stat .value {
            font-size: 1.8rem;
            font-weight: 800;
        }

        .report-stat .label {
            font-size: 0.7rem;
            opacity: 0.9;
        }

        /* Filter Bar */
        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
            background: var(--gray-50);
            padding: 12px;
            border-radius: var(--radius-lg);
        }

        .filter-group {
            flex: 1;
            min-width: 120px;
        }

        .filter-group label {
            display: block;
            font-size: 0.7rem;
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--gray-600);
        }

        /* Class Stats List */
        .class-stats-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .class-stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: var(--gray-50);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: background 0.2s;
        }

        .class-stat-item:active {
            background: var(--gray-200);
        }

        .class-name {
            font-weight: 600;
            font-size: 0.85rem;
        }

        .class-absent {
            background: #fee2e2;
            color: var(--danger);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* Table - Mobile Friendly Scrollable */
        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.75rem;
            min-width: 500px;
        }

        .data-table th,
        .data-table td {
            padding: 10px 8px;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
        }

        .data-table th {
            background: var(--gray-50);
            font-weight: 600;
            color: var(--gray-600);
            font-size: 0.7rem;
            position: sticky;
            top: 0;
        }

        /* Attendance Status Badges */
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .status-present {
            background: #d1fae5;
            color: #065f46;
        }

        .status-absent {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-late {
            background: #fed7aa;
            color: #92400e;
        }

        /* Progress Bar */
        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--gray-200);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--primary-color);
            border-radius: 4px;
            transition: width 0.3s;
        }

        /* Buttons */
        .btn {
            padding: 10px 16px;
            border-radius: var(--radius-md);
            border: none;
            font-weight: 500;
            font-size: 0.8rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            font-family: inherit;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:active {
            transform: scale(0.97);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-secondary {
            background: var(--gray-200);
            color: var(--gray-700);
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.7rem;
        }

        .btn-block {
            width: 100%;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            font-size: 0.75rem;
            color: var(--gray-700);
        }

        .form-control,
        .form-select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 0.85rem;
            transition: all 0.2s;
            background: white;
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        /* Duration Options */
        .duration-options {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 8px;
        }

        .duration-btn {
            flex: 1;
            padding: 8px;
            background: var(--gray-100);
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-size: 0.7rem;
            font-weight: 500;
            cursor: pointer;
            text-align: center;
        }

        .duration-btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        /* Checkbox */
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
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
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: var(--radius-lg);
            width: 100%;
            max-width: 500px;
            max-height: 85vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 16px;
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
            padding: 16px;
        }

        .modal-footer {
            padding: 16px;
            border-top: 1px solid var(--gray-200);
            display: flex;
            gap: 12px;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .close-modal:active {
            background: var(--gray-100);
        }

        /* Alert */
        .alert {
            padding: 12px;
            border-radius: var(--radius-md);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.75rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 3px solid var(--success);
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border-left: 3px solid var(--danger);
        }

        .alert-warning {
            background: #fed7aa;
            color: #92400e;
            border-left: 3px solid var(--warning);
        }

        /* Chart Container */
        .chart-container {
            margin: 20px 0;
            height: 250px;
            position: relative;
        }

        canvas {
            max-height: 250px;
            width: 100%;
        }

        /* Loading Spinner */
        .loading {
            text-align: center;
            padding: 40px;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--gray-200);
            border-top-color: var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Responsive */
        @media (max-width: 767px) {
            .main-content {
                padding: 12px;
            }

            .tab-btn {
                font-size: 0.7rem;
                padding: 8px 12px;
            }

            .tab-btn i {
                margin-right: 4px;
            }

            .card {
                padding: 14px;
            }

            .stats-grid {
                gap: 10px;
            }

            .stat-number {
                font-size: 1.4rem;
            }

            .report-stat .value {
                font-size: 1.4rem;
            }

            .filter-group {
                min-width: 100%;
            }
        }

        @media (min-width: 768px) {
            .main-content {
                margin-left: 280px;
            }

            .stats-grid {
                gap: 16px;
            }

            .stat-number {
                font-size: 2rem;
            }

            .filter-group {
                min-width: 160px;
            }
        }
    </style>
</head>

<body>
    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="header-title">
                <h1><i class="fas fa-calendar-check"></i> Attendance Management</h1>
                <p>Manage QR codes, track attendance, generate reports</p>
            </div>
            <div class="notification-bell" id="notificationBell">
                <i class="fas fa-bell"></i>
                <span class="notification-badge" id="notificationCount">0</span>
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header">
                        <strong>Notifications</strong>
                        <button class="btn btn-secondary btn-sm" id="markAllReadBtn" style="padding: 4px 8px; font-size: 0.65rem;">Mark all read</button>
                    </div>
                    <div id="notificationList"></div>
                </div>
            </div>
        </div>

        <div class="container">
            <!-- Tabs -->
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('school_qr')">
                    <i class="fas fa-qrcode"></i> <span>School QR</span>
                </button>
                <button class="tab-btn" onclick="switchTab('class_qr')">
                    <i class="fas fa-chalkboard"></i> <span>Class QR</span>
                </button>
                <button class="tab-btn" onclick="switchTab('attendance_report')">
                    <i class="fas fa-chart-line"></i> <span>Reports</span>
                </button>
                <button class="tab-btn" onclick="switchTab('absent_alert')">
                    <i class="fas fa-bell"></i> <span>Absent Alert</span>
                </button>
                <button class="tab-btn" onclick="switchTab('email_settings')">
                    <i class="fas fa-envelope"></i> <span>Email</span>
                </button>
            </div>

            <!-- Tab 1: School QR -->
            <div id="tab-school_qr" class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-qrcode"></i> School QR Code</h2>
                    </div>
                    <div class="qr-display" id="schoolQRDisplay">
                        <?php if ($active_school_qr && file_exists($_SERVER['DOCUMENT_ROOT'] . $active_school_qr['qr_image'])): ?>
                            <img src="<?php echo htmlspecialchars($active_school_qr['qr_image']); ?>" alt="School QR Code" class="qr-image">
                            <div class="qr-info">
                                <p><i class="fas fa-clock"></i> Expires: <?php echo date('M j, Y g:i A', strtotime($active_school_qr['expires_at'])); ?></p>
                                <p><i class="fas fa-info-circle"></i> Staff scan this QR to clock in/out</p>
                            </div>
                        <?php else: ?>
                            <p style="margin-bottom: 16px;">No active school QR code. Generate one for staff attendance.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-clock"></i> QR Duration</h2>
                    </div>
                    <div class="duration-options" id="qrDurationOptions">
                        <div class="duration-btn" data-hours="1">1 Hour</div>
                        <div class="duration-btn" data-hours="6">6 Hours</div>
                        <div class="duration-btn" data-hours="12">12 Hours</div>
                        <div class="duration-btn active" data-hours="24">24 Hours</div>
                        <div class="duration-btn" data-hours="48">48 Hours</div>
                        <div class="duration-btn" data-hours="72">72 Hours</div>
                    </div>
                    <button class="btn btn-primary btn-block" onclick="regenerateSchoolQRWithDuration()" style="margin-top: 16px;">
                        <i class="fas fa-sync-alt"></i> Generate / Regenerate QR
                    </button>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-info-circle"></i> How It Works</h2>
                    </div>
                    <ul style="padding-left: 20px; font-size: 0.8rem; color: var(--gray-600);">
                        <li>Choose how long the QR code should be valid</li>
                        <li>Staff scan this QR code using the staff portal to clock in/out</li>
                        <li>You can manually regenerate anytime - old QR becomes invalid</li>
                        <li>Each scan is logged with timestamp for accountability</li>
                    </ul>
                </div>
            </div>

            <!-- Tab 2: Class QR -->
            <div id="tab-class_qr" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-chalkboard"></i> Class QR Codes</h2>
                    </div>
                    <div class="form-group">
                        <label>Select Class</label>
                        <select id="classSelect" class="form-select">
                            <option value="">Select a class</option>
                            <?php foreach ($all_classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Expiry (Optional)</label>
                        <select id="qrExpiry" class="form-select">
                            <option value="">Never expires</option>
                            <option value="1">1 hour</option>
                            <option value="6">6 hours</option>
                            <option value="12">12 hours</option>
                            <option value="24">24 hours</option>
                            <option value="168">7 days</option>
                        </select>
                    </div>
                    <button class="btn btn-primary btn-block" onclick="generateClassQR()">
                        <i class="fas fa-qrcode"></i> Generate Class QR
                    </button>
                </div>

                <div id="classQRResult" style="display: none;" class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-qrcode"></i> Generated QR Code</h2>
                    </div>
                    <div class="qr-display">
                        <img id="classQRImage" src="" alt="Class QR Code" class="qr-image">
                        <div class="qr-info">
                            <p id="classQRInfo"></p>
                            <p><i class="fas fa-info-circle"></i> Teachers scan this QR to mark their attendance for this class</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab 3: Attendance Report -->
            <div id="tab-attendance_report" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-chart-bar"></i> Attendance Report</h2>
                    </div>

                    <!-- Report Type Selection -->
                    <div class="form-group">
                        <label>Report Type</label>
                        <div class="duration-options" id="reportTypeOptions">
                            <div class="duration-btn active" data-type="daily">Daily</div>
                            <div class="duration-btn" data-type="weekly">Weekly</div>
                            <div class="duration-btn" data-type="monthly">Monthly</div>
                            <div class="duration-btn" data-type="custom">Custom</div>
                        </div>
                    </div>

                    <!-- User Type Selection -->
                    <div class="form-group">
                        <label>View</label>
                        <div class="duration-options" id="userTypeOptions">
                            <div class="duration-btn active" data-type="student">Students</div>
                            <div class="duration-btn" data-type="staff">Staff</div>
                        </div>
                    </div>

                    <!-- Date Filters -->
                    <div id="dailyFilter" class="form-group">
                        <label>Date</label>
                        <input type="date" id="reportDate" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div id="rangeFilter" style="display: none;">
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" id="startDate" class="form-control" value="<?php echo date('Y-m-d', strtotime('-7 days')); ?>">
                        </div>
                        <div class="form-group">
                            <label>End Date</label>
                            <input type="date" id="endDate" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>

                    <!-- Class Filter -->
                    <div class="form-group">
                        <label>Filter by Class (Optional)</label>
                        <select id="reportClassFilter" class="form-select">
                            <option value="">All Classes</option>
                            <?php foreach ($all_classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Staff Filter (shown when staff view is selected) -->
                    <div id="staffFilter" style="display: none;" class="form-group">
                        <label>Filter by Staff (Optional)</label>
                        <select id="reportStaffFilter" class="form-select">
                            <option value="">All Staff</option>
                            <?php foreach ($all_staff as $staff_member): ?>
                                <option value="<?php echo $staff_member['id']; ?>"><?php echo htmlspecialchars($staff_member['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button class="btn btn-primary btn-block" onclick="loadAttendanceReport()">
                        <i class="fas fa-chart-line"></i> Generate Report
                    </button>

                    <div style="margin-top: 12px; display: flex; gap: 10px;">
                        <button class="btn btn-secondary btn-block" onclick="exportReport('csv')">
                            <i class="fas fa-file-csv"></i> Export CSV
                        </button>
                        <button class="btn btn-secondary btn-block" onclick="exportReport('pdf')">
                            <i class="fas fa-file-pdf"></i> Export PDF
                        </button>
                    </div>
                </div>

                <!-- Report Results Container -->
                <div id="reportResults" style="display: none;">
                    <div id="reportSummary" class="report-summary"></div>
                    <div id="reportChart" class="chart-container"></div>
                    <div id="reportTable" class="card">
                        <div class="card-header">
                            <h2><i class="fas fa-table"></i> Detailed Report</h2>
                        </div>
                        <div class="table-container" id="reportTableBody"></div>
                    </div>
                </div>
            </div>

            <!-- Tab 4: Absent Alert -->
            <div id="tab-absent_alert" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-chart-bar"></i> Absentee Summary</h2>
                    </div>
                    <div class="form-group">
                        <label>Select Date</label>
                        <input type="date" id="absentDate" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <button class="btn btn-primary btn-block" onclick="loadAbsentStats()">
                        <i class="fas fa-chart-line"></i> Load Summary
                    </button>
                </div>

                <div id="absentStatsContainer" style="display: none;" class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-users"></i> Absent Students by Class</h2>
                    </div>
                    <div id="classStatsList" class="class-stats-list"></div>
                </div>
            </div>

            <!-- Tab 5: Email Settings -->
            <div id="tab-email_settings" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-envelope"></i> Email Notification Settings</h2>
                    </div>
                    <form id="emailSettingsForm">
                        <div class="form-group">
                            <label class="checkbox-item">
                                <input type="checkbox" name="email_notifications_enabled" value="1" <?php echo ($attendance_settings['email_notifications_enabled'] ?? 1) ? 'checked' : ''; ?>>
                                Enable Email Notifications
                            </label>
                        </div>
                        <div class="form-group">
                            <label>From Name</label>
                            <input type="text" name="email_from_name" class="form-control" value="<?php echo htmlspecialchars($attendance_settings['email_from_name'] ?? $school_name); ?>">
                        </div>
                        <div class="form-group">
                            <label>From Email</label>
                            <input type="email" name="email_from_address" class="form-control" value="<?php echo htmlspecialchars($attendance_settings['email_from_address'] ?? 'noreply@' . $_SERVER['HTTP_HOST']); ?>">
                        </div>
                        <div class="form-group">
                            <label>SMTP Host</label>
                            <input type="text" name="smtp_host" class="form-control" value="<?php echo htmlspecialchars($attendance_settings['smtp_host'] ?? ''); ?>" placeholder="smtp.gmail.com">
                        </div>
                        <div class="form-group">
                            <label>SMTP Port</label>
                            <input type="number" name="smtp_port" class="form-control" value="<?php echo htmlspecialchars($attendance_settings['smtp_port'] ?? 587); ?>">
                        </div>
                        <div class="form-group">
                            <label>SMTP Encryption</label>
                            <select name="smtp_encryption" class="form-select">
                                <option value="tls" <?php echo ($attendance_settings['smtp_encryption'] ?? '') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                                <option value="ssl" <?php echo ($attendance_settings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                <option value="none" <?php echo ($attendance_settings['smtp_encryption'] ?? '') === 'none' ? 'selected' : ''; ?>>None</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>SMTP Username</label>
                            <input type="text" name="smtp_username" class="form-control" value="<?php echo htmlspecialchars($attendance_settings['smtp_username'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>SMTP Password</label>
                            <input type="password" name="smtp_password" class="form-control" value="<?php echo htmlspecialchars($attendance_settings['smtp_password'] ?? ''); ?>">
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-save"></i> Save Settings
                        </button>
                    </form>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-flask"></i> Test Email</h2>
                    </div>
                    <div class="form-group">
                        <label>Test Email Address</label>
                        <input type="email" id="testEmail" class="form-control" placeholder="admin@school.com">
                    </div>
                    <button class="btn btn-success btn-block" onclick="testEmailConfig()">
                        <i class="fas fa-paper-plane"></i> Send Test Email
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Absent Students Modal -->
    <div class="modal" id="absentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="absentModalTitle">Absent Students</h3>
                <button class="close-modal" onclick="closeAbsentModal()">&times;</button>
            </div>
            <div class="modal-body" id="absentModalBody"></div>
        </div>
    </div>

    <!-- Sidebar -->
    <?php require_once 'includes/sidebar.php'; ?>

    <script>
        let html5QrScanner = null;
        let isScannerActive = false;
        let attendanceChart = null;
        let selectedDuration = 24;
        let currentReportData = null;

        // Tab switching
        function switchTab(tabName) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

            event.target.closest('.tab-btn').classList.add('active');
            document.getElementById(`tab-${tabName}`).classList.add('active');

            // If switching to reports tab, load initial report
            if (tabName === 'attendance_report') {
                loadAttendanceReport();
            }
        }

        // QR Duration Selection
        document.querySelectorAll('#qrDurationOptions .duration-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('#qrDurationOptions .duration-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                selectedDuration = parseInt(this.dataset.hours);
            });
        });

        // Report Type Selection
        document.querySelectorAll('#reportTypeOptions .duration-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('#reportTypeOptions .duration-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                const type = this.dataset.type;

                if (type === 'daily') {
                    document.getElementById('dailyFilter').style.display = 'block';
                    document.getElementById('rangeFilter').style.display = 'none';
                } else {
                    document.getElementById('dailyFilter').style.display = 'none';
                    document.getElementById('rangeFilter').style.display = 'block';
                }
            });
        });

        // User Type Selection
        document.querySelectorAll('#userTypeOptions .duration-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('#userTypeOptions .duration-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');

                const staffFilter = document.getElementById('staffFilter');
                if (this.dataset.type === 'staff') {
                    staffFilter.style.display = 'block';
                } else {
                    staffFilter.style.display = 'none';
                }
            });
        });

        // Regenerate School QR with Custom Duration
        function regenerateSchoolQRWithDuration() {
            const formData = new URLSearchParams();
            formData.append('action', 'regenerate_school_qr');
            formData.append('duration_hours', selectedDuration);

            showLoading('Generating QR code...');

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Failed to regenerate QR code');
                    }
                })
                .catch(() => {
                    hideLoading();
                    alert('Failed to regenerate QR code');
                });
        }

        // Original regenerate function (for compatibility)
        function regenerateSchoolQR() {
            regenerateSchoolQRWithDuration();
        }

        // Generate Class QR
        function generateClassQR() {
            const classId = document.getElementById('classSelect').value;
            const expiryHours = document.getElementById('qrExpiry').value;

            if (!classId) {
                alert('Please select a class');
                return;
            }

            const formData = new URLSearchParams();
            formData.append('action', 'generate_class_qr');
            formData.append('class_id', classId);
            if (expiryHours) formData.append('expiry_hours', expiryHours);

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.qr) {
                        document.getElementById('classQRImage').src = data.qr.qr_url;
                        const expiryText = data.qr.expires_at !== 'never' ? `Expires: ${data.qr.expires_at}` : 'Never expires';
                        document.getElementById('classQRInfo').innerHTML = `<strong>${data.qr.class_name}</strong><br>${expiryText}`;
                        document.getElementById('classQRResult').style.display = 'block';
                    } else {
                        alert('Failed to generate QR code');
                    }
                });
        }

        // Load Attendance Report
        function loadAttendanceReport() {
            const reportType = document.querySelector('#reportTypeOptions .duration-btn.active').dataset.type;
            const userType = document.querySelector('#userTypeOptions .duration-btn.active').dataset.type;
            const classId = document.getElementById('reportClassFilter').value;
            const staffId = document.getElementById('reportStaffFilter').value;

            let date = null;
            let startDate = null;
            let endDate = null;

            if (reportType === 'daily') {
                date = document.getElementById('reportDate').value;
            } else {
                startDate = document.getElementById('startDate').value;
                endDate = document.getElementById('endDate').value;
            }

            // If staff filter is specific, use staff report
            let url;
            if (userType === 'staff' && staffId) {
                url = `${window.location.href}?action=get_attendance_report&report_type=staff&start_date=${startDate}&end_date=${endDate}&staff_id=${staffId}&user_type=${userType}`;
            } else if (userType === 'student' && classId && reportType !== 'daily') {
                url = `${window.location.href}?action=get_attendance_report&report_type=${reportType}&start_date=${startDate}&end_date=${endDate}&class_id=${classId}&user_type=${userType}`;
            } else if (reportType === 'daily') {
                url = `${window.location.href}?action=get_attendance_report&report_type=daily&date=${date}&class_id=${classId}&user_type=${userType}`;
                if (userType === 'staff' && staffId) {
                    url = `${window.location.href}?action=get_attendance_report&report_type=staff&date=${date}&staff_id=${staffId}&user_type=${userType}`;
                }
            } else {
                url = `${window.location.href}?action=get_attendance_report&report_type=${reportType}&start_date=${startDate}&end_date=${endDate}&class_id=${classId}&user_type=${userType}`;
            }

            showLoading('Loading report...');

            fetch(url, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        currentReportData = data.report;
                        displayReport(data.report, reportType, userType);
                    } else {
                        alert('Failed to load report');
                    }
                })
                .catch(() => {
                    hideLoading();
                    alert('Failed to load report');
                });
        }

        function displayReport(report, reportType, userType) {
            const resultsDiv = document.getElementById('reportResults');
            const summaryDiv = document.getElementById('reportSummary');
            const chartDiv = document.getElementById('reportChart');
            const tableBody = document.getElementById('reportTableBody');

            resultsDiv.style.display = 'block';

            if (userType === 'student') {
                if (report.daily_breakdown) {
                    // Weekly/Monthly report with daily breakdown
                    const totalPresent = report.daily_breakdown.reduce((sum, d) => sum + d.present, 0);
                    const totalStudents = report.daily_breakdown[0]?.total || 0;
                    const avgAttendance = report.summary?.average_daily_attendance || 0;

                    summaryDiv.innerHTML = `
                        <div class="report-stat">
                            <div class="value">${totalStudents}</div>
                            <div class="label">Total Students</div>
                        </div>
                        <div class="report-stat">
                            <div class="value">${totalPresent}</div>
                            <div class="label">Total Present Days</div>
                        </div>
                        <div class="report-stat">
                            <div class="value">${avgAttendance}%</div>
                            <div class="label">Avg Attendance</div>
                        </div>
                        <div class="report-stat">
                            <div class="value">${report.daily_breakdown.length}</div>
                            <div class="label">School Days</div>
                        </div>
                    `;

                    // Create chart
                    const ctx = document.createElement('canvas');
                    chartDiv.innerHTML = '';
                    chartDiv.appendChild(ctx);

                    if (attendanceChart) attendanceChart.destroy();
                    attendanceChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: report.daily_breakdown.map(d => new Date(d.date).toLocaleDateString()),
                            datasets: [{
                                label: 'Attendance Percentage',
                                data: report.daily_breakdown.map(d => d.percentage),
                                borderColor: 'var(--primary-color)',
                                backgroundColor: 'rgba(var(--primary-color-rgb), 0.1)',
                                fill: true,
                                tension: 0.4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: true,
                            plugins: {
                                legend: {
                                    position: 'top'
                                },
                                tooltip: {
                                    callbacks: {
                                        label: (ctx) => `${ctx.raw}%`
                                    }
                                }
                            }
                        }
                    });

                    // Display table
                    tableBody.innerHTML = `
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Total Students</th>
                                    <th>Present</th>
                                    <th>Absent</th>
                                    <th>Percentage</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${report.daily_breakdown.map(d => `
                                    <tr>
                                        <td>${new Date(d.date).toLocaleDateString()}</td>
                                        <td>${d.total}</td>
                                        <td>${d.present}</td>
                                        <td>${d.absent}</td>
                                        <td>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: ${d.percentage}%"></div>
                                            </div>
                                            <small>${d.percentage}%</small>
                                        </td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    `;

                } else if (report.details && report.details.length > 0) {
                    // Daily report with student details
                    summaryDiv.innerHTML = `
                        <div class="report-stat">
                            <div class="value">${report.total_students || report.details.length}</div>
                            <div class="label">Total Students</div>
                        </div>
                        <div class="report-stat">
                            <div class="value">${report.total_present || 0}</div>
                            <div class="label">Present</div>
                        </div>
                        <div class="report-stat">
                            <div class="value">${report.attendance_percentage || 0}%</div>
                            <div class="label">Attendance Rate</div>
                        </div>
                        <div class="report-stat">
                            <div class="value">${report.details.length - (report.total_present || 0)}</div>
                            <div class="label">Absent</div>
                        </div>
                    `;

                    chartDiv.innerHTML = '';

                    tableBody.innerHTML = `
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Admission No</th>
                                    <th>Class</th>
                                    <th>Status</th>
                                    <th>Check-in Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${report.details.map(s => `
                                    <tr>
                                        <td>${escapeHtml(s.full_name)}</td>
                                        <td>${escapeHtml(s.admission_number)}</td>
                                        <td>${escapeHtml(s.class_name)}</td>
                                        <td>
                                            <span class="status-badge status-${s.attendance_status}">
                                                ${s.attendance_status.toUpperCase()}
                                            </span>
                                        </td>
                                        <td>${s.check_in_time || '-'}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    `;
                }
            } else if (userType === 'staff') {
                // Staff report
                const staffData = report.staff || {};
                summaryDiv.innerHTML = `
                    <div class="report-stat">
                        <div class="value">${staffData.full_name || 'All Staff'}</div>
                        <div class="label">Staff Member</div>
                    </div>
                    <div class="report-stat">
                        <div class="value">${staffData.days_present || report.details?.length || 0}</div>
                        <div class="label">Days Present</div>
                    </div>
                    <div class="report-stat">
                        <div class="value">${staffData.total_late_minutes || 0}</div>
                        <div class="label">Late Minutes</div>
                    </div>
                    <div class="report-stat">
                        <div class="value">${report.total_days || 0}</div>
                        <div class="label">Total Days</div>
                    </div>
                `;

                chartDiv.innerHTML = '';

                if (report.daily_logs && report.daily_logs.length > 0) {
                    tableBody.innerHTML = `
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Clock In</th>
                                    <th>Clock Out</th>
                                    <th>Status</th>
                                    <th>Late (mins)</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${report.daily_logs.map(log => `
                                    <tr>
                                        <td>${new Date(log.date).toLocaleDateString()}</td>
                                        <td>${log.clock_in_time || '-'}</td>
                                        <td>${log.clock_out_time || '-'}</td>
                                        <td>
                                            <span class="status-badge status-${log.status}">
                                                ${log.status.toUpperCase()}
                                            </span>
                                        </td>
                                        <td>${log.late_minutes || 0}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    `;
                } else if (report.details && report.details.length > 0) {
                    tableBody.innerHTML = `
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Staff Name</th>
                                    <th>Staff ID</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Clock In</th>
                                    <th>Clock Out</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${report.details.map(s => `
                                    <tr>
                                        <td>${escapeHtml(s.full_name)}</td>
                                        <td>${escapeHtml(s.staff_id)}</td>
                                        <td>${escapeHtml(s.role)}</td>
                                        <td>
                                            <span class="status-badge status-${s.attendance_status}">
                                                ${s.attendance_status.toUpperCase()}
                                            </span>
                                        </td>
                                        <td>${s.clock_in_time || '-'}</td>
                                        <td>${s.clock_out_time || '-'}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    `;
                }
            }
        }

        // Export Report
        function exportReport(format) {
            if (!currentReportData) {
                alert('Please generate a report first');
                return;
            }

            const reportType = document.querySelector('#reportTypeOptions .duration-btn.active').dataset.type;
            const userType = document.querySelector('#userTypeOptions .duration-btn.active').dataset.type;
            const classId = document.getElementById('reportClassFilter').value;

            let date = null;
            let startDate = null;
            let endDate = null;

            if (reportType === 'daily') {
                date = document.getElementById('reportDate').value;
            } else {
                startDate = document.getElementById('startDate').value;
                endDate = document.getElementById('endDate').value;
            }

            const formData = new URLSearchParams();
            formData.append('action', 'export_attendance_report');
            formData.append('report_type', reportType);
            formData.append('user_type', userType);
            formData.append('format', format);
            if (date) formData.append('date', date);
            if (startDate) formData.append('start_date', startDate);
            if (endDate) formData.append('end_date', endDate);
            if (classId) formData.append('class_id', classId);

            window.location.href = window.location.href + '?' + formData.toString();
        }

        // Load Absent Stats
        function loadAbsentStats() {
            const date = document.getElementById('absentDate').value;

            fetch(`${window.location.href}?action=get_absent_stats&date=${date}`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const container = document.getElementById('classStatsList');
                        const statsContainer = document.getElementById('absentStatsContainer');

                        if (data.stats.length === 0) {
                            container.innerHTML = '<p style="text-align:center; padding:20px;">No classes found</p>';
                        } else {
                            container.innerHTML = data.stats.map(stat => `
                            <div class="class-stat-item" onclick="showAbsentStudents(${stat.id}, '${escapeHtml(stat.class_name)}', '${date}')">
                                <div>
                                    <div class="class-name">${escapeHtml(stat.class_name)}</div>
                                    <small>Total: ${stat.total} students</small>
                                </div>
                                <div class="class-absent">${stat.absent} absent</div>
                            </div>
                        `).join('');
                        }

                        statsContainer.style.display = 'block';
                    }
                });
        }

        function showAbsentStudents(classId, className, date) {
            fetch(`${window.location.href}?action=get_absent_students&date=${date}&class_id=${classId}`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const modal = document.getElementById('absentModal');
                        const title = document.getElementById('absentModalTitle');
                        const body = document.getElementById('absentModalBody');

                        title.innerHTML = `${escapeHtml(className)} - Absent Students (${data.count})`;

                        if (data.students.length === 0) {
                            body.innerHTML = '<p style="text-align:center; padding:20px;">No absent students</p>';
                        } else {
                            body.innerHTML = `
                            <div class="table-container">
                                <table class="data-table">
                                    <thead>
                                        <tr><th>Name</th><th>Admission No</th><th>Parent Contact</th></tr>
                                    </thead>
                                    <tbody>
                                        ${data.students.map(s => `
                                            <tr>
                                                <td><strong>${escapeHtml(s.full_name)}</strong></td>
                                                <td>${escapeHtml(s.admission_number)}</td>
                                                <td>
                                                    ${s.parent_phone ? `<i class="fas fa-phone"></i> ${escapeHtml(s.parent_phone)}<br>` : ''}
                                                    ${s.parent_email ? `<i class="fas fa-envelope"></i> ${escapeHtml(s.parent_email)}` : ''}
                                                    ${!s.parent_phone && !s.parent_email ? '—' : ''}
                                                </td>
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

        function closeAbsentModal() {
            document.getElementById('absentModal').classList.remove('show');
        }

        // Email Settings
        document.getElementById('emailSettingsForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            formData.append('action', 'save_email_settings');

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Email settings saved successfully');
                    } else {
                        alert('Failed to save settings');
                    }
                });
        });

        function testEmailConfig() {
            const testEmail = document.getElementById('testEmail').value;
            if (!testEmail) {
                alert('Please enter a test email address');
                return;
            }

            const formData = new URLSearchParams();
            formData.append('action', 'test_email');
            formData.append('test_email', testEmail);

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Test email sent successfully! Check your inbox.');
                    } else {
                        alert('Failed to send test email: ' + (data.error || 'Unknown error'));
                    }
                });
        }

        // Notification functions
        function loadNotifications() {
            fetch(`${window.location.href}?action=get_notifications`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const list = document.getElementById('notificationList');
                        if (data.notifications.length === 0) {
                            list.innerHTML = '<div style="padding: 20px; text-align:center; color: var(--gray-500);">No notifications</div>';
                        } else {
                            list.innerHTML = data.notifications.map(n => `
                            <div class="notification-item ${n.is_read ? '' : 'unread'}" onclick="markNotificationRead(${n.id})">
                                <div class="notification-title">${escapeHtml(n.title)}</div>
                                <div class="notification-body">${escapeHtml(n.body)}</div>
                                <div class="notification-time">${n.time_ago}</div>
                            </div>
                        `).join('');
                        }
                    }
                });
        }

        function loadUnreadCount() {
            fetch(`${window.location.href}?action=get_unread_count`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const badge = document.getElementById('notificationCount');
                        badge.textContent = data.count;
                        badge.style.display = data.count > 0 ? 'inline-block' : 'none';
                    }
                });
        }

        function markNotificationRead(id) {
            const formData = new URLSearchParams();
            formData.append('action', 'mark_read');
            formData.append('notification_id', id);

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(() => {
                    loadNotifications();
                    loadUnreadCount();
                });
        }

        document.getElementById('markAllReadBtn').addEventListener('click', function() {
            const formData = new URLSearchParams();
            formData.append('action', 'mark_all_read');

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(() => {
                    loadNotifications();
                    loadUnreadCount();
                });
        });

        // Notification dropdown toggle
        const bell = document.getElementById('notificationBell');
        const dropdown = document.getElementById('notificationDropdown');

        bell.addEventListener('click', function(e) {
            e.stopPropagation();
            dropdown.classList.toggle('show');
            if (dropdown.classList.contains('show')) {
                loadNotifications();
            }
        });

        document.addEventListener('click', function() {
            dropdown.classList.remove('show');
        });

        dropdown.addEventListener('click', function(e) {
            e.stopPropagation();
        });

        // Loading indicator
        function showLoading(message) {
            let loader = document.getElementById('globalLoader');
            if (!loader) {
                loader = document.createElement('div');
                loader.id = 'globalLoader';
                loader.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center;flex-direction:column;color:white;';
                loader.innerHTML = '<div class="spinner"></div><div id="loaderMessage">Loading...</div>';
                document.body.appendChild(loader);
            }
            document.getElementById('loaderMessage').textContent = message;
            loader.style.display = 'flex';
        }

        function hideLoading() {
            const loader = document.getElementById('globalLoader');
            if (loader) loader.style.display = 'none';
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Auto-refresh unread count every 30 seconds
        loadUnreadCount();
        setInterval(loadUnreadCount, 30000);

        // Load initial report if on reports tab
        if (document.getElementById('tab-attendance_report').classList.contains('active')) {
            loadAttendanceReport();
        }
    </script>
</body>

</html>
<?php
// admin/manage_attendance_api.php — All AJAX handlers for the admin attendance panel
session_start();

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthenticated']);
    exit();
}

if (isset($_SESSION['admin_id'])) {
    $admin_id   = $_SESSION['admin_id'];
    $admin_role = $_SESSION['admin_role'] ?? 'super_admin';
} else {
    $admin_id   = $_SESSION['user_id'];
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

if ($admin_role !== 'super_admin' && $admin_role !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

require_once '../includes/config.php';

if (file_exists('../includes/notification_helper.php')) {
    require_once '../includes/notification_helper.php';
}

header('Content-Type: application/json');

$school_id = SCHOOL_ID;
$action    = $_GET['action'] ?? $_POST['action'] ?? '';
$input     = json_decode(file_get_contents('php://input'), true) ?? [];

// Merge JSON body into POST for convenience
if (!empty($input)) {
    $_POST = array_merge($_POST, $input);
    if (empty($action)) {
        $action = $input['action'] ?? '';
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: build safe IN() placeholders
// ─────────────────────────────────────────────────────────────────────────────
function placeholders(array $arr): string
{
    return implode(',', array_fill(0, count($arr), '?'));
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: safe int from request
// ─────────────────────────────────────────────────────────────────────────────
function intParam(string $key, int $default = 0): int
{
    return (int)($_POST[$key] ?? $_GET[$key] ?? $default);
}

function strParam(string $key, string $default = ''): string
{
    return trim($_POST[$key] ?? $_GET[$key] ?? $default);
}

// ─────────────────────────────────────────────────────────────────────────────
switch ($action) {

    // =========================================================================
    // SCHOOL QR
    // =========================================================================
    case 'regenerate_school_qr':
        $hours      = intParam('hours', 72);
        $never      = strParam('never') === 'true';
        $expires_at = $never ? null : date('Y-m-d H:i:s', strtotime("+{$hours} hours"));

        // Expire old codes
        $pdo->prepare("UPDATE school_qr_codes SET status='expired' WHERE school_id=? AND status='active'")
            ->execute([$school_id]);

        // Generate unique token
        $token = bin2hex(random_bytes(16));
        $qr_data = json_encode(['type' => 'school_attendance', 'school_id' => $school_id, 'token' => $token]);

        // Generate QR image via Google Charts (no server-side lib needed)
        $qr_url  = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($qr_data);
        $img_dir = $_SERVER['DOCUMENT_ROOT'] . '/gos/uploads/qrcodes/';
        if (!is_dir($img_dir)) mkdir($img_dir, 0777, true);

        $img_data = @file_get_contents($qr_url);
        $img_path = null;
        if ($img_data) {
            $filename = "school_qr_{$school_id}_" . time() . '.png';
            file_put_contents($img_dir . $filename, $img_data);
            $img_path = "/gos/uploads/qrcodes/{$filename}";
        }

        $stmt = $pdo->prepare("
            INSERT INTO school_qr_codes (school_id, token, qr_data, qr_image, status, expires_at, generated_at)
            VALUES (?, ?, ?, ?, 'active', ?, NOW())
        ");
        $stmt->execute([$school_id, $token, $qr_data, $img_path, $expires_at]);

        echo json_encode([
            'success'    => true,
            'qr_image'   => $img_path,
            'expires_at' => $expires_at,
            'message'    => 'QR code generated successfully'
        ]);
        break;

    // =========================================================================
    // CLASS QR
    // =========================================================================
    case 'generate_class_qr':
        $class_id = intParam('class_id');
        $expiry_h = intParam('expiry', 0);

        if (!$class_id) {
            echo json_encode(['success' => false, 'error' => 'Class ID required']);
            break;
        }

        $stmt = $pdo->prepare("SELECT class_name FROM classes WHERE id=? AND school_id=?");
        $stmt->execute([$class_id, $school_id]);
        $class = $stmt->fetch();
        if (!$class) {
            echo json_encode(['success' => false, 'error' => 'Class not found']);
            break;
        }

        $expires_at = $expiry_h > 0 ? date('Y-m-d H:i:s', strtotime("+{$expiry_h} hours")) : null;
        $token      = bin2hex(random_bytes(16));
        $qr_data    = json_encode(['type' => 'class_attendance', 'school_id' => $school_id, 'class_id' => $class_id, 'token' => $token]);

        $qr_url  = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($qr_data);
        $img_dir = $_SERVER['DOCUMENT_ROOT'] . '/gos/uploads/qrcodes/';
        if (!is_dir($img_dir)) mkdir($img_dir, 0777, true);

        $img_data = @file_get_contents($qr_url);
        $img_path = null;
        if ($img_data) {
            $filename = "class_qr_{$school_id}_{$class_id}_" . time() . '.png';
            file_put_contents($img_dir . $filename, $img_data);
            $img_path = "/gos/uploads/qrcodes/{$filename}";
        }

        $stmt = $pdo->prepare("
            INSERT INTO school_qr_codes (school_id, class_id, token, qr_data, qr_image, status, expires_at, generated_at)
            VALUES (?, ?, ?, ?, ?, 'active', ?, NOW())
        ");
        $stmt->execute([$school_id, $class_id, $token, $qr_data, $img_path, $expires_at]);

        echo json_encode([
            'success'    => true,
            'qr_image'   => $img_path,
            'class_name' => $class['class_name'],
            'expires_at' => $expires_at,
            'message'    => "Class QR for {$class['class_name']} generated"
        ]);
        break;

    // =========================================================================
    // ADMIN TAKE STUDENT ATTENDANCE — record single student
    // =========================================================================
    case 'admin_record_student_attendance':
        $student_id = intParam('student_id');
        $scan_type  = strParam('scan_type', 'check_in');
        $today      = date('Y-m-d');

        if (!$student_id) {
            echo json_encode(['success' => false, 'error' => 'Invalid student ID']);
            break;
        }

        // Validate student belongs to this school
        $stmt = $pdo->prepare("
            SELECT s.*, c.class_name
            FROM students s
            LEFT JOIN classes c ON s.class_id = c.id
            WHERE s.id=? AND s.school_id=? AND s.status='active'
        ");
        $stmt->execute([$student_id, $school_id]);
        $student = $stmt->fetch();

        if (!$student) {
            echo json_encode(['success' => false, 'error' => 'Student not found']);
            break;
        }

        if ($scan_type === 'check_in') {
            $stmt = $pdo->prepare("
                SELECT id FROM attendance_logs
                WHERE student_id=? AND school_id=? AND DATE(scan_time)=? AND scan_type='check_in'
            ");
            $stmt->execute([$student_id, $school_id, $today]);
            if ($stmt->fetch()) {
                echo json_encode([
                    'success'      => false,
                    'error'        => $student['full_name'] . ' is already checked in today',
                    'student_name' => $student['full_name']
                ]);
                break;
            }
        }

        $current_time = date('H:i:s');
        // Late threshold: after 09:00
        $late_threshold = strParam('late_threshold', '09:00:00');
        $status = ($scan_type === 'check_in' && $current_time > $late_threshold) ? 'late' : 'present';

        $stmt = $pdo->prepare("
            INSERT INTO attendance_logs (school_id, student_id, staff_id, scan_time, scan_type, status, ip_address, created_at)
            VALUES (?, ?, ?, NOW(), ?, ?, ?, NOW())
        ");
        $stmt->execute([$school_id, $student_id, $admin_id, $scan_type, $status, $_SERVER['REMOTE_ADDR']]);

        // Upsert main attendance table
        $stmt = $pdo->prepare("
            INSERT INTO attendance (school_id, student_id, date, status, created_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE status=VALUES(status), created_at=NOW()
        ");
        $stmt->execute([$school_id, $student_id, $today, $status]);

        // Notification
        if (function_exists('createAttendanceNotification')) {
            $notif_type = $scan_type === 'check_in'
                ? ($status === 'late' ? 'student_late' : 'student_clock_in')
                : 'student_clock_out';
            createAttendanceNotification(
                $pdo, $school_id, $notif_type,
                $admin_id, 'admin',
                $student_id, 'student',
                $student['full_name'], $student['class_name'],
                null, null, null,
                date('H:i:s'), $status,
                false, false
            );
        }

        echo json_encode([
            'success'      => true,
            'student_name' => $student['full_name'],
            'class_name'   => $student['class_name'],
            'status'       => $status,
            'scan_type'    => $scan_type,
            'message'      => $scan_type === 'check_in' ? 'Checked In' : 'Checked Out'
        ]);
        break;

    // =========================================================================
    // ADMIN — BULK MARK CLASS ATTENDANCE (manual checkbox list)
    // =========================================================================
    case 'admin_bulk_mark_attendance':
        $class_id    = intParam('class_id');
        $date        = strParam('date', date('Y-m-d'));
        $present_ids = $_POST['present_ids'] ?? $input['present_ids'] ?? [];
        $absent_ids  = $_POST['absent_ids']  ?? $input['absent_ids']  ?? [];

        if (!$class_id) {
            echo json_encode(['success' => false, 'error' => 'Class ID required']);
            break;
        }

        $marked = 0;
        $scan_time = $date . ' 08:00:00'; // default bulk mark time

        foreach ($present_ids as $sid) {
            $sid = (int)$sid;
            if (!$sid) continue;
            // Skip if already recorded
            $chk = $pdo->prepare("
                SELECT id FROM attendance_logs
                WHERE student_id=? AND school_id=? AND DATE(scan_time)=? AND scan_type='check_in'
            ");
            $chk->execute([$sid, $school_id, $date]);
            if ($chk->fetch()) continue;

            $pdo->prepare("
                INSERT INTO attendance_logs (school_id, student_id, staff_id, scan_time, scan_type, status, ip_address, created_at)
                VALUES (?, ?, ?, ?, 'check_in', 'present', ?, NOW())
            ")->execute([$school_id, $sid, $admin_id, $scan_time, $_SERVER['REMOTE_ADDR']]);

            $pdo->prepare("
                INSERT INTO attendance (school_id, student_id, date, status, created_at)
                VALUES (?, ?, ?, 'present', NOW())
                ON DUPLICATE KEY UPDATE status='present', created_at=NOW()
            ")->execute([$school_id, $sid, $date]);
            $marked++;
        }

        foreach ($absent_ids as $sid) {
            $sid = (int)$sid;
            if (!$sid) continue;
            $pdo->prepare("
                INSERT INTO attendance (school_id, student_id, date, status, created_at)
                VALUES (?, ?, ?, 'absent', NOW())
                ON DUPLICATE KEY UPDATE status='absent', created_at=NOW()
            ")->execute([$school_id, $sid, $date]);
            $marked++;
        }

        echo json_encode(['success' => true, 'marked' => $marked, 'message' => "Marked attendance for {$marked} student(s)"]);
        break;

    // =========================================================================
    // ADMIN — GET STUDENTS FOR A CLASS (with today's attendance status)
    // =========================================================================
    case 'admin_get_class_students':
        $class_id = intParam('class_id');
        $date     = strParam('date', date('Y-m-d'));

        if (!$class_id) {
            echo json_encode(['success' => false, 'error' => 'Class ID required']);
            break;
        }

        $stmt = $pdo->prepare("
            SELECT s.id, s.full_name, s.admission_number,
                   al.scan_type, al.status as log_status, al.scan_time,
                   CASE WHEN al.id IS NOT NULL THEN 'present' ELSE 'absent' END AS attendance_status
            FROM students s
            LEFT JOIN attendance_logs al
                ON s.id = al.student_id
                AND DATE(al.scan_time) = ?
                AND al.scan_type = 'check_in'
                AND al.school_id = ?
            WHERE s.class_id=? AND s.school_id=? AND s.status='active'
            ORDER BY s.full_name
        ");
        $stmt->execute([$date, $school_id, $class_id, $school_id]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Count summary
        $present = 0; $absent = 0; $late = 0;
        foreach ($students as $s) {
            if ($s['attendance_status'] === 'present') {
                $present++;
                if ($s['log_status'] === 'late') $late++;
            } else {
                $absent++;
            }
        }

        echo json_encode([
            'success'  => true,
            'students' => $students,
            'date'     => $date,
            'summary'  => ['total' => count($students), 'present' => $present, 'absent' => $absent, 'late' => $late]
        ]);
        break;

    // =========================================================================
    // ADMIN — TODAY'S STATS (school-wide)
    // =========================================================================
    case 'admin_today_stats':
        $today = date('Y-m-d');

        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM students WHERE school_id=? AND status='active'");
        $stmt->execute([$school_id]);
        $total = (int)$stmt->fetch()['total'];

        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT student_id) as cnt
            FROM attendance_logs
            WHERE school_id=? AND DATE(scan_time)=? AND scan_type='check_in'
        ");
        $stmt->execute([$school_id, $today]);
        $present = (int)$stmt->fetch()['cnt'];

        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT student_id) as cnt
            FROM attendance_logs
            WHERE school_id=? AND DATE(scan_time)=? AND scan_type='check_in' AND status='late'
        ");
        $stmt->execute([$school_id, $today]);
        $late = (int)$stmt->fetch()['cnt'];

        echo json_encode([
            'success' => true,
            'total'   => $total,
            'present' => $present,
            'absent'  => max(0, $total - $present),
            'late'    => $late
        ]);
        break;

    // =========================================================================
    // ADMIN — RECENT SCANS (school-wide)
    // =========================================================================
    case 'admin_recent_scans':
        $limit = min(50, intParam('limit', 20));
        $stmt = $pdo->prepare("
            SELECT al.id, al.scan_time, al.scan_type, al.status,
                   s.full_name, s.admission_number, c.class_name
            FROM attendance_logs al
            JOIN students s ON al.student_id = s.id
            LEFT JOIN classes c ON s.class_id = c.id
            WHERE al.school_id=?
            ORDER BY al.scan_time DESC
            LIMIT ?
        ");
        $stmt->execute([$school_id, $limit]);
        $scans = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($scans as &$sc) {
            $sc['time_formatted'] = date('h:i A', strtotime($sc['scan_time']));
            $sc['date_formatted'] = date('M j', strtotime($sc['scan_time']));
        }
        echo json_encode(['success' => true, 'scans' => $scans]);
        break;

    // =========================================================================
    // STAFF PERMISSIONS — LIST
    // =========================================================================
    case 'get_staff_permissions':
        $stmt = $pdo->prepare("
            SELECT ap.id, ap.staff_id, ap.can_take_attendance, ap.can_view_reports,
                   ap.assigned_classes, ap.created_at,
                   st.full_name, st.staff_id as staff_code, st.role
            FROM attendance_permissions ap
            JOIN staff st ON ap.staff_id = st.id
            WHERE ap.school_id=?
            ORDER BY st.full_name
        ");
        $stmt->execute([$school_id]);
        $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Resolve class names for display
        $all_classes_stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id=? AND status='active'");
        $all_classes_stmt->execute([$school_id]);
        $class_map = [];
        foreach ($all_classes_stmt->fetchAll() as $c) {
            $class_map[$c['id']] = $c['class_name'];
        }

        foreach ($permissions as &$p) {
            $ids = $p['assigned_classes'] ? explode(',', $p['assigned_classes']) : [];
            $p['assigned_class_names'] = empty($ids)
                ? 'All Classes'
                : implode(', ', array_filter(array_map(fn($id) => $class_map[trim($id)] ?? null, $ids)));
        }

        echo json_encode(['success' => true, 'permissions' => $permissions, 'class_map' => $class_map]);
        break;

    // =========================================================================
    // STAFF PERMISSIONS — SAVE / UPDATE
    // =========================================================================
    case 'save_staff_permissions':
        $staff_id           = intParam('staff_id');
        $can_take           = intParam('can_take_attendance', 1);
        $can_view           = intParam('can_view_reports', 1);
        $assigned_class_ids = $_POST['assigned_classes'] ?? $input['assigned_classes'] ?? [];

        if (!$staff_id) {
            echo json_encode(['success' => false, 'error' => 'Staff ID required']);
            break;
        }

        // Verify staff belongs to school
        $stmt = $pdo->prepare("SELECT id FROM staff WHERE id=? AND school_id=? AND is_active=1");
        $stmt->execute([$staff_id, $school_id]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Staff member not found']);
            break;
        }

        $assigned_str = empty($assigned_class_ids) ? null : implode(',', array_map('intval', $assigned_class_ids));

        // Upsert
        $stmt = $pdo->prepare("
            INSERT INTO attendance_permissions (school_id, staff_id, can_take_attendance, can_view_reports, assigned_classes)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                can_take_attendance = VALUES(can_take_attendance),
                can_view_reports    = VALUES(can_view_reports),
                assigned_classes    = VALUES(assigned_classes)
        ");
        $stmt->execute([$school_id, $staff_id, $can_take, $can_view, $assigned_str]);

        echo json_encode(['success' => true, 'message' => 'Permissions saved successfully']);
        break;

    // =========================================================================
    // STAFF PERMISSIONS — REVOKE (delete row)
    // =========================================================================
    case 'revoke_staff_permissions':
        $staff_id = intParam('staff_id');
        if (!$staff_id) {
            echo json_encode(['success' => false, 'error' => 'Staff ID required']);
            break;
        }
        $pdo->prepare("DELETE FROM attendance_permissions WHERE staff_id=? AND school_id=?")
            ->execute([$staff_id, $school_id]);
        echo json_encode(['success' => true, 'message' => 'Permissions revoked']);
        break;

    // =========================================================================
    // ATTENDANCE REPORTS
    // =========================================================================
    case 'get_attendance_report':
        $report_type = strParam('report_type', 'daily');   // daily|weekly|monthly
        $category    = strParam('category', 'student');    // student|staff_attendance|staff_class
        $date        = strParam('date', date('Y-m-d'));
        $start_date  = strParam('start_date', date('Y-m-d', strtotime('-7 days')));
        $end_date    = strParam('end_date', date('Y-m-d'));
        $class_id    = intParam('class_id', 0);
        $staff_id    = intParam('staff_id_filter', 0);

        if ($report_type === 'daily') {
            $start_date = $end_date = $date;
        }

        if ($category === 'student') {
            $params = [$school_id, $start_date, $end_date];
            $class_cond = '';
            if ($class_id) { $class_cond = ' AND s.class_id=?'; $params[] = $class_id; }

            $stmt = $pdo->prepare("
                SELECT s.full_name, s.admission_number, c.class_name,
                       al.scan_time, al.scan_type, al.status,
                       DATE(al.scan_time) as date
                FROM attendance_logs al
                JOIN students s ON al.student_id = s.id
                LEFT JOIN classes c ON s.class_id = c.id
                WHERE al.school_id=? AND DATE(al.scan_time) BETWEEN ? AND ?
                  AND al.scan_type='check_in'
                  {$class_cond}
                ORDER BY al.scan_time DESC
                LIMIT 500
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Summary counts
            $p_stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT al.student_id) as present,
                       SUM(CASE WHEN al.status='late' THEN 1 ELSE 0 END) as late
                FROM attendance_logs al
                JOIN students s ON al.student_id = s.id
                WHERE al.school_id=? AND DATE(al.scan_time) BETWEEN ? AND ?
                  AND al.scan_type='check_in'
                  {$class_cond}
            ");
            $p_stmt->execute($params);
            $counts = $p_stmt->fetch();

            $t_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM students WHERE school_id=? AND status='active'" . ($class_id ? ' AND class_id=?' : ''));
            $t_params = $class_id ? [$school_id, $class_id] : [$school_id];
            $t_stmt->execute($t_params);
            $total = (int)$t_stmt->fetch()['total'];

            foreach ($rows as &$r) {
                $r['time_formatted'] = date('h:i A', strtotime($r['scan_time']));
                $r['date_formatted'] = date('M j, Y', strtotime($r['date']));
            }

            echo json_encode([
                'success'  => true,
                'rows'     => $rows,
                'summary'  => [
                    'present' => (int)$counts['present'],
                    'late'    => (int)$counts['late'],
                    'absent'  => max(0, $total - (int)$counts['present']),
                    'total'   => $total
                ]
            ]);

        } elseif ($category === 'staff_attendance') {
            $params = [$school_id, $start_date, $end_date];
            $staff_cond = '';
            if ($staff_id) { $staff_cond = ' AND sa.staff_id=?'; $params[] = $staff_id; }

            $stmt = $pdo->prepare("
                SELECT st.full_name, st.staff_id as staff_code,
                       sa.date, sa.clock_in, sa.clock_out, sa.status, sa.attendance_source, sa.marked_by_name
                FROM staff_attendance sa
                JOIN staff st ON sa.staff_id = st.id
                WHERE sa.school_id=? AND sa.date BETWEEN ? AND ?
                  {$staff_cond}
                ORDER BY sa.date DESC, st.full_name
                LIMIT 500
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as &$r) {
                $r['clock_in_fmt']  = $r['clock_in']  ? date('h:i A', strtotime('1970-01-01 ' . $r['clock_in']))  : '—';
                $r['clock_out_fmt'] = $r['clock_out'] ? date('h:i A', strtotime('1970-01-01 ' . $r['clock_out'])) : '—';
                $r['date_fmt']      = date('M j, Y', strtotime($r['date']));
            }

            $present = array_filter($rows, fn($r) => in_array($r['status'], ['present', 'late']));
            echo json_encode([
                'success' => true,
                'rows'    => $rows,
                'summary' => [
                    'total'   => count($rows),
                    'present' => count($present),
                    'absent'  => count($rows) - count($present),
                    'late'    => count(array_filter($rows, fn($r) => $r['status'] === 'late'))
                ]
            ]);

        } else {
            echo json_encode(['success' => false, 'error' => 'Unknown report category']);
        }
        break;

    // =========================================================================
    // EXPORT CSV
    // =========================================================================
    case 'export_csv':
        // Re-run report then stream as CSV
        // Delegates to same logic — kept simple here
        $category   = strParam('category', 'student');
        $start_date = strParam('start_date', date('Y-m-d'));
        $end_date   = strParam('end_date', date('Y-m-d'));
        $class_id   = intParam('class_id', 0);

        if ($category === 'student') {
            $params = [$school_id, $start_date, $end_date];
            $cond   = $class_id ? ' AND s.class_id=?' : '';
            if ($class_id) $params[] = $class_id;
            $stmt = $pdo->prepare("
                SELECT s.admission_number, s.full_name, c.class_name,
                       DATE(al.scan_time) as date,
                       TIME_FORMAT(al.scan_time,'%h:%i %p') as time,
                       al.status
                FROM attendance_logs al
                JOIN students s ON al.student_id = s.id
                LEFT JOIN classes c ON s.class_id = c.id
                WHERE al.school_id=? AND DATE(al.scan_time) BETWEEN ? AND ?
                  AND al.scan_type='check_in' {$cond}
                ORDER BY al.scan_time DESC
                LIMIT 5000
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Override content type for CSV
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="attendance_' . $start_date . '_' . $end_date . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Admission No', 'Name', 'Class', 'Date', 'Time', 'Status']);
            foreach ($rows as $r) fputcsv($out, [$r['admission_number'], $r['full_name'], $r['class_name'], $r['date'], $r['time'], $r['status']]);
            fclose($out);
            exit();
        }
        echo json_encode(['success' => false, 'error' => 'Category not supported for CSV']);
        break;

    // =========================================================================
    // ABSENT STATS & STUDENTS
    // =========================================================================
    case 'get_absent_stats':
        $date = strParam('date', date('Y-m-d'));

        $stmt = $pdo->prepare("
            SELECT c.id, c.class_name,
                   COUNT(s.id) as total,
                   COUNT(al.id) as present_count
            FROM classes c
            JOIN students s ON s.class_id = c.id AND s.school_id=? AND s.status='active'
            LEFT JOIN attendance_logs al
                ON al.student_id = s.id AND DATE(al.scan_time)=? AND al.scan_type='check_in'
            WHERE c.school_id=? AND c.status='active'
            GROUP BY c.id
            ORDER BY c.class_name
        ");
        $stmt->execute([$school_id, $date, $school_id]);
        $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stats as &$st) {
            $st['absent'] = max(0, $st['total'] - $st['present_count']);
        }
        echo json_encode(['success' => true, 'stats' => $stats]);
        break;

    case 'get_absent_students':
        $class_id = intParam('class_id');
        $date     = strParam('date', date('Y-m-d'));

        $stmt = $pdo->prepare("
            SELECT s.id, s.full_name, s.admission_number, s.parent_phone, s.parent_email
            FROM students s
            WHERE s.class_id=? AND s.school_id=? AND s.status='active'
              AND s.id NOT IN (
                  SELECT student_id FROM attendance_logs
                  WHERE school_id=? AND DATE(scan_time)=? AND scan_type='check_in'
              )
            ORDER BY s.full_name
        ");
        $stmt->execute([$class_id, $school_id, $school_id, $date]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'students' => $students, 'count' => count($students)]);
        break;

    // =========================================================================
    // NOTIFICATIONS
    // =========================================================================
    case 'get_notifications':
        $stmt = $pdo->prepare("
            SELECT id, notification_type, trigger_user_name, trigger_user_class,
                   marked_by_name, scan_time, status, is_read, created_at
            FROM attendance_notifications
            WHERE school_id=? AND user_id=? AND user_type='admin'
            ORDER BY created_at DESC
            LIMIT 30
        ");
        $stmt->execute([$school_id, $admin_id]);
        $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($notifs as &$n) {
            // Build human-readable title/body
            $type = $n['notification_type'];
            $name = $n['trigger_user_name'];
            switch ($type) {
                case 'staff_clock_in':
                    $n['title'] = "{$name} clocked in";
                    $n['body']  = 'Staff attendance recorded';
                    break;
                case 'staff_clock_out':
                    $n['title'] = "{$name} clocked out";
                    $n['body']  = 'Staff clock-out recorded';
                    break;
                case 'friend_marked':
                    $n['title'] = "{$name} marked by {$n['marked_by_name']}";
                    $n['body']  = 'Friend attendance marking';
                    break;
                case 'student_clock_in':
                    $n['title'] = "{$name} checked in";
                    $n['body']  = $n['trigger_user_class'] ? "Class: {$n['trigger_user_class']}" : 'Student attendance recorded';
                    break;
                case 'student_late':
                    $n['title'] = "⚠️ {$name} arrived late";
                    $n['body']  = $n['trigger_user_class'] ?? '';
                    break;
                case 'student_absent':
                    $n['title'] = "{$name} is absent";
                    $n['body']  = $n['trigger_user_class'] ?? '';
                    break;
                default:
                    $n['title'] = ucfirst(str_replace('_', ' ', $type));
                    $n['body']  = $name;
            }
            $n['time_ago'] = timeAgo($n['created_at']);
        }

        $unread = array_sum(array_column($notifs, 'is_read') ? [] : array_map(fn($n) => (int)!$n['is_read'], $notifs));
        echo json_encode(['success' => true, 'notifications' => $notifs, 'unread' => $unread]);
        break;

    case 'get_unread_count':
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as cnt FROM attendance_notifications
            WHERE school_id=? AND user_id=? AND user_type='admin' AND is_read=0
        ");
        $stmt->execute([$school_id, $admin_id]);
        echo json_encode(['success' => true, 'count' => (int)$stmt->fetch()['cnt']]);
        break;

    case 'mark_read':
        $notif_id = intParam('notification_id');
        $pdo->prepare("UPDATE attendance_notifications SET is_read=1 WHERE id=? AND school_id=?")
            ->execute([$notif_id, $school_id]);
        echo json_encode(['success' => true]);
        break;

    case 'mark_all_read':
        $pdo->prepare("UPDATE attendance_notifications SET is_read=1 WHERE school_id=? AND user_id=? AND user_type='admin'")
            ->execute([$school_id, $admin_id]);
        echo json_encode(['success' => true]);
        break;

    // =========================================================================
    // EMAIL SETTINGS
    // =========================================================================
    case 'save_email_settings':
        $fields = [
            'email_notifications_enabled' => intParam('email_notifications_enabled', 0),
            'email_from_name'             => strParam('email_from_name'),
            'email_from_address'          => strParam('email_from_address'),
            'smtp_host'                   => strParam('smtp_host'),
            'smtp_port'                   => intParam('smtp_port', 587),
            'smtp_encryption'             => strParam('smtp_encryption', 'tls'),
            'smtp_username'               => strParam('smtp_username'),
        ];
        $password = strParam('smtp_password');

        $set_clauses = implode(', ', array_map(fn($k) => "{$k}=?", array_keys($fields)));
        $values = array_values($fields);

        if ($password) {
            $set_clauses .= ', smtp_password=?';
            $values[] = $password;
        }

        $values[] = $school_id;
        $pdo->prepare("UPDATE attendance_settings SET {$set_clauses} WHERE school_id=?")->execute($values);
        echo json_encode(['success' => true, 'message' => 'Settings saved']);
        break;

    case 'test_email':
        $test_email = strParam('test_email');
        if (!$test_email) {
            echo json_encode(['success' => false, 'error' => 'Email address required']);
            break;
        }
        // Basic connectivity test — requires PHPMailer or mail()
        $sent = @mail($test_email, 'Test Email from School Portal', 'This is a test email from your attendance system.');
        echo json_encode(['success' => $sent, 'message' => $sent ? 'Test email sent' : 'mail() returned false — check SMTP settings']);
        break;

    // =========================================================================
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Unknown action: {$action}"]);
}

// ─────────────────────────────────────────────────────────────────────────────
function timeAgo(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60)          return 'just now';
    if ($diff < 3600)        return floor($diff / 60) . 'm ago';
    if ($diff < 86400)       return floor($diff / 3600) . 'h ago';
    if ($diff < 604800)      return floor($diff / 86400) . 'd ago';
    return date('M j', strtotime($datetime));
}
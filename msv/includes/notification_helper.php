<?php
// includes/notification_helper.php - In-App & Push Notifications

require_once __DIR__ . '/push_helper.php';
require_once __DIR__ . '/email_helper.php';

/**
 * Create an attendance notification (in-app)
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @param string $notification_type staff_clock_in, staff_clock_out, student_clock_in, student_clock_out, student_late, friend_marked
 * @param int $user_id Who receives this notification (admin/staff ID)
 * @param string $user_type admin or staff
 * @param int $trigger_user_id Who triggered this (student/staff ID)
 * @param string $trigger_user_type student or staff
 * @param string $trigger_user_name Name of trigger user
 * @param string|null $trigger_user_class Class name (for students)
 * @param int|null $marked_by_id For friend_marked: who marked
 * @param string|null $marked_by_name For friend_marked: name of marker
 * @param string|null $proof_photo Photo proof path
 * @param string|null $scan_time Scan time
 * @param string|null $status present, late, absent
 * @param bool $send_push Whether to also send push notification
 * @param bool $send_email Whether to also send email (for parent notifications)
 * @return int|false Notification ID or false
 */
function createAttendanceNotification($pdo, $school_id, $notification_type, $user_id, $user_type, $trigger_user_id, $trigger_user_type, $trigger_user_name, $trigger_user_class = null, $marked_by_id = null, $marked_by_name = null, $proof_photo = null, $scan_time = null, $status = null, $send_push = true, $send_email = false)
{
    $scan_time = $scan_time ?: date('Y-m-d H:i:s');
    $status = $status ?: 'present';
    
    // Insert in-app notification
    $stmt = $pdo->prepare("
        INSERT INTO attendance_notifications (
            school_id, notification_type, user_id, user_type, 
            trigger_user_id, trigger_user_type, trigger_user_name, trigger_user_class,
            marked_by_id, marked_by_name, proof_photo, scan_time, status,
            is_read, is_push_sent, is_email_sent, created_at
        ) VALUES (
            ?, ?, ?, ?, 
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            0, 0, 0, NOW()
        )
    ");
    
    $success = $stmt->execute([
        $school_id, $notification_type, $user_id, $user_type,
        $trigger_user_id, $trigger_user_type, $trigger_user_name, $trigger_user_class,
        $marked_by_id, $marked_by_name, $proof_photo, $scan_time, $status
    ]);
    
    if (!$success) {
        return false;
    }
    
    $notification_id = $pdo->lastInsertId();
    
    // Send push notification if enabled
    if ($send_push && isPushEnabled($pdo, $school_id)) {
        $push_title = getNotificationTitle($notification_type, $trigger_user_name, $status);
        $push_body = getNotificationBody($notification_type, $trigger_user_name, $trigger_user_class, $status, $marked_by_name);
        $click_url = getNotificationClickUrl($notification_type);
        
        sendPushToUser($pdo, $school_id, $user_id, $user_type, $push_title, $push_body, null, $click_url);
        
        // Mark push as sent
        $stmt = $pdo->prepare("UPDATE attendance_notifications SET is_push_sent = 1 WHERE id = ?");
        $stmt->execute([$notification_id]);
    }
    
    return $notification_id;
}

/**
 * Get notification title based on type
 */
function getNotificationTitle($type, $name, $status = null)
{
    $titles = [
        'staff_clock_in' => "Staff Clock In",
        'staff_clock_out' => "Staff Clock Out",
        'student_clock_in' => "Student Check In",
        'student_clock_out' => "Student Check Out",
        'student_late' => "Student Late Arrival",
        'friend_marked' => "Friend Marked Attendance",
        'student_absent' => "Student Absent"
    ];
    
    $title = $titles[$type] ?? "Attendance Update";
    
    if ($status === 'late') {
        $title = "⚠️ " . $title;
    }
    
    return $title;
}

/**
 * Get notification body based on type
 */
function getNotificationBody($type, $name, $class = null, $status = null, $marked_by = null)
{
    switch ($type) {
        case 'staff_clock_in':
            return "{$name} clocked in at " . date('h:i A');
        case 'staff_clock_out':
            return "{$name} clocked out at " . date('h:i A');
        case 'student_clock_in':
            $status_text = $status === 'late' ? ' (Late)' : '';
            $class_text = $class ? " ({$class})" : "";
            return "{$name}{$class_text} checked in{$status_text} at " . date('h:i A');
        case 'student_clock_out':
            $class_text = $class ? " ({$class})" : "";
            return "{$name}{$class_text} checked out at " . date('h:i A');
        case 'student_late':
            $class_text = $class ? " ({$class})" : "";
            return "⚠️ {$name}{$class_text} arrived late at " . date('h:i A');
        case 'friend_marked':
            return "{$marked_by} marked attendance for {$name} with photo proof";
        case 'student_absent':
            $class_text = $class ? " ({$class})" : "";
            return "{$name}{$class_text} was absent on " . date('F j, Y');
        default:
            return "Attendance update for {$name}";
    }
}

/**
 * Get click URL for notification
 */
function getNotificationClickUrl($type)
{
    $urls = [
        'staff_clock_in' => '/msv/admin/manage_attendance.php?tab=staff',
        'staff_clock_out' => '/msv/admin/manage_attendance.php?tab=staff',
        'student_clock_in' => '/msv/admin/manage_attendance.php?tab=students',
        'student_clock_out' => '/msv/admin/manage_attendance.php?tab=students',
        'student_late' => '/msv/admin/manage_attendance.php?tab=students&filter=late',
        'friend_marked' => '/msv/admin/manage_attendance.php?tab=staff&filter=friends',
        'student_absent' => '/msv/admin/manage_attendance.php?tab=absent'
    ];
    
    return $urls[$type] ?? '/msv/admin/manage_attendance.php';
}

/**
 * Get unread notification count for a user
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @param int $user_id User ID
 * @param string $user_type admin or staff
 * @return int
 */
function getUnreadNotificationCount($pdo, $school_id, $user_id, $user_type)
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM attendance_notifications 
        WHERE school_id = ? AND user_id = ? AND user_type = ? AND is_read = 0
    ");
    $stmt->execute([$school_id, $user_id, $user_type]);
    $result = $stmt->fetch();
    
    return (int)$result['count'];
}

/**
 * Get notifications for a user
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @param int $user_id User ID
 * @param string $user_type admin or staff
 * @param int $limit Maximum notifications to return
 * @param int $offset Pagination offset
 * @return array
 */
function getUserNotifications($pdo, $school_id, $user_id, $user_type, $limit = 50, $offset = 0)
{
    $stmt = $pdo->prepare("
        SELECT * FROM attendance_notifications 
        WHERE school_id = ? AND user_id = ? AND user_type = ?
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$school_id, $user_id, $user_type, $limit, $offset]);
    $notifications = $stmt->fetchAll();
    
    // Format for display
    foreach ($notifications as &$n) {
        $n['time_ago'] = getTimeAgo($n['created_at']);
        $n['title'] = getNotificationTitle($n['notification_type'], $n['trigger_user_name'], $n['status']);
        $n['body'] = getNotificationBody($n['notification_type'], $n['trigger_user_name'], $n['trigger_user_class'], $n['status'], $n['marked_by_name']);
        $n['click_url'] = getNotificationClickUrl($n['notification_type']);
    }
    
    return $notifications;
}

/**
 * Mark notification as read
 * 
 * @param PDO $pdo Database connection
 * @param int $notification_id Notification ID
 * @param int $user_id User ID (for security)
 * @return bool
 */
function markNotificationRead($pdo, $notification_id, $user_id)
{
    $stmt = $pdo->prepare("
        UPDATE attendance_notifications 
        SET is_read = 1 
        WHERE id = ? AND user_id = ?
    ");
    return $stmt->execute([$notification_id, $user_id]);
}

/**
 * Mark all notifications as read for a user
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @param int $user_id User ID
 * @param string $user_type admin or staff
 * @return bool
 */
function markAllNotificationsRead($pdo, $school_id, $user_id, $user_type)
{
    $stmt = $pdo->prepare("
        UPDATE attendance_notifications 
        SET is_read = 1 
        WHERE school_id = ? AND user_id = ? AND user_type = ? AND is_read = 0
    ");
    return $stmt->execute([$school_id, $user_id, $user_type]);
}

/**
 * Delete old notifications (older than days)
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @param int $days Days to keep (default 30)
 * @return int Number of deleted notifications
 */
function deleteOldNotifications($pdo, $school_id, $days = 30)
{
    $stmt = $pdo->prepare("
        DELETE FROM attendance_notifications 
        WHERE school_id = ? AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute([$school_id, $days]);
    
    return $stmt->rowCount();
}

/**
 * Get time ago string
 */
function getTimeAgo($datetime)
{
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) {
        return $diff . ' seconds ago';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . ' minutes ago';
    } elseif ($diff < 86400) {
        return floor($diff / 3600) . ' hours ago';
    } elseif ($diff < 604800) {
        return floor($diff / 86400) . ' days ago';
    } else {
        return date('M j, Y', $timestamp);
    }
}

/**
 * Handle full attendance notification flow (create notification + email + push)
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @param array $data Notification data
 * @return bool
 */
function handleAttendanceNotification($pdo, $school_id, $data)
{
    // Create in-app notification for admin
    $admin_id = getAdminId($pdo, $school_id);
    
    createAttendanceNotification(
        $pdo, $school_id,
        $data['type'],
        $admin_id, 'admin',
        $data['user_id'], $data['user_type'],
        $data['user_name'],
        $data['user_class'] ?? null,
        $data['marked_by_id'] ?? null,
        $data['marked_by_name'] ?? null,
        $data['proof_photo'] ?? null,
        $data['scan_time'] ?? null,
        $data['status'] ?? null,
        true,  // send push
        false // don't send email for admin
    );
    
    // Send parent email if applicable
    if ($data['user_type'] === 'student' && isset($data['parent_email']) && $data['parent_email']) {
        sendParentAttendanceNotification(
            $pdo, $school_id,
            $data['user_id'],
            $data['user_name'],
            $data['admission_number'] ?? '',
            $data['user_class'] ?? '',
            $data['scan_type'] ?? 'check_in',
            $data['status'] ?? 'present',
            $data['scan_time'] ?? date('h:i A')
        );
    }
    
    return true;
}

/**
 * Get admin ID for a school (first active admin)
 */
function getAdminId($pdo, $school_id)
{
    $stmt = $pdo->prepare("
        SELECT id FROM admin_users 
        WHERE school_id = ? AND status = 'active' 
        ORDER BY id LIMIT 1
    ");
    $stmt->execute([$school_id]);
    $result = $stmt->fetch();
    
    return $result ? $result['id'] : 1;
}
<?php
// includes/email_helper.php - Email Notification System with Queue

/**
 * Queue an email for sending (async via cron or sync fallback)
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @param string $recipient_email Parent email address
 * @param string $recipient_name Recipient name
 * @param int|null $student_id Student ID (optional, for tracking)
 * @param string $subject Email subject
 * @param string $message Email body (HTML)
 * @param string $email_type Type of email (check_in, check_out, late, absent, daily_summary)
 * @return bool
 */
function queueEmail($pdo, $school_id, $recipient_email, $recipient_name, $student_id, $subject, $message, $email_type)
{
    // Validate email
    if (!filter_var($recipient_email, FILTER_VALIDATE_EMAIL)) {
        error_log("Invalid email address: $recipient_email");
        return false;
    }
    
    // Check if email notifications are enabled for this school
    $stmt = $pdo->prepare("SELECT email_notifications_enabled FROM attendance_settings WHERE school_id = ?");
    $stmt->execute([$school_id]);
    $settings = $stmt->fetch();
    
    if (!$settings || !$settings['email_notifications_enabled']) {
        return false;
    }
    
    // Queue the email
    $stmt = $pdo->prepare("
        INSERT INTO email_queue (school_id, recipient_email, recipient_name, student_id, subject, message, email_type, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    
    return $stmt->execute([
        $school_id,
        $recipient_email,
        $recipient_name,
        $student_id,
        $subject,
        $message,
        $email_type
    ]);
}

/**
 * Send email immediately (synchronous) - for fallback or critical emails
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @param string $to Recipient email
 * @param string $to_name Recipient name
 * @param string $subject Email subject
 * @param string $message HTML message
 * @return bool
 */
function sendEmailNow($pdo, $school_id, $to, $to_name, $subject, $message)
{
    // Get school SMTP settings
    $stmt = $pdo->prepare("SELECT * FROM attendance_settings WHERE school_id = ?");
    $stmt->execute([$school_id]);
    $settings = $stmt->fetch();
    
    if (!$settings || !$settings['email_notifications_enabled']) {
        return false;
    }
    
    $from_name = $settings['email_from_name'] ?? SCHOOL_NAME;
    $from_email = $settings['email_from_address'] ?? 'noreply@' . $_SERVER['HTTP_HOST'];
    
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=utf-8',
        "From: {$from_name} <{$from_email}>",
        "Reply-To: {$from_email}"
    ];
    
    return mail($to, $subject, $message, implode("\r\n", $headers));
}

/**
 * Process email queue (call via cron job or after attendance recording)
 * 
 * @param PDO $pdo Database connection
 * @param int $limit Maximum emails to send in one batch
 * @return array Stats about emails sent
 */
function processEmailQueue($pdo, $limit = 50)
{
    $stats = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
    
    // Get pending emails
    $stmt = $pdo->prepare("
        SELECT * FROM email_queue 
        WHERE status = 'pending' AND attempts < max_attempts
        ORDER BY created_at ASC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $emails = $stmt->fetchAll();
    
    foreach ($emails as $email) {
        // Get school settings
        $stmt = $pdo->prepare("SELECT * FROM attendance_settings WHERE school_id = ?");
        $stmt->execute([$email['school_id']]);
        $settings = $stmt->fetch();
        
        if (!$settings || !$settings['email_notifications_enabled']) {
            $stats['skipped']++;
            continue;
        }
        
        $from_name = $settings['email_from_name'] ?? SCHOOL_NAME;
        $from_email = $settings['email_from_address'] ?? 'noreply@' . $_SERVER['HTTP_HOST'];
        
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=utf-8',
            "From: {$from_name} <{$from_email}>",
            "Reply-To: {$from_email}"
        ];
        
        $success = mail($email['recipient_email'], $email['subject'], $email['message'], implode("\r\n", $headers));
        
        if ($success) {
            $stmt = $pdo->prepare("UPDATE email_queue SET status = 'sent', sent_at = NOW() WHERE id = ?");
            $stmt->execute([$email['id']]);
            $stats['sent']++;
        } else {
            $new_attempts = $email['attempts'] + 1;
            $new_status = $new_attempts >= $email['max_attempts'] ? 'failed' : 'pending';
            
            $stmt = $pdo->prepare("
                UPDATE email_queue 
                SET attempts = ?, status = ?, last_error = 'Mail sending failed' 
                WHERE id = ?
            ");
            $stmt->execute([$new_attempts, $new_status, $email['id']]);
            $stats['failed']++;
        }
    }
    
    return $stats;
}

/**
 * Send parent notification for student attendance
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @param int $student_id Student ID
 * @param string $student_name Student name
 * @param string $admission_number Admission number
 * @param string $class_name Class name
 * @param string $scan_type check_in or check_out
 * @param string $status present or late
 * @param string $scan_time Scan time
 * @return bool
 */
function sendParentAttendanceNotification($pdo, $school_id, $student_id, $student_name, $admission_number, $class_name, $scan_type, $status, $scan_time)
{
    // Get student's parent email
    $stmt = $pdo->prepare("
        SELECT parent_email, parent_phone, email_notifications_enabled 
        FROM students 
        WHERE id = ? AND school_id = ?
    ");
    $stmt->execute([$student_id, $school_id]);
    $student = $stmt->fetch();
    
    if (!$student || !$student['parent_email'] || !$student['email_notifications_enabled']) {
        return false;
    }
    
    // Get email template
    $template_type = ($status === 'late' && $scan_type === 'check_in') ? 'late' : $scan_type;
    $stmt = $pdo->prepare("
        SELECT subject_template, body_template 
        FROM email_templates 
        WHERE school_id = ? AND template_type = ? AND is_active = 1
    ");
    $stmt->execute([$school_id, $template_type]);
    $template = $stmt->fetch();
    
    if (!$template) {
        // Use default template
        $subject = "Attendance Notification: {$student_name} - " . ucfirst($scan_type);
        $message = buildDefaultEmailMessage($school_id, $student_name, $admission_number, $class_name, $scan_type, $status, $scan_time);
    } else {
        // Replace placeholders
        $subject = str_replace(
            ['{{SCHOOL_NAME}}', '{{STUDENT_NAME}}'],
            [SCHOOL_NAME, $student_name],
            $template['subject_template']
        );
        
        $message = str_replace(
            ['{{SCHOOL_NAME}}', '{{STUDENT_NAME}}', '{{ADMISSION_NUMBER}}', '{{CLASS_NAME}}', '{{SCAN_TIME}}', '{{DATE}}', '{{STATUS}}', '{{PRIMARY_COLOR}}'],
            [SCHOOL_NAME, $student_name, $admission_number, $class_name, $scan_time, date('F j, Y'), ucfirst($status), SCHOOL_PRIMARY],
            $template['body_template']
        );
    }
    
    // Queue the email
    return queueEmail($pdo, $school_id, $student['parent_email'], $student_name, $student_id, $subject, $message, $scan_type);
}

/**
 * Send parent notification for student absence
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @param int $student_id Student ID
 * @param string $student_name Student name
 * @param string $admission_number Admission number
 * @param string $class_name Class name
 * @param string $date Absent date
 * @return bool
 */
function sendParentAbsenceNotification($pdo, $school_id, $student_id, $student_name, $admission_number, $class_name, $date)
{
    // Get student's parent email
    $stmt = $pdo->prepare("
        SELECT parent_email, email_notifications_enabled 
        FROM students 
        WHERE id = ? AND school_id = ?
    ");
    $stmt->execute([$student_id, $school_id]);
    $student = $stmt->fetch();
    
    if (!$student || !$student['parent_email'] || !$student['email_notifications_enabled']) {
        return false;
    }
    
    // Get email template
    $stmt = $pdo->prepare("
        SELECT subject_template, body_template 
        FROM email_templates 
        WHERE school_id = ? AND template_type = 'absent' AND is_active = 1
    ");
    $stmt->execute([$school_id]);
    $template = $stmt->fetch();
    
    if ($template) {
        $subject = str_replace(
            ['{{SCHOOL_NAME}}', '{{STUDENT_NAME}}'],
            [SCHOOL_NAME, $student_name],
            $template['subject_template']
        );
        
        $message = str_replace(
            ['{{SCHOOL_NAME}}', '{{STUDENT_NAME}}', '{{ADMISSION_NUMBER}}', '{{CLASS_NAME}}', '{{DATE}}', '{{PRIMARY_COLOR}}'],
            [SCHOOL_NAME, $student_name, $admission_number, $class_name, date('F j, Y', strtotime($date)), SCHOOL_PRIMARY],
            $template['body_template']
        );
    } else {
        $subject = "Attendance Alert: {$student_name} Absent Today";
        $message = buildDefaultAbsenceMessage($school_id, $student_name, $admission_number, $class_name, $date);
    }
    
    return queueEmail($pdo, $school_id, $student['parent_email'], $student_name, $student_id, $subject, $message, 'absent');
}

/**
 * Build default email message when template not found
 */
function buildDefaultEmailMessage($school_id, $student_name, $admission_number, $class_name, $scan_type, $status, $scan_time)
{
    $status_text = $status === 'late' ? '⚠️ Late' : '✅ Present';
    $icon = $scan_type === 'check_in' ? '✅ Checked In' : '👋 Checked Out';
    $color = $status === 'late' ? '#e67e22' : '#27ae60';
    
    return "
    <div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;\">
        <h2 style=\"color: " . SCHOOL_PRIMARY . ";\">" . SCHOOL_NAME . "</h2>
        <hr style=\"border-color: " . SCHOOL_PRIMARY . ";\">
        <h3>Attendance Notification</h3>
        <p>Dear Parent/Guardian,</p>
        <p>Your child, <strong>{$student_name}</strong> (Admission No: {$admission_number}), has been marked:</p>
        <div style=\"background-color: #f5f5f5; padding: 15px; border-radius: 8px; margin: 15px 0;\">
            <p style=\"margin: 0;\"><strong>{$icon}</strong></p>
            <p style=\"margin: 5px 0 0; color: #666;\">Time: {$scan_time} | Date: " . date('F j, Y') . "</p>
            <p style=\"margin: 5px 0 0; color: {$color};\">Status: {$status_text}</p>
        </div>
        <p>Thank you,<br><strong>" . SCHOOL_NAME . " Administration</strong></p>
        <hr>
        <p style=\"font-size: 12px; color: #999;\">This is an automated message. Please do not reply.</p>
    </div>";
}

/**
 * Build default absence message
 */
function buildDefaultAbsenceMessage($school_id, $student_name, $admission_number, $class_name, $date)
{
    return "
    <div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;\">
        <h2 style=\"color: " . SCHOOL_PRIMARY . ";\">" . SCHOOL_NAME . "</h2>
        <hr style=\"border-color: " . SCHOOL_PRIMARY . ";\">
        <h3>❌ Absence Alert</h3>
        <p>Dear Parent/Guardian,</p>
        <p>Your child, <strong>{$student_name}</strong> (Admission No: {$admission_number}), has been marked <strong>ABSENT</strong> for " . date('F j, Y', strtotime($date)) . ".</p>
        <div style=\"background-color: #ffebee; padding: 15px; border-radius: 8px; margin: 15px 0;\">
            <p style=\"margin: 0;\"><strong>❌ Absent</strong></p>
            <p style=\"margin: 5px 0 0; color: #666;\">Date: " . date('F j, Y', strtotime($date)) . "</p>
        </div>
        <p>If your child was present, please contact the school administration to resolve this.</p>
        <p>Thank you,<br><strong>" . SCHOOL_NAME . " Administration</strong></p>
        <hr>
        <p style=\"font-size: 12px; color: #999;\">This is an automated message. Please do not reply.</p>
    </div>";
}

/**
 * Test email configuration
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @param string $test_email Email to send test to
 * @return array
 */
function testEmailConfiguration($pdo, $school_id, $test_email)
{
    $settings = $pdo->prepare("SELECT * FROM attendance_settings WHERE school_id = ?");
    $settings->execute([$school_id]);
    $config = $settings->fetch();
    
    if (!$config) {
        return ['success' => false, 'error' => 'Email settings not configured'];
    }
    
    $subject = "Test Email from " . SCHOOL_NAME . " Portal";
    $message = "
    <div style=\"font-family: Arial, sans-serif; padding: 20px;\">
        <h2 style=\"color: " . SCHOOL_PRIMARY . ";\">Test Email</h2>
        <p>This is a test email to confirm that the email notification system is working correctly.</p>
        <p>If you received this email, your configuration is correct.</p>
        <hr>
        <p style=\"font-size: 12px; color: #999;\">Sent from " . SCHOOL_NAME . " School Portal</p>
    </div>";
    
    $success = sendEmailNow($pdo, $school_id, $test_email, 'Admin', $subject, $message);
    
    // Update test status
    $stmt = $pdo->prepare("
        UPDATE attendance_settings 
        SET last_email_test_at = NOW(), email_test_status = ? 
        WHERE school_id = ?
    ");
    $stmt->execute([$success ? 'success' : 'failed', $school_id]);
    
    return [
        'success' => $success,
        'message' => $success ? 'Test email sent successfully' : 'Failed to send test email'
    ];
}
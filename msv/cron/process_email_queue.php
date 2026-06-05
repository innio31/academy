#!/usr/bin/php
<?php
/**
 * Email Queue Processor - Cron Job
 * Run this script every minute to process pending emails
 * 
 * Setup cron job:
 * * * * * * /usr/bin/php /path/to/gsa/cron/process_email_queue.php >> /dev/null 2>&1
 * 
 * Or for more verbose logging:
 * * * * * * /usr/bin/php /path/to/gsa/cron/process_email_queue.php >> /path/to/gsa/logs/email_queue.log 2>&1
 */

// Set execution time limit to avoid timeout
set_time_limit(300);

// Define log file path
define('LOG_FILE', __DIR__ . '/../logs/email_queue.log');

// Change to parent directory to access config
chdir(__DIR__ . '/..');

// Load configuration
require_once 'includes/config.php';

// Create logs directory if not exists
$log_dir = __DIR__ . '/../logs';
if (!file_exists($log_dir)) {
    mkdir($log_dir, 0777, true);
}

/**
 * Write to log file
 */
function writeLog($message, $type = 'INFO')
{
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] [$type] $message" . PHP_EOL;
    file_put_contents(LOG_FILE, $log_entry, FILE_APPEND);
    
    // Also output to console if running in CLI
    if (php_sapi_name() === 'cli') {
        echo $log_entry;
    }
}

/**
 * Send email using PHP mail() function
 */
function sendMail($to, $subject, $message, $from_name, $from_email)
{
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=utf-8',
        "From: {$from_name} <{$from_email}>",
        "Reply-To: {$from_email}",
        'X-Mailer: PHP/' . phpversion()
    ];
    
    return mail($to, $subject, $message, implode("\r\n", $headers));
}

/**
 * Send email using SMTP (if configured)
 */
function sendMailSMTP($to, $subject, $message, $smtp_config)
{
    // For now, fallback to mail() if SMTP not fully implemented
    // In production, you can implement PHPMailer or SwiftMailer here
    return sendMail($to, $subject, $message, $smtp_config['from_name'], $smtp_config['from_address']);
}

/**
 * Get school email settings
 */
function getSchoolEmailSettings($pdo, $school_id)
{
    $stmt = $pdo->prepare("
        SELECT email_notifications_enabled, email_from_name, email_from_address,
               smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password
        FROM attendance_settings 
        WHERE school_id = ?
    ");
    $stmt->execute([$school_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Process a single email from queue
 */
function processEmail($pdo, $email)
{
    writeLog("Processing email ID: {$email['id']} - To: {$email['recipient_email']} - Type: {$email['email_type']}");
    
    try {
        // Get school email settings
        $settings = getSchoolEmailSettings($pdo, $email['school_id']);
        
        if (!$settings || !$settings['email_notifications_enabled']) {
            writeLog("Email notifications disabled for school ID: {$email['school_id']}", 'WARNING');
            
            // Mark as failed since disabled
            $stmt = $pdo->prepare("UPDATE email_queue SET status = 'cancelled', last_error = 'Email notifications disabled' WHERE id = ?");
            $stmt->execute([$email['id']]);
            return false;
        }
        
        // Send email
        $from_name = $settings['email_from_name'] ?? SCHOOL_NAME ?? 'School Portal';
        $from_email = $settings['email_from_address'] ?? 'noreply@' . $_SERVER['HTTP_HOST'];
        
        $success = sendMail(
            $email['recipient_email'],
            $email['subject'],
            $email['message'],
            $from_name,
            $from_email
        );
        
        if ($success) {
            // Mark as sent
            $stmt = $pdo->prepare("
                UPDATE email_queue 
                SET status = 'sent', sent_at = NOW(), attempts = attempts + 1 
                WHERE id = ?
            ");
            $stmt->execute([$email['id']]);
            writeLog("Email ID {$email['id']} sent successfully");
            return true;
        } else {
            throw new Exception("Mail sending failed");
        }
        
    } catch (Exception $e) {
        $new_attempts = $email['attempts'] + 1;
        $max_attempts = $email['max_attempts'] ?? 3;
        $new_status = $new_attempts >= $max_attempts ? 'failed' : 'pending';
        
        $stmt = $pdo->prepare("
            UPDATE email_queue 
            SET attempts = ?, status = ?, last_error = ? 
            WHERE id = ?
        ");
        $stmt->execute([$new_attempts, $new_status, $e->getMessage(), $email['id']]);
        
        writeLog("Email ID {$email['id']} failed (attempt {$new_attempts}/{$max_attempts}): " . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Clean old sent/failed emails (keep last 30 days)
 */
function cleanOldEmails($pdo, $days = 30)
{
    $stmt = $pdo->prepare("
        DELETE FROM email_queue 
        WHERE status IN ('sent', 'failed') 
        AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute([$days]);
    $deleted = $stmt->rowCount();
    
    if ($deleted > 0) {
        writeLog("Cleaned up {$deleted} old emails (older than {$days} days)");
    }
    
    return $deleted;
}

/**
 * Get queue statistics
 */
function getQueueStats($pdo)
{
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
        FROM email_queue
    ");
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Send daily summary emails to parents (optional feature)
 */
function sendDailySummaries($pdo)
{
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    // Get all students with parent email who had attendance yesterday
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.id, s.full_name, s.admission_number, s.parent_email, c.class_name,
               al.status, al.scan_time
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN attendance_logs al ON s.id = al.student_id AND DATE(al.scan_time) = ?
        WHERE s.school_id = ? AND s.parent_email IS NOT NULL 
        AND s.parent_email != '' AND s.email_notifications_enabled = 1
    ");
    $stmt->execute([$yesterday, SCHOOL_ID]);
    $students = $stmt->fetchAll();
    
    $sent = 0;
    foreach ($students as $student) {
        $status_text = $student['status'] === 'late' ? 'Late' : ($student['status'] === 'present' ? 'Present' : 'Absent');
        $color = $student['status'] === 'late' ? '#e67e22' : ($student['status'] === 'present' ? '#27ae60' : '#e74c3c');
        
        $subject = SCHOOL_NAME . " - Daily Attendance Summary for " . date('F j, Y', strtotime($yesterday));
        $message = "
        <div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;\">
            <h2 style=\"color: " . SCHOOL_PRIMARY . ";\">" . SCHOOL_NAME . "</h2>
            <hr style=\"border-color: " . SCHOOL_PRIMARY . ";\">
            <h3>Daily Attendance Summary</h3>
            <p>Dear Parent/Guardian,</p>
            <p>Here is your child's attendance summary for <strong>" . date('F j, Y', strtotime($yesterday)) . "</strong>:</p>
            <div style=\"background-color: #f5f5f5; padding: 15px; border-radius: 8px; margin: 15px 0;\">
                <p><strong>Student:</strong> {$student['full_name']} ({$student['admission_number']})</p>
                <p><strong>Class:</strong> {$student['class_name']}</p>
                <p><strong>Status:</strong> <span style=\"color: {$color}; font-weight: bold;\">{$status_text}</span></p>
                " . ($student['scan_time'] ? "<p><strong>Time:</strong> " . date('g:i A', strtotime($student['scan_time'])) . "</p>" : "") . "
            </div>
            <p>Thank you,<br><strong>" . SCHOOL_NAME . " Administration</strong></p>
            <hr>
            <p style=\"font-size: 12px; color: #999;\">This is an automated message. Please do not reply.</p>
        </div>";
        
        // Queue the email (don't send immediately to avoid blocking)
        $stmt2 = $pdo->prepare("
            INSERT INTO email_queue (school_id, recipient_email, recipient_name, student_id, subject, message, email_type, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'daily_summary', NOW())
        ");
        $stmt2->execute([SCHOOL_ID, $student['parent_email'], $student['full_name'], $student['id'], $subject, $message]);
        $sent++;
    }
    
    if ($sent > 0) {
        writeLog("Queued {$sent} daily summary emails for " . date('Y-m-d', strtotime($yesterday)));
    }
    
    return $sent;
}

// ============================================================
// MAIN EXECUTION
// ============================================================

writeLog("========== Email Queue Processor Started ==========");

try {
    // Get database connection
    global $pdo;
    
    if (!isset($pdo)) {
        throw new Exception("Database connection not available");
    }
    
    // Process pending emails (limit 50 per batch to avoid memory issues)
    $stmt = $pdo->prepare("
        SELECT * FROM email_queue 
        WHERE status = 'pending' AND attempts < max_attempts
        ORDER BY created_at ASC
        LIMIT 50
    ");
    $stmt->execute();
    $emails = $stmt->fetchAll();
    
    $processed = 0;
    $success = 0;
    $failed = 0;
    
    foreach ($emails as $email) {
        $result = processEmail($pdo, $email);
        $processed++;
        if ($result) {
            $success++;
        } else {
            $failed++;
        }
        
        // Small delay to avoid overwhelming mail server
        usleep(100000); // 0.1 seconds
    }
    
    // Clean old emails (older than 30 days)
    $cleaned = cleanOldEmails($pdo, 30);
    
    // Get queue statistics
    $stats = getQueueStats($pdo);
    
    writeLog("Summary: Processed: {$processed}, Sent: {$success}, Failed: {$failed}, Cleaned: {$cleaned}");
    writeLog("Queue Status - Total: {$stats['total']}, Pending: {$stats['pending']}, Sent: {$stats['sent']}, Failed: {$stats['failed']}");
    
    // Optional: Send daily summaries at midnight (commented by default)
    // $current_hour = date('H');
    // if ($current_hour == 0) { // Run at midnight
    //     $summaries_sent = sendDailySummaries($pdo);
    //     writeLog("Daily summaries queued: {$summaries_sent}");
    // }
    
} catch (Exception $e) {
    writeLog("CRITICAL ERROR: " . $e->getMessage(), 'ERROR');
}

writeLog("========== Email Queue Processor Finished ==========");
writeLog("");
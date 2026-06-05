<?php
// includes/push_helper.php - Web Push Notifications using Web Push API

// Note: This requires the web-push-php library
// Install via composer: composer require minishlink/web-push

require_once __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/**
 * Initialize WebPush with VAPID keys from database
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @return WebPush|null
 */
function initWebPush($pdo, $school_id)
{
    $stmt = $pdo->prepare("SELECT vapid_public_key, vapid_private_key FROM attendance_settings WHERE school_id = ?");
    $stmt->execute([$school_id]);
    $settings = $stmt->fetch();
    
    if (!$settings || !$settings['vapid_public_key'] || !$settings['vapid_private_key']) {
        return null;
    }
    
    $auth = [
        'VAPID' => [
            'subject' => 'mailto:noreply@' . $_SERVER['HTTP_HOST'],
            'publicKey' => $settings['vapid_public_key'],
            'privateKey' => $settings['vapid_private_key']
        ]
    ];
    
    return new WebPush($auth);
}

/**
 * Generate VAPID keys (run once during setup)
 * 
 * @return array {publicKey, privateKey}
 */
function generateVAPIDKeys()
{
    // Use the web-push-php library to generate keys
    // This requires the library to be installed
    if (class_exists('Minishlink\WebPush\VAPID')) {
        return \Minishlink\WebPush\VAPID::createVapidKeys();
    }
    
    // Fallback: manual generation (less secure but works)
    $publicKey = base64_encode(random_bytes(32));
    $privateKey = base64_encode(random_bytes(32));
    
    return [
        'publicKey' => $publicKey,
        'privateKey' => $privateKey
    ];
}

/**
 * Save push subscription for a user
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @param int $user_id User ID (admin or staff)
 * @param string $user_type admin or staff
 * @param object $subscription Subscription object from browser
 * @return bool
 */
function savePushSubscription($pdo, $school_id, $user_id, $user_type, $subscription)
{
    // Check if subscription already exists
    $stmt = $pdo->prepare("
        SELECT id FROM push_subscriptions 
        WHERE user_id = ? AND user_type = ? AND endpoint = ?
    ");
    $stmt->execute([$user_id, $user_type, $subscription->endpoint]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Update existing
        $stmt = $pdo->prepare("
            UPDATE push_subscriptions 
            SET keys_auth = ?, keys_p256dh = ?, is_active = 1, updated_at = NOW()
            WHERE id = ?
        ");
        return $stmt->execute([
            $subscription->keys->auth,
            $subscription->keys->p256dh,
            $existing['id']
        ]);
    } else {
        // Insert new
        $stmt = $pdo->prepare("
            INSERT INTO push_subscriptions (school_id, user_id, user_type, endpoint, keys_auth, keys_p256dh, user_agent, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([
            $school_id,
            $user_id,
            $user_type,
            $subscription->endpoint,
            $subscription->keys->auth,
            $subscription->keys->p256dh,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    }
}

/**
 * Remove push subscription (when user unsubscribes)
 * 
 * @param PDO $pdo Database connection
 * @param string $endpoint Push endpoint
 * @return bool
 */
function removePushSubscription($pdo, $endpoint)
{
    $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
    return $stmt->execute([$endpoint]);
}

/**
 * Send push notification to a specific user
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @param int $user_id User ID
 * @param string $user_type admin or staff
 * @param string $title Notification title
 * @param string $body Notification body
 * @param string $icon Icon URL (optional)
 * @param string $click_url URL to open when clicked (optional)
 * @return array Results
 */
function sendPushToUser($pdo, $school_id, $user_id, $user_type, $title, $body, $icon = null, $click_url = null)
{
    // Get user's push subscriptions
    $stmt = $pdo->prepare("
        SELECT * FROM push_subscriptions 
        WHERE school_id = ? AND user_id = ? AND user_type = ? AND is_active = 1
    ");
    $stmt->execute([$school_id, $user_id, $user_type]);
    $subscriptions = $stmt->fetchAll();
    
    if (empty($subscriptions)) {
        return ['sent' => 0, 'failed' => 0];
    }
    
    $webPush = initWebPush($pdo, $school_id);
    if (!$webPush) {
        return ['sent' => 0, 'failed' => 0, 'error' => 'WebPush not initialized'];
    }
    
    $payload = json_encode([
        'title' => $title,
        'body' => $body,
        'icon' => $icon ?? '/msv/assets/logo.png',
        'badge' => '/msv/assets/badge.png',
        'data' => [
            'url' => $click_url ?? '/msv/',
            'timestamp' => time()
        ]
    ]);
    
    $results = ['sent' => 0, 'failed' => 0];
    
    foreach ($subscriptions as $sub) {
        $subscription = Subscription::create([
            'endpoint' => $sub['endpoint'],
            'authToken' => $sub['keys_auth'],
            'contentEncoding' => 'aesgcm',
            'publicKey' => $sub['keys_p256dh']
        ]);
        
        $webPush->queueNotification($subscription, $payload);
    }
    
    // Send all notifications
    foreach ($webPush->flush() as $report) {
        if ($report->isSuccess()) {
            $results['sent']++;
        } else {
            $results['failed']++;
            // If endpoint is expired, remove it
            if ($report->isSubscriptionExpired()) {
                removePushSubscription($pdo, $report->getEndpoint());
            }
        }
    }
    
    return $results;
}

/**
 * Send push notification to all admins of a school
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @param string $title Notification title
 * @param string $body Notification body
 * @param string $icon Icon URL
 * @param string $click_url URL to open
 * @return array
 */
function sendPushToAllAdmins($pdo, $school_id, $title, $body, $icon = null, $click_url = null)
{
    // Get all admin users
    $stmt = $pdo->prepare("SELECT id FROM admin_users WHERE school_id = ? AND status = 'active'");
    $stmt->execute([$school_id]);
    $admins = $stmt->fetchAll();
    
    $results = ['sent' => 0, 'failed' => 0];
    
    foreach ($admins as $admin) {
        $result = sendPushToUser($pdo, $school_id, $admin['id'], 'admin', $title, $body, $icon, $click_url);
        $results['sent'] += $result['sent'];
        $results['failed'] += $result['failed'];
    }
    
    return $results;
}

/**
 * Send push notification to all staff of a school
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @param string $title Notification title
 * @param string $body Notification body
 * @param string $icon Icon URL
 * @param string $click_url URL to open
 * @return array
 */
function sendPushToAllStaff($pdo, $school_id, $title, $body, $icon = null, $click_url = null)
{
    // Get all staff users
    $stmt = $pdo->prepare("SELECT id FROM staff WHERE school_id = ? AND is_active = 1");
    $stmt->execute([$school_id]);
    $staff = $stmt->fetchAll();
    
    $results = ['sent' => 0, 'failed' => 0];
    
    foreach ($staff as $staff_member) {
        $result = sendPushToUser($pdo, $school_id, $staff_member['id'], 'staff', $title, $body, $icon, $click_url);
        $results['sent'] += $result['sent'];
        $results['failed'] += $result['failed'];
    }
    
    return $results;
}

/**
 * Save VAPID keys to database
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @return bool
 */
function saveVAPIDKeys($pdo, $school_id)
{
    $keys = generateVAPIDKeys();
    
    $stmt = $pdo->prepare("
        UPDATE attendance_settings 
        SET vapid_public_key = ?, vapid_private_key = ?, push_notifications_enabled = 1
        WHERE school_id = ?
    ");
    
    return $stmt->execute([$keys['publicKey'], $keys['privateKey'], $school_id]);
}

/**
 * Check if push notifications are enabled for a school
 * 
 * @param PDO $pdo Database connection
 * @param int $school_id School ID
 * @return bool
 */
function isPushEnabled($pdo, $school_id)
{
    $stmt = $pdo->prepare("SELECT push_notifications_enabled FROM attendance_settings WHERE school_id = ?");
    $stmt->execute([$school_id]);
    $result = $stmt->fetch();
    
    return $result && $result['push_notifications_enabled'];
}
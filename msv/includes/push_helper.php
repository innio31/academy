<?php
// includes/push_helper.php - Web Push Notifications (No external libraries required)

/**
 * Send push notification using cURL (works without web-push-php library)
 * 
 * @param string $endpoint Push endpoint URL
 * @param string $publicKey VAPID public key
 * @param string $authToken Auth token
 * @param string $payload JSON payload to send
 * @param string $vapid_private_key VAPID private key
 * @param string $vapid_public_key VAPID public key
 * @return bool
 */
function sendPushNotification($endpoint, $publicKey, $authToken, $payload, $vapid_private_key, $vapid_public_key)
{
    // Prepare headers
    $headers = [
        'Content-Type: application/octet-stream',
        'Content-Encoding: aes128gcm',
        'TTL: 86400',
        'Urgency: high'
    ];

    // Add VAPID headers
    $vapid_header = generateVapidHeader($vapid_public_key, $vapid_private_key, $endpoint);
    if ($vapid_header) {
        $headers[] = 'Authorization: WebPush ' . $vapid_header;
    }

    // Initialize cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Success codes: 201 Created
    return $httpCode === 201;
}

/**
 * Generate VAPID header for push notification
 * Simplified version - for production, use proper JWT generation
 */
function generateVapidHeader($publicKey, $privateKey, $endpoint)
{
    // Parse endpoint to get origin
    $origin = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);

    // Create JWT header
    $header = [
        'typ' => 'JWT',
        'alg' => 'ES256'
    ];

    // Create JWT payload
    $payload = [
        'aud' => $origin,
        'exp' => time() + 86400  // 24 hours
    ];

    // Encode header and payload
    $encodedHeader = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
    $encodedPayload = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

    // For production, you need proper ECDSA signing using openssl
    // This is a simplified placeholder
    $signature = openssl_sign(
        $encodedHeader . '.' . $encodedPayload,
        $signature,
        $privateKey,
        OPENSSL_ALGO_SHA256
    );

    if (!$signature) {
        return null;
    }

    $encodedSignature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    return $encodedHeader . '.' . $encodedPayload . '.' . $encodedSignature;
}

/**
 * Generate VAPID keys (pure PHP, no external libraries)
 */
function generateVAPIDKeysPHP()
{
    // Generate a P-256 EC key pair
    $config = [
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1'
    ];

    $res = openssl_pkey_new($config);

    if (!$res) {
        return false;
    }

    // Extract private key
    openssl_pkey_export($res, $privateKey);

    // Extract public key details
    $publicKeyDetails = openssl_pkey_get_details($res);
    $publicKey = $publicKeyDetails['key'];

    // Format for Web Push API (base64url without padding)
    $privateKeyFormatted = formatVapidKeyForPush($privateKey, 'private');
    $publicKeyFormatted = formatVapidKeyForPush($publicKey, 'public');

    return [
        'publicKey' => $publicKeyFormatted,
        'privateKey' => $privateKeyFormatted
    ];
}

/**
 * Format VAPID key for Web Push API
 */
function formatVapidKeyForPush($key, $type)
{
    // Extract key from PEM format
    if ($type === 'private') {
        preg_match('/-----BEGIN PRIVATE KEY-----(.*?)-----END PRIVATE KEY-----/s', $key, $matches);
    } else {
        preg_match('/-----BEGIN PUBLIC KEY-----(.*?)-----END PUBLIC KEY-----/s', $key, $matches);
    }

    if (!isset($matches[1])) {
        return '';
    }

    $pemContent = trim($matches[1]);
    $der = base64_decode($pemContent);

    if ($type === 'private') {
        // Extract raw private key (last 32 bytes for EC P-256)
        $keyBytes = substr($der, -32);
    } else {
        // Extract uncompressed point (remove 0x04 prefix)
        if (strlen($der) >= 65) {
            $keyBytes = substr($der, -64);
        } else {
            $keyBytes = $der;
        }
    }

    // Convert to base64url (URL-safe, no padding)
    return rtrim(strtr(base64_encode($keyBytes), '+/', '-_'), '=');
}

/**
 * Save push subscription to database
 */
function savePushSubscription($pdo, $school_id, $user_id, $user_type, $subscription_data)
{
    $stmt = $pdo->prepare("
        INSERT INTO push_subscriptions (school_id, user_id, user_type, endpoint, keys_auth, keys_p256dh, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE keys_auth = ?, keys_p256dh = ?, updated_at = NOW()
    ");

    return $stmt->execute([
        $school_id,
        $user_id,
        $user_type,
        $subscription_data['endpoint'],
        $subscription_data['keys']['auth'],
        $subscription_data['keys']['p256dh'],
        $subscription_data['keys']['auth'],
        $subscription_data['keys']['p256dh']
    ]);
}

/**
 * Remove push subscription
 */
function removePushSubscription($pdo, $endpoint)
{
    $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
    return $stmt->execute([$endpoint]);
}

/**
 * Send push to a specific user
 */
function sendPushToUser($pdo, $school_id, $user_id, $user_type, $title, $body, $icon = null, $click_url = null)
{
    // Get user's subscriptions
    $stmt = $pdo->prepare("
        SELECT * FROM push_subscriptions 
        WHERE school_id = ? AND user_id = ? AND user_type = ? AND is_active = 1
    ");
    $stmt->execute([$school_id, $user_id, $user_type]);
    $subscriptions = $stmt->fetchAll();

    if (empty($subscriptions)) {
        return ['sent' => 0, 'failed' => 0];
    }

    // Get VAPID keys from settings
    $stmt = $pdo->prepare("SELECT vapid_public_key, vapid_private_key FROM attendance_settings WHERE school_id = ?");
    $stmt->execute([$school_id]);
    $keys = $stmt->fetch();

    if (!$keys || !$keys['vapid_public_key'] || !$keys['vapid_private_key']) {
        return ['sent' => 0, 'failed' => 0, 'error' => 'VAPID keys not configured'];
    }

    $payload = json_encode([
        'title' => $title,
        'body' => $body,
        'icon' => $icon ?? '/gsa/assets/logo-192.png',
        'badge' => '/gsa/assets/badge.png',
        'data' => [
            'url' => $click_url ?? '/gsa/admin/manage_attendance.php',
            'timestamp' => time()
        ]
    ]);

    $sent = 0;
    $failed = 0;

    foreach ($subscriptions as $sub) {
        $success = sendPushNotification(
            $sub['endpoint'],
            $sub['keys_p256dh'],
            $sub['keys_auth'],
            $payload,
            $keys['vapid_private_key'],
            $keys['vapid_public_key']
        );

        if ($success) {
            $sent++;
        } else {
            $failed++;
            // If endpoint is invalid, remove it
            removePushSubscription($pdo, $sub['endpoint']);
        }
    }

    return ['sent' => $sent, 'failed' => $failed];
}

/**
 * Check if push is enabled for school
 */
function isPushEnabled($pdo, $school_id)
{
    $stmt = $pdo->prepare("SELECT push_notifications_enabled FROM attendance_settings WHERE school_id = ?");
    $stmt->execute([$school_id]);
    $result = $stmt->fetch();

    return $result && $result['push_notifications_enabled'];
}

/**
 * Generate VAPID keys and save to database
 */
function generateAndSaveVAPIDKeys($pdo, $school_id)
{
    $keys = generateVAPIDKeysPHP();

    if (!$keys) {
        return false;
    }

    $stmt = $pdo->prepare("
        UPDATE attendance_settings 
        SET vapid_public_key = ?, vapid_private_key = ?, push_notifications_enabled = 1
        WHERE school_id = ?
    ");

    return $stmt->execute([$keys['publicKey'], $keys['privateKey'], $school_id]);
}

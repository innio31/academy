<?php
// gos/subscription-expired.php - Subscription Expired Page
session_start();
require_once 'includes/config.php';

// Get subscription status
$subscription_status = checkSubscriptionStatus($pdo);

// Clear session to prevent auto-redirect loops
$subscription_message = $_SESSION['subscription_message'] ?? ($subscription_status['message'] ?? 'Your subscription has expired.');
$blocked_url = $_SESSION['blocked_url'] ?? '';

// Clear the stored message after displaying
unset($_SESSION['subscription_message']);
unset($_SESSION['blocked_url']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription Expired - <?php echo SCHOOL_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .expired-container {
            max-width: 600px;
            width: 100%;
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideIn 0.5s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .expired-header {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
            padding: 40px;
            text-align: center;
        }

        .expired-icon {
            font-size: 80px;
            margin-bottom: 20px;
        }

        .expired-header h1 {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .expired-header p {
            opacity: 0.9;
        }

        .expired-body {
            padding: 40px;
        }

        .info-box {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
            flex-wrap: wrap;
            gap: 10px;
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: #555;
        }

        .info-value {
            color: #333;
            word-break: break-word;
            text-align: right;
        }

        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #f39c12;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
        }

        .warning-box i {
            color: #f39c12;
            margin-right: 10px;
        }

        .contact-box {
            background: #d5f4e6;
            border-left: 4px solid #27ae60;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
        }

        .contact-box i {
            color: #27ae60;
            margin-right: 10px;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #f8f9fa;
            color: #333;
            border: 1px solid #ddd;
        }

        .btn-secondary:hover {
            background: #e9ecef;
        }

        @media (max-width: 768px) {
            .expired-header {
                padding: 30px;
            }

            .expired-body {
                padding: 25px;
            }

            .expired-icon {
                font-size: 60px;
            }

            .expired-header h1 {
                font-size: 1.5rem;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                justify-content: center;
            }

            .info-item {
                flex-direction: column;
            }

            .info-value {
                text-align: left;
            }
        }
    </style>
</head>

<body>
    <div class="expired-container">
        <div class="expired-header">
            <div class="expired-icon">
                <i class="fas fa-hourglass-end"></i>
            </div>
            <h1>Subscription Expired</h1>
            <p>Access to the portal has been restricted</p>
        </div>

        <div class="expired-body">
            <div class="warning-box">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Access Blocked:</strong> <?php echo htmlspecialchars($subscription_message); ?>
            </div>

            <div class="info-box">
                <div class="info-item">
                    <span class="info-label">School:</span>
                    <span class="info-value"><?php echo SCHOOL_NAME; ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">School Code:</span>
                    <span class="info-value"><?php echo SCHOOL_CODE; ?></span>
                </div>
                <?php
                $expiry_formatted = $subscription_status['expiry_formatted'] ?? 'N/A';
                if ($expiry_formatted !== 'N/A' && $expiry_formatted !== 'No expiry date'):
                ?>
                    <div class="info-item">
                        <span class="info-label">Expired On:</span>
                        <span class="info-value"><?php echo $expiry_formatted; ?></span>
                    </div>
                <?php endif; ?>
                <div class="info-item">
                    <span class="info-label">Status:</span>
                    <span class="info-value" style="color: #e74c3c; font-weight: 600;">
                        <i class="fas fa-times-circle"></i>
                        <?php
                        $status = $subscription_status['status'] ?? 'inactive';
                        echo ucfirst((string)$status);
                        ?>
                    </span>
                </div>
            </div>

            <div class="contact-box">
                <i class="fas fa-envelope"></i>
                <strong>Need Assistance?</strong><br>
                Please contact the school administrator to renew your subscription and restore access.
                <div style="margin-top: 10px;">
                    <small>
                        <i class="fas fa-phone"></i> Call:
                        <?php
                        $contact_phone = $subscription_status['contact_phone'] ?? '';
                        if ($contact_phone) {
                            echo htmlspecialchars($contact_phone);
                        } else {
                            echo SCHOOL_PHONE;
                        }
                        ?>
                    </small>
                    <br>
                    <small>
                        <i class="fas fa-envelope"></i> Email:
                        <?php
                        $contact_email = $subscription_status['contact_email'] ?? '';
                        if ($contact_email) {
                            echo htmlspecialchars($contact_email);
                        } else {
                            echo SCHOOL_EMAIL;
                        }
                        ?>
                    </small>
                </div>
            </div>

            <div class="action-buttons">
                <a href="login.php" class="btn btn-secondary">
                    <i class="fas fa-sign-in-alt"></i> Back to Login
                </a>
                <a href="https://acad.com.ng" class="btn btn-primary">
                    <i class="fas fa-headset"></i> Contact Support
                </a>
            </div>
        </div>
    </div>
</body>

</html>
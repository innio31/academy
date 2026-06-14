<?php
// msv/admin/bank_settings.php - Configure Bank Details
session_start();
require_once '../includes/config.php';
check_page_access(['acct', 'super_admin', 'admin']);

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;

$message = '';
$message_type = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bank_name = trim($_POST['bank_name'] ?? '');
    $bank_account_name = trim($_POST['bank_account_name'] ?? '');
    $bank_account_number = trim($_POST['bank_account_number'] ?? '');
    $bank_branch = trim($_POST['bank_branch'] ?? '');
    
    try {
        // Update or insert bank settings
        $settings = [
            'bank_name' => $bank_name,
            'bank_account_name' => $bank_account_name,
            'bank_account_number' => $bank_account_number,
            'bank_branch' => $bank_branch
        ];
        
        foreach ($settings as $key => $value) {
            $stmt = $pdo->prepare("
                INSERT INTO system_settings (school_id, setting_key, setting_value, created_at) 
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
            ");
            $stmt->execute([$school_id, $key, $value, $value]);
        }
        
        $message = "Bank details saved successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = "Error saving bank details: " . $e->getMessage();
        $message_type = "error";
    }
}

// Get current bank settings
$bank_details = [];
$stmt = $pdo->prepare("
    SELECT setting_key, setting_value 
    FROM system_settings 
    WHERE school_id = ? AND setting_key LIKE 'bank_%'
");
$stmt->execute([$school_id]);
$bank_settings = $stmt->fetchAll();
foreach ($bank_settings as $setting) {
    $bank_details[$setting['setting_key']] = $setting['setting_value'];
}

$bank_name = $bank_details['bank_name'] ?? 'First Bank of Nigeria';
$bank_account_name = $bank_details['bank_account_name'] ?? $school_name . ' - School Account';
$bank_account_number = $bank_details['bank_account_number'] ?? '';
$bank_branch = $bank_details['bank_branch'] ?? 'Main Branch';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bank Settings - <?php echo htmlspecialchars($school_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: <?php echo $secondary_color; ?>;
            --sidebar-width: 280px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            color: #333;
        }
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px;
            min-height: 100vh;
        }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 70px 15px 15px; }
        }
        .top-header {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            max-width: 600px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 8px;
            color: #555;
        }
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.9rem;
            font-family: inherit;
        }
        .form-group input:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            background: var(--primary-color);
            color: white;
        }
        .alert {
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d5f4e6;
            color: #27ae60;
            border-left: 4px solid #27ae60;
        }
        .alert-error {
            background: #f8d7da;
            color: #e74c3c;
            border-left: 4px solid #e74c3c;
        }
        .info-text {
            font-size: 0.75rem;
            color: #888;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <?php require_once 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="top-header">
            <h1><i class="fas fa-university"></i> Bank Settings</h1>
            <p>Configure school bank account details for student payments</p>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="form-card">
            <form method="POST">
                <div class="form-group">
                    <label>Bank Name *</label>
                    <input type="text" name="bank_name" required value="<?php echo htmlspecialchars($bank_name); ?>" placeholder="e.g., First Bank of Nigeria">
                </div>
                
                <div class="form-group">
                    <label>Account Name *</label>
                    <input type="text" name="bank_account_name" required value="<?php echo htmlspecialchars($bank_account_name); ?>" placeholder="School Name - School Account">
                </div>
                
                <div class="form-group">
                    <label>Account Number *</label>
                    <input type="text" name="bank_account_number" required value="<?php echo htmlspecialchars($bank_account_number); ?>" placeholder="e.g., 1234567890">
                </div>
                
                <div class="form-group">
                    <label>Branch</label>
                    <input type="text" name="bank_branch" value="<?php echo htmlspecialchars($bank_branch); ?>" placeholder="e.g., Main Branch">
                </div>
                
                <div class="info-text">
                    <i class="fas fa-info-circle"></i> These bank details will be displayed to students when they make payments.
                </div>
                
                <button type="submit" class="btn">Save Bank Details</button>
            </form>
        </div>
    </div>
</body>
</html>
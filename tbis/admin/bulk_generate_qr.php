<?php
// admin/bulk_regenerate_qr.php - Bulk QR code regeneration
session_start();
require_once '../includes/config.php';
require_once '../includes/qr_functions.php';

$school_id = SCHOOL_ID;
$message = '';
$message_type = '';

if (isset($_POST['regenerate_all'])) {
    $stmt = $pdo->prepare("SELECT id, admission_number, full_name FROM students WHERE school_id = ?");
    $stmt->execute([$school_id]);
    $students = $stmt->fetchAll();
    
    $success = 0;
    $failed = 0;
    
    foreach ($students as $student) {
        $qr_data = generateStudentQRCode($student['id'], $student['admission_number'], $student['full_name']);
        if (saveStudentQRCode($pdo, $student['id'], $qr_data)) {
            $success++;
        } else {
            $failed++;
        }
    }
    
    $message = "QR codes regenerated: $success successful, $failed failed";
    $message_type = $failed > 0 ? 'warning' : 'success';
}

// Get statistics
$stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN qr_code IS NULL OR qr_code = '' THEN 1 ELSE 0 END) as missing FROM students WHERE school_id = ?");
$stmt->execute([$school_id]);
$stats = $stmt->fetch();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Bulk QR Code Regeneration</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: Arial, sans-serif; padding: 50px; background: #f5f6fa; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; }
        .stats { background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center; }
        .stat-number { font-size: 2rem; font-weight: bold; color: #3498db; }
        .alert { padding: 15px; border-radius: 8px; margin: 20px 0; }
        .alert-success { background: #d5f4e6; color: #155724; }
        .alert-warning { background: #fff3cd; color: #856404; }
        .btn { padding: 12px 24px; background: #3498db; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 1rem; }
        .btn:hover { background: #2980b9; }
        .back-link { display: inline-block; margin-top: 20px; color: #3498db; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-qrcode"></i> Bulk QR Code Regeneration</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="stats">
            <div class="stat-number"><?php echo $stats['total']; ?></div>
            <div>Total Students</div>
            <div style="margin-top: 10px;">
                <span style="color: #e74c3c;"><?php echo $stats['missing']; ?></span> missing QR codes
            </div>
        </div>
        
        <form method="POST">
            <button type="submit" name="regenerate_all" class="btn" onclick="return confirm('Regenerate QR codes for all <?php echo $stats['total']; ?> students?')">
                <i class="fas fa-sync-alt"></i> Regenerate All QR Codes
            </button>
        </form>
        
        <a href="manage-students.php" class="back-link">← Back to Manage Students</a>
    </div>
</body>
</html>
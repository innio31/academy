<?php
// msv/student/download_receipt.php - Download Payment Receipt
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: /msv/login.php");
    exit();
}

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$school_address = defined('SCHOOL_ADDRESS') ? SCHOOL_ADDRESS : '';
$school_phone = defined('SCHOOL_PHONE') ? SCHOOL_PHONE : '';
$school_email = defined('SCHOOL_EMAIL') ? SCHOOL_EMAIL : '';
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;
$student_id = $_SESSION['user_id'];

$payment_id = isset($_GET['payment_id']) ? intval($_GET['payment_id']) : 0;

if (!$payment_id) {
    die("Invalid payment ID");
}

// Get payment details
$stmt = $pdo->prepare("
    SELECT p.*, 
           s.full_name as student_name,
           s.admission_number,
           s.class,
           b.description as bill_description,
           b.session as bill_session,
           b.term as bill_term,
           r.receipt_number,
           a.full_name as verified_by_name
    FROM fin_payments p
    JOIN students s ON p.student_id = s.id
    LEFT JOIN fin_bills b ON p.bill_id = b.id
    LEFT JOIN fin_receipts r ON p.id = r.payment_id
    LEFT JOIN admin_users a ON p.verified_by = a.id
    WHERE p.id = ? AND p.student_id = ? AND p.school_id = ? AND p.status = 'verified'
");
$stmt->execute([$payment_id, $student_id, $school_id]);
$payment = $stmt->fetch();

if (!$payment) {
    die("Receipt not found or payment not verified.");
}

// Get school logo
$school_logo = '';
$stmt2 = $pdo->prepare("SELECT logo_path FROM schools WHERE id = ?");
$stmt2->execute([$school_id]);
$school_data = $stmt2->fetch();
if ($school_data && !empty($school_data['logo_path'])) {
    $school_logo = $school_data['logo_path'];
    if (file_exists($_SERVER['DOCUMENT_ROOT'] . $school_logo)) {
        $school_logo = $_SERVER['DOCUMENT_ROOT'] . $school_logo;
    } else {
        $school_logo = '';
    }
}

// Generate HTML receipt
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Receipt - <?php echo htmlspecialchars($school_name); ?></title>
    <style>
        @media print {
            body { margin: 0; padding: 0; }
            .no-print { display: none; }
        }
        body {
            font-family: 'Poppins', Arial, sans-serif;
            background: #f5f6fa;
            padding: 20px;
        }
        .receipt-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .receipt-header {
            background: <?php echo $primary_color; ?>;
            color: white;
            padding: 20px;
            text-align: center;
        }
        .receipt-body {
            padding: 30px;
        }
        .receipt-title {
            text-align: center;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 20px;
            color: <?php echo $primary_color; ?>;
        }
        .receipt-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #eee;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .info-label {
            font-weight: 600;
            color: #555;
        }
        .info-value {
            font-weight: 500;
        }
        .amount-row {
            background: #f0f7ff;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            text-align: center;
        }
        .amount-value {
            font-size: 28px;
            font-weight: 700;
            color: <?php echo $primary_color; ?>;
        }
        .footer {
            text-align: center;
            padding: 20px;
            border-top: 1px solid #eee;
            font-size: 12px;
            color: #888;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            margin: 10px;
            background: <?php echo $primary_color; ?>;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            cursor: pointer;
        }
        @media (max-width: 600px) {
            .receipt-info {
                grid-template-columns: 1fr;
            }
            .receipt-body {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="receipt-header">
            <?php if ($school_logo): ?>
                <img src="<?php echo $school_logo; ?>" style="height: 60px; margin-bottom: 10px;" alt="Logo">
            <?php endif; ?>
            <h2><?php echo htmlspecialchars($school_name); ?></h2>
            <p><?php echo htmlspecialchars($school_address); ?></p>
            <p>Tel: <?php echo htmlspecialchars($school_phone); ?> | Email: <?php echo htmlspecialchars($school_email); ?></p>
        </div>
        
        <div class="receipt-body">
            <div class="receipt-title">
                PAYMENT RECEIPT
            </div>
            
            <div class="receipt-info">
                <div class="info-row">
                    <span class="info-label">Receipt Number:</span>
                    <span class="info-value"><?php echo htmlspecialchars($payment['receipt_number']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Date:</span>
                    <span class="info-value"><?php echo date('d F Y', strtotime($payment['payment_date'])); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Student Name:</span>
                    <span class="info-value"><?php echo htmlspecialchars($payment['student_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Admission Number:</span>
                    <span class="info-value"><?php echo htmlspecialchars($payment['admission_number']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Class:</span>
                    <span class="info-value"><?php echo htmlspecialchars($payment['class']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Description:</span>
                    <span class="info-value"><?php echo htmlspecialchars($payment['bill_description'] ?? 'School Fees Payment'); ?></span>
                </div>
                <?php if ($payment['bill_session']): ?>
                <div class="info-row">
                    <span class="info-label">Session/Term:</span>
                    <span class="info-value"><?php echo htmlspecialchars($payment['bill_session'] . ' - ' . $payment['bill_term'] . ' Term'); ?></span>
                </div>
                <?php endif; ?>
                <div class="info-row">
                    <span class="info-label">Payment Method:</span>
                    <span class="info-value"><?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Reference Number:</span>
                    <span class="info-value"><?php echo htmlspecialchars($payment['reference_number'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Verified By:</span>
                    <span class="info-value"><?php echo htmlspecialchars($payment['verified_by_name'] ?? 'System'); ?></span>
                </div>
            </div>
            
            <div class="amount-row">
                <div style="font-size: 14px; color: #666;">AMOUNT PAID</div>
                <div class="amount-value">₦<?php echo number_format($payment['amount_paid'], 2); ?></div>
            </div>
            
            <div class="info-row">
                <span class="info-label">Amount in Words:</span>
                <span class="info-value"><?php echo ucwords(strtolower(convertNumberToWords($payment['amount_paid']))); ?> Naira Only</span>
            </div>
            
            <div style="margin-top: 30px; text-align: center;">
                <p style="font-size: 12px;">This is a computer-generated receipt and requires no signature.</p>
                <p style="font-size: 12px;">Thank you for your payment!</p>
            </div>
        </div>
        
        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> - Official Payment Receipt</p>
            <p>Generated on: <?php echo date('d F Y H:i:s'); ?></p>
        </div>
    </div>
    
    <div style="text-align: center; margin-top: 20px;" class="no-print">
        <button onclick="window.print()" class="btn">Print Receipt</button>
        <button onclick="window.close()" class="btn" style="background: #666;">Close</button>
    </div>
    
    <script>
        // Auto-print
        window.onload = function() {
            // Optional: auto-print
            // window.print();
        }
    </script>
</body>
</html>

<?php
function convertNumberToWords($number) {
    $number = (int)$number;
    $words = array(
        0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 
        5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 
        14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 
        18 => 'Eighteen', 19 => 'Nineteen', 20 => 'Twenty', 30 => 'Thirty', 
        40 => 'Forty', 50 => 'Fifty', 60 => 'Sixty', 70 => 'Seventy', 
        80 => 'Eighty', 90 => 'Ninety'
    );
    
    if ($number < 21) {
        return $words[$number];
    } elseif ($number < 100) {
        $tens = floor($number / 10) * 10;
        $units = $number % 10;
        return $words[$tens] . ($units ? ' ' . $words[$units] : '');
    } elseif ($number < 1000) {
        $hundreds = floor($number / 100);
        $remainder = $number % 100;
        return $words[$hundreds] . ' Hundred' . ($remainder ? ' and ' . convertNumberToWords($remainder) : '');
    } elseif ($number < 1000000) {
        $thousands = floor($number / 1000);
        $remainder = $number % 1000;
        return convertNumberToWords($thousands) . ' Thousand' . ($remainder ? ' ' . convertNumberToWords($remainder) : '');
    }
    return number_format($number);
}
?>
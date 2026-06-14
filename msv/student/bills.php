<?php
// msv/student/bills.php - Student Bill Management
session_start();
require_once '../includes/config.php';

// Check if student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: /msv/login.php");
    exit();
}

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'] ?? 'Student';

// Get student details
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND school_id = ?");
$stmt->execute([$student_id, $school_id]);
$student = $stmt->fetch();

if (!$student) {
    header("Location: /msv/login.php");
    exit();
}

$student_class = $student['class'] ?? '';
$admission_number = $student['admission_number'] ?? '';

// Get school bank details from system_settings with school_id
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

// Default bank details if not set
$bank_account_name = $bank_details['bank_account_name'] ?? $school_name . ' - School Account';
$bank_account_number = $bank_details['bank_account_number'] ?? '1234567890';
$bank_name = $bank_details['bank_name'] ?? 'First Bank of Nigeria';
$bank_branch = $bank_details['bank_branch'] ?? 'Main Branch';

// Handle payment upload
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'upload_payment') {
        $bill_id = intval($_POST['bill_id'] ?? 0);
        $amount_paid = floatval($_POST['amount_paid'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? 'bank_transfer';
        $reference_number = trim($_POST['reference_number'] ?? '');
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');

        // Handle file upload (optional)
        $proof_path = '';
        if (isset($_FILES['proof_payment']) && $_FILES['proof_payment']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/msv/uploads/payments/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $file_ext = strtolower(pathinfo($_FILES['proof_payment']['name'], PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png', 'pdf'];
            if (in_array($file_ext, $allowed_ext)) {
                $file_name = 'payment_' . $student_id . '_' . time() . '.' . $file_ext;
                $file_path = '/msv/uploads/payments/' . $file_name;
                
                if (move_uploaded_file($_FILES['proof_payment']['tmp_name'], $upload_dir . $file_name)) {
                    $proof_path = $file_path;
                }
            }
        }

        if ($amount_paid <= 0) {
            $message = "Please enter a valid amount";
            $message_type = "error";
        } else {
            try {
                // Get bill details if bill_id is provided
                if ($bill_id > 0) {
                    $stmt = $pdo->prepare("
                        SELECT amount, description, session, term 
                        FROM fin_bills 
                        WHERE id = ? AND student_id = ? AND school_id = ? 
                        AND status IN ('pending', 'part_paid')
                    ");
                    $stmt->execute([$bill_id, $student_id, $school_id]);
                    $bill = $stmt->fetch();
                    if (!$bill) {
                        throw new Exception("Invalid bill selected");
                    }
                    
                    // Check if amount exceeds balance
                    $stmt = $pdo->prepare("
                        SELECT (amount - amount_paid) as balance 
                        FROM fin_bills 
                        WHERE id = ? AND student_id = ? AND school_id = ?
                    ");
                    $stmt->execute([$bill_id, $student_id, $school_id]);
                    $balance = $stmt->fetchColumn();
                    
                    if ($amount_paid > $balance) {
                        throw new Exception("Amount paid exceeds outstanding balance of ₦" . number_format($balance, 2));
                    }
                }

                // Insert payment record
                $stmt = $pdo->prepare("
                    INSERT INTO fin_payments (
                        school_id, bill_id, student_id, amount_paid, 
                        payment_date, payment_method, reference_number, 
                        notes, proof_path, status, recorded_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_verification', NULL, NOW())
                ");
                $stmt->execute([
                    $school_id, 
                    $bill_id ?: null, 
                    $student_id, 
                    $amount_paid, 
                    $payment_date, 
                    $payment_method, 
                    $reference_number ?: null, 
                    $notes, 
                    $proof_path ?: null
                ]);

                $message = "Payment submitted successfully! Our finance team will verify it shortly.";
                $message_type = "success";
                
                // Redirect to refresh the page after successful upload
                header("Location: bills.php?success=1");
                exit();
            } catch (Exception $e) {
                $message = "Error submitting payment: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
}

// Get student's bills
$stmt = $pdo->prepare("
    SELECT b.*, 
           (b.amount - b.amount_paid) as balance,
           CASE 
               WHEN b.status = 'paid' THEN 'Paid'
               WHEN b.status = 'part_paid' THEN 'Partially Paid'
               WHEN b.status = 'overdue' THEN 'Overdue'
               ELSE 'Pending'
           END as status_text
    FROM fin_bills b
    WHERE b.student_id = ? AND b.school_id = ?
    ORDER BY CASE WHEN b.status IN ('pending', 'part_paid', 'overdue') THEN 0 ELSE 1 END, 
             b.due_date ASC, b.created_at DESC
");
$stmt->execute([$student_id, $school_id]);
$bills = $stmt->fetchAll();

// Get payment history
$stmt = $pdo->prepare("
    SELECT p.*, 
           b.description as bill_description,
           r.receipt_number,
           r.pdf_path as receipt_path
    FROM fin_payments p
    LEFT JOIN fin_bills b ON p.bill_id = b.id
    LEFT JOIN fin_receipts r ON p.id = r.payment_id
    WHERE p.student_id = ? AND p.school_id = ?
    ORDER BY p.payment_date DESC, p.created_at DESC
");
$stmt->execute([$student_id, $school_id]);
$payments = $stmt->fetchAll();

// Calculate totals
$total_bills = array_sum(array_column($bills, 'amount'));
$total_paid = array_sum(array_column($bills, 'amount_paid'));
$total_outstanding = $total_bills - $total_paid;

// Get pending payments count
$pending_payments = array_filter($payments, function($p) {
    return $p['status'] === 'pending_verification';
});
$pending_count = count($pending_payments);

// Check for success message from redirect
if (isset($_GET['success'])) {
    $message = "Payment submitted successfully! Our finance team will verify it shortly.";
    $message_type = "success";
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($school_name); ?> - My Bills</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: <?php echo $secondary_color; ?>;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #3498db;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --sidebar-width: 280px;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.12);
            --radius-sm: 8px;
            --radius-md: 12px;
            --transition: all 0.3s ease;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            color: #333;
            min-height: 100vh;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px;
            min-height: 100vh;
            transition: var(--transition);
        }

        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            width: 44px;
            height: 44px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 20px;
            cursor: pointer;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 70px 15px 15px;
            }
            .mobile-menu-btn {
                display: flex;
                align-items: center;
                justify-content: center;
            }
        }

        .top-header {
            background: white;
            padding: 20px;
            border-radius: var(--radius-md);
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
        }

        .top-header h1 {
            color: var(--primary-color);
            font-size: 1.3rem;
            margin-bottom: 5px;
        }

        .top-header p {
            color: #666;
            font-size: 0.85rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            border-left: 4px solid;
            text-align: center;
        }

        .stat-card.total { border-left-color: var(--primary-color); }
        .stat-card.paid { border-left-color: var(--success-color); }
        .stat-card.outstanding { border-left-color: var(--warning-color); }
        .stat-card.pending { border-left-color: var(--info-color); }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .stat-label {
            font-size: 0.75rem;
            color: #666;
            margin-top: 5px;
        }

        .alert {
            padding: 12px 20px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d5f4e6;
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }

        .alert-error {
            background: #f8d7da;
            color: var(--danger-color);
            border-left: 4px solid var(--danger-color);
        }

        .alert-info {
            background: #e3f2fd;
            color: var(--info-color);
            border-left: 4px solid var(--info-color);
        }

        .section-card {
            background: white;
            border-radius: var(--radius-md);
            margin-bottom: 24px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .section-header {
            padding: 15px 20px;
            background: var(--light-color);
            border-bottom: 2px solid var(--primary-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .section-header h2 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark-color);
        }

        .section-header h2 i {
            margin-right: 8px;
            color: var(--primary-color);
        }

        .section-body {
            padding: 20px;
        }

        .bill-item, .payment-item {
            border-bottom: 1px solid var(--light-color);
            padding: 15px;
            transition: var(--transition);
        }

        .bill-item:last-child, .payment-item:last-child {
            border-bottom: none;
        }

        .bill-item:hover, .payment-item:hover {
            background: #f9f9f9;
        }

        .bill-header, .payment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .bill-title {
            font-weight: 600;
            font-size: 1rem;
        }

        .bill-amount {
            font-weight: 700;
            font-size: 1rem;
        }

        .bill-details {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            font-size: 0.75rem;
            color: #888;
            margin-bottom: 10px;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-paid { background: #d5f4e6; color: var(--success-color); }
        .status-part_paid { background: #fef3c7; color: var(--warning-color); }
        .status-pending { background: #fef3c7; color: var(--warning-color); }
        .status-overdue { background: #f8d7da; color: var(--danger-color); }
        .status-verified { background: #d5f4e6; color: var(--success-color); }
        .status-pending_verification { background: #fef3c7; color: var(--warning-color); }
        .status-rejected { background: #f8d7da; color: var(--danger-color); }

        .progress-bar {
            height: 6px;
            background: var(--light-color);
            border-radius: 10px;
            overflow: hidden;
            margin-top: 8px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            border-radius: 10px;
            transition: width 0.3s;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 500;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary-color);
            color: var(--primary-color);
        }

        .btn-outline:hover {
            background: var(--primary-color);
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.7rem;
        }

        .bank-details {
            background: #f8f9fa;
            border-radius: var(--radius-sm);
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid #e0e0e0;
        }

        .bank-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dashed #ddd;
        }

        .bank-row:last-child {
            border-bottom: none;
        }

        .bank-label {
            font-weight: 600;
            color: #555;
        }

        .bank-value {
            font-weight: 500;
            font-family: monospace;
            font-size: 1rem;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 500;
            margin-bottom: 5px;
            color: #555;
        }

        .form-group label .optional {
            font-weight: 400;
            color: #888;
            font-size: 0.7rem;
        }

        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            font-family: inherit;
        }

        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: var(--secondary-color);
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: var(--radius-md);
            max-width: 550px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 15px 20px;
            background: var(--primary-color);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid var(--light-color);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        .copy-btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            cursor: pointer;
            margin-left: 8px;
        }

        .required-field::after {
            content: " *";
            color: var(--danger-color);
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            .stat-card {
                padding: 12px;
            }
            .stat-value {
                font-size: 1.2rem;
            }
            .bill-header, .payment-header {
                flex-direction: column;
                gap: 8px;
            }
            .section-body {
                padding: 12px;
            }
            .bank-row {
                flex-direction: column;
                gap: 5px;
            }
        }
    </style>
</head>

<body>

    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <?php require_once 'includes/student_sidebar.php'; ?>

    <div class="main-content">
        <div class="top-header">
            <h1><i class="fas fa-receipt"></i> My Bills & Payments</h1>
            <p>View outstanding fees, make payments, and track your payment history</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : ($message_type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'); ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-value">₦<?php echo number_format($total_bills, 2); ?></div>
                <div class="stat-label">Total Bills</div>
            </div>
            <div class="stat-card paid">
                <div class="stat-value">₦<?php echo number_format($total_paid, 2); ?></div>
                <div class="stat-label">Total Paid</div>
            </div>
            <div class="stat-card outstanding">
                <div class="stat-value">₦<?php echo number_format($total_outstanding, 2); ?></div>
                <div class="stat-label">Outstanding Balance</div>
            </div>
            <div class="stat-card pending">
                <div class="stat-value"><?php echo $pending_count; ?></div>
                <div class="stat-label">Pending Payments</div>
            </div>
        </div>

        <!-- Outstanding Bills Section -->
        <div class="section-card">
            <div class="section-header">
                <h2><i class="fas fa-file-invoice"></i> Outstanding Bills</h2>
                <button class="btn btn-primary btn-sm" onclick="openPaymentModal()">
                    <i class="fas fa-plus-circle"></i> Make Payment
                </button>
            </div>
            <div class="section-body">
                <?php $outstanding_bills = array_filter($bills, function($b) {
                    return $b['status'] !== 'paid';
                }); ?>
                
                <?php if (empty($outstanding_bills)): ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle" style="color: var(--success-color);"></i>
                        <p>No outstanding bills! All your fees are paid.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($outstanding_bills as $bill): 
                        $paid_percent = $bill['amount'] > 0 ? ($bill['amount_paid'] / $bill['amount']) * 100 : 0;
                        $status_class = $bill['status'] === 'overdue' ? 'status-overdue' : ($bill['status'] === 'part_paid' ? 'status-part_paid' : 'status-pending');
                    ?>
                        <div class="bill-item">
                            <div class="bill-header">
                                <div class="bill-title"><?php echo htmlspecialchars($bill['description']); ?></div>
                                <div class="bill-amount">₦<?php echo number_format($bill['balance'], 2); ?></div>
                            </div>
                            <div class="bill-details">
                                <span><i class="fas fa-calendar"></i> Due: <?php echo date('d M Y', strtotime($bill['due_date'])); ?></span>
                                <span><i class="fas fa-tag"></i> Session: <?php echo htmlspecialchars($bill['session']); ?></span>
                                <span><i class="fas fa-chalkboard"></i> Term: <?php echo htmlspecialchars($bill['term']); ?> Term</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $paid_percent; ?>%"></div>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-top: 8px; font-size: 0.7rem;">
                                <span>Paid: ₦<?php echo number_format($bill['amount_paid'], 2); ?></span>
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <?php echo $bill['status_text']; ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment History -->
        <div class="section-card">
            <div class="section-header">
                <h2><i class="fas fa-history"></i> Payment History</h2>
            </div>
            <div class="section-body">
                <?php if (empty($payments)): ?>
                    <div class="empty-state">
                        <i class="fas fa-credit-card"></i>
                        <p>No payment records found.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($payments as $payment): 
                        $status_class = '';
                        if ($payment['status'] === 'verified') $status_class = 'status-verified';
                        elseif ($payment['status'] === 'pending_verification') $status_class = 'status-pending_verification';
                        elseif ($payment['status'] === 'rejected') $status_class = 'status-rejected';
                    ?>
                        <div class="payment-item">
                            <div class="bill-header">
                                <div class="bill-title">
                                    <?php echo htmlspecialchars($payment['bill_description'] ?? 'Manual Payment'); ?>
                                    <?php if ($payment['status'] === 'pending_verification'): ?>
                                        <span class="status-badge <?php echo $status_class; ?>" style="margin-left: 8px;">Pending Verification</span>
                                    <?php elseif ($payment['status'] === 'verified'): ?>
                                        <span class="status-badge <?php echo $status_class; ?>" style="margin-left: 8px;">Verified</span>
                                    <?php elseif ($payment['status'] === 'rejected'): ?>
                                        <span class="status-badge <?php echo $status_class; ?>" style="margin-left: 8px;">Rejected</span>
                                    <?php endif; ?>
                                </div>
                                <div class="bill-amount">₦<?php echo number_format($payment['amount_paid'], 2); ?></div>
                            </div>
                            <div class="bill-details">
                                <span><i class="fas fa-calendar"></i> Date: <?php echo date('d M Y', strtotime($payment['payment_date'])); ?></span>
                                <span><i class="fas fa-money-bill"></i> Method: <?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></span>
                                <?php if (!empty($payment['reference_number'])): ?>
                                    <span><i class="fas fa-hashtag"></i> Ref: <?php echo htmlspecialchars($payment['reference_number']); ?></span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($payment['status'] === 'verified' && !empty($payment['receipt_number'])): ?>
                                <div style="margin-top: 10px;">
                                    <a href="download_receipt.php?payment_id=<?php echo $payment['id']; ?>" class="btn btn-outline btn-sm" target="_blank">
                                        <i class="fas fa-download"></i> Download Receipt
                                    </a>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($payment['status'] === 'rejected' && !empty($payment['rejection_reason'])): ?>
                                <div style="margin-top: 10px; font-size: 0.7rem; color: var(--danger-color); background: #f8d7da; padding: 8px; border-radius: 6px;">
                                    <i class="fas fa-info-circle"></i> Rejection reason: <?php echo htmlspecialchars($payment['rejection_reason']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="footer" style="text-align: center; padding: 20px; color: #888; font-size: 0.75rem;">
            <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> - Student Portal</p>
        </div>
    </div>

    <!-- Payment Modal -->
    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data" id="paymentForm">
                <input type="hidden" name="action" value="upload_payment">
                
                <div class="modal-header">
                    <h3><i class="fas fa-money-bill-wave"></i> Make a Payment</h3>
                    <button type="button" onclick="closePaymentModal()" style="background: none; border: none; color: white; font-size: 20px; cursor: pointer;">&times;</button>
                </div>
                
                <div class="modal-body">
                    <!-- Bank Details -->
                    <div class="bank-details">
                        <h4 style="margin-bottom: 10px; color: var(--primary-color);">
                            <i class="fas fa-university"></i> School Account Details
                        </h4>
                        <div class="bank-row">
                            <span class="bank-label">Bank Name:</span>
                            <span class="bank-value"><?php echo htmlspecialchars($bank_name); ?>
                                <button type="button" class="copy-btn" onclick="copyToClipboard('<?php echo addslashes($bank_name); ?>')">Copy</button>
                            </span>
                        </div>
                        <div class="bank-row">
                            <span class="bank-label">Account Name:</span>
                            <span class="bank-value"><?php echo htmlspecialchars($bank_account_name); ?>
                                <button type="button" class="copy-btn" onclick="copyToClipboard('<?php echo addslashes($bank_account_name); ?>')">Copy</button>
                            </span>
                        </div>
                        <div class="bank-row">
                            <span class="bank-label">Account Number:</span>
                            <span class="bank-value"><?php echo htmlspecialchars($bank_account_number); ?>
                                <button type="button" class="copy-btn" onclick="copyToClipboard('<?php echo addslashes($bank_account_number); ?>')">Copy</button>
                            </span>
                        </div>
                        <div class="bank-row">
                            <span class="bank-label">Branch:</span>
                            <span class="bank-value"><?php echo htmlspecialchars($bank_branch); ?></span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Select Bill (Optional)</label>
                        <select name="bill_id" id="modal_bill_select">
                            <option value="">-- General Payment (No specific bill) --</option>
                            <?php foreach ($outstanding_bills as $bill): ?>
                                <option value="<?php echo $bill['id']; ?>" data-amount="<?php echo $bill['balance']; ?>">
                                    <?php echo htmlspecialchars($bill['description']); ?> - Balance: ₦<?php echo number_format($bill['balance'], 2); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="required-field">Amount Paid (₦) *</label>
                        <input type="number" name="amount_paid" id="modal_amount_paid" step="0.01" required placeholder="Enter amount">
                    </div>
                    
                    <div class="form-group">
                        <label>Payment Method *</label>
                        <select name="payment_method" required>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cash">Cash (If paying at school)</option>
                            <option value="pos">POS</option>
                            <option value="online">Online Payment</option>
                            <option value="cheque">Cheque</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Reference/Transaction Number <span class="optional">(Optional)</span></label>
                        <input type="text" name="reference_number" placeholder="Enter transaction reference or slip number (if available)">
                    </div>
                    
                    <div class="form-group">
                        <label>Payment Date *</label>
                        <input type="date" name="payment_date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Upload Payment Proof <span class="optional">(Optional - Screenshot/Receipt)</span></label>
                        <input type="file" name="proof_payment" accept="image/*,.pdf">
                        <small style="color: #888;">Optional: Upload a clear image or PDF of your payment receipt/transfer confirmation.</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Additional Notes <span class="optional">(Optional)</span></label>
                        <textarea name="notes" rows="3" placeholder="Any additional information about this payment..."></textarea>
                    </div>
                    
                    <div class="alert alert-info" style="background: #e3f2fd; color: #1565c0; border-left-color: #1565c0; font-size: 0.75rem;">
                        <i class="fas fa-info-circle"></i>
                        <strong>Note:</strong> Reference number and payment proof are optional but recommended. They help our finance team verify your payment faster.
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closePaymentModal()" class="btn btn-warning">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit Payment</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Mobile sidebar
        const mobileBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('studentSidebar');
        const overlay = document.getElementById('sidebarOverlay');

        if (mobileBtn) {
            mobileBtn.addEventListener('click', function() {
                sidebar.classList.toggle('active');
                overlay.classList.toggle('active');
                document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
            });
        }

        if (overlay) {
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            });
        }

        function openPaymentModal() {
            document.getElementById('paymentModal').classList.add('active');
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').classList.remove('active');
        }

        // Auto-fill amount when bill is selected
        const billSelect = document.getElementById('modal_bill_select');
        if (billSelect) {
            billSelect.addEventListener('change', function() {
                const selected = this.options[this.selectedIndex];
                const amount = selected.dataset.amount;
                const amountInput = document.getElementById('modal_amount_paid');
                if (amount && amount > 0) {
                    amountInput.value = amount;
                    amountInput.max = amount;
                } else {
                    amountInput.value = '';
                    amountInput.max = '';
                }
            });
        }

        // Close modal on outside click
        window.onclick = function(event) {
            const modal = document.getElementById('paymentModal');
            if (event.target === modal) {
                closePaymentModal();
            }
        }

        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(function() {
                alert('Copied to clipboard: ' + text);
            }).catch(function() {
                alert('Could not copy text. Please select and copy manually.');
            });
        }
    </script>

</body>

</html>
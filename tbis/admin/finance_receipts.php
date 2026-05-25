<?php
// tbis/admin/finance_receipts.php - Manage and Print Receipts
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../includes/config.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /tbis/login.php");
    exit();
}

// Get admin info
if (isset($_SESSION['admin_id'])) {
    $admin_id = $_SESSION['admin_id'];
    $admin_name = $_SESSION['admin_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'super_admin';
} else {
    $admin_id = $_SESSION['user_id'];
    $admin_name = $_SESSION['user_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$school_motto = defined('SCHOOL_MOTTO') ? SCHOOL_MOTTO : 'Excellence in Education';
$school_address = defined('SCHOOL_ADDRESS') ? SCHOOL_ADDRESS : '';
$school_phone = defined('SCHOOL_PHONE') ? SCHOOL_PHONE : '';
$school_email = defined('SCHOOL_EMAIL') ? SCHOOL_EMAIL : '';
$school_logo = defined('SCHOOL_LOGO') ? SCHOOL_LOGO : '/assets/logos/default.png';
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;

// Handle actions
$message = '';
$message_type = '';

// Regenerate receipt (if lost)
if (isset($_GET['action']) && $_GET['action'] === 'regenerate' && isset($_GET['payment_id'])) {
    $payment_id = intval($_GET['payment_id']);

    try {
        // Check if receipt already exists
        $stmt = $pdo->prepare("SELECT id FROM fin_receipts WHERE payment_id = ? AND school_id = ?");
        $stmt->execute([$payment_id, $school_id]);
        $existing = $stmt->fetch();

        if ($existing) {
            header("Location: finance_receipts.php?view=" . $existing['id']);
            exit();
        }

        // Get payment details
        $stmt = $pdo->prepare("
            SELECT p.*, s.full_name as student_name, s.admission_number, s.class, b.description as bill_description
            FROM fin_payments p
            JOIN students s ON p.student_id = s.id
            LEFT JOIN fin_bills b ON p.bill_id = b.id
            WHERE p.id = ? AND p.school_id = ? AND p.status = 'verified'
        ");
        $stmt->execute([$payment_id, $school_id]);
        $payment = $stmt->fetch();

        if ($payment) {
            $receipt_number = generateReceiptNumber($pdo, $school_id);
            $stmt = $pdo->prepare("
                INSERT INTO fin_receipts (school_id, payment_id, receipt_number, issued_to, issued_by, issued_at, amount)
                VALUES (?, ?, ?, ?, ?, NOW(), ?)
            ");
            $stmt->execute([$school_id, $payment_id, $receipt_number, $payment['student_name'], $admin_id, $payment['amount_paid']]);

            $receipt_id = $pdo->lastInsertId();
            header("Location: finance_receipts.php?view=" . $receipt_id);
            exit();
        }
    } catch (Exception $e) {
        $message = "Error regenerating receipt: " . $e->getMessage();
        $message_type = "error";
    }
}

// Helper function
function generateReceiptNumber($pdo, $school_id)
{
    $year = date('Y');
    $stmt = $pdo->prepare("
        SELECT COUNT(*) + 1 as next_num 
        FROM fin_receipts 
        WHERE school_id = ? AND YEAR(issued_at) = ?
    ");
    $stmt->execute([$school_id, $year]);
    $next = $stmt->fetchColumn();
    return "RCP/" . $year . "/" . str_pad($next, 6, "0", STR_PAD_LEFT);
}

// Pagination for receipts list
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Filters
$filter_receipt_no = isset($_GET['receipt_no']) ? trim($_GET['receipt_no']) : '';
$filter_student = isset($_GET['student']) ? trim($_GET['student']) : '';
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filter_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build query for receipts list
$where_clauses = ["r.school_id = ?"];
$params = [$school_id];

if (!empty($filter_receipt_no)) {
    $where_clauses[] = "r.receipt_number LIKE ?";
    $params[] = "%$filter_receipt_no%";
}

if (!empty($filter_student)) {
    $where_clauses[] = "(s.full_name LIKE ? OR s.admission_number LIKE ?)";
    $params[] = "%$filter_student%";
    $params[] = "%$filter_student%";
}

if (!empty($filter_date_from)) {
    $where_clauses[] = "DATE(r.issued_at) >= ?";
    $params[] = $filter_date_from;
}

if (!empty($filter_date_to)) {
    $where_clauses[] = "DATE(r.issued_at) <= ?";
    $params[] = $filter_date_to;
}

$where_sql = implode(" AND ", $where_clauses);

// Get total count
$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM fin_receipts r
    JOIN fin_payments p ON r.payment_id = p.id
    JOIN students s ON p.student_id = s.id
    WHERE $where_sql
");
$stmt->execute($params);
$total_records = $stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// Get receipts list
$stmt = $pdo->prepare("
    SELECT r.*, 
           p.amount_paid, p.payment_date, p.payment_method, p.reference_number,
           s.full_name as student_name, s.admission_number, s.class,
           a.full_name as issued_by_name
    FROM fin_receipts r
    JOIN fin_payments p ON r.payment_id = p.id
    JOIN students s ON p.student_id = s.id
    LEFT JOIN admin_users a ON r.issued_by = a.id
    WHERE $where_sql
    ORDER BY r.issued_at DESC
    LIMIT $offset, $per_page
");
$stmt->execute($params);
$receipts = $stmt->fetchAll();

// Get single receipt for view
$view_receipt = null;
if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $stmt = $pdo->prepare("
        SELECT r.*, 
               p.amount_paid, p.payment_date, p.payment_method, p.reference_number, p.notes,
               b.id as bill_id, b.description as bill_description, b.session, b.term,
               s.id as student_id, s.full_name as student_name, s.admission_number, s.class, s.parent_phone, s.parent_email,
               a.full_name as issued_by_name
        FROM fin_receipts r
        JOIN fin_payments p ON r.payment_id = p.id
        JOIN students s ON p.student_id = s.id
        LEFT JOIN fin_bills b ON p.bill_id = b.id
        LEFT JOIN admin_users a ON r.issued_by = a.id
        WHERE r.id = ? AND r.school_id = ?
    ");
    $stmt->execute([$_GET['view'], $school_id]);
    $view_receipt = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $school_name; ?> - Payment Receipts</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: <?php echo $secondary_color; ?>;
            --accent-color: #e74c3c;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #3498db;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --sidebar-width: 260px;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.1);
            --radius-sm: 8px;
            --radius-md: 12px;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            color: #333;
            min-height: 100vh;
            overflow-x: hidden;
        }

        .main-content {
            min-height: 100vh;
            padding: 20px;
            transition: var(--transition);
        }

        .mobile-menu-btn {
            position: fixed;
            top: 15px;
            right: 20px;
            z-index: 1001;
            width: 44px;
            height: 44px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 20px;
            cursor: pointer;
            display: none;
        }

        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .sidebar-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        @media (min-width: 768px) {

            .mobile-menu-btn,
            .sidebar-overlay {
                display: none;
            }

            .main-content {
                margin-left: var(--sidebar-width);
            }
        }

        @media (max-width: 767px) {
            .main-content {
                padding-top: 70px;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: var(--shadow-sm);
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .header-title p {
            color: #666;
            font-size: 0.85rem;
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

        /* Receipt View */
        .receipt-container {
            background: white;
            border-radius: var(--radius-md);
            padding: 30px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-md);
        }

        .receipt-header {
            text-align: center;
            padding-bottom: 20px;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--light-color);
        }

        .receipt-logo {
            max-width: 100px;
            margin-bottom: 10px;
        }

        .receipt-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-color);
            letter-spacing: 2px;
        }

        .receipt-subtitle {
            font-size: 0.7rem;
            color: #666;
        }

        .receipt-number {
            background: var(--light-color);
            padding: 8px 15px;
            border-radius: 20px;
            display: inline-block;
            font-weight: 600;
            margin: 10px 0;
        }

        .receipt-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .receipt-field {
            margin-bottom: 10px;
        }

        .receipt-field-label {
            font-size: 0.7rem;
            color: #666;
            margin-bottom: 3px;
        }

        .receipt-field-value {
            font-size: 0.9rem;
            font-weight: 500;
        }

        .receipt-amount {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: var(--radius-sm);
            padding: 15px;
            text-align: center;
            margin: 20px 0;
        }

        .receipt-amount .label {
            font-size: 0.7rem;
            color: #666;
        }

        .receipt-amount .value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--success-color);
        }

        .receipt-footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px dashed #ddd;
            text-align: center;
            font-size: 0.7rem;
            color: #999;
        }

        .btn-print {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            margin-right: 10px;
        }

        .btn-print:hover {
            opacity: 0.9;
        }

        /* Filter Bar */
        .filter-bar {
            background: white;
            padding: 15px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: flex-end;
            box-shadow: var(--shadow-sm);
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-size: 0.7rem;
            color: #666;
            margin-bottom: 3px;
        }

        .filter-group input,
        .filter-group select {
            padding: 6px 12px;
            border: 1px solid #ddd;
            border-radius: var(--radius-sm);
            font-size: 0.8rem;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 500;
            transition: var(--transition);
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-secondary {
            background: var(--secondary-color);
            color: white;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.7rem;
        }

        /* Table */
        .table-card {
            background: white;
            border-radius: var(--radius-md);
            padding: 20px;
            box-shadow: var(--shadow-sm);
        }

        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 12px 10px;
            text-align: left;
            border-bottom: 1px solid #eee;
            font-size: 0.8rem;
        }

        .data-table th {
            background: var(--light-color);
            font-weight: 600;
        }

        .data-table tr:hover {
            background: #f9f9f9;
        }

        .action-icon {
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.7rem;
        }

        .action-icon.view {
            background: var(--info-color);
            color: white;
        }

        .action-icon.print {
            background: var(--primary-color);
            color: white;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .page-link {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: var(--radius-sm);
            text-decoration: none;
            color: #666;
            transition: var(--transition);
        }

        .page-link.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .footer {
            text-align: center;
            padding: 20px;
            color: #666;
            font-size: 0.8rem;
            border-top: 1px solid var(--light-color);
            margin-top: 20px;
        }

        /* Print Styles */
        @media print {

            .sidebar,
            .mobile-menu-btn,
            .top-header .btn-secondary,
            .filter-bar,
            .table-card,
            .btn-print,
            .footer,
            .action-buttons,
            .no-print {
                display: none !important;
            }

            .main-content {
                margin: 0 !important;
                padding: 0 !important;
            }

            .receipt-container {
                box-shadow: none;
                padding: 0;
                margin: 0;
            }

            .receipt-header {
                border-bottom: 1px solid #000;
            }
        }

        @media (max-width: 767px) {
            .receipt-grid {
                grid-template-columns: 1fr;
            }

            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-group {
                width: 100%;
            }

            .filter-group input {
                width: 100%;
            }
        }
    </style>
</head>

<body>

    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Header -->
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-receipt" style="margin-right: 10px; color: var(--secondary-color);"></i>Payment Receipts</h1>
                <p>View, print, and manage payment receipts</p>
            </div>
            <div>
                <a href="finance_dashboard.php" class="btn btn-secondary btn-sm no-print">
                    <i class="fas fa-chart-line"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($view_receipt): ?>
            <!-- Single Receipt View -->
            <div class="receipt-container" id="receiptContainer">
                <div class="receipt-header">
                    <?php if (file_exists($_SERVER['DOCUMENT_ROOT'] . $school_logo)): ?>
                        <img src="<?php echo $school_logo; ?>" alt="School Logo" class="receipt-logo">
                    <?php endif; ?>
                    <div class="receipt-title"><?php echo strtoupper(htmlspecialchars($school_name)); ?></div>
                    <div class="receipt-subtitle"><?php echo htmlspecialchars($school_motto); ?></div>
                    <div class="receipt-subtitle"><?php echo htmlspecialchars($school_address); ?></div>
                    <div class="receipt-subtitle">📞 <?php echo htmlspecialchars($school_phone); ?> | ✉ <?php echo htmlspecialchars($school_email); ?></div>
                    <div class="receipt-number">
                        <i class="fas fa-hashtag"></i> Receipt No: <?php echo htmlspecialchars($view_receipt['receipt_number']); ?>
                    </div>
                </div>

                <div class="receipt-grid">
                    <div>
                        <div class="receipt-field">
                            <div class="receipt-field-label"><i class="fas fa-user-graduate"></i> Student Name</div>
                            <div class="receipt-field-value"><?php echo htmlspecialchars($view_receipt['student_name']); ?></div>
                        </div>
                        <div class="receipt-field">
                            <div class="receipt-field-label"><i class="fas fa-id-card"></i> Admission Number</div>
                            <div class="receipt-field-value"><?php echo htmlspecialchars($view_receipt['admission_number']); ?></div>
                        </div>
                        <div class="receipt-field">
                            <div class="receipt-field-label"><i class="fas fa-graduation-cap"></i> Class</div>
                            <div class="receipt-field-value"><?php echo htmlspecialchars($view_receipt['class']); ?></div>
                        </div>
                    </div>
                    <div>
                        <div class="receipt-field">
                            <div class="receipt-field-label"><i class="fas fa-calendar-alt"></i> Payment Date</div>
                            <div class="receipt-field-value"><?php echo date('l, F j, Y', strtotime($view_receipt['payment_date'])); ?></div>
                        </div>
                        <div class="receipt-field">
                            <div class="receipt-field-label"><i class="fas fa-credit-card"></i> Payment Method</div>
                            <div class="receipt-field-value"><?php echo ucfirst(str_replace('_', ' ', $view_receipt['payment_method'])); ?></div>
                        </div>
                        <?php if ($view_receipt['reference_number']): ?>
                            <div class="receipt-field">
                                <div class="receipt-field-label"><i class="fas fa-hashtag"></i> Reference Number</div>
                                <div class="receipt-field-value"><?php echo htmlspecialchars($view_receipt['reference_number']); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($view_receipt['bill_description']): ?>
                    <div class="receipt-field" style="margin-bottom: 15px;">
                        <div class="receipt-field-label"><i class="fas fa-file-invoice"></i> Bill Description</div>
                        <div class="receipt-field-value"><?php echo htmlspecialchars($view_receipt['bill_description']); ?></div>
                        <?php if ($view_receipt['session'] && $view_receipt['term']): ?>
                            <div style="font-size: 0.7rem; color: #666;"><?php echo htmlspecialchars($view_receipt['session'] . ' - ' . $view_receipt['term'] . ' Term'); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="receipt-amount">
                    <div class="label">Amount Paid</div>
                    <div class="value">₦<?php echo number_format($view_receipt['amount_paid'], 2); ?></div>
                    <div class="label" style="margin-top: 5px;">(<?php echo ucwords(str_replace('_', ' ', $view_receipt['payment_method'])); ?> Payment)</div>
                </div>

                <?php if ($view_receipt['notes']): ?>
                    <div class="receipt-field" style="margin-bottom: 15px;">
                        <div class="receipt-field-label"><i class="fas fa-sticky-note"></i> Notes</div>
                        <div class="receipt-field-value"><?php echo nl2br(htmlspecialchars($view_receipt['notes'])); ?></div>
                    </div>
                <?php endif; ?>

                <div class="receipt-footer">
                    <div>This is a computer-generated receipt and requires no signature.</div>
                    <div>Generated on: <?php echo date('F j, Y g:i A', strtotime($view_receipt['issued_at'])); ?></div>
                    <div>Issued by: <?php echo htmlspecialchars($view_receipt['issued_by_name'] ?? 'System'); ?></div>
                    <div style="margin-top: 10px;">Thank you for your payment!</div>
                </div>
            </div>

            <div style="text-align: center; margin-bottom: 24px;" class="no-print">
                <button onclick="window.print()" class="btn-print">
                    <i class="fas fa-print"></i> Print Receipt
                </button>
                <button onclick="window.location.href='finance_receipts.php'" class="btn btn-warning">
                    <i class="fas fa-arrow-left"></i> Back to List
                </button>
            </div>

        <?php else: ?>
            <!-- Filter Bar -->
            <div class="filter-bar no-print">
                <div class="filter-group">
                    <label>Receipt Number</label>
                    <input type="text" id="filter_receipt_no" placeholder="Search receipt #" value="<?php echo htmlspecialchars($filter_receipt_no); ?>">
                </div>
                <div class="filter-group">
                    <label>Student Name/Admission</label>
                    <input type="text" id="filter_student" placeholder="Search student..." value="<?php echo htmlspecialchars($filter_student); ?>">
                </div>
                <div class="filter-group">
                    <label>Date From</label>
                    <input type="date" id="filter_date_from" value="<?php echo $filter_date_from; ?>">
                </div>
                <div class="filter-group">
                    <label>Date To</label>
                    <input type="date" id="filter_date_to" value="<?php echo $filter_date_to; ?>">
                </div>
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <button onclick="applyFilters()" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
                </div>
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <button onclick="resetFilters()" class="btn btn-warning"><i class="fas fa-undo"></i> Reset</button>
                </div>
            </div>

            <!-- Receipts List Table -->
            <div class="table-card no-print">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="color: var(--primary-color); font-size: 1rem;">
                        <i class="fas fa-list"></i> Receipts Issued
                        <span style="font-size: 0.75rem; color: #666;">(<?php echo $total_records; ?> total)</span>
                    </h3>
                </div>

                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Receipt No.</th>
                                <th>Student</th>
                                <th>Class</th>
                                <th>Amount</th>
                                <th>Payment Date</th>
                                <th>Issued Date</th>
                                <th>Issued By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($receipts)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 40px; color: #999;">
                                        <i class="fas fa-receipt" style="font-size: 40px; margin-bottom: 10px; display: block;"></i>
                                        No receipts found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($receipts as $receipt): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($receipt['receipt_number']); ?></strong></td>
                                        <td>
                                            <?php echo htmlspecialchars($receipt['student_name']); ?><br>
                                            <small style="color: #999;"><?php echo htmlspecialchars($receipt['admission_number']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($receipt['class']); ?></td>
                                        <td style="font-weight: 600; color: var(--success-color);">₦<?php echo number_format($receipt['amount_paid'], 2); ?></td>
                                        <td><?php echo date('d M Y', strtotime($receipt['payment_date'])); ?></td>
                                        <td><?php echo date('d M Y', strtotime($receipt['issued_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($receipt['issued_by_name'] ?? 'System'); ?></td>
                                        <td>
                                            <div style="display: flex; gap: 8px;">
                                                <a href="?view=<?php echo $receipt['id']; ?>" class="action-icon view">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                                <a href="?view=<?php echo $receipt['id']; ?>" onclick="setTimeout(function(){window.print();}, 500); return true;" class="action-icon print">
                                                    <i class="fas fa-print"></i> Print
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&receipt_no=<?php echo urlencode($filter_receipt_no); ?>&student=<?php echo urlencode($filter_student); ?>&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>" class="page-link">&laquo; Prev</a>
                        <?php endif; ?>

                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&receipt_no=<?php echo urlencode($filter_receipt_no); ?>&student=<?php echo urlencode($filter_student); ?>&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&receipt_no=<?php echo urlencode($filter_receipt_no); ?>&student=<?php echo urlencode($filter_student); ?>&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>" class="page-link">Next &raquo;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="footer no-print">
            <p>&copy; <?php echo date('Y'); ?> <?php echo $school_name; ?> - Finance Management System</p>
        </div>
    </div>

    <script>
        // Mobile menu functionality
        document.addEventListener('DOMContentLoaded', function() {
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            const sidebarOverlay = document.getElementById('sidebarOverlay');

            setTimeout(function() {
                const sidebar = document.getElementById('sidebar');

                if (mobileMenuBtn && sidebar) {
                    mobileMenuBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        sidebar.classList.toggle('active');
                        if (sidebarOverlay) sidebarOverlay.classList.toggle('active');
                        document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
                    });
                }

                if (sidebarOverlay) {
                    sidebarOverlay.addEventListener('click', function() {
                        if (sidebar) sidebar.classList.remove('active');
                        sidebarOverlay.classList.remove('active');
                        document.body.style.overflow = '';
                    });
                }
            }, 100);
        });

        function applyFilters() {
            const receiptNo = document.getElementById('filter_receipt_no').value;
            const student = document.getElementById('filter_student').value;
            const dateFrom = document.getElementById('filter_date_from').value;
            const dateTo = document.getElementById('filter_date_to').value;

            let url = '?';
            if (receiptNo) url += 'receipt_no=' + encodeURIComponent(receiptNo) + '&';
            if (student) url += 'student=' + encodeURIComponent(student) + '&';
            if (dateFrom) url += 'date_from=' + dateFrom + '&';
            if (dateTo) url += 'date_to=' + dateTo + '&';

            window.location.href = url;
        }

        function resetFilters() {
            window.location.href = 'finance_receipts.php';
        }
    </script>

    <?php
    // Include sidebar
    require_once 'includes/sidebar.php';
    ?>
</body>

</html>
<?php
// tbis/admin/finance_payments.php - Manage Payments
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
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;

// Get current session
$current_session = date('Y') . '/' . (date('Y') + 1);

// Handle actions
$message = '';
$message_type = '';

// Helper function to post to ledger and income
function postToLedgerAndIncome($pdo, $school_id, $payment_id, $amount, $payment_date, $student_name, $admin_id)
{
    try {
        // Get or create income account for school fees
        $stmt = $pdo->prepare("SELECT id FROM fin_accounts WHERE school_id = ? AND account_name LIKE '%School Fees%' AND account_type = 'income' LIMIT 1");
        $stmt->execute([$school_id]);
        $income_account = $stmt->fetch();

        if (!$income_account) {
            // Create default income account
            $stmt = $pdo->prepare("INSERT INTO fin_accounts (school_id, account_name, account_type, opening_balance, current_balance, is_active) VALUES (?, 'School Fees Income', 'income', 0, 0, 1)");
            $stmt->execute([$school_id]);
            $income_account_id = $pdo->lastInsertId();
        } else {
            $income_account_id = $income_account['id'];
        }

        // Get cash account (default asset account)
        $stmt = $pdo->prepare("SELECT id FROM fin_accounts WHERE school_id = ? AND account_type = 'asset' LIMIT 1");
        $stmt->execute([$school_id]);
        $cash_account = $stmt->fetch();
        $cash_account_id = $cash_account ? $cash_account['id'] : null;

        if ($cash_account_id) {
            // Get current balances
            $stmt = $pdo->prepare("SELECT current_balance FROM fin_accounts WHERE id = ?");
            $stmt->execute([$cash_account_id]);
            $cash_balance = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT current_balance FROM fin_accounts WHERE id = ?");
            $stmt->execute([$income_account_id]);
            $income_balance = $stmt->fetchColumn();

            // Post debit to cash account
            $stmt = $pdo->prepare("
                INSERT INTO fin_ledger (school_id, account_id, entry_date, entry_type, amount, balance, description, ref_type, ref_id, posted_by, created_at)
                VALUES (?, ?, ?, 'debit', ?, ?, ?, 'payment', ?, ?, NOW())
            ");
            $new_cash_balance = $cash_balance + $amount;
            $stmt->execute([$school_id, $cash_account_id, $payment_date, $amount, $new_cash_balance, "Payment from " . $student_name, $payment_id, $admin_id]);

            // Update cash account balance
            $stmt = $pdo->prepare("UPDATE fin_accounts SET current_balance = current_balance + ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$amount, $cash_account_id]);

            // Post credit to income account
            $stmt = $pdo->prepare("
                INSERT INTO fin_ledger (school_id, account_id, entry_date, entry_type, amount, balance, description, ref_type, ref_id, posted_by, created_at)
                VALUES (?, ?, ?, 'credit', ?, ?, ?, 'payment', ?, ?, NOW())
            ");
            $new_income_balance = $income_balance - $amount;
            $stmt->execute([$school_id, $income_account_id, $payment_date, $amount, $new_income_balance, "School fees from " . $student_name, $payment_id, $admin_id]);

            // Update income account balance
            $stmt = $pdo->prepare("UPDATE fin_accounts SET current_balance = current_balance - ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$amount, $income_account_id]);

            return true;
        }
        return false;
    } catch (Exception $e) {
        error_log("Ledger posting error: " . $e->getMessage());
        return false;
    }
}

// Process POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Record new payment
        if ($_POST['action'] === 'record_payment') {
            $bill_id = intval($_POST['bill_id'] ?? 0);
            $student_id = intval($_POST['student_id'] ?? 0);
            $amount_paid = floatval($_POST['amount_paid'] ?? 0);
            $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
            $payment_method = $_POST['payment_method'] ?? 'cash';
            $reference_number = trim($_POST['reference_number'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            $account_id = !empty($_POST['account_id']) ? intval($_POST['account_id']) : null;

            if ($amount_paid <= 0) {
                $message = "Please enter a valid amount";
                $message_type = "error";
            } else {
                try {
                    // If bill_id is provided, get student_id from bill
                    if ($bill_id > 0) {
                        $stmt = $pdo->prepare("SELECT student_id, amount, amount_paid FROM fin_bills WHERE id = ? AND school_id = ?");
                        $stmt->execute([$bill_id, $school_id]);
                        $bill = $stmt->fetch();

                        if (!$bill) {
                            throw new Exception("Bill not found");
                        }
                        $student_id = $bill['student_id'];
                        $bill_amount = $bill['amount'];
                        $current_paid = $bill['amount_paid'];
                    } elseif ($student_id > 0) {
                        // Manual payment without specific bill
                        $bill_amount = 0;
                        $current_paid = 0;
                    } else {
                        throw new Exception("Please select either a bill or a student");
                    }

                    // Insert payment record
                    $stmt = $pdo->prepare("
                        INSERT INTO fin_payments (school_id, bill_id, student_id, amount_paid, payment_date, payment_method, reference_number, notes, account_id, status, recorded_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_verification', ?, NOW())
                    ");
                    $stmt->execute([$school_id, $bill_id > 0 ? $bill_id : null, $student_id, $amount_paid, $payment_date, $payment_method, $reference_number, $notes, $account_id, $admin_id]);

                    $payment_id = $pdo->lastInsertId();

                    // Auto-verify if payment method is cash or if amount is small
                    if ($payment_method === 'cash' || $amount_paid < 5000) {
                        // Auto-verify
                        $stmt = $pdo->prepare("
                            UPDATE fin_payments 
                            SET status = 'verified', verified_by = ?, verified_at = NOW() 
                            WHERE id = ? AND school_id = ?
                        ");
                        $stmt->execute([$admin_id, $payment_id, $school_id]);

                        // Update bill paid amount
                        if ($bill_id > 0) {
                            $new_paid = $current_paid + $amount_paid;
                            $new_status = ($new_paid >= $bill_amount) ? 'paid' : 'part_paid';
                            $stmt = $pdo->prepare("UPDATE fin_bills SET amount_paid = ?, status = ?, updated_at = NOW() WHERE id = ? AND school_id = ?");
                            $stmt->execute([$new_paid, $new_status, $bill_id, $school_id]);
                        }

                        // Get student name for receipt and ledger
                        $student_name = getStudentName($pdo, $student_id);

                        // Create receipt
                        $receipt_number = generateReceiptNumber($pdo, $school_id);
                        $stmt = $pdo->prepare("
                            INSERT INTO fin_receipts (school_id, payment_id, receipt_number, issued_to, issued_by, issued_at, amount)
                            VALUES (?, ?, ?, ?, ?, NOW(), ?)
                        ");
                        $stmt->execute([$school_id, $payment_id, $receipt_number, $student_name, $admin_id, $amount_paid]);

                        // Post to ledger and income accounts
                        $ledger_posted = postToLedgerAndIncome($pdo, $school_id, $payment_id, $amount_paid, $payment_date, $student_name, $admin_id);

                        if ($ledger_posted) {
                            $message = "Payment recorded and verified successfully! Receipt #$receipt_number";
                        } else {
                            $message = "Payment recorded and verified but ledger posting failed. Receipt #$receipt_number";
                        }
                        $message_type = "success";
                    } else {
                        $message = "Payment recorded successfully! Waiting for verification.";
                        $message_type = "success";
                    }
                } catch (Exception $e) {
                    $message = "Error recording payment: " . $e->getMessage();
                    $message_type = "error";
                }
            }
        }

        // Verify payment
        elseif ($_POST['action'] === 'verify_payment') {
            $payment_id = intval($_POST['payment_id']);

            try {
                // Get payment details
                $stmt = $pdo->prepare("
                    SELECT p.*, b.amount as bill_amount, b.amount_paid as current_paid, b.student_id, s.full_name as student_name
                    FROM fin_payments p
                    LEFT JOIN fin_bills b ON p.bill_id = b.id
                    LEFT JOIN students s ON p.student_id = s.id
                    WHERE p.id = ? AND p.school_id = ? AND p.status = 'pending_verification'
                ");
                $stmt->execute([$payment_id, $school_id]);
                $payment = $stmt->fetch();

                if (!$payment) {
                    throw new Exception("Payment not found or already verified");
                }

                // Update payment status
                $stmt = $pdo->prepare("
                    UPDATE fin_payments 
                    SET status = 'verified', verified_by = ?, verified_at = NOW() 
                    WHERE id = ? AND school_id = ?
                ");
                $stmt->execute([$admin_id, $payment_id, $school_id]);

                // Update bill if applicable
                if ($payment['bill_id']) {
                    $new_paid = $payment['current_paid'] + $payment['amount_paid'];
                    $new_status = ($new_paid >= $payment['bill_amount']) ? 'paid' : 'part_paid';
                    $stmt = $pdo->prepare("
                        UPDATE fin_bills 
                        SET amount_paid = ?, status = ?, updated_at = NOW() 
                        WHERE id = ? AND school_id = ?
                    ");
                    $stmt->execute([$new_paid, $new_status, $payment['bill_id'], $school_id]);
                }

                // Generate receipt
                $receipt_number = generateReceiptNumber($pdo, $school_id);
                $student_name = $payment['student_name'] ?? getStudentName($pdo, $payment['student_id']);
                $stmt = $pdo->prepare("
                    INSERT INTO fin_receipts (school_id, payment_id, receipt_number, issued_to, issued_by, issued_at, amount)
                    VALUES (?, ?, ?, ?, ?, NOW(), ?)
                ");
                $stmt->execute([$school_id, $payment_id, $receipt_number, $student_name, $admin_id, $payment['amount_paid']]);

                // Post to ledger and income accounts
                $ledger_posted = postToLedgerAndIncome($pdo, $school_id, $payment_id, $payment['amount_paid'], $payment['payment_date'], $student_name, $admin_id);

                if ($ledger_posted) {
                    $message = "Payment verified successfully! Receipt #$receipt_number generated and ledger updated.";
                } else {
                    $message = "Payment verified successfully! Receipt #$receipt_number generated but ledger posting failed.";
                }
                $message_type = "success";
            } catch (Exception $e) {
                $message = "Error verifying payment: " . $e->getMessage();
                $message_type = "error";
            }
        }

        // Reject payment
        elseif ($_POST['action'] === 'reject_payment') {
            $payment_id = intval($_POST['payment_id']);
            $rejection_reason = trim($_POST['rejection_reason'] ?? '');

            try {
                $stmt = $pdo->prepare("
                    UPDATE fin_payments 
                    SET status = 'rejected', rejection_reason = ? 
                    WHERE id = ? AND school_id = ? AND status = 'pending_verification'
                ");
                $stmt->execute([$rejection_reason, $payment_id, $school_id]);

                $message = "Payment rejected successfully!";
                $message_type = "success";
            } catch (Exception $e) {
                $message = "Error rejecting payment: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
}

// Helper functions
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

function getStudentName($pdo, $student_id)
{
    $stmt = $pdo->prepare("SELECT full_name FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();
    return $student ? $student['full_name'] : 'Unknown Student';
}

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Filters
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';
$filter_bill_id = isset($_GET['bill_id']) ? intval($_GET['bill_id']) : null;
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filter_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$filter_search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$where_clauses = ["p.school_id = ?"];
$params = [$school_id];

if ($filter_bill_id) {
    $where_clauses[] = "p.bill_id = ?";
    $params[] = $filter_bill_id;
}

if ($filter_status !== 'all') {
    $where_clauses[] = "p.status = ?";
    $params[] = $filter_status;
}

if ($filter_date_from) {
    $where_clauses[] = "p.payment_date >= ?";
    $params[] = $filter_date_from;
}

if ($filter_date_to) {
    $where_clauses[] = "p.payment_date <= ?";
    $params[] = $filter_date_to;
}

if (!empty($filter_search)) {
    $where_clauses[] = "(s.full_name LIKE ? OR s.admission_number LIKE ? OR p.reference_number LIKE ?)";
    $params[] = "%$filter_search%";
    $params[] = "%$filter_search%";
    $params[] = "%$filter_search%";
}

$where_sql = implode(" AND ", $where_clauses);

// Get total count
$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM fin_payments p
    JOIN students s ON p.student_id = s.id
    WHERE $where_sql
");
$stmt->execute($params);
$total_records = $stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// Get payments with student and bill info
$stmt = $pdo->prepare("
    SELECT p.*, 
           s.full_name as student_name, 
           s.admission_number,
           s.class,
           b.description as bill_description,
           b.amount as bill_amount,
           CONCAT(a.full_name, ' (', a.role, ')') as verified_by_name
    FROM fin_payments p
    JOIN students s ON p.student_id = s.id
    LEFT JOIN fin_bills b ON p.bill_id = b.id
    LEFT JOIN admin_users a ON p.verified_by = a.id
    WHERE $where_sql
    ORDER BY 
        CASE p.status 
            WHEN 'pending_verification' THEN 1
            ELSE 2
        END,
        p.payment_date DESC,
        p.created_at DESC
    LIMIT $offset, $per_page
");
$stmt->execute($params);
$payments = $stmt->fetchAll();

// Get statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_payments,
        COALESCE(SUM(CASE WHEN status = 'verified' THEN amount_paid ELSE 0 END), 0) as total_verified,
        COALESCE(SUM(CASE WHEN status = 'pending_verification' THEN amount_paid ELSE 0 END), 0) as total_pending,
        COALESCE(SUM(CASE WHEN status = 'rejected' THEN amount_paid ELSE 0 END), 0) as total_rejected,
        COUNT(CASE WHEN status = 'pending_verification' THEN 1 END) as pending_count
    FROM fin_payments p
    WHERE p.school_id = ?
");
$stmt->execute([$school_id]);
$stats = $stmt->fetch();

// Get bills for dropdown (only pending/part_paid)
$stmt = $pdo->prepare("
    SELECT b.id, b.description, s.full_name as student_name, b.amount, b.amount_paid, (b.amount - b.amount_paid) as balance
    FROM fin_bills b
    JOIN students s ON b.student_id = s.id
    WHERE b.school_id = ? AND b.status IN ('pending', 'part_paid')
    ORDER BY s.full_name
");
$stmt->execute([$school_id]);
$bills = $stmt->fetchAll();

// Get students for manual payment dropdown
$stmt = $pdo->prepare("SELECT id, full_name, admission_number, class FROM students WHERE school_id = ? AND status = 'active' ORDER BY full_name");
$stmt->execute([$school_id]);
$students = $stmt->fetchAll();

// Get specific bill if passed
$selected_bill = null;
if ($filter_bill_id) {
    $stmt = $pdo->prepare("
        SELECT b.*, s.full_name as student_name, s.admission_number, s.class
        FROM fin_bills b
        JOIN students s ON b.student_id = s.id
        WHERE b.id = ? AND b.school_id = ?
    ");
    $stmt->execute([$filter_bill_id, $school_id]);
    $selected_bill = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $school_name; ?> - Manage Payments</title>

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

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            border-left: 4px solid;
        }

        .stat-card.verified {
            border-left-color: var(--success-color);
        }

        .stat-card.pending {
            border-left-color: var(--warning-color);
        }

        .stat-card.rejected {
            border-left-color: var(--danger-color);
        }

        .stat-card.count {
            border-left-color: var(--info-color);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .stat-label {
            font-size: 0.75rem;
            color: #666;
            margin-top: 5px;
        }

        /* Form Styles */
        .form-card {
            background: white;
            border-radius: var(--radius-md);
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
        }

        .form-title {
            color: var(--primary-color);
            font-size: 1rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--light-color);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 0.8rem;
            font-weight: 500;
            margin-bottom: 5px;
            color: #555;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--secondary-color);
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            transition: var(--transition);
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--secondary-color);
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.7rem;
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
            align-items: center;
            box-shadow: var(--shadow-sm);
        }

        .filter-btn {
            padding: 6px 15px;
            border: 1px solid #ddd;
            border-radius: 20px;
            background: white;
            cursor: pointer;
            font-size: 0.8rem;
            transition: var(--transition);
            text-decoration: none;
            color: #666;
        }

        .filter-btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .filter-btn:hover:not(.active) {
            border-color: var(--secondary-color);
        }

        .search-box {
            display: flex;
            gap: 5px;
            margin-left: auto;
        }

        .search-box input {
            padding: 6px 12px;
            border: 1px solid #ddd;
            border-radius: 20px;
            font-size: 0.8rem;
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

        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
            display: inline-block;
        }

        .status-verified {
            background: #d5f4e6;
            color: var(--success-color);
        }

        .status-pending {
            background: #fef3c7;
            color: var(--warning-color);
        }

        .status-rejected {
            background: #f8d7da;
            color: var(--danger-color);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .action-icon {
            padding: 6px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.7rem;
        }

        .action-icon.verify {
            background: var(--success-color);
            color: white;
        }

        .action-icon.reject {
            background: var(--danger-color);
            color: white;
        }

        .action-icon.view {
            background: var(--info-color);
            color: white;
        }

        .bill-info-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
        }

        .bill-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
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

        .page-link:hover:not(.active) {
            border-color: var(--secondary-color);
        }

        /* Modal */
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
            max-width: 500px;
            width: 90%;
            padding: 20px;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--light-color);
        }

        .modal-body {
            margin-bottom: 20px;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .footer {
            text-align: center;
            padding: 20px;
            color: #666;
            font-size: 0.8rem;
            border-top: 1px solid var(--light-color);
            margin-top: 20px;
        }

        @media (max-width: 767px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }

            .data-table th,
            .data-table td {
                padding: 8px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .search-box {
                width: 100%;
                margin-left: 0;
            }

            .search-box input {
                flex: 1;
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
                <h1><i class="fas fa-money-bill-wave" style="margin-right: 10px; color: var(--secondary-color);"></i>Manage Payments</h1>
                <p>Record, verify, and track all payments</p>
            </div>
            <div>
                <a href="finance_dashboard.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-chart-line"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Message Display -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card verified">
                <div class="stat-value">₦<?php echo number_format($stats['total_verified'] ?? 0, 2); ?></div>
                <div class="stat-label">Total Verified Payments</div>
            </div>
            <div class="stat-card pending">
                <div class="stat-value">₦<?php echo number_format($stats['total_pending'] ?? 0, 2); ?></div>
                <div class="stat-label">Pending Verification</div>
            </div>
            <div class="stat-card rejected">
                <div class="stat-value">₦<?php echo number_format($stats['total_rejected'] ?? 0, 2); ?></div>
                <div class="stat-label">Rejected Payments</div>
            </div>
            <div class="stat-card count">
                <div class="stat-value"><?php echo $stats['pending_count'] ?? 0; ?></div>
                <div class="stat-label">Pending Items to Verify</div>
            </div>
        </div>

        <!-- Record Payment Form -->
        <div class="form-card">
            <div class="form-title">
                <i class="fas fa-plus-circle"></i> Record New Payment
            </div>

            <?php if ($selected_bill): ?>
                <div class="bill-info-card">
                    <div class="bill-info-grid">
                        <div>
                            <strong>Student:</strong> <?php echo htmlspecialchars($selected_bill['student_name']); ?><br>
                            <strong>Class:</strong> <?php echo htmlspecialchars($selected_bill['class']); ?><br>
                            <strong>Admission No:</strong> <?php echo htmlspecialchars($selected_bill['admission_number']); ?>
                        </div>
                        <div>
                            <strong>Bill:</strong> <?php echo htmlspecialchars($selected_bill['description']); ?><br>
                            <strong>Total Amount:</strong> ₦<?php echo number_format($selected_bill['amount'], 2); ?><br>
                            <strong>Balance:</strong> ₦<?php echo number_format($selected_bill['amount'] - $selected_bill['amount_paid'], 2); ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="paymentForm">
                <input type="hidden" name="action" value="record_payment">

                <div class="form-grid">
                    <div class="form-group">
                        <label>Select Bill (Optional)</label>
                        <select name="bill_id" id="bill_select" onchange="updateBillInfo(this)">
                            <option value="">-- Manual Payment (No Bill) --</option>
                            <?php foreach ($bills as $bill): ?>
                                <option value="<?php echo $bill['id']; ?>" <?php echo ($filter_bill_id == $bill['id']) ? 'selected' : ''; ?> data-student-id="<?php echo $bill['student_id']; ?>" data-balance="<?php echo $bill['balance']; ?>">
                                    <?php echo htmlspecialchars($bill['student_name'] . ' - ' . $bill['description'] . ' (Balance: ₦' . number_format($bill['balance'], 2) . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Student *</label>
                        <select name="student_id" id="student_select" required>
                            <option value="">-- Select Student --</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?php echo $student['id']; ?>">
                                    <?php echo htmlspecialchars($student['admission_number'] . ' - ' . $student['full_name'] . ' (' . $student['class'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Amount Paid (₦) *</label>
                        <input type="number" name="amount_paid" id="amount_paid" step="0.01" required placeholder="0.00">
                    </div>

                    <div class="form-group">
                        <label>Payment Date *</label>
                        <input type="date" name="payment_date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="form-group">
                        <label>Payment Method *</label>
                        <select name="payment_method" id="payment_method" required>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cheque">Cheque</option>
                            <option value="pos">POS</option>
                            <option value="online">Online Payment</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Reference Number</label>
                        <input type="text" name="reference_number" placeholder="Transaction/Cheque/Receipt No.">
                    </div>

                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" rows="3" placeholder="Additional payment notes..."></textarea>
                    </div>
                </div>

                <div style="margin-top: 20px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Record Payment
                    </button>
                    <?php if ($filter_bill_id): ?>
                        <a href="finance_payments.php" class="btn btn-warning">
                            <i class="fas fa-times"></i> Clear Bill Selection
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <span style="font-size: 0.8rem; color: #666;"><i class="fas fa-filter"></i> Filters:</span>
            <a href="?status=all&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&search=<?php echo urlencode($filter_search); ?>" class="filter-btn <?php echo $filter_status === 'all' ? 'active' : ''; ?>">All</a>
            <a href="?status=pending_verification&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&search=<?php echo urlencode($filter_search); ?>" class="filter-btn <?php echo $filter_status === 'pending_verification' ? 'active' : ''; ?>">Pending</a>
            <a href="?status=verified&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&search=<?php echo urlencode($filter_search); ?>" class="filter-btn <?php echo $filter_status === 'verified' ? 'active' : ''; ?>">Verified</a>
            <a href="?status=rejected&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&search=<?php echo urlencode($filter_search); ?>" class="filter-btn <?php echo $filter_status === 'rejected' ? 'active' : ''; ?>">Rejected</a>

            <form method="GET" class="search-box" style="display: flex; gap: 5px;">
                <input type="hidden" name="status" value="<?php echo $filter_status; ?>">
                <input type="date" name="date_from" placeholder="Date From" value="<?php echo $filter_date_from; ?>" style="padding: 6px 10px; border-radius: 20px; border: 1px solid #ddd;">
                <input type="date" name="date_to" placeholder="Date To" value="<?php echo $filter_date_to; ?>" style="padding: 6px 10px; border-radius: 20px; border: 1px solid #ddd;">
                <input type="text" name="search" placeholder="Search student or reference..." value="<?php echo htmlspecialchars($filter_search); ?>" style="padding: 6px 12px; border-radius: 20px; border: 1px solid #ddd;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
                <?php if ($filter_search || $filter_date_from || $filter_date_to): ?>
                    <a href="finance_payments.php?status=<?php echo $filter_status; ?>" class="btn btn-warning btn-sm">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Payments Table -->
        <div class="table-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h3 style="color: var(--primary-color); font-size: 1rem;">
                    <i class="fas fa-list"></i> Payment Transactions
                    <span style="font-size: 0.75rem; color: #666;">(<?php echo $total_records; ?> total)</span>
                </h3>
            </div>

            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Student</th>
                            <th>Bill Description</th>
                            <th>Method</th>
                            <th>Amount</th>
                            <th>Reference</th>
                            <th>Status</th>
                            <th>Verified By</th>
                            <th>Actions</th>
                    </thead>
                    <tbody>
                        <?php if (empty($payments)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 40px; color: #999;">
                                    <i class="fas fa-money-bill" style="font-size: 40px; margin-bottom: 10px; display: block;"></i>
                                    No payment records found
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?php echo date('d M Y', strtotime($payment['payment_date'])); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($payment['student_name']); ?></strong><br>
                                        <small style="color: #999;"><?php echo htmlspecialchars($payment['admission_number']); ?> | <?php echo htmlspecialchars($payment['class']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($payment['bill_description'] ?? 'Manual Payment'); ?></td>
                                    <td>
                                        <span class="status-badge status-pending" style="background: #e8e8e8;">
                                            <?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?>
                                        </span>
                                    </td>
                                    <td style="font-weight: 600; color: var(--success-color);">₦<?php echo number_format($payment['amount_paid'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($payment['reference_number'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php
                                        $status_class = '';
                                        $status_text = '';
                                        switch ($payment['status']) {
                                            case 'verified':
                                                $status_class = 'status-verified';
                                                $status_text = 'Verified';
                                                break;
                                            case 'pending_verification':
                                                $status_class = 'status-pending';
                                                $status_text = 'Pending';
                                                break;
                                            case 'rejected':
                                                $status_class = 'status-rejected';
                                                $status_text = 'Rejected';
                                                break;
                                        }
                                        ?>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($payment['verified_by_name']): ?>
                                            <?php echo htmlspecialchars($payment['verified_by_name']); ?><br>
                                            <small><?php echo date('d M Y', strtotime($payment['verified_at'])); ?></small>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($payment['status'] == 'pending_verification'): ?>
                                                <a href="#" onclick="showVerifyModal(<?php echo $payment['id']; ?>, '<?php echo htmlspecialchars($payment['student_name']); ?>', <?php echo $payment['amount_paid']; ?>)" class="action-icon verify">
                                                    <i class="fas fa-check-circle"></i> Verify
                                                </a>
                                                <a href="#" onclick="showRejectModal(<?php echo $payment['id']; ?>, '<?php echo htmlspecialchars($payment['student_name']); ?>', <?php echo $payment['amount_paid']; ?>)" class="action-icon reject">
                                                    <i class="fas fa-times-circle"></i> Reject
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($payment['status'] == 'verified'): ?>
                                                <a href="finance_receipts.php?payment_id=<?php echo $payment['id']; ?>" class="action-icon view">
                                                    <i class="fas fa-receipt"></i> View Receipt
                                                </a>
                                            <?php endif; ?>
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
                        <a href="?page=<?php echo $page - 1; ?>&status=<?php echo $filter_status; ?>&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&search=<?php echo urlencode($filter_search); ?>" class="page-link">&laquo; Prev</a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>&status=<?php echo $filter_status; ?>&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&search=<?php echo urlencode($filter_search); ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&status=<?php echo $filter_status; ?>&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&search=<?php echo urlencode($filter_search); ?>" class="page-link">Next &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo $school_name; ?> - Finance Management System</p>
        </div>
    </div>

    <!-- Verify Payment Modal -->
    <div id="verifyModal" class="modal">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="verify_payment">
                <input type="hidden" name="payment_id" id="verify_payment_id">

                <div class="modal-header">
                    <h3><i class="fas fa-check-circle"></i> Verify Payment</h3>
                    <button type="button" onclick="closeVerifyModal()" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
                </div>
                <div class="modal-body">
                    <p id="verify_payment_info" style="margin-bottom: 15px;"></p>
                    <p style="color: var(--success-color); font-size: 0.75rem; margin-top: 10px;">
                        <i class="fas fa-info-circle"></i> Verifying this payment will:
                    </p>
                    <ul style="margin-left: 20px; font-size: 0.75rem; color: #666;">
                        <li>Mark the payment as verified</li>
                        <li>Update the bill balance</li>
                        <li>Generate an official receipt</li>
                        <li>Post to ledger and income accounts</li>
                    </ul>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeVerifyModal()" class="btn btn-warning">Cancel</button>
                    <button type="submit" class="btn btn-success">Yes, Verify Payment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reject Payment Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="reject_payment">
                <input type="hidden" name="payment_id" id="reject_payment_id">

                <div class="modal-header">
                    <h3><i class="fas fa-times-circle"></i> Reject Payment</h3>
                    <button type="button" onclick="closeRejectModal()" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
                </div>
                <div class="modal-body">
                    <p id="reject_payment_info" style="margin-bottom: 15px;"></p>
                    <div class="form-group">
                        <label>Rejection Reason *</label>
                        <textarea name="rejection_reason" rows="3" required placeholder="Why is this payment being rejected?"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeRejectModal()" class="btn btn-warning">Cancel</button>
                    <button type="submit" class="btn btn-danger">Yes, Reject Payment</button>
                </div>
            </form>
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

        function updateBillInfo(select) {
            const selectedOption = select.options[select.selectedIndex];
            const studentId = selectedOption.dataset.studentId;
            const balance = selectedOption.dataset.balance;
            const studentSelect = document.getElementById('student_select');
            const amountInput = document.getElementById('amount_paid');

            if (studentId) {
                studentSelect.value = studentId;
                if (balance) {
                    amountInput.max = balance;
                    amountInput.placeholder = "Max: ₦" + parseFloat(balance).toLocaleString();
                }
            } else {
                studentSelect.value = '';
                amountInput.max = '';
                amountInput.placeholder = "0.00";
            }
        }

        function showVerifyModal(paymentId, studentName, amount) {
            document.getElementById('verify_payment_id').value = paymentId;
            document.getElementById('verify_payment_info').innerHTML =
                '<strong>Student:</strong> ' + studentName + '<br>' +
                '<strong>Amount:</strong> ₦' + amount.toLocaleString();
            document.getElementById('verifyModal').classList.add('active');
        }

        function closeVerifyModal() {
            document.getElementById('verifyModal').classList.remove('active');
        }

        function showRejectModal(paymentId, studentName, amount) {
            document.getElementById('reject_payment_id').value = paymentId;
            document.getElementById('reject_payment_info').innerHTML =
                '<strong>Student:</strong> ' + studentName + '<br>' +
                '<strong>Amount:</strong> ₦' + amount.toLocaleString();
            document.getElementById('rejectModal').classList.add('active');
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').classList.remove('active');
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const verifyModal = document.getElementById('verifyModal');
            const rejectModal = document.getElementById('rejectModal');
            if (event.target === verifyModal) {
                closeVerifyModal();
            }
            if (event.target === rejectModal) {
                closeRejectModal();
            }
        }

        // Auto-select student when bill is selected
        document.addEventListener('DOMContentLoaded', function() {
            const billSelect = document.getElementById('bill_select');
            if (billSelect && billSelect.value) {
                updateBillInfo(billSelect);
            }
        });
    </script>

    <?php
    // Include sidebar
    require_once 'includes/sidebar.php';
    ?>
</body>

</html>
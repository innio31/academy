<?php
// msv/admin/finance_bills.php - Manage Student Bills
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../includes/config.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /msv/login.php");
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

// Handle actions
$message = '';
$message_type = '';

// Process POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Record payment from modal
        if ($_POST['action'] === 'record_payment_modal') {
            $bill_id = intval($_POST['bill_id'] ?? 0);
            $student_id = intval($_POST['student_id'] ?? 0);
            $amount_paid = floatval($_POST['amount_paid'] ?? 0);
            $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
            $payment_method = $_POST['payment_method'] ?? 'cash';
            $reference_number = trim($_POST['reference_number'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            if ($amount_paid <= 0) {
                $message = "Please enter a valid amount";
                $message_type = "error";
            } else {
                try {
                    // Get bill details
                    $stmt = $pdo->prepare("
                        SELECT b.*, s.full_name as student_name, s.class, s.admission_number
                        FROM fin_bills b
                        JOIN students s ON b.student_id = s.id
                        WHERE b.id = ? AND b.school_id = ?
                    ");
                    $stmt->execute([$bill_id, $school_id]);
                    $bill = $stmt->fetch();

                    if (!$bill) {
                        throw new Exception("Bill not found");
                    }

                    $student_id = $bill['student_id'];
                    $bill_amount = $bill['amount'];
                    $current_paid = $bill['amount_paid'];
                    $student_name = $bill['student_name'];

                    // Insert payment record
                    $stmt = $pdo->prepare("
                        INSERT INTO fin_payments (school_id, bill_id, student_id, amount_paid, payment_date, payment_method, reference_number, notes, status, recorded_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'verified', ?, NOW())
                    ");
                    $stmt->execute([$school_id, $bill_id, $student_id, $amount_paid, $payment_date, $payment_method, $reference_number, $notes, $admin_id]);

                    $payment_id = $pdo->lastInsertId();

                    // Update bill paid amount
                    $new_paid = $current_paid + $amount_paid;
                    $new_status = ($new_paid >= $bill_amount) ? 'paid' : 'part_paid';
                    $stmt = $pdo->prepare("UPDATE fin_bills SET amount_paid = ?, status = ?, updated_at = NOW() WHERE id = ? AND school_id = ?");
                    $stmt->execute([$new_paid, $new_status, $bill_id, $school_id]);

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
                        $message = "Payment recorded successfully! Receipt #$receipt_number";
                    } else {
                        $message = "Payment recorded but ledger posting failed. Receipt #$receipt_number";
                    }
                    $message_type = "success";
                } catch (Exception $e) {
                    $message = "Error recording payment: " . $e->getMessage();
                    $message_type = "error";
                }
            }
        }

        // Update bill amount
        elseif ($_POST['action'] === 'update_amount' && isset($_POST['bill_id'])) {
            $bill_id = intval($_POST['bill_id']);
            $new_amount = floatval($_POST['amount']);
            $notes = trim($_POST['notes'] ?? '');

            if ($new_amount <= 0) {
                $message = "Invalid amount specified";
                $message_type = "error";
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE fin_bills SET amount = ?, updated_at = NOW() WHERE id = ? AND school_id = ?");
                    $stmt->execute([$new_amount, $bill_id, $school_id]);

                    $message = "Bill amount updated successfully!";
                    $message_type = "success";
                } catch (Exception $e) {
                    $message = "Error updating bill: " . $e->getMessage();
                    $message_type = "error";
                }
            }
        }

        // Cancel bill
        elseif ($_POST['action'] === 'cancel_bill' && isset($_POST['bill_id'])) {
            $bill_id = intval($_POST['bill_id']);
            $cancel_reason = trim($_POST['cancel_reason'] ?? '');

            try {
                $stmt = $pdo->prepare("UPDATE fin_bills SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND school_id = ? AND status IN ('pending', 'part_paid')");
                $stmt->execute([$bill_id, $school_id]);

                if ($stmt->rowCount() > 0) {
                    $message = "Bill cancelled successfully!";
                    $message_type = "success";
                } else {
                    $message = "Bill cannot be cancelled (already paid or cancelled)";
                    $message_type = "error";
                }
            } catch (Exception $e) {
                $message = "Error cancelling bill: " . $e->getMessage();
                $message_type = "error";
            }
        }

        // Add manual bill
        elseif ($_POST['action'] === 'add_manual_bill') {
            $student_id = intval($_POST['student_id']);
            $description = trim($_POST['description']);
            $amount = floatval($_POST['amount']);
            $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
            $session = trim($_POST['session'] ?? $current_session);
            $term = trim($_POST['term'] ?? 'First');

            if ($student_id <= 0 || empty($description) || $amount <= 0) {
                $message = "Please fill in all required fields";
                $message_type = "error";
            } else {
                try {
                    $stmt = $pdo->prepare("SELECT class, full_name FROM students WHERE id = ? AND school_id = ?");
                    $stmt->execute([$student_id, $school_id]);
                    $student = $stmt->fetch();

                    if (!$student) {
                        throw new Exception("Student not found");
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO fin_bills (school_id, student_id, class, session, term, description, amount, due_date, status, created_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
                    ");
                    $stmt->execute([$school_id, $student_id, $student['class'], $session, $term, $description, $amount, $due_date, $admin_id]);

                    $message = "Manual bill added successfully!";
                    $message_type = "success";
                } catch (Exception $e) {
                    $message = "Error adding bill: " . $e->getMessage();
                    $message_type = "error";
                }
            }
        }
    }
}

// Handle GET actions
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'delete' && isset($_GET['id'])) {
        $bill_id = intval($_GET['id']);

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM fin_payments WHERE bill_id = ? AND school_id = ?");
            $stmt->execute([$bill_id, $school_id]);
            $payment_count = $stmt->fetchColumn();

            if ($payment_count > 0) {
                $message = "Cannot delete bill with existing payments. Consider cancelling it instead.";
                $message_type = "error";
            } else {
                $stmt = $pdo->prepare("DELETE FROM fin_bills WHERE id = ? AND school_id = ? AND status = 'pending'");
                $stmt->execute([$bill_id, $school_id]);
                $message = "Bill deleted successfully!";
                $message_type = "success";
            }
        } catch (Exception $e) {
            $message = "Error deleting bill: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Filters
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';
$filter_class = isset($_GET['class']) ? $_GET['class'] : 'all';
$filter_term = isset($_GET['term']) ? $_GET['term'] : 'all';
$filter_session = isset($_GET['session']) ? $_GET['session'] : 'all';
$filter_search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$where_clauses = ["b.school_id = ?"];
$params = [$school_id];

if ($filter_status !== 'all') {
    $where_clauses[] = "b.status = ?";
    $params[] = $filter_status;
}

if ($filter_class !== 'all') {
    $where_clauses[] = "b.class = ?";
    $params[] = $filter_class;
}

if ($filter_term !== 'all') {
    $where_clauses[] = "b.term = ?";
    $params[] = $filter_term;
}

if ($filter_session !== 'all') {
    $where_clauses[] = "b.session = ?";
    $params[] = $filter_session;
}

if (!empty($filter_search)) {
    $where_clauses[] = "(s.full_name LIKE ? OR s.admission_number LIKE ? OR b.description LIKE ?)";
    $params[] = "%$filter_search%";
    $params[] = "%$filter_search%";
    $params[] = "%$filter_search%";
}

$where_sql = implode(" AND ", $where_clauses);

// Get total count
$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM fin_bills b
    JOIN students s ON b.student_id = s.id
    WHERE $where_sql
");
$stmt->execute($params);
$total_records = $stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// Get bills with student info
$stmt = $pdo->prepare("
    SELECT b.*, 
           s.full_name as student_name, 
           s.admission_number,
           s.class,
           s.profile_picture,
           (SELECT COALESCE(SUM(amount_paid), 0) FROM fin_payments WHERE bill_id = b.id AND status = 'verified') as total_paid
    FROM fin_bills b
    JOIN students s ON b.student_id = s.id
    WHERE $where_sql
    ORDER BY 
        CASE b.status 
            WHEN 'overdue' THEN 1
            WHEN 'pending' THEN 2
            WHEN 'part_paid' THEN 3
            WHEN 'paid' THEN 4
            ELSE 5
        END,
        b.due_date ASC,
        b.created_at DESC
    LIMIT $offset, $per_page
");
$stmt->execute($params);
$bills = $stmt->fetchAll();

// Get statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_bills,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN amount - amount_paid ELSE 0 END), 0) as total_pending,
        COALESCE(SUM(CASE WHEN status = 'part_paid' THEN amount - amount_paid ELSE 0 END), 0) as total_part_paid,
        COALESCE(SUM(CASE WHEN status = 'overdue' THEN amount - amount_paid ELSE 0 END), 0) as total_overdue,
        COALESCE(SUM(CASE WHEN status IN ('pending', 'part_paid') THEN amount - amount_paid ELSE 0 END), 0) as total_outstanding
    FROM fin_bills b
    WHERE b.school_id = ?
");
$stmt->execute([$school_id]);
$stats = $stmt->fetch();

// Get unique classes for filter
$stmt = $pdo->prepare("SELECT DISTINCT class FROM students WHERE school_id = ? AND status = 'active' AND class IS NOT NULL AND class != '' ORDER BY class");
$stmt->execute([$school_id]);
$available_classes = $stmt->fetchAll();

// Get students for manual bill dropdown
$stmt = $pdo->prepare("SELECT id, full_name, admission_number, class FROM students WHERE school_id = ? AND status = 'active' ORDER BY full_name");
$stmt->execute([$school_id]);
$students = $stmt->fetchAll();

// Get student info for editing (if edit parameter)
$edit_bill = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $pdo->prepare("
        SELECT b.*, s.full_name as student_name 
        FROM fin_bills b
        JOIN students s ON b.student_id = s.id
        WHERE b.id = ? AND b.school_id = ?
    ");
    $stmt->execute([$_GET['edit'], $school_id]);
    $edit_bill = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $school_name; ?> - Manage Bills</title>

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

        .stat-card.pending {
            border-left-color: var(--warning-color);
        }

        .stat-card.overdue {
            border-left-color: var(--danger-color);
        }

        .stat-card.outstanding {
            border-left-color: var(--info-color);
        }

        .stat-card.total {
            border-left-color: var(--primary-color);
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

        .status-paid {
            background: #d5f4e6;
            color: var(--success-color);
        }

        .status-pending {
            background: #fef3c7;
            color: var(--warning-color);
        }

        .status-part_paid {
            background: #ffe6b3;
            color: #b45f06;
        }

        .status-overdue {
            background: #f8d7da;
            color: var(--danger-color);
        }

        .status-cancelled {
            background: #e2e3e5;
            color: #6c757d;
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

        .action-icon.view {
            background: var(--info-color);
            color: white;
        }

        .action-icon.edit {
            background: var(--warning-color);
            color: white;
        }

        .action-icon.pay {
            background: var(--success-color);
            color: white;
        }

        .action-icon.cancel {
            background: var(--danger-color);
            color: white;
        }

        .action-icon.delete {
            background: #6c757d;
            color: white;
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: #eee;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 5px;
        }

        .progress-fill {
            height: 100%;
            background: var(--success-color);
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        /* Payment Modal Styles */
        .payment-modal-content {
            max-width: 550px;
        }

        .bill-preview {
            background: #f8f9fa;
            border-radius: var(--radius-sm);
            padding: 15px;
            margin-bottom: 20px;
        }

        .bill-preview h4 {
            color: var(--primary-color);
            font-size: 0.9rem;
            margin-bottom: 10px;
        }

        .bill-info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
            font-size: 0.8rem;
        }

        .bill-info-row:last-child {
            border-bottom: none;
        }

        .bill-info-label {
            font-weight: 600;
            color: #555;
        }

        .bill-info-value {
            color: #333;
        }

        .balance-amount {
            color: var(--success-color);
            font-weight: 700;
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

        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--light-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: var(--primary-color);
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
                <h1><i class="fas fa-file-invoice-dollar" style="margin-right: 10px; color: var(--secondary-color);"></i>Manage Bills</h1>
                <p>View and manage all student bills</p>
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
            <div class="stat-card pending">
                <div class="stat-value">₦<?php echo number_format($stats['total_pending'] ?? 0, 2); ?></div>
                <div class="stat-label">Pending Bills</div>
            </div>
            <div class="stat-card overdue">
                <div class="stat-value">₦<?php echo number_format($stats['total_overdue'] ?? 0, 2); ?></div>
                <div class="stat-label">Overdue Bills</div>
            </div>
            <div class="stat-card outstanding">
                <div class="stat-value">₦<?php echo number_format($stats['total_outstanding'] ?? 0, 2); ?></div>
                <div class="stat-label">Total Outstanding</div>
            </div>
            <div class="stat-card total">
                <div class="stat-value"><?php echo number_format($stats['total_bills'] ?? 0); ?></div>
                <div class="stat-label">Total Bills</div>
            </div>
        </div>

        <!-- Add Manual Bill Form -->
        <div class="form-card">
            <div class="form-title">
                <i class="fas fa-plus-circle"></i> Add Manual Bill
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_manual_bill">

                <div class="form-grid">
                    <div class="form-group">
                        <label>Student *</label>
                        <select name="student_id" required>
                            <option value="">-- Select Student --</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?php echo $student['id']; ?>">
                                    <?php echo htmlspecialchars($student['admission_number'] . ' - ' . $student['full_name'] . ' (' . $student['class'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Description *</label>
                        <input type="text" name="description" required placeholder="e.g., Library Fee, Sports Fee">
                    </div>

                    <div class="form-group">
                        <label>Amount (₦) *</label>
                        <input type="number" name="amount" step="0.01" required placeholder="0.00">
                    </div>

                    <div class="form-group">
                        <label>Session</label>
                        <select name="session">
                            <option value="2023/2024">2023/2024</option>
                            <option value="2024/2025">2024/2025</option>
                            <option value="2025/2026">2025/2026</option>
                            <option value="<?php echo $current_session; ?>" selected><?php echo $current_session; ?> (Current)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Term</label>
                        <select name="term">
                            <option value="First">First Term</option>
                            <option value="Second">Second Term</option>
                            <option value="Third">Third Term</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Due Date</label>
                        <input type="date" name="due_date">
                    </div>
                </div>

                <div style="margin-top: 20px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Add Bill
                    </button>
                </div>
            </form>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <span style="font-size: 0.8rem; color: #666;"><i class="fas fa-filter"></i> Filters:</span>
            <a href="?status=all&class=<?php echo $filter_class; ?>&term=<?php echo $filter_term; ?>&session=<?php echo $filter_session; ?>&search=<?php echo urlencode($filter_search); ?>" class="filter-btn <?php echo $filter_status === 'all' ? 'active' : ''; ?>">All</a>
            <a href="?status=pending&class=<?php echo $filter_class; ?>&term=<?php echo $filter_term; ?>&session=<?php echo $filter_session; ?>&search=<?php echo urlencode($filter_search); ?>" class="filter-btn <?php echo $filter_status === 'pending' ? 'active' : ''; ?>">Pending</a>
            <a href="?status=part_paid&class=<?php echo $filter_class; ?>&term=<?php echo $filter_term; ?>&session=<?php echo $filter_session; ?>&search=<?php echo urlencode($filter_search); ?>" class="filter-btn <?php echo $filter_status === 'part_paid' ? 'active' : ''; ?>">Part Paid</a>
            <a href="?status=overdue&class=<?php echo $filter_class; ?>&term=<?php echo $filter_term; ?>&session=<?php echo $filter_session; ?>&search=<?php echo urlencode($filter_search); ?>" class="filter-btn <?php echo $filter_status === 'overdue' ? 'active' : ''; ?>">Overdue</a>
            <a href="?status=paid&class=<?php echo $filter_class; ?>&term=<?php echo $filter_term; ?>&session=<?php echo $filter_session; ?>&search=<?php echo urlencode($filter_search); ?>" class="filter-btn <?php echo $filter_status === 'paid' ? 'active' : ''; ?>">Paid</a>

            <select onchange="window.location.href='?status=<?php echo $filter_status; ?>&term=<?php echo $filter_term; ?>&session='+this.value+'&class=<?php echo $filter_class; ?>&search=<?php echo urlencode($filter_search); ?>'" style="padding: 6px 10px; border-radius: 20px; border: 1px solid #ddd;">
                <option value="all" <?php echo $filter_session === 'all' ? 'selected' : ''; ?>>All Sessions</option>
                <option value="2023/2024" <?php echo $filter_session === '2023/2024' ? 'selected' : ''; ?>>2023/2024</option>
                <option value="2024/2025" <?php echo $filter_session === '2024/2025' ? 'selected' : ''; ?>>2024/2025</option>
                <option value="2025/2026" <?php echo $filter_session === '2025/2026' ? 'selected' : ''; ?>>2025/2026</option>
            </select>

            <select onchange="window.location.href='?status=<?php echo $filter_status; ?>&term='+this.value+'&session=<?php echo $filter_session; ?>&class=<?php echo $filter_class; ?>&search=<?php echo urlencode($filter_search); ?>'" style="padding: 6px 10px; border-radius: 20px; border: 1px solid #ddd;">
                <option value="all" <?php echo $filter_term === 'all' ? 'selected' : ''; ?>>All Terms</option>
                <option value="First" <?php echo $filter_term === 'First' ? 'selected' : ''; ?>>First Term</option>
                <option value="Second" <?php echo $filter_term === 'Second' ? 'selected' : ''; ?>>Second Term</option>
                <option value="Third" <?php echo $filter_term === 'Third' ? 'selected' : ''; ?>>Third Term</option>
            </select>

            <select onchange="window.location.href='?status=<?php echo $filter_status; ?>&term=<?php echo $filter_term; ?>&session=<?php echo $filter_session; ?>&class='+this.value+'&search=<?php echo urlencode($filter_search); ?>'" style="padding: 6px 10px; border-radius: 20px; border: 1px solid #ddd;">
                <option value="all" <?php echo $filter_class === 'all' ? 'selected' : ''; ?>>All Classes</option>
                <?php foreach ($available_classes as $class): ?>
                    <option value="<?php echo htmlspecialchars($class['class']); ?>" <?php echo $filter_class === $class['class'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($class['class']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <form method="GET" class="search-box" style="display: flex; gap: 5px; margin-left: auto;">
                <input type="hidden" name="status" value="<?php echo $filter_status; ?>">
                <input type="hidden" name="class" value="<?php echo $filter_class; ?>">
                <input type="hidden" name="term" value="<?php echo $filter_term; ?>">
                <input type="hidden" name="session" value="<?php echo $filter_session; ?>">
                <input type="text" name="search" placeholder="Search student or bill..." value="<?php echo htmlspecialchars($filter_search); ?>">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
                <?php if ($filter_search): ?>
                    <a href="?status=<?php echo $filter_status; ?>&class=<?php echo $filter_class; ?>&term=<?php echo $filter_term; ?>&session=<?php echo $filter_session; ?>" class="btn btn-warning btn-sm">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Bills Table -->
        <div class="table-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h3 style="color: var(--primary-color); font-size: 1rem;">
                    <i class="fas fa-list"></i> Student Bills
                    <span style="font-size: 0.75rem; color: #666;">(<?php echo $total_records; ?> total)</span>
                </h3>
            </div>

            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Description</th>
                            <th>Session/Term</th>
                            <th>Amount</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bills)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 40px; color: #999;">
                                    <i class="fas fa-file-invoice" style="font-size: 40px; margin-bottom: 10px; display: block;"></i>
                                    No bills found
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($bills as $bill): ?>
                                <?php
                                $balance = $bill['amount'] - $bill['total_paid'];
                                $paid_percentage = $bill['amount'] > 0 ? ($bill['total_paid'] / $bill['amount']) * 100 : 0;
                                $is_overdue = ($bill['due_date'] && $bill['due_date'] < date('Y-m-d') && $bill['status'] != 'paid' && $bill['status'] != 'cancelled');

                                // Update status to overdue if applicable
                                if ($is_overdue && $bill['status'] != 'overdue') {
                                    try {
                                        $updateStmt = $pdo->prepare("UPDATE fin_bills SET status = 'overdue' WHERE id = ? AND school_id = ?");
                                        $updateStmt->execute([$bill['id'], $school_id]);
                                        $bill['status'] = 'overdue';
                                    } catch (Exception $e) {
                                    }
                                }
                                ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div class="student-avatar">
                                                <?php echo strtoupper(substr($bill['student_name'], 0, 2)); ?>
                                            </div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($bill['student_name']); ?></strong><br>
                                                <small style="color: #999;"><?php echo htmlspecialchars($bill['admission_number']); ?> | <?php echo htmlspecialchars($bill['class']); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars(substr($bill['description'], 0, 50)); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($bill['session']); ?><br>
                                        <small><?php echo htmlspecialchars($bill['term']); ?> Term</small>
                                    </td>
                                    <td style="font-weight: 600;">₦<?php echo number_format($bill['amount'], 2); ?></td>
                                    <td>₦<?php echo number_format($bill['total_paid'], 2); ?></td>
                                    <td>
                                        ₦<?php echo number_format($balance, 2); ?>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $paid_percentage; ?>%;"></div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($bill['due_date']): ?>
                                            <?php echo date('d M Y', strtotime($bill['due_date'])); ?>
                                            <?php if ($is_overdue): ?>
                                                <br><small style="color: var(--danger-color);"><i class="fas fa-exclamation-circle"></i> Overdue</small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            Not set
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $status_class = '';
                                        $status_text = '';
                                        switch ($bill['status']) {
                                            case 'paid':
                                                $status_class = 'status-paid';
                                                $status_text = 'Paid';
                                                break;
                                            case 'pending':
                                                $status_class = 'status-pending';
                                                $status_text = 'Pending';
                                                break;
                                            case 'part_paid':
                                                $status_class = 'status-part_paid';
                                                $status_text = 'Part Paid';
                                                break;
                                            case 'overdue':
                                                $status_class = 'status-overdue';
                                                $status_text = 'Overdue';
                                                break;
                                            case 'cancelled':
                                                $status_class = 'status-cancelled';
                                                $status_text = 'Cancelled';
                                                break;
                                            default:
                                                $status_class = 'status-pending';
                                                $status_text = ucfirst($bill['status']);
                                        }
                                        ?>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="#" onclick="showPaymentModal(<?php echo $bill['id']; ?>, '<?php echo htmlspecialchars($bill['student_name']); ?>', '<?php echo htmlspecialchars($bill['description']); ?>', <?php echo $bill['amount']; ?>, <?php echo $bill['total_paid']; ?>, '<?php echo htmlspecialchars($bill['class']); ?>', '<?php echo htmlspecialchars($bill['admission_number']); ?>')" class="action-icon pay">
                                                <i class="fas fa-money-bill"></i> Pay
                                            </a>
                                            <a href="#" onclick="showEditModal(<?php echo $bill['id']; ?>, <?php echo $bill['amount']; ?>, '<?php echo htmlspecialchars($bill['description']); ?>')" class="action-icon edit">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <?php if ($bill['status'] != 'paid' && $bill['status'] != 'cancelled'): ?>
                                                <a href="#" onclick="showCancelModal(<?php echo $bill['id']; ?>, '<?php echo htmlspecialchars($bill['student_name']); ?>', '<?php echo htmlspecialchars($bill['description']); ?>')" class="action-icon cancel">
                                                    <i class="fas fa-ban"></i> Cancel
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($bill['status'] == 'pending' && $bill['total_paid'] == 0): ?>
                                                <a href="?action=delete&id=<?php echo $bill['id']; ?>" class="action-icon delete" onclick="return confirm('Are you sure you want to delete this bill? This cannot be undone.')">
                                                    <i class="fas fa-trash"></i> Delete
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
                        <a href="?page=<?php echo $page - 1; ?>&status=<?php echo $filter_status; ?>&class=<?php echo $filter_class; ?>&term=<?php echo $filter_term; ?>&session=<?php echo $filter_session; ?>&search=<?php echo urlencode($filter_search); ?>" class="page-link">&laquo; Prev</a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>&status=<?php echo $filter_status; ?>&class=<?php echo $filter_class; ?>&term=<?php echo $filter_term; ?>&session=<?php echo $filter_session; ?>&search=<?php echo urlencode($filter_search); ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&status=<?php echo $filter_status; ?>&class=<?php echo $filter_class; ?>&term=<?php echo $filter_term; ?>&session=<?php echo $filter_session; ?>&search=<?php echo urlencode($filter_search); ?>" class="page-link">Next &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo $school_name; ?> - Finance Management System</p>
        </div>
    </div>

    <!-- Payment Modal -->
    <div id="paymentModal" class="modal">
        <div class="modal-content payment-modal-content">
            <form method="POST" action="" id="paymentForm">
                <input type="hidden" name="action" value="record_payment_modal">
                <input type="hidden" name="bill_id" id="payment_bill_id">
                <input type="hidden" name="student_id" id="payment_student_id">

                <div class="modal-header">
                    <h3><i class="fas fa-money-bill-wave"></i> Record Payment</h3>
                    <button type="button" onclick="closePaymentModal()" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
                </div>
                <div class="modal-body">
                    <!-- Bill Preview Section -->
                    <div class="bill-preview" id="billPreview">
                        <h4><i class="fas fa-receipt"></i> Bill Details</h4>
                        <div class="bill-info-row">
                            <span class="bill-info-label">Student:</span>
                            <span class="bill-info-value" id="preview_student_name"></span>
                        </div>
                        <div class="bill-info-row">
                            <span class="bill-info-label">Admission No:</span>
                            <span class="bill-info-value" id="preview_admission_no"></span>
                        </div>
                        <div class="bill-info-row">
                            <span class="bill-info-label">Class:</span>
                            <span class="bill-info-value" id="preview_class"></span>
                        </div>
                        <div class="bill-info-row">
                            <span class="bill-info-label">Bill Description:</span>
                            <span class="bill-info-value" id="preview_description"></span>
                        </div>
                        <div class="bill-info-row">
                            <span class="bill-info-label">Total Amount:</span>
                            <span class="bill-info-value" id="preview_total_amount"></span>
                        </div>
                        <div class="bill-info-row">
                            <span class="bill-info-label">Already Paid:</span>
                            <span class="bill-info-value" id="preview_paid_amount"></span>
                        </div>
                        <div class="bill-info-row">
                            <span class="bill-info-label">Balance Due:</span>
                            <span class="bill-info-value balance-amount" id="preview_balance"></span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Amount Paid (₦) *</label>
                        <input type="number" name="amount_paid" id="payment_amount" step="0.01" required placeholder="Enter amount" onchange="validateAmount()">
                        <small id="amount_hint" style="color: #666; font-size: 0.7rem;"></small>
                    </div>

                    <div class="form-group">
                        <label>Payment Date *</label>
                        <input type="date" name="payment_date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="form-group">
                        <label>Payment Method *</label>
                        <select name="payment_method" required>
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
                        <textarea name="notes" rows="2" placeholder="Additional payment notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closePaymentModal()" class="btn btn-warning">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Record Payment
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Amount Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_amount">
                <input type="hidden" name="bill_id" id="edit_bill_id">

                <div class="modal-header">
                    <h3><i class="fas fa-edit"></i> Edit Bill Amount</h3>
                    <button type="button" onclick="closeEditModal()" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Bill Description</label>
                        <input type="text" id="edit_description" readonly style="background: #f5f5f5;">
                    </div>
                    <div class="form-group">
                        <label>New Amount (₦) *</label>
                        <input type="number" name="amount" id="edit_amount" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>Notes (Optional)</label>
                        <textarea name="notes" rows="3" placeholder="Reason for amount change..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeEditModal()" class="btn btn-warning">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Amount</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Cancel Bill Modal -->
    <div id="cancelModal" class="modal">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="cancel_bill">
                <input type="hidden" name="bill_id" id="cancel_bill_id">

                <div class="modal-header">
                    <h3><i class="fas fa-ban"></i> Cancel Bill</h3>
                    <button type="button" onclick="closeCancelModal()" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
                </div>
                <div class="modal-body">
                    <p id="cancel_bill_info" style="margin-bottom: 15px;"></p>
                    <div class="form-group">
                        <label>Reason for Cancellation</label>
                        <textarea name="cancel_reason" rows="3" placeholder="Enter reason for cancelling this bill..."></textarea>
                    </div>
                    <p style="color: var(--danger-color); font-size: 0.75rem; margin-top: 10px;">
                        <i class="fas fa-exclamation-triangle"></i> Warning: This action cannot be undone. The bill will be marked as cancelled.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeCancelModal()" class="btn btn-warning">No, Go Back</button>
                    <button type="submit" class="btn btn-danger">Yes, Cancel Bill</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Variables for payment modal
        let currentBillBalance = 0;

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

        // Payment Modal Functions
        function showPaymentModal(billId, studentName, description, totalAmount, paidAmount, studentClass, admissionNo) {
            const balance = totalAmount - paidAmount;
            currentBillBalance = balance;

            document.getElementById('payment_bill_id').value = billId;
            document.getElementById('preview_student_name').innerHTML = studentName;
            document.getElementById('preview_admission_no').innerHTML = admissionNo;
            document.getElementById('preview_class').innerHTML = studentClass;
            document.getElementById('preview_description').innerHTML = description;
            document.getElementById('preview_total_amount').innerHTML = '₦' + parseFloat(totalAmount).toLocaleString();
            document.getElementById('preview_paid_amount').innerHTML = '₦' + parseFloat(paidAmount).toLocaleString();
            document.getElementById('preview_balance').innerHTML = '₦' + balance.toLocaleString();

            const amountInput = document.getElementById('payment_amount');
            amountInput.value = '';
            amountInput.max = balance;
            amountInput.placeholder = 'Max: ₦' + balance.toLocaleString();

            document.getElementById('amount_hint').innerHTML = 'Maximum payable: ₦' + balance.toLocaleString();

            document.getElementById('paymentModal').classList.add('active');
        }

        function validateAmount() {
            const amountInput = document.getElementById('payment_amount');
            const amount = parseFloat(amountInput.value);

            if (amount > currentBillBalance) {
                alert('Amount cannot exceed the bill balance of ₦' + currentBillBalance.toLocaleString());
                amountInput.value = currentBillBalance;
            }
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').classList.remove('active');
        }

        function showEditModal(billId, currentAmount, description) {
            document.getElementById('edit_bill_id').value = billId;
            document.getElementById('edit_amount').value = currentAmount;
            document.getElementById('edit_description').value = description;
            document.getElementById('editModal').classList.add('active');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        function showCancelModal(billId, studentName, description) {
            document.getElementById('cancel_bill_id').value = billId;
            document.getElementById('cancel_bill_info').innerHTML =
                '<strong>Student:</strong> ' + studentName + '<br>' +
                '<strong>Bill:</strong> ' + description;
            document.getElementById('cancelModal').classList.add('active');
        }

        function closeCancelModal() {
            document.getElementById('cancelModal').classList.remove('active');
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const paymentModal = document.getElementById('paymentModal');
            const editModal = document.getElementById('editModal');
            const cancelModal = document.getElementById('cancelModal');
            if (event.target === paymentModal) {
                closePaymentModal();
            }
            if (event.target === editModal) {
                closeEditModal();
            }
            if (event.target === cancelModal) {
                closeCancelModal();
            }
        }
    </script>

    <?php
    // Include sidebar
    require_once 'includes/sidebar.php';
    ?>
</body>

</html>
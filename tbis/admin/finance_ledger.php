<?php
// tbis/admin/finance_ledger.php - General Ledger & Chart of Accounts
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

// Ensure default accounts exist
try {
    // Check if any accounts exist
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fin_accounts WHERE school_id = ?");
    $stmt->execute([$school_id]);
    $account_count = $stmt->fetchColumn();

    if ($account_count == 0) {
        // Create default chart of accounts
        $default_accounts = [
            ['Cash Account', 'asset', '1001', 'Main cash/bank account'],
            ['School Fees Income', 'income', '4001', 'Income from student school fees'],
            ['Other Income', 'income', '4002', 'Grants, donations, PTA levies'],
            ['Salaries Expense', 'expenditure', '5001', 'Staff salaries and wages'],
            ['Maintenance Expense', 'expenditure', '5002', 'School maintenance costs'],
            ['Stationery Expense', 'expenditure', '5003', 'Office and school supplies'],
            ['Utilities Expense', 'expenditure', '5004', 'Electricity, water, internet'],
            ['Accounts Receivable', 'asset', '1100', 'Student fee receivables'],
        ];

        $stmt = $pdo->prepare("
            INSERT INTO fin_accounts (school_id, account_name, account_type, account_code, opening_balance, current_balance, description, is_active, created_at)
            VALUES (?, ?, ?, ?, 0, 0, ?, 1, NOW())
        ");

        foreach ($default_accounts as $acc) {
            $stmt->execute([$school_id, $acc[0], $acc[1], $acc[2], $acc[3]]);
        }
    }
} catch (Exception $e) {
    error_log("Error creating default accounts: " . $e->getMessage());
}

// Handle actions
$message = '';
$message_type = '';

// Process POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Create new account
        if ($_POST['action'] === 'create_account') {
            $account_name = trim($_POST['account_name']);
            $account_type = $_POST['account_type'];
            $account_code = trim($_POST['account_code']);
            $opening_balance = floatval($_POST['opening_balance']);
            $description = trim($_POST['description']);

            if (empty($account_name)) {
                $message = "Account name is required";
                $message_type = "error";
            } else {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO fin_accounts (school_id, account_name, account_type, account_code, opening_balance, current_balance, description, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$school_id, $account_name, $account_type, $account_code, $opening_balance, $opening_balance, $description]);

                    $message = "Account created successfully!";
                    $message_type = "success";
                } catch (Exception $e) {
                    $message = "Error creating account: " . $e->getMessage();
                    $message_type = "error";
                }
            }
        }

        // Update account
        elseif ($_POST['action'] === 'update_account') {
            $account_id = intval($_POST['account_id']);
            $account_name = trim($_POST['account_name']);
            $account_type = $_POST['account_type'];
            $account_code = trim($_POST['account_code']);
            $description = trim($_POST['description']);
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if (empty($account_name)) {
                $message = "Account name is required";
                $message_type = "error";
            } else {
                try {
                    $stmt = $pdo->prepare("
                        UPDATE fin_accounts 
                        SET account_name = ?, account_type = ?, account_code = ?, description = ?, is_active = ?
                        WHERE id = ? AND school_id = ?
                    ");
                    $stmt->execute([$account_name, $account_type, $account_code, $description, $is_active, $account_id, $school_id]);

                    $message = "Account updated successfully!";
                    $message_type = "success";
                } catch (Exception $e) {
                    $message = "Error updating account: " . $e->getMessage();
                    $message_type = "error";
                }
            }
        }

        // Delete account
        elseif ($_POST['action'] === 'delete_account') {
            $account_id = intval($_POST['account_id']);

            try {
                // Check if account has transactions
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM fin_ledger WHERE account_id = ? AND school_id = ?
                ");
                $stmt->execute([$account_id, $school_id]);
                $has_transactions = $stmt->fetchColumn();

                if ($has_transactions > 0) {
                    $message = "Cannot delete account with existing transactions. Deactivate it instead.";
                    $message_type = "error";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM fin_accounts WHERE id = ? AND school_id = ?");
                    $stmt->execute([$account_id, $school_id]);
                    $message = "Account deleted successfully!";
                    $message_type = "success";
                }
            } catch (Exception $e) {
                $message = "Error deleting account: " . $e->getMessage();
                $message_type = "error";
            }
        }

        // Manual journal entry
        elseif ($_POST['action'] === 'add_journal_entry') {
            $entry_date = $_POST['entry_date'];
            $debit_account = intval($_POST['debit_account']);
            $credit_account = intval($_POST['credit_account']);
            $amount = floatval($_POST['amount']);
            $description = trim($_POST['description']);

            if ($amount <= 0) {
                $message = "Amount must be greater than zero";
                $message_type = "error";
            } elseif ($debit_account == $credit_account) {
                $message = "Debit and Credit accounts cannot be the same";
                $message_type = "error";
            } else {
                try {
                    // Start transaction
                    $pdo->beginTransaction();

                    // Get current balances
                    $stmt = $pdo->prepare("SELECT current_balance FROM fin_accounts WHERE id = ? AND school_id = ? FOR UPDATE");
                    $stmt->execute([$debit_account, $school_id]);
                    $debit_balance = $stmt->fetchColumn();

                    $stmt = $pdo->prepare("SELECT current_balance FROM fin_accounts WHERE id = ? AND school_id = ? FOR UPDATE");
                    $stmt->execute([$credit_account, $school_id]);
                    $credit_balance = $stmt->fetchColumn();

                    // Debit entry
                    $stmt = $pdo->prepare("
                        INSERT INTO fin_ledger (school_id, account_id, entry_date, entry_type, amount, balance, description, ref_type, posted_by, created_at)
                        VALUES (?, ?, ?, 'debit', ?, ?, ?, 'journal', ?, NOW())
                    ");
                    $stmt->execute([$school_id, $debit_account, $entry_date, $amount, $debit_balance + $amount, $description, $admin_id]);

                    // Credit entry
                    $stmt = $pdo->prepare("
                        INSERT INTO fin_ledger (school_id, account_id, entry_date, entry_type, amount, balance, description, ref_type, posted_by, created_at)
                        VALUES (?, ?, ?, 'credit', ?, ?, ?, 'journal', ?, NOW())
                    ");
                    $stmt->execute([$school_id, $credit_account, $entry_date, $amount, $credit_balance - $amount, $description, $admin_id]);

                    // Update account balances
                    $stmt = $pdo->prepare("UPDATE fin_accounts SET current_balance = current_balance + ? WHERE id = ? AND school_id = ?");
                    $stmt->execute([$amount, $debit_account, $school_id]);

                    $stmt = $pdo->prepare("UPDATE fin_accounts SET current_balance = current_balance - ? WHERE id = ? AND school_id = ?");
                    $stmt->execute([$amount, $credit_account, $school_id]);

                    // Add to cashflow
                    $stmt = $pdo->prepare("
                        INSERT INTO fin_cashflow (school_id, flow_date, flow_type, amount, balance_after, description, source_ref, created_at)
                        VALUES (?, ?, 'inflow', ?, (SELECT COALESCE(SUM(CASE WHEN flow_type = 'inflow' THEN amount ELSE -amount END), 0) + ? FROM fin_cashflow WHERE school_id = ?), ?, 'journal', NOW())
                    ");
                    $stmt->execute([$school_id, $entry_date, $amount, $amount, $school_id, $description]);

                    $pdo->commit();

                    $message = "Journal entry posted successfully!";
                    $message_type = "success";
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = "Error posting journal entry: " . $e->getMessage();
                    $message_type = "error";
                }
            }
        }
    }
}

// Get filters
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'accounts';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Account filter
$account_filter = isset($_GET['account']) ? intval($_GET['account']) : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Get all accounts
$stmt = $pdo->prepare("
    SELECT a.*, 
           (SELECT COUNT(*) FROM fin_ledger WHERE account_id = a.id AND school_id = a.school_id) as transaction_count
    FROM fin_accounts a
    WHERE a.school_id = ?
    ORDER BY 
        CASE a.account_type
            WHEN 'asset' THEN 1
            WHEN 'liability' THEN 2
            WHEN 'equity' THEN 3
            WHEN 'income' THEN 4
            WHEN 'expenditure' THEN 5
            ELSE 6
        END,
        a.account_name
");
$stmt->execute([$school_id]);
$accounts = $stmt->fetchAll();

// Calculate account totals
$account_totals = [
    'asset' => ['balance' => 0, 'count' => 0],
    'liability' => ['balance' => 0, 'count' => 0],
    'income' => ['balance' => 0, 'count' => 0],
    'expenditure' => ['balance' => 0, 'count' => 0],
    'equity' => ['balance' => 0, 'count' => 0]
];

foreach ($accounts as $acc) {
    if (isset($account_totals[$acc['account_type']])) {
        $account_totals[$acc['account_type']]['balance'] += $acc['current_balance'];
        $account_totals[$acc['account_type']]['count']++;
    }
}

// Get ledger entries for selected account
$ledger_entries = [];
$selected_account = null;

if ($account_filter > 0) {
    // Get account details
    $stmt = $pdo->prepare("SELECT * FROM fin_accounts WHERE id = ? AND school_id = ?");
    $stmt->execute([$account_filter, $school_id]);
    $selected_account = $stmt->fetch();

    if ($selected_account) {
        // Build query for ledger entries
        $where_clauses = ["l.account_id = ?", "l.school_id = ?"];
        $params = [$account_filter, $school_id];

        if ($date_from) {
            $where_clauses[] = "l.entry_date >= ?";
            $params[] = $date_from;
        }
        if ($date_to) {
            $where_clauses[] = "l.entry_date <= ?";
            $params[] = $date_to;
        }

        $where_sql = implode(" AND ", $where_clauses);

        // Get total count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM fin_ledger l WHERE $where_sql");
        $stmt->execute($params);
        $total_entries = $stmt->fetchColumn();
        $total_pages = ceil($total_entries / $per_page);

        // Get ledger entries with reference details
        $stmt = $pdo->prepare("
            SELECT l.*, 
                   a.account_name,
                   CASE 
                       WHEN l.ref_type = 'payment' THEN 'Student Payment'
                       WHEN l.ref_type = 'income' THEN 'Other Income'
                       WHEN l.ref_type = 'expenditure' THEN 'Expenditure'
                       WHEN l.ref_type = 'journal' THEN 'Journal Entry'
                       ELSE 'Manual'
                   END as entry_type_display,
                   p.receipt_number,
                   p.payment_method,
                   s.full_name as student_name
            FROM fin_ledger l
            JOIN fin_accounts a ON l.account_id = a.id
            LEFT JOIN fin_payments p ON l.ref_type = 'payment' AND l.ref_id = p.id
            LEFT JOIN students s ON p.student_id = s.id
            WHERE $where_sql
            ORDER BY l.entry_date DESC, l.created_at DESC
            LIMIT $offset, $per_page
        ");
        $stmt->execute($params);
        $ledger_entries = $stmt->fetchAll();
    } else {
        $total_entries = 0;
        $total_pages = 0;
    }
} else {
    $total_entries = 0;
    $total_pages = 0;
}

// Get trial balance data
$trial_balance = [];
$stmt = $pdo->prepare("
    SELECT a.account_name, a.account_type, a.account_code, a.current_balance
    FROM fin_accounts a
    WHERE a.school_id = ? AND a.is_active = 1
    ORDER BY 
        CASE a.account_type
            WHEN 'asset' THEN 1
            WHEN 'liability' THEN 2
            WHEN 'equity' THEN 3
            WHEN 'income' THEN 4
            WHEN 'expenditure' THEN 5
            ELSE 6
        END,
        a.account_name
");
$stmt->execute([$school_id]);
$trial_balance = $stmt->fetchAll();

// Calculate trial balance totals (assets + expenses = liabilities + equity + income)
$total_debits = 0;
$total_credits = 0;

foreach ($trial_balance as $tb) {
    if (in_array($tb['account_type'], ['asset', 'expenditure'])) {
        $total_debits += $tb['current_balance'];
    } else {
        $total_credits += $tb['current_balance'];
    }
}

// Get edit account data
$edit_account = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM fin_accounts WHERE id = ? AND school_id = ?");
    $stmt->execute([$_GET['edit'], $school_id]);
    $edit_account = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $school_name; ?> - General Ledger</title>

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

        /* Tabs */
        .tabs {
            display: flex;
            gap: 5px;
            margin-bottom: 20px;
            background: white;
            padding: 5px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            flex-wrap: wrap;
        }

        .tab-btn {
            flex: 1;
            padding: 12px;
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            border-radius: var(--radius-sm);
            transition: var(--transition);
        }

        .tab-btn.active {
            background: var(--primary-color);
            color: white;
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
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

        .btn-success {
            background: var(--success-color);
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

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.7rem;
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
            padding: 15px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            border-left: 4px solid;
        }

        .stat-card.asset {
            border-left-color: var(--success-color);
        }

        .stat-card.liability {
            border-left-color: var(--danger-color);
        }

        .stat-card.equity {
            border-left-color: var(--info-color);
        }

        .stat-card.income {
            border-left-color: var(--warning-color);
        }

        .stat-value {
            font-size: 1.2rem;
            font-weight: 700;
        }

        .stat-label {
            font-size: 0.7rem;
            color: #666;
            margin-top: 5px;
        }

        /* Account Cards */
        .account-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
            margin-bottom: 24px;
        }

        .account-card {
            background: white;
            border-radius: var(--radius-md);
            padding: 15px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .account-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .account-name {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .account-code {
            font-size: 0.7rem;
            color: #999;
        }

        .account-balance {
            font-size: 1.1rem;
            font-weight: 700;
            margin-top: 10px;
        }

        .account-type {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.6rem;
            margin-top: 5px;
        }

        .type-asset {
            background: #d5f4e6;
            color: var(--success-color);
        }

        .type-liability {
            background: #f8d7da;
            color: var(--danger-color);
        }

        .type-income {
            background: #fef3c7;
            color: var(--warning-color);
        }

        .type-expenditure {
            background: #e2e3e5;
            color: #6c757d;
        }

        .type-equity {
            background: #d1ecf1;
            color: var(--info-color);
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

        .debit {
            color: var(--danger-color);
            font-weight: 500;
        }

        .credit {
            color: var(--success-color);
            font-weight: 500;
        }

        .action-icon {
            padding: 5px 10px;
            border-radius: var(--radius-sm);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.7rem;
            cursor: pointer;
        }

        .action-icon.edit {
            background: var(--info-color);
            color: white;
        }

        .action-icon.delete {
            background: var(--danger-color);
            color: white;
        }

        .action-icon.view {
            background: var(--success-color);
            color: white;
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

        .filter-group select,
        .filter-group input {
            padding: 6px 12px;
            border: 1px solid #ddd;
            border-radius: var(--radius-sm);
            font-size: 0.8rem;
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

        @media (max-width: 767px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .account-grid {
                grid-template-columns: 1fr;
            }

            .tabs {
                flex-direction: column;
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
                <h1><i class="fas fa-book" style="margin-right: 10px; color: var(--secondary-color);"></i>General Ledger</h1>
                <p>Chart of accounts and double-entry ledger</p>
            </div>
            <div>
                <a href="finance_dashboard.php" class="btn btn-warning btn-sm">
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

        <!-- Account Summary Stats -->
        <div class="stats-grid">
            <div class="stat-card asset">
                <div class="stat-value">₦<?php echo number_format($account_totals['asset']['balance'], 2); ?></div>
                <div class="stat-label">Total Assets (<?php echo $account_totals['asset']['count']; ?> accounts)</div>
            </div>
            <div class="stat-card liability">
                <div class="stat-value">₦<?php echo number_format($account_totals['liability']['balance'], 2); ?></div>
                <div class="stat-label">Total Liabilities (<?php echo $account_totals['liability']['count']; ?> accounts)</div>
            </div>
            <div class="stat-card equity">
                <div class="stat-value">₦<?php echo number_format($account_totals['equity']['balance'], 2); ?></div>
                <div class="stat-label">Total Equity (<?php echo $account_totals['equity']['count']; ?> accounts)</div>
            </div>
            <div class="stat-card income">
                <div class="stat-value">₦<?php echo number_format($account_totals['income']['balance'], 2); ?></div>
                <div class="stat-label">Total Income (<?php echo $account_totals['income']['count']; ?> accounts)</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab-btn <?php echo $active_tab === 'accounts' ? 'active' : ''; ?>" onclick="switchTab('accounts')">
                <i class="fas fa-chart-bar"></i> Chart of Accounts
            </button>
            <button class="tab-btn <?php echo $active_tab === 'ledger' ? 'active' : ''; ?>" onclick="switchTab('ledger')">
                <i class="fas fa-list-ol"></i> Ledger Entries
            </button>
            <button class="tab-btn <?php echo $active_tab === 'trial' ? 'active' : ''; ?>" onclick="switchTab('trial')">
                <i class="fas fa-balance-scale"></i> Trial Balance
            </button>
            <button class="tab-btn <?php echo $active_tab === 'journal' ? 'active' : ''; ?>" onclick="switchTab('journal')">
                <i class="fas fa-pen-alt"></i> Journal Entry
            </button>
        </div>

        <!-- Chart of Accounts Tab -->
        <div id="accountsTab" class="tab-pane <?php echo $active_tab === 'accounts' ? 'active' : ''; ?>">
            <!-- Create Account Form -->
            <div class="form-card">
                <div class="form-title">
                    <i class="fas fa-plus-circle"></i> <?php echo $edit_account ? 'Edit Account' : 'Create New Account'; ?>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="<?php echo $edit_account ? 'update_account' : 'create_account'; ?>">
                    <?php if ($edit_account): ?>
                        <input type="hidden" name="account_id" value="<?php echo $edit_account['id']; ?>">
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Account Name *</label>
                            <input type="text" name="account_name" required value="<?php echo $edit_account ? htmlspecialchars($edit_account['account_name']) : ''; ?>" placeholder="e.g., School Fees Account">
                        </div>

                        <div class="form-group">
                            <label>Account Type *</label>
                            <select name="account_type" required>
                                <option value="asset" <?php echo ($edit_account && $edit_account['account_type'] == 'asset') ? 'selected' : ''; ?>>Asset</option>
                                <option value="liability" <?php echo ($edit_account && $edit_account['account_type'] == 'liability') ? 'selected' : ''; ?>>Liability</option>
                                <option value="income" <?php echo ($edit_account && $edit_account['account_type'] == 'income') ? 'selected' : ''; ?>>Income</option>
                                <option value="expenditure" <?php echo ($edit_account && $edit_account['account_type'] == 'expenditure') ? 'selected' : ''; ?>>Expenditure</option>
                                <option value="equity" <?php echo ($edit_account && $edit_account['account_type'] == 'equity') ? 'selected' : ''; ?>>Equity</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Account Code</label>
                            <input type="text" name="account_code" value="<?php echo $edit_account ? htmlspecialchars($edit_account['account_code']) : ''; ?>" placeholder="e.g., 1001">
                        </div>

                        <?php if (!$edit_account): ?>
                            <div class="form-group">
                                <label>Opening Balance (₦)</label>
                                <input type="number" name="opening_balance" step="0.01" value="0.00">
                            </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label>Description</label>
                            <textarea name="description" rows="3" placeholder="Account description..."><?php echo $edit_account ? htmlspecialchars($edit_account['description'] ?? '') : ''; ?></textarea>
                        </div>

                        <?php if ($edit_account): ?>
                            <div class="form-group" style="flex-direction: row; align-items: center; gap: 10px;">
                                <input type="checkbox" name="is_active" id="is_active" value="1" <?php echo $edit_account['is_active'] ? 'checked' : ''; ?>>
                                <label for="is_active">Active</label>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top: 20px;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?php echo $edit_account ? 'Update Account' : 'Create Account'; ?>
                        </button>
                        <?php if ($edit_account): ?>
                            <a href="finance_ledger.php?tab=accounts" class="btn btn-warning">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Accounts List -->
            <div class="account-grid">
                <?php foreach ($accounts as $acc): ?>
                    <div class="account-card">
                        <div class="account-name">
                            <?php echo htmlspecialchars($acc['account_name']); ?>
                            <?php if ($acc['account_code']): ?>
                                <span class="account-code">(<?php echo htmlspecialchars($acc['account_code']); ?>)</span>
                            <?php endif; ?>
                        </div>
                        <span class="account-type type-<?php echo $acc['account_type']; ?>">
                            <?php echo ucfirst($acc['account_type']); ?>
                        </span>
                        <div class="account-balance">
                            ₦<?php echo number_format($acc['current_balance'], 2); ?>
                        </div>
                        <div style="margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap;">
                            <a href="?tab=ledger&account=<?php echo $acc['id']; ?>" class="action-icon view">
                                <i class="fas fa-list"></i> View Ledger
                            </a>
                            <a href="?tab=accounts&edit=<?php echo $acc['id']; ?>" class="action-icon edit">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <?php if ($acc['transaction_count'] == 0): ?>
                                <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Delete this account?')">
                                    <input type="hidden" name="action" value="delete_account">
                                    <input type="hidden" name="account_id" value="<?php echo $acc['id']; ?>">
                                    <button type="submit" class="action-icon delete"><i class="fas fa-trash"></i> Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Ledger Entries Tab -->
        <div id="ledgerTab" class="tab-pane <?php echo $active_tab === 'ledger' ? 'active' : ''; ?>">
            <!-- Account Selector -->
            <div class="filter-bar">
                <div class="filter-group">
                    <label>Select Account</label>
                    <select id="account_select" onchange="window.location.href='?tab=ledger&account='+this.value">
                        <option value="0">-- Select Account --</option>
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?php echo $acc['id']; ?>" <?php echo $account_filter == $acc['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($acc['account_name']); ?> - ₦<?php echo number_format($acc['current_balance'], 2); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Date From</label>
                    <input type="date" id="ledger_date_from" value="<?php echo $date_from; ?>">
                </div>
                <div class="filter-group">
                    <label>Date To</label>
                    <input type="date" id="ledger_date_to" value="<?php echo $date_to; ?>">
                </div>
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <button onclick="applyLedgerFilters()" class="btn btn-primary btn-sm">Apply Filters</button>
                </div>
            </div>

            <?php if ($selected_account): ?>
                <div class="table-card">
                    <div style="margin-bottom: 15px;">
                        <h3 style="color: var(--primary-color);">
                            <?php echo htmlspecialchars($selected_account['account_name']); ?>
                            <span style="font-size: 0.8rem; color: #666;">(<?php echo ucfirst($selected_account['account_type']); ?>)</span>
                        </h3>
                        <p>Current Balance: <strong>₦<?php echo number_format($selected_account['current_balance'], 2); ?></strong></p>
                    </div>

                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Description</th>
                                    <th>Reference</th>
                                    <th>Debit (₦)</th>
                                    <th>Credit (₦)</th>
                                    <th>Balance (₦)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($ledger_entries)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 40px; color: #999;">
                                            <i class="fas fa-book-open" style="font-size: 40px; margin-bottom: 10px; display: block;"></i>
                                            No ledger entries found
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($ledger_entries as $entry): ?>
                                        <tr>
                                            <td><?php echo date('d M Y', strtotime($entry['entry_date'])); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($entry['description']); ?>
                                                <?php if ($entry['student_name']): ?>
                                                    <br><small class="status-badge" style="background: #e8e8e8; padding: 2px 8px; border-radius: 12px; font-size: 0.65rem;">
                                                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($entry['student_name']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-badge" style="background: #e8e8e8; padding: 2px 8px; border-radius: 12px; font-size: 0.7rem;">
                                                    <?php echo $entry['entry_type_display']; ?>
                                                </span>
                                                <?php if ($entry['receipt_number']): ?>
                                                    <br><small>Receipt: <?php echo $entry['receipt_number']; ?></small>
                                                <?php endif; ?>
                                                <?php if ($entry['payment_method']): ?>
                                                    <br><small>Method: <?php echo ucfirst($entry['payment_method']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="debit">
                                                <?php echo $entry['entry_type'] == 'debit' ? '₦' . number_format($entry['amount'], 2) : '-'; ?>
                                            </td>
                                            <td class="credit">
                                                <?php echo $entry['entry_type'] == 'credit' ? '₦' . number_format($entry['amount'], 2) : '-'; ?>
                                            </td>
                                            <td>₦<?php echo number_format($entry['balance'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?tab=ledger&account=<?php echo $account_filter; ?>&page=<?php echo $i; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-card" style="text-align: center; padding: 40px;">
                    <i class="fas fa-hand-point-left" style="font-size: 48px; color: #999; margin-bottom: 15px;"></i>
                    <p>Select an account from the dropdown above to view its ledger entries.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Trial Balance Tab -->
        <div id="trialTab" class="tab-pane <?php echo $active_tab === 'trial' ? 'active' : ''; ?>">
            <div class="table-card">
                <div class="form-title">
                    <i class="fas fa-balance-scale"></i> Trial Balance
                    <span style="font-size: 0.7rem; color: #666; margin-left: 10px;">As at <?php echo date('F j, Y'); ?></span>
                </div>

                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Account Name</th>
                                <th>Account Type</th>
                                <th>Debit (₦)</th>
                                <th>Credit (₦)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($trial_balance)): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; padding: 40px; color: #999;">No accounts found</td>
                                </tr>
                            <?php else: ?>
                                <?php
                                $running_debits = 0;
                                $running_credits = 0;
                                foreach ($trial_balance as $tb):
                                    if (in_array($tb['account_type'], ['asset', 'expenditure'])) {
                                        $debit_balance = $tb['current_balance'];
                                        $credit_balance = 0;
                                        $running_debits += $debit_balance;
                                    } else {
                                        $debit_balance = 0;
                                        $credit_balance = $tb['current_balance'];
                                        $running_credits += $credit_balance;
                                    }
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($tb['account_name']); ?>
                                            <?php if ($tb['account_code']): ?>
                                                <br><small style="color: #999;">(<?php echo htmlspecialchars($tb['account_code']); ?>)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="account-type type-<?php echo $tb['account_type']; ?>"><?php echo ucfirst($tb['account_type']); ?></span></td>
                                        <td class="debit"><?php echo $debit_balance > 0 ? '₦' . number_format($debit_balance, 2) : '-'; ?></td>
                                        <td class="credit"><?php echo $credit_balance > 0 ? '₦' . number_format($credit_balance, 2) : '-'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot style="background: var(--light-color); font-weight: 600;">
                            <tr>
                                <td colspan="2"><strong>Totals</strong></td>
                                <td class="debit"><strong>₦<?php echo number_format($running_debits ?? 0, 2); ?></strong></td>
                                <td class="credit"><strong>₦<?php echo number_format($running_credits ?? 0, 2); ?></strong></td>
                            </tr>
                            <tr>
                                <td colspan="4" style="text-align: center;">
                                    <?php if (abs(($running_debits ?? 0) - ($running_credits ?? 0)) < 0.01): ?>
                                        <span style="color: var(--success-color);"><i class="fas fa-check-circle"></i> Trial Balance is BALANCED ✓</span>
                                    <?php else: ?>
                                        <span style="color: var(--danger-color);"><i class="fas fa-exclamation-triangle"></i> Trial Balance is OUT OF BALANCE by ₦<?php echo number_format(abs(($running_debits ?? 0) - ($running_credits ?? 0)), 2); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="alert" style="margin-top: 20px; background: #e8f4fd; color: var(--info-color);">
                    <i class="fas fa-info-circle"></i>
                    <div style="font-size: 0.75rem;">
                        <strong>Accounting Equation:</strong> Assets (₦<?php echo number_format($account_totals['asset']['balance'], 2); ?>) = Liabilities (₦<?php echo number_format($account_totals['liability']['balance'], 2); ?>) + Equity (₦<?php echo number_format($account_totals['equity']['balance'], 2); ?>)
                    </div>
                </div>
            </div>
        </div>

        <!-- Journal Entry Tab -->
        <div id="journalTab" class="tab-pane <?php echo $active_tab === 'journal' ? 'active' : ''; ?>">
            <div class="form-card">
                <div class="form-title">
                    <i class="fas fa-pen-alt"></i> Manual Journal Entry
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add_journal_entry">

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Entry Date *</label>
                            <input type="date" name="entry_date" required value="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div class="form-group">
                            <label>Debit Account *</label>
                            <select name="debit_account" required>
                                <option value="">-- Select Account --</option>
                                <?php foreach ($accounts as $acc): ?>
                                    <option value="<?php echo $acc['id']; ?>">
                                        <?php echo htmlspecialchars($acc['account_name']); ?> (<?php echo ucfirst($acc['account_type']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Credit Account *</label>
                            <select name="credit_account" required>
                                <option value="">-- Select Account --</option>
                                <?php foreach ($accounts as $acc): ?>
                                    <option value="<?php echo $acc['id']; ?>">
                                        <?php echo htmlspecialchars($acc['account_name']); ?> (<?php echo ucfirst($acc['account_type']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Amount (₦) *</label>
                            <input type="number" name="amount" step="0.01" required placeholder="0.00">
                        </div>

                        <div class="form-group">
                            <label>Description *</label>
                            <textarea name="description" rows="3" required placeholder="Explain the reason for this journal entry..."></textarea>
                        </div>
                    </div>

                    <div style="margin-top: 20px;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Post Journal Entry
                        </button>
                    </div>
                </form>
            </div>

            <div class="alert" style="background: #fff3cd; color: #856404;">
                <i class="fas fa-exclamation-triangle"></i>
                <div style="font-size: 0.8rem;">
                    <strong>Note:</strong> Journal entries are for manual adjustments only.
                    For regular income/expenses, use the Income & Expenditure section.
                    For student payments, use the Payments section. Journal entries affect account balances directly.
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
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

        function switchTab(tab) {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tab);
            url.searchParams.delete('page');
            url.searchParams.delete('account');
            window.location.href = url.toString();
        }

        function applyLedgerFilters() {
            const dateFrom = document.getElementById('ledger_date_from').value;
            const dateTo = document.getElementById('ledger_date_to').value;
            const account = <?php echo $account_filter; ?>;

            let url = '?tab=ledger&account=' + account;
            if (dateFrom) url += '&date_from=' + dateFrom;
            if (dateTo) url += '&date_to=' + dateTo;

            window.location.href = url;
        }
    </script>

    <?php
    // Include sidebar
    require_once 'includes/sidebar.php';
    ?>
</body>

</html>
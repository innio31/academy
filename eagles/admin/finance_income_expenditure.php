<?php
// eagles/admin/finance_income_expenditure.php - Manage Income & Expenditure
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../includes/config.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /eagles/login.php");
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

// Process POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Add Income
        if ($_POST['action'] === 'add_income') {
            $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
            $account_id = !empty($_POST['account_id']) ? intval($_POST['account_id']) : null;
            $income_date = $_POST['income_date'] ?? date('Y-m-d');
            $source = trim($_POST['source']);
            $description = trim($_POST['description']);
            $amount = floatval($_POST['amount']);
            $session = trim($_POST['session'] ?? $current_session);
            $term = trim($_POST['term'] ?? 'First');
            $reference = trim($_POST['reference'] ?? '');
            $proof_path = null;

            if (empty($source) || $amount <= 0) {
                $message = "Please fill in source and amount";
                $message_type = "error";
            } else {
                try {
                    // Handle file upload
                    if (isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] == 0) {
                        $upload_dir = '../uploads/finance/';
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        $file_ext = pathinfo($_FILES['proof_file']['name'], PATHINFO_EXTENSION);
                        $file_name = 'income_' . time() . '_' . uniqid() . '.' . $file_ext;
                        $upload_path = $upload_dir . $file_name;
                        if (move_uploaded_file($_FILES['proof_file']['tmp_name'], $upload_path)) {
                            $proof_path = '/uploads/finance/' . $file_name;
                        }
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO fin_income (school_id, category_id, account_id, income_date, source, description, amount, session, term, reference, proof_path, recorded_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$school_id, $category_id, $account_id, $income_date, $source, $description, $amount, $session, $term, $reference, $proof_path, $admin_id]);

                    // Add to cashflow
                    $stmt = $pdo->prepare("
                        INSERT INTO fin_cashflow (school_id, flow_date, flow_type, amount, balance_after, description, source_ref, account_id, created_at)
                        VALUES (?, ?, 'inflow', ?, (SELECT COALESCE(SUM(CASE WHEN flow_type = 'inflow' THEN amount ELSE -amount END), 0) + ? FROM fin_cashflow WHERE school_id = ?), ?, 'income:' || LAST_INSERT_ID(), ?, NOW())
                    ");
                    $stmt->execute([$school_id, $income_date, $amount, $amount, $school_id, $description, $account_id]);

                    $message = "Income recorded successfully!";
                    $message_type = "success";
                } catch (Exception $e) {
                    $message = "Error recording income: " . $e->getMessage();
                    $message_type = "error";
                }
            }
        }

        // Add Expenditure
        elseif ($_POST['action'] === 'add_expenditure') {
            $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
            $account_id = !empty($_POST['account_id']) ? intval($_POST['account_id']) : null;
            $expense_date = $_POST['expense_date'] ?? date('Y-m-d');
            $payee = trim($_POST['payee']);
            $description = trim($_POST['description']);
            $amount = floatval($_POST['amount']);
            $session = trim($_POST['session'] ?? $current_session);
            $term = trim($_POST['term'] ?? 'First');
            $payment_method = $_POST['payment_method'] ?? 'cash';
            $reference = trim($_POST['reference'] ?? '');
            $proof_path = null;

            if (empty($payee) || $amount <= 0) {
                $message = "Please fill in payee and amount";
                $message_type = "error";
            } else {
                try {
                    // Handle file upload
                    if (isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] == 0) {
                        $upload_dir = '../uploads/finance/';
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        $file_ext = pathinfo($_FILES['proof_file']['name'], PATHINFO_EXTENSION);
                        $file_name = 'expense_' . time() . '_' . uniqid() . '.' . $file_ext;
                        $upload_path = $upload_dir . $file_name;
                        if (move_uploaded_file($_FILES['proof_file']['tmp_name'], $upload_path)) {
                            $proof_path = '/uploads/finance/' . $file_name;
                        }
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO fin_expenditure (school_id, category_id, account_id, expense_date, payee, description, amount, session, term, payment_method, reference, proof_path, recorded_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$school_id, $category_id, $account_id, $expense_date, $payee, $description, $amount, $session, $term, $payment_method, $reference, $proof_path, $admin_id]);

                    // Add to cashflow
                    $stmt = $pdo->prepare("
                        INSERT INTO fin_cashflow (school_id, flow_date, flow_type, amount, balance_after, description, source_ref, account_id, created_at)
                        VALUES (?, ?, 'outflow', ?, (SELECT COALESCE(SUM(CASE WHEN flow_type = 'inflow' THEN amount ELSE -amount END), 0) - ? FROM fin_cashflow WHERE school_id = ?), ?, 'expenditure:' || LAST_INSERT_ID(), ?, NOW())
                    ");
                    $stmt->execute([$school_id, $expense_date, $amount, $amount, $school_id, $description, $account_id]);

                    $message = "Expenditure recorded successfully!";
                    $message_type = "success";
                } catch (Exception $e) {
                    $message = "Error recording expenditure: " . $e->getMessage();
                    $message_type = "error";
                }
            }
        }

        // Delete record
        elseif ($_POST['action'] === 'delete') {
            $type = $_POST['record_type'];
            $record_id = intval($_POST['record_id']);

            try {
                if ($type === 'income') {
                    $stmt = $pdo->prepare("DELETE FROM fin_income WHERE id = ? AND school_id = ?");
                    $stmt->execute([$record_id, $school_id]);
                } else {
                    $stmt = $pdo->prepare("DELETE FROM fin_expenditure WHERE id = ? AND school_id = ?");
                    $stmt->execute([$record_id, $school_id]);
                }
                $message = "Record deleted successfully!";
                $message_type = "success";
            } catch (Exception $e) {
                $message = "Error deleting record: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
}

// Get filters
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'income';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Filter variables
$filter_category = isset($_GET['category']) ? intval($_GET['category']) : 0;
$filter_session = isset($_GET['session']) ? $_GET['session'] : 'all';
$filter_term = isset($_GET['term']) ? $_GET['term'] : 'all';
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filter_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build where clause for income
$income_where = ["i.school_id = ?"];
$income_params = [$school_id];

if ($filter_category > 0) {
    $income_where[] = "i.category_id = ?";
    $income_params[] = $filter_category;
}
if ($filter_session !== 'all') {
    $income_where[] = "i.session = ?";
    $income_params[] = $filter_session;
}
if ($filter_term !== 'all') {
    $income_where[] = "i.term = ?";
    $income_params[] = $filter_term;
}
if ($filter_date_from) {
    $income_where[] = "i.income_date >= ?";
    $income_params[] = $filter_date_from;
}
if ($filter_date_to) {
    $income_where[] = "i.income_date <= ?";
    $income_params[] = $filter_date_to;
}

$income_where_sql = implode(" AND ", $income_where);

// Get income records
$stmt = $pdo->prepare("
    SELECT i.*, c.name as category_name
    FROM fin_income i
    LEFT JOIN fin_categories c ON i.category_id = c.id
    WHERE $income_where_sql
    ORDER BY i.income_date DESC, i.created_at DESC
    LIMIT $offset, $per_page
");
$stmt->execute($income_params);
$income_records = $stmt->fetchAll();

// Get total income count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM fin_income i WHERE $income_where_sql");
$stmt->execute($income_params);
$total_income = $stmt->fetchColumn();
$income_pages = ceil($total_income / $per_page);

// Build where clause for expenditure
$exp_where = ["e.school_id = ?"];
$exp_params = [$school_id];

if ($filter_category > 0) {
    $exp_where[] = "e.category_id = ?";
    $exp_params[] = $filter_category;
}
if ($filter_session !== 'all') {
    $exp_where[] = "e.session = ?";
    $exp_params[] = $filter_session;
}
if ($filter_term !== 'all') {
    $exp_where[] = "e.term = ?";
    $exp_params[] = $filter_term;
}
if ($filter_date_from) {
    $exp_where[] = "e.expense_date >= ?";
    $exp_params[] = $filter_date_from;
}
if ($filter_date_to) {
    $exp_where[] = "e.expense_date <= ?";
    $exp_params[] = $filter_date_to;
}

$exp_where_sql = implode(" AND ", $exp_where);

// Get expenditure records
$stmt = $pdo->prepare("
    SELECT e.*, c.name as category_name
    FROM fin_expenditure e
    LEFT JOIN fin_categories c ON e.category_id = c.id
    WHERE $exp_where_sql
    ORDER BY e.expense_date DESC, e.created_at DESC
    LIMIT $offset, $per_page
");
$stmt->execute($exp_params);
$expenditure_records = $stmt->fetchAll();

// Get total expenditure count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM fin_expenditure e WHERE $exp_where_sql");
$stmt->execute($exp_params);
$total_expenditure = $stmt->fetchColumn();
$exp_pages = ceil($total_expenditure / $per_page);

// Get summary statistics
$stmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(amount), 0) as total_income,
        COALESCE(SUM(CASE WHEN MONTH(income_date) = MONTH(CURRENT_DATE()) AND YEAR(income_date) = YEAR(CURRENT_DATE()) THEN amount ELSE 0 END), 0) as monthly_income
    FROM fin_income WHERE school_id = ?
");
$stmt->execute([$school_id]);
$income_stats = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(amount), 0) as total_expenditure,
        COALESCE(SUM(CASE WHEN MONTH(expense_date) = MONTH(CURRENT_DATE()) AND YEAR(expense_date) = YEAR(CURRENT_DATE()) THEN amount ELSE 0 END), 0) as monthly_expenditure
    FROM fin_expenditure WHERE school_id = ?
");
$stmt->execute([$school_id]);
$exp_stats = $stmt->fetch();

$net_position = $income_stats['total_income'] - $exp_stats['total_expenditure'];
$monthly_net = $income_stats['monthly_income'] - $exp_stats['monthly_expenditure'];

// Get categories for dropdown
$stmt = $pdo->prepare("SELECT id, name, type FROM fin_categories WHERE school_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$school_id]);
$categories = $stmt->fetchAll();

// Get accounts for dropdown
$stmt = $pdo->prepare("SELECT id, account_name FROM fin_accounts WHERE school_id = ? AND is_active = 1 ORDER BY account_name");
$stmt->execute([$school_id]);
$accounts = $stmt->fetchAll();

// Get sessions for filter
$sessions = ['2023/2024', '2024/2025', '2025/2026', $current_session];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $school_name; ?> - Income & Expenditure</title>

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

        .stat-card.income {
            border-left-color: var(--success-color);
        }

        .stat-card.expense {
            border-left-color: var(--danger-color);
        }

        .stat-card.net {
            border-left-color: var(--info-color);
        }

        .stat-card.monthly {
            border-left-color: var(--warning-color);
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

        /* Tabs */
        .tabs {
            display: flex;
            gap: 5px;
            margin-bottom: 20px;
            background: white;
            padding: 5px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
        }

        .tab-btn {
            flex: 1;
            padding: 12px;
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 0.9rem;
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
            padding: 5px 10px;
            border-radius: var(--radius-sm);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.7rem;
            cursor: pointer;
        }

        .action-icon.delete {
            background: var(--danger-color);
            color: white;
        }

        .action-icon.view {
            background: var(--info-color);
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
                <h1><i class="fas fa-chart-pie" style="margin-right: 10px; color: var(--secondary-color);"></i>Income & Expenditure</h1>
                <p>Track all income sources and expenses</p>
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

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card income">
                <div class="stat-value">₦<?php echo number_format($income_stats['total_income'], 2); ?></div>
                <div class="stat-label">Total Income (All Time)</div>
            </div>
            <div class="stat-card expense">
                <div class="stat-value">₦<?php echo number_format($exp_stats['total_expenditure'], 2); ?></div>
                <div class="stat-label">Total Expenditure (All Time)</div>
            </div>
            <div class="stat-card net">
                <div class="stat-value" style="color: <?php echo $net_position >= 0 ? 'var(--success-color)' : 'var(--danger-color)'; ?>">
                    ₦<?php echo number_format(abs($net_position), 2); ?>
                </div>
                <div class="stat-label">Net Position (<?php echo $net_position >= 0 ? 'Surplus' : 'Deficit'; ?>)</div>
            </div>
            <div class="stat-card monthly">
                <div class="stat-value">₦<?php echo number_format($monthly_net, 2); ?></div>
                <div class="stat-label">This Month's Net</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab-btn <?php echo $active_tab === 'income' ? 'active' : ''; ?>" onclick="switchTab('income')">
                <i class="fas fa-arrow-up" style="color: var(--success-color);"></i> Income
            </button>
            <button class="tab-btn <?php echo $active_tab === 'expenditure' ? 'active' : ''; ?>" onclick="switchTab('expenditure')">
                <i class="fas fa-arrow-down" style="color: var(--danger-color);"></i> Expenditure
            </button>
        </div>

        <!-- Income Tab -->
        <div id="incomeTab" class="tab-pane <?php echo $active_tab === 'income' ? 'active' : ''; ?>">
            <!-- Add Income Form -->
            <div class="form-card">
                <div class="form-title">
                    <i class="fas fa-plus-circle"></i> Record New Income
                </div>
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_income">

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Source *</label>
                            <input type="text" name="source" required placeholder="e.g., PTA Levy, Government Grant">
                        </div>

                        <div class="form-group">
                            <label>Category</label>
                            <select name="category_id">
                                <option value="">-- Select Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <?php if ($cat['type'] === 'income' || $cat['type'] === 'both'): ?>
                                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Account</label>
                            <select name="account_id">
                                <option value="">-- Select Account --</option>
                                <?php foreach ($accounts as $acc): ?>
                                    <option value="<?php echo $acc['id']; ?>"><?php echo htmlspecialchars($acc['account_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Date *</label>
                            <input type="date" name="income_date" required value="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div class="form-group">
                            <label>Amount (₦) *</label>
                            <input type="number" name="amount" step="0.01" required placeholder="0.00">
                        </div>

                        <div class="form-group">
                            <label>Session</label>
                            <select name="session">
                                <?php foreach ($sessions as $sess): ?>
                                    <option value="<?php echo $sess; ?>" <?php echo $sess === $current_session ? 'selected' : ''; ?>><?php echo $sess; ?></option>
                                <?php endforeach; ?>
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
                            <label>Reference/Cheque No.</label>
                            <input type="text" name="reference" placeholder="Optional reference number">
                        </div>

                        <div class="form-group">
                            <label>Proof Document (Optional)</label>
                            <input type="file" name="proof_file" accept=".pdf,.jpg,.png,.jpeg">
                        </div>

                        <div class="form-group">
                            <label>Description</label>
                            <textarea name="description" rows="3" placeholder="Additional details..."></textarea>
                        </div>
                    </div>

                    <div style="margin-top: 20px;">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> Record Income
                        </button>
                    </div>
                </form>
            </div>

            <!-- Filter Bar for Income -->
            <div class="filter-bar">
                <div class="filter-group">
                    <label>Category</label>
                    <select id="income_category_filter">
                        <option value="0">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <?php if ($cat['type'] === 'income' || $cat['type'] === 'both'): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo $filter_category == $cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Session</label>
                    <select id="income_session_filter">
                        <option value="all">All Sessions</option>
                        <?php foreach ($sessions as $sess): ?>
                            <option value="<?php echo $sess; ?>" <?php echo $filter_session === $sess ? 'selected' : ''; ?>><?php echo $sess; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Term</label>
                    <select id="income_term_filter">
                        <option value="all">All Terms</option>
                        <option value="First" <?php echo $filter_term === 'First' ? 'selected' : ''; ?>>First Term</option>
                        <option value="Second" <?php echo $filter_term === 'Second' ? 'selected' : ''; ?>>Second Term</option>
                        <option value="Third" <?php echo $filter_term === 'Third' ? 'selected' : ''; ?>>Third Term</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Date From</label>
                    <input type="date" id="income_date_from" value="<?php echo $filter_date_from; ?>">
                </div>
                <div class="filter-group">
                    <label>Date To</label>
                    <input type="date" id="income_date_to" value="<?php echo $filter_date_to; ?>">
                </div>
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <button onclick="applyIncomeFilters()" class="btn btn-primary btn-sm">Apply Filters</button>
                </div>
            </div>

            <!-- Income Records Table -->
            <div class="table-card">
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Source</th>
                                <th>Category</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Reference</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($income_records)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 40px; color: #999;">
                                        <i class="fas fa-arrow-up" style="font-size: 40px; margin-bottom: 10px; display: block;"></i>
                                        No income records found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($income_records as $income): ?>
                                    <tr>
                                        <td><?php echo date('d M Y', strtotime($income['income_date'])); ?></td>
                                        <td><strong><?php echo htmlspecialchars($income['source']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($income['category_name'] ?? 'Uncategorized'); ?></td>
                                        <td><?php echo htmlspecialchars(substr($income['description'] ?? '', 0, 40)); ?></td>
                                        <td style="font-weight: 600; color: var(--success-color);">₦<?php echo number_format($income['amount'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($income['reference'] ?? 'N/A'); ?></td>
                                        <td>
                                            <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this income record?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="record_type" value="income">
                                                <input type="hidden" name="record_id" value="<?php echo $income['id']; ?>">
                                                <button type="submit" class="action-icon delete"><i class="fas fa-trash"></i> Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($income_pages > 1): ?>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $income_pages; $i++): ?>
                            <a href="?tab=income&page=<?php echo $i; ?>&category=<?php echo $filter_category; ?>&session=<?php echo $filter_session; ?>&term=<?php echo $filter_term; ?>&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Expenditure Tab -->
        <div id="expenditureTab" class="tab-pane <?php echo $active_tab === 'expenditure' ? 'active' : ''; ?>">
            <!-- Add Expenditure Form -->
            <div class="form-card">
                <div class="form-title">
                    <i class="fas fa-plus-circle"></i> Record New Expenditure
                </div>
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_expenditure">

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Payee *</label>
                            <input type="text" name="payee" required placeholder="e.g., Vendor, Staff, Supplier">
                        </div>

                        <div class="form-group">
                            <label>Category</label>
                            <select name="category_id">
                                <option value="">-- Select Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <?php if ($cat['type'] === 'expenditure' || $cat['type'] === 'both'): ?>
                                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Account</label>
                            <select name="account_id">
                                <option value="">-- Select Account --</option>
                                <?php foreach ($accounts as $acc): ?>
                                    <option value="<?php echo $acc['id']; ?>"><?php echo htmlspecialchars($acc['account_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Date *</label>
                            <input type="date" name="expense_date" required value="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div class="form-group">
                            <label>Amount (₦) *</label>
                            <input type="number" name="amount" step="0.01" required placeholder="0.00">
                        </div>

                        <div class="form-group">
                            <label>Payment Method</label>
                            <select name="payment_method">
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                                <option value="pos">POS</option>
                                <option value="other">Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Session</label>
                            <select name="session">
                                <?php foreach ($sessions as $sess): ?>
                                    <option value="<?php echo $sess; ?>" <?php echo $sess === $current_session ? 'selected' : ''; ?>><?php echo $sess; ?></option>
                                <?php endforeach; ?>
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
                            <label>Reference/Receipt No.</label>
                            <input type="text" name="reference" placeholder="Optional reference number">
                        </div>

                        <div class="form-group">
                            <label>Proof Document (Optional)</label>
                            <input type="file" name="proof_file" accept=".pdf,.jpg,.png,.jpeg">
                        </div>

                        <div class="form-group">
                            <label>Description</label>
                            <textarea name="description" rows="3" placeholder="What was this payment for?"></textarea>
                        </div>
                    </div>

                    <div style="margin-top: 20px;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Record Expenditure
                        </button>
                    </div>
                </form>
            </div>

            <!-- Filter Bar for Expenditure -->
            <div class="filter-bar">
                <div class="filter-group">
                    <label>Category</label>
                    <select id="exp_category_filter">
                        <option value="0">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <?php if ($cat['type'] === 'expenditure' || $cat['type'] === 'both'): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo $filter_category == $cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Session</label>
                    <select id="exp_session_filter">
                        <option value="all">All Sessions</option>
                        <?php foreach ($sessions as $sess): ?>
                            <option value="<?php echo $sess; ?>" <?php echo $filter_session === $sess ? 'selected' : ''; ?>><?php echo $sess; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Term</label>
                    <select id="exp_term_filter">
                        <option value="all">All Terms</option>
                        <option value="First" <?php echo $filter_term === 'First' ? 'selected' : ''; ?>>First Term</option>
                        <option value="Second" <?php echo $filter_term === 'Second' ? 'selected' : ''; ?>>Second Term</option>
                        <option value="Third" <?php echo $filter_term === 'Third' ? 'selected' : ''; ?>>Third Term</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Date From</label>
                    <input type="date" id="exp_date_from" value="<?php echo $filter_date_from; ?>">
                </div>
                <div class="filter-group">
                    <label>Date To</label>
                    <input type="date" id="exp_date_to" value="<?php echo $filter_date_to; ?>">
                </div>
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <button onclick="applyExpFilters()" class="btn btn-primary btn-sm">Apply Filters</button>
                </div>
            </div>

            <!-- Expenditure Records Table -->
            <div class="table-card">
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Payee</th>
                                <th>Category</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($expenditure_records)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 40px; color: #999;">
                                        <i class="fas fa-arrow-down" style="font-size: 40px; margin-bottom: 10px; display: block;"></i>
                                        No expenditure records found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($expenditure_records as $expense): ?>
                                    <tr>
                                        <td><?php echo date('d M Y', strtotime($expense['expense_date'])); ?></td>
                                        <td><strong><?php echo htmlspecialchars($expense['payee']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($expense['category_name'] ?? 'Uncategorized'); ?></td>
                                        <td><?php echo htmlspecialchars(substr($expense['description'] ?? '', 0, 40)); ?></td>
                                        <td style="font-weight: 600; color: var(--danger-color);">₦<?php echo number_format($expense['amount'], 2); ?></td>
                                        <td><span style="background: #e8e8e8; padding: 3px 8px; border-radius: 12px; font-size: 0.7rem;"><?php echo ucfirst($expense['payment_method']); ?></span></td>
                                        <td>
                                            <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this expenditure record?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="record_type" value="expenditure">
                                                <input type="hidden" name="record_id" value="<?php echo $expense['id']; ?>">
                                                <button type="submit" class="action-icon delete"><i class="fas fa-trash"></i> Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($exp_pages > 1): ?>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $exp_pages; $i++): ?>
                            <a href="?tab=expenditure&page=<?php echo $i; ?>&category=<?php echo $filter_category; ?>&session=<?php echo $filter_session; ?>&term=<?php echo $filter_term; ?>&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
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
            window.location.href = url.toString();
        }

        function applyIncomeFilters() {
            const category = document.getElementById('income_category_filter').value;
            const session = document.getElementById('income_session_filter').value;
            const term = document.getElementById('income_term_filter').value;
            const dateFrom = document.getElementById('income_date_from').value;
            const dateTo = document.getElementById('income_date_to').value;

            let url = '?tab=income';
            if (category && category != '0') url += '&category=' + category;
            if (session && session != 'all') url += '&session=' + encodeURIComponent(session);
            if (term && term != 'all') url += '&term=' + encodeURIComponent(term);
            if (dateFrom) url += '&date_from=' + dateFrom;
            if (dateTo) url += '&date_to=' + dateTo;

            window.location.href = url;
        }

        function applyExpFilters() {
            const category = document.getElementById('exp_category_filter').value;
            const session = document.getElementById('exp_session_filter').value;
            const term = document.getElementById('exp_term_filter').value;
            const dateFrom = document.getElementById('exp_date_from').value;
            const dateTo = document.getElementById('exp_date_to').value;

            let url = '?tab=expenditure';
            if (category && category != '0') url += '&category=' + category;
            if (session && session != 'all') url += '&session=' + encodeURIComponent(session);
            if (term && term != 'all') url += '&term=' + encodeURIComponent(term);
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
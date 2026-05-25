<?php
// eagles/admin/finance_dashboard.php - Finance Management Dashboard
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../../includes/config.php';

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

// Get current session/term (from settings or default)
$current_session = date('Y') . '/' . (date('Y') + 1);
$current_term = 'First'; // This should come from system settings

// Fetch finance statistics
try {
    // Cash Position (Current Balance - sum of all verified payments, income, minus expenditure)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN flow_type = 'inflow' THEN amount ELSE -amount END), 0) as current_balance
        FROM fin_cashflow 
        WHERE school_id = ?
    ");
    $stmt->execute([$school_id]);
    $current_balance = $stmt->fetch()['current_balance'] ?? 0;

    // Total Pending Payments (Unverified payments)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount_paid), 0) as total
        FROM fin_payments 
        WHERE school_id = ? AND status = 'pending_verification'
    ");
    $stmt->execute([$school_id]);
    $pending_payments = $stmt->fetch()['total'] ?? 0;

    // Total Overdue Bills
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount - amount_paid), 0) as total
        FROM fin_bills 
        WHERE school_id = ? AND status = 'overdue'
    ");
    $stmt->execute([$school_id]);
    $overdue_bills = $stmt->fetch()['total'] ?? 0;

    // Total Outstanding Balances (all pending and part_paid bills)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount - amount_paid), 0) as total
        FROM fin_bills 
        WHERE school_id = ? AND status IN ('pending', 'part_paid')
    ");
    $stmt->execute([$school_id]);
    $outstanding_balance = $stmt->fetch()['total'] ?? 0;

    // Monthly Income (Current month)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM fin_income 
        WHERE school_id = ? AND MONTH(income_date) = MONTH(CURRENT_DATE()) AND YEAR(income_date) = YEAR(CURRENT_DATE())
    ");
    $stmt->execute([$school_id]);
    $monthly_income = $stmt->fetch()['total'] ?? 0;

    // Monthly Expenditure (Current month)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM fin_expenditure 
        WHERE school_id = ? AND MONTH(expense_date) = MONTH(CURRENT_DATE()) AND YEAR(expense_date) = YEAR(CURRENT_DATE())
    ");
    $stmt->execute([$school_id]);
    $monthly_expenditure = $stmt->fetch()['total'] ?? 0;

    // Monthly Net = Income - Expenditure
    $monthly_net = $monthly_income - $monthly_expenditure;

    // Total Bills (active bills amount)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM fin_bills 
        WHERE school_id = ? AND status IN ('pending', 'part_paid')
    ");
    $stmt->execute([$school_id]);
    $total_bills = $stmt->fetch()['total'] ?? 0;

    // Total Payments Received (verified only)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount_paid), 0) as total
        FROM fin_payments 
        WHERE school_id = ? AND status = 'verified'
    ");
    $stmt->execute([$school_id]);
    $total_payments = $stmt->fetch()['total'] ?? 0;

    // Total Income (all sources)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM fin_income 
        WHERE school_id = ?
    ");
    $stmt->execute([$school_id]);
    $total_income = $stmt->fetch()['total'] ?? 0;

    // Total Expenditure
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM fin_expenditure 
        WHERE school_id = ?
    ");
    $stmt->execute([$school_id]);
    $total_expenditure = $stmt->fetch()['total'] ?? 0;

    // Net Profit/Loss
    $net_profit = ($total_payments + $total_income) - $total_expenditure;

    // Recent Payments (last 5 verified payments)
    $stmt = $pdo->prepare("
        SELECT p.*, s.full_name as student_name, b.description as bill_description
        FROM fin_payments p
        JOIN students s ON p.student_id = s.id
        LEFT JOIN fin_bills b ON p.bill_id = b.id
        WHERE p.school_id = ? AND p.status = 'verified'
        ORDER BY p.payment_date DESC
        LIMIT 5
    ");
    $stmt->execute([$school_id]);
    $recent_payments = $stmt->fetchAll();

    // Recent Expenditures (last 5)
    $stmt = $pdo->prepare("
        SELECT e.*, c.name as category_name
        FROM fin_expenditure e
        LEFT JOIN fin_categories c ON e.category_id = c.id
        WHERE e.school_id = ?
        ORDER BY e.expense_date DESC
        LIMIT 5
    ");
    $stmt->execute([$school_id]);
    $recent_expenditures = $stmt->fetchAll();

    // Pending Verifications (payments needing verification)
    $stmt = $pdo->prepare("
        SELECT p.*, s.full_name as student_name, b.description as bill_description
        FROM fin_payments p
        JOIN students s ON p.student_id = s.id
        LEFT JOIN fin_bills b ON p.bill_id = b.id
        WHERE p.school_id = ? AND p.status = 'pending_verification'
        ORDER BY p.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$school_id]);
    $pending_verifications = $stmt->fetchAll();

    // Chart Data: Last 6 months cash flow
    $chart_labels = [];
    $chart_inflow = [];
    $chart_outflow = [];

    for ($i = 5; $i >= 0; $i--) {
        $month = date('Y-m', strtotime("-$i months"));
        $month_name = date('M Y', strtotime("-$i months"));
        $chart_labels[] = $month_name;

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(CASE WHEN flow_type = 'inflow' THEN amount ELSE 0 END), 0) as inflow,
                   COALESCE(SUM(CASE WHEN flow_type = 'outflow' THEN amount ELSE 0 END), 0) as outflow
            FROM fin_cashflow 
            WHERE school_id = ? AND DATE_FORMAT(flow_date, '%Y-%m') = ?
        ");
        $stmt->execute([$school_id, $month]);
        $row = $stmt->fetch();
        $chart_inflow[] = $row['inflow'] ?? 0;
        $chart_outflow[] = $row['outflow'] ?? 0;
    }

    // Bill Types Count
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM fin_bill_types WHERE school_id = ? AND is_active = 1");
    $stmt->execute([$school_id]);
    $bill_types_count = $stmt->fetch()['total'] ?? 0;

    // Total Students
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM students WHERE school_id = ? AND status = 'active'");
    $stmt->execute([$school_id]);
    $total_students = $stmt->fetch()['total'] ?? 0;

    // Receipts generated this month
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM fin_receipts 
        WHERE school_id = ? AND MONTH(issued_at) = MONTH(CURRENT_DATE()) AND YEAR(issued_at) = YEAR(CURRENT_DATE())
    ");
    $stmt->execute([$school_id]);
    $receipts_this_month = $stmt->fetch()['total'] ?? 0;
} catch (Exception $e) {
    error_log("Finance dashboard error: " . $e->getMessage());
    $error_message = "Error loading finance data";
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $school_name; ?> - Finance Dashboard</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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

        /* Main Content */
        .main-content {
            min-height: 100vh;
            padding: 20px;
            transition: var(--transition);
        }

        /* Mobile Menu Toggle */
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

        /* Top Header */
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

        .period-badge {
            background: var(--light-color);
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
        }

        .period-badge i {
            margin-right: 8px;
            color: var(--primary-color);
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
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-card.cash {
            border-left-color: var(--success-color);
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

        .stat-card.income {
            border-left-color: var(--success-color);
        }

        .stat-card.expense {
            border-left-color: var(--danger-color);
        }

        .stat-card.bills {
            border-left-color: var(--primary-color);
        }

        .stat-card.payments {
            border-left-color: var(--secondary-color);
        }

        .stat-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: white;
        }

        .stat-card.cash .stat-icon {
            background: var(--success-color);
        }

        .stat-card.pending .stat-icon {
            background: var(--warning-color);
        }

        .stat-card.overdue .stat-icon {
            background: var(--danger-color);
        }

        .stat-card.outstanding .stat-icon {
            background: var(--info-color);
        }

        .stat-card.income .stat-icon {
            background: var(--success-color);
        }

        .stat-card.expense .stat-icon {
            background: var(--danger-color);
        }

        .stat-card.bills .stat-icon {
            background: var(--primary-color);
        }

        .stat-card.payments .stat-icon {
            background: var(--secondary-color);
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

        .stat-trend {
            font-size: 0.7rem;
            margin-top: 8px;
        }

        .trend-up {
            color: var(--success-color);
        }

        .trend-down {
            color: var(--danger-color);
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .content-card {
            background: white;
            border-radius: var(--radius-md);
            padding: 20px;
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--light-color);
        }

        .card-header h3 {
            color: var(--primary-color);
            font-size: 1rem;
            font-weight: 600;
        }

        .card-header a {
            color: var(--secondary-color);
            text-decoration: none;
            font-size: 0.75rem;
        }

        .card-header a:hover {
            text-decoration: underline;
        }

        /* Chart Container */
        .chart-container {
            position: relative;
            height: 300px;
        }

        /* Tables */
        .table-container {
            overflow-x: auto;
            max-height: 350px;
            overflow-y: auto;
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
            position: sticky;
            top: 0;
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

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }

        .action-btn {
            background: white;
            border: 2px solid var(--light-color);
            border-radius: var(--radius-sm);
            padding: 12px;
            text-align: center;
            text-decoration: none;
            color: var(--primary-color);
            transition: var(--transition);
        }

        .action-btn:hover {
            border-color: var(--secondary-color);
            transform: translateY(-3px);
        }

        .action-icon {
            font-size: 20px;
            margin-bottom: 6px;
        }

        .action-text {
            font-size: 0.7rem;
            font-weight: 500;
        }

        /* Summary Row */
        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .summary-item {
            flex: 1;
            background: var(--light-color);
            padding: 15px;
            border-radius: var(--radius-sm);
            text-align: center;
        }

        .summary-item .label {
            font-size: 0.7rem;
            color: #666;
            margin-bottom: 5px;
        }

        .summary-item .value {
            font-size: 1.2rem;
            font-weight: 700;
        }

        /* Footer */
        .dashboard-footer {
            text-align: center;
            padding: 20px;
            color: #666;
            font-size: 0.8rem;
            border-top: 1px solid var(--light-color);
            margin-top: 20px;
        }

        /* Responsive */
        @media (max-width: 767px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .content-grid {
                grid-template-columns: 1fr;
            }

            .summary-row {
                flex-direction: column;
            }

            .stat-value {
                font-size: 1.2rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Top Header -->
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-chart-line" style="margin-right: 10px; color: var(--secondary-color);"></i>Finance Dashboard</h1>
                <p>Financial overview and management for <?php echo htmlspecialchars($school_name); ?></p>
            </div>
            <div class="period-badge">
                <i class="fas fa-calendar-alt"></i>
                <?php echo $current_session; ?> | <?php echo $current_term; ?> Term
            </div>
        </div>

        <!-- Stats Cards Row 1 - Cash Position -->
        <div class="stats-grid">
            <div class="stat-card cash">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value">₦<?php echo number_format($current_balance, 2); ?></div>
                        <div class="stat-label">Current Cash Balance</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                </div>
                <div class="stat-trend">
                    <i class="fas fa-chart-line"></i> Available funds
                </div>
            </div>

            <div class="stat-card pending">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value">₦<?php echo number_format($pending_payments, 2); ?></div>
                        <div class="stat-label">Pending Verification</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
                <div class="stat-trend">
                    <i class="fas fa-hourglass-half"></i> Awaiting approval
                </div>
            </div>

            <div class="stat-card overdue">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value">₦<?php echo number_format($overdue_bills, 2); ?></div>
                        <div class="stat-label">Overdue Bills</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </div>
                <div class="stat-trend">
                    <i class="fas fa-arrow-down trend-down"></i> Needs attention
                </div>
            </div>

            <div class="stat-card outstanding">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value">₦<?php echo number_format($outstanding_balance, 2); ?></div>
                        <div class="stat-label">Outstanding Balance</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-credit-card"></i>
                    </div>
                </div>
                <div class="stat-trend">
                    <i class="fas fa-users"></i> From active bills
                </div>
            </div>
        </div>

        <!-- Stats Cards Row 2 - Income/Expense -->
        <div class="stats-grid">
            <div class="stat-card income">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value">₦<?php echo number_format($monthly_income, 2); ?></div>
                        <div class="stat-label">This Month's Income</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-arrow-up"></i>
                    </div>
                </div>
                <div class="stat-trend">
                    <i class="fas fa-chart-line trend-up"></i> Revenue this month
                </div>
            </div>

            <div class="stat-card expense">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value">₦<?php echo number_format($monthly_expenditure, 2); ?></div>
                        <div class="stat-label">This Month's Expenses</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-arrow-down"></i>
                    </div>
                </div>
                <div class="stat-trend">
                    <i class="fas fa-chart-line trend-down"></i> Costs this month
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value">₦<?php echo number_format($monthly_net, 2); ?></div>
                        <div class="stat-label">Monthly Net</div>
                    </div>
                    <div class="stat-icon" style="background: <?php echo $monthly_net >= 0 ? 'var(--success-color)' : 'var(--danger-color)'; ?>">
                        <i class="fas fa-balance-scale"></i>
                    </div>
                </div>
                <div class="stat-trend">
                    <?php if ($monthly_net >= 0): ?>
                        <i class="fas fa-smile trend-up"></i> Profit this month
                    <?php else: ?>
                        <i class="fas fa-frown trend-down"></i> Loss this month
                    <?php endif; ?>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value">₦<?php echo number_format($net_profit, 2); ?></div>
                        <div class="stat-label">Net Profit (YTD)</div>
                    </div>
                    <div class="stat-icon" style="background: <?php echo $net_profit >= 0 ? 'var(--success-color)' : 'var(--danger-color)'; ?>">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                </div>
                <div class="stat-trend">
                    <i class="fas fa-calendar"></i> Year-to-date performance
                </div>
            </div>
        </div>

        <!-- Summary Row -->
        <div class="summary-row">
            <div class="summary-item">
                <div class="label">Total Bills (Active)</div>
                <div class="value">₦<?php echo number_format($total_bills, 2); ?></div>
            </div>
            <div class="summary-item">
                <div class="label">Total Payments Received</div>
                <div class="value">₦<?php echo number_format($total_payments, 2); ?></div>
            </div>
            <div class="summary-item">
                <div class="label">Total Income (Other)</div>
                <div class="value">₦<?php echo number_format($total_income, 2); ?></div>
            </div>
            <div class="summary-item">
                <div class="label">Total Expenditure</div>
                <div class="value">₦<?php echo number_format($total_expenditure, 2); ?></div>
            </div>
        </div>

        <!-- Chart Section -->
        <div class="content-grid">
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-line"></i> Cash Flow Trends (Last 6 Months)</h3>
                    <a href="finance_reports.php">Detailed Report <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="chart-container">
                    <canvas id="cashFlowChart"></canvas>
                </div>
            </div>

            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                </div>
                <div class="quick-actions">
                    <a href="finance_bill_types.php" class="action-btn">
                        <div class="action-icon"><i class="fas fa-tag"></i></div>
                        <div class="action-text">Bill Templates</div>
                    </a>
                    <a href="finance_bills.php" class="action-btn">
                        <div class="action-icon"><i class="fas fa-file-invoice"></i></div>
                        <div class="action-text">Manage Bills</div>
                    </a>
                    <a href="finance_payments.php" class="action-btn">
                        <div class="action-icon"><i class="fas fa-money-bill"></i></div>
                        <div class="action-text">Record Payment</div>
                    </a>
                    <a href="finance_income.php" class="action-btn">
                        <div class="action-icon"><i class="fas fa-coins"></i></div>
                        <div class="action-text">Add Income</div>
                    </a>
                    <a href="finance_expenditure.php" class="action-btn">
                        <div class="action-icon"><i class="fas fa-receipt"></i></div>
                        <div class="action-text">Add Expense</div>
                    </a>
                    <a href="finance_ledger.php" class="action-btn">
                        <div class="action-icon"><i class="fas fa-book"></i></div>
                        <div class="action-text">General Ledger</div>
                    </a>
                    <a href="finance_invoices.php" class="action-btn">
                        <div class="action-icon"><i class="fas fa-file-pdf"></i></div>
                        <div class="action-text">Invoices</div>
                    </a>
                    <a href="finance_receipts.php" class="action-btn">
                        <div class="action-icon"><i class="fas fa-receipt"></i></div>
                        <div class="action-text">Receipts</div>
                    </a>
                </div>

                <!-- Quick Stats -->
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--light-color);">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                        <span style="font-size: 0.75rem; color: #666;"><i class="fas fa-tags"></i> Active Bill Types:</span>
                        <span style="font-weight: 600;"><?php echo $bill_types_count; ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                        <span style="font-size: 0.75rem; color: #666;"><i class="fas fa-users"></i> Total Students:</span>
                        <span style="font-weight: 600;"><?php echo $total_students; ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="font-size: 0.75rem; color: #666;"><i class="fas fa-receipt"></i> Receipts This Month:</span>
                        <span style="font-weight: 600;"><?php echo $receipts_this_month; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Payments and Pending Verifications -->
        <div class="content-grid">
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-check-circle" style="color: var(--success-color);"></i> Recent Verified Payments</h3>
                    <a href="finance_payments.php">View All</a>
                </div>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Student</th>
                                <th>Description</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recent_payments)): ?>
                                <?php foreach ($recent_payments as $payment): ?>
                                    <tr>
                                        <td><?php echo date('d M Y', strtotime($payment['payment_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($payment['student_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars(substr($payment['bill_description'] ?? 'Payment', 0, 30)); ?></td>
                                        <td style="font-weight: 600; color: var(--success-color);">₦<?php echo number_format($payment['amount_paid'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; color: #999;">No recent payments</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-clock" style="color: var(--warning-color);"></i> Pending Verifications</h3>
                    <a href="finance_payments.php?filter=pending">Verify Now</a>
                </div>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Student</th>
                                <th>Method</th>
                                <th>Amount</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($pending_verifications)): ?>
                                <?php foreach ($pending_verifications as $payment): ?>
                                    <tr>
                                        <td><?php echo date('d M Y', strtotime($payment['payment_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($payment['student_name'] ?? 'N/A'); ?></td>
                                        <td>
                                            <span class="status-badge status-pending">
                                                <?php echo ucfirst($payment['payment_method']); ?>
                                            </span>
                                        </td>
                                        <td style="font-weight: 600;">₦<?php echo number_format($payment['amount_paid'], 2); ?></td>
                                        <td>
                                            <a href="finance_payments.php?verify=<?php echo $payment['id']; ?>" style="color: var(--primary-color);">
                                                <i class="fas fa-check-circle"></i> Verify
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: #999;">No pending verifications</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent Expenditures -->
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-arrow-down" style="color: var(--danger-color);"></i> Recent Expenditures</h3>
                <a href="finance_expenditure.php">View All</a>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Payee</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recent_expenditures)): ?>
                            <?php foreach ($recent_expenditures as $expense): ?>
                                <tr>
                                    <td><?php echo date('d M Y', strtotime($expense['expense_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($expense['payee']); ?></td>
                                    <td><?php echo htmlspecialchars($expense['category_name'] ?? 'Uncategorized'); ?></td>
                                    <td><?php echo htmlspecialchars(substr($expense['description'] ?? '', 0, 40)); ?></td>
                                    <td style="font-weight: 600; color: var(--danger-color);">₦<?php echo number_format($expense['amount'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: #999;">No expenditure records</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer -->
        <div class="dashboard-footer">
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

        // Cash Flow Chart
        const ctx = document.getElementById('cashFlowChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [{
                        label: 'Inflow (Income)',
                        data: <?php echo json_encode($chart_inflow); ?>,
                        borderColor: '<?php echo $secondary_color; ?>',
                        backgroundColor: '<?php echo $secondary_color; ?>20',
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '<?php echo $secondary_color; ?>'
                    },
                    {
                        label: 'Outflow (Expenses)',
                        data: <?php echo json_encode($chart_outflow); ?>,
                        borderColor: '#e74c3c',
                        backgroundColor: '#e74c3c20',
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#e74c3c'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ₦' + context.raw.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        ticks: {
                            callback: function(value) {
                                return '₦' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
    </script>

    <?php
    // Include sidebar
    require_once '../includes/sidebar.php';
    ?>
</body>

</html>
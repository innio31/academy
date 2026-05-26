<?php
// msv/admin/finance_bills.php - Manage Bills & Batch Generation
// No caching - always loads fresh
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');

error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../includes/config.php';

// Auth check
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /msv/login.php");
    exit();
}

// Get admin info
if (isset($_SESSION['admin_id'])) {
    $admin_id  = $_SESSION['admin_id'];
    $admin_name = $_SESSION['admin_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'super_admin';
} else {
    $admin_id  = $_SESSION['user_id'];
    $admin_name = $_SESSION['user_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

$school_id       = SCHOOL_ID;
$school_name     = SCHOOL_NAME;
$primary_color   = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;
$current_session = date('Y') . '/' . (date('Y') + 1);

// ─────────────────────────────────────────────────────────────────────────────
// AJAX/POST Handler – record a payment directly from this page
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    $ajax_action = $_POST['ajax_action'];

    // ── Record Payment ──────────────────────────────────────────────────────
    if ($ajax_action === 'record_payment') {
        $bill_id        = intval($_POST['bill_id'] ?? 0);
        $amount_paid    = floatval($_POST['amount_paid'] ?? 0);
        $payment_date   = trim($_POST['payment_date'] ?? date('Y-m-d'));
        $payment_method = trim($_POST['payment_method'] ?? 'cash');
        $reference      = trim($_POST['reference_number'] ?? '');
        $notes          = trim($_POST['notes'] ?? '');
        $account_id     = !empty($_POST['account_id']) ? intval($_POST['account_id']) : null;

        // Validate
        if ($bill_id <= 0 || $amount_paid <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid bill or amount.']);
            exit();
        }

        try {
            $pdo->beginTransaction();

            // 1. Fetch bill + student
            $stmt = $pdo->prepare("
                SELECT b.*, s.full_name AS student_name, s.admission_number
                FROM fin_bills b
                JOIN students s ON b.student_id = s.id
                WHERE b.id = ? AND b.school_id = ?
            ");
            $stmt->execute([$bill_id, $school_id]);
            $bill = $stmt->fetch();

            if (!$bill) {
                throw new Exception('Bill not found.');
            }
            if (in_array($bill['status'], ['paid', 'cancelled'])) {
                throw new Exception('This bill is already ' . $bill['status'] . '.');
            }

            $balance_due = floatval($bill['amount']) - floatval($bill['amount_paid']);
            if ($amount_paid > $balance_due) {
                throw new Exception("Amount exceeds balance due (₦" . number_format($balance_due, 2) . ").");
            }

            // 2. Insert into fin_payments (pending_verification by default)
            $stmt = $pdo->prepare("
                INSERT INTO fin_payments
                    (school_id, bill_id, student_id, amount_paid, payment_date,
                     payment_method, reference_number, account_id, notes,
                     status, recorded_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'verified', ?, NOW())
            ");
            $stmt->execute([
                $school_id,
                $bill_id,
                $bill['student_id'],
                $amount_paid,
                $payment_date,
                $payment_method,
                $reference ?: null,
                $account_id,
                $notes ?: null,
                $admin_id
            ]);
            $payment_id = $pdo->lastInsertId();

            // 3. Update bill amount_paid & status
            $new_amount_paid = floatval($bill['amount_paid']) + $amount_paid;
            $new_balance     = floatval($bill['amount']) - $new_amount_paid;
            $new_status      = ($new_balance <= 0) ? 'paid'
                : (($new_amount_paid > 0) ? 'part_paid' : $bill['status']);

            $stmt = $pdo->prepare("
                UPDATE fin_bills
                SET amount_paid = ?, status = ?, updated_at = NOW()
                WHERE id = ? AND school_id = ?
            ");
            $stmt->execute([$new_amount_paid, $new_status, $bill_id, $school_id]);

            // 4. Generate receipt number
            $receipt_no = 'RCP/' . date('Y') . '/' . str_pad($payment_id, 6, '0', STR_PAD_LEFT);

            // Update payment with receipt number
            $stmt = $pdo->prepare("UPDATE fin_payments SET receipt_number = ?, verified_by = ?, verified_at = NOW() WHERE id = ?");
            $stmt->execute([$receipt_no, $admin_id, $payment_id]);

            // 5. Insert into fin_receipts
            $stmt = $pdo->prepare("
                INSERT INTO fin_receipts
                    (school_id, payment_id, receipt_number, issued_to, issued_by, issued_at, amount, notes)
                VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)
            ");
            $stmt->execute([
                $school_id,
                $payment_id,
                $receipt_no,
                $bill['student_name'],
                $admin_id,
                $amount_paid,
                $notes ?: null
            ]);

            // 6. Insert into fin_income
            // Find or get a suitable category_id for school fees / income
            $stmt = $pdo->prepare("SELECT id FROM fin_categories WHERE school_id = ? AND type IN ('income','both') AND is_active = 1 ORDER BY id LIMIT 1");
            $stmt->execute([$school_id]);
            $income_cat = $stmt->fetch();
            $income_cat_id = $income_cat ? $income_cat['id'] : null;

            $stmt = $pdo->prepare("
                INSERT INTO fin_income
                    (school_id, category_id, account_id, income_date, source,
                     description, amount, session, term, reference, recorded_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $income_source = $bill['student_name'] . ' (' . $bill['admission_number'] . ')';
            $income_desc   = ($bill['description'] ?? 'Bill Payment') . ' — ' . $bill['term'] . ' Term ' . $bill['session'];
            $stmt->execute([
                $school_id,
                $income_cat_id,
                $account_id,
                $payment_date,
                $income_source,
                $income_desc,
                $amount_paid,
                $bill['session'],
                $bill['term'],
                $receipt_no,
                $admin_id
            ]);
            $income_id = $pdo->lastInsertId();

            // 7. Insert into fin_ledger (credit entry)
            // Get default asset account or use provided
            $ledger_account_id = $account_id;
            if (!$ledger_account_id) {
                $stmt = $pdo->prepare("SELECT id FROM fin_accounts WHERE school_id = ? AND account_type = 'asset' AND is_active = 1 ORDER BY id LIMIT 1");
                $stmt->execute([$school_id]);
                $acct = $stmt->fetch();
                $ledger_account_id = $acct ? $acct['id'] : 1;
            }

            // Get running balance for this account
            $stmt = $pdo->prepare("SELECT COALESCE(balance, 0) FROM fin_ledger WHERE account_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$ledger_account_id]);
            $last_balance  = floatval($stmt->fetchColumn() ?? 0);
            $running_balance = $last_balance + $amount_paid;

            $stmt = $pdo->prepare("
                INSERT INTO fin_ledger
                    (school_id, account_id, entry_date, entry_type, amount, balance,
                     description, ref_type, ref_id, session, term, posted_by, created_at)
                VALUES (?, ?, ?, 'credit', ?, ?, ?, 'payment', ?, ?, ?, ?, NOW())
            ");
            $ledger_desc = "Payment received — {$bill['student_name']} | {$receipt_no}";
            $stmt->execute([
                $school_id,
                $ledger_account_id,
                $payment_date,
                $amount_paid,
                $running_balance,
                $ledger_desc,
                $payment_id,
                $bill['session'],
                $bill['term'],
                $admin_id
            ]);

            // 8. Update fin_accounts current_balance
            $stmt = $pdo->prepare("UPDATE fin_accounts SET current_balance = current_balance + ? WHERE id = ?");
            $stmt->execute([$amount_paid, $ledger_account_id]);

            // 9. Insert into fin_cashflow (inflow)
            $stmt = $pdo->prepare("
                INSERT INTO fin_cashflow
                    (school_id, flow_date, flow_type, amount, balance_after, description, source_ref, account_id, created_at)
                VALUES (?, ?, 'inflow', ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $school_id,
                $payment_date,
                $amount_paid,
                $running_balance,
                $ledger_desc,
                'payment:' . $payment_id,
                $ledger_account_id
            ]);

            $pdo->commit();

            echo json_encode([
                'success'        => true,
                'message'        => "Payment of ₦" . number_format($amount_paid, 2) . " recorded successfully.",
                'receipt_number' => $receipt_no,
                'payment_id'     => $payment_id,
                'new_status'     => $new_status,
                'new_balance'    => number_format($new_balance, 2),
                'new_amount_paid' => number_format($new_amount_paid, 2),
                'bill_id'        => $bill_id,
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Payment recording error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    // ── Get Bill Details (for payment modal) ───────────────────────────────
    if ($ajax_action === 'get_bill_details') {
        $bill_id = intval($_POST['bill_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("
                SELECT b.*, s.full_name AS student_name, s.admission_number,
                       bt.name AS bill_type_name
                FROM fin_bills b
                JOIN students s ON b.student_id = s.id
                LEFT JOIN fin_bill_types bt ON b.bill_type_id = bt.id
                WHERE b.id = ? AND b.school_id = ?
            ");
            $stmt->execute([$bill_id, $school_id]);
            $bill = $stmt->fetch();
            if (!$bill) throw new Exception('Bill not found');
            echo json_encode(['success' => true, 'bill' => $bill]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    // ── Batch Generate Bills ────────────────────────────────────────────────
    if ($ajax_action === 'batch_generate') {
        $bill_type_id = intval($_POST['bill_type_id'] ?? 0);
        $target_class = trim($_POST['target_class'] ?? '');

        try {
            $stmt = $pdo->prepare("SELECT * FROM fin_bill_types WHERE id = ? AND school_id = ? AND is_active = 1");
            $stmt->execute([$bill_type_id, $school_id]);
            $bill_type = $stmt->fetch();
            if (!$bill_type) throw new Exception('Bill template not found or inactive.');
            if (empty($target_class)) throw new Exception('Please select a class.');

            $stmt = $pdo->prepare("SELECT id, full_name, admission_number, class FROM students WHERE school_id = ? AND class = ? AND status = 'active'");
            $stmt->execute([$school_id, $target_class]);
            $students = $stmt->fetchAll();
            if (empty($students)) throw new Exception("No active students in class: $target_class");

            $generated = 0;
            $skipped   = 0;

            foreach ($students as $student) {
                $stmt = $pdo->prepare("SELECT id FROM fin_bills WHERE school_id = ? AND student_id = ? AND bill_type_id = ? AND session = ? AND term = ?");
                $stmt->execute([$school_id, $student['id'], $bill_type_id, $bill_type['session'], $bill_type['term']]);
                if ($stmt->fetch()) {
                    $skipped++;
                    continue;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO fin_bills
                        (school_id, bill_type_id, student_id, class, session, term,
                         description, amount, due_date, status, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
                ");
                $stmt->execute([
                    $school_id,
                    $bill_type_id,
                    $student['id'],
                    $student['class'],
                    $bill_type['session'],
                    $bill_type['term'],
                    $bill_type['name'],
                    $bill_type['default_amount'],
                    $bill_type['due_date'],
                    $admin_id
                ]);
                $generated++;
            }

            echo json_encode([
                'success'   => true,
                'message'   => "Generated $generated bills. Skipped $skipped (already exist).",
                'generated' => $generated,
                'skipped'   => $skipped,
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// Page Data
// ─────────────────────────────────────────────────────────────────────────────

// Filters
$filter_status  = $_GET['status']  ?? 'all';
$filter_term    = $_GET['term']    ?? 'all';
$filter_class   = $_GET['class']   ?? 'all';
$filter_session = $_GET['session'] ?? 'all';
$filter_search  = trim($_GET['search'] ?? '');

// Pagination
$page     = max(1, intval($_GET['page'] ?? 1));
$per_page = 25;
$offset   = ($page - 1) * $per_page;

// Build WHERE
$where  = ['b.school_id = ?'];
$params = [$school_id];

if ($filter_status !== 'all') {
    $where[]  = 'b.status = ?';
    $params[] = $filter_status;
}
if ($filter_term !== 'all') {
    $where[]  = 'b.term = ?';
    $params[] = $filter_term;
}
if ($filter_class !== 'all') {
    $where[]  = 'b.class = ?';
    $params[] = $filter_class;
}
if ($filter_session !== 'all') {
    $where[]  = 'b.session = ?';
    $params[] = $filter_session;
}
if ($filter_search !== '') {
    $where[]  = "(s.full_name LIKE ? OR s.admission_number LIKE ? OR b.description LIKE ?)";
    $like     = '%' . $filter_search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$where_sql = implode(' AND ', $where);

// Count
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM fin_bills b JOIN students s ON b.student_id = s.id WHERE $where_sql");
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages   = max(1, ceil($total_records / $per_page));

// Fetch bills
$stmt = $pdo->prepare("
    SELECT b.*,
           s.full_name AS student_name,
           s.admission_number,
           bt.name AS bill_type_name
    FROM fin_bills b
    JOIN students s ON b.student_id = s.id
    LEFT JOIN fin_bill_types bt ON b.bill_type_id = bt.id
    WHERE $where_sql
    ORDER BY b.created_at DESC
    LIMIT $offset, $per_page
");
$stmt->execute($params);
$bills = $stmt->fetchAll();

// Summary stats
try {
    $stmt = $pdo->prepare("SELECT
        COUNT(*) AS total_bills,
        SUM(amount) AS total_amount,
        SUM(amount_paid) AS total_paid,
        SUM(amount - amount_paid) AS total_balance,
        SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) AS paid_count,
        SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN status='part_paid' THEN 1 ELSE 0 END) AS part_paid_count,
        SUM(CASE WHEN status='overdue' THEN 1 ELSE 0 END) AS overdue_count
        FROM fin_bills WHERE school_id = ?");
    $stmt->execute([$school_id]);
    $stats = $stmt->fetch();
} catch (Exception $e) {
    $stats = [];
}

// Dropdowns
$stmt = $pdo->prepare("SELECT DISTINCT class FROM students WHERE school_id = ? AND status = 'active' AND class IS NOT NULL AND class != '' ORDER BY class");
$stmt->execute([$school_id]);
$classes = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, name, applies_to_class, session, term, default_amount FROM fin_bill_types WHERE school_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$school_id]);
$bill_templates = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, account_name FROM fin_accounts WHERE school_id = ? AND account_type = 'asset' AND is_active = 1 ORDER BY account_name");
$stmt->execute([$school_id]);
$accounts = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <!-- Prevent browser caching -->
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title><?php echo htmlspecialchars($school_name); ?> – Manage Bills</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: <?php echo $primary_color; ?>;
            --secondary: <?php echo $secondary_color; ?>;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --info: #3498db;
            --light: #ecf0f1;
            --dark: #2c3e50;
            --sidebar-w: 260px;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, .08);
            --shadow-md: 0 4px 16px rgba(0, 0, 0, .12);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 18px;
            --t: all .25s ease;
        }

        *,
        *::before,
        *::after {
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

        /* ── Layout ── */
        .main-content {
            min-height: 100vh;
            padding: 20px;
        }

        @media(min-width:768px) {
            .main-content {
                margin-left: var(--sidebar-w);
            }
        }

        @media(max-width:767px) {
            .main-content {
                padding-top: 70px;
            }
        }

        .mobile-menu-btn {
            position: fixed;
            top: 15px;
            right: 20px;
            z-index: 1001;
            width: 44px;
            height: 44px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: var(--radius-md);
            font-size: 20px;
            cursor: pointer;
            display: none;
        }

        @media(max-width:767px) {
            .mobile-menu-btn {
                display: flex;
                align-items: center;
                justify-content: center;
            }
        }

        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .5);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: var(--t);
        }

        .sidebar-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        /* ── Header ── */
        .top-header {
            background: #fff;
            padding: 20px;
            border-radius: var(--radius-md);
            margin-bottom: 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: var(--shadow-sm);
        }

        .header-title h1 {
            color: var(--primary);
            font-size: 1.4rem;
            margin-bottom: 4px;
        }

        .header-title p {
            color: #666;
            font-size: .82rem;
        }

        .header-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* ── Stats Bar ── */
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }

        .stat-pill {
            background: #fff;
            border-radius: var(--radius-md);
            padding: 14px 16px;
            box-shadow: var(--shadow-sm);
            border-top: 4px solid var(--light);
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .stat-pill.total {
            border-top-color: var(--primary);
        }

        .stat-pill.paid {
            border-top-color: var(--success);
        }

        .stat-pill.partial {
            border-top-color: var(--warning);
        }

        .stat-pill.overdue {
            border-top-color: var(--danger);
        }

        .stat-pill.pending {
            border-top-color: var(--info);
        }

        .stat-pill .val {
            font-size: 1.25rem;
            font-weight: 700;
            line-height: 1;
        }

        .stat-pill .lbl {
            font-size: .7rem;
            color: #777;
        }

        /* ── Buttons ── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 16px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: .82rem;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: var(--t);
        }

        .btn:hover {
            opacity: .88;
            transform: translateY(-1px);
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
        }

        .btn-secondary {
            background: var(--secondary);
            color: #fff;
        }

        .btn-success {
            background: var(--success);
            color: #fff;
        }

        .btn-warning {
            background: var(--warning);
            color: #fff;
        }

        .btn-danger {
            background: var(--danger);
            color: #fff;
        }

        .btn-info {
            background: var(--info);
            color: #fff;
        }

        .btn-light {
            background: var(--light);
            color: var(--dark);
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: .72rem;
        }

        /* ── Filters ── */
        .filter-card {
            background: #fff;
            padding: 16px 20px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: flex-end;
        }

        .filter-card .fg {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .filter-card label {
            font-size: .72rem;
            font-weight: 500;
            color: #555;
        }

        .filter-card select,
        .filter-card input {
            padding: 7px 10px;
            border: 1px solid #ddd;
            border-radius: var(--radius-sm);
            font-size: .8rem;
            font-family: inherit;
            min-width: 130px;
        }

        .filter-card select:focus,
        .filter-card input:focus {
            outline: none;
            border-color: var(--secondary);
        }

        /* ── Batch Generate Panel ── */
        .batch-panel {
            background: #fff;
            border-radius: var(--radius-md);
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--secondary);
        }

        .batch-panel h3 {
            font-size: .9rem;
            color: var(--primary);
            margin-bottom: 12px;
        }

        .batch-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: flex-end;
        }

        .batch-row .fg {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .batch-row label {
            font-size: .72rem;
            font-weight: 500;
            color: #555;
        }

        .batch-row select {
            padding: 7px 10px;
            border: 1px solid #ddd;
            border-radius: var(--radius-sm);
            font-size: .8rem;
            font-family: inherit;
        }

        .batch-row select:focus {
            outline: none;
            border-color: var(--secondary);
        }

        /* ── Table ── */
        .table-card {
            background: #fff;
            border-radius: var(--radius-md);
            padding: 20px;
            box-shadow: var(--shadow-sm);
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .table-header h3 {
            font-size: .95rem;
            color: var(--primary);
        }

        .table-wrap {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 11px 10px;
            text-align: left;
            border-bottom: 1px solid #eee;
            font-size: .78rem;
        }

        .data-table th {
            background: var(--light);
            font-weight: 600;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .data-table tr:hover td {
            background: #fafafa;
        }

        /* ── Badges ── */
        .badge {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: .67rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .badge-success {
            background: #d5f4e6;
            color: var(--success);
        }

        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }

        .badge-danger {
            background: #f8d7da;
            color: var(--danger);
        }

        .badge-info {
            background: #d1ecf1;
            color: #0c5460;
        }

        .badge-secondary {
            background: #e2e3e5;
            color: #383d41;
        }

        /* ── Action icons ── */
        .act {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 5px 8px;
            border-radius: var(--radius-sm);
            font-size: .7rem;
            text-decoration: none;
            cursor: pointer;
            border: none;
            font-family: inherit;
            transition: var(--t);
        }

        .act:hover {
            opacity: .85;
        }

        .act-pay {
            background: var(--success);
            color: #fff;
        }

        .act-view {
            background: var(--info);
            color: #fff;
        }

        .act-cancel {
            background: var(--danger);
            color: #fff;
        }

        /* ── Pagination ── */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 6px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .page-link {
            padding: 7px 12px;
            border: 1px solid #ddd;
            border-radius: var(--radius-sm);
            text-decoration: none;
            color: #666;
            font-size: .78rem;
            transition: var(--t);
        }

        .page-link.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .page-link:hover:not(.active) {
            border-color: var(--secondary);
        }

        /* ── Progress Bar ── */
        .prog-bar {
            height: 5px;
            background: #eee;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 4px;
        }

        .prog-fill {
            height: 100%;
            border-radius: 4px;
            transition: .4s;
        }

        /* ── MODAL ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .55);
            z-index: 9000;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal-box {
            background: #fff;
            border-radius: var(--radius-lg);
            width: 100%;
            max-width: 560px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-md);
            animation: slideUp .25s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 22px;
            border-bottom: 2px solid var(--light);
            position: sticky;
            top: 0;
            background: #fff;
            z-index: 1;
        }

        .modal-head h3 {
            color: var(--primary);
            font-size: 1rem;
        }

        .modal-close {
            background: var(--light);
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            font-size: 16px;
            cursor: pointer;
            transition: var(--t);
        }

        .modal-close:hover {
            background: #ddd;
        }

        .modal-body {
            padding: 20px 22px;
        }

        .modal-foot {
            padding: 14px 22px;
            border-top: 1px solid var(--light);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            position: sticky;
            bottom: 0;
            background: #fff;
        }

        /* Bill info strip */
        .bill-info-strip {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: #fff;
            border-radius: var(--radius-sm);
            padding: 14px 18px;
            margin-bottom: 18px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .bil-item .lbl {
            font-size: .65rem;
            opacity: .8;
        }

        .bil-item .val {
            font-size: .9rem;
            font-weight: 600;
        }

        /* Form grid */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        @media(max-width:480px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        .fg {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .fg.full {
            grid-column: 1/-1;
        }

        .fg label {
            font-size: .75rem;
            font-weight: 500;
            color: #555;
        }

        .fg input,
        .fg select,
        .fg textarea {
            padding: 9px 12px;
            border: 1px solid #ddd;
            border-radius: var(--radius-sm);
            font-size: .82rem;
            font-family: inherit;
            transition: var(--t);
        }

        .fg input:focus,
        .fg select:focus,
        .fg textarea:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(0, 0, 0, .05);
        }

        /* Alert */
        .alert {
            padding: 11px 16px;
            border-radius: var(--radius-sm);
            margin-bottom: 16px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: .82rem;
        }

        .alert-success {
            background: #d5f4e6;
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .alert-error {
            background: #f8d7da;
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid var(--info);
        }

        /* Toast */
        #toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 99999;
            background: #2c3e50;
            color: #fff;
            padding: 12px 20px;
            border-radius: var(--radius-md);
            font-size: .82rem;
            max-width: 340px;
            display: none;
            box-shadow: var(--shadow-md);
            animation: fadeIn .3s;
        }

        #toast.show {
            display: block;
        }

        #toast.success {
            background: var(--success);
        }

        #toast.error {
            background: var(--danger);
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Loading spinner */
        .spinner {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 3px solid rgba(255, 255, 255, .4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .7s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .footer {
            text-align: center;
            padding: 20px;
            color: #888;
            font-size: .78rem;
            border-top: 1px solid var(--light);
            margin-top: 20px;
        }
    </style>
</head>

<body>

    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="main-content">

        <!-- ── Header ── -->
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-file-invoice-dollar" style="margin-right:8px;color:var(--secondary)"></i>Manage Bills</h1>
                <p>View, generate and collect payments for student bills · <?php echo htmlspecialchars($school_name); ?></p>
            </div>
            <div class="header-actions">
                <a href="finance_bill_types.php" class="btn btn-light btn-sm"><i class="fas fa-tags"></i> Bill Templates</a>
                <a href="finance_dashboard.php" class="btn btn-secondary btn-sm"><i class="fas fa-chart-line"></i> Dashboard</a>
            </div>
        </div>

        <!-- ── Stats ── -->
        <div class="stats-bar">
            <div class="stat-pill total">
                <span class="val">₦<?php echo number_format($stats['total_amount'] ?? 0, 2); ?></span>
                <span class="lbl">Total Billed (<?php echo number_format($stats['total_bills'] ?? 0); ?> bills)</span>
            </div>
            <div class="stat-pill paid">
                <span class="val">₦<?php echo number_format($stats['total_paid'] ?? 0, 2); ?></span>
                <span class="lbl">Total Collected · <?php echo $stats['paid_count'] ?? 0; ?> fully paid</span>
            </div>
            <div class="stat-pill partial">
                <span class="val">₦<?php echo number_format($stats['total_balance'] ?? 0, 2); ?></span>
                <span class="lbl">Outstanding · <?php echo $stats['part_paid_count'] ?? 0; ?> part-paid</span>
            </div>
            <div class="stat-pill overdue">
                <span class="val"><?php echo number_format($stats['overdue_count'] ?? 0); ?></span>
                <span class="lbl">Overdue Bills</span>
            </div>
            <div class="stat-pill pending">
                <span class="val"><?php echo number_format($stats['pending_count'] ?? 0); ?></span>
                <span class="lbl">Pending Bills</span>
            </div>
        </div>

        <!-- ── Batch Generate ── -->
        <div class="batch-panel">
            <h3><i class="fas fa-magic" style="color:var(--secondary)"></i> Batch Generate Bills from Template</h3>
            <div class="batch-row">
                <div class="fg">
                    <label>Bill Template</label>
                    <select id="batchTemplate">
                        <option value="">— Select Template —</option>
                        <?php foreach ($bill_templates as $tpl): ?>
                            <option value="<?php echo $tpl['id']; ?>"
                                data-class="<?php echo htmlspecialchars($tpl['applies_to_class'] ?? ''); ?>">
                                <?php echo htmlspecialchars($tpl['name']); ?>
                                (<?php echo htmlspecialchars($tpl['session'] ?? ''); ?> · <?php echo $tpl['term']; ?> Term)
                                · ₦<?php echo number_format($tpl['default_amount'], 2); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label>Target Class</label>
                    <select id="batchClass">
                        <option value="">— Select Class —</option>
                        <?php foreach ($classes as $cl): ?>
                            <option value="<?php echo htmlspecialchars($cl['class']); ?>">
                                <?php echo htmlspecialchars($cl['class']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-secondary" id="batchGenerateBtn" onclick="runBatchGenerate()">
                    <i class="fas fa-bolt"></i> Generate Bills
                </button>
            </div>
            <div id="batchResult" style="margin-top:10px; display:none;"></div>
        </div>

        <!-- ── Filters ── -->
        <form method="GET" action="" id="filterForm">
            <div class="filter-card">
                <div class="fg">
                    <label>Status</label>
                    <select name="status" onchange="document.getElementById('filterForm').submit()">
                        <option value="all" <?php echo $filter_status === 'all'       ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="pending" <?php echo $filter_status === 'pending'   ? 'selected' : ''; ?>>Pending</option>
                        <option value="part_paid" <?php echo $filter_status === 'part_paid' ? 'selected' : ''; ?>>Part Paid</option>
                        <option value="paid" <?php echo $filter_status === 'paid'      ? 'selected' : ''; ?>>Paid</option>
                        <option value="overdue" <?php echo $filter_status === 'overdue'   ? 'selected' : ''; ?>>Overdue</option>
                        <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="fg">
                    <label>Term</label>
                    <select name="term" onchange="document.getElementById('filterForm').submit()">
                        <option value="all" <?php echo $filter_term === 'all'    ? 'selected' : ''; ?>>All Terms</option>
                        <option value="First" <?php echo $filter_term === 'First'  ? 'selected' : ''; ?>>First Term</option>
                        <option value="Second" <?php echo $filter_term === 'Second' ? 'selected' : ''; ?>>Second Term</option>
                        <option value="Third" <?php echo $filter_term === 'Third'  ? 'selected' : ''; ?>>Third Term</option>
                    </select>
                </div>
                <div class="fg">
                    <label>Class</label>
                    <select name="class" onchange="document.getElementById('filterForm').submit()">
                        <option value="all">All Classes</option>
                        <?php foreach ($classes as $cl): ?>
                            <option value="<?php echo htmlspecialchars($cl['class']); ?>"
                                <?php echo $filter_class === $cl['class'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cl['class']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label>Session</label>
                    <select name="session" onchange="document.getElementById('filterForm').submit()">
                        <option value="all">All Sessions</option>
                        <option value="<?php echo $current_session; ?>" <?php echo $filter_session === $current_session ? 'selected' : ''; ?>><?php echo $current_session; ?> (Current)</option>
                        <?php
                        $yr = (int)date('Y');
                        for ($i = 0; $i < 3; $i++) {
                            $s = ($yr - $i - 1) . '/' . ($yr - $i);
                            echo "<option value=\"$s\" " . ($filter_session === $s ? 'selected' : '') . ">$s</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="fg">
                    <label>Search</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($filter_search); ?>" placeholder="Name / Adm. No. / Description">
                </div>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
                <a href="finance_bills.php" class="btn btn-light btn-sm"><i class="fas fa-times"></i> Reset</a>
            </div>
        </form>

        <!-- ── Bills Table ── -->
        <div class="table-card">
            <div class="table-header">
                <h3><i class="fas fa-list"></i> Bills
                    <small style="color:#999;font-size:.72rem;margin-left:6px;"><?php echo number_format($total_records); ?> record<?php echo $total_records !== 1 ? 's' : ''; ?></small>
                </h3>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Class</th>
                            <th>Description</th>
                            <th>Session/Term</th>
                            <th>Amount (₦)</th>
                            <th>Paid (₦)</th>
                            <th>Balance (₦)</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bills)): ?>
                            <tr>
                                <td colspan="11" style="text-align:center;padding:40px;color:#aaa;">
                                    <i class="fas fa-file-invoice-dollar" style="font-size:36px;display:block;margin-bottom:10px;opacity:.35"></i>
                                    No bills found matching the current filters
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($bills as $i => $bill):
                                $balance = floatval($bill['amount']) - floatval($bill['amount_paid']);
                                $pct     = floatval($bill['amount']) > 0 ? round((floatval($bill['amount_paid']) / floatval($bill['amount'])) * 100) : 0;
                                $badge   = [
                                    'pending'   => 'badge-info',
                                    'part_paid' => 'badge-warning',
                                    'paid'      => 'badge-success',
                                    'overdue'   => 'badge-danger',
                                    'cancelled' => 'badge-secondary',
                                ][$bill['status']] ?? 'badge-secondary';
                                $is_payable = !in_array($bill['status'], ['paid', 'cancelled']);
                            ?>
                                <tr id="bill-row-<?php echo $bill['id']; ?>">
                                    <td style="color:#aaa"><?php echo $offset + $i + 1; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($bill['student_name']); ?></strong>
                                        <br><small style="color:#999"><?php echo htmlspecialchars($bill['admission_number']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($bill['class']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars(substr($bill['description'] ?? $bill['bill_type_name'] ?? '—', 0, 45)); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($bill['session']); ?><br><small><?php echo $bill['term']; ?> Term</small></td>
                                    <td style="font-weight:600">₦<?php echo number_format($bill['amount'], 2); ?></td>
                                    <td style="color:var(--success);font-weight:600">
                                        ₦<?php echo number_format($bill['amount_paid'], 2); ?>
                                        <div class="prog-bar">
                                            <div class="prog-fill" style="width:<?php echo $pct; ?>%;background:var(--success)"></div>
                                        </div>
                                    </td>
                                    <td style="color:<?php echo $balance > 0 ? 'var(--danger)' : 'var(--success)'; ?>;font-weight:600">
                                        ₦<?php echo number_format($balance, 2); ?>
                                    </td>
                                    <td><?php echo $bill['due_date'] ? date('d M Y', strtotime($bill['due_date'])) : '—'; ?></td>
                                    <td>
                                        <span class="badge <?php echo $badge; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $bill['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($is_payable): ?>
                                            <button class="act act-pay"
                                                onclick="openPaymentModal(<?php echo $bill['id']; ?>, '<?php echo addslashes($bill['student_name']); ?>', '<?php echo addslashes($bill['admission_number']); ?>', <?php echo $bill['amount']; ?>, <?php echo $bill['amount_paid']; ?>, '<?php echo $bill['description'] ?? $bill['bill_type_name'] ?? ''; ?>', '<?php echo $bill['session']; ?>', '<?php echo $bill['term']; ?>')">
                                                <i class="fas fa-money-bill-wave"></i> Pay
                                            </button>
                                        <?php else: ?>
                                            <span style="color:#aaa;font-size:.72rem">—</span>
                                        <?php endif; ?>
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
                        <a href="?page=<?php echo $page - 1; ?>&status=<?php echo $filter_status; ?>&term=<?php echo $filter_term; ?>&class=<?php echo urlencode($filter_class); ?>&session=<?php echo urlencode($filter_session); ?>&search=<?php echo urlencode($filter_search); ?>" class="page-link">&#8249; Prev</a>
                    <?php endif; ?>
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($total_pages, $page + 2);
                    if ($start > 1) echo '<span class="page-link" style="pointer-events:none">…</span>';
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <a href="?page=<?php echo $i; ?>&status=<?php echo $filter_status; ?>&term=<?php echo $filter_term; ?>&class=<?php echo urlencode($filter_class); ?>&session=<?php echo urlencode($filter_session); ?>&search=<?php echo urlencode($filter_search); ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor;
                    if ($end < $total_pages) echo '<span class="page-link" style="pointer-events:none">…</span>';
                    ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&status=<?php echo $filter_status; ?>&term=<?php echo $filter_term; ?>&class=<?php echo urlencode($filter_class); ?>&session=<?php echo urlencode($filter_session); ?>&search=<?php echo urlencode($filter_search); ?>" class="page-link">Next &#8250;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="footer">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> – Finance Management System</div>
    </div><!-- /main-content -->


    <!-- ═══════════════════════════════════════════════════════════════
     PAYMENT MODAL
═══════════════════════════════════════════════════════════════ -->
    <div class="modal-overlay" id="paymentModal">
        <div class="modal-box">
            <div class="modal-head">
                <h3><i class="fas fa-money-bill-wave" style="color:var(--success);margin-right:8px"></i>Record Payment</h3>
                <button class="modal-close" onclick="closePaymentModal()">&#10005;</button>
            </div>
            <div class="modal-body">

                <!-- Bill summary strip -->
                <div class="bill-info-strip">
                    <div class="bil-item">
                        <div class="lbl">Student</div>
                        <div class="val" id="pm_student_name">—</div>
                    </div>
                    <div class="bil-item">
                        <div class="lbl">Adm. No.</div>
                        <div class="val" id="pm_adm_no">—</div>
                    </div>
                    <div class="bil-item">
                        <div class="lbl">Bill</div>
                        <div class="val" id="pm_desc" style="font-size:.78rem;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">—</div>
                    </div>
                    <div class="bil-item">
                        <div class="lbl">Total Bill</div>
                        <div class="val" id="pm_total">₦0.00</div>
                    </div>
                    <div class="bil-item">
                        <div class="lbl">Already Paid</div>
                        <div class="val" id="pm_paid">₦0.00</div>
                    </div>
                    <div class="bil-item">
                        <div class="lbl">Balance Due</div>
                        <div class="val" id="pm_balance" style="color:#ffe08a">₦0.00</div>
                    </div>
                </div>

                <div id="paymentAlert" style="display:none;"></div>

                <div class="form-grid">
                    <input type="hidden" id="pm_bill_id">

                    <div class="fg">
                        <label>Amount to Pay (₦) *</label>
                        <input type="number" id="pm_amount" min="1" step="0.01" placeholder="0.00" oninput="validatePayAmount()">
                        <small id="pm_amount_hint" style="color:var(--danger);font-size:.7rem;display:none">Amount exceeds balance due</small>
                    </div>
                    <div class="fg">
                        <label>Payment Date *</label>
                        <input type="date" id="pm_date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="fg">
                        <label>Payment Method *</label>
                        <select id="pm_method">
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="pos">POS</option>
                            <option value="cheque">Cheque</option>
                            <option value="online">Online Payment</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Reference / Teller No.</label>
                        <input type="text" id="pm_reference" placeholder="Optional">
                    </div>
                    <?php if (!empty($accounts)): ?>
                        <div class="fg">
                            <label>Deposit Account</label>
                            <select id="pm_account">
                                <option value="">— Default Account —</option>
                                <?php foreach ($accounts as $acct): ?>
                                    <option value="<?php echo $acct['id']; ?>"><?php echo htmlspecialchars($acct['account_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="fg full">
                        <label>Notes</label>
                        <textarea id="pm_notes" rows="2" placeholder="Optional remarks"></textarea>
                    </div>
                </div>

                <!-- Pay full balance shortcut -->
                <div style="margin-top:12px;">
                    <button type="button" class="btn btn-light btn-sm" onclick="payFullBalance()">
                        <i class="fas fa-check-double"></i> Pay Full Balance
                    </button>
                    <small style="color:#aaa;margin-left:8px;font-size:.7rem">Fills in the outstanding balance automatically</small>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn btn-light" onclick="closePaymentModal()">Cancel</button>
                <button class="btn btn-success" id="submitPaymentBtn" onclick="submitPayment()">
                    <i class="fas fa-save"></i> Save Payment
                </button>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast"></div>

    <script>
        // ─── Global state ─────────────────────────────────────────────────────────────
        let currentBillBalance = 0;

        // ─── Mobile sidebar ───────────────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', function() {
            const btn = document.getElementById('mobileMenuBtn');
            const overlay = document.getElementById('sidebarOverlay');
            setTimeout(() => {
                const sb = document.getElementById('sidebar');
                if (btn && sb) {
                    btn.addEventListener('click', () => {
                        sb.classList.toggle('active');
                        overlay?.classList.toggle('active');
                        document.body.style.overflow = sb.classList.contains('active') ? 'hidden' : '';
                    });
                }
                overlay?.addEventListener('click', () => {
                    sb?.classList.remove('active');
                    overlay.classList.remove('active');
                    document.body.style.overflow = '';
                });
            }, 100);

            // Auto-populate batch class when template is selected
            document.getElementById('batchTemplate').addEventListener('change', function() {
                const opt = this.options[this.selectedIndex];
                const cls = opt.dataset.class;
                const classSelect = document.getElementById('batchClass');
                if (cls && cls !== '') {
                    classSelect.value = cls;
                }
            });
        });

        // ─── Toast ────────────────────────────────────────────────────────────────────
        function showToast(msg, type = 'success', duration = 4000) {
            const t = document.getElementById('toast');
            t.className = 'show ' + type;
            t.textContent = msg;
            setTimeout(() => t.className = '', duration);
        }

        // ─── Payment Modal ────────────────────────────────────────────────────────────
        function openPaymentModal(billId, studentName, admNo, amount, amountPaid, desc, session, term) {
            const balance = parseFloat(amount) - parseFloat(amountPaid);
            currentBillBalance = balance;

            document.getElementById('pm_bill_id').value = billId;
            document.getElementById('pm_student_name').textContent = studentName;
            document.getElementById('pm_adm_no').textContent = admNo;
            document.getElementById('pm_desc').textContent = desc || '—';
            document.getElementById('pm_total').textContent = '₦' + parseFloat(amount).toLocaleString('en-NG', {
                minimumFractionDigits: 2
            });
            document.getElementById('pm_paid').textContent = '₦' + parseFloat(amountPaid).toLocaleString('en-NG', {
                minimumFractionDigits: 2
            });
            document.getElementById('pm_balance').textContent = '₦' + balance.toLocaleString('en-NG', {
                minimumFractionDigits: 2
            });

            // Reset form
            document.getElementById('pm_amount').value = '';
            document.getElementById('pm_date').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('pm_method').value = 'cash';
            document.getElementById('pm_reference').value = '';
            document.getElementById('pm_notes').value = '';
            if (document.getElementById('pm_account')) document.getElementById('pm_account').value = '';
            document.getElementById('pm_amount_hint').style.display = 'none';
            document.getElementById('paymentAlert').style.display = 'none';

            document.getElementById('paymentModal').classList.add('open');
            document.getElementById('pm_amount').focus();
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').classList.remove('open');
        }

        // Click outside to close
        document.getElementById('paymentModal').addEventListener('click', function(e) {
            if (e.target === this) closePaymentModal();
        });

        function payFullBalance() {
            document.getElementById('pm_amount').value = currentBillBalance.toFixed(2);
            validatePayAmount();
        }

        function validatePayAmount() {
            const val = parseFloat(document.getElementById('pm_amount').value) || 0;
            const hint = document.getElementById('pm_amount_hint');
            hint.style.display = val > currentBillBalance ? 'block' : 'none';
        }

        function submitPayment() {
            const billId = document.getElementById('pm_bill_id').value;
            const amount = parseFloat(document.getElementById('pm_amount').value);
            const date = document.getElementById('pm_date').value;
            const method = document.getElementById('pm_method').value;
            const reference = document.getElementById('pm_reference').value;
            const notes = document.getElementById('pm_notes').value;
            const accountEl = document.getElementById('pm_account');
            const account = accountEl ? accountEl.value : '';

            const alertBox = document.getElementById('paymentAlert');
            alertBox.style.display = 'none';

            if (!amount || amount <= 0) {
                alertBox.className = 'alert alert-error';
                alertBox.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please enter a valid amount.';
                alertBox.style.display = 'flex';
                return;
            }
            if (amount > currentBillBalance) {
                alertBox.className = 'alert alert-error';
                alertBox.innerHTML = '<i class="fas fa-exclamation-circle"></i> Amount exceeds the balance due.';
                alertBox.style.display = 'flex';
                return;
            }
            if (!date) {
                alertBox.className = 'alert alert-error';
                alertBox.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please select a payment date.';
                alertBox.style.display = 'flex';
                return;
            }

            const btn = document.getElementById('submitPaymentBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Saving…';

            const fd = new FormData();
            fd.append('ajax_action', 'record_payment');
            fd.append('bill_id', billId);
            fd.append('amount_paid', amount);
            fd.append('payment_date', date);
            fd.append('payment_method', method);
            fd.append('reference_number', reference);
            fd.append('notes', notes);
            if (account) fd.append('account_id', account);

            fetch('finance_bills.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        closePaymentModal();
                        showToast('✓ ' + data.message + '  Receipt: ' + data.receipt_number, 'success', 6000);
                        // Update the row in-place
                        updateBillRow(data.bill_id, data.new_status, data.new_amount_paid, data.new_balance);
                    } else {
                        alertBox.className = 'alert alert-error';
                        alertBox.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + data.message;
                        alertBox.style.display = 'flex';
                    }
                })
                .catch(() => {
                    alertBox.className = 'alert alert-error';
                    alertBox.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error. Please try again.';
                    alertBox.style.display = 'flex';
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-save"></i> Save Payment';
                });
        }

        function updateBillRow(billId, newStatus, newAmountPaid, newBalance) {
            const row = document.getElementById('bill-row-' + billId);
            if (!row) return;

            const badgeMap = {
                pending: 'badge-info',
                part_paid: 'badge-warning',
                paid: 'badge-success',
                overdue: 'badge-danger',
                cancelled: 'badge-secondary'
            };

            // Columns: 0=#, 1=student, 2=class, 3=desc, 4=session, 5=amount, 6=paid, 7=balance, 8=due, 9=status, 10=actions
            const cells = row.querySelectorAll('td');

            // Update paid
            cells[6].innerHTML = '₦' + parseFloat(newAmountPaid.replace(/,/g, '')).toLocaleString('en-NG', {
                minimumFractionDigits: 2
            });

            // Update balance
            const balNum = parseFloat(newBalance.replace(/,/g, ''));
            cells[7].style.color = balNum > 0 ? 'var(--danger)' : 'var(--success)';
            cells[7].innerHTML = '₦' + balNum.toLocaleString('en-NG', {
                minimumFractionDigits: 2
            });

            // Update status badge
            const label = newStatus.replace('_', ' ');
            cells[9].innerHTML = `<span class="badge ${badgeMap[newStatus] || 'badge-secondary'}">${label.charAt(0).toUpperCase()+label.slice(1)}</span>`;

            // Hide Pay button if paid
            if (newStatus === 'paid') {
                cells[10].innerHTML = '<span style="color:#aaa;font-size:.72rem">—</span>';
            } else {
                // Update balance for next click
                const payBtn = cells[10].querySelector('.act-pay');
                if (payBtn) {
                    const totalAmnt = parseFloat(cells[5].textContent.replace(/[₦,]/g, ''));
                    const paidAmnt = parseFloat(newAmountPaid.replace(/,/g, ''));
                    const desc = cells[3].textContent.trim();
                    const sess = cells[4].textContent.split('\n')[0].trim();
                    const term = cells[4].textContent.split('\n')[1]?.replace(' Term', '').trim() || '';
                    const sName = cells[1].querySelector('strong').textContent;
                    const admNo = cells[1].querySelector('small').textContent;
                    payBtn.setAttribute('onclick',
                        `openPaymentModal(${billId}, '${sName.replace(/'/g,"\\'")}', '${admNo}', ${totalAmnt}, ${paidAmnt}, '${desc.replace(/'/g,"\\'")}', '${sess}', '${term}')`
                    );
                }
            }

            // Flash the row
            row.style.transition = 'background .5s';
            row.style.background = '#e8f8f0';
            setTimeout(() => {
                row.style.background = '';
            }, 1500);
        }

        // ─── Batch Generate ───────────────────────────────────────────────────────────
        function runBatchGenerate() {
            const tplId = document.getElementById('batchTemplate').value;
            const cls = document.getElementById('batchClass').value;
            const res = document.getElementById('batchResult');
            const btn = document.getElementById('batchGenerateBtn');

            if (!tplId || !cls) {
                res.innerHTML = '<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> Please select both a template and a class.</div>';
                res.style.display = 'block';
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Generating…';
            res.style.display = 'none';

            const fd = new FormData();
            fd.append('ajax_action', 'batch_generate');
            fd.append('bill_type_id', tplId);
            fd.append('target_class', cls);

            fetch('finance_bills.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        res.innerHTML = `<div class="alert alert-success"><i class="fas fa-check-circle"></i> ${data.message}</div>`;
                        showToast('✓ ' + data.message, 'success', 5000);
                        // Refresh table after short delay
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        res.innerHTML = `<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> ${data.message}</div>`;
                    }
                    res.style.display = 'block';
                })
                .catch(() => {
                    res.innerHTML = '<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> Network error. Please try again.</div>';
                    res.style.display = 'block';
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-bolt"></i> Generate Bills';
                });
        }
    </script>

    <?php require_once 'includes/sidebar.php'; ?>
</body>

</html>
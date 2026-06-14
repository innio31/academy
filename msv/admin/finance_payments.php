<?php
// msv/admin/finance_payments.php - Manage Payments (Updated to handle student-uploaded payments)
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../includes/config.php';
check_page_access(['acct', 'super_admin']);

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

$current_session = date('Y') . '/' . (date('Y') + 1);

$message = '';
$message_type = '';

// ──────────────────────────────────────────────────────────────
// HELPER FUNCTIONS
// ──────────────────────────────────────────────────────────────
function postToLedgerAndIncome($pdo, $school_id, $payment_id, $amount, $payment_date, $student_name, $admin_id)
{
    try {
        $stmt = $pdo->prepare("SELECT id FROM fin_accounts WHERE school_id = ? AND account_name LIKE '%School Fees%' AND account_type = 'income' LIMIT 1");
        $stmt->execute([$school_id]);
        $income_account = $stmt->fetch();

        if (!$income_account) {
            $stmt = $pdo->prepare("INSERT INTO fin_accounts (school_id, account_name, account_type, opening_balance, current_balance, is_active) VALUES (?, 'School Fees Income', 'income', 0, 0, 1)");
            $stmt->execute([$school_id]);
            $income_account_id = $pdo->lastInsertId();
        } else {
            $income_account_id = $income_account['id'];
        }

        $stmt = $pdo->prepare("SELECT id FROM fin_accounts WHERE school_id = ? AND account_type = 'asset' LIMIT 1");
        $stmt->execute([$school_id]);
        $cash_account = $stmt->fetch();
        $cash_account_id = $cash_account ? $cash_account['id'] : null;

        if ($cash_account_id) {
            $stmt = $pdo->prepare("SELECT current_balance FROM fin_accounts WHERE id = ?");
            $stmt->execute([$cash_account_id]);
            $cash_balance = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT current_balance FROM fin_accounts WHERE id = ?");
            $stmt->execute([$income_account_id]);
            $income_balance = $stmt->fetchColumn();

            $stmt = $pdo->prepare("
                INSERT INTO fin_ledger (school_id, account_id, entry_date, entry_type, amount, balance, description, ref_type, ref_id, posted_by, created_at)
                VALUES (?, ?, ?, 'debit', ?, ?, ?, 'payment', ?, ?, NOW())
            ");
            $new_cash_balance = $cash_balance + $amount;
            $stmt->execute([$school_id, $cash_account_id, $payment_date, $amount, $new_cash_balance, "Payment from " . $student_name, $payment_id, $admin_id]);

            $stmt = $pdo->prepare("UPDATE fin_accounts SET current_balance = current_balance + ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$amount, $cash_account_id]);

            $stmt = $pdo->prepare("
                INSERT INTO fin_ledger (school_id, account_id, entry_date, entry_type, amount, balance, description, ref_type, ref_id, posted_by, created_at)
                VALUES (?, ?, ?, 'credit', ?, ?, ?, 'payment', ?, ?, NOW())
            ");
            $new_income_balance = $income_balance - $amount;
            $stmt->execute([$school_id, $income_account_id, $payment_date, $amount, $new_income_balance, "School fees from " . $student_name, $payment_id, $admin_id]);

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
    $stmt = $pdo->prepare("SELECT COUNT(*) + 1 as next_num FROM fin_receipts WHERE school_id = ? AND YEAR(issued_at) = ?");
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

// ──────────────────────────────────────────────────────────────
// PROCESS POST ACTIONS
// ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {

        // ── Record new payment (admin recorded) ──────────────────────────────
        if ($_POST['action'] === 'record_payment') {
            $bill_id        = intval($_POST['bill_id'] ?? 0);
            $student_id     = intval($_POST['student_id'] ?? 0);
            $amount_paid    = floatval($_POST['amount_paid'] ?? 0);
            $payment_date   = $_POST['payment_date'] ?? date('Y-m-d');
            $payment_method = $_POST['payment_method'] ?? 'cash';
            $reference_number = trim($_POST['reference_number'] ?? '');
            $notes          = trim($_POST['notes'] ?? '');
            $account_id     = !empty($_POST['account_id']) ? intval($_POST['account_id']) : null;

            if ($amount_paid <= 0) {
                $message = "Please enter a valid amount";
                $message_type = "error";
            } else {
                try {
                    if ($bill_id > 0) {
                        $stmt = $pdo->prepare("SELECT student_id, amount, amount_paid FROM fin_bills WHERE id = ? AND school_id = ?");
                        $stmt->execute([$bill_id, $school_id]);
                        $bill = $stmt->fetch();
                        if (!$bill) throw new Exception("Bill not found");
                        $student_id   = $bill['student_id'];
                        $bill_amount  = $bill['amount'];
                        $current_paid = $bill['amount_paid'];
                    } elseif ($student_id > 0) {
                        $bill_amount  = 0;
                        $current_paid = 0;
                    } else {
                        throw new Exception("Please select either a bill or a student");
                    }

                    // Determine initial status — cash/small amounts auto-verified
                    $initial_status = ($payment_method === 'cash' || $amount_paid < 5000) ? 'verified' : 'pending';

                    $stmt = $pdo->prepare("
                        INSERT INTO fin_payments (school_id, bill_id, student_id, amount_paid, payment_date, payment_method, reference_number, notes, account_id, status, recorded_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $school_id,
                        $bill_id > 0 ? $bill_id : null,
                        $student_id,
                        $amount_paid,
                        $payment_date,
                        $payment_method,
                        $reference_number,
                        $notes,
                        $account_id,
                        $initial_status,
                        $admin_id
                    ]);
                    $payment_id = $pdo->lastInsertId();

                    if ($initial_status === 'verified') {
                        $stmt = $pdo->prepare("UPDATE fin_payments SET verified_by = ?, verified_at = NOW() WHERE id = ? AND school_id = ?");
                        $stmt->execute([$admin_id, $payment_id, $school_id]);

                        if ($bill_id > 0) {
                            $new_paid   = $current_paid + $amount_paid;
                            $new_status = ($new_paid >= $bill_amount) ? 'paid' : 'part_paid';
                            $stmt = $pdo->prepare("UPDATE fin_bills SET amount_paid = ?, status = ?, updated_at = NOW() WHERE id = ? AND school_id = ?");
                            $stmt->execute([$new_paid, $new_status, $bill_id, $school_id]);
                        }

                        $student_name   = getStudentName($pdo, $student_id);
                        $receipt_number = generateReceiptNumber($pdo, $school_id);
                        $stmt = $pdo->prepare("INSERT INTO fin_receipts (school_id, payment_id, receipt_number, issued_to, issued_by, issued_at, amount) VALUES (?, ?, ?, ?, ?, NOW(), ?)");
                        $stmt->execute([$school_id, $payment_id, $receipt_number, $student_name, $admin_id, $amount_paid]);

                        postToLedgerAndIncome($pdo, $school_id, $payment_id, $amount_paid, $payment_date, $student_name, $admin_id);
                        $message = "Payment recorded and verified! Receipt #$receipt_number issued.";
                    } else {
                        $message = "Payment recorded and is awaiting verification.";
                    }
                    $message_type = "success";
                } catch (Exception $e) {
                    $message = "Error recording payment: " . $e->getMessage();
                    $message_type = "error";
                }
            }
        }

        // ── Verify payment (for both admin-recorded AND student-uploaded) ──────────────────────────────────
        elseif ($_POST['action'] === 'verify_payment') {
            $payment_id = intval($_POST['payment_id']);
            try {
                $stmt = $pdo->prepare("
                    SELECT p.*, b.amount as bill_amount, b.amount_paid as current_paid, s.full_name as student_name
                    FROM fin_payments p
                    LEFT JOIN fin_bills b ON p.bill_id = b.id
                    LEFT JOIN students s ON p.student_id = s.id
                    WHERE p.id = ? AND p.school_id = ? AND p.status = 'pending'
                ");
                $stmt->execute([$payment_id, $school_id]);
                $payment = $stmt->fetch();

                if (!$payment) throw new Exception("Payment not found or already processed");

                $stmt = $pdo->prepare("UPDATE fin_payments SET status = 'verified', verified_by = ?, verified_at = NOW() WHERE id = ? AND school_id = ?");
                $stmt->execute([$admin_id, $payment_id, $school_id]);

                if ($payment['bill_id']) {
                    $new_paid   = $payment['current_paid'] + $payment['amount_paid'];
                    $new_status = ($new_paid >= $payment['bill_amount']) ? 'paid' : 'part_paid';
                    $stmt = $pdo->prepare("UPDATE fin_bills SET amount_paid = ?, status = ?, updated_at = NOW() WHERE id = ? AND school_id = ?");
                    $stmt->execute([$new_paid, $new_status, $payment['bill_id'], $school_id]);
                }

                $student_name   = $payment['student_name'] ?? getStudentName($pdo, $payment['student_id']);
                $receipt_number = generateReceiptNumber($pdo, $school_id);
                $stmt = $pdo->prepare("INSERT INTO fin_receipts (school_id, payment_id, receipt_number, issued_to, issued_by, issued_at, amount) VALUES (?, ?, ?, ?, ?, NOW(), ?)");
                $stmt->execute([$school_id, $payment_id, $receipt_number, $student_name, $admin_id, $payment['amount_paid']]);

                $ledger_posted = postToLedgerAndIncome($pdo, $school_id, $payment_id, $payment['amount_paid'], $payment['payment_date'], $student_name, $admin_id);

                $message = "Payment verified! Receipt #$receipt_number generated." . (!$ledger_posted ? " (Ledger posting failed — check accounts setup.)" : "");
                $message_type = "success";
            } catch (Exception $e) {
                $message = "Error verifying payment: " . $e->getMessage();
                $message_type = "error";
            }
        }

        // ── Reject payment ──────────────────────────────────
        elseif ($_POST['action'] === 'reject_payment') {
            $payment_id       = intval($_POST['payment_id']);
            $rejection_reason = trim($_POST['rejection_reason'] ?? '');
            try {
                $stmt = $pdo->prepare("UPDATE fin_payments SET status = 'rejected', rejection_reason = ? WHERE id = ? AND school_id = ? AND status = 'pending'");
                $stmt->execute([$rejection_reason, $payment_id, $school_id]);
                $message = "Payment rejected.";
                $message_type = "success";
            } catch (Exception $e) {
                $message = "Error rejecting payment: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
}

// ──────────────────────────────────────────────────────────────
// QUERY PARAMS & FILTERS
// ──────────────────────────────────────────────────────────────
$page           = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page       = 20;
$offset         = ($page - 1) * $per_page;

$filter_status    = $_GET['status']    ?? 'all';
$filter_bill_id   = !empty($_GET['bill_id']) ? intval($_GET['bill_id']) : null;
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to   = $_GET['date_to']   ?? '';
$filter_search    = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build WHERE
$where_clauses = ["p.school_id = ?"];
$params        = [$school_id];

if ($filter_bill_id) {
    $where_clauses[] = "p.bill_id = ?";
    $params[]        = $filter_bill_id;
}
if ($filter_status !== 'all') {
    $where_clauses[] = "p.status = ?";
    $params[]        = $filter_status;
}
if ($filter_date_from) {
    $where_clauses[] = "p.payment_date >= ?";
    $params[]        = $filter_date_from;
}
if ($filter_date_to) {
    $where_clauses[] = "p.payment_date <= ?";
    $params[]        = $filter_date_to;
}
if (!empty($filter_search)) {
    $where_clauses[] = "(s.full_name LIKE ? OR s.admission_number LIKE ? OR p.reference_number LIKE ?)";
    $search_like = "%$filter_search%";
    $params[]    = $search_like;
    $params[]    = $search_like;
    $params[]    = $search_like;
}

$where_sql = implode(" AND ", $where_clauses);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM fin_payments p JOIN students s ON p.student_id = s.id WHERE $where_sql");
$stmt->execute($params);
$total_records = $stmt->fetchColumn();
$total_pages   = ceil($total_records / $per_page);

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
        CASE p.status WHEN 'pending' THEN 1 ELSE 2 END,
        p.payment_date DESC,
        p.created_at DESC
    LIMIT $offset, $per_page
");
$stmt->execute($params);
$payments = $stmt->fetchAll();

// Stats (include proof_path to identify student-uploaded payments)
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_payments,
        COALESCE(SUM(CASE WHEN status = 'verified' THEN amount_paid ELSE 0 END), 0) as total_verified,
        COALESCE(SUM(CASE WHEN status = 'pending'  THEN amount_paid ELSE 0 END), 0) as total_pending,
        COALESCE(SUM(CASE WHEN status = 'rejected' THEN amount_paid ELSE 0 END), 0) as total_rejected,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
        COUNT(CASE WHEN status = 'verified' THEN 1 END) as verified_count,
        COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_count,
        COUNT(CASE WHEN status = 'pending' AND proof_path IS NOT NULL THEN 1 END) as student_uploaded_pending
    FROM fin_payments p
    WHERE p.school_id = ?
");
$stmt->execute([$school_id]);
$stats = $stmt->fetch();

// Bills dropdown (pending/part_paid only)
$stmt = $pdo->prepare("
    SELECT b.id, b.description, s.full_name as student_name, s.id as student_id, b.amount, b.amount_paid, (b.amount - b.amount_paid) as balance
    FROM fin_bills b JOIN students s ON b.student_id = s.id
    WHERE b.school_id = ? AND b.status IN ('pending', 'part_paid')
    ORDER BY s.full_name
");
$stmt->execute([$school_id]);
$bills = $stmt->fetchAll();

// Students dropdown
$stmt = $pdo->prepare("SELECT id, full_name, admission_number, class FROM students WHERE school_id = ? AND status = 'active' ORDER BY full_name");
$stmt->execute([$school_id]);
$students = $stmt->fetchAll();

// Selected bill info
$selected_bill = null;
if ($filter_bill_id) {
    $stmt = $pdo->prepare("SELECT b.*, s.full_name as student_name, s.admission_number, s.class FROM fin_bills b JOIN students s ON b.student_id = s.id WHERE b.id = ? AND b.school_id = ?");
    $stmt->execute([$filter_bill_id, $school_id]);
    $selected_bill = $stmt->fetch();
}

// Build base query string for filter links
function filterUrl($overrides = []) {
    global $filter_status, $filter_date_from, $filter_date_to, $filter_search, $filter_bill_id;
    $base = [
        'status'    => $filter_status,
        'date_from' => $filter_date_from,
        'date_to'   => $filter_date_to,
        'search'    => $filter_search,
    ];
    if ($filter_bill_id) $base['bill_id'] = $filter_bill_id;
    $merged = array_merge($base, $overrides);
    return '?' . http_build_query(array_filter($merged, fn($v) => $v !== '' && $v !== null));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($school_name); ?> – Manage Payments</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* ── CSS Variables ──────────────────────────────── */
        :root {
            --primary:   <?php echo $primary_color; ?>;
            --secondary: <?php echo $secondary_color; ?>;
            --success:   #16a34a;
            --warning:   #d97706;
            --danger:    #dc2626;
            --info:      #0284c7;
            --bg:        #f1f5f9;
            --surface:   #ffffff;
            --border:    #e2e8f0;
            --text:      #1e293b;
            --muted:     #64748b;
            --sidebar-w: 260px;
            --radius:    10px;
            --radius-lg: 16px;
            --shadow:    0 1px 3px rgba(0,0,0,.08), 0 4px 16px rgba(0,0,0,.06);
            --shadow-lg: 0 8px 30px rgba(0,0,0,.12);
            --transition: all .22s ease;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family:'Poppins',sans-serif;
            background:var(--bg);
            color:var(--text);
            min-height:100vh;
            overflow-x:hidden;
        }

        /* ── Layout ─────────────────────────────────────── */
        .main-content {
            min-height:100vh;
            padding:24px;
            transition:var(--transition);
        }
        @media(min-width:768px){ .main-content{ margin-left:var(--sidebar-w); } }
        @media(max-width:767px){ .main-content{ padding:72px 16px 24px; } }

        /* ── Mobile menu btn ─────────────────────────────── */
        .mobile-menu-btn {
            position:fixed; top:14px; right:16px; z-index:1001;
            width:44px; height:44px;
            background:var(--primary); color:#fff;
            border:none; border-radius:var(--radius);
            font-size:18px; cursor:pointer; display:none;
            align-items:center; justify-content:center;
            box-shadow:var(--shadow);
        }
        .sidebar-overlay {
            position:fixed; inset:0;
            background:rgba(0,0,0,.45);
            z-index:999; opacity:0; visibility:hidden;
            transition:var(--transition);
        }
        .sidebar-overlay.active{ opacity:1; visibility:visible; }
        @media(max-width:767px){ .mobile-menu-btn{ display:flex; } }
        @media(min-width:768px){ .mobile-menu-btn,.sidebar-overlay{ display:none; } }

        /* ── Page header ─────────────────────────────────── */
        .top-header {
            background:var(--surface);
            padding:20px 24px;
            border-radius:var(--radius-lg);
            margin-bottom:20px;
            display:flex;
            justify-content:space-between;
            align-items:center;
            flex-wrap:wrap;
            gap:12px;
            box-shadow:var(--shadow);
        }
        .header-title h1 {
            color:var(--primary);
            font-size:1.35rem;
            font-weight:700;
            display:flex;
            align-items:center;
            gap:10px;
        }
        .header-title p { color:var(--muted); font-size:.8rem; margin-top:2px; }

        /* ── Alert ───────────────────────────────────────── */
        .alert {
            padding:13px 18px;
            border-radius:var(--radius);
            margin-bottom:20px;
            display:flex;
            align-items:center;
            gap:10px;
            font-size:.85rem;
            font-weight:500;
        }
        .alert-success { background:#dcfce7; color:var(--success); border-left:4px solid var(--success); }
        .alert-error   { background:#fee2e2; color:var(--danger);  border-left:4px solid var(--danger); }

        /* ── Stats cards ─────────────────────────────────── */
        .stats-grid {
            display:grid;
            grid-template-columns:repeat(4,1fr);
            gap:16px;
            margin-bottom:20px;
        }
        @media(max-width:900px){ .stats-grid{ grid-template-columns:repeat(2,1fr); } }
        @media(max-width:480px){ .stats-grid{ grid-template-columns:repeat(2,1fr); gap:10px; } }

        .stat-card {
            background:var(--surface);
            padding:18px 20px;
            border-radius:var(--radius-lg);
            box-shadow:var(--shadow);
            cursor:pointer;
            transition:var(--transition);
            border-top:3px solid transparent;
            text-decoration:none;
            display:block;
        }
        .stat-card:hover { transform:translateY(-2px); box-shadow:var(--shadow-lg); }
        .stat-card.active-filter { box-shadow:var(--shadow-lg); }

        .stat-card.verified  { border-top-color:var(--success); }
        .stat-card.pending   { border-top-color:var(--warning); }
        .stat-card.rejected  { border-top-color:var(--danger);  }
        .stat-card.total     { border-top-color:var(--info);    }

        .stat-icon {
            width:40px; height:40px;
            border-radius:10px;
            display:flex; align-items:center; justify-content:center;
            font-size:1rem;
            margin-bottom:12px;
        }
        .stat-card.verified  .stat-icon { background:#dcfce7; color:var(--success); }
        .stat-card.pending   .stat-icon { background:#fef3c7; color:var(--warning); }
        .stat-card.rejected  .stat-icon { background:#fee2e2; color:var(--danger);  }
        .stat-card.total     .stat-icon { background:#e0f2fe; color:var(--info);    }

        .stat-value { font-size:1.3rem; font-weight:700; line-height:1.2; }
        .stat-sub   { font-size:.72rem; color:var(--muted); margin-top:2px; }
        .stat-label { font-size:.75rem; color:var(--muted); margin-top:6px; }

        /* ── Pending alert banner ────────────────────────── */
        .pending-banner {
            background:linear-gradient(135deg,#fffbeb,#fef3c7);
            border:1px solid #fcd34d;
            border-left:4px solid var(--warning);
            border-radius:var(--radius);
            padding:14px 18px;
            margin-bottom:20px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            flex-wrap:wrap;
            gap:10px;
        }
        .pending-banner .banner-text {
            display:flex; align-items:center; gap:10px;
            font-size:.85rem; font-weight:600; color:#92400e;
        }
        .pending-banner .pulse {
            width:10px; height:10px;
            background:var(--warning);
            border-radius:50%;
            animation:pulse 1.5s infinite;
            flex-shrink:0;
        }
        @keyframes pulse {
            0%,100% { box-shadow:0 0 0 0 rgba(217,119,6,.4); }
            50%      { box-shadow:0 0 0 8px rgba(217,119,6,0); }
        }

        /* ── Record payment form ─────────────────────────── */
        .form-card {
            background:var(--surface);
            border-radius:var(--radius-lg);
            padding:22px 24px;
            margin-bottom:20px;
            box-shadow:var(--shadow);
        }
        .section-title {
            color:var(--primary);
            font-size:.95rem;
            font-weight:600;
            margin-bottom:18px;
            padding-bottom:10px;
            border-bottom:2px solid var(--border);
            display:flex; align-items:center; gap:8px;
        }
        .form-grid {
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
            gap:14px;
        }
        .form-group { display:flex; flex-direction:column; gap:5px; }
        .form-group label { font-size:.78rem; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
        .form-group input,
        .form-group select,
        .form-group textarea {
            padding:10px 12px;
            border:1.5px solid var(--border);
            border-radius:8px;
            font-size:.84rem;
            font-family:inherit;
            color:var(--text);
            background:var(--surface);
            transition:var(--transition);
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline:none;
            border-color:var(--secondary);
            box-shadow:0 0 0 3px rgba(0,0,0,.06);
        }

        .bill-info-card {
            background:linear-gradient(135deg,#f0f9ff,#e0f2fe);
            border:1px solid #bae6fd;
            border-radius:var(--radius);
            padding:14px 18px;
            margin-bottom:16px;
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
            gap:12px;
            font-size:.83rem;
        }
        .bill-info-card strong { color:var(--muted); font-size:.72rem; text-transform:uppercase; display:block; margin-bottom:2px; }

        /* ── Buttons ─────────────────────────────────────── */
        .btn {
            padding:10px 18px;
            border:none;
            border-radius:8px;
            cursor:pointer;
            font-size:.83rem;
            font-weight:600;
            transition:var(--transition);
            display:inline-flex;
            align-items:center;
            gap:7px;
            text-decoration:none;
            font-family:inherit;
        }
        .btn:hover { transform:translateY(-1px); }
        .btn-primary   { background:var(--primary);   color:#fff; }
        .btn-secondary { background:var(--secondary); color:#fff; }
        .btn-success   { background:var(--success);   color:#fff; }
        .btn-danger    { background:var(--danger);    color:#fff; }
        .btn-warning   { background:var(--warning);   color:#fff; }
        .btn-ghost     { background:#f1f5f9; color:var(--text); border:1px solid var(--border); }
        .btn-sm        { padding:6px 12px; font-size:.76rem; }

        /* ── Filter bar ──────────────────────────────────── */
        .filter-bar {
            background:var(--surface);
            padding:14px 18px;
            border-radius:var(--radius-lg);
            margin-bottom:16px;
            box-shadow:var(--shadow);
        }
        .filter-top {
            display:flex;
            flex-wrap:wrap;
            gap:8px;
            align-items:center;
            margin-bottom:12px;
        }
        .filter-label { font-size:.75rem; color:var(--muted); font-weight:600; margin-right:4px; }
        .filter-chip {
            padding:6px 14px;
            border:1.5px solid var(--border);
            border-radius:20px;
            background:var(--surface);
            cursor:pointer;
            font-size:.78rem;
            font-weight:500;
            transition:var(--transition);
            text-decoration:none;
            color:var(--muted);
            display:inline-flex; align-items:center; gap:5px;
        }
        .filter-chip:hover:not(.active) { border-color:var(--secondary); color:var(--text); }
        .filter-chip.active { background:var(--primary); color:#fff; border-color:var(--primary); }
        .filter-chip .chip-count {
            background:rgba(255,255,255,.25);
            border-radius:20px;
            padding:1px 7px;
            font-size:.68rem;
        }
        .filter-chip:not(.active) .chip-count {
            background:var(--border);
            color:var(--muted);
        }

        .filter-bottom {
            display:flex;
            flex-wrap:wrap;
            gap:8px;
            align-items:center;
        }
        .filter-bottom input[type="date"],
        .filter-bottom input[type="text"] {
            padding:7px 12px;
            border:1.5px solid var(--border);
            border-radius:8px;
            font-size:.8rem;
            font-family:inherit;
            background:var(--surface);
        }

        /* ── Table ──────────────────────────────────────── */
        .table-card {
            background:var(--surface);
            border-radius:var(--radius-lg);
            padding:20px 24px;
            box-shadow:var(--shadow);
        }
        .table-header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:16px;
            flex-wrap:wrap;
            gap:10px;
        }
        .table-header h3 { font-size:.95rem; color:var(--primary); font-weight:600; }
        .record-count { font-size:.75rem; color:var(--muted); font-weight:400; }

        .table-container { overflow-x:auto; }
        .data-table { width:100%; border-collapse:collapse; }
        .data-table th, .data-table td { padding:11px 12px; text-align:left; border-bottom:1px solid var(--border); font-size:.8rem; }
        .data-table th { background:#f8fafc; font-weight:600; color:var(--muted); font-size:.72rem; text-transform:uppercase; }
        .data-table tbody tr:hover { background:#f8fafc; }
        .data-table tbody tr.row-pending { background:#fffbeb; }

        /* Mobile card list */
        .mobile-list { display:none; }
        .mobile-card {
            background:var(--surface);
            border-radius:var(--radius);
            padding:14px 16px;
            margin-bottom:10px;
            box-shadow:var(--shadow);
            border-left:3px solid var(--border);
            cursor:pointer;
        }
        .mobile-card.pending-card  { border-left-color:var(--warning); background:#fffbeb; }
        .mobile-card.verified-card { border-left-color:var(--success); }
        .mobile-card.rejected-card { border-left-color:var(--danger);  }

        .mobile-card-top { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:6px; }
        .mobile-card-name { font-weight:600; font-size:.88rem; }
        .mobile-card-meta { font-size:.73rem; color:var(--muted); margin-top:2px; }
        .mobile-card-amount { font-size:1rem; font-weight:700; color:var(--success); }
        .mobile-card-bottom { display:flex; justify-content:space-between; align-items:center; margin-top:8px; }
        .mobile-card-date { font-size:.72rem; color:var(--muted); }

        @media(max-width:767px){
            .desktop-table { display:none; }
            .mobile-list   { display:block; }
        }

        /* Status badges */
        .badge {
            padding:3px 10px;
            border-radius:20px;
            font-size:.7rem;
            font-weight:600;
            display:inline-flex;
            align-items:center;
            gap:4px;
        }
        .badge-success { background:#dcfce7; color:var(--success); }
        .badge-warning { background:#fef3c7; color:var(--warning); }
        .badge-danger  { background:#fee2e2; color:var(--danger);  }

        /* Action buttons */
        .action-row { display:flex; gap:6px; flex-wrap:wrap; }
        .act-btn {
            padding:5px 10px;
            border-radius:6px;
            font-size:.7rem;
            font-weight:600;
            cursor:pointer;
            border:none;
            display:inline-flex;
            align-items:center;
            gap:4px;
            transition:var(--transition);
            text-decoration:none;
        }
        .act-verify  { background:var(--success); color:#fff; }
        .act-reject  { background:var(--danger);  color:#fff; }
        .act-receipt { background:var(--info);    color:#fff; }
        .act-btn:hover { opacity:.88; }

        /* Modals */
        .modal-overlay {
            display:none;
            position:fixed; inset:0;
            background:rgba(15,23,42,.55);
            z-index:3000;
            align-items:center; justify-content:center;
            padding:16px;
        }
        .modal-overlay.open { display:flex; }

        .modal-box {
            background:var(--surface);
            border-radius:var(--radius-lg);
            max-width:480px;
            width:100%;
            overflow:hidden;
        }
        .modal-head {
            padding:18px 22px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            border-bottom:1px solid var(--border);
        }
        .modal-close {
            width:32px; height:32px;
            background:var(--border); border:none;
            border-radius:50%; cursor:pointer;
            font-size:16px;
        }
        .modal-body { padding:20px 22px; }
        .modal-foot {
            padding:14px 22px;
            display:flex;
            justify-content:flex-end;
            gap:10px;
            border-top:1px solid var(--border);
            background:#f8fafc;
        }

        .payment-detail-box {
            background:#f8fafc;
            border:1px solid var(--border);
            border-radius:var(--radius);
            padding:14px 16px;
            margin-bottom:16px;
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:10px;
            font-size:.82rem;
        }
        .payment-detail-box dt { color:var(--muted); font-size:.72rem; text-transform:uppercase; font-weight:600; margin-bottom:2px; }
        .payment-detail-box dd { color:var(--text); font-weight:600; }

        .pagination {
            display:flex;
            justify-content:center;
            flex-wrap:wrap;
            gap:6px;
            margin-top:20px;
        }
        .page-link {
            padding:7px 13px;
            border:1.5px solid var(--border);
            border-radius:8px;
            text-decoration:none;
            color:var(--muted);
            font-size:.78rem;
        }
        .page-link.active { background:var(--primary); color:#fff; border-color:var(--primary); }

        .empty-state { text-align:center; padding:50px 20px; color:var(--muted); }
        .empty-state i { font-size:2.5rem; margin-bottom:12px; opacity:.3; }
        .footer { text-align:center; padding:20px; color:var(--muted); font-size:.75rem; margin-top:20px; }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="main-content">

        <!-- Top Header -->
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-money-bill-wave"></i> Manage Payments</h1>
                <p>Record, verify, and track all student fee payments</p>
            </div>
            <a href="finance_dashboard.php" class="btn btn-ghost btn-sm">
                <i class="fas fa-chart-line"></i> Dashboard
            </a>
        </div>

        <!-- Alert -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Pending banner with student-uploaded count -->
        <?php if (($stats['pending_count'] ?? 0) > 0): ?>
            <div class="pending-banner">
                <div class="banner-text">
                    <div class="pulse"></div>
                    <?php echo $stats['pending_count']; ?> payment<?php echo $stats['pending_count'] > 1 ? 's' : ''; ?> awaiting verification
                    <?php if (($stats['student_uploaded_pending'] ?? 0) > 0): ?>
                        <span class="badge" style="background:var(--warning);color:#fff;margin-left:8px;">
                            <?php echo $stats['student_uploaded_pending']; ?> student-uploaded
                        </span>
                    <?php endif; ?>
                </div>
                <a href="<?php echo filterUrl(['status' => 'pending', 'page' => 1]); ?>" class="btn btn-warning btn-sm">
                    <i class="fas fa-eye"></i> Review Now
                </a>
            </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <a href="<?php echo filterUrl(['status' => 'all', 'page' => 1]); ?>" class="stat-card total <?php echo $filter_status === 'all' ? 'active-filter' : ''; ?>">
                <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
                <div class="stat-value"><?php echo number_format($stats['total_payments'] ?? 0); ?></div>
                <div class="stat-sub">All payments</div>
                <div class="stat-label">Total</div>
            </a>
            <a href="<?php echo filterUrl(['status' => 'pending', 'page' => 1]); ?>" class="stat-card pending <?php echo $filter_status === 'pending' ? 'active-filter' : ''; ?>">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-value">₦<?php echo number_format($stats['total_pending'] ?? 0, 0); ?></div>
                <div class="stat-sub"><?php echo number_format($stats['pending_count'] ?? 0); ?> payments</div>
                <div class="stat-label">Pending</div>
            </a>
            <a href="<?php echo filterUrl(['status' => 'verified', 'page' => 1]); ?>" class="stat-card verified <?php echo $filter_status === 'verified' ? 'active-filter' : ''; ?>">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-value">₦<?php echo number_format($stats['total_verified'] ?? 0, 0); ?></div>
                <div class="stat-sub"><?php echo number_format($stats['verified_count'] ?? 0); ?> payments</div>
                <div class="stat-label">Verified</div>
            </a>
            <a href="<?php echo filterUrl(['status' => 'rejected', 'page' => 1]); ?>" class="stat-card rejected <?php echo $filter_status === 'rejected' ? 'active-filter' : ''; ?>">
                <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                <div class="stat-value">₦<?php echo number_format($stats['total_rejected'] ?? 0, 0); ?></div>
                <div class="stat-sub"><?php echo number_format($stats['rejected_count'] ?? 0); ?> payments</div>
                <div class="stat-label">Rejected</div>
            </a>
        </div>

        <!-- Record Payment Form (Admin only) -->
        <div class="form-card">
            <div class="section-title"><i class="fas fa-plus-circle"></i> Record New Payment (Bursary/Cash)</div>

            <?php if ($selected_bill): ?>
                <div class="bill-info-card">
                    <div><strong>Student</strong><br><?php echo htmlspecialchars($selected_bill['student_name']); ?></div>
                    <div><strong>Class</strong><br><?php echo htmlspecialchars($selected_bill['class']); ?></div>
                    <div><strong>Bill</strong><br><?php echo htmlspecialchars($selected_bill['description']); ?></div>
                    <div><strong>Balance Due</strong><br>₦<?php echo number_format($selected_bill['amount'] - $selected_bill['amount_paid'], 2); ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="action" value="record_payment">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Select Bill <span style="color:var(--muted);font-weight:400;">(optional)</span></label>
                        <select name="bill_id" id="bill_select" onchange="updateBillInfo(this)">
                            <option value="">— Manual Payment (no bill) —</option>
                            <?php foreach ($bills as $bill): ?>
                                <option value="<?php echo $bill['id']; ?>"
                                    <?php echo ($filter_bill_id == $bill['id']) ? 'selected' : ''; ?>
                                    data-student-id="<?php echo $bill['student_id']; ?>"
                                    data-balance="<?php echo $bill['balance']; ?>">
                                    <?php echo htmlspecialchars($bill['student_name'] . ' – ' . $bill['description'] . ' (Bal: ₦' . number_format($bill['balance'], 2) . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Student *</label>
                        <select name="student_id" id="student_select" required>
                            <option value="">— Select Student —</option>
                            <?php foreach ($students as $s): ?>
                                <option value="<?php echo $s['id']; ?>">
                                    <?php echo htmlspecialchars($s['admission_number'] . ' – ' . $s['full_name'] . ' (' . $s['class'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Amount Paid (₦) *</label>
                        <input type="number" name="amount_paid" id="amount_paid" step="0.01" min="0.01" required placeholder="0.00">
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
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Reference Number</label>
                        <input type="text" name="reference_number" placeholder="Teller / POS / Transfer ref">
                    </div>

                    <div class="form-group" style="grid-column:1/-1;">
                        <label>Notes</label>
                        <textarea name="notes" rows="2" placeholder="Optional notes…"></textarea>
                    </div>
                </div>

                <div style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Record Payment
                    </button>
                    <?php if ($filter_bill_id): ?>
                        <a href="finance_payments.php" class="btn btn-ghost">
                            <i class="fas fa-times"></i> Clear Bill
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="filter-top">
                <span class="filter-label"><i class="fas fa-filter"></i> Status:</span>
                <a href="<?php echo filterUrl(['status' => 'all', 'page' => 1]); ?>"
                   class="filter-chip <?php echo $filter_status === 'all' ? 'active' : ''; ?>">
                    All <span class="chip-count"><?php echo $stats['total_payments'] ?? 0; ?></span>
                </a>
                <a href="<?php echo filterUrl(['status' => 'pending', 'page' => 1]); ?>"
                   class="filter-chip <?php echo $filter_status === 'pending' ? 'active' : ''; ?>">
                    <i class="fas fa-clock"></i> Pending <span class="chip-count"><?php echo $stats['pending_count'] ?? 0; ?></span>
                </a>
                <a href="<?php echo filterUrl(['status' => 'verified', 'page' => 1]); ?>"
                   class="filter-chip <?php echo $filter_status === 'verified' ? 'active' : ''; ?>">
                    <i class="fas fa-check"></i> Verified <span class="chip-count"><?php echo $stats['verified_count'] ?? 0; ?></span>
                </a>
                <a href="<?php echo filterUrl(['status' => 'rejected', 'page' => 1]); ?>"
                   class="filter-chip <?php echo $filter_status === 'rejected' ? 'active' : ''; ?>">
                    <i class="fas fa-times"></i> Rejected <span class="chip-count"><?php echo $stats['rejected_count'] ?? 0; ?></span>
                </a>
            </div>

            <form method="GET" class="filter-bottom">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>">
                <?php if ($filter_bill_id): ?>
                    <input type="hidden" name="bill_id" value="<?php echo $filter_bill_id; ?>">
                <?php endif; ?>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>" placeholder="From">
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>" placeholder="To">
                <input type="text" name="search" value="<?php echo htmlspecialchars($filter_search); ?>" placeholder="Search name, ref…">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
                <?php if ($filter_search || $filter_date_from || $filter_date_to): ?>
                    <a href="<?php echo filterUrl(['search' => '', 'date_from' => '', 'date_to' => '', 'page' => 1]); ?>"
                       class="btn btn-ghost btn-sm">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Payments List -->
        <div class="table-card">
            <div class="table-header">
                <h3><i class="fas fa-list"></i> Payment Transactions
                    <span class="record-count">(<?php echo number_format($total_records); ?> records)</span>
                </h3>
            </div>

            <?php if (empty($payments)): ?>
                <div class="empty-state">
                    <i class="fas fa-money-bill-slash"></i>
                    <p>No payments found</p>
                </div>
            <?php else: ?>

            <!-- Desktop Table -->
            <div class="desktop-table">
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Student</th>
                                <th>Bill / Description</th>
                                <th>Method</th>
                                <th>Amount</th>
                                <th>Reference</th>
                                <th>Status</th>
                                <th>Source</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $p): ?>
                                <tr class="<?php echo $p['status'] === 'pending' ? 'row-pending' : ''; ?>">
                                    <td><?php echo date('d M Y', strtotime($p['payment_date'])); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($p['student_name']); ?></strong><br>
                                        <small><?php echo htmlspecialchars($p['admission_number']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($p['bill_description'] ?? 'Manual Payment'); ?></td>
                                    <td><?php echo ucfirst(str_replace('_', ' ', $p['payment_method'])); ?></td>
                                    <td style="font-weight:700; color:var(--success);">₦<?php echo number_format($p['amount_paid'], 2); ?></td>
                                    <td><small><?php echo htmlspecialchars($p['reference_number'] ?: '—'); ?></small></td>
                                    <td>
                                        <?php
                                        $badge = match($p['status']) {
                                            'verified' => ['badge-success', 'Verified'],
                                            'pending'  => ['badge-warning', 'Pending'],
                                            'rejected' => ['badge-danger', 'Rejected'],
                                            default    => ['badge-gray', ucfirst($p['status'])],
                                        };
                                        ?>
                                        <span class="badge <?php echo $badge[0]; ?>"><?php echo $badge[1]; ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($p['proof_path'])): ?>
                                            <span class="badge" style="background:#e0f2fe;color:var(--info);">
                                                <i class="fas fa-upload"></i> Student Upload
                                            </span>
                                        <?php else: ?>
                                            <span class="badge" style="background:#f1f5f9;color:var(--muted);">
                                                <i class="fas fa-user-check"></i> Admin
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-row">
                                            <?php if ($p['status'] === 'pending'): ?>
                                                <button class="act-btn act-verify"
                                                    onclick="openVerifyModal(<?php echo $p['id']; ?>,'<?php echo addslashes($p['student_name']); ?>',<?php echo $p['amount_paid']; ?>,'<?php echo addslashes($p['bill_description'] ?? 'Manual Payment'); ?>','<?php echo $p['payment_method']; ?>','<?php echo addslashes($p['reference_number'] ?? '—'); ?>','<?php echo $p['payment_date']; ?>')">
                                                    <i class="fas fa-check"></i> Verify
                                                </button>
                                                <button class="act-btn act-reject"
                                                    onclick="openRejectModal(<?php echo $p['id']; ?>,'<?php echo addslashes($p['student_name']); ?>',<?php echo $p['amount_paid']; ?>)">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($p['status'] === 'verified'): ?>
                                                <a href="finance_receipts.php?payment_id=<?php echo $p['id']; ?>" class="act-btn act-receipt">
                                                    <i class="fas fa-receipt"></i> Receipt
                                                </a>
                                            <?php endif; ?>
                                            <?php if (!empty($p['proof_path']) && $p['status'] === 'pending'): ?>
                                                <button class="act-btn" style="background:#e0f2fe;color:var(--info);"
                                                    onclick="viewProof('<?php echo $p['proof_path']; ?>')">
                                                    <i class="fas fa-image"></i> View Proof
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Mobile Cards -->
            <div class="mobile-list">
                <?php foreach ($payments as $p):
                    $cardClass = match($p['status']) {
                        'pending'  => 'pending-card',
                        'verified' => 'verified-card',
                        'rejected' => 'rejected-card',
                        default    => '',
                    };
                    $badge = match($p['status']) {
                        'verified' => ['badge-success', 'Verified'],
                        'pending'  => ['badge-warning', 'Pending'],
                        'rejected' => ['badge-danger', 'Rejected'],
                        default    => ['badge-gray', ucfirst($p['status'])],
                    };
                ?>
                    <div class="mobile-card <?php echo $cardClass; ?>"
                        onclick="openMobileModal(
                            <?php echo $p['id']; ?>,
                            '<?php echo addslashes($p['student_name']); ?>',
                            <?php echo $p['amount_paid']; ?>,
                            '<?php echo $p['status']; ?>',
                            '<?php echo addslashes($p['bill_description'] ?? 'Manual Payment'); ?>',
                            '<?php echo $p['payment_method']; ?>',
                            '<?php echo addslashes($p['reference_number'] ?? '—'); ?>',
                            '<?php echo $p['payment_date']; ?>',
                            '<?php echo addslashes($p['rejection_reason'] ?? ''); ?>',
                            '<?php echo addslashes($p['proof_path'] ?? ''); ?>'
                        )">
                        <div class="mobile-card-top">
                            <div>
                                <div class="mobile-card-name"><?php echo htmlspecialchars($p['student_name']); ?></div>
                                <div class="mobile-card-meta"><?php echo htmlspecialchars($p['admission_number']); ?></div>
                            </div>
                            <div class="mobile-card-amount">₦<?php echo number_format($p['amount_paid'], 2); ?></div>
                        </div>
                        <div class="mobile-card-bottom">
                            <span class="mobile-card-date"><?php echo date('d M Y', strtotime($p['payment_date'])); ?></span>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span class="badge <?php echo $badge[0]; ?>"><?php echo $badge[1]; ?></span>
                                <i class="fas fa-chevron-right" style="font-size:.6rem;color:var(--muted);"></i>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo filterUrl(['page' => $page - 1]); ?>" class="page-link">&laquo; Prev</a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="<?php echo filterUrl(['page' => $i]); ?>"
                           class="page-link <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="<?php echo filterUrl(['page' => $page + 1]); ?>" class="page-link">Next &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="footer">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> — Finance Management System</div>
    </div>

    <!-- Verify Modal -->
    <div id="verifyModal" class="modal-overlay">
        <div class="modal-box">
            <form method="POST">
                <input type="hidden" name="action" value="verify_payment">
                <input type="hidden" name="payment_id" id="vm_payment_id">

                <div class="modal-head">
                    <h3 style="color:var(--success);"><i class="fas fa-check-circle"></i> Verify Payment</h3>
                    <button type="button" class="modal-close" onclick="closeModal('verifyModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <dl class="payment-detail-box">
                        <div><dt>Student</dt><dd id="vm_student"></dd></div>
                        <div><dt>Amount</dt><dd id="vm_amount" style="color:var(--success);"></dd></div>
                        <div><dt>Bill</dt><dd id="vm_bill"></dd></div>
                        <div><dt>Method</dt><dd id="vm_method"></dd></div>
                        <div><dt>Reference</dt><dd id="vm_ref"></dd></div>
                        <div><dt>Date</dt><dd id="vm_date"></dd></div>
                    </dl>
                    <p style="font-size:.8rem;font-weight:600;color:var(--muted);margin-bottom:8px;">This will:</p>
                    <ul style="list-style:none; margin-left:0;">
                        <li><i class="fas fa-check" style="color:var(--success);"></i> Mark payment as verified</li>
                        <li><i class="fas fa-check" style="color:var(--success);"></i> Update bill balance</li>
                        <li><i class="fas fa-check" style="color:var(--success);"></i> Generate official receipt</li>
                        <li><i class="fas fa-check" style="color:var(--success);"></i> Post to ledger</li>
                    </ul>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('verifyModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Verify Payment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="modal-overlay">
        <div class="modal-box">
            <form method="POST">
                <input type="hidden" name="action" value="reject_payment">
                <input type="hidden" name="payment_id" id="rm_payment_id">

                <div class="modal-head">
                    <h3 style="color:var(--danger);"><i class="fas fa-times-circle"></i> Reject Payment</h3>
                    <button type="button" class="modal-close" onclick="closeModal('rejectModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <dl class="payment-detail-box">
                        <div><dt>Student</dt><dd id="rm_student"></dd></div>
                        <div><dt>Amount</dt><dd id="rm_amount" style="color:var(--danger);"></dd></div>
                    </dl>
                    <div class="form-group">
                        <label>Rejection Reason *</label>
                        <textarea name="rejection_reason" rows="3" required
                            placeholder="Why is this payment being rejected?"></textarea>
                    </div>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('rejectModal')">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject Payment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Mobile Detail Modal -->
    <div id="mobileModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-head">
                <h3>Payment Details</h3>
                <button type="button" class="modal-close" onclick="closeModal('mobileModal')">&times;</button>
            </div>
            <div class="modal-body">
                <dl class="payment-detail-box">
                    <div><dt>Student</dt><dd id="mob_student"></dd></div>
                    <div><dt>Amount</dt><dd id="mob_amount"></dd></div>
                    <div><dt>Bill</dt><dd id="mob_bill"></dd></div>
                    <div><dt>Method</dt><dd id="mob_method"></dd></div>
                    <div><dt>Reference</dt><dd id="mob_ref"></dd></div>
                    <div><dt>Date</dt><dd id="mob_date"></dd></div>
                </dl>
                <div id="mob_rejection_block" style="display:none;background:#fee2e2;border-radius:8px;padding:10px;margin-top:8px;">
                    <strong>Rejection reason:</strong> <span id="mob_rejection_reason"></span>
                </div>
                <div id="mob_proof_block" style="display:none;margin-top:8px;">
                    <button class="btn btn-info btn-sm" onclick="viewProof(document.getElementById('mob_proof_path').value)">
                        <i class="fas fa-image"></i> View Payment Proof
                    </button>
                    <input type="hidden" id="mob_proof_path">
                </div>
            </div>
            <div class="modal-foot" id="mob_actions"></div>
        </div>
    </div>

    <script>
    function openModal(id) { 
        document.getElementById(id).classList.add('open'); 
        document.body.style.overflow = 'hidden';
    }
    function closeModal(id) { 
        document.getElementById(id).classList.remove('open'); 
        document.body.style.overflow = '';
    }

    document.querySelectorAll('.modal-overlay').forEach(m => {
        m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); });
    });

    function openVerifyModal(id, name, amount, bill, method, ref, date) {
        document.getElementById('vm_payment_id').value = id;
        document.getElementById('vm_student').textContent = name;
        document.getElementById('vm_amount').textContent = '₦' + parseFloat(amount).toLocaleString('en-NG', {minimumFractionDigits:2});
        document.getElementById('vm_bill').textContent = bill;
        document.getElementById('vm_method').textContent = method.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase());
        document.getElementById('vm_ref').textContent = ref;
        document.getElementById('vm_date').textContent = date;
        openModal('verifyModal');
    }

    function openRejectModal(id, name, amount) {
        document.getElementById('rm_payment_id').value = id;
        document.getElementById('rm_student').textContent = name;
        document.getElementById('rm_amount').textContent = '₦' + parseFloat(amount).toLocaleString('en-NG', {minimumFractionDigits:2});
        openModal('rejectModal');
    }

    function viewProof(proofPath) {
        if (proofPath) {
            window.open(proofPath, '_blank');
        }
    }

    function openMobileModal(id, name, amount, status, bill, method, ref, date, rejectionReason, proofPath) {
        document.getElementById('mob_student').textContent = name;
        document.getElementById('mob_amount').textContent = '₦' + parseFloat(amount).toLocaleString('en-NG', {minimumFractionDigits:2});
        document.getElementById('mob_bill').textContent = bill;
        document.getElementById('mob_method').textContent = method.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase());
        document.getElementById('mob_ref').textContent = ref;
        document.getElementById('mob_date').textContent = date;

        const amtEl = document.getElementById('mob_amount');
        amtEl.style.color = status === 'verified' ? 'var(--success)' : status === 'rejected' ? 'var(--danger)' : 'var(--warning)';

        const rejBlock = document.getElementById('mob_rejection_block');
        if (status === 'rejected' && rejectionReason) {
            document.getElementById('mob_rejection_reason').textContent = rejectionReason;
            rejBlock.style.display = 'block';
        } else {
            rejBlock.style.display = 'none';
        }

        const proofBlock = document.getElementById('mob_proof_block');
        if (proofPath) {
            document.getElementById('mob_proof_path').value = proofPath;
            proofBlock.style.display = 'block';
        } else {
            proofBlock.style.display = 'none';
        }

        const actionsDiv = document.getElementById('mob_actions');
        actionsDiv.innerHTML = '';

        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'btn btn-ghost';
        cancelBtn.innerHTML = 'Close';
        cancelBtn.onclick = () => closeModal('mobileModal');
        actionsDiv.appendChild(cancelBtn);

        if (status === 'pending') {
            const rejectBtn = document.createElement('button');
            rejectBtn.type = 'button';
            rejectBtn.className = 'btn btn-danger';
            rejectBtn.innerHTML = '<i class="fas fa-times"></i> Reject';
            rejectBtn.onclick = () => {
                closeModal('mobileModal');
                openRejectModal(id, name, amount);
            };
            actionsDiv.appendChild(rejectBtn);

            const verifyBtn = document.createElement('button');
            verifyBtn.type = 'button';
            verifyBtn.className = 'btn btn-success';
            verifyBtn.innerHTML = '<i class="fas fa-check"></i> Verify';
            verifyBtn.onclick = () => {
                closeModal('mobileModal');
                openVerifyModal(id, name, amount, bill, method, ref, date);
            };
            actionsDiv.appendChild(verifyBtn);
        }

        if (status === 'verified') {
            const receiptBtn = document.createElement('a');
            receiptBtn.href = 'finance_receipts.php?payment_id=' + id;
            receiptBtn.className = 'btn btn-info';
            receiptBtn.innerHTML = '<i class="fas fa-receipt"></i> View Receipt';
            actionsDiv.appendChild(receiptBtn);
        }

        openModal('mobileModal');
    }

    function updateBillInfo(select) {
        const opt = select.options[select.selectedIndex];
        const studentId = opt.dataset.studentId;
        const balance = opt.dataset.balance;
        const studentSel = document.getElementById('student_select');
        const amountInp = document.getElementById('amount_paid');

        if (studentId) {
            studentSel.value = studentId;
            if (balance) amountInp.max = balance;
        } else {
            studentSel.value = '';
            amountInp.max = '';
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const btn = document.getElementById('mobileMenuBtn');
        const overlay = document.getElementById('sidebarOverlay');
        const sidebar = document.getElementById('sidebar');

        if (btn && sidebar) {
            btn.addEventListener('click', () => {
                sidebar.classList.toggle('active');
                overlay.classList.toggle('active');
                document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
            });
        }
        if (overlay) {
            overlay.addEventListener('click', () => {
                if (sidebar) sidebar.classList.remove('active');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            });
        }

        const billSel = document.getElementById('bill_select');
        if (billSel && billSel.value) updateBillInfo(billSel);
    });
    </script>

    <?php require_once 'includes/sidebar.php'; ?>
</body>
</html>
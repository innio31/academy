<?php
// msv/admin/admissions.php - Admission Applications Management
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../includes/config.php';

// Auth check
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /msv/login.php");
    exit();
}

$admin_id   = $_SESSION['admin_id']   ?? $_SESSION['user_id'];
$admin_name = $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? 'Administrator';
$admin_role = $_SESSION['admin_role'] ?? 'admin';
$school_id  = SCHOOL_ID;
$school_name   = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;

// ── Handle AJAX status-update ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'update_status') {
        $app_id    = (int)$_POST['app_id'];
        $newStatus = $_POST['status'];
        $allowed   = ['pending', 'contacted', 'reviewed', 'enrolled', 'rejected'];

        if (!in_array($newStatus, $allowed)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status']);
            exit;
        }

        try {
            // Fetch old status
            $stmt = $pdo->prepare("SELECT status FROM admission_applications WHERE id = ? AND school_id = ?");
            $stmt->execute([$app_id, $school_id]);
            $old = $stmt->fetch();

            if (!$old) {
                echo json_encode(['success' => false, 'message' => 'Application not found']);
                exit;
            }

            // Timestamp columns
            $extra = '';
            if ($newStatus === 'contacted') $extra = ", contacted_at = NOW()";
            if ($newStatus === 'reviewed')  $extra = ", reviewed_at = NOW(), reviewed_by = $admin_id";

            $pdo->prepare("UPDATE admission_applications SET status = ?$extra WHERE id = ? AND school_id = ?")
                ->execute([$newStatus, $app_id, $school_id]);

            // Log the change
            $pdo->prepare("INSERT INTO admission_application_logs
                (application_id, school_id, action, old_status, new_status, note, performed_by, performed_by_type, ip_address)
                VALUES (?, ?, 'status_changed', ?, ?, ?, ?, 'admin', ?)")
                ->execute([
                    $app_id,
                    $school_id,
                    $old['status'],
                    $newStatus,
                    "Status changed from {$old['status']} to $newStatus",
                    $admin_id,
                    $_SERVER['REMOTE_ADDR'] ?? null
                ]);

            echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'delete') {
        $app_id = (int)$_POST['app_id'];
        try {
            $pdo->prepare("DELETE FROM admission_applications WHERE id = ? AND school_id = ?")
                ->execute([$app_id, $school_id]);
            echo json_encode(['success' => true, 'message' => 'Application deleted']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// ── Filters ──────────────────────────────────────────────────────────────────
$filter_status = $_GET['status'] ?? 'all';
$filter_level  = $_GET['level']  ?? 'all';
$search        = trim($_GET['search'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 15;
$offset        = ($page - 1) * $per_page;

$where  = "WHERE a.school_id = ?";
$params = [$school_id];

if ($filter_status !== 'all') {
    $where .= " AND a.status = ?";
    $params[] = $filter_status;
}
if ($filter_level !== 'all') {
    $where .= " AND a.applying_level = ?";
    $params[] = $filter_level;
}
if ($search !== '') {
    $where .= " AND (a.first_name LIKE ? OR a.last_name LIKE ? OR a.application_number LIKE ? OR a.parent_name LIKE ? OR a.phone_number LIKE ?)";
    $s = "%$search%";
    array_push($params, $s, $s, $s, $s, $s);
}

// ── Stats ────────────────────────────────────────────────────────────────────
try {
    $stats = [];
    foreach (['pending', 'contacted', 'reviewed', 'enrolled', 'rejected'] as $st) {
        $r = $pdo->prepare("SELECT COUNT(*) as c FROM admission_applications WHERE school_id = ? AND status = ?");
        $r->execute([$school_id, $st]);
        $stats[$st] = $r->fetch()['c'];
    }
    $stats['total'] = array_sum($stats);

    // Distinct levels for filter
    $lvl = $pdo->prepare("SELECT DISTINCT applying_level FROM admission_applications WHERE school_id = ? ORDER BY applying_level");
    $lvl->execute([$school_id]);
    $levels = $lvl->fetchAll(PDO::FETCH_COLUMN);

    // Total for pagination
    $cnt = $pdo->prepare("SELECT COUNT(*) as c FROM admission_applications a $where");
    $cnt->execute($params);
    $total_rows  = $cnt->fetch()['c'];
    $total_pages = ceil($total_rows / $per_page);

    // Main query
    $sql = "SELECT a.*, CONCAT(a.first_name,' ',a.last_name) as full_name,
                   adm.full_name as reviewed_by_name
            FROM admission_applications a
            LEFT JOIN admin_users adm ON a.reviewed_by = adm.id
            $where
            ORDER BY a.created_at DESC
            LIMIT $per_page OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $applications = $stmt->fetchAll();
} catch (PDOException $e) {
    $error_msg = $e->getMessage();
    $applications = [];
    $stats = array_fill_keys(['total', 'pending', 'contacted', 'reviewed', 'enrolled', 'rejected'], 0);
    $levels = [];
    $total_pages = 1;
}

// ── Fetch single application for detail modal ─────────────────────────────
$detail = null;
if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    try {
        $ds = $pdo->prepare("SELECT a.*, CONCAT(a.first_name,' ',a.last_name) as full_name,
                                    adm.full_name as reviewed_by_name
                             FROM admission_applications a
                             LEFT JOIN admin_users adm ON a.reviewed_by = adm.id
                             WHERE a.id = ? AND a.school_id = ?");
        $ds->execute([(int)$_GET['view'], $school_id]);
        $detail = $ds->fetch();

        // Logs
        $ls = $pdo->prepare("SELECT l.*, adm.full_name as performed_by_name
                             FROM admission_application_logs l
                             LEFT JOIN admin_users adm ON l.performed_by = adm.id
                             WHERE l.application_id = ?
                             ORDER BY l.created_at DESC");
        $ls->execute([(int)$_GET['view']]);
        $detail_logs = $ls->fetchAll();
    } catch (PDOException $e) {
        $detail = null;
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function statusBadge($s)
{
    $map = [
        'pending'   => ['#fff3cd', '#856404', 'clock'],
        'contacted' => ['#cfe2ff', '#0a3869', 'phone'],
        'reviewed'  => ['#d1ecf1', '#0c5460', 'eye'],
        'enrolled'  => ['#d4edda', '#155724', 'check-circle'],
        'rejected'  => ['#f8d7da', '#721c24', 'times-circle'],
    ];
    [$bg, $color, $icon] = $map[$s] ?? ['#eee', '#333', 'circle'];
    return "<span class='status-pill' style='background:$bg;color:$color'><i class='fas fa-$icon'></i> " . ucfirst($s) . "</span>";
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $school_name; ?> – Admission Applications</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: <?php echo $secondary_color; ?>;
            --accent-color: #e74c3c;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --sidebar-width: 260px;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, .08);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, .1);
            --radius-sm: 8px;
            --radius-md: 12px;
            --transition: all .3s ease;
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

        /* ── Sidebar (identical to index.php) ── */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-color), var(--dark-color));
            color: #fff;
            padding: 20px 0;
            transition: transform .3s ease;
            z-index: 1000;
            overflow-y: auto;
            transform: translateX(-100%);
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, .1);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            width: 44px;
            height: 44px;
            background: var(--secondary-color);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .logo-text h3 {
            font-size: 1rem;
            font-weight: 600;
        }

        .logo-text p {
            font-size: .7rem;
            opacity: .8;
        }

        .nav-links {
            list-style: none;
            padding: 0 15px;
            margin-top: 10px;
        }

        .nav-links li {
            margin-bottom: 5px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: rgba(255, 255, 255, .9);
            text-decoration: none;
            border-radius: 8px;
            transition: var(--transition);
        }

        .nav-links a:hover {
            background: rgba(255, 255, 255, .15);
        }

        .nav-links a.active {
            background: rgba(255, 255, 255, .2);
            border-left: 3px solid var(--secondary-color);
        }

        .nav-links i {
            width: 20px;
            text-align: center;
        }

        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, .5);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .sidebar-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        /* ── Layout ── */
        .mobile-menu-btn {
            position: fixed;
            top: 15px;
            right: 20px;
            z-index: 1001;
            width: 44px;
            height: 44px;
            background: var(--primary-color);
            color: #fff;
            border: none;
            border-radius: var(--radius-md);
            font-size: 20px;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .main-content {
            min-height: 100vh;
            padding: 20px;
            transition: var(--transition);
        }

        .top-header {
            background: #fff;
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
            font-size: .85rem;
        }

        .logout-btn {
            background: var(--danger-color);
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: .85rem;
        }

        /* ── Stats row ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: #fff;
            padding: 18px 20px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            border-top: 4px solid;
            cursor: pointer;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .stat-card.total {
            border-top-color: var(--primary-color);
        }

        .stat-card.pending {
            border-top-color: #f39c12;
        }

        .stat-card.contacted {
            border-top-color: #3498db;
        }

        .stat-card.reviewed {
            border-top-color: #9b59b6;
        }

        .stat-card.enrolled {
            border-top-color: #27ae60;
        }

        .stat-card.rejected {
            border-top-color: #e74c3c;
        }

        .stat-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
        }

        .stat-label {
            font-size: .78rem;
            color: #666;
            margin-top: 2px;
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: #fff;
        }

        .stat-card.total .stat-icon {
            background: var(--primary-color);
        }

        .stat-card.pending .stat-icon {
            background: #f39c12;
        }

        .stat-card.contacted .stat-icon {
            background: #3498db;
        }

        .stat-card.reviewed .stat-icon {
            background: #9b59b6;
        }

        .stat-card.enrolled .stat-icon {
            background: #27ae60;
        }

        .stat-card.rejected .stat-icon {
            background: #e74c3c;
        }

        /* ── Toolbar ── */
        .toolbar {
            background: #fff;
            padding: 16px 20px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }

        .search-box {
            position: relative;
            flex: 1;
            min-width: 220px;
        }

        .search-box input {
            width: 100%;
            padding: 10px 16px 10px 40px;
            border: 1.5px solid #ddd;
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: .85rem;
            transition: var(--transition);
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .search-box i {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: #aaa;
        }

        .filter-select {
            padding: 10px 14px;
            border: 1.5px solid #ddd;
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: .85rem;
            cursor: pointer;
            background: #fff;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            font-size: .85rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
        }

        .btn-primary {
            background: var(--primary-color);
            color: #fff;
        }

        .btn-primary:hover {
            opacity: .9;
        }

        .btn-outline {
            background: #fff;
            color: var(--primary-color);
            border: 1.5px solid var(--primary-color);
        }

        .btn-outline:hover {
            background: var(--primary-color);
            color: #fff;
        }

        /* ── Table ── */
        .card {
            background: #fff;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .card-header {
            padding: 18px 20px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-header h3 {
            color: var(--primary-color);
            font-size: 1rem;
        }

        .card-header .count {
            background: var(--light-color);
            color: #666;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: .78rem;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead tr {
            background: #f8f9fa;
        }

        th,
        td {
            padding: 12px 14px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
            font-size: .83rem;
            white-space: nowrap;
        }

        th {
            font-weight: 600;
            color: #555;
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        tbody tr:hover {
            background: #fafbff;
        }

        .app-number {
            font-weight: 600;
            color: var(--primary-color);
            font-size: .82rem;
        }

        .child-name {
            font-weight: 500;
        }

        .sub-text {
            font-size: .75rem;
            color: #888;
            margin-top: 2px;
        }

        .level-badge {
            background: #e8f4fd;
            color: #1a6fa3;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: .75rem;
            font-weight: 500;
        }

        .status-pill {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: .75rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .action-btns {
            display: flex;
            gap: 6px;
        }

        .btn-icon {
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            transition: var(--transition);
        }

        .btn-view {
            background: #e8f4fd;
            color: #1a6fa3;
        }

        .btn-view:hover {
            background: #1a6fa3;
            color: #fff;
        }

        .btn-delete {
            background: #fde8e8;
            color: #c0392b;
        }

        .btn-delete:hover {
            background: #c0392b;
            color: #fff;
        }

        /* ── Pagination ── */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            padding: 20px;
            flex-wrap: wrap;
        }

        .page-btn {
            padding: 8px 14px;
            border: 1.5px solid #ddd;
            border-radius: var(--radius-sm);
            background: #fff;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            font-size: .83rem;
            text-decoration: none;
            color: #333;
            transition: var(--transition);
        }

        .page-btn:hover,
        .page-btn.active {
            background: var(--primary-color);
            color: #fff;
            border-color: var(--primary-color);
        }

        .page-btn.disabled {
            opacity: .4;
            pointer-events: none;
        }

        /* ── Modal ── */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .5);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .modal-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .modal {
            background: #fff;
            border-radius: var(--radius-md);
            width: 100%;
            max-width: 680px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .2);
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: #fff;
            z-index: 1;
        }

        .modal-header h3 {
            font-size: 1.1rem;
            color: var(--primary-color);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: #999;
            transition: var(--transition);
        }

        .modal-close:hover {
            color: #333;
        }

        .modal-body {
            padding: 24px;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 24px;
        }

        .detail-item label {
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #888;
            display: block;
            margin-bottom: 4px;
        }

        .detail-item span {
            font-size: .9rem;
            font-weight: 500;
            color: #333;
        }

        .detail-item.full {
            grid-column: 1/-1;
        }

        .section-title {
            font-size: .85rem;
            font-weight: 600;
            color: var(--primary-color);
            text-transform: uppercase;
            letter-spacing: .5px;
            margin: 20px 0 12px;
            padding-bottom: 6px;
            border-bottom: 2px solid var(--light-color);
        }

        .status-select-wrap {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #f0f0f0;
        }

        .status-select-wrap label {
            font-size: .85rem;
            font-weight: 500;
            white-space: nowrap;
        }

        .status-select-wrap select {
            flex: 1;
            min-width: 160px;
            padding: 9px 14px;
            border: 1.5px solid #ddd;
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: .85rem;
        }

        .timeline {
            list-style: none;
            position: relative;
            padding-left: 24px;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 7px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #eee;
        }

        .timeline li {
            position: relative;
            padding: 0 0 16px 16px;
        }

        .timeline li::before {
            content: '';
            position: absolute;
            left: -8px;
            top: 5px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--primary-color);
            border: 2px solid #fff;
            box-shadow: 0 0 0 2px var(--primary-color);
        }

        .timeline .t-action {
            font-size: .82rem;
            font-weight: 500;
            color: #333;
        }

        .timeline .t-note {
            font-size: .78rem;
            color: #666;
            margin-top: 2px;
        }

        .timeline .t-time {
            font-size: .72rem;
            color: #aaa;
            margin-top: 2px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #aaa;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            display: block;
            opacity: .4;
        }

        .empty-state p {
            font-size: .9rem;
        }

        /* ── Confirm modal ── */
        .confirm-modal {
            max-width: 400px;
        }

        .confirm-body {
            padding: 32px 24px;
            text-align: center;
        }

        .confirm-icon {
            font-size: 48px;
            color: var(--danger-color);
            margin-bottom: 16px;
        }

        .confirm-body h4 {
            font-size: 1.1rem;
            margin-bottom: 8px;
        }

        .confirm-body p {
            font-size: .85rem;
            color: #666;
            margin-bottom: 24px;
        }

        .confirm-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        /* ── Toast ── */
        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 3000;
            background: #333;
            color: #fff;
            padding: 12px 20px;
            border-radius: var(--radius-sm);
            font-size: .85rem;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: var(--shadow-md);
            transform: translateY(80px);
            opacity: 0;
            transition: var(--transition);
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        .toast.success {
            background: #27ae60;
        }

        .toast.error {
            background: #e74c3c;
        }

        /* ── Responsive ── */
        @media(min-width:768px) {

            .mobile-menu-btn,
            .sidebar-overlay {
                display: none;
            }

            .sidebar {
                transform: translateX(0);
            }

            .main-content {
                margin-left: var(--sidebar-width);
            }
        }

        @media(max-width:767px) {
            .mobile-menu-btn {
                display: flex;
            }

            .main-content {
                padding-top: 70px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .detail-grid {
                grid-template-columns: 1fr;
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
                <h1><i class="fas fa-user-plus" style="margin-right:8px;font-size:1.2rem;"></i>Admission Applications</h1>
                <p>Manage and track all incoming admission enquiries</p>
            </div>
            <div style="display:flex;gap:10px;align-items:center;">
                <a href="index.php" class="btn btn-outline"><i class="fas fa-home"></i> Dashboard</a>
                <button class="logout-btn" onclick="window.location.href='/msv/logout.php'">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </div>

        <?php if (isset($error_msg)): ?>
            <div style="background:#fde8e8;color:#c0392b;padding:14px 18px;border-radius:var(--radius-sm);margin-bottom:20px;font-size:.85rem;">
                <i class="fas fa-exclamation-circle"></i> Database error: <?php echo htmlspecialchars($error_msg); ?>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <?php
            $sc = [
                'total'     => ['Total',     'layer',        'total'],
                'pending'   => ['Pending',   'clock',        'pending'],
                'contacted' => ['Contacted', 'phone',        'contacted'],
                'reviewed'  => ['Reviewed',  'eye',          'reviewed'],
                'enrolled'  => ['Enrolled',  'check-circle', 'enrolled'],
                'rejected'  => ['Rejected',  'times-circle', 'rejected'],
            ];
            foreach ($sc as $key => [$label, $icon, $cls]):
            ?>
                <div class="stat-card <?php echo $cls; ?>" onclick="filterByStatus('<?php echo $key; ?>')">
                    <div class="stat-card-header">
                        <div>
                            <div class="stat-value"><?php echo $stats[$key] ?? 0; ?></div>
                            <div class="stat-label"><?php echo $label; ?></div>
                        </div>
                        <div class="stat-icon"><i class="fas fa-<?php echo $icon; ?>"></i></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Toolbar -->
        <div class="toolbar">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search by name, phone or application no…"
                    value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <select class="filter-select" id="statusFilter">
                <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                <?php foreach (['pending', 'contacted', 'reviewed', 'enrolled', 'rejected'] as $st): ?>
                    <option value="<?php echo $st; ?>" <?php echo $filter_status === $st ? 'selected' : ''; ?>><?php echo ucfirst($st); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!empty($levels)): ?>
                <select class="filter-select" id="levelFilter">
                    <option value="all" <?php echo $filter_level === 'all' ? 'selected' : ''; ?>>All Levels</option>
                    <?php foreach ($levels as $lv): ?>
                        <option value="<?php echo htmlspecialchars($lv); ?>" <?php echo $filter_level === $lv ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($lv); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <button class="btn btn-primary" onclick="applyFilters()">
                <i class="fas fa-filter"></i> Filter
            </button>
            <?php if ($filter_status !== 'all' || $filter_level !== 'all' || $search !== ''): ?>
                <a href="admissions.php" class="btn btn-outline"><i class="fas fa-times"></i> Clear</a>
            <?php endif; ?>
        </div>

        <!-- Table Card -->
        <div class="card">
            <div class="card-header">
                <h3>Applications</h3>
                <span class="count"><?php echo number_format($total_rows); ?> record<?php echo $total_rows != 1 ? 's' : ''; ?></span>
            </div>
            <div class="table-wrap">
                <?php if (!empty($applications)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>App No.</th>
                                <th>Child</th>
                                <th>Level</th>
                                <th>Parent / Guardian</th>
                                <th>Phone</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($applications as $i => $app): ?>
                                <tr>
                                    <td><?php echo $offset + $i + 1; ?></td>
                                    <td><span class="app-number"><?php echo htmlspecialchars($app['application_number']); ?></span></td>
                                    <td>
                                        <div class="child-name"><?php echo htmlspecialchars($app['full_name']); ?></div>
                                        <div class="sub-text"><?php echo htmlspecialchars($app['gender']); ?> &middot;
                                            DOB: <?php echo date('d M Y', strtotime($app['date_of_birth'])); ?>
                                        </div>
                                    </td>
                                    <td><span class="level-badge"><?php echo htmlspecialchars($app['applying_level']); ?></span></td>
                                    <td>
                                        <div><?php echo htmlspecialchars($app['parent_name']); ?></div>
                                        <div class="sub-text"><?php echo ucfirst($app['relationship']); ?></div>
                                    </td>
                                    <td>
                                        <a href="tel:<?php echo $app['phone_number']; ?>" style="color:inherit;text-decoration:none;">
                                            <?php echo htmlspecialchars($app['phone_number']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo statusBadge($app['status']); ?></td>
                                    <td>
                                        <div><?php echo date('d M Y', strtotime($app['created_at'])); ?></div>
                                        <div class="sub-text"><?php echo date('H:i', strtotime($app['created_at'])); ?></div>
                                    </td>
                                    <td>
                                        <div class="action-btns">
                                            <button class="btn-icon btn-view" title="View Details"
                                                onclick="viewApplication(<?php echo $app['id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn-icon btn-delete" title="Delete"
                                                onclick="confirmDelete(<?php echo $app['id']; ?>, '<?php echo addslashes($app['full_name']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>No applications found<?php echo $search !== '' ? ' for your search' : ''; ?>.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <a class="page-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>"
                        href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                        <a class="page-btn <?php echo $p === $page ? 'active' : ''; ?>"
                            href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>">
                            <?php echo $p; ?>
                        </a>
                    <?php endfor; ?>
                    <a class="page-btn <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"
                        href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            <?php endif; ?>
        </div><!-- /card -->

        <div style="text-align:center;padding:20px;color:#aaa;font-size:.8rem;border-top:1px solid var(--light-color);margin-top:20px;">
            &copy; <?php echo date('Y'); ?> <?php echo $school_name; ?> – Online Portal
        </div>
    </div><!-- /main-content -->

    <!-- ── Detail Modal ── -->
    <div class="modal-overlay" id="detailModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="modalTitle">Application Details</h3>
                <button class="modal-close" onclick="closeModal('detailModal')"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="modalBody">
                <div style="text-align:center;padding:40px;">
                    <i class="fas fa-spinner fa-spin" style="font-size:32px;color:var(--primary-color);"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Confirm Delete Modal ── -->
    <div class="modal-overlay" id="confirmModal">
        <div class="modal confirm-modal">
            <div class="confirm-body">
                <div class="confirm-icon"><i class="fas fa-trash-alt"></i></div>
                <h4>Delete Application?</h4>
                <p id="confirmText">This action cannot be undone.</p>
                <div class="confirm-actions">
                    <button class="btn btn-outline" onclick="closeModal('confirmModal')">Cancel</button>
                    <button class="btn" style="background:var(--danger-color);color:#fff;" id="confirmDeleteBtn">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div class="toast" id="toast"></div>

    <?php require_once 'includes/sidebar.php'; ?>

    <script>
        // ── PHP data to JS ────────────────────────────────────────────────────────────
        const APPLICATIONS = <?php echo json_encode(array_map(function ($a) {
                                    return [
                                        'id'                 => $a['id'],
                                        'application_number' => $a['application_number'],
                                        'full_name'          => $a['full_name'],
                                        'first_name'         => $a['first_name'],
                                        'last_name'          => $a['last_name'],
                                        'date_of_birth'      => $a['date_of_birth'],
                                        'gender'             => $a['gender'],
                                        'applying_level'     => $a['applying_level'],
                                        'parent_name'        => $a['parent_name'],
                                        'relationship'       => $a['relationship'],
                                        'phone_number'       => $a['phone_number'],
                                        'email_address'      => $a['email_address'],
                                        'home_address'       => $a['home_address'],
                                        'additional_notes'   => $a['additional_notes'],
                                        'status'             => $a['status'],
                                        'reviewed_by_name'   => $a['reviewed_by_name'],
                                        'contacted_at'       => $a['contacted_at'],
                                        'reviewed_at'        => $a['reviewed_at'],
                                        'created_at'         => $a['created_at'],
                                    ];
                                }, $applications)); ?>;

        const LOGS_BY_APP = <?php
                            $logs_map = [];
                            if ($detail) {
                                $logs_map[$detail['id']] = $detail_logs ?? [];
                            }
                            echo json_encode($logs_map);
                            ?>;

        // ── Mobile sidebar ────────────────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', () => {
            const btn = document.getElementById('mobileMenuBtn');
            setTimeout(() => {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                if (btn && sidebar) {
                    btn.addEventListener('click', e => {
                        e.preventDefault();
                        sidebar.classList.toggle('active');
                        if (overlay) overlay.classList.toggle('active');
                        document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
                    });
                }
                if (overlay) {
                    overlay.addEventListener('click', () => {
                        sidebar.classList.remove('active');
                        overlay.classList.remove('active');
                        document.body.style.overflow = '';
                    });
                }
            }, 100);
        });

        // ── Filters ───────────────────────────────────────────────────────────────────
        function applyFilters() {
            const s = document.getElementById('searchInput').value.trim();
            const st = document.getElementById('statusFilter').value;
            const lv = document.getElementById('levelFilter') ? document.getElementById('levelFilter').value : 'all';
            const p = new URLSearchParams({
                search: s,
                status: st,
                level: lv,
                page: 1
            });
            window.location.href = 'admissions.php?' + p.toString();
        }
        document.getElementById('searchInput').addEventListener('keydown', e => {
            if (e.key === 'Enter') applyFilters();
        });

        function filterByStatus(s) {
            const p = new URLSearchParams({
                status: s === 'total' ? 'all' : s,
                level: 'all',
                search: '',
                page: 1
            });
            window.location.href = 'admissions.php?' + p.toString();
        }

        // ── View Modal ────────────────────────────────────────────────────────────────
        const STATUS_COLORS = {
            pending: {
                bg: '#fff3cd',
                color: '#856404'
            },
            contacted: {
                bg: '#cfe2ff',
                color: '#0a3869'
            },
            reviewed: {
                bg: '#d1ecf1',
                color: '#0c5460'
            },
            enrolled: {
                bg: '#d4edda',
                color: '#155724'
            },
            rejected: {
                bg: '#f8d7da',
                color: '#721c24'
            },
        };
        const STATUS_ICONS = {
            pending: 'clock',
            contacted: 'phone',
            reviewed: 'eye',
            enrolled: 'check-circle',
            rejected: 'times-circle'
        };

        function statusPill(s) {
            const c = STATUS_COLORS[s] || {
                bg: '#eee',
                color: '#333'
            };
            const i = STATUS_ICONS[s] || 'circle';
            return `<span class="status-pill" style="background:${c.bg};color:${c.color}"><i class="fas fa-${i}"></i> ${cap(s)}</span>`;
        }

        function cap(s) {
            return s.charAt(0).toUpperCase() + s.slice(1);
        }

        function fmtDate(d) {
            if (!d) return '—';
            const dt = new Date(d);
            return dt.toLocaleDateString('en-GB', {
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            });
        }

        function fmtDateTime(d) {
            if (!d) return '—';
            const dt = new Date(d);
            return dt.toLocaleDateString('en-GB', {
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            }) + ' ' + dt.toLocaleTimeString('en-GB', {
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function viewApplication(id) {
            const app = APPLICATIONS.find(a => a.id === id);
            if (!app) return;
            document.getElementById('modalTitle').textContent = 'Application — ' + app.application_number;
            document.getElementById('modalBody').innerHTML = buildDetailHTML(app);
            document.getElementById('detailModal').classList.add('show');
            fetchLogs(id);
        }

        function buildDetailHTML(app) {
            return `
    <div class="section-title"><i class="fas fa-child"></i> Child Information</div>
    <div class="detail-grid">
        <div class="detail-item"><label>Full Name</label><span>${escHtml(app.full_name)}</span></div>
        <div class="detail-item"><label>Date of Birth</label><span>${fmtDate(app.date_of_birth)}</span></div>
        <div class="detail-item"><label>Gender</label><span>${cap(app.gender)}</span></div>
        <div class="detail-item"><label>Applying for Level</label><span>${escHtml(app.applying_level)}</span></div>
    </div>

    <div class="section-title"><i class="fas fa-user"></i> Parent / Guardian</div>
    <div class="detail-grid">
        <div class="detail-item"><label>Name</label><span>${escHtml(app.parent_name)}</span></div>
        <div class="detail-item"><label>Relationship</label><span>${cap(app.relationship)}</span></div>
        <div class="detail-item"><label>Phone</label>
            <span><a href="tel:${app.phone_number}" style="color:var(--primary-color)">${escHtml(app.phone_number)}</a></span>
        </div>
        <div class="detail-item"><label>Email</label><span>${app.email_address ? `<a href="mailto:${app.email_address}" style="color:var(--primary-color)">${escHtml(app.email_address)}</a>` : '—'}</span></div>
        <div class="detail-item full"><label>Home Address</label><span>${escHtml(app.home_address)}</span></div>
        ${app.additional_notes ? `<div class="detail-item full"><label>Additional Notes</label><span>${escHtml(app.additional_notes)}</span></div>` : ''}
    </div>

    <div class="section-title"><i class="fas fa-info-circle"></i> Application Status</div>
    <div class="detail-grid">
        <div class="detail-item"><label>Current Status</label><span id="currentStatusPill">${statusPill(app.status)}</span></div>
        <div class="detail-item"><label>Submitted On</label><span>${fmtDateTime(app.created_at)}</span></div>
        ${app.contacted_at ? `<div class="detail-item"><label>Contacted At</label><span>${fmtDateTime(app.contacted_at)}</span></div>` : ''}
        ${app.reviewed_at ? `<div class="detail-item"><label>Reviewed At</label><span>${fmtDateTime(app.reviewed_at)}</span></div>` : ''}
        ${app.reviewed_by_name ? `<div class="detail-item"><label>Reviewed By</label><span>${escHtml(app.reviewed_by_name)}</span></div>` : ''}
    </div>

    <div class="status-select-wrap">
        <label>Update Status:</label>
        <select id="statusUpdateSelect">
            ${['pending','contacted','reviewed','enrolled','rejected'].map(s =>
                `<option value="${s}" ${s===app.status?'selected':''}>${cap(s)}</option>`
            ).join('')}
        </select>
        <button class="btn btn-primary" onclick="updateStatus(${app.id})">
            <i class="fas fa-save"></i> Save
        </button>
    </div>

    <div class="section-title" style="margin-top:24px;"><i class="fas fa-history"></i> Activity Log</div>
    <ul class="timeline" id="activityTimeline">
        <li style="color:#aaa;font-size:.82rem;padding-left:16px;">Loading…</li>
    </ul>`;
        }

        function fetchLogs(appId) {
            fetch(`admissions.php?ajax_logs=1&app_id=${appId}`)
                .then(r => r.json())
                .then(data => {
                    const tl = document.getElementById('activityTimeline');
                    if (!tl) return;
                    if (!data.length) {
                        tl.innerHTML = '<li style="color:#aaa;font-size:.82rem;padding-left:16px;">No activity yet.</li>';
                        return;
                    }
                    tl.innerHTML = data.map(l => `
                <li>
                    <div class="t-action">${cap(l.action.replace('_',' '))}
                        ${l.old_status && l.new_status ? `<span style="font-weight:400;color:#888"> · ${l.old_status} → ${l.new_status}</span>` : ''}
                    </div>
                    ${l.note ? `<div class="t-note">${escHtml(l.note)}</div>` : ''}
                    <div class="t-time">${fmtDateTime(l.created_at)} ${l.performed_by_name ? '· '+escHtml(l.performed_by_name) : ''}</div>
                </li>`).join('');
                })
                .catch(() => {});
        }

        // ── Update Status ─────────────────────────────────────────────────────────────
        function updateStatus(appId) {
            const sel = document.getElementById('statusUpdateSelect');
            if (!sel) return;
            const newStatus = sel.value;
            const fd = new FormData();
            fd.append('action', 'update_status');
            fd.append('app_id', appId);
            fd.append('status', newStatus);

            fetch('admissions.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        // Update pill in modal
                        const pill = document.getElementById('currentStatusPill');
                        if (pill) pill.innerHTML = statusPill(newStatus);
                        // Update in local array
                        const app = APPLICATIONS.find(a => a.id === appId);
                        if (app) app.status = newStatus;
                        // Refresh logs
                        fetchLogs(appId);
                        // Update row badge in table without reload
                        updateRowBadge(appId, newStatus);
                    } else {
                        showToast(data.message, 'error');
                    }
                })
                .catch(() => showToast('Network error', 'error'));
        }

        function updateRowBadge(appId, newStatus) {
            const COLOR = STATUS_COLORS[newStatus] || {
                bg: '#eee',
                color: '#333'
            };
            const ICON = STATUS_ICONS[newStatus] || 'circle';
            document.querySelectorAll('tbody tr').forEach(row => {
                const viewBtn = row.querySelector(`.btn-view`);
                if (viewBtn && viewBtn.getAttribute('onclick').includes(`(${appId})`)) {
                    const pill = row.querySelector('.status-pill');
                    if (pill) {
                        pill.style.background = COLOR.bg;
                        pill.style.color = COLOR.color;
                        pill.innerHTML = `<i class="fas fa-${ICON}"></i> ${cap(newStatus)}`;
                    }
                }
            });
        }

        // ── Delete ────────────────────────────────────────────────────────────────────
        let deleteTargetId = null;

        function confirmDelete(id, name) {
            deleteTargetId = id;
            document.getElementById('confirmText').textContent = `Delete application for "${name}"? This cannot be undone.`;
            document.getElementById('confirmModal').classList.add('show');
        }
        document.getElementById('confirmDeleteBtn').addEventListener('click', () => {
            if (!deleteTargetId) return;
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('app_id', deleteTargetId);
            fetch('admissions.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(data => {
                    closeModal('confirmModal');
                    if (data.success) {
                        showToast('Application deleted', 'success');
                        setTimeout(() => window.location.reload(), 800);
                    } else {
                        showToast(data.message, 'error');
                    }
                });
        });

        // ── Helpers ───────────────────────────────────────────────────────────────────
        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
        }
        document.querySelectorAll('.modal-overlay').forEach(m => {
            m.addEventListener('click', e => {
                if (e.target === m) m.classList.remove('show');
            });
        });

        function showToast(msg, type = 'success') {
            const t = document.getElementById('toast');
            t.className = `toast show ${type}`;
            t.innerHTML = `<i class="fas fa-${type==='success'?'check':'exclamation'}-circle"></i> ${msg}`;
            setTimeout(() => t.classList.remove('show'), 3500);
        }

        function escHtml(s) {
            if (!s) return '';
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // Stat cards entrance animation
        document.querySelectorAll('.stat-card').forEach((c, i) => {
            c.style.opacity = '0';
            c.style.transform = 'translateY(16px)';
            setTimeout(() => {
                c.style.transition = 'all .4s ease';
                c.style.opacity = '1';
                c.style.transform = 'translateY(0)';
            }, i * 80);
        });
    </script>

    <?php
    // AJAX logs endpoint (called by JS fetchLogs)
    if (isset($_GET['ajax_logs']) && isset($_GET['app_id'])) {
        header('Content-Type: application/json');
        try {
            $ls = $pdo->prepare("SELECT l.*, adm.full_name as performed_by_name
                             FROM admission_application_logs l
                             LEFT JOIN admin_users adm ON l.performed_by = adm.id
                             WHERE l.application_id = ? AND l.school_id = ?
                             ORDER BY l.created_at DESC");
            $ls->execute([(int)$_GET['app_id'], $school_id]);
            echo json_encode($ls->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            echo json_encode([]);
        }
        exit;
    }
    ?>
</body>

</html>
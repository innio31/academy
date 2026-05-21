<?php
// ida/admin/id_cards.php - ID Card Generation System
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../includes/config.php';

// Auth check
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /ida/login.php");
    exit();
}

$admin_id   = $_SESSION['admin_id']  ?? $_SESSION['user_id'];
$admin_name = $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? 'Administrator';
$admin_role = $_SESSION['admin_role'] ?? 'admin';
$school_id  = SCHOOL_ID;
$school_name    = SCHOOL_NAME;
$primary_color   = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;

// ── Fetch school details (logo, motto, contact) ─────────────────────────────
try {
    $stmt = $pdo->prepare("SELECT * FROM schools WHERE id = ?");
    $stmt->execute([$school_id]);
    $school = $stmt->fetch(PDO::FETCH_ASSOC);

    // ID card settings (insert defaults if missing)
    $stmt = $pdo->prepare("SELECT * FROM id_card_settings WHERE school_id = ?");
    $stmt->execute([$school_id]);
    $id_settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$id_settings) {
        $default_back = "This ID card is the property of {$school_name}. If found, please return to the school admin office.\n\nUnauthorised use of this card is prohibited. The cardholder must present this card on request by school officials.\n\nLoss of this card should be reported immediately to the school administration.";
        $pdo->prepare("INSERT INTO id_card_settings (school_id, card_back_text, card_template, primary_color, secondary_color, show_motto, show_qr) VALUES (?,?,?,?,?,1,1)")
            ->execute([$school_id, $default_back, 'modern', $primary_color, $secondary_color]);
        $stmt = $pdo->prepare("SELECT * FROM id_card_settings WHERE school_id = ?");
        $stmt->execute([$school_id]);
        $id_settings = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Classes for filter dropdown
    $stmt = $pdo->prepare("SELECT DISTINCT class FROM students WHERE school_id = ? AND status = 'active' ORDER BY class");
    $stmt->execute([$school_id]);
    $classes = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Stats
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE school_id = ? AND status = 'active'");
    $stmt->execute([$school_id]);
    $total_students = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM id_card_generation_log WHERE school_id = ?");
    $stmt->execute([$school_id]);
    $total_generated = $stmt->fetchColumn();
} catch (Exception $e) {
    error_log("ID card page error: " . $e->getMessage());
    $error_message = "Error loading data: " . $e->getMessage();
}

// ── Handle AJAX requests ─────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    // Fetch students list (with optional filters)
    if ($_GET['action'] === 'get_students') {
        $class_filter = $_GET['class'] ?? '';
        $search       = $_GET['search'] ?? '';
        $params = [$school_id];
        $where  = "WHERE s.school_id = ? AND s.status = 'active'";
        if ($class_filter) {
            $where .= " AND s.class = ?";
            $params[] = $class_filter;
        }
        if ($search) {
            $where .= " AND (s.full_name LIKE ? OR s.admission_number LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        $stmt = $pdo->prepare("SELECT s.id, s.full_name, s.admission_number, s.class, s.profile_picture, s.dob, s.gender FROM students s $where ORDER BY s.full_name LIMIT 200");
        $stmt->execute($params);
        echo json_encode(['success' => true, 'students' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit();
    }

    // Save ID card settings
    if ($_GET['action'] === 'save_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $pdo->prepare("UPDATE id_card_settings SET card_back_text=?, card_template=?, primary_color=?, secondary_color=?, show_motto=?, show_qr=?, updated_at=NOW() WHERE school_id=?")
            ->execute([$data['card_back_text'], $data['card_template'], $data['primary_color'], $data['secondary_color'], (int)$data['show_motto'], (int)$data['show_qr'], $school_id]);
        echo json_encode(['success' => true]);
        exit();
    }

    // Log generation
    if ($_GET['action'] === 'log_generation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $student_ids = $data['student_ids'] ?? [];
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt = $pdo->prepare("INSERT INTO id_card_generation_log (school_id, student_id, generated_by, ip_address) VALUES (?,?,?,?)");
        foreach ($student_ids as $sid) {
            $stmt->execute([$school_id, (int)$sid, $admin_id, $ip]);
        }
        echo json_encode(['success' => true, 'logged' => count($student_ids)]);
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($school_name) ?> – ID Card Generator</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
    <!-- html2canvas for PDF export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <!-- jsPDF -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

    <style>
        :root {
            --primary: <?= htmlspecialchars($id_settings['primary_color'] ?? $primary_color) ?>;
            --secondary: <?= htmlspecialchars($id_settings['secondary_color'] ?? $secondary_color) ?>;
            --accent: #e74c3c;
            --success: #27ae60;
            --bg: #f0f2f8;
            --card-bg: #ffffff;
            --text: #1a1a2e;
            --muted: #6b7280;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, .08);
            --shadow-md: 0 4px 20px rgba(0, 0, 0, .12);
            --radius: 14px;
            --sidebar-w: 260px;
            --transition: all .3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* ─── Sidebar (matches existing dashboard) ─────────── */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-w);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary), #1a1a2e);
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
            background: var(--secondary);
            border-radius: 8px;
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

        .admin-info {
            text-align: center;
            padding: 15px;
            background: rgba(255, 255, 255, .1);
            border-radius: 10px;
            margin: 15px;
        }

        .nav-links {
            list-style: none;
            padding: 0 15px;
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
            border-left: 3px solid var(--secondary);
        }

        .nav-links i {
            width: 20px;
            text-align: center;
        }

        /* ─── Top bar ─────────────────────────────────────── */
        .topbar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: var(--card-bg);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 24px;
            border-bottom: 1px solid rgba(0, 0, 0, .06);
            box-shadow: var(--shadow-sm);
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .menu-btn {
            background: none;
            border: none;
            font-size: 1.3rem;
            cursor: pointer;
            color: var(--text);
        }

        .page-title {
            font-size: 1.15rem;
            font-weight: 600;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* ─── Mobile toggle ───────────────────────────────── */
        .mobile-menu-toggle {
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: var(--primary);
            color: #fff;
            border: none;
            font-size: 1.3rem;
            cursor: pointer;
            box-shadow: var(--shadow-md);
            z-index: 999;
        }

        .overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .5);
            z-index: 999;
        }

        .overlay.active {
            display: block;
        }

        /* ─── Main layout ─────────────────────────────────── */
        .main-content {
            padding: 24px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* ─── Stat cards ──────────────────────────────────── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--primary);
        }

        .stat-card .val {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-card .lbl {
            font-size: .78rem;
            color: var(--muted);
            margin-top: 2px;
        }

        /* ─── Controls bar ────────────────────────────────── */
        .controls-bar {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 18px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }

        .controls-bar input,
        .controls-bar select {
            border: 1.5px solid #e5e7eb;
            border-radius: 8px;
            padding: 9px 14px;
            font-family: inherit;
            font-size: .88rem;
            outline: none;
            transition: var(--transition);
            background: #fff;
        }

        .controls-bar input:focus,
        .controls-bar select:focus {
            border-color: var(--primary);
        }

        .controls-bar input {
            flex: 1;
            min-width: 200px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            border-radius: 8px;
            border: none;
            font-family: inherit;
            font-size: .88rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            white-space: nowrap;
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
        }

        .btn-primary:hover {
            opacity: .88;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: #f3f4f6;
            color: var(--text);
        }

        .btn-secondary:hover {
            background: #e5e7eb;
        }

        .btn-success {
            background: var(--success);
            color: #fff;
        }

        .btn-success:hover {
            opacity: .88;
            transform: translateY(-1px);
        }

        .btn-warning {
            background: #f59e0b;
            color: #fff;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: .8rem;
        }

        .btn:disabled {
            opacity: .5;
            cursor: not-allowed;
            transform: none !important;
        }

        /* ─── Student grid ────────────────────────────────── */
        .grid-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .grid-header h3 {
            font-size: 1rem;
            font-weight: 600;
        }

        .selected-count {
            background: var(--primary);
            color: #fff;
            font-size: .78rem;
            padding: 4px 12px;
            border-radius: 20px;
        }

        .student-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 14px;
            max-height: 520px;
            overflow-y: auto;
            padding-right: 4px;
        }

        .student-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            box-shadow: var(--shadow-sm);
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid transparent;
            position: relative;
        }

        .student-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .student-card.selected {
            border-color: var(--primary);
            background: #f0f4ff;
        }

        .student-card .check-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: var(--primary);
            color: #fff;
            font-size: .7rem;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .student-card.selected .check-badge {
            display: flex;
        }

        .student-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--secondary);
            margin: 0 auto 10px;
        }

        .student-avatar-placeholder {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff;
            font-size: 1.6rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            border: 3px solid var(--secondary);
        }

        .student-card .s-name {
            font-size: .88rem;
            font-weight: 600;
            margin-bottom: 3px;
        }

        .student-card .s-adm {
            font-size: .75rem;
            color: var(--muted);
        }

        .student-card .s-cls {
            font-size: .72rem;
            color: var(--primary);
            font-weight: 500;
            margin-top: 4px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 12px;
            opacity: .4;
            display: block;
        }

        /* ─── Preview modal ───────────────────────────────── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .7);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal {
            background: var(--card-bg);
            border-radius: var(--radius);
            width: 100%;
            max-width: 960px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .3);
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 24px;
            border-bottom: 1px solid #e5e7eb;
            position: sticky;
            top: 0;
            background: var(--card-bg);
            z-index: 1;
        }

        .modal-header h2 {
            font-size: 1.1rem;
            font-weight: 600;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.4rem;
            cursor: pointer;
            color: var(--muted);
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            padding: 16px 24px;
            border-top: 1px solid #e5e7eb;
            flex-wrap: wrap;
        }

        /* ─── ID Cards preview layout ─────────────────────── */
        .cards-preview-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 30px;
            justify-content: center;
        }

        /* ─── THE ID CARD ─────────────────────────────────── */
        .id-card-wrapper {
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: center;
        }

        .id-card-label {
            font-size: .72rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .id-card {
            width: 340px;
            height: 210px;
            border-radius: 14px;
            overflow: hidden;
            position: relative;
            font-family: 'Poppins', sans-serif;
            box-shadow: 0 8px 30px rgba(0, 0, 0, .22);
            flex-shrink: 0;
        }

        /* ── FRONT FACE ── */
        .id-front {
            background: #fff;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
        }

        .id-front .card-header {
            background: var(--card-primary, #722F37);
            padding: 10px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
            overflow: hidden;
        }

        .id-front .card-header::after {
            content: '';
            position: absolute;
            right: -20px;
            bottom: -20px;
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, .08);
            border-radius: 50%;
        }

        .id-front .card-header::before {
            content: '';
            position: absolute;
            right: 20px;
            bottom: -30px;
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, .06);
            border-radius: 50%;
        }

        .school-logo-img {
            width: 38px;
            height: 38px;
            border-radius: 6px;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, .4);
            flex-shrink: 0;
        }

        .school-logo-placeholder {
            width: 38px;
            height: 38px;
            border-radius: 6px;
            background: rgba(255, 255, 255, .2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: #fff;
            flex-shrink: 0;
            border: 2px solid rgba(255, 255, 255, .3);
        }

        .school-info {
            flex: 1;
            min-width: 0;
        }

        .school-info .sname {
            font-size: .72rem;
            font-weight: 800;
            color: #fff;
            text-transform: uppercase;
            letter-spacing: .03em;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            line-height: 1.25;
        }

        .school-info .motto {
            font-size: .5rem;
            color: rgba(255, 255, 255, .75);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-style: italic;
            margin-top: 1px;
        }

        .id-label-badge {
            background: rgba(255, 255, 255, .15);
            border: 1px solid rgba(255, 255, 255, .3);
            color: #fff;
            font-size: .48rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 10px;
            text-transform: uppercase;
            letter-spacing: .08em;
            flex-shrink: 0;
        }

        .id-front .card-body {
            flex: 1;
            display: flex;
            align-items: stretch;
            padding: 12px 14px;
            gap: 12px;
        }

        .student-photo-wrap {
            position: relative;
            flex-shrink: 0;
        }

        .student-photo {
            width: 72px;
            height: 86px;
            border-radius: 8px;
            object-fit: cover;
            border: 3px solid var(--card-secondary, #d4af7a);
            display: block;
        }

        .student-photo-ph {
            width: 72px;
            height: 86px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--card-primary, #722F37), var(--card-secondary, #d4af7a));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: 700;
            color: #fff;
            border: 3px solid var(--card-secondary, #d4af7a);
        }

        .student-details {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 5px;
        }

        .std-name {
            font-size: .82rem;
            font-weight: 800;
            color: #1a1a2e;
            line-height: 1.2;
            text-transform: uppercase;
            letter-spacing: .02em;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .detail-row {
            display: flex;
            flex-direction: column;
        }

        .detail-lbl {
            font-size: .46rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #9ca3af;
            font-weight: 600;
            line-height: 1;
        }

        .detail-val {
            font-size: .6rem;
            color: #374151;
            font-weight: 600;
            line-height: 1.3;
        }

        .id-front .card-footer {
            height: 6px;
            background: linear-gradient(90deg, var(--card-primary, #722F37), var(--card-secondary, #d4af7a), var(--card-primary, #722F37));
        }

        /* ── Watermark ── */
        .card-watermark {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }

        .card-watermark span {
            font-size: .95rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .18em;
            color: var(--card-primary, #722F37);
            opacity: .04;
            white-space: nowrap;
            transform: rotate(-30deg);
            text-shadow:
                -90px -30px 0 var(--card-primary, #722F37),
                90px -30px 0 var(--card-primary, #722F37),
                -90px 30px 0 var(--card-primary, #722F37),
                90px 30px 0 var(--card-primary, #722F37),
                0px -60px 0 var(--card-primary, #722F37),
                0px 60px 0 var(--card-primary, #722F37),
                -180px 0px 0 var(--card-primary, #722F37),
                180px 0px 0 var(--card-primary, #722F37);
        }

        /* Make card-body and card contents sit above watermark */
        .id-front .card-header,
        .id-front .card-body,
        .id-front .card-footer,
        .id-back .back-header,
        .id-back .back-body,
        .id-back .back-footer,
        .front-qr-wrap {
            position: relative;
            z-index: 1;
        }

        /* QR area on front */
        .front-qr-wrap {
            position: absolute;
            bottom: 12px;
            right: 12px;
            background: #fff;
            padding: 3px;
            border-radius: 4px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, .15);
        }

        .front-qr-wrap canvas,
        .front-qr-wrap img {
            width: 46px !important;
            height: 46px !important;
            display: block;
        }

        /* ── BACK FACE ── */
        .id-back {
            background: #fff;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
        }

        .id-back .back-header {
            background: var(--card-primary, #722F37);
            height: 8px;
        }

        .id-back .back-body {
            flex: 1;
            padding: 12px 16px;
            display: flex;
            gap: 14px;
            align-items: flex-start;
        }

        .back-logo-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
            padding-top: 2px;
        }

        .back-logo {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            object-fit: cover;
            border: 2px solid var(--card-secondary, #d4af7a);
        }

        .back-logo-ph {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--card-primary, #722F37), var(--card-secondary, #d4af7a));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            color: #fff;
            border: 2px solid var(--card-secondary, #d4af7a);
        }

        .back-divider {
            width: 1px;
            background: linear-gradient(180deg, transparent, var(--card-secondary, #d4af7a), transparent);
            align-self: stretch;
            flex-shrink: 0;
        }

        .back-text-area {
            flex: 1;
        }

        .back-title {
            font-size: .56rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--card-primary, #722F37);
            margin-bottom: 8px;
        }

        .back-body-text {
            font-size: .49rem;
            color: #4b5563;
            line-height: 1.7;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .back-contacts {
            margin-top: 8px;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .back-contact-row {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: .48rem;
            color: #6b7280;
        }

        .back-contact-row i {
            color: var(--card-primary, #722F37);
            width: 10px;
        }

        .id-back .back-footer {
            height: 28px;
            background: linear-gradient(90deg, var(--card-primary, #722F37), var(--card-secondary, #d4af7a));
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .back-footer-text {
            font-size: .46rem;
            color: rgba(255, 255, 255, .85);
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        /* ─── Settings panel ──────────────────────────────── */
        .settings-panel {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 24px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 20px;
        }

        .settings-panel h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 18px;
        }

        .form-group label {
            display: block;
            font-size: .8rem;
            font-weight: 500;
            color: var(--muted);
            margin-bottom: 6px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            border: 1.5px solid #e5e7eb;
            border-radius: 8px;
            padding: 9px 12px;
            font-family: inherit;
            font-size: .88rem;
            outline: none;
            transition: var(--transition);
            background: #fff;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--primary);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 90px;
        }

        .color-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .color-row input[type=color] {
            width: 40px;
            height: 38px;
            padding: 2px;
            border-radius: 6px;
            cursor: pointer;
        }

        .toggle-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .toggle {
            position: relative;
            width: 44px;
            height: 24px;
        }

        .toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: #e5e7eb;
            border-radius: 12px;
            transition: .3s;
        }

        .toggle-slider::before {
            content: '';
            position: absolute;
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background: #fff;
            border-radius: 50%;
            transition: .3s;
        }

        .toggle input:checked+.toggle-slider {
            background: var(--primary);
        }

        .toggle input:checked+.toggle-slider::before {
            transform: translateX(20px);
        }

        /* ─── Loading spinner ─────────────────────────────── */
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, .4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .6s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* ─── Toast ───────────────────────────────────────── */
        .toast {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: #1a1a2e;
            color: #fff;
            padding: 12px 24px;
            border-radius: 10px;
            font-size: .88rem;
            z-index: 9999;
            transition: transform .3s ease;
            box-shadow: var(--shadow-md);
        }

        .toast.show {
            transform: translateX(-50%) translateY(0);
        }

        .toast.success {
            background: var(--success);
        }

        .toast.error {
            background: var(--accent);
        }

        /* ─── Print / PDF styles ──────────────────────────── */
        @media print {
            body>*:not(#print-area) {
                display: none !important;
            }

            #print-area {
                display: block !important;
            }

            .id-card-page {
                page-break-after: always;
                display: flex;
                flex-wrap: wrap;
                gap: 20px;
                justify-content: center;
                padding: 10mm;
            }
        }

        #print-area {
            display: none;
        }

        /* ─── Responsive ──────────────────────────────────── */
        @media(max-width:640px) {
            .main-content {
                padding: 14px;
            }

            .id-card {
                width: 300px;
                height: 185px;
            }

            .student-photo,
            .student-photo-ph {
                width: 58px;
                height: 70px;
            }

            .std-name {
                font-size: .65rem;
            }

            .student-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            }
        }
    </style>
</head>

<body>

    <!-- Overlay -->
    <div class="overlay" id="overlay" onclick="toggleSidebar()"></div>

    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon">
                    <?php if (!empty($school['logo_path'])): ?>
                        <img src="/ida/assets/loida/ida001.png" style="width:100%;height:100%;object-fit:cover;border-radius:6px;" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                        <i class="fas fa-graduation-cap" style="display:none;"></i>
                    <?php else: ?>
                        <i class="fas fa-graduation-cap"></i>
                    <?php endif; ?>
                </div>
                <div class="logo-text">
                    <h3><?= htmlspecialchars($school_name) ?></h3>
                    <p>Admin Portal</p>
                </div>
            </div>
        </div>
        <div class="admin-info">
            <div style="font-weight:600;font-size:.9rem;"><?= htmlspecialchars($admin_name) ?></div>
            <div style="font-size:.72rem;opacity:.75;text-transform:capitalize;"><?= htmlspecialchars($admin_role) ?></div>
        </div>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="students.php"><i class="fas fa-user-graduate"></i> Students</a></li>
            <li><a href="staff.php"><i class="fas fa-chalkboard-teacher"></i> Staff</a></li>
            <li><a href="results.php"><i class="fas fa-chart-bar"></i> Results</a></li>
            <li><a href="id_cards.php" class="active"><i class="fas fa-id-card"></i> ID Cards</a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
            <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </nav>

    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <button class="menu-btn" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
            <span class="page-title"><i class="fas fa-id-card" style="color:var(--primary);margin-right:6px;"></i> ID Card Generator</span>
        </div>
        <div class="topbar-right">
            <button class="btn btn-primary btn-sm" onclick="openSettingsModal()">
                <i class="fas fa-cog"></i> Settings
            </button>
        </div>
    </div>

    <!-- Main -->
    <main class="main-content">

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="val"><?= number_format($total_students) ?></div>
                <div class="lbl"><i class="fas fa-users" style="margin-right:4px;"></i> Active Students</div>
            </div>
            <div class="stat-card" style="border-left-color:var(--secondary)">
                <div class="val" style="color:var(--secondary);"><?= number_format($total_generated) ?></div>
                <div class="lbl"><i class="fas fa-print" style="margin-right:4px;"></i> Cards Generated</div>
            </div>
            <div class="stat-card" style="border-left-color:#27ae60">
                <div class="val" id="selected-stat" style="color:#27ae60;">0</div>
                <div class="lbl"><i class="fas fa-check-circle" style="margin-right:4px;"></i> Selected Now</div>
            </div>
        </div>

        <!-- Filter & Search -->
        <div class="controls-bar">
            <input type="text" id="search-input" placeholder="&#128269; Search by name or admission number..." oninput="debounceSearch()">
            <select id="class-filter" onchange="loadStudents()">
                <option value="">All Classes</option>
                <?php foreach ($classes as $cls): ?>
                    <option value="<?= htmlspecialchars($cls) ?>"><?= htmlspecialchars($cls) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-secondary" onclick="selectAll()"><i class="fas fa-check-double"></i> Select All</button>
            <button class="btn btn-secondary" onclick="clearSelection()"><i class="fas fa-times"></i> Clear</button>
            <button class="btn btn-primary" id="preview-btn" onclick="openPreviewModal()" disabled>
                <i class="fas fa-eye"></i> Preview & Print
            </button>
        </div>

        <!-- Student grid -->
        <div class="grid-header">
            <h3>Students <span id="student-count" style="color:var(--muted);font-weight:400;font-size:.88rem;"></span></h3>
            <span class="selected-count" id="selected-count-badge" style="display:none;"></span>
        </div>
        <div class="student-grid" id="student-grid">
            <div class="empty-state"><i class="fas fa-spinner fa-spin"></i><span>Loading students…</span></div>
        </div>
    </main>

    <!-- Mobile menu button -->
    <button class="mobile-menu-toggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>

    <!-- ─── Preview Modal ───────────────────────────────────────────── -->
    <div class="modal-overlay" id="preview-modal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-id-card" style="margin-right:8px;color:var(--primary);"></i>ID Card Preview</h2>
                <button class="modal-close" onclick="closeModal('preview-modal')"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <p style="font-size:.82rem;color:var(--muted);margin-bottom:20px;">
                    Review the cards below. Each card shows front and back. Click <strong>Download PDF</strong> to export all selected cards.
                </p>
                <div class="cards-preview-grid" id="cards-preview-grid"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('preview-modal')"><i class="fas fa-times"></i> Close</button>
                <button class="btn btn-warning" onclick="printCards()"><i class="fas fa-print"></i> Print</button>
                <button class="btn btn-success" id="pdf-btn" onclick="downloadPDF()"><i class="fas fa-file-pdf"></i> Download PDF</button>
            </div>
        </div>
    </div>

    <!-- ─── Settings Modal ─────────────────────────────────────────── -->
    <div class="modal-overlay" id="settings-modal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-cog" style="margin-right:8px;color:var(--primary);"></i>ID Card Settings</h2>
                <button class="modal-close" onclick="closeModal('settings-modal')"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="settings-grid">
                    <div class="form-group">
                        <label>Card Template</label>
                        <select id="s-template">
                            <option value="modern" <?= ($id_settings['card_template'] ?? 'modern') === 'modern'  ? 'selected' : '' ?>>Modern (Default)</option>
                            <option value="classic" <?= ($id_settings['card_template'] ?? '') === 'classic' ? 'selected' : '' ?>>Classic</option>
                            <option value="premium" <?= ($id_settings['card_template'] ?? '') === 'premium' ? 'selected' : '' ?>>Premium</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Card Primary Colour</label>
                        <div class="color-row">
                            <input type="color" id="s-primary" value="<?= htmlspecialchars($id_settings['primary_color'] ?? $primary_color) ?>">
                            <input type="text" id="s-primary-txt" value="<?= htmlspecialchars($id_settings['primary_color'] ?? $primary_color) ?>" placeholder="#722F37" style="flex:1;">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Card Secondary Colour</label>
                        <div class="color-row">
                            <input type="color" id="s-secondary" value="<?= htmlspecialchars($id_settings['secondary_color'] ?? $secondary_color) ?>">
                            <input type="text" id="s-secondary-txt" value="<?= htmlspecialchars($id_settings['secondary_color'] ?? $secondary_color) ?>" placeholder="#d4af7a" style="flex:1;">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Show School Motto on Card</label>
                        <div class="toggle-row">
                            <label class="toggle">
                                <input type="checkbox" id="s-motto" <?= ($id_settings['show_motto'] ?? 1) ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                            <span style="font-size:.85rem;">Display motto</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Show QR Code on Card</label>
                        <div class="toggle-row">
                            <label class="toggle">
                                <input type="checkbox" id="s-qr" <?= ($id_settings['show_qr'] ?? 1) ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                            <span style="font-size:.85rem;">Display QR code</span>
                        </div>
                    </div>
                    <div class="form-group" style="grid-column:1/-1;">
                        <label>Card Back Text</label>
                        <textarea id="s-back-text" rows="5"><?= htmlspecialchars($id_settings['card_back_text'] ?? '') ?></textarea>
                        <span style="font-size:.72rem;color:var(--muted);">This text appears on the back of every ID card.</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('settings-modal')">Cancel</button>
                <button class="btn btn-primary" onclick="saveSettings()"><i class="fas fa-save"></i> Save Settings</button>
            </div>
        </div>
    </div>

    <!-- Hidden print area -->
    <div id="print-area"></div>
    <div class="toast" id="toast"></div>

    <!-- Pass PHP data to JS -->
    <script>
        const SCHOOL = {
            name: <?= json_encode($school_name) ?>,
            motto: <?= json_encode($school['motto'] ?? '') ?>,
            logo: '/ida/assets/loida/ida001.png',
            email: <?= json_encode($school['contact_email'] ?? '') ?>,
            phone: <?= json_encode($school['contact_phone'] ?? '') ?>,
            school_id: <?= (int)$school_id ?>
        };
        const CARD_SETTINGS = {
            primary: <?= json_encode($id_settings['primary_color'] ?? $primary_color) ?>,
            secondary: <?= json_encode($id_settings['secondary_color'] ?? $secondary_color) ?>,
            show_motto: <?= ($id_settings['show_motto'] ?? 1) ? 'true' : 'false' ?>,
            show_qr: <?= ($id_settings['show_qr'] ?? 1) ? 'true' : 'false' ?>,
            back_text: <?= json_encode($id_settings['card_back_text'] ?? '') ?>,
            template: <?= json_encode($id_settings['card_template'] ?? 'modern') ?>
        };
        const API_BASE = 'id_cards.php';
    </script>

    <script>
        // ─── State ──────────────────────────────────────────────────────
        let allStudents = [];
        let selected = new Set();
        let searchTimer = null;

        // ─── Sidebar ─────────────────────────────────────────────────────
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('active');
            document.getElementById('overlay').classList.toggle('active');
        }

        // ─── Load students ────────────────────────────────────────────────
        async function loadStudents() {
            const search = document.getElementById('search-input').value.trim();
            const cls = document.getElementById('class-filter').value;
            const grid = document.getElementById('student-grid');
            grid.innerHTML = '<div class="empty-state"><i class="fas fa-spinner fa-spin"></i><span style="margin-left:8px;">Loading…</span></div>';

            const params = new URLSearchParams({
                action: 'get_students',
                search,
                class: cls
            });
            const res = await fetch(`${API_BASE}?${params}`);
            const data = await res.json();
            allStudents = data.students || [];

            document.getElementById('student-count').textContent = `(${allStudents.length})`;
            renderGrid();
        }

        function debounceSearch() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(loadStudents, 320);
        }

        // ─── Render student cards ─────────────────────────────────────────
        function renderGrid() {
            const grid = document.getElementById('student-grid');
            if (!allStudents.length) {
                grid.innerHTML = '<div class="empty-state"><i class="fas fa-user-graduate"></i><span>No students found</span></div>';
                return;
            }
            grid.innerHTML = allStudents.map(s => {
                const initials = s.full_name.split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase();
                const photoSrc = s.profile_picture ? s.profile_picture : null;
                const avatar = photoSrc ?
                    `<img class="student-avatar" src="${photoSrc}" onerror="this.outerHTML='<div class=student-avatar-placeholder>${initials}</div>'" alt="${s.full_name}">` :
                    `<div class="student-avatar-placeholder">${initials}</div>`;
                const isSel = selected.has(s.id);
                return `
        <div class="student-card ${isSel?'selected':''}" id="sc-${s.id}" onclick="toggleStudent(${s.id})">
            <div class="check-badge"><i class="fas fa-check"></i></div>
            ${avatar}
            <div class="s-name">${esc(s.full_name)}</div>
            <div class="s-adm">${esc(s.admission_number)}</div>
            <div class="s-cls">${esc(s.class)}</div>
        </div>`;
            }).join('');
            updateSelUI();
        }

        function toggleStudent(id) {
            if (selected.has(id)) selected.delete(id);
            else selected.add(id);
            const card = document.getElementById(`sc-${id}`);
            card.classList.toggle('selected', selected.has(id));
            updateSelUI();
        }

        function selectAll() {
            allStudents.forEach(s => selected.add(s.id));
            renderGrid();
        }

        function clearSelection() {
            selected.clear();
            renderGrid();
        }

        function updateSelUI() {
            const n = selected.size;
            document.getElementById('selected-stat').textContent = n;
            const badge = document.getElementById('selected-count-badge');
            const btn = document.getElementById('preview-btn');
            if (n > 0) {
                badge.textContent = `${n} selected`;
                badge.style.display = 'inline-block';
                btn.disabled = false;
            } else {
                badge.style.display = 'none';
                btn.disabled = true;
            }
        }

        // ─── Settings modal ───────────────────────────────────────────────
        function openSettingsModal() {
            // Sync color picker ↔ text
            ['primary', 'secondary'].forEach(k => {
                const picker = document.getElementById(`s-${k}`);
                const txt = document.getElementById(`s-${k}-txt`);
                picker.oninput = () => txt.value = picker.value;
                txt.oninput = () => {
                    if (/^#[0-9a-fA-F]{6}$/.test(txt.value)) picker.value = txt.value;
                };
            });
            openModal('settings-modal');
        }

        async function saveSettings() {
            const body = {
                card_template: document.getElementById('s-template').value,
                primary_color: document.getElementById('s-primary').value,
                secondary_color: document.getElementById('s-secondary').value,
                show_motto: document.getElementById('s-motto').checked ? 1 : 0,
                show_qr: document.getElementById('s-qr').checked ? 1 : 0,
                card_back_text: document.getElementById('s-back-text').value
            };
            const res = await fetch(`${API_BASE}?action=save_settings`, {
                method: 'POST',
                body: JSON.stringify(body)
            });
            const data = await res.json();
            if (data.success) {
                Object.assign(CARD_SETTINGS, {
                    primary: body.primary_color,
                    secondary: body.secondary_color,
                    show_motto: !!body.show_motto,
                    show_qr: !!body.show_qr,
                    back_text: body.card_back_text,
                    template: body.card_template
                });
                showToast('Settings saved!', 'success');
                closeModal('settings-modal');
            } else {
                showToast('Save failed.', 'error');
            }
        }

        // ─── Preview modal ────────────────────────────────────────────────
        function openPreviewModal() {
            if (!selected.size) return;
            const students = allStudents.filter(s => selected.has(s.id));
            const grid = document.getElementById('cards-preview-grid');
            grid.innerHTML = '';

            students.forEach(s => {
                const wrapper = document.createElement('div');
                wrapper.className = 'id-card-wrapper';
                wrapper.innerHTML = `
            <div class="id-card-label">Front</div>
            ${buildFrontHTML(s)}
            <div class="id-card-label" style="margin-top:8px;">Back</div>
            ${buildBackHTML(s)}
        `;
                grid.appendChild(wrapper);
            });

            // Generate QR codes after DOM insert
            students.forEach(s => {
                // Use the pre-generated QR image saved by the system
                const qrSrc = `/ida/uploads/qrcodes/student_${s.id}.png`;
                const frontEl = document.getElementById(`qr-front-${s.id}`);
                const backEl = document.getElementById(`qr-back-${s.id}`);
                if (frontEl && CARD_SETTINGS.show_qr) {
                    frontEl.innerHTML = `<img src="${qrSrc}" onerror="this.style.opacity='.3'" alt="QR">`;
                }
                if (backEl) {
                    backEl.innerHTML = `<img src="${qrSrc}" onerror="this.style.opacity='.3'" alt="QR">`;
                }
            });

            openModal('preview-modal');
        }

        // (QR codes are loaded from pre-generated images at /ida/uploads/qrcodes/student_[id].png)

        // ─── Build card HTML ──────────────────────────────────────────────
        function buildFrontHTML(s) {
            const p = CARD_SETTINGS.primary;
            const sec = CARD_SETTINGS.secondary;
            const initials = s.full_name.split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase();
            const photoSrc = s.profile_picture ? s.profile_picture : null;
            const photoHTML = photoSrc ?
                `<img class="student-photo" src="${photoSrc}" alt="${esc(s.full_name)}" onerror="this.outerHTML='<div class=student-photo-ph style=background:linear-gradient(135deg,${p},${sec})>${initials}</div>'">` :
                `<div class="student-photo-ph" style="background:linear-gradient(135deg,${p},${sec})">${initials}</div>`;
            const logoHTML = SCHOOL.logo ?
                `<img class="school-logo-img" src="${esc(SCHOOL.logo)}" onerror="this.outerHTML='<div class=school-logo-placeholder>🎓</div>'">` :
                `<div class="school-logo-placeholder">🎓</div>`;
            const qrBlock = CARD_SETTINGS.show_qr ? `<div class="front-qr-wrap" id="qr-front-${s.id}"></div>` : '';
            const mottoEl = (CARD_SETTINGS.show_motto && SCHOOL.motto) ?
                `<div class="motto">${esc(SCHOOL.motto)}</div>` : '';
            const admDate = s.date_of_admission ? new Date(s.date_of_admission).getFullYear() : '';

            const wmText = SCHOOL.name.length > 20 ? SCHOOL.name.split(' ').slice(0, 3).join(' ') : SCHOOL.name;

            return `
    <div class="id-card id-front" style="--card-primary:${p};--card-secondary:${sec};">
        <div class="card-watermark"><span>${esc(wmText)}</span></div>
        <div class="card-header">
            ${logoHTML}
            <div class="school-info">
                <div class="sname">${esc(SCHOOL.name)}</div>
                ${mottoEl}
            </div>
            <div class="id-label-badge">Student ID</div>
        </div>
        <div class="card-body">
            <div class="student-photo-wrap">${photoHTML}</div>
            <div class="student-details">
                <div class="std-name">${esc(s.full_name)}</div>
                <div class="detail-row">
                    <span class="detail-lbl">Admission No.</span>
                    <span class="detail-val">${esc(s.admission_number)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-lbl">Class</span>
                    <span class="detail-val">${esc(s.class)}</span>
                </div>
                ${s.dob ? `<div class="detail-row"><span class="detail-lbl">Date of Birth</span><span class="detail-val">${formatDate(s.dob)}</span></div>` : ''}
                ${admDate ? `<div class="detail-row"><span class="detail-lbl">Year Admitted</span><span class="detail-val">${admDate}</span></div>` : ''}
            </div>
        </div>
        ${qrBlock}
        <div class="card-footer"></div>
    </div>`;
        }

        function buildBackHTML(s) {
            const p = CARD_SETTINGS.primary;
            const sec = CARD_SETTINGS.secondary;
            const wmText = SCHOOL.name.length > 20 ? SCHOOL.name.split(' ').slice(0, 3).join(' ') : SCHOOL.name;
            const logoHTML = SCHOOL.logo ?
                `<img class="back-logo" src="${esc(SCHOOL.logo)}" onerror="this.outerHTML='<div class=back-logo-ph style=background:linear-gradient(135deg,${p},${sec})>🎓</div>'">` :
                `<div class="back-logo-ph" style="background:linear-gradient(135deg,${p},${sec})">🎓</div>`;
            const contacts = (SCHOOL.email || SCHOOL.phone) ? `
        <div class="back-contacts">
            ${SCHOOL.phone ? `<div class="back-contact-row"><i class="fas fa-phone"></i>${esc(SCHOOL.phone)}</div>` : ''}
            ${SCHOOL.email ? `<div class="back-contact-row"><i class="fas fa-envelope"></i>${esc(SCHOOL.email)}</div>` : ''}
        </div>` : '';

            return `
    <div class="id-card id-back" style="--card-primary:${p};--card-secondary:${sec};">
        <div class="card-watermark"><span>${esc(wmText)}</span></div>
        <div class="back-header"></div>
        <div class="back-body">
            <div class="back-logo-wrap">
                ${logoHTML}
                <div id="qr-back-${s.id}" style="background:#fff;padding:2px;border-radius:4px;width:54px;height:54px;overflow:hidden;flex-shrink:0;"></div>
            </div>
            <div class="back-divider"></div>
            <div class="back-text-area">
                <div class="back-title">${esc(SCHOOL.name)}</div>
                <div class="back-body-text">${esc(CARD_SETTINGS.back_text)}</div>
                ${contacts}
            </div>
        </div>
        <div class="back-footer">
            <span class="back-footer-text">Adm. No: ${esc(s.admission_number)} &nbsp;|&nbsp; ${esc(s.class)}</span>
        </div>
    </div>`;
        }

        // ─── Print ────────────────────────────────────────────────────────
        function printCards() {
            const content = document.getElementById('cards-preview-grid').innerHTML;
            const printArea = document.getElementById('print-area');
            printArea.innerHTML = `<div class="id-card-page">${content}</div>`;
            window.print();
            printArea.innerHTML = '';
        }

        // ─── Download PDF ─────────────────────────────────────────────────
        async function downloadPDF() {
            const btn = document.getElementById('pdf-btn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Generating…';

            try {
                const {
                    jsPDF
                } = window.jspdf;
                const pdf = new jsPDF({
                    orientation: 'landscape',
                    unit: 'mm',
                    format: 'a4'
                });
                const pageW = 297,
                    pageH = 210,
                    margin = 10;
                // Each card: 86x54mm (standard CR80) — print 3 per page row
                const cW = 86,
                    cH = 54,
                    gap = 6;

                const cards = document.querySelectorAll('#cards-preview-grid .id-card');
                let col = 0,
                    row = 0,
                    page = 0;

                for (let i = 0; i < cards.length; i++) {
                    if (i > 0 && i % 6 === 0) {
                        pdf.addPage();
                        col = 0;
                        row = 0;
                    } else if (i > 0 && i % 3 === 0) {
                        row++;
                        col = 0;
                    }

                    const x = margin + col * (cW + gap);
                    const y = margin + row * (cH + gap);

                    const canvas = await html2canvas(cards[i], {
                        scale: 2,
                        useCORS: true,
                        allowTaint: true,
                        backgroundColor: '#fff'
                    });
                    const imgData = canvas.toDataURL('image/png');
                    pdf.addImage(imgData, 'PNG', x, y, cW, cH);
                    col++;
                }

                pdf.save(`ID_Cards_${SCHOOL.name.replace(/\s+/g,'_')}_${new Date().toISOString().slice(0,10)}.pdf`);

                // Log to server
                const studentIds = allStudents.filter(s => selected.has(s.id)).map(s => s.id);
                await fetch(`${API_BASE}?action=log_generation`, {
                    method: 'POST',
                    body: JSON.stringify({
                        student_ids: studentIds
                    })
                });

                showToast('PDF downloaded successfully!', 'success');
            } catch (e) {
                console.error(e);
                showToast('PDF generation failed. Try print instead.', 'error');
            }

            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-file-pdf"></i> Download PDF';
        }

        // ─── Modal helpers ────────────────────────────────────────────────
        function openModal(id) {
            document.getElementById(id).classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        // ─── Toast ────────────────────────────────────────────────────────
        function showToast(msg, type = '') {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.className = `toast ${type}`;
            t.classList.add('show');
            setTimeout(() => t.classList.remove('show'), 3200);
        }

        // ─── Utilities ────────────────────────────────────────────────────
        function esc(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function formatDate(d) {
            if (!d) return '';
            const dt = new Date(d);
            return dt.toLocaleDateString('en-GB', {
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            });
        }

        // ─── Init ─────────────────────────────────────────────────────────
        loadStudents();
    </script>
</body>

</html>
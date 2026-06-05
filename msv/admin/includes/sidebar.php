<?php
// admin/includes/sidebar.php - Reusable sidebar component with Notification Bell & Push Notifications
// Fully dynamic school theme integration – reads constants from config.php

// Make sure required variables are available
if (!isset($school_name) || !isset($admin_name) || !isset($admin_role)) {
    $school_name = SCHOOL_NAME ?? 'School';
    $admin_name = $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);

// Subscription status check
if (!isset($subscription_active)) {
    $subscription_active = false;
    $subscription_end_date = '';
    $subscription_days_remaining = 0;

    try {
        global $pdo;
        if (isset($pdo) && defined('SCHOOL_ID')) {
            $stmt = $pdo->prepare("SELECT subscription_status, subscription_expiry FROM schools WHERE id = ?");
            $stmt->execute([SCHOOL_ID]);
            $school_sub = $stmt->fetch();

            if ($school_sub) {
                $subscription_active = ($school_sub['subscription_status'] === 'active');
                $subscription_end_date = $school_sub['subscription_expiry'];

                if ($subscription_end_date && $subscription_end_date !== '0000-00-00') {
                    $expiry_timestamp = strtotime($subscription_end_date);
                    $current_timestamp = time();
                    $subscription_days_remaining = ceil(($expiry_timestamp - $current_timestamp) / (60 * 60 * 24));
                    $subscription_days_remaining = max(0, $subscription_days_remaining);

                    if ($expiry_timestamp < $current_timestamp) {
                        $subscription_active = false;
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Sidebar subscription check error: " . $e->getMessage());
    }
}

// Determine which nav group is active based on current page
$portal_pages = ['manage-students.php', 'manage-staff.php', 'manage-classes.php', 'manage-subjects.php', 'manage_attendance.php'];
$exam_pages   = ['manage-exams.php', 'view-results.php', 'exam_record_setup.php', 'exam_generate_cards.php'];
$resources_pages = ['ai-tools.php', 'reports.php', 'sync.php'];
$bills_pages  = ['billing.php', 'invoices.php', 'payments.php', 'finance_dashboard.php', 'finance_bill_types.php', 'finance_bills.php', 'finance_payments.php', 'finance_receipts.php', 'finance_invoices.php', 'finance_income_expenditure.php', 'finance_ledger.php', 'finance_reports.php'];

$portal_active    = in_array($current_page, $portal_pages);
$exam_active      = in_array($current_page, $exam_pages);
$resources_active = in_array($current_page, $resources_pages);
$bills_active     = in_array($current_page, $bills_pages);

// ── School Theme Resolution ──────────────────────────────────────────────────
$sb_primary   = defined('SCHOOL_PRIMARY')   ? SCHOOL_PRIMARY   : '#1e293b';
$sb_secondary = defined('SCHOOL_SECONDARY') ? SCHOOL_SECONDARY : '#3b82f6';
$sb_accent    = defined('SCHOOL_ACCENT')    ? SCHOOL_ACCENT    : '#ffffff';

// Helper: convert hex to RGB string "r,g,b"
function sbHexToRgb(string $hex): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return "$r,$g,$b";
}

// Helper: adjust color brightness
function sbAdjustBrightness(string $hex, int $percent): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    $factor = 1 + ($percent / 100);
    $r = (int)min(255, max(0, $r * $factor));
    $g = (int)min(255, max(0, $g * $factor));
    $b = (int)min(255, max(0, $b * $factor));

    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

// Helper: get contrast color
function sbGetContrastColor(string $hex): string
{
    $rgb = sbHexToRgb($hex);
    list($r, $g, $b) = explode(',', $rgb);
    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b);
    return ($luminance > 128) ? '#1e293b' : '#ffffff';
}

// ── Derive sidebar styling ──────────────────────────────────────────────
$sb_bg          = sbAdjustBrightness($sb_primary, -12);
$sb_surface     = sbAdjustBrightness($sb_primary, -5);
$sb_hover_bg    = "rgba(" . sbHexToRgb($sb_accent) . ", 0.08)";
$sb_active_bg   = "rgba(" . sbHexToRgb($sb_secondary) . ", 0.18)";
$sb_border      = "rgba(" . sbHexToRgb($sb_accent) . ", 0.10)";

$text_primary   = sbGetContrastColor($sb_primary);
$text_muted     = (sbGetContrastColor($sb_primary) === '#ffffff')
    ? "rgba(255,255,255,0.65)"
    : "rgba(0,0,0,0.65)";
$text_bright    = (sbGetContrastColor($sb_primary) === '#ffffff')
    ? "rgba(255,255,255,0.95)"
    : "rgba(0,0,0,0.95)";

$logo_gradient  = "linear-gradient(135deg, $sb_secondary, $sb_primary)";

// Get VAPID public key from database for push notifications
$vapid_public_key = '';
try {
    $stmt = $pdo->prepare("SELECT vapid_public_key FROM attendance_settings WHERE school_id = ?");
    $stmt->execute([SCHOOL_ID]);
    $vapid_public_key = $stmt->fetchColumn();
} catch (Exception $e) {
    error_log("Failed to get VAPID key: " . $e->getMessage());
}

// Check if we should output just the sidebar (not the whole layout)
$standalone_sidebar = isset($standalone_sidebar) ? $standalone_sidebar : false;
?>

<?php if (!$standalone_sidebar): ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <?php endif; ?>

    <style>
        /* ============================================================
       GLOBAL LAYOUT STYLES - FIXED
    ============================================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', 'Segoe UI', system-ui, sans-serif;
            background: #f5f7fb;
            overflow-x: hidden;
        }

        /* Theme CSS variables */
        :root {
            --sb-primary: <?php echo $sb_primary; ?>;
            --sb-secondary: <?php echo $sb_secondary; ?>;
            --sb-accent: <?php echo $sb_accent; ?>;
            --sb-bg: <?php echo $sb_bg; ?>;
            --sb-surface: <?php echo $sb_surface; ?>;
            --sb-border: <?php echo $sb_border; ?>;
            --sb-text: <?php echo $text_muted; ?>;
            --sb-text-bright: <?php echo $text_bright; ?>;
            --sb-accent-clr: <?php echo $sb_secondary; ?>;
            --sb-accent-glow: rgba(<?php echo sbHexToRgb($sb_secondary); ?>, 0.18);
            --sb-hover: <?php echo $sb_hover_bg; ?>;
            --sb-active-bg: <?php echo $sb_active_bg; ?>;
            --sb-logo-grad: <?php echo $logo_gradient; ?>;
            --sb-radius: 10px;
            --sb-width: 280px;
            --sb-transition: 0.22s ease;
            --header-height: 70px;

            /* Notification colors */
            --danger: #ef4444;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
        }

        /* ---------- Sidebar (always visible on desktop) ---------- */
        .sidebar {
            width: var(--sb-width);
            height: 100vh;
            background: var(--sb-bg);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            left: 0;
            overflow-y: auto;
            overflow-x: hidden;
            z-index: 1000;
            border-right: 1px solid var(--sb-border);
            scrollbar-width: thin;
            scrollbar-color: var(--sb-surface) transparent;
            font-family: 'Poppins', 'Segoe UI', system-ui, sans-serif;
            transform: translateX(0);
            transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar::-webkit-scrollbar {
            width: 4px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: transparent;
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: var(--sb-surface);
            border-radius: 4px;
        }

        /* ---------- Main Content Wrapper (shifted for sidebar) ---------- */
        .main-content-wrapper {
            margin-left: var(--sb-width);
            min-height: 100vh;
            transition: margin-left 0.28s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* ---------- Top Header Bar (inside main content) ---------- */
        .top-header {
            position: sticky;
            top: 0;
            right: 0;
            left: 0;
            height: var(--header-height);
            background: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
            z-index: 99;
            border-bottom: 1px solid #e5e7eb;
        }

        .header-title h2 {
            font-size: 1.3rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }

        .header-title p {
            font-size: 0.75rem;
            color: #6b7280;
            margin: 0;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        /* Notification Bell Styles */
        .notification-bell {
            position: relative;
            cursor: pointer;
        }

        .notification-bell i {
            font-size: 1.3rem;
            color: #4b5563;
        }

        .notification-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #ef4444;
            color: white;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 20px;
            min-width: 18px;
            text-align: center;
            display: none;
        }

        .notification-dropdown {
            position: absolute;
            top: 40px;
            right: 0;
            width: 340px;
            max-width: calc(100vw - 20px);
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            z-index: 1100;
            display: none;
            max-height: 450px;
            overflow-y: auto;
        }

        .notification-dropdown.show {
            display: block;
        }

        .notification-header {
            padding: 12px 16px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
        }

        .notification-item {
            padding: 12px 16px;
            border-bottom: 1px solid #f3f4f6;
            cursor: pointer;
            transition: background 0.2s;
        }

        .notification-item:hover {
            background: #f9fafb;
        }

        .notification-item.unread {
            background: rgba(59, 130, 246, 0.05);
            border-left: 3px solid var(--sb-primary);
        }

        .notification-title {
            font-weight: 600;
            font-size: 0.8rem;
            margin-bottom: 4px;
        }

        .notification-body {
            font-size: 0.7rem;
            color: #6b7280;
        }

        .notification-time {
            font-size: 0.6rem;
            color: #9ca3af;
            margin-top: 4px;
        }

        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
            border: none;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.65rem;
            cursor: pointer;
            font-family: inherit;
        }

        .btn-secondary:hover {
            background: #d1d5db;
        }

        .admin-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 10px;
            transition: background 0.2s;
        }

        .admin-profile:hover {
            background: #f3f4f6;
        }

        .admin-avatar {
            width: 40px;
            height: 40px;
            background: var(--sb-primary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
        }

        .admin-info-header {
            display: none;
        }

        @media (min-width: 768px) {
            .admin-info-header {
                display: block;
            }

            .admin-info-header .name {
                font-size: 0.85rem;
                font-weight: 600;
                color: #1f2937;
            }

            .admin-info-header .role {
                font-size: 0.7rem;
                color: #6b7280;
            }
        }

        /* ---------- Sidebar Internal Styles ---------- */
        .sidebar-header {
            padding: 24px 20px 20px;
            border-bottom: 1px solid var(--sb-border);
            flex-shrink: 0;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .logo-icon {
            width: 52px;
            height: 52px;
            flex-shrink: 0;
            border-radius: 12px;
            background: var(--sb-logo-grad);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            box-shadow: 0 4px 12px var(--sb-accent-glow);
        }

        .logo-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .logo-icon i {
            font-size: 26px;
            color: #fff;
        }

        .logo-text h3.school-name {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--sb-text-bright);
            line-height: 1.3;
            white-space: normal;
            word-break: break-word;
            margin: 0 0 4px;
        }

        .logo-text p {
            font-size: 0.8rem;
            color: var(--sb-text);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin: 0;
        }

        .admin-info {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 18px 20px;
            border-bottom: 1px solid var(--sb-border);
            flex-shrink: 0;
        }

        .admin-avatar-sidebar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: var(--sb-logo-grad);
            color: #fff;
            font-size: 1.1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .admin-details h4 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--sb-text-bright);
            margin: 0 0 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 170px;
        }

        .admin-details p {
            font-size: 0.8rem;
            color: var(--sb-text);
            margin: 0;
            text-transform: capitalize;
        }

        .subscription-status {
            margin: 16px 16px;
            padding: 12px 16px;
            border-radius: var(--sb-radius);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-shrink: 0;
        }

        .subscription-status.active {
            background: rgba(16, 185, 129, 0.12);
            border-left: 3px solid #10b981;
        }

        .subscription-status.warning {
            background: rgba(245, 158, 11, 0.12);
            border-left: 3px solid #f59e0b;
        }

        .subscription-status.danger {
            background: rgba(239, 68, 68, 0.12);
            border-left: 3px solid #ef4444;
        }

        .sub-left {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: var(--sb-text);
            flex-shrink: 0;
        }

        .sub-left i {
            font-size: 0.9rem;
        }

        .sub-right {
            text-align: right;
        }

        .days-badge {
            display: block;
            font-size: 0.95rem;
            font-weight: 700;
            line-height: 1.3;
        }

        .subscription-status.active .days-badge {
            color: #10b981;
        }

        .subscription-status.warning .days-badge {
            color: #f59e0b;
        }

        .subscription-status.danger .days-badge,
        .days-badge.expired {
            color: #ef4444;
        }

        .expiry-text {
            display: block;
            font-size: 0.72rem;
            color: var(--sb-text);
            margin-top: 2px;
        }

        .sidebar-nav {
            flex: 1;
            padding: 12px 0 24px;
            display: flex;
            flex-direction: column;
        }

        .nav-item.standalone {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 20px;
            color: var(--sb-text);
            text-decoration: none;
            font-size: 1rem;
            font-weight: 500;
            transition: background var(--sb-transition), color var(--sb-transition);
            border-radius: 0;
            position: relative;
        }

        .nav-item.standalone:hover {
            background: var(--sb-hover);
            color: var(--sb-text-bright);
        }

        .nav-item.standalone.active {
            background: var(--sb-active-bg);
            color: var(--sb-accent-clr);
        }

        .nav-item.standalone.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 6px;
            bottom: 6px;
            width: 3px;
            background: var(--sb-accent-clr);
            border-radius: 0 3px 3px 0;
        }

        .nav-item.standalone.logout {
            margin-top: auto;
            border-top: 1px solid var(--sb-border);
        }

        .nav-item.standalone.logout:hover {
            color: #ef4444;
        }

        .nav-group {
            flex-shrink: 0;
        }

        .nav-group-toggle {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 20px;
            background: none;
            border: none;
            cursor: pointer;
            color: var(--sb-text);
            font-size: 1rem;
            font-weight: 500;
            text-align: left;
            transition: background var(--sb-transition), color var(--sb-transition);
            position: relative;
        }

        .nav-group-toggle:hover {
            background: var(--sb-hover);
            color: var(--sb-text-bright);
        }

        .nav-group.open>.nav-group-toggle {
            color: var(--sb-text-bright);
            background: var(--sb-hover);
        }

        .nav-label {
            flex: 1;
        }

        .group-badge {
            display: flex;
            align-items: center;
            flex-shrink: 0;
        }

        .chevron {
            font-size: 0.75rem;
            color: var(--sb-text);
            transition: transform 0.25s ease;
        }

        .nav-group.open .chevron {
            transform: rotate(180deg);
        }

        .nav-icon {
            width: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            flex-shrink: 0;
        }

        .nav-group-items {
            list-style: none;
            margin: 0;
            padding: 0;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            background: rgba(0, 0, 0, 0.15);
        }

        .nav-group-items.expanded {
            max-height: 500px;
        }

        .nav-group-items li a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 20px 10px 52px;
            color: var(--sb-text);
            text-decoration: none;
            font-size: 0.95rem;
            transition: background var(--sb-transition), color var(--sb-transition);
            position: relative;
        }

        .nav-group-items li a i {
            font-size: 0.9rem;
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }

        .nav-group-items li a:hover {
            background: var(--sb-hover);
            color: var(--sb-text-bright);
        }

        .nav-group-items li a.active {
            color: var(--sb-accent-clr);
            background: var(--sb-active-bg);
        }

        .nav-group-items li a.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 4px;
            bottom: 4px;
            width: 3px;
            background: var(--sb-accent-clr);
            border-radius: 0 3px 3px 0;
        }

        .nav-group.open .nav-group-items {
            border-left: 1px solid var(--sb-border);
            margin-left: 32px;
        }

        .nav-group.open .nav-group-items li a {
            padding-left: 24px;
        }

        .nav-group.open .nav-group-items li a.active::before {
            left: -1px;
        }

        .push-settings-section {
            margin-top: auto;
            padding: 16px;
            border-top: 1px solid var(--sb-border);
            margin-top: 20px;
        }

        .btn-push {
            width: 100%;
            padding: 8px;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            font-size: 0.7rem;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-push-primary {
            background: var(--sb-secondary);
            color: white;
        }

        .btn-push-warning {
            background: #ef4444;
            color: white;
        }

        /* Mobile Menu Button */
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            width: 44px;
            height: 44px;
            background: var(--sb-primary);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 20px;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        /* Sidebar Overlay */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.55);
            z-index: 999;
            backdrop-filter: blur(2px);
        }

        .sidebar-overlay.active {
            display: block;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content-wrapper {
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .top-header {
                padding: 0 15px;
            }

            .header-title h2 {
                font-size: 1.1rem;
            }

            .header-title p {
                display: none;
            }

            .notification-dropdown {
                width: 300px;
                right: -10px;
            }

            .admin-info-header {
                display: none;
            }
        }
    </style>

    <?php if (!$standalone_sidebar): ?>
    </head>

    <body>
    <?php endif; ?>

    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <!-- Header -->
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon">
                    <?php
                    $logo_path = null;
                    $logo_locations = [
                        '/msv/assets/logos/logo.png',
                        '/assets/logos/logo.png',
                        '../assets/logos/logo.png',
                        'assets/logos/logo.png'
                    ];
                    if (defined('SCHOOL_LOGO') && SCHOOL_LOGO && file_exists($_SERVER['DOCUMENT_ROOT'] . SCHOOL_LOGO)) {
                        $logo_path = SCHOOL_LOGO;
                    } else {
                        foreach ($logo_locations as $location) {
                            if (file_exists($_SERVER['DOCUMENT_ROOT'] . $location)) {
                                $logo_path = $location;
                                break;
                            }
                        }
                    }
                    if ($logo_path && file_exists($_SERVER['DOCUMENT_ROOT'] . $logo_path)): ?>
                        <img src="<?php echo $logo_path; ?>" alt="<?php echo htmlspecialchars($school_name); ?>">
                    <?php else: ?>
                        <i class="fas fa-graduation-cap"></i>
                    <?php endif; ?>
                </div>
                <div class="logo-text">
                    <h3 class="school-name"><?php echo htmlspecialchars($school_name); ?></h3>
                    <p>Admin Panel</p>
                </div>
            </div>
        </div>

        <!-- Admin Info -->
        <div class="admin-info">
            <div class="admin-avatar-sidebar">
                <?php echo strtoupper(substr($admin_name, 0, 1)); ?>
            </div>
            <div class="admin-details">
                <h4><?php echo htmlspecialchars($admin_name); ?></h4>
                <p><?php echo ucfirst(str_replace('_', ' ', $admin_role)); ?></p>
            </div>
        </div>

        <!-- Subscription Status -->
        <?php
        $status_class = '';
        $display_days = $subscription_days_remaining ?? 0;
        if (!$subscription_active || $display_days <= 0) {
            $status_class = 'danger';
            $display_days = 0;
        } elseif ($display_days <= 14) {
            $status_class = 'danger';
        } elseif ($display_days <= 30) {
            $status_class = 'warning';
        } else {
            $status_class = 'active';
        }
        ?>
        <div class="subscription-status <?php echo $status_class; ?>">
            <div class="sub-left">
                <i class="fas fa-calendar-alt"></i>
                <span class="sub-label">Subscription</span>
            </div>
            <div class="sub-right">
                <?php if ($display_days > 0): ?>
                    <span class="days-badge"><?php echo $display_days; ?> days</span>
                <?php else: ?>
                    <span class="days-badge expired">EXPIRED</span>
                <?php endif; ?>
                <?php if (isset($subscription_end_date) && $subscription_end_date && $subscription_end_date !== '0000-00-00'): ?>
                    <span class="expiry-text">Expires <?php echo date('M j, Y', strtotime($subscription_end_date)); ?></span>
                <?php else: ?>
                    <span class="expiry-text">No expiry set</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="sidebar-nav">
            <!-- Dashboard -->
            <a href="index.php" class="nav-item standalone <?php echo $current_page == 'index.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                <span class="nav-label">Dashboard</span>
            </a>

            <!-- Portal Group -->
            <div class="nav-group <?php echo $portal_active ? 'open' : ''; ?>" data-group="portal">
                <button class="nav-group-toggle" aria-expanded="<?php echo $portal_active ? 'true' : 'false'; ?>">
                    <span class="nav-icon"><i class="fas fa-school"></i></span>
                    <span class="nav-label">Portal</span>
                    <span class="group-badge">
                        <i class="fas fa-chevron-down chevron"></i>
                    </span>
                </button>
                <ul class="nav-group-items <?php echo $portal_active ? 'expanded' : ''; ?>">
                    <li><a href="manage-students.php" class="<?php echo $current_page == 'manage-students.php' ? 'active' : ''; ?>"><i class="fas fa-user-graduate"></i> Students</a></li>
                    <li><a href="manage-staff.php" class="<?php echo $current_page == 'manage-staff.php' ? 'active' : ''; ?>"><i class="fas fa-chalkboard-teacher"></i> Staff</a></li>
                    <li><a href="manage-classes.php" class="<?php echo $current_page == 'manage-classes.php' ? 'active' : ''; ?>"><i class="fas fa-layer-group"></i> Classes</a></li>
                    <li><a href="manage-subjects.php" class="<?php echo $current_page == 'manage-subjects.php' ? 'active' : ''; ?>"><i class="fas fa-book"></i> Subjects</a></li>
                    <li><a href="manage_attendance.php" class="<?php echo $current_page == 'manage_attendance.php' ? 'active' : ''; ?>"><i class="fas fa-calendar-check"></i> Attendance</a></li>
                </ul>
            </div>

            <!-- Exams & Results Group -->
            <div class="nav-group <?php echo $exam_active ? 'open' : ''; ?>" data-group="exams">
                <button class="nav-group-toggle" aria-expanded="<?php echo $exam_active ? 'true' : 'false'; ?>">
                    <span class="nav-icon"><i class="fas fa-file-alt"></i></span>
                    <span class="nav-label">Exams & Results</span>
                    <span class="group-badge">
                        <i class="fas fa-chevron-down chevron"></i>
                    </span>
                </button>
                <ul class="nav-group-items <?php echo $exam_active ? 'expanded' : ''; ?>">
                    <li><a href="manage-exams.php" class="<?php echo $current_page == 'manage-exams.php' ? 'active' : ''; ?>"><i class="fas fa-pen-alt"></i> Manage Exams</a></li>
                    <li><a href="view-results.php" class="<?php echo $current_page == 'view-results.php' ? 'active' : ''; ?>"><i class="fas fa-chart-bar"></i> View Results</a></li>
                    <li><a href="exam_record_setup.php" class="<?php echo $current_page == 'exam_record_setup.php' ? 'active' : ''; ?>"><i class="fas fa-file-invoice"></i> Process Results</a></li>
                </ul>
            </div>

            <!-- Resources Group -->
            <div class="nav-group <?php echo $resources_active ? 'open' : ''; ?>" data-group="resources">
                <button class="nav-group-toggle" aria-expanded="<?php echo $resources_active ? 'true' : 'false'; ?>">
                    <span class="nav-icon"><i class="fas fa-cubes"></i></span>
                    <span class="nav-label">Resources</span>
                    <span class="group-badge">
                        <i class="fas fa-chevron-down chevron"></i>
                    </span>
                </button>
                <ul class="nav-group-items <?php echo $resources_active ? 'expanded' : ''; ?>">
                    <li><a href="ai-tools.php" class="<?php echo $current_page == 'ai-tools.php' ? 'active' : ''; ?>"><i class="fas fa-robot"></i> AI Teaching Tools</a></li>
                    <li><a href="reports.php" class="<?php echo $current_page == 'reports.php' ? 'active' : ''; ?>"><i class="fas fa-chart-line"></i> Reports</a></li>
                    <li><a href="sync.php" class="<?php echo $current_page == 'sync.php' ? 'active' : ''; ?>"><i class="fas fa-sync-alt"></i> Sync to Cloud</a></li>
                </ul>
            </div>

            <!-- Finance Group -->
            <div class="nav-group <?php echo $bills_active ? 'open' : ''; ?>" data-group="bills">
                <button class="nav-group-toggle" aria-expanded="<?php echo $bills_active ? 'true' : 'false'; ?>">
                    <span class="nav-icon"><i class="fas fa-receipt"></i></span>
                    <span class="nav-label">Finance</span>
                    <span class="group-badge">
                        <i class="fas fa-chevron-down chevron"></i>
                    </span>
                </button>
                <ul class="nav-group-items <?php echo $bills_active ? 'expanded' : ''; ?>">
                    <li><a href="finance_dashboard.php" class="<?php echo $current_page == 'finance_dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-chart-pie"></i> Dashboard</a></li>
                    <li><a href="finance_bill_types.php" class="<?php echo $current_page == 'finance_bill_types.php' ? 'active' : ''; ?>"><i class="fas fa-tags"></i> Bill Types</a></li>
                    <li><a href="finance_bills.php" class="<?php echo $current_page == 'finance_bills.php' ? 'active' : ''; ?>"><i class="fas fa-file-invoice"></i> Manage Bills</a></li>
                    <li><a href="finance_payments.php" class="<?php echo $current_page == 'finance_payments.php' ? 'active' : ''; ?>"><i class="fas fa-money-bill-wave"></i> Payments</a></li>
                    <li><a href="finance_receipts.php" class="<?php echo $current_page == 'finance_receipts.php' ? 'active' : ''; ?>"><i class="fas fa-print"></i> Receipts</a></li>
                    <li><a href="finance_invoices.php" class="<?php echo $current_page == 'finance_invoices.php' ? 'active' : ''; ?>"><i class="fas fa-file-alt"></i> Invoices</a></li>
                    <li><a href="finance_income_expenditure.php" class="<?php echo $current_page == 'finance_income_expenditure.php' ? 'active' : ''; ?>"><i class="fas fa-exchange-alt"></i> Income/Expenditure</a></li>
                    <li><a href="finance_ledger.php" class="<?php echo $current_page == 'finance_ledger.php' ? 'active' : ''; ?>"><i class="fas fa-book"></i> General Ledger</a></li>
                    <li><a href="finance_reports.php" class="<?php echo $current_page == 'finance_reports.php' ? 'active' : ''; ?>"><i class="fas fa-chart-line"></i> Reports</a></li>
                </ul>
            </div>

            <!-- Push Notifications Settings -->
            <div class="push-settings-section">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                    <span style="font-size: 0.75rem; opacity: 0.7;">
                        <i class="fas fa-bell"></i> Push Notifications
                    </span>
                    <span id="pushStatus"></span>
                </div>
                <button id="pushToggleBtn" class="btn-push btn-push-primary">
                    <i class="fas fa-bell"></i> Enable Notifications
                </button>
                <p style="font-size: 0.6rem; opacity: 0.5; margin-top: 8px; text-align: center;">
                    Get real-time alerts when students/staff check in/out
                </p>
            </div>

            <!-- Logout -->
            <a href="/msv/logout.php" class="nav-item standalone logout">
                <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                <span class="nav-label">Logout</span>
            </a>
        </nav>
    </div>

    <!-- Main Content Wrapper (to be used on each page) -->
    <div class="main-content-wrapper" id="mainContentWrapper">
        <!-- Top Header Bar (inside main content) -->
        <div class="top-header">
            <div class="header-title">
                <h2><?php echo htmlspecialchars($page_title ?? 'Dashboard'); ?></h2>
                <p><?php echo date('l, F j, Y'); ?></p>
            </div>
            <div class="header-actions">
                <div class="notification-bell" id="notificationBell">
                    <i class="fas fa-bell"></i>
                    <span class="notification-badge" id="notificationCount">0</span>
                    <div class="notification-dropdown" id="notificationDropdown">
                        <div class="notification-header">
                            <strong><i class="fas fa-bell"></i> Notifications</strong>
                            <button class="btn-secondary" id="markAllReadBtn">Mark all read</button>
                        </div>
                        <div id="notificationList">
                            <div style="padding: 20px; text-align:center; color: #9ca3af;">Loading...</div>
                        </div>
                    </div>
                </div>
                <div class="admin-profile" id="adminProfile">
                    <div class="admin-avatar">
                        <?php echo strtoupper(substr($admin_name, 0, 1)); ?>
                    </div>
                    <div class="admin-info-header">
                        <div class="name"><?php echo htmlspecialchars($admin_name); ?></div>
                        <div class="role"><?php echo ucfirst(str_replace('_', ' ', $admin_role)); ?></div>
                    </div>
                    <i class="fas fa-chevron-down" style="font-size: 12px; color: #9ca3af;"></i>
                </div>
            </div>
        </div>

        <script>
            // ============================================================
            // SIDEBAR ACCORDION & MOBILE
            // ============================================================

            (function() {
                'use strict';

                // Accordion groups
                function initGroups() {
                    document.querySelectorAll('.nav-group').forEach(function(group) {
                        var toggle = group.querySelector('.nav-group-toggle');
                        var items = group.querySelector('.nav-group-items');

                        if (!toggle || !items) return;

                        toggle.addEventListener('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            var isOpen = group.classList.contains('open');

                            document.querySelectorAll('.nav-group.open').forEach(function(g) {
                                if (g !== group) {
                                    g.classList.remove('open');
                                    var gToggle = g.querySelector('.nav-group-toggle');
                                    var gItems = g.querySelector('.nav-group-items');
                                    if (gToggle) gToggle.setAttribute('aria-expanded', 'false');
                                    if (gItems) gItems.classList.remove('expanded');
                                }
                            });

                            if (isOpen) {
                                group.classList.remove('open');
                                toggle.setAttribute('aria-expanded', 'false');
                                items.classList.remove('expanded');
                            } else {
                                group.classList.add('open');
                                toggle.setAttribute('aria-expanded', 'true');
                                items.classList.add('expanded');
                            }
                        });
                    });
                }

                // Mobile sidebar
                function initMobileSidebar() {
                    var toggle = document.getElementById('mobileMenuBtn');
                    var sidebar = document.getElementById('sidebar');
                    var overlay = document.getElementById('sidebarOverlay');
                    var body = document.body;

                    if (!sidebar || !overlay) return;

                    function open() {
                        sidebar.classList.add('active');
                        overlay.classList.add('active');
                        body.style.overflow = 'hidden';
                    }

                    function close() {
                        sidebar.classList.remove('active');
                        overlay.classList.remove('active');
                        body.style.overflow = '';
                    }

                    if (toggle) {
                        toggle.addEventListener('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            sidebar.classList.contains('active') ? close() : open();
                        });
                    }

                    overlay.addEventListener('click', close);

                    document.querySelectorAll('.nav-item.standalone, .nav-group-items a').forEach(function(link) {
                        link.addEventListener('click', function() {
                            if (window.innerWidth <= 767) setTimeout(close, 150);
                        });
                    });

                    document.addEventListener('keydown', function(e) {
                        if (e.key === 'Escape') close();
                    });

                    var resizeTimer;
                    window.addEventListener('resize', function() {
                        clearTimeout(resizeTimer);
                        resizeTimer = setTimeout(function() {
                            if (window.innerWidth >= 768) close();
                        }, 250);
                    });
                }

                // ============================================================
                // NOTIFICATION FUNCTIONS
                // ============================================================

                function escapeHtml(text) {
                    if (!text) return '';
                    const div = document.createElement('div');
                    div.textContent = text;
                    return div.innerHTML;
                }

                function loadNotifications() {
                    fetch('/msv/admin/attendance_api.php?action=get_notifications', {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                const list = document.getElementById('notificationList');
                                if (!data.notifications || data.notifications.length === 0) {
                                    list.innerHTML = '<div style="padding: 20px; text-align:center; color: #9ca3af;"><i class="fas fa-bell-slash"></i> No notifications</div>';
                                } else {
                                    list.innerHTML = data.notifications.map(n => `
                        <div class="notification-item ${n.is_read ? '' : 'unread'}" data-id="${n.id}">
                            <div class="notification-title">${escapeHtml(n.title)}</div>
                            <div class="notification-body">${escapeHtml(n.body)}</div>
                            <div class="notification-time">${n.time_ago}</div>
                        </div>
                    `).join('');

                                    document.querySelectorAll('.notification-item').forEach(item => {
                                        item.addEventListener('click', function(e) {
                                            e.stopPropagation();
                                            const id = this.dataset.id;
                                            if (id) markNotificationRead(id);
                                        });
                                    });
                                }
                            }
                        })
                        .catch(err => console.error('Error loading notifications:', err));
                }

                function loadUnreadCount() {
                    fetch('/msv/admin/attendance_api.php?action=get_unread_count', {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                const badge = document.getElementById('notificationCount');
                                if (badge) {
                                    badge.textContent = data.count;
                                    badge.style.display = data.count > 0 ? 'inline-block' : 'none';
                                }
                            }
                        })
                        .catch(err => console.error('Error loading unread count:', err));
                }

                function markNotificationRead(id) {
                    const formData = new URLSearchParams();
                    formData.append('action', 'mark_read');
                    formData.append('notification_id', id);

                    fetch('/msv/admin/attendance_api.php', {
                            method: 'POST',
                            body: formData,
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        })
                        .then(() => {
                            loadNotifications();
                            loadUnreadCount();
                        })
                        .catch(err => console.error('Error marking notification read:', err));
                }

                function markAllNotificationsRead() {
                    const formData = new URLSearchParams();
                    formData.append('action', 'mark_all_read');

                    fetch('/msv/admin/attendance_api.php', {
                            method: 'POST',
                            body: formData,
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        })
                        .then(() => {
                            loadNotifications();
                            loadUnreadCount();
                        })
                        .catch(err => console.error('Error marking all notifications read:', err));
                }

                function initNotificationBell() {
                    const bell = document.getElementById('notificationBell');
                    const dropdown = document.getElementById('notificationDropdown');

                    if (!bell || !dropdown) return;

                    bell.addEventListener('click', function(e) {
                        e.stopPropagation();
                        dropdown.classList.toggle('show');
                        if (dropdown.classList.contains('show')) {
                            loadNotifications();
                        }
                    });

                    document.addEventListener('click', function() {
                        dropdown.classList.remove('show');
                    });

                    dropdown.addEventListener('click', function(e) {
                        e.stopPropagation();
                    });

                    const markAllBtn = document.getElementById('markAllReadBtn');
                    if (markAllBtn) {
                        markAllBtn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            markAllNotificationsRead();
                        });
                    }

                    loadUnreadCount();
                    setInterval(loadUnreadCount, 30000);
                }

                // ============================================================
                // PUSH NOTIFICATIONS
                // ============================================================

                let isSubscribed = false;
                let swRegistration = null;

                const VAPID_PUBLIC_KEY = '<?php echo $vapid_public_key; ?>';

                function isPushSupported() {
                    return 'serviceWorker' in navigator && 'PushManager' in window;
                }

                function urlBase64ToUint8Array(base64String) {
                    const padding = '='.repeat((4 - base64String.length % 4) % 4);
                    const base64 = (base64String + padding)
                        .replace(/\-/g, '+')
                        .replace(/_/g, '/');

                    const rawData = window.atob(base64);
                    const outputArray = new Uint8Array(rawData.length);

                    for (let i = 0; i < rawData.length; ++i) {
                        outputArray[i] = rawData.charCodeAt(i);
                    }
                    return outputArray;
                }

                async function saveSubscription(subscription) {
                    const response = await fetch('/msv/admin/attendance_api.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: `action=save_push_subscription&subscription=${encodeURIComponent(JSON.stringify(subscription))}`
                    });

                    const data = await response.json();
                    return data.success;
                }

                async function removeSubscription(subscription) {
                    await fetch('/msv/admin/attendance_api.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: `action=remove_push_subscription&endpoint=${encodeURIComponent(subscription.endpoint)}`
                    });
                }

                async function askPermission() {
                    const result = await Notification.requestPermission();

                    if (result === 'granted') {
                        console.log('Notification permission granted');
                        await subscribeUser();
                    } else {
                        console.log('Notification permission denied');
                        showPushPrompt('Notifications are blocked. You can enable them in browser settings.');
                    }
                }

                async function subscribeUser() {
                    if (!swRegistration) {
                        console.log('Service worker not ready');
                        return;
                    }

                    try {
                        const subscription = await swRegistration.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
                        });

                        console.log('User subscribed:', subscription);
                        const saved = await saveSubscription(subscription);

                        if (saved) {
                            isSubscribed = true;
                            updatePushUI(true);
                        }
                    } catch (err) {
                        console.error('Failed to subscribe:', err);
                        showPushPrompt('Could not subscribe to notifications. Please try again.');
                    }
                }

                async function unsubscribeUser() {
                    if (!swRegistration) return;

                    try {
                        const subscription = await swRegistration.pushManager.getSubscription();
                        if (subscription) {
                            await subscription.unsubscribe();
                            await removeSubscription(subscription);
                            console.log('User unsubscribed');
                            isSubscribed = false;
                            updatePushUI(false);
                        }
                    } catch (err) {
                        console.error('Failed to unsubscribe:', err);
                    }
                }

                async function checkSubscription() {
                    if (!swRegistration) return;

                    const subscription = await swRegistration.pushManager.getSubscription();
                    isSubscribed = subscription !== null;
                    updatePushUI(isSubscribed);
                }

                function updatePushUI(subscribed) {
                    const toggleBtn = document.getElementById('pushToggleBtn');
                    const statusSpan = document.getElementById('pushStatus');

                    if (toggleBtn) {
                        toggleBtn.innerHTML = subscribed ?
                            '<i class="fas fa-bell-slash"></i> Disable Notifications' :
                            '<i class="fas fa-bell"></i> Enable Notifications';
                        toggleBtn.className = subscribed ? 'btn-push btn-push-warning' : 'btn-push btn-push-primary';
                    }

                    if (statusSpan) {
                        statusSpan.innerHTML = subscribed ?
                            '<span style="color: #10b981;"><i class="fas fa-check-circle"></i> Enabled</span>' :
                            '<span style="color: #6b7280;"><i class="fas fa-bell-slash"></i> Disabled</span>';
                    }
                }

                function showPushPrompt(message) {
                    const promptDiv = document.createElement('div');
                    promptDiv.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            left: 20px;
            max-width: 400px;
            margin: 0 auto;
            background: #1f2937;
            color: white;
            padding: 16px;
            border-radius: 12px;
            z-index: 10000;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            font-size: 14px;
        `;
                    promptDiv.innerHTML = `
            <span>${message}</span>
            <button style="background: none; border: none; color: white; cursor: pointer; font-size: 20px;">&times;</button>
        `;

                    const closeBtn = promptDiv.querySelector('button');
                    closeBtn.onclick = () => promptDiv.remove();

                    document.body.appendChild(promptDiv);
                    setTimeout(() => promptDiv.remove(), 5000);
                }

                async function initPushNotifications() {
                    if (!isPushSupported()) {
                        console.log('Push notifications not supported');
                        return;
                    }

                    if (!VAPID_PUBLIC_KEY || VAPID_PUBLIC_KEY === '') {
                        console.log('VAPID public key not configured');
                        return;
                    }

                    try {
                        swRegistration = await navigator.serviceWorker.register('/msv/sw.js');
                        console.log('Service Worker registered');
                        await checkSubscription();
                    } catch (err) {
                        console.error('Service Worker registration failed:', err);
                    }
                }

                // Admin profile click
                function initAdminProfile() {
                    const adminProfile = document.getElementById('adminProfile');
                    if (adminProfile) {
                        adminProfile.addEventListener('click', function() {
                            window.location.href = 'profile.php';
                        });
                    }
                }

                // ============================================================
                // INITIALIZE
                // ============================================================

                function init() {
                    initGroups();
                    initMobileSidebar();
                    initNotificationBell();
                    initPushNotifications();
                    initAdminProfile();
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', init);
                } else {
                    init();
                }
            })();
        </script>

        <?php if (!$standalone_sidebar): ?>
    </body>

    </html>
<?php endif; ?>
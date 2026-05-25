<?php
// gos/admin/includes/sidebar.php - Reusable sidebar component
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
$portal_pages = ['manage-students.php', 'manage-staff.php', 'manage-classes.php', 'manage-subjects.php', 'attendance.php'];
$exam_pages   = ['manage-exams.php', 'view-results.php', 'exam_record_setup.php'];
$resources_pages = ['ai-tools.php', 'reports.php', 'sync.php'];
$bills_pages  = ['billing.php', 'invoices.php', 'payments.php'];

$portal_active    = in_array($current_page, $portal_pages);
$exam_active      = in_array($current_page, $exam_pages);
$resources_active = in_array($current_page, $resources_pages);
$bills_active     = in_array($current_page, $bills_pages);

// ── School Theme Resolution ──────────────────────────────────────────────────
// Read school constants (defined in config.php). Falls back to modern defaults.
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

// Helper: adjust color brightness (positive = lighter, negative = darker)
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

// Helper: get contrast color (black or white) for a given background
function sbGetContrastColor(string $hex): string
{
    $rgb = sbHexToRgb($hex);
    list($r, $g, $b) = explode(',', $rgb);
    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b);
    return ($luminance > 128) ? '#1e293b' : '#ffffff';
}

// ── Derive sidebar styling from school palette ──────────────────────────────
$sb_bg          = sbAdjustBrightness($sb_primary, -12);        // slightly darker than primary
$sb_surface     = sbAdjustBrightness($sb_primary, -5);         // subtle elevation
$sb_hover_bg    = "rgba(" . sbHexToRgb($sb_accent) . ", 0.08)";
$sb_active_bg   = "rgba(" . sbHexToRgb($sb_secondary) . ", 0.18)";
$sb_border      = "rgba(" . sbHexToRgb($sb_accent) . ", 0.10)";

// Text colors based on contrast with primary background
$text_primary   = sbGetContrastColor($sb_primary);
$text_muted     = (sbGetContrastColor($sb_primary) === '#ffffff')
    ? "rgba(255,255,255,0.65)"
    : "rgba(0,0,0,0.65)";
$text_bright    = (sbGetContrastColor($sb_primary) === '#ffffff')
    ? "rgba(255,255,255,0.95)"
    : "rgba(0,0,0,0.95)";

// Logo gradient uses secondary as highlight
$logo_gradient  = "linear-gradient(135deg, $sb_secondary, $sb_primary)";
?>

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
                    '/gos/assets/logos/logo.png',
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
        <div class="admin-avatar">
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

        <!-- Dashboard (standalone) -->
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
                <li>
                    <a href="manage-students.php" class="<?php echo $current_page == 'manage-students.php' ? 'active' : ''; ?>">
                        <i class="fas fa-user-graduate"></i> Students
                    </a>
                </li>
                <li>
                    <a href="manage-staff.php" class="<?php echo $current_page == 'manage-staff.php' ? 'active' : ''; ?>">
                        <i class="fas fa-chalkboard-teacher"></i> Staff
                    </a>
                </li>
                <li>
                    <a href="manage-classes.php" class="<?php echo $current_page == 'manage-classes.php' ? 'active' : ''; ?>">
                        <i class="fas fa-layer-group"></i> Classes
                    </a>
                </li>
                <li>
                    <a href="manage-subjects.php" class="<?php echo $current_page == 'manage-subjects.php' ? 'active' : ''; ?>">
                        <i class="fas fa-book"></i> Subjects
                    </a>
                </li>
                <li>
                    <a href="attendance.php" class="<?php echo $current_page == 'attendance.php' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-check"></i> Attendance
                    </a>
                </li>
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
                <li>
                    <a href="manage-exams.php" class="<?php echo $current_page == 'manage-exams.php' ? 'active' : ''; ?>">
                        <i class="fas fa-pen-alt"></i> Manage Exams
                    </a>
                </li>
                <li>
                    <a href="view-results.php" class="<?php echo $current_page == 'view-results.php' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-bar"></i> View Results
                    </a>
                </li>
                <li>
                    <a href="exam_record_setup.php" class="<?php echo $current_page == 'exam_record_setup.php' ? 'active' : ''; ?>">
                        <i class="fas fa-file-invoice"></i> Process Results
                    </a>
                </li>
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
                <li>
                    <a href="ai-tools.php" class="<?php echo $current_page == 'ai-tools.php' ? 'active' : ''; ?>">
                        <i class="fas fa-robot"></i> AI Teaching Tools
                    </a>
                </li>
                <li>
                    <a href="reports.php" class="<?php echo $current_page == 'reports.php' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-line"></i> Reports
                    </a>
                </li>
                <li>
                    <a href="sync.php" class="<?php echo $current_page == 'sync.php' ? 'active' : ''; ?>">
                        <i class="fas fa-sync-alt"></i> Sync to Cloud
                    </a>
                </li>
            </ul>
        </div>

        <!-- Bills Group (Full Finance Module) -->
        <div class="nav-group <?php echo $bills_active ? 'open' : ''; ?>" data-group="bills">
            <button class="nav-group-toggle" aria-expanded="<?php echo $bills_active ? 'true' : 'false'; ?>">
                <span class="nav-icon"><i class="fas fa-receipt"></i></span>
                <span class="nav-label">Finance</span>
                <span class="group-badge">
                    <i class="fas fa-chevron-down chevron"></i>
                </span>
            </button>
            <ul class="nav-group-items <?php echo $bills_active ? 'expanded' : ''; ?>">
                <li>
                    <a href="finance_dashboard.php" class="<?php echo $current_page == 'finance_dashboard.php' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-pie"></i> Dashboard
                    </a>
                </li>
                <li>
                    <a href="finance_bill_types.php" class="<?php echo $current_page == 'finance_bill_types.php' ? 'active' : ''; ?>">
                        <i class="fas fa-tags"></i> Bill Types / Templates
                    </a>
                </li>
                <li>
                    <a href="finance_bills.php" class="<?php echo $current_page == 'finance_bills.php' ? 'active' : ''; ?>">
                        <i class="fas fa-file-invoice"></i> Manage Bills
                    </a>
                </li>
                <li>
                    <a href="finance_payments.php" class="<?php echo $current_page == 'finance_payments.php' ? 'active' : ''; ?>">
                        <i class="fas fa-money-bill-wave"></i> Record Payments
                    </a>
                </li>
                <li>
                    <a href="finance_receipts.php" class="<?php echo $current_page == 'finance_receipts.php' ? 'active' : ''; ?>">
                        <i class="fas fa-print"></i> Receipts
                    </a>
                </li>
                <li>
                    <a href="finance_invoices.php" class="<?php echo $current_page == 'finance_invoices.php' ? 'active' : ''; ?>">
                        <i class="fas fa-file-alt"></i> Invoices
                    </a>
                </li>
                <li>
                    <a href="finance_income_expenditure.php" class="<?php echo $current_page == 'finance_income_expenditure.php' ? 'active' : ''; ?>">
                        <i class="fas fa-exchange-alt"></i> Income/Expenditure
                    </a>
                </li>
                <li>
                    <a href="finance_ledger.php" class="<?php echo $current_page == 'finance_ledger.php' ? 'active' : ''; ?>">
                        <i class="fas fa-book"></i> General Ledger
                    </a>
                </li>
                <li>
                    <a href="finance_reports.php" class="<?php echo $current_page == 'finance_reports.php' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-line"></i> Financial Reports
                    </a>
                </li>
            </ul>
        </div>

        <!-- Logout -->
        <a href="/gos/logout.php" class="nav-item standalone logout">
            <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
            <span class="nav-label">Logout</span>
        </a>

    </nav>
</div>

<style>
    /* ============================================================
   SIDEBAR — Dynamic School Theme Integration
   Fully respects SCHOOL_PRIMARY, SCHOOL_SECONDARY, SCHOOL_ACCENT
   ============================================================ */

    /* Theme CSS variables — injected from config.php constants */
    :root {
        --sb-primary: <?php echo $sb_primary; ?>;
        --sb-secondary: <?php echo $sb_secondary; ?>;
        --sb-accent: <?php echo $sb_accent; ?>;

        /* Derived tokens */
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
        --sb-width: 260px;
        --sb-transition: 0.22s ease;
    }

    /* ---------- Base ---------- */
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
        font-family: 'Segoe UI', system-ui, sans-serif;
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

    /* ---------- Header ---------- */
    .sidebar-header {
        padding: 20px 18px 16px;
        border-bottom: 1px solid var(--sb-border);
        flex-shrink: 0;
    }

    .logo {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .logo-icon {
        width: 44px;
        height: 44px;
        flex-shrink: 0;
        border-radius: 10px;
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
        font-size: 22px;
        color: #fff;
    }

    .logo-text h3.school-name {
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--sb-text-bright);
        line-height: 1.25;
        white-space: normal;
        word-break: break-word;
        margin: 0 0 2px;
    }

    .logo-text p {
        font-size: 0.68rem;
        color: var(--sb-text);
        text-transform: uppercase;
        letter-spacing: 0.06em;
        margin: 0;
    }

    /* ---------- Admin Info ---------- */
    .admin-info {
        display: flex;
        align-items: center;
        gap: 11px;
        padding: 14px 18px;
        border-bottom: 1px solid var(--sb-border);
        flex-shrink: 0;
    }

    .admin-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: var(--sb-logo-grad);
        color: #fff;
        font-size: 0.85rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .admin-details h4 {
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--sb-text-bright);
        margin: 0 0 2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 160px;
    }

    .admin-details p {
        font-size: 0.68rem;
        color: var(--sb-text);
        margin: 0;
        text-transform: capitalize;
    }

    /* ---------- Subscription ---------- */
    .subscription-status {
        margin: 12px 14px;
        padding: 10px 13px;
        border-radius: var(--sb-radius);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
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
        gap: 7px;
        font-size: 0.72rem;
        color: var(--sb-text);
        flex-shrink: 0;
    }

    .sub-left i {
        font-size: 0.75rem;
    }

    .sub-right {
        text-align: right;
    }

    .days-badge {
        display: block;
        font-size: 0.78rem;
        font-weight: 700;
        line-height: 1.2;
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
        font-size: 0.62rem;
        color: var(--sb-text);
        margin-top: 1px;
    }

    /* ---------- Nav ---------- */
    .sidebar-nav {
        flex: 1;
        padding: 8px 0 20px;
        display: flex;
        flex-direction: column;
    }

    /* Standalone items (Dashboard, Logout) */
    .nav-item.standalone {
        display: flex;
        align-items: center;
        gap: 11px;
        padding: 10px 18px;
        color: var(--sb-text);
        text-decoration: none;
        font-size: 0.84rem;
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
        top: 4px;
        bottom: 4px;
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

    /* Nav group wrapper */
    .nav-group {
        flex-shrink: 0;
    }

    /* Group toggle button */
    .nav-group-toggle {
        width: 100%;
        display: flex;
        align-items: center;
        gap: 11px;
        padding: 10px 18px;
        background: none;
        border: none;
        cursor: pointer;
        color: var(--sb-text);
        font-size: 0.84rem;
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
        font-size: 0.65rem;
        color: var(--sb-text);
        transition: transform 0.25s ease;
    }

    .nav-group.open .chevron {
        transform: rotate(180deg);
    }

    /* Coming soon badge */
    .coming-soon-badge {
        font-size: 0.58rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #f59e0b;
        background: rgba(245, 158, 11, 0.15);
        border: 1px solid rgba(245, 158, 11, 0.3);
        padding: 2px 6px;
        border-radius: 20px;
    }

    /* Icon wrapper */
    .nav-icon {
        width: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.85rem;
        flex-shrink: 0;
    }

    /* Group item list */
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
        max-height: 400px;
    }

    .nav-group-items li a {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 18px 8px 46px;
        color: var(--sb-text);
        text-decoration: none;
        font-size: 0.8rem;
        transition: background var(--sb-transition), color var(--sb-transition);
        position: relative;
    }

    .nav-group-items li a i {
        font-size: 0.75rem;
        width: 16px;
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
        top: 3px;
        bottom: 3px;
        width: 3px;
        background: var(--sb-accent-clr);
        border-radius: 0 3px 3px 0;
    }

    /* Vertical connector line for group items */
    .nav-group.open .nav-group-items {
        border-left: 1px solid var(--sb-border);
        margin-left: 28px;
    }

    .nav-group.open .nav-group-items li a {
        padding-left: 20px;
    }

    .nav-group.open .nav-group-items li a.active::before {
        left: -1px;
    }

    /* ---------- Overlay ---------- */
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

    /* ---------- Mobile ---------- */
    @media (max-width: 767px) {
        .sidebar {
            transform: translateX(-100%);
            transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1001;
        }

        .sidebar.active {
            transform: translateX(0);
            box-shadow: 8px 0 32px rgba(0, 0, 0, 0.5);
        }
    }
</style>

<script>
    (function() {
        'use strict';

        /* ── Accordion groups ── */
        function initGroups() {
            document.querySelectorAll('.nav-group').forEach(function(group) {
                var toggle = group.querySelector('.nav-group-toggle');
                var items = group.querySelector('.nav-group-items');

                if (!toggle || !items) return;

                toggle.addEventListener('click', function() {
                    var isOpen = group.classList.contains('open');

                    // Close all sibling groups (accordion behaviour)
                    document.querySelectorAll('.nav-group.open').forEach(function(g) {
                        if (g !== group) {
                            g.classList.remove('open');
                            g.querySelector('.nav-group-toggle').setAttribute('aria-expanded', 'false');
                            g.querySelector('.nav-group-items').classList.remove('expanded');
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

        /* ── Mobile sidebar ── */
        function initMobileSidebar() {
            var toggle = document.getElementById('mobileMenuToggle');
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

            if (toggle) toggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                sidebar.classList.contains('active') ? close() : open();
            });

            overlay.addEventListener('click', close);

            document.querySelectorAll('.nav-links a, .nav-item.standalone, .nav-group-items a').forEach(function(a) {
                a.addEventListener('click', function() {
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

        function init() {
            initGroups();
            initMobileSidebar();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }
    })();
</script>
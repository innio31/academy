<?php
// includes/theme-css.php - Outputs CSS based on theme variables
// Include this in the <head> section of any page

if (!defined('SCHOOL_PRIMARY')) {
    require_once __DIR__ . '/theme.php';
}
?>
<style>
    :root {
        --primary: <?php echo SCHOOL_PRIMARY; ?>;
        --secondary: <?php echo SCHOOL_SECONDARY; ?>;
        --accent: <?php echo SCHOOL_ACCENT; ?>;
    }

    /* Global styles using theme colors */
    body {
        background: #f5f5f5;
    }

    .btn-primary {
        background: var(--primary);
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .btn-primary:hover {
        background: var(--secondary);
        transform: translateY(-2px);
    }

    .btn-outline {
        background: transparent;
        border: 2px solid var(--primary);
        color: var(--primary);
        padding: 10px 20px;
        border-radius: 8px;
        cursor: pointer;
    }

    .btn-outline:hover {
        background: var(--primary);
        color: white;
    }

    .school-header {
        background: var(--primary);
        color: var(--accent);
        padding: 20px;
        border-radius: 10px;
        margin-bottom: 20px;
    }

    .school-logo {
        width: 50px;
        height: 50px;
        background: var(--accent);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .sidebar {
        background: var(--primary);
    }

    .nav-link:hover {
        background: var(--secondary);
    }

    .stat-card {
        border-top-color: var(--primary);
    }

    .alert-success {
        border-left-color: var(--secondary);
    }

    a {
        color: var(--primary);
    }

    a:hover {
        color: var(--secondary);
    }

    /* Add more reusable classes as needed */
</style>
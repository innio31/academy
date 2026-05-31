<?php
// tbis/admin/includes/page-template.php - Base template for admin pages

function renderAdminPage($title, $content, $additionalStyles = '', $additionalScripts = '')
{
    // Access global variables
    global $school_name, $primary_color, $secondary_color, $admin_name, $admin_role;
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <title><?php echo htmlspecialchars($school_name ?? 'School'); ?> — <?php echo htmlspecialchars($title); ?></title>

        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

        <style>
            :root {
                --primary-color: <?php echo $primary_color ?? '#2c3e50'; ?>;
                --secondary-color: <?php echo $secondary_color ?? '#3498db'; ?>;
                --accent-color: #e74c3c;
                --success-color: #27ae60;
                --warning-color: #f39c12;
                --danger-color: #e74c3c;
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

            .mobile-menu-toggle {
                position: fixed;
                top: 15px;
                left: 15px;
                z-index: 1001;
                width: 44px;
                height: 44px;
                background: var(--primary-color);
                color: white;
                border: none;
                border-radius: var(--radius-md);
                font-size: 20px;
                cursor: pointer;
            }

            .sidebar-overlay {
                position: fixed;
                inset: 0;
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

            .top-header h1 {
                color: var(--primary-color);
                font-size: 1.5rem;
                margin-bottom: 4px;
            }

            .top-header p {
                color: #666;
                font-size: 0.85rem;
            }

            @media (min-width: 768px) {

                .mobile-menu-toggle,
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
            }

            <?php echo $additionalStyles; ?>
        </style>

        <?php echo $additionalScripts; ?>
    </head>

    <body>
        <?php echo $content; ?>
    </body>

    </html>
<?php
}
?>
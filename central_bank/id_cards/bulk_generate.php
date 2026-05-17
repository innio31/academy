<?php
// /central_bank/id_cards/bulk_generate.php

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once __DIR__ . '/includes/id_card_functions.php';
require_once __DIR__ . '/templates/card_front.php';
require_once __DIR__ . '/templates/card_back.php';
require_once __DIR__ . '/templates/card_style.php';

require_super_admin();

$school_id = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
$class = isset($_GET['class']) ? $_GET['class'] : '';

if (!$school_id || !$class) {
    header("Location: index.php?error=missing_params");
    exit();
}

// Get all students in the class
$students = getStudentsByClass($school_id, $class);
if (empty($students)) {
    header("Location: index.php?error=no_students");
    exit();
}

// Get school details (from first student or fetch directly)
$school = getStudentIDCardData($students[0]['id'], $school_id);
if (!$school) {
    die("School not found");
}

$settings = getIDCardSettings($school_id);

// Log generation for each student
foreach ($students as $student) {
    logIDCardGeneration($school_id, $student['id'], $_SESSION['central_admin_id']);
}

// Generate all cards
$card_styles = getCardStyles();
ob_start();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk ID Cards - <?php echo htmlspecialchars($class); ?> - <?php echo htmlspecialchars($school['school_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php echo $card_styles; ?>
</head>

<body>
    <div class="no-print" style="position: fixed; top: 20px; right: 20px; display: flex; gap: 10px; z-index: 1000;">
        <button onclick="window.print()" class="btn btn-primary" style="background: #667eea; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer;">
            <i class="fas fa-print"></i> Print All (<?php echo count($students); ?> cards)
        </button>
        <a href="index.php?school_id=<?php echo $school_id; ?>&class=<?php echo urlencode($class); ?>" class="btn btn-secondary" style="background: #6c757d; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none;">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>

    <div class="id-card-container">
        <?php foreach ($students as $index => $student): ?>
            <?php
            // Get full student data
            $full_student = getStudentIDCardData($student['id'], $school_id);
            if (!$full_student) continue;

            // Generate QR code
            $qr_code_url = generateStudentQRCode($student['id'], $student['admission_number'], $school_id, $school['school_code']);

            $front_html = renderCardFront($full_student, $school, $settings, $qr_code_url);
            $back_html = renderCardBack($full_student, $school, $settings);
            ?>
            <div class="id-card">
                <div class="card-side">
                    <?php echo $front_html; ?>
                </div>
                <div class="card-side" style="margin-top: 4mm;">
                    <?php echo $back_html; ?>
                </div>
            </div>
            <?php if ($index < count($students) - 1): ?>
                <div style="page-break-after: always;"></div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="no-print" style="position: fixed; bottom: 20px; left: 20px; background: white; padding: 10px 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
        <p style="margin: 0; font-size: 12px;">
            <i class="fas fa-info-circle"></i>
            Generating <?php echo count($students); ?> ID cards for <?php echo htmlspecialchars($class); ?> class
        </p>
    </div>
</body>

</html>

<?php
$html = ob_get_clean();
echo $html;
?>
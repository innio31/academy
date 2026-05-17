<?php
// /central_bank/id_cards/generate.php - Generate single ID card

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once 'includes/id_card_functions.php';
require_once 'templates/card_front.php';
require_once 'templates/card_back.php';
require_once 'templates/card_style.php';

require_super_admin();

$school_id = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

if (!$school_id || !$student_id) {
    header("Location: index.php?error=missing_params");
    exit();
}

// Get student data
$student = getStudentIDCardData($student_id, $school_id);
if (!$student) {
    header("Location: index.php?error=student_not_found");
    exit();
}

// Get school settings
$settings = getIDCardSettings($school_id);

// Generate QR code
$qr_code_url = generateStudentQRCode($student_id, $student['admission_number'], $school_id, $student['school_code']);

// Log generation
logIDCardGeneration($school_id, $student_id, $_SESSION['central_admin_id']);

// Get card HTML
$front_html = renderCardFront($student, $student, $settings, $qr_code_url);
$back_html = renderCardBack($student, $student, $settings);

$card_styles = getCardStyles();

// Check if download requested
$download = isset($_GET['download']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ID Card - <?php echo htmlspecialchars($student['full_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php echo $card_styles; ?>
</head>

<body>
    <div class="no-print" style="position: fixed; top: 20px; right: 20px; display: flex; gap: 10px; z-index: 1000;">
        <button onclick="window.print()" class="btn btn-primary" style="background: #667eea; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer;">
            <i class="fas fa-print"></i> Print / Download PDF
        </button>
        <a href="index.php" class="btn btn-secondary" style="background: #6c757d; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none;">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>

    <div class="id-card-container">
        <div class="id-card">
            <div class="card-side">
                <?php echo $front_html; ?>
            </div>
            <div class="card-side" style="margin-top: 4mm;">
                <?php echo $back_html; ?>
            </div>
        </div>
    </div>

    <div class="no-print" style="position: fixed; bottom: 20px; left: 20px; background: white; padding: 10px 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
        <p style="margin: 0; font-size: 12px;">
            <i class="fas fa-info-circle"></i>
            To save as PDF: Click Print → Save as PDF → Adjust scale to 100%
        </p>
    </div>
</body>

</html>
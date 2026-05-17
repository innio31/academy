<?php
// /central_bank/id_cards/preview.php

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once __DIR__ . '/includes/id_card_functions.php';
require_once __DIR__ . '/templates/card_front.php';
require_once __DIR__ . '/templates/card_back.php';
require_once __DIR__ . '/templates/card_style.php';

require_super_admin();

$school_id = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

if (!$school_id || !$student_id) {
    header("Location: index.php");
    exit();
}

$student = getStudentIDCardData($student_id, $school_id);
if (!$student) {
    die("Student not found");
}

$settings = getIDCardSettings($school_id);
$qr_code_url = generateStudentQRCode($student_id, $student['admission_number'], $school_id, $student['school_code']);

$front_html = renderCardFront($student, $student, $settings, $qr_code_url);
$back_html = renderCardBack($student, $student, $settings);
$card_styles = getCardStyles();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview ID Card - <?php echo htmlspecialchars($student['full_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php echo $card_styles; ?>
    <style>
        .preview-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: #e0e0e0;
            padding: 20px;
        }

        .preview-actions {
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
        }

        .card-preview {
            transform: scale(1.5);
            transform-origin: center;
            margin: 40px;
        }

        @media print {
            .preview-actions {
                display: none;
            }

            .card-preview {
                transform: scale(1);
                margin: 0;
            }

            .preview-container {
                background: white;
                padding: 0;
            }
        }
    </style>
</head>

<body>
    <div class="preview-container">
        <div class="preview-actions">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fas fa-print"></i> Print Card
            </button>
            <a href="generate.php?school_id=<?php echo $school_id; ?>&student_id=<?php echo $student_id; ?>" class="btn btn-primary">
                <i class="fas fa-download"></i> Download PDF
            </a>
            <a href="index.php?school_id=<?php echo $school_id; ?>&class=<?php echo urlencode($student['class']); ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>

        <div class="card-preview">
            <div class="id-card">
                <div class="card-side">
                    <?php echo $front_html; ?>
                </div>
                <div class="card-side" style="margin-top: 4mm;">
                    <?php echo $back_html; ?>
                </div>
            </div>
        </div>

        <div class="preview-actions" style="margin-top: 40px;">
            <p style="color: #666; font-size: 12px;">
                <i class="fas fa-info-circle"></i>
                The card above is scaled for preview. When printed, it will be actual ID card size (85.6mm × 54mm).
            </p>
        </div>
    </div>
</body>

</html>
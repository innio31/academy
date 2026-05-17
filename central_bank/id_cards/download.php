<?php
// /central_bank/id_cards/download.php - Download ID card as PDF

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once 'includes/id_card_functions.php';
require_once 'templates/card_front.php';
require_once 'templates/card_back.php';

require_super_admin();

$school_id = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

if (!$school_id || !$student_id) {
    die("Invalid request");
}

$student = getStudentIDCardData($student_id, $school_id);
if (!$student) {
    die("Student not found");
}

$settings = getIDCardSettings($school_id);
$qr_code_url = generateStudentQRCode($student_id, $student['admission_number'], $school_id, $student['school_code']);

$front_html = renderCardFront($student, $student, $settings, $qr_code_url);
$back_html = renderCardBack($student, $student, $settings);

// HTML for PDF
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            background: white;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .id-card {
            width: 85.6mm;
            margin: 0 auto;
        }
        .card-side {
            margin-bottom: 4mm;
        }
        @page {
            size: 85.6mm 108mm;
            margin: 0mm;
        }
    </style>
</head>
<body>
    <div class="id-card">
        <div class="card-side">
            ' . $front_html . '
        </div>
        <div class="card-side">
            ' . $back_html . '
        </div>
    </div>
</body>
</html>
';

// Save to temporary file
$temp_file = __DIR__ . '/assets/temp/card_' . $student_id . '_' . time() . '.html';
file_put_contents($temp_file, $html);

// For now, just output HTML for browser print
// In production, use a PDF library like dompdf or mPDF
header('Content-Type: text/html');
header('Content-Disposition: inline; filename="id_card_' . $student['admission_number'] . '.html"');
echo $html;
exit();

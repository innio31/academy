<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

$student_id = $_GET['id'] ?? 0;

if (!$student_id) {
    die("Student ID required");
}

// Get student details
$stmt = $pdo->prepare("
    SELECT 
        s.*,
        u.email, u.first_name, u.last_name, u.phone,
        d.name as department_name, d.code as department_code,
        f.name as faculty_name
    FROM students s
    JOIN users u ON s.user_id = u.id
    JOIN departments d ON s.department_id = d.id
    JOIN faculties f ON d.faculty_id = f.id
    WHERE s.id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    die("Student not found");
}

// Ensure QR exists
$qr_path = __DIR__ . '/../assets/qrcodes/' . $student['reg_number'] . '.png';
$qr_relative = 'assets/qrcodes/' . $student['reg_number'] . '.png';

if (!file_exists($qr_path)) {
    // Generate QR code
    $qr_data = json_encode([
        'id' => $student['id'],
        'reg_no' => $student['reg_number'],
        'name' => $student['first_name'] . ' ' . $student['last_name']
    ]);
    
    // Try online API
    $qr_image = @file_get_contents("https://quickchart.io/qr?text=" . urlencode($qr_data) . "&size=200");
    if ($qr_image !== false) {
        file_put_contents($qr_path, $qr_image);
    } else {
        // Create fallback image
        $img = imagecreate(200, 200);
        $bg = imagecolorallocate($img, 255, 255, 255);
        $color = imagecolorallocate($img, 0, 0, 0);
        imagestring($img, 5, 30, 80, $student['reg_number'], $color);
        imagepng($img, $qr_path);
        imagedestroy($img);
    }
    
    // Update database
    $update = $pdo->prepare("UPDATE students SET qr_code = ? WHERE id = ?");
    $update->execute([$qr_relative, $student_id]);
}

// Mark ID card as issued
$update = $pdo->prepare("UPDATE students SET id_card_issued = 1 WHERE id = ?");
$update->execute([$student_id]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student ID Card - <?php echo $student['reg_number']; ?></title>
    <style>
        @media print {
            body {
                margin: 0;
                padding: 0;
                background: white;
            }
            .no-print {
                display: none;
            }
            .id-card {
                box-shadow: none;
                border: 1px solid #ddd;
                page-break-after: avoid;
                break-inside: avoid;
            }
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #e2e8f0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }
        
        .id-card {
            width: 350px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
            position: relative;
        }
        
        .card-header {
            background: linear-gradient(135deg, #1a365d 0%, #2b6cb0 100%);
            padding: 20px;
            text-align: center;
            border-bottom: 3px solid #ecc94b;
        }
        
        .institution-name {
            color: white;
            font-size: 20px;
            font-weight: bold;
            letter-spacing: 1px;
        }
        
        .institution-motto {
            color: #ecc94b;
            font-size: 10px;
            margin-top: 4px;
        }
        
        .card-type {
            background: #ecc94b;
            color: #1a365d;
            padding: 6px;
            text-align: center;
            font-weight: bold;
            font-size: 12px;
            letter-spacing: 2px;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .student-photo {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            margin: -50px auto 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            color: white;
            border: 4px solid white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .student-name {
            font-size: 18px;
            font-weight: bold;
            color: #2d3748;
            text-align: center;
            margin-bottom: 5px;
        }
        
        .student-reg {
            font-size: 12px;
            color: #718096;
            text-align: center;
            margin-bottom: 15px;
            font-family: monospace;
            letter-spacing: 1px;
        }
        
        .info-section {
            background: #f7fafc;
            border-radius: 8px;
            padding: 12px;
            margin: 15px 0;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .info-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .info-label {
            font-size: 11px;
            color: #718096;
            font-weight: 500;
        }
        
        .info-value {
            font-size: 11px;
            font-weight: 600;
            color: #2d3748;
        }
        
        .qr-section {
            text-align: center;
            margin: 15px 0;
            padding: 10px;
            background: white;
            border: 1px dashed #cbd5e0;
            border-radius: 8px;
        }
        
        .qr-section img {
            max-width: 120px;
            height: auto;
            margin: 5px auto;
        }
        
        .qr-label {
            font-size: 9px;
            color: #718096;
            margin-top: 5px;
        }
        
        .card-footer {
            background: #f7fafc;
            padding: 10px;
            text-align: center;
            font-size: 8px;
            color: #718096;
            border-top: 1px solid #e2e8f0;
        }
        
        .signature-line {
            display: flex;
            justify-content: space-between;
            margin-top: 15px;
            padding-top: 10px;
        }
        
        .signature {
            text-align: center;
            font-size: 9px;
        }
        
        .signature .line {
            width: 100px;
            border-top: 1px solid #cbd5e0;
            margin-top: 5px;
        }
        
        .print-btn {
            background: #48bb78;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            margin: 20px auto;
            display: block;
            transition: all 0.3s;
        }
        
        .print-btn:hover {
            background: #38a169;
            transform: scale(1.02);
        }
        
        .button-group {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
        }
        
        .close-btn {
            background: #718096;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
        }
        
        @media print {
            .button-group {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div>
        <div class="id-card">
            <div class="card-header">
                <div class="institution-name">🏫 HIGHER INSTITUTION</div>
                <div class="institution-motto">Excellence in Education</div>
            </div>
            <div class="card-type">STUDENT IDENTIFICATION CARD</div>
            
            <div class="card-body">
                <div class="student-photo">
                    👨‍🎓
                </div>
                
                <div class="student-name">
                    <?php echo strtoupper(htmlspecialchars($student['first_name'] . ' ' . $student['last_name'])); ?>
                </div>
                
                <div class="student-reg">
                    <?php echo $student['reg_number']; ?>
                </div>
                
                <div class="info-section">
                    <div class="info-row">
                        <span class="info-label">Faculty</span>
                        <span class="info-value"><?php echo htmlspecialchars($student['faculty_name']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Department</span>
                        <span class="info-value"><?php echo htmlspecialchars($student['department_name']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Level</span>
                        <span class="info-value"><?php echo $student['current_level']; ?> Level</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Blood Group</span>
                        <span class="info-value">-</span>
                    </div>
                </div>
                
                <div class="qr-section">
                    <?php if(file_exists(__DIR__ . '/../' . $qr_relative)): ?>
                        <img src="../<?php echo $qr_relative; ?>" alt="QR Code">
                    <?php else: ?>
                        <div style="width: 120px; height: 120px; background: #e2e8f0; display: inline-flex; align-items: center; justify-content: center;">
                            QR
                        </div>
                    <?php endif; ?>
                    <div class="qr-label">Scan for Attendance & Verification</div>
                </div>
                
                <div class="signature-line">
                    <div class="signature">
                        <div>Student's Signature</div>
                        <div class="line"></div>
                    </div>
                    <div class="signature">
                        <div>Registrar's Signature</div>
                        <div class="line"></div>
                    </div>
                </div>
            </div>
            
            <div class="card-footer">
                Valid for Academic Session • This card remains property of the institution
            </div>
        </div>
        
        <div class="button-group no-print">
            <button onclick="window.print()" class="print-btn">🖨️ Print ID Card</button>
            <button onclick="window.close()" class="close-btn">✖️ Close</button>
        </div>
    </div>
</body>
</html>
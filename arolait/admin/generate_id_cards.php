<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin']);

error_reporting(E_ALL);
ini_set('display_errors', 1);

$student_id = $_GET['student_id'] ?? 0;
$department_filter = $_GET['department'] ?? '';
$show_all = isset($_GET['show_all']) ? true : false;

// Get institution settings
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('institution_name', 'app_name', 'app_slogan', 'institution_address', 'institution_phone', 'institution_email')");
$stmt->execute();
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$institution_name = $settings['institution_name'] ?? 'Higher Institution of Learning';
$institution_address = $settings['institution_address'] ?? 'No. 1 University Road, City';
$institution_phone = $settings['institution_phone'] ?? '+234 800 000 0000';
$institution_email = $settings['institution_email'] ?? 'info@institution.edu.ng';
$slogan = $settings['app_slogan'] ?? 'Excellence in Education';

// Get departments for filter
$departments = $pdo->query("SELECT id, name, code FROM departments ORDER BY name")->fetchAll();

// Get students based on filter
if ($student_id) {
    $stmt = $pdo->prepare("
        SELECT 
            s.*,
            u.first_name, u.last_name, u.email, u.phone, u.profile_pic,
            d.name as department_name, d.code as department_code,
            f.name as faculty_name,
            a.name as current_session_name
        FROM students s
        JOIN users u ON s.user_id = u.id
        JOIN departments d ON s.department_id = d.id
        JOIN faculties f ON d.faculty_id = f.id
        LEFT JOIN academic_sessions a ON s.current_session_id = a.id
        WHERE s.id = ?
    ");
    $stmt->execute([$student_id]);
    $students = $stmt->fetchAll();
} elseif ($department_filter) {
    $stmt = $pdo->prepare("
        SELECT 
            s.*,
            u.first_name, u.last_name, u.email, u.phone, u.profile_pic,
            d.name as department_name, d.code as department_code,
            f.name as faculty_name,
            a.name as current_session_name
        FROM students s
        JOIN users u ON s.user_id = u.id
        JOIN departments d ON s.department_id = d.id
        JOIN faculties f ON d.faculty_id = f.id
        LEFT JOIN academic_sessions a ON s.current_session_id = a.id
        WHERE s.department_id = ?
        ORDER BY u.last_name
    ");
    $stmt->execute([$department_filter]);
    $students = $stmt->fetchAll();
} elseif ($show_all) {
    $stmt = $pdo->prepare("
        SELECT 
            s.*,
            u.first_name, u.last_name, u.email, u.phone, u.profile_pic,
            d.name as department_name, d.code as department_code,
            f.name as faculty_name,
            a.name as current_session_name
        FROM students s
        JOIN users u ON s.user_id = u.id
        JOIN departments d ON s.department_id = d.id
        JOIN faculties f ON d.faculty_id = f.id
        LEFT JOIN academic_sessions a ON s.current_session_id = a.id
        ORDER BY d.name, u.last_name
        LIMIT 200
    ");
    $stmt->execute();
    $students = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT 
            s.*,
            u.first_name, u.last_name, u.email, u.phone, u.profile_pic,
            d.name as department_name, d.code as department_code,
            f.name as faculty_name,
            a.name as current_session_name
        FROM students s
        JOIN users u ON s.user_id = u.id
        JOIN departments d ON s.department_id = d.id
        JOIN faculties f ON d.faculty_id = f.id
        LEFT JOIN academic_sessions a ON s.current_session_id = a.id
        WHERE s.id_card_issued = 0
        ORDER BY d.name, u.last_name
        LIMIT 200
    ");
    $stmt->execute();
    $students = $stmt->fetchAll();
}

// Function to generate QR code
function getStudentQRCode($reg_number, $student_id, $pdo) {
    $qr_dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/qrcodes/';
    if (!file_exists($qr_dir)) {
        mkdir($qr_dir, 0777, true);
    }
    
    $qr_filename = 'idcard_' . preg_replace('/[^a-zA-Z0-9]/', '_', $reg_number) . '.png';
    $qr_filepath = $qr_dir . $qr_filename;
    
    // Check if QR already exists
    if (file_exists($qr_filepath)) {
        return '/assets/qrcodes/' . $qr_filename;
    }
    
    $qr_data = urlencode(json_encode([
        'student_id' => $student_id,
        'reg_number' => $reg_number,
        'type' => 'student_id_card'
    ]));
    
    $qr_url = "https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=" . $qr_data . "&choe=UTF-8";
    $qr_image = @file_get_contents($qr_url);
    
    if ($qr_image !== false) {
        file_put_contents($qr_filepath, $qr_image);
        return '/assets/qrcodes/' . $qr_filename;
    }
    
    return null;
}

// Mark ID card as issued
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_issued'])) {
    $student_ids = $_POST['student_ids'] ?? [];
    $count = 0;
    
    foreach ($student_ids as $sid) {
        $stmt = $pdo->prepare("UPDATE students SET id_card_issued = 1 WHERE id = ?");
        if ($stmt->execute([$sid])) {
            $count++;
        }
    }
    
    $message = "$count ID card(s) marked as issued.";
    header("Location: generate_id_cards.php?message=" . urlencode($message) . "&show_all=" . ($show_all ? '1' : ''));
    exit();
}

// Handle download
if (isset($_GET['download_front']) || isset($_GET['download_back'])) {
    $did = isset($_GET['download_front']) ? $_GET['download_front'] : $_GET['download_back'];
    $is_front = isset($_GET['download_front']);
    
    $stmt = $pdo->prepare("
        SELECT s.*, u.first_name, u.last_name, u.email, u.phone, u.profile_pic,
               d.name as department_name, d.code as department_code,
               f.name as faculty_name
        FROM students s
        JOIN users u ON s.user_id = u.id
        JOIN departments d ON s.department_id = d.id
        JOIN faculties f ON d.faculty_id = f.id
        WHERE s.id = ?
    ");
    $stmt->execute([$did]);
    $student = $stmt->fetch();
    
    if ($student) {
        $qr_code = getStudentQRCode($student['reg_number'], $student['id'], $pdo);
        $profile_pic = !empty($student['profile_pic']) && file_exists($_SERVER['DOCUMENT_ROOT'] . $student['profile_pic']) 
            ? $student['profile_pic'] : null;
        
        header('Content-Type: text/html');
        if ($is_front) {
            header('Content-Disposition: attachment; filename="id_card_front_' . $student['reg_number'] . '.html"');
        } else {
            header('Content-Disposition: attachment; filename="id_card_back_' . $student['reg_number'] . '.html"');
        }
        
        echo generateCardHTML($student, $qr_code, $profile_pic, $institution_name, $slogan, $is_front);
        exit();
    }
}

function generateCardHTML($student, $qr_code, $profile_pic, $institution_name, $slogan, $is_front) {
    $full_name = htmlspecialchars($student['first_name'] . ' ' . $student['last_name']);
    $reg_number = htmlspecialchars($student['reg_number']);
    $dept_code = htmlspecialchars($student['department_code']);
    $faculty = htmlspecialchars($student['faculty_name']);
    $level = $student['current_level'];
    $email = htmlspecialchars($student['email']);
    $phone = htmlspecialchars($student['phone'] ?? 'Not provided');
    $guardian = htmlspecialchars($student['guardian_name'] ?? 'Not provided');
    $guardian_phone = htmlspecialchars($student['guardian_phone'] ?? 'Not provided');
    $blood = htmlspecialchars($student['blood_group'] ?? 'Not specified');
    
    if ($is_front) {
        $photo_html = '';
        if ($profile_pic) {
            $photo_html = '<img src="' . $profile_pic . '" alt="Student Photo">';
        } else {
            $photo_html = '<div class="photo-placeholder">📸</div>';
        }
        
        $qr_html = '';
        if ($qr_code) {
            $qr_html = '<div class="qr-code"><img src="' . $qr_code . '" alt="QR Code"></div>';
        } else {
            $qr_html = '<div class="qr-code" style="background:#333;"></div>';
        }
        
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>ID Card Front - ' . $reg_number . '</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: #e0e0e0;
            font-family: Arial, sans-serif;
            padding: 20px;
        }
        .id-card {
            width: 400px;
            background: linear-gradient(135deg, #0f3460 0%, #16213e 50%, #1a1a2e 100%);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 35px rgba(0,0,0,0.3);
        }
        .card-header {
            text-align: center;
            padding: 20px 20px 10px;
            border-bottom: 1px solid rgba(255,255,255,0.15);
        }
        .institution-name {
            font-size: 16px;
            font-weight: bold;
            color: white;
            text-transform: uppercase;
        }
        .slogan {
            font-size: 9px;
            color: rgba(255,255,255,0.7);
            margin-top: 4px;
        }
        .card-title {
            font-size: 10px;
            color: #a0aec0;
            margin-top: 8px;
        }
        .card-body {
            padding: 20px;
            display: flex;
            gap: 15px;
        }
        .student-photo {
            width: 110px;
            height: 110px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 15px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 3px solid rgba(255,255,255,0.3);
        }
        .student-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .photo-placeholder {
            font-size: 48px;
            color: white;
        }
        .details-section {
            flex: 1;
        }
        .detail-row {
            margin-bottom: 10px;
            border-bottom: 1px dotted rgba(255,255,255,0.15);
            padding-bottom: 6px;
        }
        .detail-label {
            font-size: 8px;
            color: rgba(255,255,255,0.6);
            text-transform: uppercase;
        }
        .detail-value {
            font-size: 12px;
            font-weight: bold;
            color: white;
            margin-top: 2px;
        }
        .card-footer {
            padding: 15px 20px;
            background: rgba(0,0,0,0.3);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .reg-number {
            font-size: 10px;
            color: rgba(255,255,255,0.7);
            font-family: monospace;
        }
        .qr-code {
            width: 55px;
            height: 55px;
            background: white;
            border-radius: 8px;
            padding: 5px;
        }
        .qr-code img {
            width: 100%;
            height: 100%;
        }
    </style>
</head>
<body>
    <div class="id-card">
        <div class="card-header">
            <div class="institution-name">' . strtoupper($institution_name) . '</div>
            <div class="slogan">' . $slogan . '</div>
            <div class="card-title">STUDENT IDENTIFICATION CARD</div>
        </div>
        <div class="card-body">
            <div class="student-photo">' . $photo_html . '</div>
            <div class="details-section">
                <div class="detail-row">
                    <div class="detail-label">Full Name</div>
                    <div class="detail-value">' . $full_name . '</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Registration No.</div>
                    <div class="detail-value">' . $reg_number . '</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Department</div>
                    <div class="detail-value">' . $dept_code . '</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Faculty</div>
                    <div class="detail-value">' . $faculty . '</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Level</div>
                    <div class="detail-value">' . $level . ' Level</div>
                </div>
            </div>
        </div>
        <div class="card-footer">
            <div class="reg-number">ID: ' . str_pad($student['id'], 6, '0', STR_PAD_LEFT) . '</div>
            ' . $qr_html . '
        </div>
    </div>
</body>
</html>';
    } else {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>ID Card Back - ' . $reg_number . '</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: #e0e0e0;
            font-family: Arial, sans-serif;
            padding: 20px;
        }
        .id-card-back {
            width: 400px;
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 35px rgba(0,0,0,0.2);
        }
        .back-header {
            background: linear-gradient(135deg, #0f3460 0%, #16213e 100%);
            padding: 15px;
            text-align: center;
        }
        .back-header h3 {
            color: white;
            font-size: 14px;
        }
        .back-body {
            padding: 20px;
        }
        .section-title {
            font-size: 11px;
            font-weight: bold;
            color: #667eea;
            border-bottom: 2px solid #667eea;
            padding-bottom: 5px;
            margin-bottom: 10px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 10px;
            padding: 4px 0;
            border-bottom: 1px dotted #e2e8f0;
        }
        .info-label {
            color: #718096;
        }
        .info-value {
            color: #2d3748;
            font-weight: bold;
        }
        .signature-section {
            margin-top: 20px;
            text-align: center;
            padding-top: 15px;
            border-top: 2px dashed #cbd5e0;
        }
        .signature {
            border-top: 1px solid #2d3748;
            width: 180px;
            margin: 5px auto 0;
            padding-top: 5px;
            font-size: 9px;
            color: #718096;
        }
        .footer-note {
            background: #f7fafc;
            padding: 10px;
            text-align: center;
            font-size: 8px;
            color: #a0aec0;
            border-top: 1px solid #e2e8f0;
        }
    </style>
</head>
<body>
    <div class="id-card-back">
        <div class="back-header">
            <h3>IMPORTANT INFORMATION</h3>
        </div>
        <div class="back-body">
            <div class="section-title">PERSONAL DETAILS</div>
            <div class="info-row">
                <span class="info-label">Full Name:</span>
                <span class="info-value">' . $full_name . '</span>
            </div>
            <div class="info-row">
                <span class="info-label">Registration Number:</span>
                <span class="info-value">' . $reg_number . '</span>
            </div>
            <div class="info-row">
                <span class="info-label">Blood Group:</span>
                <span class="info-value">' . $blood . '</span>
            </div>
            <div class="info-row">
                <span class="info-label">Phone:</span>
                <span class="info-value">' . $phone . '</span>
            </div>
            <div class="info-row">
                <span class="info-label">Email:</span>
                <span class="info-value">' . $email . '</span>
            </div>
            
            <div class="section-title" style="margin-top: 15px;">EMERGENCY CONTACT</div>
            <div class="info-row">
                <span class="info-label">Guardian Name:</span>
                <span class="info-value">' . $guardian . '</span>
            </div>
            <div class="info-row">
                <span class="info-label">Guardian Phone:</span>
                <span class="info-value">' . $guardian_phone . '</span>
            </div>
            
            <div class="signature-section">
                <div class="signature">Authorized Signature</div>
            </div>
        </div>
        <div class="footer-note">
            Valid for Academic Session • ' . $level . ' Level
        </div>
    </div>
</body>
</html>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate ID Cards - Admin Portal</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fb;
            padding: 16px;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        
        .header {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 24px; color: #2d3748; }
        .header p { color: #718096; font-size: 14px; }
        
        .filter-bar {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }
        .filter-group { flex: 1; min-width: 180px; }
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-size: 12px;
            font-weight: 600;
            color: #4a5568;
        }
        .filter-group select, .filter-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-success { background: #48bb78; color: white; }
        .btn-info { background: #4299e1; color: white; }
        .btn-outline { background: transparent; border: 1px solid #667eea; color: #667eea; }
        .btn-sm { padding: 5px 12px; font-size: 12px; }
        
        .students-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(420px, 1fr));
            gap: 20px;
        }
        
        .id-card-wrapper {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .id-card {
            background: linear-gradient(135deg, #0f3460 0%, #16213e 50%, #1a1a2e 100%);
            padding: 15px;
        }
        .card-header {
            text-align: center;
            padding: 10px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .card-header .institution-name {
            font-size: 12px;
            font-weight: bold;
            color: white;
            text-transform: uppercase;
        }
        .card-header .card-title {
            font-size: 8px;
            color: #a0aec0;
            margin-top: 4px;
        }
        .card-body {
            display: flex;
            gap: 12px;
            padding: 15px 0;
        }
        .student-photo {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 12px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .student-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .photo-placeholder {
            font-size: 36px;
            color: white;
        }
        .details-section {
            flex: 1;
        }
        .detail-row {
            margin-bottom: 6px;
        }
        .detail-label {
            font-size: 7px;
            color: rgba(255,255,255,0.6);
            text-transform: uppercase;
        }
        .detail-value {
            font-size: 10px;
            font-weight: bold;
            color: white;
        }
        .card-footer {
            background: rgba(0,0,0,0.3);
            padding: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 8px;
        }
        .reg-number {
            font-size: 9px;
            color: rgba(255,255,255,0.7);
            font-family: monospace;
        }
        .qr-code {
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 6px;
            padding: 3px;
        }
        .qr-code img {
            width: 100%;
            height: 100%;
        }
        
        .checkbox-col {
            padding: 10px 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f7fafc;
        }
        .action-buttons {
            display: flex;
            gap: 8px;
            padding: 10px 15px;
            justify-content: center;
            background: #f7fafc;
            border-top: 1px solid #e2e8f0;
        }
        
        .message {
            background: #c6f6d5;
            color: #22543d;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .info-banner {
            background: #e9f5ff;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
        }
        .badge-issued { background: #48bb78; color: white; }
        .badge-pending { background: #ed8936; color: white; }
        
        @media (max-width: 768px) {
            .students-grid { grid-template-columns: 1fr; }
            .filter-form { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>🪪 Generate ID Cards</h1>
                <p>Generate student identification cards with QR code</p>
            </div>
            <a href="index.php" style="color: #667eea; text-decoration: none;">← Back to Dashboard</a>
        </div>
        
        <?php if(isset($_GET['message'])): ?>
            <div class="message">✓ <?php echo htmlspecialchars($_GET['message']); ?></div>
        <?php endif; ?>
        
        <!-- Filter Bar -->
        <div class="filter-bar no-print">
            <form method="GET" action="" class="filter-form">
                <div class="filter-group">
                    <label>Filter by Department</label>
                    <select name="department">
                        <option value="">All Departments</option>
                        <?php foreach($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" <?php echo $department_filter == $dept['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Single Student ID</label>
                    <input type="number" name="student_id" placeholder="Enter Student ID">
                </div>
                <div class="filter-group">
                    <label>Show Students</label>
                    <select name="show_all">
                        <option value="0" <?php echo !$show_all ? 'selected' : ''; ?>>Without ID Card Only</option>
                        <option value="1" <?php echo $show_all ? 'selected' : ''; ?>>All Students</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary">🔍 Filter</button>
                </div>
            </form>
        </div>
        
        <!-- Info Banner -->
        <div class="info-banner no-print">
            <span>📊 <strong><?php echo count($students); ?></strong> student(s) found</span>
        </div>
        
        <!-- Batch Actions -->
        <div class="filter-bar no-print">
            <form method="POST" action="" id="batchForm">
                <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
                    <button type="button" onclick="selectAll()" class="btn btn-outline">✓ Select All</button>
                    <button type="button" onclick="deselectAll()" class="btn btn-outline">✗ Deselect All</button>
                    <button type="submit" name="mark_issued" class="btn btn-success">📌 Mark Selected as Issued</button>
                </div>
            </form>
        </div>
        
        <!-- Students ID Cards Grid -->
        <div class="students-grid">
            <?php if(empty($students)): ?>
                <div style="grid-column: 1/-1; text-align: center; padding: 60px; background: white; border-radius: 12px;">
                    <p>No students found. Please try a different filter.</p>
                </div>
            <?php else: ?>
                <?php foreach($students as $student):
                    $profile_pic = !empty($student['profile_pic']) && file_exists($_SERVER['DOCUMENT_ROOT'] . $student['profile_pic']) 
                        ? $student['profile_pic'] : null;
                    $qr_code = getStudentQRCode($student['reg_number'], $student['id'], $pdo);
                    $full_name = $student['first_name'] . ' ' . $student['last_name'];
                    $is_issued = $student['id_card_issued'];
                ?>
                <div class="id-card-wrapper">
                    <div class="checkbox-col no-print">
                        <input type="checkbox" name="student_ids[]" form="batchForm" value="<?php echo $student['id']; ?>" class="student-checkbox">
                        <span style="font-size: 12px;">
                            <?php echo htmlspecialchars($full_name); ?> - <?php echo htmlspecialchars($student['reg_number']); ?>
                            <?php if($is_issued): ?>
                                <span class="badge badge-issued">Issued</span>
                            <?php else: ?>
                                <span class="badge badge-pending">Pending</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    
                    <div class="id-card">
                        <div class="card-header">
                            <div class="institution-name"><?php echo strtoupper(htmlspecialchars($institution_name)); ?></div>
                            <div class="card-title">STUDENT IDENTIFICATION CARD</div>
                        </div>
                        <div class="card-body">
                            <div class="student-photo">
                                <?php if($profile_pic): ?>
                                    <img src="<?php echo $profile_pic; ?>" alt="Photo">
                                <?php else: ?>
                                    <div class="photo-placeholder">📸</div>
                                <?php endif; ?>
                            </div>
                            <div class="details-section">
                                <div class="detail-row">
                                    <div class="detail-label">Name</div>
                                    <div class="detail-value"><?php echo htmlspecialchars(substr($full_name, 0, 20)); ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Reg No.</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($student['reg_number']); ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Dept</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($student['department_code']); ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Level</div>
                                    <div class="detail-value"><?php echo $student['current_level']; ?> Level</div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer">
                            <div class="reg-number">ID: <?php echo str_pad($student['id'], 6, '0', STR_PAD_LEFT); ?></div>
                            <?php if($qr_code): ?>
                                <div class="qr-code"><img src="<?php echo $qr_code; ?>" alt="QR"></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="action-buttons no-print">
                        <a href="?download_front=<?php echo $student['id']; ?>" class="btn btn-primary btn-sm">⬇️ Front</a>
                        <a href="?download_back=<?php echo $student['id']; ?>" class="btn btn-info btn-sm">⬇️ Back</a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function selectAll() {
            document.querySelectorAll('.student-checkbox').forEach(cb => cb.checked = true);
        }
        function deselectAll() {
            document.querySelectorAll('.student-checkbox').forEach(cb => cb.checked = false);
        }
    </script>
</body>
</html>
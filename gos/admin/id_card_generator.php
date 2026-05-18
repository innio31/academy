<?php
// gos/admin/id_card_generator.php - Generate ID Cards
session_start();

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /gos/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$school_motto = defined('SCHOOL_MOTTO') ? SCHOOL_MOTTO : '';
$school_logo = SCHOOL_LOGO;

// Get ID card settings
$stmt = $pdo->prepare("SELECT * FROM id_card_settings WHERE school_id = ?");
$stmt->execute([$school_id]);
$settings = $stmt->fetch();

if (!$settings) {
    $settings = [
        'card_template' => 'modern',
        'primary_color' => '#722F37',
        'secondary_color' => '#d4af7a',
        'show_motto' => 1,
        'show_qr' => 1,
        'card_back_text' => "This ID Card is the property of $school_name. If found, please return to the school administration office."
    ];
}

// Get filter parameters
$class_filter = $_GET['class'] ?? '';
$student_search = $_GET['search'] ?? '';
$generate_all = isset($_GET['generate_all']) && $_GET['generate_all'] == '1';
$student_id = $_GET['student_id'] ?? '';

// Get classes
$stmt = $pdo->prepare("SELECT DISTINCT class FROM students WHERE school_id = ? AND status = 'active' ORDER BY class");
$stmt->execute([$school_id]);
$classes = $stmt->fetchAll();

// Build query
$query = "SELECT * FROM students WHERE school_id = ? AND status = 'active'";
$params = [$school_id];

if (!empty($class_filter)) {
    $query .= " AND class = ?";
    $params[] = $class_filter;
}
if (!empty($student_search)) {
    $query .= " AND (full_name LIKE ? OR admission_number LIKE ?)";
    $params[] = "%$student_search%";
    $params[] = "%$student_search%";
}
$query .= " ORDER BY class, full_name";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Generate single ID card
if ($student_id && !$generate_all) {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND school_id = ?");
    $stmt->execute([$student_id, $school_id]);
    $student = $stmt->fetch();

    if ($student) {
        generateSingleIDCard($student, $school_name, $school_motto, $school_logo, $settings, $pdo, $school_id);
        exit;
    }
}

// Generate bulk ID cards
if ($generate_all && !empty($students)) {
    generateBulkIDCards($students, $school_name, $school_motto, $school_logo, $settings, $pdo, $school_id);
    exit;
}

function generateQRCodeData($student_id, $admission_number, $full_name)
{
    $qr_data = json_encode([
        'id' => $student_id,
        'admission' => $admission_number,
        'name' => $full_name,
        'type' => 'student',
        'timestamp' => time()
    ]);
    return "https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=" . urlencode($qr_data) . "&choe=UTF-8";
}

function generateSingleIDCard($student, $school_name, $school_motto, $school_logo, $settings, $pdo, $school_id)
{
    $primary_color = $settings['primary_color'];
    $secondary_color = $settings['secondary_color'];
    $show_qr = $settings['show_qr'];
    $show_motto = $settings['show_motto'];
    $card_back_text = $settings['card_back_text'];

    $qr_url = generateQRCodeData($student['id'], $student['admission_number'], $student['full_name']);
    $profile_pic = !empty($student['profile_picture']) ? $student['profile_picture'] : '/assets/default-avatar.png';

    $html = generateIDCardHTML($student, $school_name, $school_motto, $school_logo, $primary_color, $secondary_color, $qr_url, $profile_pic, $show_qr, $show_motto, $card_back_text);

    require_once '../includes/tcpdf/tcpdf.php';

    $pdf = new TCPDF('L', 'mm', array(85.6, 54), true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 10);
    $pdf->writeHTML($html, true, false, true, false, '');

    $filename = 'id_card_' . preg_replace('/[^a-zA-Z0-9]/', '_', $student['full_name']) . '.pdf';
    $pdf->Output($filename, 'D');
}

function generateBulkIDCards($students, $school_name, $school_motto, $school_logo, $settings, $pdo, $school_id)
{
    require_once '../includes/tcpdf/tcpdf.php';

    $pdf = new TCPDF('L', 'mm', array(85.6, 54), true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    foreach ($students as $index => $student) {
        if ($index > 0 && $index % 4 == 0) {
            $pdf->AddPage();
        }

        $qr_url = generateQRCodeData($student['id'], $student['admission_number'], $student['full_name']);
        $profile_pic = !empty($student['profile_picture']) ? $student['profile_picture'] : '/assets/default-avatar.png';

        $html = generateIDCardHTML(
            $student,
            $school_name,
            $school_motto,
            $school_logo,
            $settings['primary_color'],
            $settings['secondary_color'],
            $qr_url,
            $profile_pic,
            $settings['show_qr'],
            $settings['show_motto'],
            $settings['card_back_text']
        );

        $pdf->writeHTML($html, true, false, true, false, '');
    }

    $filename = 'id_cards_bulk_' . date('Y-m-d') . '.pdf';
    $pdf->Output($filename, 'D');
}

function generateIDCardHTML($student, $school_name, $school_motto, $school_logo, $primary_color, $secondary_color, $qr_url, $profile_pic, $show_qr, $show_motto, $card_back_text)
{
    $gender_icon = $student['gender'] == 'Male' ? '👨' : ($student['gender'] == 'Female' ? '👩' : '👤');
    $formatted_dob = $student['dob'] ? date('d/m/Y', strtotime($student['dob'])) : 'N/A';

    return '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: Arial, sans-serif; background: #f0f0f0; }
            .id-card { width: 100%; height: auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
            .card-front { background: linear-gradient(135deg, ' . $primary_color . ', ' . $secondary_color . '); padding: 12px; color: white; }
            .card-header { text-align: center; border-bottom: 2px solid rgba(255,255,255,0.3); padding-bottom: 8px; margin-bottom: 12px; }
            .school-name { font-size: 16px; font-weight: bold; }
            .card-title { font-size: 10px; opacity: 0.9; }
            .card-body { display: flex; gap: 12px; margin-bottom: 12px; }
            .photo-area { text-align: center; }
            .student-photo { width: 80px; height: 80px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; overflow: hidden; border: 3px solid white; }
            .student-photo img { width: 100%; height: 100%; object-fit: cover; }
            .no-photo { width: 100%; height: 100%; background: #eee; color: #999; display: flex; align-items: center; justify-content: center; font-size: 24px; }
            .details { flex: 1; }
            .detail-row { margin-bottom: 5px; }
            .detail-label { font-size: 8px; opacity: 0.8; }
            .detail-value { font-size: 11px; font-weight: bold; }
            .qr-area { text-align: center; width: 70px; }
            .qr-code { width: 60px; height: 60px; background: white; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-bottom: 4px; }
            .qr-code img { width: 55px; height: 55px; }
            .qr-label { font-size: 7px; opacity: 0.8; }
            .card-back { background: #f5f5f5; padding: 12px; min-height: 100px; }
            .back-text { font-size: 8px; line-height: 1.4; color: #333; text-align: center; }
            .back-header { font-weight: bold; margin-bottom: 8px; font-size: 9px; text-align: center; }
            .back-footer { margin-top: 10px; font-size: 7px; text-align: center; color: #666; }
            .motto { font-size: 8px; font-style: italic; text-align: center; margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(255,255,255,0.3); }
        </style>
    </head>
    <body>
        <div class="id-card">
            <div class="card-front">
                <div class="card-header">
                    <div class="school-name">' . htmlspecialchars($school_name) . '</div>
                    <div class="card-title">STUDENT IDENTIFICATION CARD</div>
                </div>
                <div class="card-body">
                    <div class="photo-area">
                        <div class="student-photo">
                            <div class="no-photo">' . $gender_icon . '</div>
                        </div>
                    </div>
                    <div class="details">
                        <div class="detail-row"><div class="detail-label">NAME</div><div class="detail-value">' . strtoupper(htmlspecialchars($student['full_name'])) . '</div></div>
                        <div class="detail-row"><div class="detail-label">ADMISSION NO</div><div class="detail-value">' . htmlspecialchars($student['admission_number']) . '</div></div>
                        <div class="detail-row"><div class="detail-label">CLASS</div><div class="detail-value">' . htmlspecialchars($student['class']) . '</div></div>
                        <div class="detail-row"><div class="detail-label">DOB / GENDER</div><div class="detail-value">' . $formatted_dob . ' / ' . htmlspecialchars($student['gender'] ?? 'N/A') . '</div></div>
                    </div>' . ($show_qr ? '
                    <div class="qr-area">
                        <div class="qr-code"><img src="' . $qr_url . '" alt="QR"></div>
                        <div class="qr-label">SCAN TO VERIFY</div>
                    </div>' : '') . '
                </div>' . ($show_motto && !empty($school_motto) ? '<div class="motto">"' . htmlspecialchars($school_motto) . '"</div>' : '') . '
            </div>
            <div class="card-back">
                <div class="back-header">IMPORTANT INFORMATION</div>
                <div class="back-text">' . nl2br(htmlspecialchars($card_back_text)) . '</div>
                <div class="back-footer">Authorized by: School Administration | Valid for: Current Academic Session</div>
            </div>
        </div>
    </body>
    </html>';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - ID Card Generator</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $settings['primary_color'] ?? '#722F37'; ?>;
            --sidebar-width: 260px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-color), #1a2a3a);
            color: white;
            padding: 20px 0;
            z-index: 100;
            overflow-y: auto;
            transform: translateX(-100%);
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 20px;
            margin-bottom: 15px;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: #d4af7a;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .admin-info {
            text-align: center;
            padding: 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            margin: 0 15px 20px;
        }

        .nav-links {
            list-style: none;
            padding: 0 15px;
        }

        .nav-links li {
            margin-bottom: 5px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            border-radius: 8px;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255, 255, 255, 0.2);
        }

        .main-content {
            margin-left: 0;
            padding: 20px;
        }

        .mobile-menu-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 101;
            background: var(--primary-color);
            color: white;
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 10px;
            font-size: 20px;
            cursor: pointer;
        }

        .top-header {
            background: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        .dashboard-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
        }

        .filter-form {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 12px;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .form-control,
        .form-select {
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            width: 200px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-success {
            background: #27ae60;
            color: white;
        }

        .btn-warning {
            background: #f39c12;
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        .students-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .students-table th,
        .students-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .students-table th {
            background: #f5f5f5;
            font-weight: 600;
        }

        .data-table tr:hover {
            background: #f9f9f9;
        }

        @media (min-width: 769px) {
            .sidebar {
                transform: translateX(0);
            }

            .main-content {
                margin-left: var(--sidebar-width);
            }

            .mobile-menu-btn {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .filter-form {
                flex-direction: column;
            }

            .form-control,
            .form-select {
                width: 100%;
            }

            .students-table {
                font-size: 12px;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>

    <div class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
            <div class="logo-text">
                <h3><?php echo htmlspecialchars($school_name); ?></h3>
                <p>Admin Panel</p>
            </div>
        </div>
        <div class="admin-info">
            <h4><?php echo $_SESSION['admin_name'] ?? 'Admin'; ?></h4>
        </div>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-students.php"><i class="fas fa-users"></i> Students</a></li>
            <li><a href="id_card_generator.php" class="active"><i class="fas fa-id-card"></i> ID Cards</a></li>
            <li><a href="id_card_settings.php"><i class="fas fa-cog"></i> ID Card Settings</a></li>
            <li><a href="/gos/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-id-card"></i> ID Card Generator</h1>
            </div>
            <button class="logout-btn" onclick="window.location.href='/gos/logout.php'"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>

        <div class="dashboard-card">
            <h2><i class="fas fa-filter"></i> Filter Students</h2>
            <form method="GET" class="filter-form">
                <div class="form-group"><label>Class</label><select name="class" class="form-select">
                        <option value="">All Classes</option><?php foreach ($classes as $class): ?><option value="<?php echo htmlspecialchars($class['class']); ?>" <?php echo $class_filter == $class['class'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['class']); ?></option><?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label>Search</label><input type="text" name="search" class="form-control" placeholder="Name or Admission No" value="<?php echo htmlspecialchars($student_search); ?>"></div>
                <div class="form-group"><button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button><a href="id_card_generator.php" class="btn btn-warning"><i class="fas fa-redo"></i> Reset</a></div>
            </form>
        </div>

        <div class="dashboard-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
                <h2><i class="fas fa-users"></i> Students (<?php echo count($students); ?>)</h2>
                <?php if (!empty($students)): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['generate_all' => '1'])); ?>" class="btn btn-success"><i class="fas fa-print"></i> Generate All ID Cards</a>
                <?php endif; ?>
            </div>
            <?php if (empty($students)): ?>
                <div style="text-align:center; padding:50px;"><i class="fas fa-users" style="font-size:48px; color:#ccc;"></i>
                    <p>No students found</p>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="students-table">
                        <thead>
                            <tr>
                                <th>S/N</th>
                                <th>Admission No</th>
                                <th>Student Name</th>
                                <th>Class</th>
                                <th>Gender</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $counter = 1;
                            foreach ($students as $student): ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><?php echo htmlspecialchars($student['admission_number']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($student['full_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($student['class']); ?></td>
                                    <td><?php echo htmlspecialchars($student['gender'] ?? 'N/A'); ?></td>
                                    <td><a href="?student_id=<?php echo $student['id']; ?>" class="btn btn-primary btn-sm"><i class="fas fa-id-card"></i> Generate ID Card</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
        document.getElementById('mobileMenuBtn').onclick = () => document.getElementById('sidebar').classList.toggle('active');
    </script>
</body>

</html>
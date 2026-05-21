<?php
// ida/admin/library.php - Admin Library Management
session_start();

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /ida/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$admin_name = $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? 'Administrator';

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_resource'])) {
    $title = trim($_POST['title']);
    $subject = trim($_POST['subject']);
    $class = trim($_POST['class']);
    $description = trim($_POST['description']);

    if (empty($title) || empty($subject) || empty($class)) {
        $error = "Title, subject, and class are required";
    } elseif (isset($_FILES['resource_file']) && $_FILES['resource_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/library/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_FILES['resource_file']['name']));
        $file_path = 'uploads/library/' . $file_name;
        $file_type = pathinfo($file_name, PATHINFO_EXTENSION);
        $file_size = $_FILES['resource_file']['size'];

        if (move_uploaded_file($_FILES['resource_file']['tmp_name'], '../' . $file_path)) {
            $stmt = $pdo->prepare("
                INSERT INTO library_resources (school_id, title, subject, class, file_type, file_path, file_size, uploaded_by, uploaded_by_type, uploaded_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'admin', NOW())
            ");
            $stmt->execute([$school_id, $title, $subject, $class, $file_type, $file_path, $file_size, $_SESSION['admin_id'] ?? $_SESSION['user_id']]);

            $message = "Resource uploaded successfully!";
            $message_type = "success";
        } else {
            $error = "Failed to upload file";
        }
    } else {
        $error = "Please select a file to upload";
    }
}

// Handle delete
if (isset($_GET['delete'])) {
    $resource_id = $_GET['delete'];

    // Get file path
    $stmt = $pdo->prepare("SELECT file_path FROM library_resources WHERE id = ? AND school_id = ?");
    $stmt->execute([$resource_id, $school_id]);
    $resource = $stmt->fetch();

    if ($resource) {
        // Delete file
        if (file_exists('../' . $resource['file_path'])) {
            unlink('../' . $resource['file_path']);
        }

        // Delete from database
        $stmt = $pdo->prepare("DELETE FROM library_resources WHERE id = ? AND school_id = ?");
        $stmt->execute([$resource_id, $school_id]);

        $message = "Resource deleted successfully";
        $message_type = "success";
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$subject_filter = $_GET['subject'] ?? '';
$class_filter = $_GET['class'] ?? '';
$type_filter = $_GET['type'] ?? '';

// Build query
$query = "SELECT lr.*, 
          CASE 
              WHEN lr.file_size < 1024 THEN CONCAT(lr.file_size, ' B')
              WHEN lr.file_size < 1048576 THEN CONCAT(ROUND(lr.file_size/1024, 1), ' KB')
              ELSE CONCAT(ROUND(lr.file_size/1048576, 1), ' MB')
          END as formatted_size,
          (SELECT COUNT(*) FROM resource_views WHERE resource_id = lr.id) as view_count
          FROM library_resources lr
          WHERE lr.school_id = ?";
$params = [$school_id];

if (!empty($search)) {
    $query .= " AND (lr.title LIKE ? OR lr.subject LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if (!empty($subject_filter)) {
    $query .= " AND lr.subject = ?";
    $params[] = $subject_filter;
}
if (!empty($class_filter)) {
    $query .= " AND lr.class = ?";
    $params[] = $class_filter;
}
if (!empty($type_filter)) {
    $query .= " AND lr.file_type = ?";
    $params[] = $type_filter;
}

$query .= " ORDER BY lr.uploaded_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$resources = $stmt->fetchAll();

// Get distinct subjects for filter
$subjects = $pdo->prepare("SELECT DISTINCT subject FROM library_resources WHERE school_id = ? AND subject IS NOT NULL ORDER BY subject");
$subjects->execute([$school_id]);
$subjects = $subjects->fetchAll(PDO::FETCH_COLUMN);

// Get distinct classes for filter
$classes = $pdo->prepare("SELECT DISTINCT class FROM library_resources WHERE school_id = ? AND class IS NOT NULL ORDER BY class");
$classes->execute([$school_id]);
$classes = $classes->fetchAll(PDO::FETCH_COLUMN);

// Get statistics
$stats = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN file_type IN ('pdf') THEN 1 ELSE 0 END) as pdf_count,
        SUM(CASE WHEN file_type IN ('doc','docx') THEN 1 ELSE 0 END) as doc_count,
        SUM(CASE WHEN file_type IN ('jpg','jpeg','png','gif') THEN 1 ELSE 0 END) as image_count,
        SUM(CASE WHEN file_type IN ('mp4','avi','mov') THEN 1 ELSE 0 END) as video_count,
        SUM(CASE WHEN file_type IN ('mp3','wav') THEN 1 ELSE 0 END) as audio_count
    FROM library_resources WHERE school_id = ?
");
$stats->execute([$school_id]);
$stats = $stats->fetch();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Library Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
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
            padding: 15px 25px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.6rem;
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .card-header {
            border-bottom: 2px solid #ecf0f1;
            padding-bottom: 10px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            border-left: 4px solid var(--primary-color);
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .filter-bar {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
            margin-bottom: 20px;
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
            width: 180px;
        }

        .btn {
            padding: 8px 16px;
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

        .btn-danger {
            background: #e74c3c;
            color: white;
        }

        .btn-warning {
            background: #f39c12;
            color: white;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .data-table th {
            background: #f5f5f5;
            font-weight: 600;
        }

        .data-table tr:hover {
            background: #f9f9f9;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
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
            .top-header {
                flex-direction: column;
            }

            .filter-bar {
                flex-direction: column;
            }

            .form-control,
            .form-select {
                width: 100%;
            }

            .data-table {
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
            <h4><?php echo htmlspecialchars($admin_name); ?></h4>
        </div>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-students.php"><i class="fas fa-users"></i> Students</a></li>
            <li><a href="manage-staff.php"><i class="fas fa-chalkboard-teacher"></i> Staff</a></li>
            <li><a href="library.php" class="active"><i class="fas fa-book"></i> Library</a></li>
            <li><a href="/ida/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-book"></i> Library Management</h1>
            </div>
            <button class="btn" onclick="window.location.href='/ida/logout.php'"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>

        <?php if (isset($message)): ?>
            <div class="alert" style="background:#d5f4e6; padding:15px; border-radius:10px; margin-bottom:20px;"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert" style="background:#f8d7da; padding:15px; border-radius:10px; margin-bottom:20px; color:#e74c3c;"><?php echo $error; ?></div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total'] ?? 0; ?></div>
                <div>Total Resources</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['pdf_count'] ?? 0; ?></div>
                <div>PDF Files</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['doc_count'] ?? 0; ?></div>
                <div>Documents</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['image_count'] ?? 0; ?></div>
                <div>Images</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['video_count'] ?? 0; ?></div>
                <div>Videos</div>
            </div>
        </div>

        <!-- Upload Form -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-upload"></i> Upload Resource</h3><button class="btn btn-primary" onclick="toggleUploadForm()"><i class="fas fa-plus"></i> Show Form</button>
            </div>
            <div id="uploadForm" style="display: none;">
                <form method="POST" enctype="multipart/form-data">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                        <div class="form-group"><label>Title *</label><input type="text" name="title" class="form-control" required></div>
                        <div class="form-group"><label>Subject *</label><input type="text" name="subject" class="form-control" required></div>
                        <div class="form-group"><label>Class *</label>
                            <select name="class" class="form-select" required>
                                <option value="">Select Class</option>
                                <option value="All">All Classes</option>
                                <option value="Nursery 1">Nursery 1</option>
                                <option value="Nursery 2">Nursery 2</option>
                                <option value="Basic 1">Basic 1</option>
                                <option value="Basic 2">Basic 2</option>
                                <option value="Basic 3">Basic 3</option>
                                <option value="Basic 4">Basic 4</option>
                                <option value="Basic 5">Basic 5</option>
                                <option value="JSS 1">JSS 1</option>
                                <option value="JSS 2">JSS 2</option>
                                <option value="JSS 3">JSS 3</option>
                                <option value="SS 1">SS 1</option>
                                <option value="SS 2">SS 2</option>
                                <option value="SS 3">SS 3</option>
                            </select>
                        </div>
                        <div class="form-group"><label>Description</label><textarea name="description" class="form-control" rows="3"></textarea></div>
                        <div class="form-group"><label>File *</label><input type="file" name="resource_file" class="form-control" required></div>
                    </div>
                    <button type="submit" name="upload_resource" class="btn btn-success" style="margin-top: 15px;"><i class="fas fa-cloud-upload-alt"></i> Upload Resource</button>
                </form>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-filter"></i> Filter Resources</h3>
            </div>
            <div class="filter-bar">
                <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
                    <div class="form-group"><label>Search</label><input type="text" name="search" class="form-control" placeholder="Title or subject" value="<?php echo htmlspecialchars($search); ?>"></div>
                    <div class="form-group"><label>Subject</label><select name="subject" class="form-select">
                            <option value="">All Subjects</option><?php foreach ($subjects as $subject): ?><option value="<?php echo htmlspecialchars($subject); ?>" <?php echo $subject_filter == $subject ? 'selected' : ''; ?>><?php echo htmlspecialchars($subject); ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="form-group"><label>Class</label><select name="class" class="form-select">
                            <option value="">All Classes</option><?php foreach ($classes as $class): ?><option value="<?php echo htmlspecialchars($class); ?>" <?php echo $class_filter == $class ? 'selected' : ''; ?>><?php echo htmlspecialchars($class); ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="form-group"><label>Type</label><select name="type" class="form-select">
                            <option value="">All Types</option>
                            <option value="pdf">PDF</option>
                            <option value="doc">DOC</option>
                            <option value="docx">DOCX</option>
                            <option value="jpg">Image</option>
                            <option value="mp4">Video</option>
                        </select></div>
                    <div class="form-group"><button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button><a href="library.php" class="btn btn-warning"><i class="fas fa-redo"></i> Reset</a></div>
                </form>
            </div>
        </div>

        <!-- Resources Table -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Resources (<?php echo count($resources); ?>)</h3>
            </div>
            <?php if (empty($resources)): ?>
                <p style="text-align:center; padding:30px;">No resources found.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Subject</th>
                                <th>Class</th>
                                <th>Type</th>
                                <th>Size</th>
                                <th>Views</th>
                                <th>Uploaded</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resources as $resource): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($resource['title']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($resource['subject']); ?></td>
                                    <td><?php echo htmlspecialchars($resource['class']); ?></td>
                                    <td><?php echo strtoupper($resource['file_type']); ?></td>
                                    <td><?php echo $resource['formatted_size']; ?></td>
                                    <td><?php echo $resource['view_count'] ?? 0; ?></td>
                                    <td><?php echo date('M d, Y', strtotime($resource['uploaded_at'])); ?></td>
                                    <td>
                                        <a href="/<?php echo $resource['file_path']; ?>" target="_blank" class="btn btn-primary btn-sm"><i class="fas fa-eye"></i></a>
                                        <a href="library.php?delete=<?php echo $resource['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this resource?')"><i class="fas fa-trash"></i></a>
                </div>
                </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            </table>
        </div>
    <?php endif; ?>
    </div>
    </div>

    <script>
        function toggleUploadForm() {
            const form = document.getElementById('uploadForm');
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        }
        document.getElementById('mobileMenuBtn').onclick = () => document.getElementById('sidebar').classList.toggle('active');
    </script>
</body>

</html>
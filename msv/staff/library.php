<?php
// msv/staff/library.php - Staff Library Management
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: /msv/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$staff_id = $_SESSION['user_id'];
$staff_name = $_SESSION['user_name'] ?? 'Staff Member';

// Get staff assigned classes
$stmt = $pdo->prepare("SELECT class FROM staff_classes WHERE staff_id = ? AND school_id = ?");
$stmt->execute([$staff_id, $school_id]);
$assigned_classes = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Handle upload
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
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'staff', NOW())
            ");
            $stmt->execute([$school_id, $title, $subject, $class, $file_type, $file_path, $file_size, $staff_id]);

            $message = "Resource uploaded successfully!";
        } else {
            $error = "Failed to upload file";
        }
    } else {
        $error = "Please select a file to upload";
    }
}

// Handle delete (only own resources)
if (isset($_GET['delete'])) {
    $resource_id = $_GET['delete'];

    $stmt = $pdo->prepare("SELECT file_path FROM library_resources WHERE id = ? AND school_id = ? AND uploaded_by = ? AND uploaded_by_type = 'staff'");
    $stmt->execute([$resource_id, $school_id, $staff_id]);
    $resource = $stmt->fetch();

    if ($resource) {
        if (file_exists('../' . $resource['file_path'])) {
            unlink('../' . $resource['file_path']);
        }
        $stmt = $pdo->prepare("DELETE FROM library_resources WHERE id = ?");
        $stmt->execute([$resource_id]);
        $message = "Resource deleted successfully";
    }
}

// Get resources (staff's own + shared to their classes)
$search = $_GET['search'] ?? '';
$query = "SELECT lr.*, 
          CASE WHEN lr.file_size < 1024 THEN CONCAT(lr.file_size, ' B')
               WHEN lr.file_size < 1048576 THEN CONCAT(ROUND(lr.file_size/1024, 1), ' KB')
               ELSE CONCAT(ROUND(lr.file_size/1048576, 1), ' MB')
          END as formatted_size
          FROM library_resources lr
          WHERE lr.school_id = ? AND (lr.uploaded_by = ? OR lr.class IN (" . str_repeat('?,', count($assigned_classes) - 1) . "?) OR lr.class = 'All')";
$params = [$school_id, $staff_id];
$params = array_merge($params, $assigned_classes);

if (!empty($search)) {
    $query .= " AND (lr.title LIKE ? OR lr.subject LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$query .= " ORDER BY lr.uploaded_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$resources = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Library</title>
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

        .staff-info {
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
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
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
            .form-grid {
                grid-template-columns: 1fr;
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
            <div class="logo-icon"><i class="fas fa-chalkboard-teacher"></i></div>
            <div class="logo-text">
                <h3><?php echo htmlspecialchars($school_name); ?></h3>
                <p>Staff Portal</p>
            </div>
        </div>
        <div class="staff-info">
            <h4><?php echo htmlspecialchars($staff_name); ?></h4>
        </div>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="library.php" class="active"><i class="fas fa-book"></i> Library</a></li>
            <li><a href="../msv/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-book"></i> Library Resources</h1>
            </div>
            <button class="btn" onclick="window.location.href='../msv/logout.php'"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>

        <?php if (isset($message)): ?>
            <div style="background:#d5f4e6; padding:15px; border-radius:10px; margin-bottom:20px;"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Upload Form -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-upload"></i> Upload Resource</h3>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-grid">
                    <div><label>Title *</label><input type="text" name="title" class="form-control" style="width:100%; padding:10px; border:2px solid #e0e0e0; border-radius:8px;" required></div>
                    <div><label>Subject *</label><input type="text" name="subject" class="form-control" style="width:100%; padding:10px; border:2px solid #e0e0e0; border-radius:8px;" required></div>
                    <div><label>Class *</label>
                        <select name="class" class="form-select" style="width:100%; padding:10px; border:2px solid #e0e0e0; border-radius:8px;" required>
                            <option value="">Select Class</option>
                            <?php foreach ($assigned_classes as $class): ?>
                                <option value="<?php echo htmlspecialchars($class); ?>"><?php echo htmlspecialchars($class); ?></option>
                            <?php endforeach; ?>
                            <option value="All">All Classes</option>
                        </select>
                    </div>
                    <div><label>Description</label><textarea name="description" class="form-control" style="width:100%; padding:10px; border:2px solid #e0e0e0; border-radius:8px;" rows="3"></textarea></div>
                    <div><label>File *</label><input type="file" name="resource_file" style="width:100%; padding:10px;" required></div>
                </div>
                <button type="submit" name="upload_resource" class="btn btn-success" style="margin-top: 15px;"><i class="fas fa-cloud-upload-alt"></i> Upload</button>
            </form>
        </div>

        <!-- Resources List -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Available Resources</h3>
            </div>
            <div style="margin-bottom: 15px;">
                <form method="GET" style="display: flex; gap: 10px;">
                    <input type="text" name="search" class="form-control" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>" style="flex:1; padding:10px; border:2px solid #e0e0e0; border-radius:8px;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
                    <a href="library.php" class="btn btn-warning"><i class="fas fa-redo"></i> Reset</a>
                </form>
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
                                    <td>
                                        <a href="/<?php echo $resource['file_path']; ?>" target="_blank" class="btn btn-primary btn-sm"><i class="fas fa-eye"></i> View</a>
                                        <?php if ($resource['uploaded_by'] == $staff_id && $resource['uploaded_by_type'] == 'staff'): ?>
                                            <a href="library.php?delete=<?php echo $resource['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i> Delete</a>
                                        <?php endif; ?>
                </div>
                </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            </tr>
        </div>
    <?php endif; ?>
    </div>
    </div>
    <script>
        document.getElementById('mobileMenuBtn').onclick = () => document.getElementById('sidebar').classList.toggle('active');
    </script>
</body>

</html>
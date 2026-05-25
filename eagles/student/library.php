<?php
// tbis/student/library.php - Online E-Library for Students
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: /tbis/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$student_id = $_SESSION['user_id'];
$student_class = $_SESSION['user_class'] ?? '';

// Get filter parameters
$subject_filter = $_GET['subject'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';
$search_query = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'newest';

// Build WHERE conditions with school_id
$where_conditions = ["lr.school_id = ?", "(lr.class = ? OR lr.class = 'All' OR lr.class = '')"];
$params = [$school_id, $student_class];

// Subject filter
if ($subject_filter !== 'all') {
    $where_conditions[] = "lr.subject = ?";
    $params[] = $subject_filter;
}

// Type filter
if ($type_filter !== 'all') {
    if ($type_filter === 'IMAGE') {
        $where_conditions[] = "(lr.file_type LIKE '%.jpg%' OR lr.file_type LIKE '%.jpeg%' OR lr.file_type LIKE '%.png%' OR lr.file_type LIKE '%.gif%')";
    } elseif ($type_filter === 'VIDEO') {
        $where_conditions[] = "(lr.file_type LIKE '%.mp4%' OR lr.file_type LIKE '%.avi%' OR lr.file_type LIKE '%.mov%')";
    } elseif ($type_filter === 'AUDIO') {
        $where_conditions[] = "(lr.file_type LIKE '%.mp3%' OR lr.file_type LIKE '%.wav%')";
    } elseif ($type_filter === 'DOC') {
        $where_conditions[] = "(lr.file_type LIKE '%.doc%' OR lr.file_type LIKE '%.docx%')";
    } elseif ($type_filter === 'PPT') {
        $where_conditions[] = "(lr.file_type LIKE '%.ppt%' OR lr.file_type LIKE '%.pptx%')";
    } elseif ($type_filter === 'PDF') {
        $where_conditions[] = "lr.file_type LIKE '%.pdf%'";
    } else {
        $where_conditions[] = "lr.file_type LIKE ?";
        $params[] = "%$type_filter%";
    }
}

// Search filter
if ($search_query) {
    $where_conditions[] = "(lr.title LIKE ? OR lr.subject LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

// Sort order
switch ($sort_by) {
    case 'oldest':
        $order_by = "lr.uploaded_at ASC";
        break;
    case 'title_asc':
        $order_by = "lr.title ASC";
        break;
    case 'title_desc':
        $order_by = "lr.title DESC";
        break;
    default:
        $order_by = "lr.uploaded_at DESC";
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get resources
$query = "
    SELECT lr.*, 
           CASE 
               WHEN lr.file_size < 1024 THEN CONCAT(lr.file_size, ' B')
               WHEN lr.file_size < 1048576 THEN CONCAT(ROUND(lr.file_size/1024, 1), ' KB')
               ELSE CONCAT(ROUND(lr.file_size/1048576, 1), ' MB')
           END as formatted_size,
           CASE 
               WHEN lr.uploaded_by_type = 'staff' THEN st.full_name
               WHEN lr.uploaded_by_type = 'admin' THEN au.full_name
               ELSE 'System'
           END as uploaded_by_name
    FROM library_resources lr
    LEFT JOIN staff st ON lr.uploaded_by = st.id AND lr.uploaded_by_type = 'staff' AND st.school_id = lr.school_id
    LEFT JOIN admin_users au ON lr.uploaded_by = au.id AND lr.uploaded_by_type = 'admin'
    $where_clause
    ORDER BY $order_by
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$resources = $stmt->fetchAll();

// Get distinct subjects for filter
$stmt = $pdo->prepare("
    SELECT DISTINCT subject FROM library_resources 
    WHERE school_id = ? AND (class = ? OR class = 'All' OR class = '') AND subject IS NOT NULL
    ORDER BY subject
");
$stmt->execute([$school_id, $student_class]);
$available_subjects = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN file_type LIKE '%.pdf%' THEN 1 ELSE 0 END) as pdf_count,
        SUM(CASE WHEN file_type LIKE '%.jpg%' OR file_type LIKE '%.png%' THEN 1 ELSE 0 END) as image_count,
        SUM(CASE WHEN file_type LIKE '%.mp4%' THEN 1 ELSE 0 END) as video_count,
        SUM(CASE WHEN file_type LIKE '%.mp3%' THEN 1 ELSE 0 END) as audio_count,
        SUM(CASE WHEN file_type LIKE '%.doc%' OR file_type LIKE '%.docx%' THEN 1 ELSE 0 END) as doc_count
    FROM library_resources 
    WHERE school_id = ? AND (class = ? OR class = 'All' OR class = '')
");
$stmt->execute([$school_id, $student_class]);
$stats = $stmt->fetch();

// Helper functions
function getFileIcon($file_type)
{
    $ext = strtolower(pathinfo($file_type, PATHINFO_EXTENSION));
    $icons = [
        'pdf' => '📕',
        'doc' => '📘',
        'docx' => '📘',
        'ppt' => '📙',
        'pptx' => '📙',
        'xls' => '📗',
        'xlsx' => '📗',
        'jpg' => '🖼️',
        'jpeg' => '🖼️',
        'png' => '🖼️',
        'gif' => '🖼️',
        'mp4' => '🎬',
        'avi' => '🎬',
        'mov' => '🎬',
        'mp3' => '🎵',
        'wav' => '🎵',
        'txt' => '📄',
        'zip' => '📦'
    ];
    return $icons[$ext] ?? '📁';
}

function timeAgo($datetime)
{
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' mins ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return date('M j, Y', $time);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - E-Library</title>
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

        .student-info {
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
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
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
            font-size: 1.5rem;
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

        .resource-item {
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            transition: all 0.3s;
        }

        .resource-item:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .resource-header {
            display: flex;
            gap: 15px;
            align-items: flex-start;
        }

        .file-icon {
            font-size: 2rem;
        }

        .resource-details {
            flex: 1;
        }

        .resource-title {
            font-weight: 600;
            margin-bottom: 5px;
        }

        .resource-meta {
            font-size: 0.75rem;
            color: #666;
            margin-bottom: 10px;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .badge-subject {
            background: #e3f2fd;
            color: #1976d2;
        }

        .badge-type {
            background: #f3e5f5;
            color: #7b1fa2;
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
            .filter-bar {
                flex-direction: column;
            }

            .form-control,
            .form-select {
                width: 100%;
            }

            .resource-header {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>

    <div class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-user-graduate"></i></div>
            <div class="logo-text">
                <h3><?php echo htmlspecialchars($school_name); ?></h3>
                <p>Student Portal</p>
            </div>
        </div>
        <div class="student-info">
            <h4><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Student'); ?></h4>
            <p><?php echo htmlspecialchars($student_class); ?></p>
        </div>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="take-exam.php"><i class="fas fa-file-alt"></i> Take Exam</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> My Results</a></li>
            <li><a href="assignments.php"><i class="fas fa-tasks"></i> Assignments</a></li>
            <li><a href="library.php" class="active"><i class="fas fa-book"></i> E-Library</a></li>
            <li><a href="profile.php"><i class="fas fa-user-cog"></i> My Profile</a></li>
            <li><a href="../tbis/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-book"></i> Digital Library</h1>
            </div>
            <button class="btn" onclick="window.location.href='/tbis/logout.php'"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total'] ?? 0; ?></div>
                <div>Total Resources</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['pdf_count'] ?? 0; ?></div>
                <div>PDF Books</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['video_count'] ?? 0; ?></div>
                <div>Videos</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-filter"></i> Filter Resources</h3>
            </div>
            <form method="GET" class="filter-bar">
                <div class="form-group"><label>Search</label><input type="text" name="search" class="form-control" placeholder="Title or subject" value="<?php echo htmlspecialchars($search_query); ?>"></div>
                <div class="form-group"><label>Subject</label><select name="subject" class="form-select">
                        <option value="all">All Subjects</option><?php foreach ($available_subjects as $subject): ?><option value="<?php echo htmlspecialchars($subject); ?>" <?php echo $subject_filter === $subject ? 'selected' : ''; ?>><?php echo htmlspecialchars($subject); ?></option><?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label>Sort By</label><select name="sort" class="form-select">
                        <option value="newest" <?php echo $sort_by === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                        <option value="oldest" <?php echo $sort_by === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                        <option value="title_asc" <?php echo $sort_by === 'title_asc' ? 'selected' : ''; ?>>Title A-Z</option>
                    </select></div>
                <div class="form-group"><button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply</button><a href="library.php" class="btn btn-warning">Clear</a></div>
            </form>
        </div>

        <!-- Resources List -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Available Resources (<?php echo count($resources); ?>)</h3>
            </div>
            <?php if (empty($resources)): ?>
                <p style="text-align:center; padding:30px;">No resources found.</p>
            <?php else: ?>
                <?php foreach ($resources as $resource): ?>
                    <div class="resource-item">
                        <div class="resource-header">
                            <div class="file-icon"><?php echo getFileIcon($resource['file_type']); ?></div>
                            <div class="resource-details">
                                <div class="resource-title"><?php echo htmlspecialchars($resource['title']); ?></div>
                                <div class="resource-meta">
                                    <span class="badge badge-subject">📚 <?php echo htmlspecialchars($resource['subject']); ?></span>
                                    <span class="badge badge-type">📄 <?php echo strtoupper($resource['file_type']); ?></span>
                                    <span>💾 <?php echo $resource['formatted_size']; ?></span>
                                    <span>📅 <?php echo timeAgo($resource['uploaded_at']); ?></span>
                                </div>
                                <div class="action-buttons" style="display: flex; gap: 10px; margin-top: 10px;">
                                    <a href="/<?php echo $resource['file_path']; ?>" target="_blank" class="btn btn-primary btn-sm"><i class="fas fa-eye"></i> View</a>
                                    <a href="download-resource.php?id=<?php echo $resource['id']; ?>" class="btn btn-success btn-sm"><i class="fas fa-download"></i> Download</a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.getElementById('mobileMenuBtn').onclick = () => document.getElementById('sidebar').classList.toggle('active');
    </script>
</body>

</html>
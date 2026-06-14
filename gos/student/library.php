<?php
// gos/student/library.php - Online E-Library for Students
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: /gos/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'] ?? 'Student';

// Get student details including class
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND school_id = ?");
$stmt->execute([$student_id, $school_id]);
$student_data = $stmt->fetch();

if (!$student_data) {
    header("Location: /gos/login.php");
    exit();
}

$student_class = $student_data['class'];
$admission_number = $student_data['admission_number'];

// Get profile picture path
$profile_picture = !empty($student_data['profile_picture']) ? $student_data['profile_picture'] : '/assets/uploads/default-avatar.png';
if (!empty($student_data['profile_picture']) && strpos($student_data['profile_picture'], '/') !== 0) {
    $profile_picture = '/uploads/' . $student_data['profile_picture'];
}

// Get filter parameters
$subject_filter = $_GET['subject'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';
$search_query = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'newest';

// Build WHERE conditions with school_id - handle multi-class resources
// Need to check if student's class is in the comma-separated list
$where_conditions = ["lr.school_id = ?"];
$params = [$school_id];

// Access condition: student's class matches single class, is in comma-separated list, or 'All'
$access_condition = "(lr.class = ? OR lr.class = 'All' OR lr.class = '' OR FIND_IN_SET(?, lr.class))";
$params[] = $student_class;
$params[] = $student_class;
$where_conditions[] = $access_condition;

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
    WHERE school_id = ? AND (class = ? OR class = 'All' OR class = '' OR FIND_IN_SET(?, class)) AND subject IS NOT NULL
    ORDER BY subject
");
$stmt->execute([$school_id, $student_class, $student_class]);
$available_subjects = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get resource types for filter
$resource_types = ['PDF', 'DOC', 'PPT', 'VIDEO', 'AUDIO', 'IMAGE'];

// Get statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN file_type LIKE '%.pdf%' THEN 1 ELSE 0 END) as pdf_count,
        SUM(CASE WHEN file_type LIKE '%.jpg%' OR file_type LIKE '%.jpeg%' OR file_type LIKE '%.png%' OR file_type LIKE '%.gif%' THEN 1 ELSE 0 END) as image_count,
        SUM(CASE WHEN file_type LIKE '%.mp4%' OR file_type LIKE '%.avi%' OR file_type LIKE '%.mov%' THEN 1 ELSE 0 END) as video_count,
        SUM(CASE WHEN file_type LIKE '%.mp3%' OR file_type LIKE '%.wav%' THEN 1 ELSE 0 END) as audio_count,
        SUM(CASE WHEN file_type LIKE '%.doc%' OR file_type LIKE '%.docx%' THEN 1 ELSE 0 END) as doc_count,
        SUM(CASE WHEN file_type LIKE '%.ppt%' OR file_type LIKE '%.pptx%' THEN 1 ELSE 0 END) as ppt_count
    FROM library_resources 
    WHERE school_id = ? AND (class = ? OR class = 'All' OR class = '' OR FIND_IN_SET(?, class))
");
$stmt->execute([$school_id, $student_class, $student_class]);
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

function getFileBadgeColor($file_type)
{
    $ext = strtolower(pathinfo($file_type, PATHINFO_EXTENSION));
    $colors = [
        'pdf' => '#e74c3c',
        'doc' => '#2c3e50',
        'docx' => '#2c3e50',
        'ppt' => '#e67e22',
        'pptx' => '#e67e22',
        'xls' => '#27ae60',
        'xlsx' => '#27ae60',
        'jpg' => '#3498db',
        'jpeg' => '#3498db',
        'png' => '#3498db',
        'mp4' => '#9b59b6',
        'mp3' => '#1abc9c'
    ];
    return $colors[$ext] ?? '#7f8c8d';
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($school_name); ?> - E-Library</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: <?php echo $secondary_color; ?>;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --light: #ecf0f1;
            --dark: #2c3e50;
            --sidebar-width: 280px;
            --shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            --radius: 12px;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Mobile Menu Button */
        .mobile-menu-btn {
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            width: 44px;
            height: 44px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 20px;
            cursor: pointer;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Main Content */
        .main-content {
            min-height: 100vh;
            padding: 20px;
            transition: var(--transition);
        }

        /* Top Header */
        .top-header {
            background: white;
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 25px;
            background: linear-gradient(135deg, var(--primary-color), var(--dark));
            color: white;
        }

        .welcome-banner {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .welcome-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #d4af7a;
            background: #f0f0f0;
        }

        .welcome-text h1 {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .welcome-text p {
            opacity: 0.9;
            font-size: 0.85rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            text-align: center;
            box-shadow: var(--shadow);
            transition: var(--transition);
            border-top: 3px solid var(--primary-color);
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .stat-label {
            color: #666;
            font-size: 0.75rem;
            margin-top: 5px;
        }

        /* Cards */
        .content-card {
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: var(--shadow);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--light);
            flex-wrap: wrap;
            gap: 10px;
        }

        .card-header h3 {
            color: var(--primary-color);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Filter Bar */
        .filter-bar {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 150px;
        }

        .filter-group label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #666;
            margin-bottom: 5px;
            display: block;
        }

        .form-control,
        .form-select {
            width: 100%;
            padding: 10px 12px;
            border: 1.5px solid #e0e0e0;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        /* Resource Items */
        .resource-item {
            border: 1px solid #e0e0e0;
            border-radius: var(--radius);
            padding: 18px;
            margin-bottom: 15px;
            transition: var(--transition);
            background: white;
        }

        .resource-item:hover {
            box-shadow: var(--shadow);
            transform: translateY(-2px);
        }

        .resource-header {
            display: flex;
            gap: 18px;
            align-items: flex-start;
        }

        .file-icon {
            font-size: 2.5rem;
        }

        .resource-details {
            flex: 1;
        }

        .resource-title {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 8px;
            color: var(--dark);
        }

        .resource-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 12px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
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

        .badge-size {
            background: var(--light);
            color: var(--dark);
        }

        .badge-date {
            background: #fff3e0;
            color: #e65100;
        }

        .resource-description {
            font-size: 0.8rem;
            color: #666;
            margin-bottom: 12px;
            line-height: 1.5;
        }

        .uploaded-by {
            font-size: 0.7rem;
            color: #999;
            margin-bottom: 12px;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 8px 18px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.8rem;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #0e9f6e;
        }

        .btn-outline {
            border: 1px solid #e0e0e0;
            background: white;
            color: var(--dark);
        }

        .btn-outline:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        .btn-sm {
            padding: 5px 12px;
            font-size: 0.7rem;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        /* Footer */
        .footer {
            text-align: center;
            padding: 20px;
            color: #666;
            font-size: 0.8rem;
            border-top: 1px solid var(--light);
            margin-top: 20px;
        }

        /* Desktop */
        @media (min-width: 769px) {
            .mobile-menu-btn {
                display: none;
            }

            .main-content {
                margin-left: var(--sidebar-width);
            }
        }

        /* Mobile */
        @media (max-width: 768px) {
            .main-content {
                padding: 70px 15px 20px;
            }

            .welcome-banner {
                flex-direction: column;
                text-align: center;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-bar {
                flex-direction: column;
            }

            .filter-group {
                width: 100%;
            }

            .filter-actions {
                width: 100%;
            }

            .resource-header {
                flex-direction: column;
                text-align: center;
            }

            .resource-meta {
                justify-content: center;
            }

            .action-buttons {
                justify-content: center;
            }
        }
    </style>
</head>

<body>

    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>

    <!-- Include Student Sidebar -->
    <?php require_once 'includes/student_sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">

        <div class="top-header">
            <div class="welcome-banner">
                <img src="<?php echo htmlspecialchars($profile_picture); ?>"
                    alt="Profile Picture"
                    class="welcome-avatar"
                    onerror="this.src='/assets/uploads/default-avatar.png'">
                <div class="welcome-text">
                    <h1><i class="fas fa-book-open"></i> Digital Library</h1>
                    <p><i class="fas fa-graduation-cap"></i> Class: <?php echo htmlspecialchars($student_class); ?> | <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($admission_number); ?></p>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total'] ?? 0; ?></div>
                <div class="stat-label">Total Resources</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['pdf_count'] ?? 0; ?></div>
                <div class="stat-label">PDF Books</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['video_count'] ?? 0; ?></div>
                <div class="stat-label">Video Lessons</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['doc_count'] ?? 0; ?></div>
                <div class="stat-label">Documents</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-filter"></i> Filter Resources</h3>
                <a href="library.php" class="btn btn-outline btn-sm">
                    <i class="fas fa-redo-alt"></i> Reset
                </a>
            </div>
            <form method="GET" class="filter-bar">
                <div class="filter-group">
                    <label><i class="fas fa-search"></i> Search</label>
                    <input type="text" name="search" class="form-control"
                        placeholder="Search by title or subject..."
                        value="<?php echo htmlspecialchars($search_query); ?>">
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-book"></i> Subject</label>
                    <select name="subject" class="form-select">
                        <option value="all">All Subjects</option>
                        <?php foreach ($available_subjects as $subject): ?>
                            <option value="<?php echo htmlspecialchars($subject); ?>"
                                <?php echo $subject_filter === $subject ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subject); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-file-alt"></i> File Type</label>
                    <select name="type" class="form-select">
                        <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                        <option value="PDF" <?php echo $type_filter === 'PDF' ? 'selected' : ''; ?>>PDF Documents</option>
                        <option value="VIDEO" <?php echo $type_filter === 'VIDEO' ? 'selected' : ''; ?>>Videos</option>
                        <option value="DOC" <?php echo $type_filter === 'DOC' ? 'selected' : ''; ?>>Word Documents</option>
                        <option value="PPT" <?php echo $type_filter === 'PPT' ? 'selected' : ''; ?>>Presentations</option>
                        <option value="IMAGE" <?php echo $type_filter === 'IMAGE' ? 'selected' : ''; ?>>Images</option>
                        <option value="AUDIO" <?php echo $type_filter === 'AUDIO' ? 'selected' : ''; ?>>Audio</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-sort"></i> Sort By</label>
                    <select name="sort" class="form-select">
                        <option value="newest" <?php echo $sort_by === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                        <option value="oldest" <?php echo $sort_by === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                        <option value="title_asc" <?php echo $sort_by === 'title_asc' ? 'selected' : ''; ?>>Title A-Z</option>
                        <option value="title_desc" <?php echo $sort_by === 'title_desc' ? 'selected' : ''; ?>>Title Z-A</option>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Apply
                    </button>
                </div>
            </form>
        </div>

        <!-- Resources List -->
        <div class="content-card">
            <div class="card-header">
                <h3>
                    <i class="fas fa-list"></i>
                    Available Resources
                    <span class="badge" style="background: var(--light); color: var(--dark);">
                        <?php echo count($resources); ?> items
                    </span>
                </h3>
            </div>

            <?php if (empty($resources)): ?>
                <div class="empty-state">
                    <i class="fas fa-book-open"></i>
                    <h3>No resources found</h3>
                    <p style="margin-top: 10px;">Try adjusting your filters or check back later for new materials.</p>
                    <a href="library.php" class="btn btn-primary" style="margin-top: 20px;">
                        <i class="fas fa-sync-alt"></i> Clear Filters
                    </a>
                </div>
            <?php else: ?>
                <?php foreach ($resources as $resource): ?>
                    <div class="resource-item">
                        <div class="resource-header">
                            <div class="file-icon"><?php echo getFileIcon($resource['file_type']); ?></div>
                            <div class="resource-details">
                                <div class="resource-title"><?php echo htmlspecialchars($resource['title']); ?></div>

                                <div class="resource-meta">
                                    <span class="badge badge-subject">
                                        <i class="fas fa-book"></i> <?php echo htmlspecialchars($resource['subject']); ?>
                                    </span>
                                    <span class="badge badge-type">
                                        <i class="fas fa-file"></i> <?php echo strtoupper(pathinfo($resource['file_type'], PATHINFO_EXTENSION)); ?>
                                    </span>
                                    <span class="badge badge-size">
                                        <i class="fas fa-database"></i> <?php echo $resource['formatted_size']; ?>
                                    </span>
                                    <span class="badge badge-date">
                                        <i class="fas fa-calendar"></i> <?php echo timeAgo($resource['uploaded_at']); ?>
                                    </span>
                                </div>

                                <?php if (!empty($resource['description'])): ?>
                                    <div class="resource-description">
                                        <?php echo htmlspecialchars(substr($resource['description'], 0, 150)) . (strlen($resource['description']) > 150 ? '...' : ''); ?>
                                    </div>
                                <?php endif; ?>

                                <div class="uploaded-by">
                                    <i class="fas fa-user"></i> Uploaded by: <?php echo htmlspecialchars($resource['uploaded_by_name']); ?>
                                </div>

                                <div class="action-buttons" style="display: flex; gap: 10px; flex-wrap: wrap;">
                                    <?php
                                    // Determine correct file URL
                                    $file_url = '/gos/' . $resource['file_path'];
                                    // If the path already starts with /gos, don't add it again
                                    if (strpos($resource['file_path'], '/gos/') === 0) {
                                        $file_url = $resource['file_path'];
                                    } elseif (strpos($resource['file_path'], 'uploads/') === 0) {
                                        $file_url = '/gos/' . $resource['file_path'];
                                    }
                                    ?>
                                    <a href="<?php echo $file_url; ?>" target="_blank" class="btn btn-primary btn-sm">
                                        <i class="fas fa-eye"></i> View Online
                                    </a>
                                    <a href="download-resource.php?id=<?php echo $resource['id']; ?>" class="btn btn-success btn-sm">
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> - Student Portal</p>
        </div>
    </div>

    <script>
        // Mobile sidebar toggle
        const mobileBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('studentSidebar');

        if (mobileBtn && sidebar) {
            mobileBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                sidebar.classList.toggle('active');
                const overlay = document.getElementById('sidebarOverlay');
                if (overlay) overlay.classList.toggle('active');
                document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
            });
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768 && sidebar &&
                !sidebar.contains(e.target) &&
                !mobileBtn.contains(e.target)) {
                sidebar.classList.remove('active');
                const overlay = document.getElementById('sidebarOverlay');
                if (overlay) overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    </script>
</body>

</html>
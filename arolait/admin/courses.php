<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

// Handle delete of course offering
if (isset($_GET['delete_offering'])) {
    $id = $_GET['delete_offering'];
    try {
        $stmt = $pdo->prepare("DELETE FROM course_offerings WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: courses.php?message=Course offering removed successfully");
    } catch(PDOException $e) {
        header("Location: courses.php?error=Cannot remove course offering with existing records");
    }
    exit();
}

// Handle delete of course template
if (isset($_GET['delete_template'])) {
    $id = $_GET['delete_template'];
    try {
        // Check if course has any offerings
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM course_offerings WHERE course_id = ?");
        $stmt->execute([$id]);
        if($stmt->fetchColumn() > 0) {
            header("Location: courses.php?error=Cannot delete course template with existing offerings");
        } else {
            $stmt = $pdo->prepare("DELETE FROM courses WHERE id = ?");
            $stmt->execute([$id]);
            header("Location: courses.php?message=Course template deleted successfully");
        }
    } catch(PDOException $e) {
        header("Location: courses.php?error=Error deleting course");
    }
    exit();
}

// Handle status toggle (active/inactive)
if (isset($_GET['toggle_template'])) {
    $id = $_GET['toggle_template'];
    $stmt = $pdo->prepare("UPDATE courses SET is_active = NOT is_active WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: courses.php?message=Course status updated");
    exit();
}

// Get filters
$faculty_id = $_GET['faculty'] ?? '';
$department_id = $_GET['department'] ?? '';
$level = $_GET['level'] ?? '';
$session_id = $_GET['session'] ?? '';
$view = $_GET['view'] ?? 'offerings'; // 'templates' or 'offerings'

// Get all faculties for filter
$faculties = $pdo->query("SELECT id, name FROM faculties ORDER BY name")->fetchAll();

// Get departments based on faculty filter
if ($faculty_id) {
    $depts = $pdo->prepare("SELECT id, name FROM departments WHERE faculty_id = ? ORDER BY name");
    $depts->execute([$faculty_id]);
    $departments = $depts->fetchAll();
} else {
    $departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();
}

// Get academic sessions for filter
$sessions = $pdo->query("SELECT * FROM academic_sessions ORDER BY start_date DESC")->fetchAll();

// Get current session
$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current = 1 LIMIT 1")->fetch();

// Get course templates (permanent courses)
$templates_sql = "SELECT 
                    c.*,
                    d.name as department_name,
                    d.code as department_code,
                    f.name as faculty_name,
                    COUNT(co.id) as offering_count
                  FROM courses c
                  JOIN departments d ON c.department_id = d.id
                  JOIN faculties f ON d.faculty_id = f.id
                  LEFT JOIN course_offerings co ON c.id = co.course_id
                  WHERE 1=1";
$templates_params = [];

if ($department_id) {
    $templates_sql .= " AND c.department_id = ?";
    $templates_params[] = $department_id;
}
if ($level) {
    $templates_sql .= " AND c.level = ?";
    $templates_params[] = $level;
}

$templates_sql .= " GROUP BY c.id ORDER BY c.code ASC";
$stmt = $pdo->prepare($templates_sql);
$stmt->execute($templates_params);
$course_templates = $stmt->fetchAll();

// Get course offerings (courses offered in specific sessions)
$offerings_sql = "SELECT 
                    co.*,
                    c.code,
                    c.title,
                    c.credit_unit,
                    c.level,
                    c.is_elective,
                    d.name as department_name,
                    f.name as faculty_name,
                    CONCAT(u.first_name, ' ', u.last_name) as lecturer_name,
                    a.name as session_name,
                    s.name as semester_name
                  FROM course_offerings co
                  JOIN courses c ON co.course_id = c.id
                  JOIN departments d ON c.department_id = d.id
                  JOIN faculties f ON d.faculty_id = f.id
                  JOIN semesters s ON co.semester_id = s.id
                  JOIN academic_sessions a ON s.session_id = a.id
                  LEFT JOIN users u ON co.lecturer_id = u.id
                  WHERE 1=1";
$offerings_params = [];

if ($department_id) {
    $offerings_sql .= " AND c.department_id = ?";
    $offerings_params[] = $department_id;
}
if ($level) {
    $offerings_sql .= " AND c.level = ?";
    $offerings_params[] = $level;
}
if ($session_id) {
    $offerings_sql .= " AND a.id = ?";
    $offerings_params[] = $session_id;
}

$offerings_sql .= " ORDER BY a.start_date DESC, c.code ASC";
$stmt = $pdo->prepare($offerings_sql);
$stmt->execute($offerings_params);
$course_offerings = $stmt->fetchAll();

// Get statistics
$total_templates = count($course_templates);
$total_offerings = count($course_offerings);
$total_lecturers = $pdo->query("SELECT COUNT(*) FROM staff")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Course Management - University Portal</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f5f7fb;
            padding: 16px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 24px;
            color: #2d3748;
            margin-bottom: 8px;
        }
        
        .header p {
            color: #718096;
            font-size: 14px;
        }
        
        .info-banner {
            background: #e9f5ff;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }
        
        .info-banner p {
            margin: 5px 0;
            font-size: 14px;
        }
        
        .stats-bar {
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
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #667eea;
        }
        
        .stat-label {
            font-size: 12px;
            color: #718096;
            margin-top: 5px;
        }
        
        .view-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .view-tab {
            padding: 10px 20px;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .view-tab.active {
            background: #667eea;
            border-color: #667eea;
            color: white;
        }
        
        .action-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
            align-items: center;
            justify-content: space-between;
        }
        
        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5a67d8;
            transform: translateY(-1px);
        }
        
        .btn-success {
            background: #48bb78;
            color: white;
        }
        
        .btn-danger {
            background: #f56565;
            color: white;
        }
        
        .btn-warning {
            background: #ed8936;
            color: white;
        }
        
        .btn-info {
            background: #4299e1;
            color: white;
        }
        
        .btn-secondary {
            background: #a0aec0;
            color: white;
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .filter-bar {
            background: white;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 150px;
        }
        
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
            font-size: 14px;
        }
        
        .courses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 20px;
        }
        
        .course-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .course-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 16px;
            position: relative;
        }
        
        .card-header.inactive {
            background: linear-gradient(135deg, #a0aec0 0%, #718096 100%);
        }
        
        .card-header.offering {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
        }
        
        .course-code {
            font-size: 14px;
            font-family: monospace;
            opacity: 0.9;
            margin-bottom: 4px;
        }
        
        .course-title {
            font-size: 18px;
            font-weight: 600;
        }
        
        .card-body {
            padding: 16px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .info-label {
            font-size: 13px;
            color: #718096;
        }
        
        .info-value {
            font-size: 14px;
            font-weight: 500;
            color: #2d3748;
        }
        
        .credit-unit {
            display: inline-block;
            background: #e9f5ff;
            color: #2c5282;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .badge-active {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .badge-inactive {
            background: #fed7d7;
            color: #c53030;
        }
        
        .badge-elective {
            background: #feebc8;
            color: #7c2d12;
        }
        
        .badge-core {
            background: #bee3f8;
            color: #2c5282;
        }
        
        .badge-offered {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .card-actions {
            display: flex;
            gap: 8px;
            padding: 16px;
            background: #f7fafc;
            flex-wrap: wrap;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 24px;
            max-width: 600px;
            width: 90%;
            max-height: 85%;
            overflow-y: auto;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
            color: #2d3748;
        }
        
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .checkbox-group input {
            width: auto;
            width: 20px;
            height: 20px;
        }
        
        .flash-message {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 8px;
            color: white;
            z-index: 2000;
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .flash-success { background: #48bb78; }
        .flash-error { background: #f56565; }
        
        @media (max-width: 640px) {
            .courses-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-form {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
            
            .action-bar {
                flex-direction: column;
            }
            
            .view-tabs {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h1>📖 Course Management</h1>
                    <p>Manage course templates and semester offerings</p>
                </div>
                <a href="index.php" style="color: #667eea; text-decoration: none;">← Back to Dashboard</a>
            </div>
        </div>
        
        <!-- Info Banner -->
        <div class="info-banner">
            <p>💡 <strong>How it works:</strong></p>
            <p>• <strong>Course Templates</strong> are permanent course definitions (created once).</p>
            <p>• <strong>Course Offerings</strong> link templates to specific semesters (create once per semester).</p>
            <p>• You don't need to recreate courses every semester - just create an offering for existing courses!</p>
        </div>
        
        <!-- Stats -->
        <div class="stats-bar">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_templates; ?></div>
                <div class="stat-label">Course Templates</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_offerings; ?></div>
                <div class="stat-label">Active Offerings</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_lecturers; ?></div>
                <div class="stat-label">Lecturers</div>
            </div>
        </div>
        
        <!-- View Tabs -->
        <div class="view-tabs">
            <button class="view-tab <?php echo $view == 'offerings' ? 'active' : ''; ?>" onclick="switchView('offerings')">
                📅 Current Offerings (<?php echo $total_offerings; ?>)
            </button>
            <button class="view-tab <?php echo $view == 'templates' ? 'active' : ''; ?>" onclick="switchView('templates')">
                📚 Course Templates (<?php echo $total_templates; ?>)
            </button>
        </div>
        
        <!-- Action Bar -->
        <div class="action-bar">
            <?php if($view == 'templates'): ?>
                <button onclick="openTemplateModal()" class="btn btn-primary">➕ Add Course Template</button>
                <button onclick="openBulkUploadModal()" class="btn btn-success">📤 Bulk Upload Templates</button>
            <?php else: ?>
                <button onclick="openOfferingModal()" class="btn btn-primary">🎓 Offer Course for Semester</button>
                <button onclick="copyPreviousOfferings()" class="btn btn-success">📋 Copy from Previous Semester</button>
            <?php endif; ?>
        </div>
        
        <!-- Filter Bar -->
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form">
                <input type="hidden" name="view" value="<?php echo $view; ?>">
                <div class="filter-group">
                    <label>Faculty</label>
                    <select name="faculty" id="faculty_filter" onchange="this.form.submit()">
                        <option value="">All Faculties</option>
                        <?php foreach($faculties as $faculty): ?>
                            <option value="<?php echo $faculty['id']; ?>" <?php echo $faculty_id == $faculty['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($faculty['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Department</label>
                    <select name="department" onchange="this.form.submit()">
                        <option value="">All Departments</option>
                        <?php foreach($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" <?php echo $department_id == $dept['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Level</label>
                    <select name="level" onchange="this.form.submit()">
                        <option value="">All Levels</option>
                        <option value="100" <?php echo $level == '100' ? 'selected' : ''; ?>>100 Level</option>
                        <option value="200" <?php echo $level == '200' ? 'selected' : ''; ?>>200 Level</option>
                        <option value="300" <?php echo $level == '300' ? 'selected' : ''; ?>>300 Level</option>
                        <option value="400" <?php echo $level == '400' ? 'selected' : ''; ?>>400 Level</option>
                        <option value="500" <?php echo $level == '500' ? 'selected' : ''; ?>>500 Level</option>
                    </select>
                </div>
                <?php if($view == 'offerings'): ?>
                    <div class="filter-group">
                        <label>Academic Session</label>
                        <select name="session" onchange="this.form.submit()">
                            <option value="">All Sessions</option>
                            <?php foreach($sessions as $session): ?>
                                <option value="<?php echo $session['id']; ?>" <?php echo $session_id == $session['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($session['name']); ?>
                                    <?php echo $session['is_current'] ? '(Current)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <?php if($faculty_id || $department_id || $level || $session_id): ?>
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <a href="courses.php?view=<?php echo $view; ?>" class="btn btn-small" style="background: #e2e8f0; text-align: center;">Clear Filters</a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
        
        <?php if($view == 'templates'): ?>
            <!-- Course Templates View -->
            <div class="courses-grid">
                <?php foreach($course_templates as $course): ?>
                    <div class="course-card">
                        <div class="card-header <?php echo !($course['is_active'] ?? 1) ? 'inactive' : ''; ?>">
                            <div class="course-code"><?php echo htmlspecialchars($course['code']); ?></div>
                            <div class="course-title"><?php echo htmlspecialchars($course['title']); ?></div>
                        </div>
                        <div class="card-body">
                            <div class="info-row">
                                <span class="info-label">🏛️ Department</span>
                                <span class="info-value"><?php echo htmlspecialchars($course['department_name']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">📊 Level</span>
                                <span class="info-value"><?php echo $course['level']; ?> Level</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">🎓 Credit Unit</span>
                                <span class="info-value"><span class="credit-unit"><?php echo $course['credit_unit']; ?> Units</span></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Type</span>
                                <span class="info-value">
                                    <?php if($course['is_elective']): ?>
                                        <span class="badge badge-elective">Elective</span>
                                    <?php else: ?>
                                        <span class="badge badge-core">Core Course</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Offerings</span>
                                <span class="info-value"><span class="badge badge-offered"><?php echo $course['offering_count']; ?> Semesters</span></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Status</span>
                                <span class="info-value">
                                    <?php if($course['is_active'] ?? 1): ?>
                                        <span class="badge badge-active">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-inactive">Inactive</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        <div class="card-actions">
                            <button onclick="editTemplate(<?php echo $course['id']; ?>)" class="btn btn-primary btn-small">✏️ Edit</button>
                            <button onclick="offerCourse(<?php echo $course['id']; ?>)" class="btn btn-success btn-small">🎓 Offer for Semester</button>
                            <a href="?toggle_template=<?php echo $course['id']; ?>&view=templates" class="btn btn-warning btn-small" onclick="return confirm('Toggle course status?')">
                                <?php echo ($course['is_active'] ?? 1) ? '🔴 Deactivate' : '🟢 Activate'; ?>
                            </a>
                            <a href="?delete_template=<?php echo $course['id']; ?>&view=templates" class="btn btn-danger btn-small" onclick="return confirm('Delete this course template?')">🗑️ Delete</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <!-- Course Offerings View -->
            <div class="courses-grid">
                <?php foreach($course_offerings as $offering): ?>
                    <div class="course-card">
                        <div class="card-header offering">
                            <div class="course-code"><?php echo htmlspecialchars($offering['code']); ?></div>
                            <div class="course-title"><?php echo htmlspecialchars($offering['title']); ?></div>
                        </div>
                        <div class="card-body">
                            <div class="info-row">
                                <span class="info-label">🏛️ Department</span>
                                <span class="info-value"><?php echo htmlspecialchars($offering['department_name']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">👨‍🏫 Lecturer</span>
                                <span class="info-value"><?php echo $offering['lecturer_name'] ?: 'Not Assigned'; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">📅 Session</span>
                                <span class="info-value"><?php echo $offering['session_name']; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">📖 Semester</span>
                                <span class="info-value"><?php echo $offering['semester_name']; ?> Semester</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">📊 Level</span>
                                <span class="info-value"><?php echo $offering['level']; ?> Level</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">🎓 Credit Unit</span>
                                <span class="info-value"><span class="credit-unit"><?php echo $offering['credit_unit']; ?> Units</span></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Type</span>
                                <span class="info-value">
                                    <?php if($offering['is_elective']): ?>
                                        <span class="badge badge-elective">Elective</span>
                                    <?php else: ?>
                                        <span class="badge badge-core">Core Course</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        <div class="card-actions">
                            <button onclick="editOffering(<?php echo $offering['id']; ?>)" class="btn btn-primary btn-small">✏️ Edit Offering</button>
                            <a href="?delete_offering=<?php echo $offering['id']; ?>&view=offerings" class="btn btn-danger btn-small" onclick="return confirm('Remove this course offering?')">🗑️ Remove</a>
                            <button onclick="viewEnrolledStudents(<?php echo $offering['course_id']; ?>)" class="btn btn-info btn-small">👨‍🎓 Enrolled</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if($view == 'templates' && empty($course_templates)): ?>
            <div style="text-align: center; padding: 60px; background: white; border-radius: 12px;">
                <p style="color: #718096;">No course templates found. Click "Add Course Template" to get started.</p>
            </div>
        <?php endif; ?>
        
        <?php if($view == 'offerings' && empty($course_offerings)): ?>
            <div style="text-align: center; padding: 60px; background: white; border-radius: 12px;">
                <p style="color: #718096;">No course offerings found for the selected filters.</p>
                <button onclick="openOfferingModal()" class="btn btn-primary" style="margin-top: 20px;">🎓 Offer a Course</button>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Add/Edit Course Template Modal -->
    <div id="templateModal" class="modal">
        <div class="modal-content">
            <h2 id="templateModalTitle" style="margin-bottom: 20px;">Add Course Template</h2>
            <form id="templateForm" method="POST" action="save_course_template.php">
                <input type="hidden" name="course_id" id="course_id">
                
                <div class="form-group">
                    <label>Course Code *</label>
                    <input type="text" name="code" id="code" required placeholder="e.g., CSC101">
                </div>
                
                <div class="form-group">
                    <label>Course Title *</label>
                    <input type="text" name="title" id="title" required placeholder="e.g., Introduction to Programming">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Credit Unit *</label>
                        <input type="number" name="credit_unit" id="credit_unit" required min="1" max="6" value="3">
                    </div>
                    <div class="form-group">
                        <label>Level *</label>
                        <select name="level" id="level" required>
                            <option value="100">100 Level</option>
                            <option value="200">200 Level</option>
                            <option value="300">300 Level</option>
                            <option value="400">400 Level</option>
                            <option value="500">500 Level</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Department *</label>
                    <select name="department_id" id="department_id" required>
                        <option value="">Select Department</option>
                        <?php foreach($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" name="is_elective" id="is_elective" value="1">
                        <label for="is_elective">This is an Elective Course</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" name="is_active" id="is_active" value="1" checked>
                        <label for="is_active">Course is Active</label>
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn btn-primary">Save Template</button>
                    <button type="button" onclick="closeTemplateModal()" class="btn" style="background: #e2e8f0;">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Offer Course Modal -->
    <div id="offeringModal" class="modal">
        <div class="modal-content">
            <h2 style="margin-bottom: 20px;">Offer Course for Semester</h2>
            <form method="POST" action="save_course_offering.php">
                <div class="form-group">
                    <label>Select Course Template *</label>
                    <select name="course_id" id="offer_course_id" required>
                        <option value="">Select Course</option>
                        <?php foreach($course_templates as $course): ?>
                            <option value="<?php echo $course['id']; ?>">
                                <?php echo htmlspecialchars($course['code'] . ' - ' . $course['title'] . ' (' . $course['level'] . ' Level)'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Academic Session *</label>
                    <select name="session_id" id="session_id" required onchange="loadSemesters(this.value)">
                        <option value="">Select Session</option>
                        <?php foreach($sessions as $session): ?>
                            <option value="<?php echo $session['id']; ?>" <?php echo ($session['is_current'] ?? 0) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($session['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Semester *</label>
                    <select name="semester_id" id="semester_id" required>
                        <option value="">Select Semester</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Assign Lecturer (Optional)</label>
                    <select name="lecturer_id">
                        <option value="">Select Lecturer</option>
                        <?php
                        $lecturers = $pdo->query("
                            SELECT u.id, u.first_name, u.last_name, s.staff_number 
                            FROM staff s 
                            JOIN users u ON s.user_id = u.id 
                            ORDER BY u.last_name
                        ")->fetchAll();
                        foreach($lecturers as $lect):
                        ?>
                            <option value="<?php echo $lect['id']; ?>">
                                <?php echo htmlspecialchars($lect['first_name'] . ' ' . $lect['last_name'] . ' (' . $lect['staff_number'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Maximum Students (Optional)</label>
                    <input type="number" name="max_students" placeholder="Leave empty for unlimited">
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn btn-primary">Create Offering</button>
                    <button type="button" onclick="closeOfferingModal()" class="btn" style="background: #e2e8f0;">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        let currentView = '<?php echo $view; ?>';
        
        function switchView(view) {
            window.location.href = 'courses.php?view=' + view + 
                '<?php echo $faculty_id ? "&faculty=" . $faculty_id : ""; ?>' +
                '<?php echo $department_id ? "&department=" . $department_id : ""; ?>' +
                '<?php echo $level ? "&level=" . $level : ""; ?>';
        }
        
        function openTemplateModal() {
            document.getElementById('templateModalTitle').innerText = 'Add Course Template';
            document.getElementById('templateForm').reset();
            document.getElementById('course_id').value = '';
            document.getElementById('is_active').checked = true;
            document.getElementById('templateModal').style.display = 'flex';
        }
        
        function closeTemplateModal() {
            document.getElementById('templateModal').style.display = 'none';
        }
        
        function editTemplate(id) {
            fetch('get_course_template.php?id=' + id)
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        document.getElementById('templateModalTitle').innerText = 'Edit Course Template';
                        document.getElementById('course_id').value = data.course.id;
                        document.getElementById('code').value = data.course.code;
                        document.getElementById('title').value = data.course.title;
                        document.getElementById('credit_unit').value = data.course.credit_unit;
                        document.getElementById('level').value = data.course.level;
                        document.getElementById('department_id').value = data.course.department_id;
                        document.getElementById('is_elective').checked = data.course.is_elective == 1;
                        document.getElementById('is_active').checked = data.course.is_active == 1;
                        document.getElementById('templateModal').style.display = 'flex';
                    } else {
                        alert('Error loading course data');
                    }
                })
                .catch(error => {
                    alert('Error: ' + error);
                });
        }
        
        function openOfferingModal() {
            document.getElementById('offeringModal').style.display = 'flex';
            // Load semesters for current session
            const sessionSelect = document.getElementById('session_id');
            if(sessionSelect.value) {
                loadSemesters(sessionSelect.value);
            }
        }
        
        function closeOfferingModal() {
            document.getElementById('offeringModal').style.display = 'none';
        }
        
        function offerCourse(courseId) {
            document.getElementById('offer_course_id').value = courseId;
            openOfferingModal();
        }
        
        function editOffering(id) {
            window.location.href = 'edit_course_offering.php?id=' + id;
        }
        
        function loadSemesters(sessionId) {
            if(sessionId) {
                fetch('get_semesters_by_session.php?session_id=' + sessionId)
                    .then(response => response.json())
                    .then(data => {
                        const semesterSelect = document.getElementById('semester_id');
                        semesterSelect.innerHTML = '<option value="">Select Semester</option>';
                        data.forEach(semester => {
                            semesterSelect.innerHTML += `<option value="${semester.id}">${semester.name} Semester</option>`;
                        });
                    });
            }
        }
        
        function copyPreviousOfferings() {
            if(confirm('Copy course offerings from the previous semester? This will duplicate all offerings from the last semester.')) {
                window.location.href = 'copy_course_offerings.php';
            }
        }
        
        function viewEnrolledStudents(courseId) {
            window.open('course_enrollment.php?course_id=' + courseId, '_blank');
        }
        
        window.onclick = function(event) {
            const templateModal = document.getElementById('templateModal');
            const offeringModal = document.getElementById('offeringModal');
            if(event.target === templateModal) closeTemplateModal();
            if(event.target === offeringModal) closeOfferingModal();
        }
        
        // Show flash message
        const urlParams = new URLSearchParams(window.location.search);
        const message = urlParams.get('message');
        const error = urlParams.get('error');
        
        if(message) {
            const flash = document.createElement('div');
            flash.className = 'flash-message flash-success';
            flash.innerHTML = message;
            document.body.appendChild(flash);
            setTimeout(() => flash.remove(), 3000);
        }
        if(error) {
            const flash = document.createElement('div');
            flash.className = 'flash-message flash-error';
            flash.innerHTML = error;
            document.body.appendChild(flash);
            setTimeout(() => flash.remove(), 3000);
        }
    </script>
</body>
</html>
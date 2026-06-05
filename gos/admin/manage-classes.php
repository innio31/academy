<?php
// admin/manage-classes.php - Class Management
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /gos/login.php");
    exit();
}

if (isset($_SESSION['admin_id'])) {
    $admin_id = $_SESSION['admin_id'];
    $admin_name = $_SESSION['admin_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'super_admin';
} else {
    $admin_id = $_SESSION['user_id'];
    $admin_name = $_SESSION['user_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

if ($admin_role !== 'super_admin' && $admin_role !== 'admin') {
    header("Location: index.php?message=Access denied&type=error");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$page_title = "Manage Classes";

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $class_id = $_POST['class_id'] ?? 0;

    if ($action === 'add_class') {
        $class_name = trim($_POST['class_name']);
        $class_code = trim($_POST['class_code']);
        $class_category = $_POST['class_category'];
        $sort_order = (int)$_POST['sort_order'];

        try {
            $stmt = $pdo->prepare("INSERT INTO classes (school_id, class_name, class_code, class_category, sort_order) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$school_id, $class_name, $class_code, $class_category, $sort_order]);
            $_SESSION['message'] = "Class added successfully";
            $_SESSION['message_type'] = "success";
        } catch (PDOException $e) {
            $_SESSION['message'] = "Error: " . $e->getMessage();
            $_SESSION['message_type'] = "error";
        }
    } elseif ($action === 'update_class') {
        $class_name = trim($_POST['class_name']);
        $class_code = trim($_POST['class_code']);
        $class_category = $_POST['class_category'];
        $sort_order = (int)$_POST['sort_order'];
        $status = $_POST['status'];

        try {
            $stmt = $pdo->prepare("UPDATE classes SET class_name = ?, class_code = ?, class_category = ?, sort_order = ?, status = ? WHERE id = ? AND school_id = ?");
            $stmt->execute([$class_name, $class_code, $class_category, $sort_order, $status, $class_id, $school_id]);
            $_SESSION['message'] = "Class updated successfully";
            $_SESSION['message_type'] = "success";
        } catch (PDOException $e) {
            $_SESSION['message'] = "Error: " . $e->getMessage();
            $_SESSION['message_type'] = "error";
        }
    } elseif ($action === 'delete_class') {
        // Check if class has students
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE class_id = ? AND school_id = ?");
        $stmt->execute([$class_id, $school_id]);
        $student_count = $stmt->fetchColumn();

        if ($student_count > 0) {
            $_SESSION['message'] = "Cannot delete class with $student_count student(s). Transfer students first.";
            $_SESSION['message_type'] = "error";
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM classes WHERE id = ? AND school_id = ?");
                $stmt->execute([$class_id, $school_id]);
                $_SESSION['message'] = "Class deleted successfully";
                $_SESSION['message_type'] = "success";
            } catch (PDOException $e) {
                $_SESSION['message'] = "Error: " . $e->getMessage();
                $_SESSION['message_type'] = "error";
            }
        }
    }

    header("Location: manage-classes.php");
    exit();
}

// Get all classes
$classes = $pdo->prepare("SELECT * FROM classes WHERE school_id = ? ORDER BY sort_order, class_name");
$classes->execute([$school_id]);
$classes = $classes->fetchAll();

// Get message from session
$message = $_SESSION['message'] ?? '';
$message_type = $_SESSION['message_type'] ?? '';
unset($_SESSION['message'], $_SESSION['message_type']);

// Include sidebar
require_once 'includes/sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo $school_name; ?> - Manage Classes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: #d4af7a;
            --success-color: #27ae60;
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            color: #333;
            min-height: 100vh;
        }

        /* Main Content - pushed by sidebar */
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        /* Top Header */
        .top-header {
            background: white;
            padding: 15px 25px;
            border-radius: 10px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .top-header h1 {
            font-size: 1.5rem;
            color: var(--primary-color);
        }

        .top-header p {
            color: #666;
            font-size: 0.8rem;
            margin-top: 4px;
        }

        /* Buttons */
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: #1a5a8a;
            transform: translateY(-2px);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-small {
            padding: 5px 12px;
            font-size: 0.75rem;
        }

        /* Classes Grid */
        .classes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .class-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .class-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
        }

        .class-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-color);
        }

        .class-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        .class-code {
            background: var(--light-color);
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .class-details {
            margin: 15px 0;
        }

        .class-details p {
            margin: 8px 0;
            color: #666;
            font-size: 0.85rem;
        }

        .class-details i {
            width: 20px;
            color: var(--primary-color);
        }

        .class-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-active {
            background: #d5f4e6;
            color: var(--success-color);
        }

        .status-inactive {
            background: #f8d7da;
            color: var(--danger-color);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
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
            padding: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1.2rem;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #999;
        }

        .close-modal:hover {
            color: #333;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 20px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        /* Form */
        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            font-size: 0.85rem;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.85rem;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        select.form-control {
            cursor: pointer;
        }

        /* Alert */
        .alert {
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d5f4e6;
            color: #155724;
            border-left: 4px solid var(--success-color);
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger-color);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }

            .classes-grid {
                grid-template-columns: 1fr;
            }

            .top-header {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>

<body>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div>
                <h1><i class="fas fa-chalkboard"></i> Manage Classes</h1>
                <p>Add, edit, or delete classes for your school</p>
            </div>
            <button class="btn btn-primary" onclick="openAddModal()">
                <i class="fas fa-plus"></i> Add Class
            </button>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if (empty($classes)): ?>
            <div class="alert alert-info" style="background: #eaf6ff; color: #0c5460; border-left-color: #3498db;">
                <i class="fas fa-info-circle"></i>
                No classes found. Click "Add Class" to create your first class.
            </div>
        <?php else: ?>
            <div class="classes-grid">
                <?php foreach ($classes as $class): ?>
                    <div class="class-card">
                        <div class="class-header">
                            <span class="class-name"><?php echo htmlspecialchars($class['class_name']); ?></span>
                            <span class="class-code"><?php echo htmlspecialchars($class['class_code']); ?></span>
                        </div>
                        <div class="class-details">
                            <p><i class="fas fa-tag"></i> Category: <?php echo htmlspecialchars($class['class_category']); ?></p>
                            <p><i class="fas fa-sort-numeric-down"></i> Sort Order: <?php echo $class['sort_order']; ?></p>
                            <p>
                                <span class="status-badge status-<?php echo $class['status']; ?>">
                                    <?php echo ucfirst($class['status']); ?>
                                </span>
                            </p>
                        </div>
                        <div class="class-actions">
                            <button class="btn btn-warning btn-small" onclick="editClass(<?php echo htmlspecialchars(json_encode($class)); ?>)">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <button class="btn btn-danger btn-small" onclick="deleteClass(<?php echo $class['id']; ?>, '<?php echo htmlspecialchars($class['class_name']); ?>')">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add/Edit Modal -->
    <div class="modal" id="classModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Add New Class</h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" id="classForm">
                <input type="hidden" name="action" id="formAction" value="add_class">
                <input type="hidden" name="class_id" id="classId" value="0">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Class Name *</label>
                        <input type="text" name="class_name" id="className" class="form-control" required placeholder="e.g., Grade 10A, JSS 1">
                    </div>
                    <div class="form-group">
                        <label>Class Code</label>
                        <input type="text" name="class_code" id="classCode" class="form-control" placeholder="e.g., JSS1, SS2, 10A">
                        <small style="color: #666; font-size: 0.7rem;">Optional short identifier for the class</small>
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <select name="class_category" id="classCategory" class="form-control">
                            <option value="Nursery">Nursery</option>
                            <option value="Primary">Primary</option>
                            <option value="Junior Secondary">Junior Secondary</option>
                            <option value="Senior Secondary">Senior Secondary</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Sort Order</label>
                        <input type="number" name="sort_order" id="sortOrder" class="form-control" value="0">
                        <small style="color: #666; font-size: 0.7rem;">Lower numbers appear first</small>
                    </div>
                    <div class="form-group" id="statusField" style="display:none;">
                        <label>Status</label>
                        <select name="status" id="classStatus" class="form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-warning" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Class</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAddModal() {
            document.getElementById('modalTitle').innerText = 'Add New Class';
            document.getElementById('formAction').value = 'add_class';
            document.getElementById('classId').value = '0';
            document.getElementById('className').value = '';
            document.getElementById('classCode').value = '';
            document.getElementById('classCategory').value = 'Primary';
            document.getElementById('sortOrder').value = '0';
            document.getElementById('statusField').style.display = 'none';
            document.getElementById('classModal').style.display = 'flex';
        }

        function editClass(classData) {
            document.getElementById('modalTitle').innerText = 'Edit Class';
            document.getElementById('formAction').value = 'update_class';
            document.getElementById('classId').value = classData.id;
            document.getElementById('className').value = classData.class_name;
            document.getElementById('classCode').value = classData.class_code || '';
            document.getElementById('classCategory').value = classData.class_category;
            document.getElementById('sortOrder').value = classData.sort_order;
            document.getElementById('classStatus').value = classData.status;
            document.getElementById('statusField').style.display = 'block';
            document.getElementById('classModal').style.display = 'flex';
        }

        function deleteClass(id, name) {
            if (confirm(`Delete class "${name}"? This cannot be undone if students are assigned to this class.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_class">
                    <input type="hidden" name="class_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function closeModal() {
            document.getElementById('classModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('classModal');
            if (event.target === modal) {
                closeModal();
            }
        };

        // Enter key submits form
        document.getElementById('classForm')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
                e.preventDefault();
                this.submit();
            }
        });
    </script>
</body>

</html>
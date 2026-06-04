<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

// Handle delete
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM departments WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: departments.php?message=Department deleted");
    exit();
}

$faculty_filter = $_GET['faculty_id'] ?? '';

// Get all faculties for dropdown
$faculties = $pdo->query("SELECT id, name FROM faculties ORDER BY name")->fetchAll();

// Get all staff members for HOD assignment dropdown
$staff_list = $pdo->query("
    SELECT s.id, s.staff_number, s.designation, 
           CONCAT(u.first_name, ' ', u.last_name, ' (', u.email, ')') as full_name,
           d.name as department_name,
           u.id as user_id
    FROM staff s
    JOIN users u ON s.user_id = u.id
    LEFT JOIN departments d ON s.department_id = d.id
    WHERE u.is_active = 1
    ORDER BY u.first_name, u.last_name
")->fetchAll();

// Get departments with faculty info and HOD details
$sql = "SELECT d.*, f.name as faculty_name, f.code as faculty_code,
        CONCAT(u.first_name, ' ', u.last_name) as hod_name,
        u.id as hod_user_id,
        (SELECT COUNT(*) FROM courses WHERE department_id = d.id) as course_count
        FROM departments d
        JOIN faculties f ON d.faculty_id = f.id
        LEFT JOIN users u ON d.hod_id = u.id";
if ($faculty_filter) {
    $sql .= " WHERE d.faculty_id = " . intval($faculty_filter);
}
$sql .= " ORDER BY f.name, d.name";
$departments = $pdo->query($sql)->fetchAll();

// Create an associative array of department data for JavaScript
$dept_data = [];
foreach($departments as $dept) {
    $dept_data[$dept['id']] = [
        'name' => $dept['name'],
        'faculty_name' => $dept['faculty_name'],
        'hod_name' => $dept['hod_name'] ?: 'Not Assigned',
        'hod_user_id' => $dept['hod_user_id'] ?: ''
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Department Management - University Portal</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fb;
            padding: 16px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .header {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 24px; color: #2d3748; margin-bottom: 8px; }
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
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5a67d8; }
        .btn-secondary { background: #48bb78; color: white; }
        .btn-secondary:hover { background: #38a169; }
        .btn-danger { background: #f56565; color: white; }
        .btn-danger:hover { background: #e53e3e; }
        .btn-warning { background: #ed8936; color: white; }
        .btn-warning:hover { background: #dd6b20; }
        .btn-small { padding: 6px 12px; font-size: 12px; }
        .filter-bar {
            background: white;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .departments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        .dept-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        .dept-card:hover { transform: translateY(-2px); }
        .card-header {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
            padding: 16px;
        }
        .dept-name { font-size: 18px; font-weight: 600; margin-bottom: 4px; }
        .dept-code { font-size: 12px; opacity: 0.9; font-family: monospace; }
        .card-body { padding: 16px; }
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
        }
        .hod-info {
            background: #ebf8ff;
            padding: 8px 12px;
            border-radius: 8px;
            margin-top: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .hod-name { color: #2b6cb0; font-weight: 600; }
        .card-actions { display: flex; gap: 8px; padding: 16px; background: #f7fafc; flex-wrap: wrap; }
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
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 14px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }
        .staff-item {
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .staff-item:hover {
            background: #f7fafc;
            border-color: #667eea;
        }
        .staff-item.selected {
            background: #ebf8ff;
            border-color: #667eea;
            border-width: 2px;
        }
        .staff-name { font-weight: 600; }
        .staff-details { font-size: 12px; color: #718096; margin-top: 4px; }
        @media (max-width: 640px) {
            .departments-grid { grid-template-columns: 1fr; }
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
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-assigned { background: #c6f6d5; color: #22543d; }
        .badge-unassigned { background: #fed7d7; color: #742a2a; }
        .no-staff {
            text-align: center;
            padding: 20px;
            color: #718096;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h1>📚 Department Management</h1>
                    <p>Manage academic departments and assign HODs</p>
                </div>
                <div>
                    <a href="index.php" style="color: #667eea; text-decoration: none; margin-right: 16px;">← Dashboard</a>
                    <a href="faculties.php" style="color: #667eea; text-decoration: none; margin-right: 16px;">🏛️ Faculties</a>
                    <button onclick="openModal()" class="btn btn-primary">➕ Add Department</button>
                </div>
            </div>
        </div>
        
        <div class="filter-bar">
            <form method="GET" action="" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                <select name="faculty_id" onchange="this.form.submit()" style="padding: 10px; border-radius: 8px; border: 1px solid #e2e8f0;">
                    <option value="">All Faculties</option>
                    <?php foreach($faculties as $faculty): ?>
                        <option value="<?php echo $faculty['id']; ?>" <?php echo $faculty_filter == $faculty['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($faculty['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if($faculty_filter): ?>
                    <a href="departments.php" class="btn btn-small" style="background: #e2e8f0;">Clear Filter</a>
                <?php endif; ?>
            </form>
        </div>
        
        <div class="departments-grid">
            <?php foreach($departments as $dept): ?>
                <div class="dept-card" data-dept-id="<?php echo $dept['id']; ?>">
                    <div class="card-header">
                        <div class="dept-name"><?php echo htmlspecialchars($dept['name']); ?></div>
                        <div class="dept-code">Code: <?php echo htmlspecialchars($dept['code']); ?></div>
                    </div>
                    <div class="card-body">
                        <div class="info-row">
                            <span>🏛️ Faculty</span>
                            <strong><?php echo htmlspecialchars($dept['faculty_name']); ?></strong>
                        </div>
                        <div class="info-row">
                            <span>📖 Courses</span>
                            <strong><?php echo $dept['course_count']; ?></strong>
                        </div>
                        <div class="hod-info">
                            <span>👨‍🏫 Head of Department (HOD):</span>
                            <span class="hod-name">
                                <?php echo $dept['hod_name'] ?: 'Not Assigned'; ?>
                                <?php if($dept['hod_name']): ?>
                                    <span class="badge badge-assigned">Assigned</span>
                                <?php else: ?>
                                    <span class="badge badge-unassigned">Unassigned</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-actions">
                        <a href="courses.php?department_id=<?php echo $dept['id']; ?>" class="btn btn-primary btn-small">📖 View Courses</a>
                        <button onclick="openHODModal(<?php echo $dept['id']; ?>)" class="btn btn-secondary btn-small">👨‍🏫 Assign HOD</button>
                        <?php if($dept['hod_name']): ?>
                            <a href="remove_hod.php?department_id=<?php echo $dept['id']; ?>" onclick="return confirm('Remove HOD from this department?')" class="btn btn-warning btn-small">❌ Remove HOD</a>
                        <?php endif; ?>
                        <a href="?delete=<?php echo $dept['id']; ?>" onclick="return confirm('Delete this department? All courses and students will be affected!')" class="btn btn-danger btn-small">🗑️ Delete</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if(empty($departments)): ?>
            <div style="text-align: center; padding: 60px; background: white; border-radius: 12px;">
                <p>No departments found. Click "Add Department" to get started.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Add Department Modal -->
    <div id="deptModal" class="modal">
        <div class="modal-content">
            <h2 style="margin-bottom: 20px;">Add New Department</h2>
            <form method="POST" action="save_department.php">
                <div class="form-group">
                    <label>Faculty *</label>
                    <select name="faculty_id" required>
                        <option value="">Select Faculty</option>
                        <?php foreach($faculties as $faculty): ?>
                            <option value="<?php echo $faculty['id']; ?>"><?php echo htmlspecialchars($faculty['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Department Name *</label>
                    <input type="text" name="name" required>
                </div>
                <div class="form-group">
                    <label>Department Code *</label>
                    <input type="text" name="code" required placeholder="e.g., CSC, ENG, BCH">
                    <small style="color: #718096;">This will be used in student registration numbers</small>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary">Save Department</button>
                    <button type="button" onclick="closeModal()" class="btn" style="background: #e2e8f0;">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Assign HOD Modal -->
    <div id="hodModal" class="modal">
        <div class="modal-content">
            <h2 style="margin-bottom: 20px;">Assign Head of Department (HOD)</h2>
            <form id="hodForm" method="POST" action="assign_hod.php">
                <input type="hidden" name="department_id" id="department_id">
                <div class="form-group">
                    <label>Department</label>
                    <input type="text" id="department_name" readonly style="background: #f7fafc;">
                </div>
                <div class="form-group">
                    <label>Faculty</label>
                    <input type="text" id="faculty_name" readonly style="background: #f7fafc;">
                </div>
                <div class="form-group">
                    <label>Current HOD</label>
                    <input type="text" id="current_hod" readonly style="background: #f7fafc;">
                </div>
                <div class="form-group">
                    <label>Select New HOD (Staff Member)</label>
                    <div id="staffList" style="max-height: 300px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px;">
                        <?php if(count($staff_list) > 0): ?>
                            <?php foreach($staff_list as $staff): ?>
                                <div class="staff-item" data-staff-id="<?php echo $staff['id']; ?>" data-user-id="<?php echo $staff['user_id']; ?>" onclick="selectStaff(this, <?php echo $staff['id']; ?>, <?php echo $staff['user_id']; ?>)">
                                    <div class="staff-name"><?php echo htmlspecialchars($staff['full_name']); ?></div>
                                    <div class="staff-details">
                                        Staff No: <?php echo htmlspecialchars($staff['staff_number']); ?> | 
                                        Dept: <?php echo htmlspecialchars($staff['department_name'] ?: 'Not Assigned'); ?>
                                        <?php if($staff['designation']): ?>
                                            | <?php echo htmlspecialchars($staff['designation']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-staff">
                                No staff members found. Please add staff members first.
                            </div>
                        <?php endif; ?>
                    </div>
                    <input type="hidden" name="staff_id" id="selected_staff_id">
                </div>
                <div class="form-group">
                    <label>Or assign by User ID (Alternative)</label>
                    <input type="number" name="user_id" id="user_id_input" placeholder="Enter user ID of staff member" style="width: 100%;">
                    <small style="color: #718096;">You can either select from the list above or enter a user ID directly.</small>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn btn-primary" <?php echo count($staff_list) == 0 ? 'disabled' : ''; ?>>Assign HOD</button>
                    <button type="button" onclick="closeHODModal()" class="btn" style="background: #e2e8f0;">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Store department data
        const deptData = {};
        <?php foreach($dept_data as $id => $data): ?>
            deptData[<?php echo $id; ?>] = {
                name: '<?php echo addslashes($data['name']); ?>',
                facultyName: '<?php echo addslashes($data['faculty_name']); ?>',
                hodName: '<?php echo addslashes($data['hod_name']); ?>',
                hodUserId: '<?php echo $data['hod_user_id']; ?>'
            };
        <?php endforeach; ?>
        
        function openModal() { 
            document.getElementById('deptModal').style.display = 'flex'; 
        }
        
        function closeModal() { 
            document.getElementById('deptModal').style.display = 'none'; 
        }
        
        function openHODModal(departmentId) {
            // Get department data
            const dept = deptData[departmentId];
            if (!dept) {
                console.error('Department not found:', departmentId);
                return;
            }
            
            // Set form values
            document.getElementById('department_id').value = departmentId;
            document.getElementById('department_name').value = dept.name;
            document.getElementById('faculty_name').value = dept.facultyName;
            document.getElementById('current_hod').value = dept.hodName;
            
            // Clear previous selection
            document.querySelectorAll('.staff-item').forEach(item => {
                item.classList.remove('selected');
            });
            document.getElementById('selected_staff_id').value = '';
            document.getElementById('user_id_input').value = '';
            
            // Show modal
            document.getElementById('hodModal').style.display = 'flex';
        }
        
        function selectStaff(element, staffId, userId) {
            // Remove selected class from all staff items
            document.querySelectorAll('.staff-item').forEach(item => {
                item.classList.remove('selected');
            });
            // Add selected class to clicked item
            element.classList.add('selected');
            // Set the selected staff ID
            document.getElementById('selected_staff_id').value = staffId;
            // Clear the user ID input since we're using staff selection
            document.getElementById('user_id_input').value = '';
        }
        
        function closeHODModal() { 
            document.getElementById('hodModal').style.display = 'none';
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const deptModal = document.getElementById('deptModal');
            const hodModal = document.getElementById('hodModal');
            if(event.target === deptModal) closeModal();
            if(event.target === hodModal) closeHODModal();
        }
        
        // Handle user ID input to override staff selection
        const userIdInput = document.getElementById('user_id_input');
        if(userIdInput) {
            userIdInput.addEventListener('input', function() {
                if(this.value) {
                    // Clear staff selection
                    document.querySelectorAll('.staff-item').forEach(item => {
                        item.classList.remove('selected');
                    });
                    document.getElementById('selected_staff_id').value = '';
                }
            });
        }
        
        // Handle form submission to ensure either staff_id or user_id is provided
        const hodForm = document.getElementById('hodForm');
        if(hodForm) {
            hodForm.addEventListener('submit', function(e) {
                const staffId = document.getElementById('selected_staff_id').value;
                const userId = document.getElementById('user_id_input').value;
                
                if (!staffId && !userId) {
                    e.preventDefault();
                    alert('Please select a staff member or enter a user ID');
                    return false;
                }
            });
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
            // Remove message from URL without refreshing
            const newUrl = window.location.pathname + (window.location.search ? '?' + new URLSearchParams(window.location.search).toString().replace(/[&?]message=[^&]*/g, '') : '');
            window.history.replaceState({}, document.title, newUrl);
        }
        if(error) {
            const flash = document.createElement('div');
            flash.className = 'flash-message flash-error';
            flash.innerHTML = error;
            document.body.appendChild(flash);
            setTimeout(() => flash.remove(), 3000);
            const newUrl = window.location.pathname;
            window.history.replaceState({}, document.title, newUrl);
        }
    </script>
</body>
</html>
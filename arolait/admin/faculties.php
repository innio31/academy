<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

// Handle delete
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM faculties WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: faculties.php?message=Faculty deleted");
    exit();
}

// Get all faculties with staff list for dropdown
$faculties = $pdo->query("
    SELECT f.*, 
           COUNT(DISTINCT d.id) as dept_count,
           CONCAT(u.first_name, ' ', u.last_name) as dean_name,
           u.id as dean_user_id
    FROM faculties f
    LEFT JOIN departments d ON f.id = d.faculty_id
    LEFT JOIN users u ON f.dean_id = u.id
    GROUP BY f.id
    ORDER BY f.name
")->fetchAll();

// Get all staff members for assignment dropdown
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

// Create an associative array of staff for JavaScript
$staff_data = [];
foreach($staff_list as $staff) {
    $staff_data[] = [
        'id' => $staff['id'],
        'user_id' => $staff['user_id'],
        'full_name' => $staff['full_name'],
        'staff_number' => $staff['staff_number'],
        'department_name' => $staff['department_name'],
        'designation' => $staff['designation']
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Faculties Management - University Portal</title>
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
        .faculties-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        .faculty-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        .faculty-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 16px;
        }
        .faculty-name { font-size: 18px; font-weight: 600; margin-bottom: 4px; }
        .faculty-code { font-size: 12px; opacity: 0.9; font-family: monospace; }
        .card-body { padding: 16px; }
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
        }
        .dean-info {
            background: #ebf8ff;
            padding: 8px 12px;
            border-radius: 8px;
            margin-top: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .dean-name { color: #2b6cb0; font-weight: 600; }
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
        .form-group input, .form-group textarea, .form-group select {
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
        .current-dean {
            background: #c6f6d5;
            color: #22543d;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        @media (max-width: 640px) {
            .faculties-grid { grid-template-columns: 1fr; }
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
        .no-staff {
            text-align: center;
            padding: 20px;
            color: #718096;
        }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-assigned { background: #c6f6d5; color: #22543d; }
        .badge-unassigned { background: #fed7d7; color: #742a2a; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h1>🏛️ Faculty Management</h1>
                    <p>Manage academic faculties and assign deans</p>
                </div>
                <div>
                    <a href="index.php" style="color: #667eea; text-decoration: none; margin-right: 16px;">← Dashboard</a>
                    <button onclick="openModal()" class="btn btn-primary">➕ Add Faculty</button>
                </div>
            </div>
        </div>
        
        <div class="faculties-grid">
            <?php foreach($faculties as $faculty): ?>
                <div class="faculty-card" data-faculty-id="<?php echo $faculty['id']; ?>" data-faculty-name="<?php echo htmlspecialchars($faculty['name']); ?>" data-dean-user-id="<?php echo $faculty['dean_user_id'] ?: ''; ?>" data-dean-name="<?php echo htmlspecialchars($faculty['dean_name'] ?: 'Not Assigned'); ?>">
                    <div class="card-header">
                        <div class="faculty-name"><?php echo htmlspecialchars($faculty['name']); ?></div>
                        <div class="faculty-code">Code: <?php echo htmlspecialchars($faculty['code']); ?></div>
                    </div>
                    <div class="card-body">
                        <div class="info-row">
                            <span>📚 Departments</span>
                            <strong><?php echo $faculty['dept_count']; ?></strong>
                        </div>
                        <div class="dean-info">
                            <span>👨‍🏫 Dean:</span>
                            <span class="dean-name">
                                <?php echo $faculty['dean_name'] ?: 'Not Assigned'; ?>
                                <?php if($faculty['dean_name']): ?>
                                    <span class="badge badge-assigned">Assigned</span>
                                <?php else: ?>
                                    <span class="badge badge-unassigned">Unassigned</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php if($faculty['description']): ?>
                            <div class="info-row">
                                <span>📝 Description</span>
                                <span style="text-align: right;"><?php echo htmlspecialchars(substr($faculty['description'], 0, 50)); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-actions">
                        <a href="departments.php?faculty_id=<?php echo $faculty['id']; ?>" class="btn btn-primary btn-small">📂 View Departments</a>
                        <button onclick="openDeanModal(<?php echo $faculty['id']; ?>)" class="btn btn-secondary btn-small">👨‍🏫 Assign Dean</button>
                        <?php if($faculty['dean_name']): ?>
                            <a href="remove_dean.php?faculty_id=<?php echo $faculty['id']; ?>" onclick="return confirm('Remove dean from this faculty?')" class="btn btn-warning btn-small">❌ Remove Dean</a>
                        <?php endif; ?>
                        <a href="?delete=<?php echo $faculty['id']; ?>" onclick="return confirm('Delete this faculty? All departments will be deleted!')" class="btn btn-danger btn-small">🗑️ Delete</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Add Faculty Modal -->
    <div id="facultyModal" class="modal">
        <div class="modal-content">
            <h2 style="margin-bottom: 20px;">Add New Faculty</h2>
            <form method="POST" action="save_faculty.php">
                <div class="form-group">
                    <label>Faculty Name *</label>
                    <input type="text" name="name" required>
                </div>
                <div class="form-group">
                    <label>Faculty Code *</label>
                    <input type="text" name="code" required placeholder="e.g., SCI, ENG, ART">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="3"></textarea>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary">Save Faculty</button>
                    <button type="button" onclick="closeModal()" class="btn" style="background: #e2e8f0;">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Assign Dean Modal -->
    <div id="deanModal" class="modal">
        <div class="modal-content">
            <h2 style="margin-bottom: 20px;">Assign Dean to Faculty</h2>
            <form id="deanForm" method="POST" action="assign_dean.php">
                <input type="hidden" name="faculty_id" id="faculty_id">
                <div class="form-group">
                    <label>Faculty</label>
                    <input type="text" id="faculty_name" readonly style="background: #f7fafc;">
                </div>
                <div class="form-group">
                    <label>Current Dean</label>
                    <input type="text" id="current_dean" readonly style="background: #f7fafc;">
                </div>
                <div class="form-group">
                    <label>Select New Dean (Staff Member)</label>
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
                    <input type="hidden" name="staff_id" id="selected_staff_id" required>
                </div>
                <div class="form-group">
                    <label>Or assign by User ID (Alternative)</label>
                    <input type="number" name="user_id" id="user_id_input" placeholder="Enter user ID of staff member" style="width: 100%;">
                    <small style="color: #718096;">You can either select from the list above or enter a user ID directly.</small>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn btn-primary" <?php echo count($staff_list) == 0 ? 'disabled' : ''; ?>>Assign Dean</button>
                    <button type="button" onclick="closeDeanModal()" class="btn" style="background: #e2e8f0;">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Store faculty data
        const facultyData = {};
        <?php foreach($faculties as $faculty): ?>
            facultyData[<?php echo $faculty['id']; ?>] = {
                name: '<?php echo addslashes($faculty['name']); ?>',
                deanName: '<?php echo addslashes($faculty['dean_name'] ?: 'Not Assigned'); ?>',
                deanUserId: '<?php echo $faculty['dean_user_id'] ?: ''; ?>'
            };
        <?php endforeach; ?>
        
        function openModal() { 
            document.getElementById('facultyModal').style.display = 'flex'; 
        }
        
        function closeModal() { 
            document.getElementById('facultyModal').style.display = 'none'; 
        }
        
        function openDeanModal(facultyId) {
            // Get faculty data
            const faculty = facultyData[facultyId];
            if (!faculty) {
                console.error('Faculty not found:', facultyId);
                return;
            }
            
            // Set form values
            document.getElementById('faculty_id').value = facultyId;
            document.getElementById('faculty_name').value = faculty.name;
            document.getElementById('current_dean').value = faculty.deanName;
            
            // Clear previous selection
            document.querySelectorAll('.staff-item').forEach(item => {
                item.classList.remove('selected');
            });
            document.getElementById('selected_staff_id').value = '';
            document.getElementById('user_id_input').value = '';
            
            // Show modal
            document.getElementById('deanModal').style.display = 'flex';
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
        
        function closeDeanModal() { 
            document.getElementById('deanModal').style.display = 'none';
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const facultyModal = document.getElementById('facultyModal');
            const deanModal = document.getElementById('deanModal');
            if(event.target === facultyModal) closeModal();
            if(event.target === deanModal) closeDeanModal();
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
        const deanForm = document.getElementById('deanForm');
        if(deanForm) {
            deanForm.addEventListener('submit', function(e) {
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
            const newUrl = window.location.pathname;
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
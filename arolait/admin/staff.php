<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

// Handle delete
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Get user_id
        $stmt = $pdo->prepare("SELECT user_id FROM staff WHERE id = ?");
        $stmt->execute([$id]);
        $user_id = $stmt->fetch()['user_id'];
        
        // Delete staff record
        $stmt = $pdo->prepare("DELETE FROM staff WHERE id = ?");
        $stmt->execute([$id]);
        
        // Delete user account
        if ($user_id) {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
        }
        
        $pdo->commit();
        header("Location: staff.php?message=Staff member deleted successfully");
    } catch(PDOException $e) {
        $pdo->rollBack();
        header("Location: staff.php?error=Cannot delete staff with existing records");
    }
    exit();
}

// Handle status toggle (active/inactive)
if (isset($_GET['toggle'])) {
    $id = $_GET['toggle'];
    $stmt = $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = (SELECT user_id FROM staff WHERE id = ?)");
    $stmt->execute([$id]);
    header("Location: staff.php?message=Staff status updated");
    exit();
}

// Get filters
$department_id = $_GET['department'] ?? '';
$designation = $_GET['designation'] ?? '';
$status = $_GET['status'] ?? '';

// Get departments for filter
$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();

// Build staff query
$sql = "SELECT 
            s.id, s.staff_number, s.designation, s.specialization, s.hire_date,
            u.id as user_id, u.email, u.first_name, u.last_name, u.phone, u.is_active, u.last_login,
            d.name as department_name, d.code as department_code,
            f.name as faculty_name
        FROM staff s
        JOIN users u ON s.user_id = u.id
        JOIN departments d ON s.department_id = d.id
        JOIN faculties f ON d.faculty_id = f.id
        WHERE 1=1";
$params = [];

if ($department_id) {
    $sql .= " AND s.department_id = ?";
    $params[] = $department_id;
}
if ($designation) {
    $sql .= " AND s.designation = ?";
    $params[] = $designation;
}
if ($status !== '') {
    $sql .= " AND u.is_active = ?";
    $params[] = $status;
}

$sql .= " ORDER BY u.last_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$staff = $stmt->fetchAll();

// Get statistics
$total_staff = count($staff);
$active_staff = $pdo->query("SELECT COUNT(*) FROM users u JOIN staff s ON u.id = s.user_id WHERE u.is_active = 1")->fetchColumn();
$lecturers = $pdo->query("SELECT COUNT(*) FROM staff WHERE designation LIKE '%Lecturer%'")->fetchColumn();
$hod_count = $pdo->query("SELECT COUNT(*) FROM staff WHERE designation = 'HOD'")->fetchColumn();

// Get unique designations for filter
$designations = $pdo->query("SELECT DISTINCT designation FROM staff WHERE designation IS NOT NULL ORDER BY designation")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Staff Management - University Portal</title>
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
        
        .staff-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 20px;
        }
        
        .staff-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .staff-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .card-header {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
            padding: 16px;
            position: relative;
        }
        
        .card-header.inactive {
            background: linear-gradient(135deg, #a0aec0 0%, #718096 100%);
        }
        
        .staff-name {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .staff-number {
            font-size: 12px;
            font-family: monospace;
            opacity: 0.9;
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
        
        .badge-hod {
            background: #feebc8;
            color: #7c2d12;
        }
        
        .badge-lecturer {
            background: #bee3f8;
            color: #2c5282;
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
        
        .password-strength {
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }
        
        .strength-bar {
            height: 100%;
            width: 0;
            transition: width 0.3s;
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
            .staff-grid {
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
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h1>👨‍🏫 Staff Management</h1>
                    <p>Manage lecturers, HODs, and administrative staff</p>
                </div>
                <a href="index.php" style="color: #667eea; text-decoration: none;">← Back to Dashboard</a>
            </div>
        </div>
        
        <!-- Stats -->
        <div class="stats-bar">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_staff; ?></div>
                <div class="stat-label">Total Staff</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $active_staff; ?></div>
                <div class="stat-label">Active Staff</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $lecturers; ?></div>
                <div class="stat-label">Lecturers</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $hod_count; ?></div>
                <div class="stat-label">HODs</div>
            </div>
        </div>
        
        <!-- Action Bar -->
        <div class="action-bar">
            <button onclick="openAddModal()" class="btn btn-primary">➕ Add New Staff</button>
            <button onclick="window.location.href='bulk_upload_staff.php'" class="btn btn-success">📤 Bulk Upload</button>
        </div>
        
        <!-- Filter Bar -->
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form">
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
                    <label>Designation</label>
                    <select name="designation" onchange="this.form.submit()">
                        <option value="">All Designations</option>
                        <?php foreach($designations as $desig): ?>
                            <option value="<?php echo htmlspecialchars($desig['designation']); ?>" <?php echo $designation == $desig['designation'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($desig['designation']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Status</label>
                    <select name="status" onchange="this.form.submit()">
                        <option value="">All Status</option>
                        <option value="1" <?php echo $status === '1' ? 'selected' : ''; ?>>Active</option>
                        <option value="0" <?php echo $status === '0' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <?php if($department_id || $designation || $status !== ''): ?>
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <a href="staff.php" class="btn btn-small" style="background: #e2e8f0; text-align: center;">Clear Filters</a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
        
        <!-- Staff Grid -->
        <div class="staff-grid">
            <?php foreach($staff as $member): ?>
                <div class="staff-card">
                    <div class="card-header <?php echo !$member['is_active'] ? 'inactive' : ''; ?>">
                        <div class="staff-name">
                            <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                        </div>
                        <div class="staff-number">
                            ID: <?php echo htmlspecialchars($member['staff_number']); ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="info-row">
                            <span class="info-label">📧 Email</span>
                            <span class="info-value"><?php echo htmlspecialchars($member['email']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">📞 Phone</span>
                            <span class="info-value"><?php echo $member['phone'] ?: 'Not provided'; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">🏛️ Faculty</span>
                            <span class="info-value"><?php echo htmlspecialchars($member['faculty_name']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">📚 Department</span>
                            <span class="info-value"><?php echo htmlspecialchars($member['department_name']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">👔 Designation</span>
                            <span class="info-value">
                                <?php echo htmlspecialchars($member['designation'] ?: 'Not set'); ?>
                                <?php if($member['designation'] == 'HOD'): ?>
                                    <span class="badge badge-hod">Head of Department</span>
                                <?php elseif(strpos($member['designation'], 'Lecturer') !== false): ?>
                                    <span class="badge badge-lecturer">Lecturer</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">🔬 Specialization</span>
                            <span class="info-value"><?php echo $member['specialization'] ?: 'Not specified'; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">📅 Hire Date</span>
                            <span class="info-value"><?php echo $member['hire_date'] ? date('F j, Y', strtotime($member['hire_date'])) : 'Not set'; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">🕐 Last Login</span>
                            <span class="info-value"><?php echo $member['last_login'] ? date('M j, Y g:i A', strtotime($member['last_login'])) : 'Never'; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Status</span>
                            <span class="info-value">
                                <?php if($member['is_active']): ?>
                                    <span class="badge badge-active">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-inactive">Inactive</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-actions">
                        <button onclick="editStaff(<?php echo $member['id']; ?>)" class="btn btn-primary btn-small">✏️ Edit</button>
                        <a href="?toggle=<?php echo $member['id']; ?>" class="btn btn-warning btn-small" onclick="return confirm('Toggle staff status?')">
                            <?php echo $member['is_active'] ? '🔴 Deactivate' : '🟢 Activate'; ?>
                        </a>
                        <a href="?delete=<?php echo $member['id']; ?>" class="btn btn-danger btn-small" onclick="return confirm('Delete this staff member? This action cannot be undone!')">🗑️ Delete</a>
                        <button onclick="viewAssignedCourses(<?php echo $member['id']; ?>)" class="btn btn-info btn-small">📖 Courses</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if(empty($staff)): ?>
            <div style="text-align: center; padding: 60px; background: white; border-radius: 12px;">
                <p style="color: #718096;">No staff members found. Click "Add New Staff" to get started.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Add/Edit Staff Modal -->
    <div id="staffModal" class="modal">
        <div class="modal-content">
            <h2 id="modalTitle" style="margin-bottom: 20px;">Add New Staff</h2>
            <form id="staffForm" method="POST" action="save_staff.php">
                <input type="hidden" name="staff_id" id="staff_id">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>First Name *</label>
                        <input type="text" name="first_name" id="first_name" required>
                    </div>
                    <div class="form-group">
                        <label>Last Name *</label>
                        <input type="text" name="last_name" id="last_name" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" id="email" required>
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="tel" name="phone" id="phone">
                    </div>
                </div>
                
                <div class="form-group" id="password_group">
                    <label>Password *</label>
                    <input type="password" name="password" id="password">
                    <div class="password-strength">
                        <div class="strength-bar" id="strengthBar"></div>
                    </div>
                    <div class="info-text" style="font-size: 12px; color: #718096; margin-top: 4px;">Minimum 6 characters. Leave blank to keep current password when editing.</div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Staff Number</label>
                        <input type="text" name="staff_number" id="staff_number" placeholder="Auto-generated if left blank">
                        <div class="info-text" style="font-size: 12px; color: #718096;">Leave blank for auto-generation</div>
                    </div>
                    <div class="form-group">
                        <label>Hire Date</label>
                        <input type="date" name="hire_date" id="hire_date">
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
                    <label>Designation</label>
                    <select name="designation" id="designation">
                        <option value="">Select Designation</option>
                        <option value="Professor">Professor</option>
                        <option value="Associate Professor">Associate Professor</option>
                        <option value="Senior Lecturer">Senior Lecturer</option>
                        <option value="Lecturer I">Lecturer I</option>
                        <option value="Lecturer II">Lecturer II</option>
                        <option value="Assistant Lecturer">Assistant Lecturer</option>
                        <option value="HOD">Head of Department (HOD)</option>
                        <option value="Dean">Dean of Faculty</option>
                        <option value="Registrar">Registrar</option>
                        <option value="Administrative Staff">Administrative Staff</option>
                        <option value="Technologist">Laboratory Technologist</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Specialization</label>
                    <input type="text" name="specialization" id="specialization" placeholder="e.g., Computer Networks, Organic Chemistry">
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn btn-primary">Save Staff</button>
                    <button type="button" onclick="closeModal()" class="btn" style="background: #e2e8f0;">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openAddModal() {
            document.getElementById('modalTitle').innerText = 'Add New Staff';
            document.getElementById('staffForm').reset();
            document.getElementById('staff_id').value = '';
            document.getElementById('password').required = true;
            document.getElementById('password_group').style.display = 'block';
            document.getElementById('staffModal').style.display = 'flex';
        }
        
        function editStaff(id) {
            fetch('get_staff.php?id=' + id)
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        document.getElementById('modalTitle').innerText = 'Edit Staff';
                        document.getElementById('staff_id').value = data.staff.id;
                        document.getElementById('first_name').value = data.staff.first_name;
                        document.getElementById('last_name').value = data.staff.last_name;
                        document.getElementById('email').value = data.staff.email;
                        document.getElementById('phone').value = data.staff.phone || '';
                        document.getElementById('staff_number').value = data.staff.staff_number;
                        document.getElementById('hire_date').value = data.staff.hire_date || '';
                        document.getElementById('department_id').value = data.staff.department_id;
                        document.getElementById('designation').value = data.staff.designation || '';
                        document.getElementById('specialization').value = data.staff.specialization || '';
                        document.getElementById('password').required = false;
                        document.getElementById('password').placeholder = 'Leave blank to keep current';
                        document.getElementById('staffModal').style.display = 'flex';
                    } else {
                        alert('Error loading staff data');
                    }
                })
                .catch(error => {
                    alert('Error: ' + error);
                });
        }
        
        function viewAssignedCourses(staffId) {
            window.open('staff_courses.php?staff_id=' + staffId, '_blank');
        }
        
        function closeModal() {
            document.getElementById('staffModal').style.display = 'none';
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('staffModal');
            if(event.target === modal) {
                modal.style.display = 'none';
            }
        }
        
        // Password strength checker
        const password = document.getElementById('password');
        const strengthBar = document.getElementById('strengthBar');
        
        if(password) {
            password.addEventListener('input', function() {
                const val = this.value;
                let strength = 0;
                
                if(val.length >= 6) strength = 25;
                if(val.length >= 8) strength = 50;
                if(/[A-Z]/.test(val)) strength += 25;
                if(/[0-9]/.test(val)) strength += 25;
                
                strengthBar.style.width = strength + '%';
                if(strength < 50) strengthBar.style.background = '#f56565';
                else if(strength < 75) strengthBar.style.background = '#ed8936';
                else strengthBar.style.background = '#48bb78';
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
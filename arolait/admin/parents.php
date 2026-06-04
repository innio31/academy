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
        $stmt = $pdo->prepare("SELECT user_id FROM parents WHERE id = ?");
        $stmt->execute([$id]);
        $user_id = $stmt->fetch()['user_id'];
        
        // Delete parent record
        $stmt = $pdo->prepare("DELETE FROM parents WHERE id = ?");
        $stmt->execute([$id]);
        
        // Delete user account
        if ($user_id) {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
        }
        
        $pdo->commit();
        header("Location: parents.php?message=Parent deleted successfully");
    } catch(PDOException $e) {
        $pdo->rollBack();
        header("Location: parents.php?error=Cannot delete parent with existing records");
    }
    exit();
}

// Handle status toggle
if (isset($_GET['toggle'])) {
    $id = $_GET['toggle'];
    $stmt = $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = (SELECT user_id FROM parents WHERE id = ?)");
    $stmt->execute([$id]);
    header("Location: parents.php?message=Parent status updated");
    exit();
}

// Get filters
$search = $_GET['search'] ?? '';
$student_id = $_GET['student'] ?? '';

// Get students for filter
$students = $pdo->query("
    SELECT s.id, s.reg_number, CONCAT(u.first_name, ' ', u.last_name) as student_name 
    FROM students s 
    JOIN users u ON s.user_id = u.id 
    ORDER BY u.last_name
")->fetchAll();

// Build parents query
$sql = "SELECT 
            p.id, p.relationship, p.can_view_results, p.can_view_attendance,
            u.id as user_id, u.email, u.first_name, u.last_name, u.phone, u.is_active, u.last_login,
            s.id as student_id, s.reg_number,
            CONCAT(su.first_name, ' ', su.last_name) as student_name,
            d.name as department_name,
            su.email as student_email
        FROM parents p
        JOIN users u ON p.user_id = u.id
        JOIN students s ON p.student_id = s.id
        JOIN users su ON s.user_id = su.id
        JOIN departments d ON s.department_id = d.id
        WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR su.first_name LIKE ? OR su.last_name LIKE ?)";
    $searchParam = "%$search%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
}
if ($student_id) {
    $sql .= " AND p.student_id = ?";
    $params[] = $student_id;
}

$sql .= " ORDER BY u.last_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$parents = $stmt->fetchAll();

// Get statistics
$total_parents = count($parents);
$active_parents = $pdo->query("SELECT COUNT(*) FROM users u JOIN parents p ON u.id = p.user_id WHERE u.is_active = 1")->fetchColumn();
$linked_students = $pdo->query("SELECT COUNT(DISTINCT student_id) FROM parents")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Parent Management - University Portal</title>
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
        
        .search-bar {
            background: white;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        .search-form {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
        }
        
        .search-group {
            flex: 1;
            min-width: 200px;
        }
        
        .search-group label {
            display: block;
            margin-bottom: 5px;
            font-size: 12px;
            font-weight: 600;
            color: #4a5568;
        }
        
        .search-group input, .search-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .parents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 20px;
        }
        
        .parent-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .parent-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .card-header {
            background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
            color: white;
            padding: 16px;
            position: relative;
        }
        
        .card-header.inactive {
            background: linear-gradient(135deg, #a0aec0 0%, #718096 100%);
        }
        
        .parent-name {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .parent-email {
            font-size: 12px;
            opacity: 0.9;
        }
        
        .card-body {
            padding: 16px;
        }
        
        .student-section {
            background: #f0fff4;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left: 4px solid #48bb78;
        }
        
        .student-name {
            font-weight: 600;
            color: #22543d;
            margin-bottom: 5px;
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
        
        .badge-enabled {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .badge-disabled {
            background: #fed7d7;
            color: #c53030;
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
        
        .form-group input, .form-group select {
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
            margin-bottom: 12px;
        }
        
        .checkbox-group input {
            width: auto;
            width: 20px;
            height: 20px;
        }
        
        .checkbox-group label {
            margin: 0;
            cursor: pointer;
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
            .parents-grid {
                grid-template-columns: 1fr;
            }
            
            .search-form {
                flex-direction: column;
            }
            
            .search-group {
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
                    <h1>👪 Parent/Guardian Management</h1>
                    <p>Manage parents and guardians, link them to students for monitoring</p>
                </div>
                <a href="index.php" style="color: #667eea; text-decoration: none;">← Back to Dashboard</a>
            </div>
        </div>
        
        <!-- Stats -->
        <div class="stats-bar">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_parents; ?></div>
                <div class="stat-label">Total Parents</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $active_parents; ?></div>
                <div class="stat-label">Active Parents</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $linked_students; ?></div>
                <div class="stat-label">Linked Students</div>
            </div>
        </div>
        
        <!-- Action Bar -->
        <div class="action-bar">
            <button onclick="openAddModal()" class="btn btn-primary">➕ Add Parent/Guardian</button>
        </div>
        
        <!-- Search Bar -->
        <div class="search-bar">
            <form method="GET" action="" class="search-form">
                <div class="search-group">
                    <label>🔍 Search</label>
                    <input type="text" name="search" placeholder="Search by parent/student name or email..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="search-group">
                    <label>👨‍🎓 Student</label>
                    <select name="student">
                        <option value="">All Students</option>
                        <?php foreach($students as $student): ?>
                            <option value="<?php echo $student['id']; ?>" <?php echo $student_id == $student['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($student['student_name'] . ' (' . $student['reg_number'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="search-group">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">🔍 Search</button>
                </div>
                <?php if($search || $student_id): ?>
                    <div class="search-group">
                        <label>&nbsp;</label>
                        <a href="parents.php" class="btn" style="background: #e2e8f0; text-align: center; width: 100%;">Clear Filters</a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
        
        <!-- Parents Grid -->
        <div class="parents-grid">
            <?php foreach($parents as $parent): ?>
                <div class="parent-card">
                    <div class="card-header <?php echo !$parent['is_active'] ? 'inactive' : ''; ?>">
                        <div class="parent-name">
                            <?php echo htmlspecialchars($parent['first_name'] . ' ' . $parent['last_name']); ?>
                        </div>
                        <div class="parent-email">
                            <?php echo htmlspecialchars($parent['email']); ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="student-section">
                            <div class="student-name">
                                🎓 Ward: <?php echo htmlspecialchars($parent['student_name']); ?>
                            </div>
                            <div style="font-size: 12px; color: #4a5568;">
                                Reg No: <?php echo $parent['reg_number']; ?> | 
                                Dept: <?php echo htmlspecialchars($parent['department_name']); ?>
                            </div>
                        </div>
                        
                        <div class="info-row">
                            <span class="info-label">📞 Phone</span>
                            <span class="info-value"><?php echo $parent['phone'] ?: 'Not provided'; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">👔 Relationship</span>
                            <span class="info-value"><?php echo $parent['relationship'] ?: 'Not specified'; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">🕐 Last Login</span>
                            <span class="info-value"><?php echo $parent['last_login'] ? date('M j, Y g:i A', strtotime($parent['last_login'])) : 'Never'; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">👁️ View Results</span>
                            <span class="info-value">
                                <?php if($parent['can_view_results']): ?>
                                    <span class="badge badge-enabled">Enabled</span>
                                <?php else: ?>
                                    <span class="badge badge-disabled">Disabled</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">📅 View Attendance</span>
                            <span class="info-value">
                                <?php if($parent['can_view_attendance']): ?>
                                    <span class="badge badge-enabled">Enabled</span>
                                <?php else: ?>
                                    <span class="badge badge-disabled">Disabled</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Status</span>
                            <span class="info-value">
                                <?php if($parent['is_active']): ?>
                                    <span class="badge badge-active">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-inactive">Inactive</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-actions">
                        <button onclick="editParent(<?php echo $parent['id']; ?>)" class="btn btn-primary btn-small">✏️ Edit</button>
                        <a href="?toggle=<?php echo $parent['id']; ?>" class="btn btn-warning btn-small" onclick="return confirm('Toggle parent status?')">
                            <?php echo $parent['is_active'] ? '🔴 Deactivate' : '🟢 Activate'; ?>
                        </a>
                        <a href="?delete=<?php echo $parent['id']; ?>" class="btn btn-danger btn-small" onclick="return confirm('Delete this parent? This action cannot be undone!')">🗑️ Delete</a>
                        <button onclick="loginAsParent(<?php echo $parent['user_id']; ?>)" class="btn btn-info btn-small">🔑 Login as Parent</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if(empty($parents)): ?>
            <div style="text-align: center; padding: 60px; background: white; border-radius: 12px;">
                <p style="color: #718096;">No parents found. Click "Add Parent/Guardian" to get started.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Add/Edit Parent Modal -->
    <div id="parentModal" class="modal">
        <div class="modal-content">
            <h2 id="modalTitle" style="margin-bottom: 20px;">Add Parent/Guardian</h2>
            <form id="parentForm" method="POST" action="save_parent.php">
                <input type="hidden" name="parent_id" id="parent_id">
                
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
                        <div class="info-text" style="font-size: 12px; color: #718096;">Used for login</div>
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
                    <div class="info-text" style="font-size: 12px; color: #718096;">Minimum 6 characters. Leave blank to keep current password when editing.</div>
                </div>
                
                <div class="form-group">
                    <label>Select Student to Monitor *</label>
                    <select name="student_id" id="student_id" required>
                        <option value="">Select Student</option>
                        <?php foreach($students as $student): ?>
                            <option value="<?php echo $student['id']; ?>">
                                <?php echo htmlspecialchars($student['student_name'] . ' (' . $student['reg_number'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Relationship</label>
                    <select name="relationship" id="relationship">
                        <option value="">Select Relationship</option>
                        <option value="Father">Father</option>
                        <option value="Mother">Mother</option>
                        <option value="Guardian">Guardian</option>
                        <option value="Grandparent">Grandparent</option>
                        <option value="Sibling">Sibling</option>
                        <option value="Uncle">Uncle</option>
                        <option value="Aunt">Aunt</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Access Permissions</label>
                    <div class="checkbox-group">
                        <input type="checkbox" name="can_view_results" id="can_view_results" value="1" checked>
                        <label for="can_view_results">Allow viewing of results</label>
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" name="can_view_attendance" id="can_view_attendance" value="1" checked>
                        <label for="can_view_attendance">Allow viewing of attendance</label>
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn btn-primary">Save Parent</button>
                    <button type="button" onclick="closeModal()" class="btn" style="background: #e2e8f0;">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openAddModal() {
            document.getElementById('modalTitle').innerText = 'Add Parent/Guardian';
            document.getElementById('parentForm').reset();
            document.getElementById('parent_id').value = '';
            document.getElementById('password').required = true;
            document.getElementById('can_view_results').checked = true;
            document.getElementById('can_view_attendance').checked = true;
            document.getElementById('parentModal').style.display = 'flex';
        }
        
        function editParent(id) {
            fetch('get_parent.php?id=' + id)
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        document.getElementById('modalTitle').innerText = 'Edit Parent/Guardian';
                        document.getElementById('parent_id').value = data.parent.id;
                        document.getElementById('first_name').value = data.parent.first_name;
                        document.getElementById('last_name').value = data.parent.last_name;
                        document.getElementById('email').value = data.parent.email;
                        document.getElementById('phone').value = data.parent.phone || '';
                        document.getElementById('student_id').value = data.parent.student_id;
                        document.getElementById('relationship').value = data.parent.relationship || '';
                        document.getElementById('can_view_results').checked = data.parent.can_view_results == 1;
                        document.getElementById('can_view_attendance').checked = data.parent.can_view_attendance == 1;
                        document.getElementById('password').required = false;
                        document.getElementById('password').placeholder = 'Leave blank to keep current';
                        document.getElementById('parentModal').style.display = 'flex';
                    } else {
                        alert('Error loading parent data');
                    }
                })
                .catch(error => {
                    alert('Error: ' + error);
                });
        }
        
        function loginAsParent(userId) {
            if(confirm('Login as this parent? This will log you out of your admin session.')) {
                window.location.href = 'login_as_parent.php?user_id=' + userId;
            }
        }
        
        function closeModal() {
            document.getElementById('parentModal').style.display = 'none';
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('parentModal');
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
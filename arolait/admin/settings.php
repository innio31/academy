<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

$success = '';
$error = '';

// Handle different setting actions
$action = $_GET['action'] ?? 'general';

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // General Settings
    if (isset($_POST['general_settings'])) {
        $app_name = trim($_POST['app_name']);
        $app_slogan = trim($_POST['app_slogan']);
        $institution_name = trim($_POST['institution_name']);
        $institution_address = trim($_POST['institution_address']);
        $institution_phone = trim($_POST['institution_phone']);
        $institution_email = trim($_POST['institution_email']);
        $timezone = $_POST['timezone'];
        
        // Save to a settings table
        $settings = [
            'app_name' => $app_name,
            'app_slogan' => $app_slogan,
            'institution_name' => $institution_name,
            'institution_address' => $institution_address,
            'institution_phone' => $institution_phone,
            'institution_email' => $institution_email,
            'timezone' => $timezone
        ];
        
        foreach ($settings as $key => $value) {
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
                                   ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }
        
        // Update PHP timezone
        date_default_timezone_set($timezone);
        
        $success = "General settings updated successfully!";
    }
    
    // Add Academic Session
    if (isset($_POST['add_session'])) {
        $session_name = trim($_POST['session_name']);
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $is_current = isset($_POST['is_current']) ? 1 : 0;
        
        if ($is_current) {
            $pdo->exec("UPDATE academic_sessions SET is_current = 0");
        }
        
        $stmt = $pdo->prepare("INSERT INTO academic_sessions (name, start_date, end_date, is_current) 
                               VALUES (?, ?, ?, ?)");
        $stmt->execute([$session_name, $start_date, $end_date, $is_current]);
        
        $success = "Academic session added successfully!";
    }
    
    // Edit Academic Session
    if (isset($_POST['edit_session'])) {
        $session_id = $_POST['session_id'];
        $session_name = trim($_POST['session_name']);
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        
        $stmt = $pdo->prepare("UPDATE academic_sessions SET name = ?, start_date = ?, end_date = ? WHERE id = ?");
        $stmt->execute([$session_name, $start_date, $end_date, $session_id]);
        
        $success = "Academic session updated successfully!";
    }
    
    // Set Current Session
    if (isset($_POST['set_current_session'])) {
        $session_id = $_POST['session_id'];
        
        // Remove current flag from all sessions
        $pdo->exec("UPDATE academic_sessions SET is_current = 0");
        
        // Set the selected session as current
        $stmt = $pdo->prepare("UPDATE academic_sessions SET is_current = 1 WHERE id = ?");
        $stmt->execute([$session_id]);
        
        $success = "Current session updated successfully!";
    }
    
    // Delete Session
    if (isset($_POST['delete_session'])) {
        $session_id = $_POST['session_id'];
        
        // Check if session has semesters
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM semesters WHERE session_id = ?");
        $stmt->execute([$session_id]);
        $semester_count = $stmt->fetchColumn();
        
        if ($semester_count > 0) {
            $error = "Cannot delete session because it has $semester_count semester(s) linked to it. Delete the semesters first.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM academic_sessions WHERE id = ?");
            $stmt->execute([$session_id]);
            $success = "Academic session deleted successfully!";
        }
    }
    
    // Add Semester
    if (isset($_POST['add_semester'])) {
        $session_id = $_POST['session_id'];
        $semester_name = $_POST['semester_name'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $is_current = isset($_POST['is_current']) ? 1 : 0;
        
        if ($is_current) {
            $pdo->prepare("UPDATE semesters SET is_current = 0 WHERE session_id = ?")
                ->execute([$session_id]);
        }
        
        $stmt = $pdo->prepare("INSERT INTO semesters (session_id, name, start_date, end_date, is_current) 
                               VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$session_id, $semester_name, $start_date, $end_date, $is_current]);
        
        $success = "Semester added successfully!";
    }
    
    // Edit Semester
    if (isset($_POST['edit_semester'])) {
        $semester_id = $_POST['semester_id'];
        $session_id = $_POST['session_id'];
        $semester_name = $_POST['semester_name'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        
        $stmt = $pdo->prepare("UPDATE semesters SET session_id = ?, name = ?, start_date = ?, end_date = ? WHERE id = ?");
        $stmt->execute([$session_id, $semester_name, $start_date, $end_date, $semester_id]);
        
        $success = "Semester updated successfully!";
    }
    
    // Set Current Semester
    if (isset($_POST['set_current_semester'])) {
        $semester_id = $_POST['semester_id'];
        
        // Get the session of this semester
        $stmt = $pdo->prepare("SELECT session_id FROM semesters WHERE id = ?");
        $stmt->execute([$semester_id]);
        $session_id = $stmt->fetchColumn();
        
        // Remove current flag from all semesters in this session
        $pdo->prepare("UPDATE semesters SET is_current = 0 WHERE session_id = ?")
            ->execute([$session_id]);
        
        // Set the selected semester as current
        $stmt = $pdo->prepare("UPDATE semesters SET is_current = 1 WHERE id = ?");
        $stmt->execute([$semester_id]);
        
        $success = "Current semester updated successfully!";
    }
    
    // Delete Semester
    if (isset($_POST['delete_semester'])) {
        $semester_id = $_POST['semester_id'];
        
        $stmt = $pdo->prepare("DELETE FROM semesters WHERE id = ?");
        $stmt->execute([$semester_id]);
        $success = "Semester deleted successfully!";
    }
    
    // System Settings
    if (isset($_POST['system_settings'])) {
        $max_login_attempts = $_POST['max_login_attempts'];
        $session_timeout = $_POST['session_timeout'];
        $enable_registration = isset($_POST['enable_registration']) ? 1 : 0;
        $enable_attendance = isset($_POST['enable_attendance']) ? 1 : 0;
        $enable_result_check = isset($_POST['enable_result_check']) ? 1 : 0;
        $maintenance_mode = isset($_POST['maintenance_mode']) ? 1 : 0;
        
        $settings = [
            'max_login_attempts' => $max_login_attempts,
            'session_timeout' => $session_timeout,
            'enable_registration' => $enable_registration,
            'enable_attendance' => $enable_attendance,
            'enable_result_check' => $enable_result_check,
            'maintenance_mode' => $maintenance_mode
        ];
        
        foreach ($settings as $key => $value) {
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
                                   ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }
        
        $success = "System settings updated successfully!";
    }
    
    // Backup Settings
    if (isset($_POST['backup_db'])) {
        $backup_dir = $_SERVER['DOCUMENT_ROOT'] . '/backups/';
        if (!file_exists($backup_dir)) {
            mkdir($backup_dir, 0777, true);
        }
        
        $backup_file = $backup_dir . 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        
        $backup_sql = "";
        
        foreach ($tables as $table) {
            $stmt = $pdo->query("SELECT * FROM $table");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $backup_sql .= "DROP TABLE IF EXISTS $table;\n";
            $create = $pdo->query("SHOW CREATE TABLE $table")->fetch();
            $backup_sql .= $create['Create Table'] . ";\n\n";
            
            foreach ($rows as $row) {
                $columns = array_keys($row);
                $values = array_map(function($value) use ($pdo) {
                    return $value === null ? 'NULL' : $pdo->quote($value);
                }, array_values($row));
                
                $backup_sql .= "INSERT INTO $table (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ");\n";
            }
            $backup_sql .= "\n";
        }
        
        file_put_contents($backup_file, $backup_sql);
        
        $success = "Database backup created successfully! <a href='/backups/" . basename($backup_file) . "' download>Download Backup</a>";
    }
}

// Get current settings
$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get academic sessions
$sessions = $pdo->query("SELECT * FROM academic_sessions ORDER BY start_date DESC")->fetchAll();

// Get semesters with session names
$semesters = $pdo->query("
    SELECT s.*, a.name as session_name 
    FROM semesters s 
    JOIN academic_sessions a ON s.session_id = a.id 
    ORDER BY a.start_date DESC, s.id DESC
")->fetchAll();

// Get statistics
$stats = [];
$stats['total_users'] = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$stats['total_students'] = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$stats['total_staff'] = $pdo->query("SELECT COUNT(*) FROM staff")->fetchColumn();
$stats['total_courses'] = $pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn();
$stats['total_departments'] = $pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();

// Get current session
$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current = 1 LIMIT 1")->fetch();
$current_semester = $pdo->query("SELECT s.*, a.name as session_name FROM semesters s 
                                 JOIN academic_sessions a ON s.session_id = a.id 
                                 WHERE s.is_current = 1 LIMIT 1")->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>System Settings - University Portal</title>
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
        
        .tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 10px;
        }
        
        .tab {
            padding: 10px 20px;
            background: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .tab.active {
            background: #667eea;
            color: white;
        }
        
        .tab:hover:not(.active) {
            background: #e2e8f0;
        }
        
        .panel {
            display: none;
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .panel.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2d3748;
            font-size: 14px;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5a67d8;
        }
        
        .btn-danger {
            background: #f56565;
            color: white;
        }
        
        .btn-danger:hover {
            background: #e53e3e;
        }
        
        .btn-success {
            background: #48bb78;
            color: white;
        }
        
        .btn-success:hover {
            background: #38a169;
        }
        
        .btn-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }
        
        .btn-secondary:hover {
            background: #cbd5e0;
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        
        th {
            background: #f7fafc;
            font-weight: 600;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .badge-success { background: #c6f6d5; color: #22543d; }
        .badge-warning { background: #feebc8; color: #7c2d12; }
        .badge-info { background: #bee3f8; color: #2c5282; }
        
        .info-box {
            background: #e9f5ff;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .info-box h4 {
            margin-bottom: 10px;
            color: #2c5282;
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
        
        .checkbox-group label {
            margin: 0;
            cursor: pointer;
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
            max-width: 500px;
            width: 90%;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
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
            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .tab {
                text-align: center;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h1>⚙️ System Settings</h1>
                    <p>Configure your institution portal settings</p>
                </div>
                <a href="index.php" style="color: #667eea; text-decoration: none;">← Back to Dashboard</a>
            </div>
        </div>
        
        <div class="stats-bar">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_students']; ?></div>
                <div class="stat-label">Students</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_staff']; ?></div>
                <div class="stat-label">Staff</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_courses']; ?></div>
                <div class="stat-label">Courses</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_departments']; ?></div>
                <div class="stat-label">Departments</div>
            </div>
        </div>
        
        <div class="info-box">
            <h4>📅 Current Academic Status</h4>
            <p><strong>Session:</strong> <?php echo $current_session['name'] ?? 'Not set'; ?></p>
            <p><strong>Semester:</strong> <?php echo $current_semester ? $current_semester['name'] . ' Semester - ' . $current_semester['session_name'] : 'Not set'; ?></p>
        </div>
        
        <div class="tabs">
            <button class="tab <?php echo $action == 'general' ? 'active' : ''; ?>" onclick="showTab('general')">🏢 General</button>
            <button class="tab <?php echo $action == 'session' ? 'active' : ''; ?>" onclick="showTab('session')">📅 Academic Sessions</button>
            <button class="tab <?php echo $action == 'semester' ? 'active' : ''; ?>" onclick="showTab('semester')">📖 Semesters</button>
            <button class="tab <?php echo $action == 'system' ? 'active' : ''; ?>" onclick="showTab('system')">🔧 System</button>
            <button class="tab <?php echo $action == 'backup' ? 'active' : ''; ?>" onclick="showTab('backup')">💾 Backup</button>
            <button class="tab" onclick="showTab('info')">ℹ️ System Info</button>
        </div>
        
        <!-- General Settings Panel -->
        <div id="general" class="panel <?php echo $action == 'general' ? 'active' : ''; ?>">
            <h2 style="margin-bottom: 20px;">🏢 General Institution Settings</h2>
            <form method="POST">
                <div class="form-row">
                    <div class="form-group">
                        <label>Application Name</label>
                        <input type="text" name="app_name" value="<?php echo htmlspecialchars($settings['app_name'] ?? 'University Portal'); ?>">
                    </div>
                    <div class="form-group">
                        <label>Application Slogan</label>
                        <input type="text" name="app_slogan" value="<?php echo htmlspecialchars($settings['app_slogan'] ?? 'Excellence in Education'); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Institution Name</label>
                    <input type="text" name="institution_name" value="<?php echo htmlspecialchars($settings['institution_name'] ?? 'Higher Institution of Learning'); ?>">
                </div>
                
                <div class="form-group">
                    <label>Institution Address</label>
                    <textarea name="institution_address" rows="2"><?php echo htmlspecialchars($settings['institution_address'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="institution_phone" value="<?php echo htmlspecialchars($settings['institution_phone'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="institution_email" value="<?php echo htmlspecialchars($settings['institution_email'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Timezone</label>
                    <select name="timezone">
                        <option value="Africa/Lagos" <?php echo ($settings['timezone'] ?? '') == 'Africa/Lagos' ? 'selected' : ''; ?>>Africa/Lagos (West Africa Time)</option>
                        <option value="Africa/Nairobi" <?php echo ($settings['timezone'] ?? '') == 'Africa/Nairobi' ? 'selected' : ''; ?>>Africa/Nairobi (East Africa Time)</option>
                        <option value="Africa/Johannesburg" <?php echo ($settings['timezone'] ?? '') == 'Africa/Johannesburg' ? 'selected' : ''; ?>>Africa/Johannesburg (South Africa)</option>
                        <option value="Africa/Cairo" <?php echo ($settings['timezone'] ?? '') == 'Africa/Cairo' ? 'selected' : ''; ?>>Africa/Cairo (Egypt)</option>
                    </select>
                </div>
                
                <button type="submit" name="general_settings" class="btn btn-primary">Save General Settings</button>
            </form>
        </div>
        
        <!-- Academic Sessions Panel -->
        <div id="session" class="panel <?php echo $action == 'session' ? 'active' : ''; ?>">
            <h2 style="margin-bottom: 20px;">📅 Academic Sessions</h2>
            
            <div style="background: #f7fafc; padding: 20px; border-radius: 10px; margin-bottom: 30px;">
                <h3 style="margin-bottom: 15px;">Add New Session</h3>
                <form method="POST">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Session Name</label>
                            <input type="text" name="session_name" required placeholder="e.g., 2024/2025">
                        </div>
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" name="start_date" required>
                        </div>
                        <div class="form-group">
                            <label>End Date</label>
                            <input type="date" name="end_date" required>
                        </div>
                        <div class="form-group">
                            <div class="checkbox-group" style="margin-top: 30px;">
                                <input type="checkbox" name="is_current" id="is_current">
                                <label for="is_current">Set as Current Session</label>
                            </div>
                        </div>
                    </div>
                    <button type="submit" name="add_session" class="btn btn-primary">Add Session</button>
                </form>
            </div>
            
            <h3>Existing Sessions</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Session</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($sessions as $session): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($session['name']); ?></td>
                            <td><?php echo $session['start_date']; ?></td>
                            <td><?php echo $session['end_date']; ?></td>
                            <td>
                                <?php if($session['is_current']): ?>
                                    <span class="badge badge-success">Current</span>
                                <?php else: ?>
                                    <span class="badge badge-info">Archived</span>
                                <?php endif; ?>
                            </td>
                            <td class="action-buttons">
                                <button onclick="openEditSessionModal(<?php echo $session['id']; ?>, '<?php echo htmlspecialchars($session['name']); ?>', '<?php echo $session['start_date']; ?>', '<?php echo $session['end_date']; ?>')" class="btn btn-primary btn-small">✏️ Edit</button>
                                
                                <?php if(!$session['is_current']): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="session_id" value="<?php echo $session['id']; ?>">
                                        <button type="submit" name="set_current_session" class="btn btn-success btn-small" onclick="return confirm('Set this as current session?')">⭐ Set Current</button>
                                    </form>
                                <?php endif; ?>
                                
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this session? This will also delete all related semesters!');">
                                    <input type="hidden" name="session_id" value="<?php echo $session['id']; ?>">
                                    <button type="submit" name="delete_session" class="btn btn-danger btn-small">🗑️ Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Semesters Panel -->
        <div id="semester" class="panel <?php echo $action == 'semester' ? 'active' : ''; ?>">
            <h2 style="margin-bottom: 20px;">📖 Semester Management</h2>
            
            <div style="background: #f7fafc; padding: 20px; border-radius: 10px; margin-bottom: 30px;">
                <h3 style="margin-bottom: 15px;">Add New Semester</h3>
                <form method="POST">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Academic Session</label>
                            <select name="session_id" required>
                                <option value="">Select Session</option>
                                <?php foreach($sessions as $session): ?>
                                    <option value="<?php echo $session['id']; ?>" <?php echo $session['is_current'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($session['name']); ?>
                                        <?php echo $session['is_current'] ? '(Current)' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Semester</label>
                            <select name="semester_name" required>
                                <option value="First">First Semester</option>
                                <option value="Second">Second Semester</option>
                                <option value="Summer">Summer Semester</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" name="start_date" required>
                        </div>
                        <div class="form-group">
                            <label>End Date</label>
                            <input type="date" name="end_date" required>
                        </div>
                    </div>
                    <div class="checkbox-group" style="margin-bottom: 20px;">
                        <input type="checkbox" name="is_current" id="semester_current">
                        <label for="semester_current">Set as Current Semester</label>
                    </div>
                    <button type="submit" name="add_semester" class="btn btn-primary">Add Semester</button>
                </form>
            </div>
            
            <h3>Existing Semesters</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Session</th>
                            <th>Semester</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($semesters as $semester): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($semester['session_name']); ?></td>
                            <td><?php echo htmlspecialchars($semester['name']); ?></td>
                            <td><?php echo $semester['start_date']; ?></td>
                            <td><?php echo $semester['end_date']; ?></td>
                            <td>
                                <?php if($semester['is_current']): ?>
                                    <span class="badge badge-success">Current</span>
                                <?php endif; ?>
                            </td>
                            <td class="action-buttons">
                                <button onclick="openEditSemesterModal(<?php echo $semester['id']; ?>, <?php echo $semester['session_id']; ?>, '<?php echo htmlspecialchars($semester['name']); ?>', '<?php echo $semester['start_date']; ?>', '<?php echo $semester['end_date']; ?>')" class="btn btn-primary btn-small">✏️ Edit</button>
                                
                                <?php if(!$semester['is_current']): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="semester_id" value="<?php echo $semester['id']; ?>">
                                        <button type="submit" name="set_current_semester" class="btn btn-success btn-small" onclick="return confirm('Set this as current semester?')">⭐ Set Current</button>
                                    </form>
                                <?php endif; ?>
                                
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this semester?');">
                                    <input type="hidden" name="semester_id" value="<?php echo $semester['id']; ?>">
                                    <button type="submit" name="delete_semester" class="btn btn-danger btn-small">🗑️ Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- System Settings Panel -->
        <div id="system" class="panel <?php echo $action == 'system' ? 'active' : ''; ?>">
            <h2 style="margin-bottom: 20px;">🔧 System Configuration</h2>
            <form method="POST">
                <div class="form-row">
                    <div class="form-group">
                        <label>Max Login Attempts</label>
                        <input type="number" name="max_login_attempts" value="<?php echo $settings['max_login_attempts'] ?? 5; ?>" min="1" max="10">
                    </div>
                    <div class="form-group">
                        <label>Session Timeout (minutes)</label>
                        <input type="number" name="session_timeout" value="<?php echo $settings['session_timeout'] ?? 30; ?>" min="5" max="480">
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" name="enable_registration" id="enable_registration" <?php echo ($settings['enable_registration'] ?? 1) ? 'checked' : ''; ?>>
                        <label for="enable_registration">Enable Student Registration</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" name="enable_attendance" id="enable_attendance" <?php echo ($settings['enable_attendance'] ?? 1) ? 'checked' : ''; ?>>
                        <label for="enable_attendance">Enable Attendance Tracking</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" name="enable_result_check" id="enable_result_check" <?php echo ($settings['enable_result_check'] ?? 1) ? 'checked' : ''; ?>>
                        <label for="enable_result_check">Enable Result Checking</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" name="maintenance_mode" id="maintenance_mode" <?php echo ($settings['maintenance_mode'] ?? 0) ? 'checked' : ''; ?>>
                        <label for="maintenance_mode">Maintenance Mode (Only admins can access)</label>
                    </div>
                </div>
                
                <button type="submit" name="system_settings" class="btn btn-primary">Save System Settings</button>
            </form>
        </div>
        
        <!-- Backup Panel -->
        <div id="backup" class="panel <?php echo $action == 'backup' ? 'active' : ''; ?>">
            <h2 style="margin-bottom: 20px;">💾 Database Backup</h2>
            
            <div style="text-align: center; padding: 40px; background: #f7fafc; border-radius: 10px;">
                <p style="margin-bottom: 20px;">Create a full backup of your database including all students, staff, courses, and results.</p>
                <form method="POST">
                    <button type="submit" name="backup_db" class="btn btn-success" onclick="return confirm('Create database backup?')">📥 Create Database Backup</button>
                </form>
                <p style="margin-top: 20px; font-size: 12px; color: #718096;">Backups are stored in the /backups/ directory</p>
            </div>
        </div>
        
        <!-- System Info Panel -->
        <div id="info" class="panel">
            <h2 style="margin-bottom: 20px;">ℹ️ System Information</h2>
            
            <div class="info-box">
                <h4>PHP Information</h4>
                <p><strong>PHP Version:</strong> <?php echo phpversion(); ?></p>
                <p><strong>Server Software:</strong> <?php echo $_SERVER['SERVER_SOFTWARE']; ?></p>
                <p><strong>MySQL Version:</strong> <?php echo $pdo->getAttribute(PDO::ATTR_SERVER_VERSION); ?></p>
            </div>
            
            <div class="info-box">
                <h4>Extensions Status</h4>
                <p><strong>GD Library:</strong> <?php echo extension_loaded('gd') ? '✓ Enabled' : '✗ Disabled'; ?></p>
                <p><strong>PDO MySQL:</strong> <?php echo extension_loaded('pdo_mysql') ? '✓ Enabled' : '✗ Disabled'; ?></p>
                <p><strong>OpenSSL:</strong> <?php echo extension_loaded('openssl') ? '✓ Enabled' : '✗ Disabled'; ?></p>
                <p><strong>JSON:</strong> <?php echo extension_loaded('json') ? '✓ Enabled' : '✗ Disabled'; ?></p>
            </div>
            
            <div class="info-box">
                <h4>Directory Permissions</h4>
                <p><strong>assets/qrcodes/:</strong> <?php echo is_writable('../assets/qrcodes/') ? '✓ Writable' : '✗ Not Writable'; ?></p>
                <p><strong>backups/:</strong> <?php echo is_writable('../backups/') ? '✓ Writable' : '✗ Not Writable'; ?></p>
            </div>
        </div>
    </div>
    
    <!-- Edit Session Modal -->
    <div id="editSessionModal" class="modal">
        <div class="modal-content">
            <h2 style="margin-bottom: 20px;">Edit Academic Session</h2>
            <form method="POST">
                <input type="hidden" name="session_id" id="edit_session_id">
                <div class="form-group">
                    <label>Session Name</label>
                    <input type="text" name="session_name" id="edit_session_name" required>
                </div>
                <div class="form-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" id="edit_start_date" required>
                </div>
                <div class="form-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" id="edit_end_date" required>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="submit" name="edit_session" class="btn btn-primary">Update Session</button>
                    <button type="button" onclick="closeEditSessionModal()" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Semester Modal -->
    <div id="editSemesterModal" class="modal">
        <div class="modal-content">
            <h2 style="margin-bottom: 20px;">Edit Semester</h2>
            <form method="POST">
                <input type="hidden" name="semester_id" id="edit_semester_id">
                <div class="form-group">
                    <label>Academic Session</label>
                    <select name="session_id" id="edit_session_id_select" required>
                        <option value="">Select Session</option>
                        <?php foreach($sessions as $session): ?>
                            <option value="<?php echo $session['id']; ?>">
                                <?php echo htmlspecialchars($session['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Semester</label>
                    <select name="semester_name" id="edit_semester_name" required>
                        <option value="First">First Semester</option>
                        <option value="Second">Second Semester</option>
                        <option value="Summer">Summer Semester</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" id="edit_semester_start_date" required>
                </div>
                <div class="form-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" id="edit_semester_end_date" required>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="submit" name="edit_semester" class="btn btn-primary">Update Semester</button>
                    <button type="button" onclick="closeEditSemesterModal()" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function showTab(tabName) {
            document.querySelectorAll('.panel').forEach(panel => {
                panel.classList.remove('active');
            });
            
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
            history.pushState(null, '', '?action=' + tabName);
        }
        
        function openEditSessionModal(id, name, startDate, endDate) {
            document.getElementById('edit_session_id').value = id;
            document.getElementById('edit_session_name').value = name;
            document.getElementById('edit_start_date').value = startDate;
            document.getElementById('edit_end_date').value = endDate;
            document.getElementById('editSessionModal').style.display = 'flex';
        }
        
        function closeEditSessionModal() {
            document.getElementById('editSessionModal').style.display = 'none';
        }
        
        function openEditSemesterModal(id, sessionId, name, startDate, endDate) {
            document.getElementById('edit_semester_id').value = id;
            document.getElementById('edit_session_id_select').value = sessionId;
            document.getElementById('edit_semester_name').value = name;
            document.getElementById('edit_semester_start_date').value = startDate;
            document.getElementById('edit_semester_end_date').value = endDate;
            document.getElementById('editSemesterModal').style.display = 'flex';
        }
        
        function closeEditSemesterModal() {
            document.getElementById('editSemesterModal').style.display = 'none';
        }
        
        window.onclick = function(event) {
            const sessionModal = document.getElementById('editSessionModal');
            const semesterModal = document.getElementById('editSemesterModal');
            if (event.target === sessionModal) closeEditSessionModal();
            if (event.target === semesterModal) closeEditSemesterModal();
        }
        
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
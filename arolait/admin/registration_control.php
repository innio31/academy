<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

$message = '';
$error = '';

// Simple POST handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_settings'])) {
        $semester_id = (int)$_POST['semester_id'];
        $is_open = isset($_POST['is_open']) ? 1 : 0;
        $open_date = !empty($_POST['open_date']) ? $_POST['open_date'] : NULL;
        $close_date = !empty($_POST['close_date']) ? $_POST['close_date'] : NULL;
        $max_credits = (int)$_POST['max_credits'];
        $min_credits = (int)$_POST['min_credits'];
        
        try {
            // Check if exists
            $check = $pdo->prepare("SELECT id FROM registration_settings WHERE semester_id = ?");
            $check->execute([$semester_id]);
            
            if ($check->fetch()) {
                $sql = "UPDATE registration_settings SET is_open = ?, open_date = ?, close_date = ?, max_credits = ?, min_credits = ? WHERE semester_id = ?";
                $stmt = $pdo->prepare($sql);
                $result = $stmt->execute([$is_open, $open_date, $close_date, $max_credits, $min_credits, $semester_id]);
            } else {
                $sql = "INSERT INTO registration_settings (semester_id, is_open, open_date, close_date, max_credits, min_credits) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $result = $stmt->execute([$semester_id, $is_open, $open_date, $close_date, $max_credits, $min_credits]);
            }
            
            if ($result) {
                $message = "Registration settings saved successfully!";
            } else {
                $error = "Failed to save registration settings.";
            }
        } catch (Exception $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['toggle_status'])) {
        $semester_id = (int)$_POST['semester_id'];
        $is_open = (int)$_POST['is_open'];
        
        try {
            $stmt = $pdo->prepare("UPDATE registration_settings SET is_open = ? WHERE semester_id = ?");
            if ($stmt->execute([$is_open, $semester_id])) {
                $message = "Registration status updated successfully!";
            } else {
                $error = "Failed to update registration status.";
            }
        } catch (Exception $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['delete_settings'])) {
        $semester_id = (int)$_POST['semester_id'];
        
        try {
            $stmt = $pdo->prepare("DELETE FROM registration_settings WHERE semester_id = ?");
            if ($stmt->execute([$semester_id])) {
                $message = "Registration settings cleared for this semester.";
            } else {
                $error = "Failed to clear registration settings.";
            }
        } catch (Exception $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Get all semesters
$semesters = [];
try {
    $sql = "SELECT s.*, a.name as session_name, a.is_current as session_current,
                   rs.is_open, rs.open_date, rs.close_date, rs.max_credits, rs.min_credits,
                   rs.id as setting_id
            FROM semesters s
            JOIN academic_sessions a ON s.session_id = a.id
            LEFT JOIN registration_settings rs ON s.id = rs.semester_id
            ORDER BY a.start_date DESC, s.id DESC";
    $result = $pdo->query($sql);
    $semesters = $result->fetchAll();
} catch (Exception $e) {
    $error = "Error loading semesters: " . $e->getMessage();
}

$default_min_credits = 12;
$default_max_credits = 24;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Control - Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fb;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .header {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 24px; color: #2d3748; }
        .card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .card h2 { margin-bottom: 20px; color: #2d3748; font-size: 18px; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #4a5568; }
        input, select {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }
        .checkbox-group input { width: 20px; height: 20px; }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-success { background: #48bb78; color: white; }
        .btn-danger { background: #f56565; color: white; }
        .btn-outline { background: transparent; border: 1px solid #667eea; color: #667eea; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .message {
            background: #c6f6d5;
            color: #22543d;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .error {
            background: #fed7d7;
            color: #c53030;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f7fafc; font-weight: 600; }
        .badge-open { background: #c6f6d5; color: #22543d; padding: 4px 12px; border-radius: 20px; font-size: 12px; display: inline-block; }
        .badge-closed { background: #fed7d7; color: #c53030; padding: 4px 12px; border-radius: 20px; font-size: 12px; display: inline-block; }
        .action-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
        .row-2cols { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 768px) { .row-2cols { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>🎛️ Course Registration Control</h1>
                <p>Manage registration periods and credit limits</p>
            </div>
            <a href="index.php" class="btn btn-outline">← Back to Dashboard</a>
        </div>
        
        <?php if($message): ?>
            <div class="message">✓ <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if($error): ?>
            <div class="error">✗ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <!-- Settings Form -->
        <div class="card">
            <h2>📝 Configure Registration Settings</h2>
            <form method="POST" action="">
                <div class="form-group">
                    <label>Select Semester</label>
                    <select name="semester_id" required>
                        <option value="">-- Select a Semester --</option>
                        <?php foreach($semesters as $sem): ?>
                            <option value="<?php echo $sem['id']; ?>">
                                <?php echo $sem['name']; ?> Semester - <?php echo $sem['session_name']; ?>
                                <?php echo $sem['session_current'] ? '(Current)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="is_open" id="is_open" value="1">
                    <label for="is_open">✅ Open Registration for this Semester</label>
                </div>
                
                <div class="row-2cols">
                    <div class="form-group">
                        <label>Open Date</label>
                        <input type="datetime-local" name="open_date">
                    </div>
                    <div class="form-group">
                        <label>Close Date</label>
                        <input type="datetime-local" name="close_date">
                    </div>
                </div>
                
                <div class="row-2cols">
                    <div class="form-group">
                        <label>Minimum Credits</label>
                        <input type="number" name="min_credits" value="12" min="1" max="30" required>
                    </div>
                    <div class="form-group">
                        <label>Maximum Credits</label>
                        <input type="number" name="max_credits" value="24" min="1" max="36" required>
                    </div>
                </div>
                
                <button type="submit" name="save_settings" class="btn btn-primary">💾 Save Settings</button>
            </form>
        </div>
        
        <!-- Current Status Table -->
        <div class="card">
            <h2>📋 Current Registration Status</h2>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr><th>Semester</th><th>Session</th><th>Status</th><th>Credit Limits</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($semesters as $sem): 
                            $is_open = isset($sem['is_open']) ? $sem['is_open'] : 0;
                            $has_settings = isset($sem['setting_id']) && !is_null($sem['setting_id']);
                        ?>
                            <tr>
                                <td><?php echo $sem['name']; ?> <?php echo $sem['session_current'] ? '(Current)' : ''; ?></td>
                                <td><?php echo $sem['session_name']; ?></td>
                                <td>
                                    <?php if($is_open): ?>
                                        <span class="badge-open">✓ Open</span>
                                    <?php else: ?>
                                        <span class="badge-closed">✗ Closed</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($has_settings): ?>
                                        Min: <?php echo $sem['min_credits']; ?><br>Max: <?php echo $sem['max_credits']; ?>
                                    <?php else: ?>
                                        Not configured
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="semester_id" value="<?php echo $sem['id']; ?>">
                                            <input type="hidden" name="is_open" value="<?php echo $is_open ? 0 : 1; ?>">
                                            <button type="submit" name="toggle_status" class="btn btn-sm <?php echo $is_open ? 'btn-danger' : 'btn-success'; ?>">
                                                <?php echo $is_open ? 'Close' : 'Open'; ?>
                                            </button>
                                        </form>
                                        
                                        <?php if($has_settings): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Clear settings for this semester?')">
                                            <input type="hidden" name="semester_id" value="<?php echo $sem['id']; ?>">
                                            <button type="submit" name="delete_settings" class="btn btn-danger btn-sm">Clear</button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
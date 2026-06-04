<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole(['staff']);

$attendance_id = $_GET['id'] ?? 0;

if (!$attendance_id) {
    header("Location: attendance_history.php?error=Invalid attendance record");
    exit();
}

// Get attendance record
$stmt = $pdo->prepare("
    SELECT a.*, c.code, c.title, s.reg_number, CONCAT(u.first_name, ' ', u.last_name) as student_name
    FROM attendance a
    JOIN courses c ON a.course_id = c.id
    JOIN students s ON a.student_id = s.id
    JOIN users u ON s.user_id = u.id
    WHERE a.id = ?
");
$stmt->execute([$attendance_id]);
$attendance = $stmt->fetch();

if (!$attendance) {
    header("Location: attendance_history.php?error=Attendance record not found");
    exit();
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $status = $_POST['status'];
    
    $stmt = $pdo->prepare("UPDATE attendance SET status = ? WHERE id = ?");
    $stmt->execute([$status, $attendance_id]);
    
    header("Location: attendance_history.php?course_offering_id=" . $_GET['course_offering_id'] . "&message=Attendance updated");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Attendance - Staff Portal</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fb;
            padding: 20px;
        }
        .container { max-width: 600px; margin: 0 auto; }
        .card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-secondary { background: #e2e8f0; color: #4a5568; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; }
        select {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>Edit Attendance Record</h1>
            <p><strong>Student:</strong> <?php echo htmlspecialchars($attendance['student_name']); ?> (<?php echo $attendance['reg_number']; ?>)</p>
            <p><strong>Course:</strong> <?php echo htmlspecialchars($attendance['code'] . ' - ' . $attendance['title']); ?></p>
            <p><strong>Date:</strong> <?php echo date('F j, Y', strtotime($attendance['date'])); ?></p>
            
            <form method="POST">
                <div class="form-group">
                    <label>Attendance Status</label>
                    <select name="status" required>
                        <option value="present" <?php echo $attendance['status'] == 'present' ? 'selected' : ''; ?>>Present</option>
                        <option value="absent" <?php echo $attendance['status'] == 'absent' ? 'selected' : ''; ?>>Absent</option>
                        <option value="late" <?php echo $attendance['status'] == 'late' ? 'selected' : ''; ?>>Late</option>
                    </select>
                </div>
                
                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <a href="attendance_history.php?course_offering_id=<?php echo $_GET['course_offering_id']; ?>" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
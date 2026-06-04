<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

$offering_id = $_GET['id'] ?? 0;

if (!$offering_id) {
    header("Location: courses.php?view=offerings&error=Invalid offering ID");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lecturer_id = $_POST['lecturer_id'] ?: null;
    $max_students = $_POST['max_students'] ?: null;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE course_offerings 
            SET lecturer_id = ?, max_students = ?
            WHERE id = ?
        ");
        $stmt->execute([$lecturer_id, $max_students, $offering_id]);
        header("Location: courses.php?view=offerings&message=Course offering updated successfully");
        exit();
    } catch(PDOException $e) {
        $error = $e->getMessage();
    }
}

// Get offering details
$stmt = $pdo->prepare("
    SELECT co.*, 
           c.code, c.title, c.credit_unit, c.level,
           a.name as session_name, s.name as semester_name
    FROM course_offerings co
    JOIN courses c ON co.course_id = c.id
    JOIN semesters s ON co.semester_id = s.id
    JOIN academic_sessions a ON s.session_id = a.id
    WHERE co.id = ?
");
$stmt->execute([$offering_id]);
$offering = $stmt->fetch();

if (!$offering) {
    header("Location: courses.php?view=offerings&error=Offering not found");
    exit();
}

// Get all lecturers
$lecturers = $pdo->query("
    SELECT u.id, u.first_name, u.last_name, s.staff_number 
    FROM staff s 
    JOIN users u ON s.user_id = u.id 
    ORDER BY u.last_name
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Course Offering - University Portal</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
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
        .card h2 { margin-bottom: 20px; color: #2d3748; }
        .course-info {
            background: #f7fafc;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .course-info p { margin: 5px 0; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 14px; }
        select, input {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }
        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-secondary { background: #e2e8f0; color: #4a5568; }
        .error { color: #f56565; margin-bottom: 15px; padding: 10px; background: #fed7d7; border-radius: 8px; }
        .form-row { display: flex; gap: 10px; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h2>Edit Course Offering</h2>
            
            <?php if(isset($error)): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <div class="course-info">
                <p><strong>Course:</strong> <?php echo htmlspecialchars($offering['code'] . ' - ' . $offering['title']); ?></p>
                <p><strong>Level:</strong> <?php echo $offering['level']; ?> Level | <strong>Credit Units:</strong> <?php echo $offering['credit_unit']; ?></p>
                <p><strong>Session:</strong> <?php echo htmlspecialchars($offering['session_name']); ?></p>
                <p><strong>Semester:</strong> <?php echo htmlspecialchars($offering['semester_name']); ?> Semester</p>
            </div>
            
            <form method="POST">
                <div class="form-group">
                    <label>Assign Lecturer</label>
                    <select name="lecturer_id">
                        <option value="">-- Select Lecturer --</option>
                        <?php foreach($lecturers as $lect): ?>
                            <option value="<?php echo $lect['id']; ?>" <?php echo ($offering['lecturer_id'] == $lect['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($lect['first_name'] . ' ' . $lect['last_name'] . ' (' . $lect['staff_number'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Maximum Students (Optional)</label>
                    <input type="number" name="max_students" value="<?php echo $offering['max_students']; ?>" placeholder="Leave empty for unlimited">
                </div>
                
                <div class="form-row">
                    <button type="submit" class="btn btn-primary">Update Offering</button>
                    <a href="courses.php?view=offerings" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireRole(['super_admin', 'admin']);

$staff_id = $_GET['staff_id'] ?? 0;

if (!$staff_id) {
    die("Staff ID required");
}

// Get staff details with user_id
$stmt = $pdo->prepare("
    SELECT s.*, u.first_name, u.last_name, u.email, u.id as user_id
    FROM staff s 
    JOIN users u ON s.user_id = u.id 
    WHERE s.id = ?
");
$stmt->execute([$staff_id]);
$staff = $stmt->fetch();

if (!$staff) {
    die("Staff not found");
}

// Get courses taught by this staff from course_offerings
$courses = $pdo->prepare("
    SELECT 
        c.*, 
        d.name as department_name, 
        s.name as semester_name, 
        a.name as session_name,
        co.lecturer_id
    FROM course_offerings co
    JOIN courses c ON co.course_id = c.id
    JOIN departments d ON c.department_id = d.id
    JOIN semesters s ON co.semester_id = s.id
    JOIN academic_sessions a ON s.session_id = a.id
    WHERE co.lecturer_id = ?
    ORDER BY a.start_date DESC, c.code
");
$courses->execute([$staff['user_id']]);
$courses = $courses->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Courses - <?php echo $staff['first_name'] . ' ' . $staff['last_name']; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            padding: 20px; 
            background: #f5f7fb; 
        }
        .container { 
            max-width: 1200px; 
            margin: 0 auto; 
        }
        .header { 
            background: white; 
            padding: 25px; 
            border-radius: 12px; 
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .header h1 {
            color: #2d3748;
            font-size: 24px;
            margin-bottom: 8px;
        }
        .staff-info {
            color: #718096;
            margin: 10px 0;
            padding: 10px 0;
            border-top: 1px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            background: white; 
            border-radius: 12px; 
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        th, td { 
            padding: 14px; 
            text-align: left; 
            border-bottom: 1px solid #e2e8f0; 
        }
        th { 
            background: #667eea; 
            color: white;
            font-weight: 600;
        }
        tr:hover {
            background: #f7fafc;
        }
        .btn { 
            display: inline-block;
            padding: 10px 20px; 
            background: #667eea; 
            color: white; 
            text-decoration: none; 
            border-radius: 8px;
            font-size: 14px;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #5a67d8;
        }
        .empty-state {
            text-align: center;
            padding: 60px;
            background: white;
            border-radius: 12px;
            color: #718096;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            background: #e2e8f0;
            color: #4a5568;
            border-radius: 20px;
            font-size: 12px;
        }
        @media (max-width: 768px) {
            table, thead, tbody, th, td, tr {
                display: block;
            }
            thead tr {
                display: none;
            }
            tr {
                margin-bottom: 15px;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
            }
            td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 10px;
                border-bottom: 1px solid #e2e8f0;
            }
            td:before {
                content: attr(data-label);
                font-weight: 600;
                width: 40%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📖 Courses Taught by <?php echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?></h1>
            <div class="staff-info">
                <strong>Staff ID:</strong> <?php echo htmlspecialchars($staff['staff_number']); ?> | 
                <strong>Email:</strong> <?php echo htmlspecialchars($staff['email']); ?> |
                <strong>Department:</strong> <?php echo htmlspecialchars($staff['department_name']); ?>
            </div>
            <a href="staff.php" class="btn">← Back to Staff List</a>
        </div>
        
        <?php if(empty($courses)): ?>
            <div class="empty-state">
                <div style="font-size: 48px; margin-bottom: 15px;">📚</div>
                <p>No courses assigned to this staff member yet.</p>
                <p style="font-size: 13px; margin-top: 8px;">Assign courses through the course offering system.</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Course Code</th>
                        <th>Course Title</th>
                        <th>Department</th>
                        <th>Credit Unit</th>
                        <th>Semester</th>
                        <th>Session</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($courses as $course): ?>
                        <tr>
                            <td data-label="Course Code">
                                <strong><?php echo htmlspecialchars($course['code']); ?></strong>
                            </td>
                            <td data-label="Course Title"><?php echo htmlspecialchars($course['title']); ?></td>
                            <td data-label="Department"><?php echo htmlspecialchars($course['department_name']); ?></td>
                            <td data-label="Credit Unit">
                                <span class="badge"><?php echo $course['credit_unit']; ?> Unit(s)</span>
                            </td>
                            <td data-label="Semester"><?php echo htmlspecialchars($course['semester_name']); ?></td>
                            <td data-label="Session"><?php echo htmlspecialchars($course['session_name']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>
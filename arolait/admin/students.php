<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole(['super_admin', 'admin']);

// Handle filters
$search = $_GET['search'] ?? '';
$department_id = $_GET['department'] ?? '';
$level = $_GET['level'] ?? '';

// Build query
$sql = "SELECT 
            s.id, s.reg_number, s.current_level, s.id_card_issued,
            u.first_name, u.last_name, u.email, u.phone,
            d.name as department_name
        FROM students s
        JOIN users u ON s.user_id = u.id
        JOIN departments d ON s.department_id = d.id
        WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR s.reg_number LIKE ?)";
    $searchParam = "%$search%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam]);
}

if ($department_id) {
    $sql .= " AND s.department_id = ?";
    $params[] = $department_id;
}

if ($level) {
    $sql .= " AND s.current_level = ?";
    $params[] = $level;
}

$sql .= " ORDER BY u.last_name ASC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Get departments for filter
$depts = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Student Management - University Portal</title>
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
        
        /* Mobile-first container */
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        /* Header */
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
        
        /* Action Bar - Mobile friendly */
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
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5a67d8;
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: #48bb78;
            color: white;
        }
        
        .btn-info {
            background: #4299e1;
            color: white;
        }
        
        .btn-danger {
            background: #f56565;
            color: white;
        }
        
        .search-box {
            flex: 1;
            min-width: 200px;
            display: flex;
            gap: 8px;
        }
        
        .search-box input {
            flex: 1;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .filter-select {
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: white;
            font-size: 14px;
        }
        
        /* Stats Cards */
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            padding: 16px;
            border-radius: 12px;
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
            margin-top: 4px;
        }
        
        /* Student Cards (Mobile optimized) */
        .students-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px;
        }
        
        .student-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .student-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 16px;
            position: relative;
        }
        
        .student-name {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .student-reg {
            font-size: 12px;
            opacity: 0.9;
            font-family: monospace;
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
        
        .card-actions {
            display: flex;
            gap: 8px;
            padding: 16px;
            background: #f7fafc;
            flex-wrap: wrap;
        }
        
        .btn-small {
            padding: 8px 12px;
            font-size: 12px;
            flex: 1;
            text-align: center;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .badge-success {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .badge-warning {
            background: #feebc8;
            color: #7c2d12;
        }
        
        /* Modal */
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
            max-height: 80%;
            overflow-y: auto;
        }
        
        /* Responsive */
        @media (max-width: 640px) {
            .action-bar {
                flex-direction: column;
            }
            
            .search-box {
                width: 100%;
            }
            
            .filter-select {
                width: 100%;
            }
            
            .students-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Loading spinner */
        .loader {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Flash messages */
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
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .flash-success { background: #48bb78; }
        .flash-error { background: #f56565; }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h1>👨‍🎓 Student Management</h1>
                    <p>Manage all students, generate QR codes, and print ID cards</p>
                </div>
                <a href="index.php" style="color: #667eea; text-decoration: none;">← Back to Dashboard</a>
            </div>
        </div>
        
        <!-- Stats -->
        <div class="stats">
            <div class="stat-card">
                <div class="stat-number"><?php echo count($students); ?></div>
                <div class="stat-label">Total Students</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="idCardCount">0</div>
                <div class="stat-label">ID Cards Issued</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="qrCount">0</div>
                <div class="stat-label">QR Codes Generated</div>
            </div>
        </div>
        
        <!-- Action Bar -->
        <div class="action-bar">
            <form method="GET" action="" style="display: flex; gap: 8px; flex: 1; flex-wrap: wrap;">
                <div class="search-box">
                    <input type="text" name="search" placeholder="Search by name or reg number..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary" style="padding: 12px 20px;">🔍</button>
                </div>
                <select name="department" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Departments</option>
                    <?php foreach($depts as $dept): ?>
                        <option value="<?php echo $dept['id']; ?>" 
                                <?php echo $department_id == $dept['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="level" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Levels</option>
                    <option value="100" <?php echo $level == '100' ? 'selected' : ''; ?>>100 Level</option>
                    <option value="200" <?php echo $level == '200' ? 'selected' : ''; ?>>200 Level</option>
                    <option value="300" <?php echo $level == '300' ? 'selected' : ''; ?>>300 Level</option>
                    <option value="400" <?php echo $level == '400' ? 'selected' : ''; ?>>400 Level</option>
                    <option value="500" <?php echo $level == '500' ? 'selected' : ''; ?>>500 Level</option>
                </select>
            </form>
            <a href="add_student.php" class="btn btn-primary">➕ Add New Student</a>
        </div>
        
        <!-- Students Grid -->
        <div class="students-grid" id="studentsGrid">
            <?php foreach($students as $student): ?>
                <div class="student-card">
                    <div class="card-header">
                        <div class="student-name">
                            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                        </div>
                        <div class="student-reg">
                            📝 Reg: <?php echo htmlspecialchars($student['reg_number']); ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="info-row">
                            <span class="info-label">📧 Email</span>
                            <span class="info-value"><?php echo htmlspecialchars($student['email']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">📞 Phone</span>
                            <span class="info-value"><?php echo $student['phone'] ?: 'Not provided'; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">🏛️ Department</span>
                            <span class="info-value"><?php echo htmlspecialchars($student['department_name']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">📚 Level</span>
                            <span class="info-value">
                                <?php echo $student['current_level']; ?> Level
                                <?php if($student['id_card_issued']): ?>
                                    <span class="badge badge-success">🪪 ID Card Issued</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">⚠️ No ID Card</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-actions">
                        <a href="view_student.php?id=<?php echo $student['id']; ?>" class="btn btn-small btn-info">👁️ View</a>
                        <a href="edit_student.php?id=<?php echo $student['id']; ?>" class="btn btn-small btn-primary">✏️ Edit</a>
                        <button onclick="generateQR(<?php echo $student['id']; ?>)" class="btn btn-small btn-secondary">📱 QR Code</button>
                        <button onclick="printIDCard(<?php echo $student['id']; ?>)" class="btn btn-small btn-primary">🪪 ID Card</button>
                        <button onclick="deleteStudent(<?php echo $student['id']; ?>)" class="btn btn-small btn-danger">🗑️ Delete</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if(empty($students)): ?>
            <div style="text-align: center; padding: 60px 20px; background: white; border-radius: 12px;">
                <p style="color: #718096;">No students found. Click "Add New Student" to get started.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- QR Code Modal -->
    <div id="qrModal" class="modal">
        <div class="modal-content">
            <h2 style="margin-bottom: 20px;">📱 Student QR Code</h2>
            <div id="qrCodeDisplay" style="text-align: center;">
                <div class="loader"></div>
                <p>Generating QR Code...</p>
            </div>
            <button onclick="closeModal()" class="btn btn-primary" style="margin-top: 20px; width: 100%;">Close</button>
        </div>
    </div>
    
    <script>
        // Update stats
        let idCardCount = 0;
        let qrCount = 0;
        
        <?php foreach($students as $student): ?>
            <?php if($student['id_card_issued']): ?>
                idCardCount++;
            <?php endif; ?>
        <?php endforeach; ?>
        
        document.getElementById('idCardCount').innerText = idCardCount;
        document.getElementById('qrCount').innerText = <?php echo count($students); ?>;
        
        // Generate QR Code
        function generateQR(studentId) {
            const modal = document.getElementById('qrModal');
            const qrDisplay = document.getElementById('qrCodeDisplay');
            modal.style.display = 'flex';
            qrDisplay.innerHTML = '<div class="loader"></div><p>Generating QR Code...</p>';
            
            fetch('generate_qr.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'student_id=' + studentId
            })
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    qrDisplay.innerHTML = `
                        <img src="${data.qr_url}" style="max-width: 200px; margin: 20px auto; display: block;">
                        <p><strong>Student ID:</strong> ${data.reg_number}</p>
                        <p><small>Scan this QR code for attendance</small></p>
                        <button onclick="window.open('${data.qr_url}')" class="btn btn-secondary" style="margin-top: 10px;">📥 Download QR</button>
                    `;
                } else {
                    qrDisplay.innerHTML = `<p style="color: red;">Error: ${data.error}</p>`;
                }
            })
            .catch(error => {
                qrDisplay.innerHTML = `<p style="color: red;">Error generating QR code</p>`;
            });
        }
        
        // Print ID Card
        function printIDCard(studentId) {
            window.open('print_id_card.php?id=' + studentId, '_blank');
        }
        
        // Delete Student
        function deleteStudent(studentId) {
            if(confirm('Are you sure you want to delete this student? This action cannot be undone!')) {
                window.location.href = 'delete_student.php?id=' + studentId;
            }
        }
        
        // Close Modal
        function closeModal() {
            document.getElementById('qrModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('qrModal');
            if(event.target === modal) {
                modal.style.display = 'none';
            }
        }
        
        // Show flash message if exists
        const urlParams = new URLSearchParams(window.location.search);
        const message = urlParams.get('message');
        const error = urlParams.get('error');
        
        if(message) {
            showFlash(message, 'success');
        }
        if(error) {
            showFlash(error, 'error');
        }
        
        function showFlash(msg, type) {
            const flash = document.createElement('div');
            flash.className = `flash-message flash-${type}`;
            flash.innerHTML = msg;
            document.body.appendChild(flash);
            setTimeout(() => flash.remove(), 3000);
        }
    </script>
</body>
</html>
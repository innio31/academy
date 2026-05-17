<?php
// gos/report-card/settings.php - Grading System Configuration (Admin only)
session_start();
require_once '../includes/config.php';
require_once '../includes/theme.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_type = $_SESSION['user_type'] ?? 'student';
if ($user_type !== 'admin') {
    header("Location: index.php?message=Access+denied&type=error");
    exit();
}

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;

// Get current settings
$current_session = date('Y') . '/' . (date('Y') + 1);
$current_term = 'First';

// Handle form submission
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $session = $_POST['session'];
    $term = $_POST['term'];
    $max_score = $_POST['max_score'];
    $grading_system = $_POST['grading_system'];
    
    // Get score types
    $score_types = [];
    if (isset($_POST['score_type_name'])) {
        for ($i = 0; $i < count($_POST['score_type_name']); $i++) {
            if (!empty($_POST['score_type_name'][$i])) {
                $score_types[] = [
                    'name' => $_POST['score_type_name'][$i],
                    'max_score' => intval($_POST['score_type_max'][$i])
                ];
            }
        }
    }
    
    // Check if settings exist
    $stmt = $pdo->prepare("SELECT id FROM report_card_settings WHERE session = ? AND term = ? AND school_id = ?");
    $stmt->execute([$session, $term, $school_id]);
    
    if ($stmt->fetch()) {
        $stmt = $pdo->prepare("UPDATE report_card_settings SET max_score = ?, score_types = ?, grading_system = ?, updated_at = NOW() WHERE session = ? AND term = ? AND school_id = ?");
        $stmt->execute([$max_score, json_encode($score_types), $grading_system, $session, $term, $school_id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO report_card_settings (session, term, max_score, score_types, grading_system, school_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$session, $term, $max_score, json_encode($score_types), $grading_system, $school_id]);
    }
    
    $message = "Settings saved successfully!";
    $message_type = "success";
}

// Get existing settings
$stmt = $pdo->prepare("SELECT * FROM report_card_settings WHERE school_id = ? ORDER BY session DESC, 
                       CASE term WHEN 'Third' THEN 3 WHEN 'Second' THEN 2 WHEN 'First' THEN 1 END DESC LIMIT 1");
$stmt->execute([$school_id]);
$settings = $stmt->fetch();

$score_types = [];
if ($settings && !empty($settings['score_types'])) {
    $score_types = json_decode($settings['score_types'], true);
}

if (empty($score_types)) {
    $score_types = [
        ['name' => 'CA 1', 'max_score' => 20],
        ['name' => 'CA 2', 'max_score' => 20],
        ['name' => 'Exam', 'max_score' => 60]
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Report Card Settings</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary-color: <?php echo $primary_color; ?>; --secondary-color: #d4af7a; --success-color: #27ae60; --danger-color: #e74c3c; --light-color: #ecf0f1; --sidebar-width: 260px; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background: #f5f6fa; }
        
        .sidebar {
            position: fixed; top: 0; left: 0; width: var(--sidebar-width); height: 100vh;
            background: linear-gradient(180deg, var(--primary-color), #2c3e50); color: white;
            padding: 20px 0; z-index: 100; transform: translateX(-100%); transition: all 0.3s ease;
        }
        .sidebar.active { transform: translateX(0); }
        .logo { display: flex; align-items: center; gap: 10px; padding: 0 20px; margin-bottom: 20px; }
        .logo-icon { width: 40px; height: 40px; background: var(--secondary-color); border-radius: 10px; display: flex; align-items: center; justify-content: center; }
        .admin-info { text-align: center; padding: 15px; background: rgba(255,255,255,0.1); border-radius: 10px; margin: 0 15px 20px; }
        .nav-links { list-style: none; padding: 0 15px; }
        .nav-links li { margin-bottom: 5px; }
        .nav-links a { display: flex; align-items: center; gap: 12px; padding: 12px 15px; color: rgba(255,255,255,0.9); text-decoration: none; border-radius: 8px; }
        .nav-links a:hover, .nav-links a.active { background: rgba(255,255,255,0.2); }
        
        .main-content { margin-left: 0; padding: 20px; min-height: 100vh; }
        .mobile-menu-btn {
            position: fixed; top: 20px; right: 20px; z-index: 101;
            background: var(--primary-color); color: white; border: none;
            width: 45px; height: 45px; border-radius: 10px; font-size: 20px; cursor: pointer;
        }
        .top-header {
            background: white; padding: 20px 25px; border-radius: 15px; margin-bottom: 30px;
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;
        }
        .header-title h1 { color: var(--primary-color); font-size: 1.6rem; }
        
        .form-container {
            background: white; border-radius: 15px; padding: 30px; margin-bottom: 20px;
        }
        .form-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;
            margin-bottom: 20px;
        }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: #555; }
        .form-control, .form-select {
            width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 8px; font-family: 'Poppins', sans-serif;
        }
        .score-item {
            display: flex; align-items: center; gap: 15px; margin-bottom: 15px;
            padding: 10px; background: #f8f9fa; border-radius: 8px;
        }
        .score-item input[type="text"] { flex: 2; }
        .score-item input[type="number"] { flex: 1; }
        .btn {
            padding: 10px 20px; border-radius: 8px; border: none; cursor: pointer;
            font-weight: 500; display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-primary { background: var(--primary-color); color: white; }
        .btn-success { background: var(--success-color); color: white; }
        .btn-danger { background: var(--danger-color); color: white; }
        .btn-secondary { background: #95a5a6; color: white; }
        
        .alert {
            padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;
        }
        .alert-success { background: #d5f4e6; color: #155724; border-left: 4px solid var(--success-color); }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid var(--danger-color); }
        
        @media (min-width: 769px) {
            .sidebar { transform: translateX(0); }
            .main-content { margin-left: var(--sidebar-width); }
            .mobile-menu-btn { display: none; }
        }
        @media (max-width: 768px) { .form-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
            <div class="logo-text"><h3><?php echo htmlspecialchars($school_name); ?></h3><p>Report Cards</p></div>
        </div>
        <div class="admin-info">
            <h4><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['user_name'] ?? 'Admin'); ?></h4>
            <p>Administrator</p>
        </div>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="enter-scores.php"><i class="fas fa-edit"></i> Enter Scores</a></li>
            <li><a href="enter-comments.php"><i class="fas fa-comment"></i> Comments</a></li>
            <li><a href="settings.php" class="active"><i class="fas fa-cog"></i> Settings</a></li>
            <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-cog"></i> Report Card Settings</h1>
                <p>Configure grading system and score types</p>
            </div>
        </div>
        
        <div class="form-container">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Session</label>
                        <input type="text" name="session" class="form-control" value="<?php echo $settings['session'] ?? $current_session; ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Term</label>
                        <select name="term" class="form-select">
                            <option value="First" <?php echo ($settings['term'] ?? '') == 'First' ? 'selected' : ''; ?>>First Term</option>
                            <option value="Second" <?php echo ($settings['term'] ?? '') == 'Second' ? 'selected' : ''; ?>>Second Term</option>
                            <option value="Third" <?php echo ($settings['term'] ?? '') == 'Third' ? 'selected' : ''; ?>>Third Term</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Maximum Score</label>
                        <input type="number" name="max_score" id="max_score" class="form-control" value="<?php echo $settings['max_score'] ?? 100; ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Grading System</label>
                        <select name="grading_system" class="form-select">
                            <option value="simple" <?php echo ($settings['grading_system'] ?? '') == 'simple' ? 'selected' : ''; ?>>Simple (A-F)</option>
                            <option value="american" <?php echo ($settings['grading_system'] ?? '') == 'american' ? 'selected' : ''; ?>>American (A+-F)</option>
                            <option value="waec" <?php echo ($settings['grading_system'] ?? '') == 'waec' ? 'selected' : ''; ?>>WAEC (A1-F9)</option>
                        </select>
                    </div>
                </div>
                
                <h3 style="margin: 20px 0 15px;">Score Breakdown</h3>
                <div id="scoreTypesContainer">
                    <?php foreach ($score_types as $index => $st): ?>
                        <div class="score-item">
                            <input type="text" name="score_type_name[]" placeholder="e.g., CA 1" value="<?php echo htmlspecialchars($st['name']); ?>" required>
                            <input type="number" name="score_type_max[]" class="score-max" value="<?php echo $st['max_score']; ?>" min="0" required>
                            <button type="button" class="btn btn-danger" onclick="this.closest('.score-item').remove(); updateTotal()">Remove</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-success" onclick="addScoreType()"><i class="fas fa-plus"></i> Add Score Type</button>
                
                <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                    <strong>Total:</strong> <span id="totalScore">0</span> / <span id="maxScoreDisplay">0</span>
                </div>
                
                <div style="margin-top: 30px;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Settings</button>
                    <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function addScoreType() {
            const container = document.getElementById('scoreTypesContainer');
            const div = document.createElement('div');
            div.className = 'score-item';
            div.innerHTML = `
                <input type="text" name="score_type_name[]" placeholder="e.g., CA 1" required>
                <input type="number" name="score_type_max[]" class="score-max" value="0" min="0" required>
                <button type="button" class="btn btn-danger" onclick="this.closest('.score-item').remove(); updateTotal()">Remove</button>
            `;
            container.appendChild(div);
            div.querySelector('.score-max').addEventListener('input', updateTotal);
            updateTotal();
        }
        
        function updateTotal() {
            const maxScore = parseInt(document.getElementById('max_score').value) || 0;
            const scoreInputs = document.querySelectorAll('.score-max');
            let total = 0;
            scoreInputs.forEach(input => { total += parseInt(input.value) || 0; });
            document.getElementById('totalScore').textContent = total;
            document.getElementById('maxScoreDisplay').textContent = maxScore;
            
            const displayDiv = document.querySelector('div[style*="background: #f8f9fa"]');
            if (total === maxScore) {
                displayDiv.style.color = '#27ae60';
                displayDiv.style.borderLeft = '3px solid #27ae60';
            } else {
                displayDiv.style.color = '#e74c3c';
                displayDiv.style.borderLeft = '3px solid #e74c3c';
            }
        }
        
        document.getElementById('max_score').addEventListener('input', updateTotal);
        document.querySelectorAll('.score-max').forEach(input => input.addEventListener('input', updateTotal));
        updateTotal();
        
        const mobileBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        if(mobileBtn) mobileBtn.onclick = () => sidebar.classList.toggle('active');
    </script>
</body>
</html>
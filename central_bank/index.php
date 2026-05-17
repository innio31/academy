<?php
// /central_bank/index.php - Dashboard

require_once 'includes/config.php';
require_once 'includes/auth.php';
require_super_admin();

$page_title = 'Dashboard';
$page_subtitle = 'Central Bank Overview';

// Get statistics
$stats = [];

// Schools count
$stmt = $pdo->query("SELECT COUNT(*) as count FROM schools WHERE status = 'active'");
$stats['schools'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Subjects count (central)
$stmt = $pdo->query("SELECT COUNT(*) as count FROM subjects WHERE is_central = 1 OR school_id IS NULL");
$stats['subjects'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Topics count (central)
$stmt = $pdo->query("SELECT COUNT(*) as count FROM topics WHERE is_central = 1 OR school_id IS NULL");
$stats['topics'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Questions counts
$stmt = $pdo->query("SELECT COUNT(*) as count FROM objective_questions WHERE is_central = 1 OR school_id IS NULL");
$stats['objective'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

$stmt = $pdo->query("SELECT COUNT(*) as count FROM subjective_questions WHERE is_central = 1 OR school_id IS NULL");
$stats['subjective'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

$stmt = $pdo->query("SELECT COUNT(*) as count FROM theory_questions WHERE is_central = 1 OR school_id IS NULL");
$stats['theory'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

$stats['total_questions'] = $stats['objective'] + $stats['subjective'] + $stats['theory'];

// Recent schools
$recent_schools = $pdo->query("SELECT * FROM schools ORDER BY id DESC LIMIT 5")->fetchAll();

// Recent questions
$recent_questions = $pdo->query("
    SELECT 'objective' as type, id, question_text, created_at 
    FROM objective_questions WHERE is_central = 1 OR school_id IS NULL 
    UNION ALL 
    SELECT 'subjective' as type, id, question_text, created_at 
    FROM subjective_questions WHERE is_central = 1 OR school_id IS NULL 
    UNION ALL 
    SELECT 'theory' as type, id, question_text, created_at 
    FROM theory_questions WHERE is_central = 1 OR school_id IS NULL 
    ORDER BY created_at DESC LIMIT 10
")->fetchAll();

include 'includes/header.php';
?>

<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    .stat-card {
        background: white;
        padding: 20px;
        border-radius: 15px;
        text-align: center;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    .stat-value {
        font-size: 2rem;
        font-weight: 700;
        color: var(--primary);
    }
    .stat-label {
        color: #666;
        font-size: 0.85rem;
        margin-top: 5px;
    }
    .card {
        background: white;
        border-radius: 15px;
        padding: 20px;
        margin-bottom: 25px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    .card-header {
        padding-bottom: 15px;
        border-bottom: 1px solid #eee;
        margin-bottom: 15px;
        font-weight: 600;
    }
</style>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-value"><?php echo $stats['schools']; ?></div><div class="stat-label">Active Schools</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['subjects']; ?></div><div class="stat-label">Central Subjects</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['topics']; ?></div><div class="stat-label">Central Topics</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['total_questions']; ?></div><div class="stat-label">Total Questions</div></div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px;">
    <div class="card">
        <div class="card-header"><i class="fas fa-school"></i> Recently Added Schools</div>
        <?php if (empty($recent_schools)): ?>
            <p class="text-muted">No schools yet</p>
        <?php else: ?>
            <table class="data-table">
                <?php foreach ($recent_schools as $s): ?>
                <tr><td><strong><?php echo htmlspecialchars($s['school_name']); ?></strong><br><small>Code: <?php echo $s['school_code']; ?></small></td></tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
        <div style="margin-top: 15px;"><a href="manage_schools.php" class="btn btn-primary btn-sm">Manage Schools</a></div>
    </div>
    
    <div class="card">
        <div class="card-header"><i class="fas fa-question-circle"></i> Recent Questions Added</div>
        <?php if (empty($recent_questions)): ?>
            <p class="text-muted">No questions yet</p>
        <?php else: ?>
            <table class="data-table">
                <?php foreach ($recent_questions as $q): ?>
                <tr><td><span class="badge"><?php echo ucfirst($q['type']); ?></span> <?php echo htmlspecialchars(substr($q['question_text'], 0, 60)) . '...'; ?></td></tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
        <div style="margin-top: 15px;"><a href="manage_questions.php" class="btn btn-primary btn-sm">Manage Questions</a></div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
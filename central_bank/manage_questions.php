<?php
// /central_bank/manage_questions.php - CRUD Central Questions

require_once 'includes/config.php';
require_once 'includes/auth.php';
require_super_admin();

$page_title = 'Manage Questions';
$page_subtitle = 'Add, edit, and manage central questions';

$message = '';
$message_type = '';
$active_tab = $_GET['tab'] ?? 'objective';

// Get subjects and topics for dropdowns
$subjects = $pdo->query("SELECT id, subject_name FROM subjects WHERE is_central = 1 OR school_id IS NULL ORDER BY subject_name")->fetchAll();

// Handle Add Objective Question
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_objective'])) {
    try {
        $question_text = trim($_POST['question_text']);
        $option_a = trim($_POST['option_a']);
        $option_b = trim($_POST['option_b']);
        $option_c = trim($_POST['option_c']);
        $option_d = trim($_POST['option_d']);
        $correct_answer = strtoupper(trim($_POST['correct_answer']));
        $subject_id = (int)$_POST['subject_id'];
        $topic_id = (int)$_POST['topic_id'];
        $difficulty = $_POST['difficulty'];
        $marks = (int)$_POST['marks'];
        
        $stmt = $pdo->prepare("
            INSERT INTO objective_questions 
            (question_text, option_a, option_b, option_c, option_d, correct_answer, 
             subject_id, topic_id, difficulty_level, marks, is_central, school_id, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NULL, NOW())
        ");
        $stmt->execute([$question_text, $option_a, $option_b, $option_c, $option_d, $correct_answer, $subject_id, $topic_id, $difficulty, $marks]);
        $message = "Objective question added to central bank!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// Handle Delete Question
if (isset($_GET['delete']) && isset($_GET['type']) && isset($_GET['id'])) {
    $type = $_GET['type'];
    $id = (int)$_GET['id'];
    $table = $type . '_questions';
    try {
        $stmt = $pdo->prepare("DELETE FROM $table WHERE id = ? AND (is_central = 1 OR school_id IS NULL)");
        $stmt->execute([$id]);
        $message = "Question deleted successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// Get questions counts
$obj_count = $pdo->query("SELECT COUNT(*) FROM objective_questions WHERE is_central = 1 OR school_id IS NULL")->fetchColumn();
$sub_count = $pdo->query("SELECT COUNT(*) FROM subjective_questions WHERE is_central = 1 OR school_id IS NULL")->fetchColumn();
$the_count = $pdo->query("SELECT COUNT(*) FROM theory_questions WHERE is_central = 1 OR school_id IS NULL")->fetchColumn();

// Get questions for display
$objective_qs = $pdo->query("
    SELECT oq.*, s.subject_name, t.topic_name 
    FROM objective_questions oq
    LEFT JOIN subjects s ON oq.subject_id = s.id
    LEFT JOIN topics t ON oq.topic_id = t.id
    WHERE oq.is_central = 1 OR oq.school_id IS NULL
    ORDER BY oq.id DESC LIMIT 50
")->fetchAll();

$subjective_qs = $pdo->query("
    SELECT sq.*, s.subject_name, t.topic_name 
    FROM subjective_questions sq
    LEFT JOIN subjects s ON sq.subject_id = s.id
    LEFT JOIN topics t ON sq.topic_id = t.id
    WHERE sq.is_central = 1 OR sq.school_id IS NULL
    ORDER BY sq.id DESC LIMIT 50
")->fetchAll();

$theory_qs = $pdo->query("
    SELECT tq.*, s.subject_name, t.topic_name 
    FROM theory_questions tq
    LEFT JOIN subjects s ON tq.subject_id = s.id
    LEFT JOIN topics t ON tq.topic_id = t.id
    WHERE tq.is_central = 1 OR tq.school_id IS NULL
    ORDER BY tq.id DESC LIMIT 50
")->fetchAll();

include 'includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="tabs-navigation" style="background: white; border-radius: 12px; margin-bottom: 20px; overflow: hidden;">
    <div style="display: flex; border-bottom: 2px solid #eee;">
        <a href="?tab=objective" class="tab-link" style="padding: 12px 25px; text-decoration: none; color: <?php echo $active_tab === 'objective' ? 'var(--primary)' : '#666'; ?>; border-bottom: 2px solid <?php echo $active_tab === 'objective' ? 'var(--primary)' : 'transparent'; ?>; margin-bottom: -2px;"><i class="fas fa-check-circle"></i> Objective (<?php echo $obj_count; ?>)</a>
        <a href="?tab=subjective" class="tab-link" style="padding: 12px 25px; text-decoration: none; color: <?php echo $active_tab === 'subjective' ? 'var(--primary)' : '#666'; ?>; border-bottom: 2px solid <?php echo $active_tab === 'subjective' ? 'var(--primary)' : 'transparent'; ?>; margin-bottom: -2px;"><i class="fas fa-edit"></i> Subjective (<?php echo $sub_count; ?>)</a>
        <a href="?tab=theory" class="tab-link" style="padding: 12px 25px; text-decoration: none; color: <?php echo $active_tab === 'theory' ? 'var(--primary)' : '#666'; ?>; border-bottom: 2px solid <?php echo $active_tab === 'theory' ? 'var(--primary)' : 'transparent'; ?>; margin-bottom: -2px;"><i class="fas fa-file-alt"></i> Theory (<?php echo $the_count; ?>)</a>
    </div>
</div>

<?php if ($active_tab === 'objective'): ?>
<div class="card">
    <div class="card-header"><i class="fas fa-plus-circle"></i> Add Objective Question to Central Bank</div>
    <form method="POST">
        <div class="form-group"><label>Question Text *</label><textarea name="question_text" class="form-control" rows="4" required></textarea></div>
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
            <div class="form-group"><label>Option A *</label><input type="text" name="option_a" class="form-control" required></div>
            <div class="form-group"><label>Option B *</label><input type="text" name="option_b" class="form-control" required></div>
            <div class="form-group"><label>Option C</label><input type="text" name="option_c" class="form-control"></div>
            <div class="form-group"><label>Option D</label><input type="text" name="option_d" class="form-control"></div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 15px;">
            <div class="form-group"><label>Correct Answer *</label><select name="correct_answer" class="form-control" required><option value="A">A</option><option value="B">B</option><option value="C">C</option><option value="D">D</option></select></div>
            <div class="form-group"><label>Subject *</label><select name="subject_id" class="form-control" required onchange="loadTopics(this.value)"><option value="">Select</option><?php foreach ($subjects as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['subject_name']); ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Topic</label><select name="topic_id" id="topic_select" class="form-control"><option value="0">None</option></select></div>
            <div class="form-group"><label>Difficulty</label><select name="difficulty" class="form-control"><option value="easy">Easy</option><option value="medium" selected>Medium</option><option value="hard">Hard</option></select></div>
            <div class="form-group"><label>Marks</label><input type="number" name="marks" class="form-control" value="1" min="1"></div>
        </div>
        <button type="submit" name="add_objective" class="btn btn-success"><i class="fas fa-save"></i> Add to Central Bank</button>
    </form>
</div>

<div class="card">
    <div class="card-header"><i class="fas fa-list"></i> Central Objective Questions</div>
    <table class="data-table">
        <thead><tr><th>ID</th><th>Question</th><th>Subject/Topic</th><th>Correct</th><th>Actions</th></tr></thead>
        <tbody>
            <?php foreach ($objective_qs as $q): ?>
            <tr>
                <td><?php echo $q['id']; ?></td>
                <td><?php echo htmlspecialchars(substr($q['question_text'], 0, 80)) . (strlen($q['question_text']) > 80 ? '...' : ''); ?></td>
                <td><span class="badge badge-active"><?php echo htmlspecialchars($q['subject_name'] ?? 'N/A'); ?></span><br><small><?php echo htmlspecialchars($q['topic_name'] ?? 'No topic'); ?></small></td>
                <td><strong><?php echo $q['correct_answer']; ?></strong></td>
                <td><a href="?delete=1&type=objective&id=<?php echo $q['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this question from central bank?')"><i class="fas fa-trash"></i> Delete</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($active_tab === 'subjective'): ?>
<div class="card">
    <div class="card-header"><i class="fas fa-plus-circle"></i> Add Subjective Question to Central Bank</div>
    <form method="POST">
        <div class="form-group"><label>Question Text *</label><textarea name="question_text" class="form-control" rows="4" required></textarea></div>
        <div class="form-group"><label>Model Answer</label><textarea name="correct_answer" class="form-control" rows="3"></textarea></div>
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px;">
            <div class="form-group"><label>Subject *</label><select name="subject_id" class="form-control" required onchange="loadTopics(this.value, 'subj_topic')"><option value="">Select</option><?php foreach ($subjects as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['subject_name']); ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Topic</label><select name="topic_id" id="subj_topic" class="form-control"><option value="0">None</option></select></div>
            <div class="form-group"><label>Difficulty</label><select name="difficulty" class="form-control"><option value="easy">Easy</option><option value="medium" selected>Medium</option><option value="hard">Hard</option></select></div>
            <div class="form-group"><label>Marks</label><input type="number" name="marks" class="form-control" value="1" min="1"></div>
        </div>
        <button type="submit" name="add_subjective" class="btn btn-success"><i class="fas fa-save"></i> Add to Central Bank</button>
    </form>
</div>

<div class="card">
    <div class="card-header"><i class="fas fa-list"></i> Central Subjective Questions</div>
    <table class="data-table">
        <thead><tr><th>ID</th><th>Question</th><th>Subject/Topic</th><th>Actions</th></tr></thead>
        <tbody>
            <?php foreach ($subjective_qs as $q): ?>
            <tr>
                <td><?php echo $q['id']; ?></td>
                <td><?php echo htmlspecialchars(substr($q['question_text'], 0, 80)) . (strlen($q['question_text']) > 80 ? '...' : ''); ?></td>
                <td><span class="badge badge-active"><?php echo htmlspecialchars($q['subject_name'] ?? 'N/A'); ?></span><br><small><?php echo htmlspecialchars($q['topic_name'] ?? 'No topic'); ?></small></td>
                <td><a href="?delete=1&type=subjective&id=<?php echo $q['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i> Delete</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($active_tab === 'theory'): ?>
<div class="card">
    <div class="card-header"><i class="fas fa-plus-circle"></i> Add Theory Question to Central Bank</div>
    <form method="POST" enctype="multipart/form-data">
        <div class="form-group"><label>Question Text *</label><textarea name="question_text" class="form-control" rows="4" required></textarea></div>
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
            <div class="form-group"><label>Subject *</label><select name="subject_id" class="form-control" required onchange="loadTopics(this.value, 'theory_topic')"><option value="">Select</option><?php foreach ($subjects as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['subject_name']); ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Topic</label><select name="topic_id" id="theory_topic" class="form-control"><option value="0">None</option></select></div>
            <div class="form-group"><label>Marks</label><input type="number" name="marks" class="form-control" value="5" min="1"></div>
        </div>
        <div class="form-group"><label>Attach File (Optional)</label><input type="file" name="question_file" class="form-control" accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png"></div>
        <button type="submit" name="add_theory" class="btn btn-success"><i class="fas fa-save"></i> Add to Central Bank</button>
    </form>
</div>

<div class="card">
    <div class="card-header"><i class="fas fa-list"></i> Central Theory Questions</div>
    <table class="data-table">
        <thead><tr><th>ID</th><th>Question</th><th>Subject/Topic</th><th>Marks</th><th>Actions</th></tr></thead>
        <tbody>
            <?php foreach ($theory_qs as $q): ?>
            <tr>
                <td><?php echo $q['id']; ?></td>
                <td><?php echo htmlspecialchars(substr($q['question_text'] ?? '', 0, 80)) . (strlen($q['question_text'] ?? '') > 80 ? '...' : ''); ?></td>
                <td><span class="badge badge-active"><?php echo htmlspecialchars($q['subject_name'] ?? 'N/A'); ?></span><br><small><?php echo htmlspecialchars($q['topic_name'] ?? 'No topic'); ?></small></td>
                <td><?php echo $q['marks']; ?></td>
                <td><a href="?delete=1&type=theory&id=<?php echo $q['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i> Delete</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
async function loadTopics(subjectId, targetId = 'topic_select') {
    if (!subjectId) return;
    const select = document.getElementById(targetId);
    select.innerHTML = '<option value="0">Loading...</option>';
    try {
        const response = await fetch(`api/get_topics.php?subject_id=${subjectId}`);
        const data = await response.json();
        if (data.success && data.topics) {
            select.innerHTML = '<option value="0">-- No Topic --</option>';
            data.topics.forEach(topic => {
                select.innerHTML += `<option value="${topic.id}">${escapeHtml(topic.topic_name)}</option>`;
            });
        } else {
            select.innerHTML = '<option value="0">No topics found</option>';
        }
    } catch (error) {
        select.innerHTML = '<option value="0">Error loading topics</option>';
    }
}
function escapeHtml(text) { const div = document.createElement('div'); div.textContent = text; return div.innerHTML; }
</script>

<?php include 'includes/footer.php'; ?>
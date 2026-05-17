<?php
// /central_bank/manage_topics.php - CRUD Central Topics

require_once 'includes/config.php';
require_once 'includes/auth.php';
require_super_admin();

$page_title = 'Manage Topics';
$page_subtitle = 'Add, edit, and manage central topics';

$message = '';
$message_type = '';

$subjects = $pdo->query("SELECT id, subject_name FROM subjects WHERE is_central = 1 OR school_id IS NULL ORDER BY subject_name")->fetchAll();

// Handle Add Topic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_topic'])) {
    $topic_name = trim($_POST['topic_name']);
    $subject_id = (int)$_POST['subject_id'];
    $description = trim($_POST['description']);
    
    try {
        $stmt = $pdo->prepare("INSERT INTO topics (topic_name, subject_id, description, is_central, school_id, created_at) VALUES (?, ?, ?, 1, NULL, NOW())");
        $stmt->execute([$topic_name, $subject_id, $description]);
        $message = "Topic added successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// Handle Edit Topic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_topic'])) {
    $topic_id = (int)$_POST['topic_id'];
    $topic_name = trim($_POST['topic_name']);
    $subject_id = (int)$_POST['subject_id'];
    $description = trim($_POST['description']);
    
    try {
        $stmt = $pdo->prepare("UPDATE topics SET topic_name = ?, subject_id = ?, description = ? WHERE id = ? AND (is_central = 1 OR school_id IS NULL)");
        $stmt->execute([$topic_name, $subject_id, $description, $topic_id]);
        $message = "Topic updated successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// Handle Delete Topic
if (isset($_GET['delete'])) {
    $topic_id = (int)$_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM topics WHERE id = ? AND (is_central = 1 OR school_id IS NULL)");
        $stmt->execute([$topic_id]);
        $message = "Topic deleted successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

$topics = $pdo->query("
    SELECT t.*, s.subject_name 
    FROM topics t 
    JOIN subjects s ON t.subject_id = s.id 
    WHERE t.is_central = 1 OR t.school_id IS NULL 
    ORDER BY s.subject_name, t.topic_name
")->fetchAll();

include 'includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><i class="fas fa-plus-circle"></i> Add New Topic</div>
    <form method="POST">
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
            <div class="form-group"><label>Topic Name *</label><input type="text" name="topic_name" class="form-control" required></div>
            <div class="form-group"><label>Subject *</label><select name="subject_id" class="form-control" required><?php foreach ($subjects as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['subject_name']); ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Description</label><input type="text" name="description" class="form-control"></div>
        </div>
        <button type="submit" name="add_topic" class="btn btn-success"><i class="fas fa-save"></i> Add Topic</button>
    </form>
</div>

<div class="card">
    <div class="card-header"><i class="fas fa-list"></i> Central Topics (<?php echo count($topics); ?>)</div>
    <table class="data-table">
        <thead><tr><th>ID</th><th>Topic Name</th><th>Subject</th><th>Description</th><th>Actions</th></tr></thead>
        <tbody>
            <?php foreach ($topics as $t): ?>
            <tr>
                <td><?php echo $t['id']; ?></td>
                <td><strong><?php echo htmlspecialchars($t['topic_name']); ?></strong></td>
                <td><span class="badge badge-active"><?php echo htmlspecialchars($t['subject_name']); ?></span></td>
                <td><?php echo htmlspecialchars($t['description'] ?? '-'); ?></td>
                <td>
                    <button class="btn btn-primary btn-sm" onclick="editTopic(<?php echo htmlspecialchars(json_encode($t)); ?>, <?php echo htmlspecialchars(json_encode($subjects)); ?>)"><i class="fas fa-edit"></i> Edit</button>
                    <a href="?delete=<?php echo $t['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this topic? All associated questions will also be deleted.')"><i class="fas fa-trash"></i> Delete</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="modal" id="editModal">
    <div class="modal-content">
        <div class="modal-header"><h3>Edit Topic</h3><button class="close-modal" onclick="closeModal()">&times;</button></div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="topic_id" id="edit_id">
                <div class="form-group"><label>Topic Name</label><input type="text" name="topic_name" id="edit_name" class="form-control" required></div>
                <div class="form-group"><label>Subject</label><select name="subject_id" id="edit_subject" class="form-control" required></select></div>
                <div class="form-group"><label>Description</label><input type="text" name="description" id="edit_desc" class="form-control"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button><button type="submit" name="edit_topic" class="btn btn-primary">Save</button></div>
        </form>
    </div>
</div>

<script>
let subjectsData = <?php echo json_encode($subjects); ?>;
function editTopic(t, subjects) {
    document.getElementById('edit_id').value = t.id;
    document.getElementById('edit_name').value = t.topic_name;
    document.getElementById('edit_desc').value = t.description || '';
    let subjectSelect = document.getElementById('edit_subject');
    subjectSelect.innerHTML = '';
    subjectsData.forEach(s => {
        let option = document.createElement('option');
        option.value = s.id;
        option.textContent = s.subject_name;
        if (s.id == t.subject_id) option.selected = true;
        subjectSelect.appendChild(option);
    });
    document.getElementById('editModal').classList.add('active');
}
function closeModal() { document.getElementById('editModal').classList.remove('active'); }
</script>

<?php include 'includes/footer.php'; ?>
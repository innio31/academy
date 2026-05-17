<?php
// /central_bank/manage_subjects.php - CRUD Central Subjects

require_once 'includes/config.php';
require_once 'includes/auth.php';
require_super_admin();

$page_title = 'Manage Subjects';
$page_subtitle = 'Add, edit, and manage central subjects';

$message = '';
$message_type = '';

// Handle Add Subject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_subject'])) {
    $subject_name = trim($_POST['subject_name']);
    $description = trim($_POST['description']);
    
    try {
        $stmt = $pdo->prepare("INSERT INTO subjects (subject_name, description, is_central, school_id, created_at) VALUES (?, ?, 1, NULL, NOW())");
        $stmt->execute([$subject_name, $description]);
        $message = "Subject added successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// Handle Edit Subject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_subject'])) {
    $subject_id = (int)$_POST['subject_id'];
    $subject_name = trim($_POST['subject_name']);
    $description = trim($_POST['description']);
    
    try {
        $stmt = $pdo->prepare("UPDATE subjects SET subject_name = ?, description = ? WHERE id = ? AND (is_central = 1 OR school_id IS NULL)");
        $stmt->execute([$subject_name, $description, $subject_id]);
        $message = "Subject updated successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// Handle Delete Subject
if (isset($_GET['delete'])) {
    $subject_id = (int)$_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM subjects WHERE id = ? AND (is_central = 1 OR school_id IS NULL)");
        $stmt->execute([$subject_id]);
        $message = "Subject deleted successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

$subjects = $pdo->query("SELECT * FROM subjects WHERE is_central = 1 OR school_id IS NULL ORDER BY subject_name")->fetchAll();

include 'includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><i class="fas fa-plus-circle"></i> Add New Subject</div>
    <form method="POST">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
            <div class="form-group"><label>Subject Name *</label><input type="text" name="subject_name" class="form-control" required></div>
            <div class="form-group"><label>Description</label><input type="text" name="description" class="form-control"></div>
        </div>
        <button type="submit" name="add_subject" class="btn btn-success"><i class="fas fa-save"></i> Add Subject</button>
    </form>
</div>

<div class="card">
    <div class="card-header"><i class="fas fa-book"></i> Central Subjects (<?php echo count($subjects); ?>)</div>
    <table class="data-table">
        <thead><tr><th>ID</th><th>Subject Name</th><th>Description</th><th>Actions</th></tr></thead>
        <tbody>
            <?php foreach ($subjects as $s): ?>
            <tr>
                <td><?php echo $s['id']; ?></td>
                <td><strong><?php echo htmlspecialchars($s['subject_name']); ?></strong></td>
                <td><?php echo htmlspecialchars($s['description'] ?? '-'); ?></td>
                <td>
                    <button class="btn btn-primary btn-sm" onclick="editSubject(<?php echo htmlspecialchars(json_encode($s)); ?>)"><i class="fas fa-edit"></i> Edit</button>
                    <a href="?delete=<?php echo $s['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this subject? All associated topics and questions will also be deleted.')"><i class="fas fa-trash"></i> Delete</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="modal" id="editModal">
    <div class="modal-content">
        <div class="modal-header"><h3>Edit Subject</h3><button class="close-modal" onclick="closeModal()">&times;</button></div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="subject_id" id="edit_id">
                <div class="form-group"><label>Subject Name</label><input type="text" name="subject_name" id="edit_name" class="form-control" required></div>
                <div class="form-group"><label>Description</label><input type="text" name="description" id="edit_desc" class="form-control"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button><button type="submit" name="edit_subject" class="btn btn-primary">Save</button></div>
        </form>
    </div>
</div>

<script>
function editSubject(s) {
    document.getElementById('edit_id').value = s.id;
    document.getElementById('edit_name').value = s.subject_name;
    document.getElementById('edit_desc').value = s.description || '';
    document.getElementById('editModal').classList.add('active');
}
function closeModal() { document.getElementById('editModal').classList.remove('active'); }
</script>

<?php include 'includes/footer.php'; ?>
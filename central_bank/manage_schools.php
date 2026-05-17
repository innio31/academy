<?php
// /central_bank/manage_schools.php - CRUD Schools

require_once 'includes/config.php';
require_once 'includes/auth.php';
require_super_admin();

$page_title = 'Manage Schools';
$page_subtitle = 'Add, edit, and manage schools';

$message = '';
$message_type = '';

// Handle Add School
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_school'])) {
    $school_code = strtoupper(trim($_POST['school_code']));
    $school_name = trim($_POST['school_name']);
    $url_path = trim($_POST['url_path']);
    $contact_email = trim($_POST['contact_email']);
    $contact_phone = trim($_POST['contact_phone']);
    $subscription_expiry = $_POST['subscription_expiry'] ?: null;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO schools (school_code, school_name, url_path, contact_email, contact_phone, subscription_status, subscription_expiry, created_at)
            VALUES (?, ?, ?, ?, ?, 'active', ?, NOW())
        ");
        $stmt->execute([$school_code, $school_name, $url_path, $contact_email, $contact_phone, $subscription_expiry]);
        $message = "School added successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// Handle Edit School
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_school'])) {
    $school_id = (int)$_POST['school_id'];
    $school_code = strtoupper(trim($_POST['school_code']));
    $school_name = trim($_POST['school_name']);
    $url_path = trim($_POST['url_path']);
    $contact_email = trim($_POST['contact_email']);
    $contact_phone = trim($_POST['contact_phone']);
    $subscription_status = $_POST['subscription_status'];
    $subscription_expiry = $_POST['subscription_expiry'] ?: null;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE schools SET 
                school_code = ?, school_name = ?, url_path = ?, 
                contact_email = ?, contact_phone = ?, subscription_status = ?, subscription_expiry = ?
            WHERE id = ?
        ");
        $stmt->execute([$school_code, $school_name, $url_path, $contact_email, $contact_phone, $subscription_status, $subscription_expiry, $school_id]);
        $message = "School updated successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// Handle Delete School
if (isset($_GET['delete'])) {
    $school_id = (int)$_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM schools WHERE id = ?");
        $stmt->execute([$school_id]);
        $message = "School deleted successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// Get all schools
$schools = $pdo->query("SELECT * FROM schools ORDER BY id DESC")->fetchAll();

include 'includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?>">
        <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><i class="fas fa-plus-circle"></i> Add New School</div>
    <form method="POST">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
            <div class="form-group"><label>School Code *</label><input type="text" name="school_code" class="form-control" required placeholder="e.g., GOS, TCBA"></div>
            <div class="form-group"><label>School Name *</label><input type="text" name="school_name" class="form-control" required placeholder="Full school name"></div>
            <div class="form-group"><label>URL Path</label><input type="text" name="url_path" class="form-control" placeholder="e.g., gos, tcba"></div>
            <div class="form-group"><label>Contact Email</label><input type="email" name="contact_email" class="form-control" placeholder="admin@school.com"></div>
            <div class="form-group"><label>Contact Phone</label><input type="text" name="contact_phone" class="form-control" placeholder="Phone number"></div>
            <div class="form-group"><label>Subscription Expiry</label><input type="date" name="subscription_expiry" class="form-control"></div>
        </div>
        <div style="margin-top: 15px;"><button type="submit" name="add_school" class="btn btn-success"><i class="fas fa-save"></i> Add School</button></div>
    </form>
</div>

<div class="card">
    <div class="card-header"><i class="fas fa-school"></i> All Schools (<?php echo count($schools); ?>)</div>
    <table class="data-table">
        <thead>
            <tr><th>ID</th><th>School Code</th><th>School Name</th><th>URL Path</th><th>Status</th><th>Expiry</th><th>Actions</th>
        </thead>
        <tbody>
            <?php foreach ($schools as $s): ?>
            <tr>
                <td><?php echo $s['id']; ?></td>
                <td><strong><?php echo htmlspecialchars($s['school_code']); ?></strong></td>
                <td><?php echo htmlspecialchars($s['school_name']); ?></td>
                <td><code><?php echo htmlspecialchars($s['url_path']); ?></code></td>
                <td><span class="badge <?php echo $s['subscription_status'] === 'active' ? 'badge-active' : 'badge-inactive'; ?>"><?php echo $s['subscription_status']; ?></span></td>
                <td><?php echo $s['subscription_expiry'] ?: 'N/A'; ?></td>
                <td>
                    <button class="btn btn-primary btn-sm" onclick="editSchool(<?php echo htmlspecialchars(json_encode($s)); ?>)"><i class="fas fa-edit"></i> Edit</button>
                    <a href="?delete=<?php echo $s['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this school? All associated data will remain but school will be removed.')"><i class="fas fa-trash"></i> Delete</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Edit Modal -->
<div class="modal" id="editModal">
    <div class="modal-content">
        <div class="modal-header"><h3>Edit School</h3><button class="close-modal" onclick="closeModal()">&times;</button></div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="school_id" id="edit_id">
                <div class="form-group"><label>School Code</label><input type="text" name="school_code" id="edit_code" class="form-control" required></div>
                <div class="form-group"><label>School Name</label><input type="text" name="school_name" id="edit_name" class="form-control" required></div>
                <div class="form-group"><label>URL Path</label><input type="text" name="url_path" id="edit_url" class="form-control"></div>
                <div class="form-group"><label>Contact Email</label><input type="email" name="contact_email" id="edit_email" class="form-control"></div>
                <div class="form-group"><label>Contact Phone</label><input type="text" name="contact_phone" id="edit_phone" class="form-control"></div>
                <div class="form-group"><label>Status</label><select name="subscription_status" id="edit_status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option><option value="expired">Expired</option></select></div>
                <div class="form-group"><label>Subscription Expiry</label><input type="date" name="subscription_expiry" id="edit_expiry" class="form-control"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button><button type="submit" name="edit_school" class="btn btn-primary">Save Changes</button></div>
        </form>
    </div>
</div>

<script>
function editSchool(school) {
    document.getElementById('edit_id').value = school.id;
    document.getElementById('edit_code').value = school.school_code;
    document.getElementById('edit_name').value = school.school_name;
    document.getElementById('edit_url').value = school.url_path || '';
    document.getElementById('edit_email').value = school.contact_email || '';
    document.getElementById('edit_phone').value = school.contact_phone || '';
    document.getElementById('edit_status').value = school.subscription_status || 'active';
    document.getElementById('edit_expiry').value = school.subscription_expiry || '';
    document.getElementById('editModal').classList.add('active');
}
function closeModal() { document.getElementById('editModal').classList.remove('active'); }
</script>

<?php include 'includes/footer.php'; ?>
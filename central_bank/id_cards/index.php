<?php
// /central_bank/id_cards/index.php - ID Card Dashboard

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once 'includes/id_card_functions.php';

require_super_admin();

$page_title = 'ID Card Generation';
$page_subtitle = 'Generate student ID cards for all schools';

$message = '';
$message_type = '';

// Get selected filters
$selected_school = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
$selected_class = isset($_GET['class']) ? $_GET['class'] : '';
$selected_student = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

// Get all schools
$schools = getAllSchoolsForIDCards();

// Get classes for selected school
$classes = [];
$students = [];
$student_details = null;

if ($selected_school > 0) {
    $classes = getSchoolClasses($selected_school);

    if ($selected_class) {
        $students = getStudentsByClass($selected_school, $selected_class);
    }

    if ($selected_student > 0) {
        $student_details = getStudentIDCardData($selected_student, $selected_school);
    }
}

// Handle bulk generation redirect
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_generate'])) {
    $school_id = (int)$_POST['school_id'];
    $class = $_POST['class'];
    header("Location: bulk_generate.php?school_id=$school_id&class=" . urlencode($class));
    exit();
}

// Handle single generation redirect
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_single'])) {
    $school_id = (int)$_POST['school_id'];
    $student_id = (int)$_POST['student_id'];
    header("Location: generate.php?school_id=$school_id&student_id=$student_id");
    exit();
}

include '../includes/header.php';
?>

<style>
    .dashboard-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .stat-card {
        background: white;
        padding: 20px;
        border-radius: 12px;
        text-align: center;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    }

    .stat-value {
        font-size: 2rem;
        font-weight: 700;
        color: var(--primary);
    }

    .filter-card,
    .results-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 25px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    }

    .student-list {
        max-height: 400px;
        overflow-y: auto;
    }

    .student-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px;
        border-bottom: 1px solid #eee;
    }

    .student-item:hover {
        background: #f8f9fa;
    }

    .student-avatar {
        width: 40px;
        height: 40px;
        background: var(--light);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 12px;
    }

    .preview-card {
        background: linear-gradient(135deg, #667eea, #764ba2);
        border-radius: 12px;
        padding: 20px;
        color: white;
        text-align: center;
    }
</style>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="dashboard-stats">
    <div class="stat-card">
        <div class="stat-value"><?php echo count($schools); ?></div>
        <div class="stat-label">Active Schools</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php
                                $total_students = $pdo->query("SELECT COUNT(*) FROM students WHERE status = 'active'")->fetchColumn();
                                echo number_format($total_students);
                                ?></div>
        <div class="stat-label">Total Students</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php
                                $cards_generated = $pdo->query("SELECT COUNT(*) FROM id_card_generation_log")->fetchColumn();
                                echo number_format($cards_generated);
                                ?></div>
        <div class="stat-label">Cards Generated</div>
    </div>
</div>

<!-- Filter Section -->
<div class="filter-card">
    <h3 style="margin-bottom: 20px;"><i class="fas fa-filter"></i> Select Student / Class</h3>

    <form method="GET" action="">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
            <div class="form-group">
                <label>Select School</label>
                <select name="school_id" class="form-control" onchange="this.form.submit()">
                    <option value="">-- Select School --</option>
                    <?php foreach ($schools as $school): ?>
                        <option value="<?php echo $school['id']; ?>" <?php echo $selected_school == $school['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($school['school_name']); ?> (<?php echo $school['school_code']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($selected_school > 0): ?>
                <div class="form-group">
                    <label>Select Class</label>
                    <select name="class" class="form-control" onchange="this.form.submit()">
                        <option value="">-- Select Class --</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo htmlspecialchars($class); ?>" <?php echo $selected_class == $class ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($selected_class && !empty($students)): ?>
    <!-- Bulk Generation -->
    <div class="filter-card">
        <h3><i class="fas fa-layer-group"></i> Bulk Generation for <?php echo htmlspecialchars($selected_class); ?></h3>
        <p><?php echo count($students); ?> students found in this class.</p>
        <form method="POST" style="margin-top: 15px;">
            <input type="hidden" name="school_id" value="<?php echo $selected_school; ?>">
            <input type="hidden" name="class" value="<?php echo htmlspecialchars($selected_class); ?>">
            <button type="submit" name="bulk_generate" class="btn btn-primary">
                <i class="fas fa-print"></i> Generate ID Cards for All (<?php echo count($students); ?>)
            </button>
        </form>
    </div>

    <!-- Student List -->
    <div class="results-card">
        <h3><i class="fas fa-users"></i> Students in <?php echo htmlspecialchars($selected_class); ?></h3>
        <div class="student-list">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Photo</th>
                        <th>Admission No.</th>
                        <th>Student Name</th>
                        <th>Actions</th>
                </thead>
                <tbody>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td>
                                <div class="student-avatar" style="width: 35px; height: 35px;">
                                    <?php if (!empty($student['profile_picture']) && file_exists('../..' . $student['profile_picture'])): ?>
                                        <img src="../..<?php echo $student['profile_picture']; ?>" style="width: 35px; height: 35px; border-radius: 50%; object-fit: cover;">
                                    <?php else: ?>
                                        <i class="fas fa-user-graduate"></i>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($student['admission_number']); ?></td>
                            <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="school_id" value="<?php echo $selected_school; ?>">
                                    <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                    <button type="submit" name="generate_single" class="btn btn-primary btn-sm">
                                        <i class="fas fa-id-card"></i> Generate Card
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if ($selected_school > 0 && !$selected_class): ?>
    <div class="results-card">
        <h3><i class="fas fa-info-circle"></i> Select a class to view students</h3>
        <p>Please select a class from the dropdown above to see students and generate ID cards.</p>
    </div>
<?php endif; ?>

<!-- Recent Generations -->
<div class="results-card">
    <h3><i class="fas fa-history"></i> Recent ID Card Generations</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>School</th>
                <th>Student</th>
                <th>Generated By</th>
                <th>Date</th>
                <th>Actions</th>
        </thead>
        <tbody>
            <?php
            $recent_logs = $pdo->query("
                SELECT l.*, s.school_name, st.full_name, st.admission_number
                FROM id_card_generation_log l
                JOIN schools s ON l.school_id = s.id
                JOIN students st ON l.student_id = st.id
                ORDER BY l.generated_at DESC LIMIT 20
            ")->fetchAll();
            ?>
            <?php foreach ($recent_logs as $log): ?>
                <tr>
                    <td><?php echo htmlspecialchars($log['school_name']); ?></td>
                    <td><?php echo htmlspecialchars($log['full_name']); ?> (<?php echo $log['admission_number']; ?>)</td>
                    <td><?php echo htmlspecialchars($log['generated_by']); ?></td>
                    <td><?php echo date('M d, Y H:i', strtotime($log['generated_at'])); ?></td>
                    <td>
                        <a href="generate.php?school_id=<?php echo $log['school_id']; ?>&student_id=<?php echo $log['student_id']; ?>" class="btn btn-primary btn-sm">
                            <i class="fas fa-redo"></i> Regenerate
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../includes/footer.php'; ?>
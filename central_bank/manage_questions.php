<?php
// /central_bank/manage_questions.php - Manage all questions with advanced filtering
// Separate from Add Question page

require_once 'includes/config.php';
require_once 'includes/auth.php';
require_super_admin();

$page_title = 'Manage Questions';
$page_subtitle = 'View, filter, edit, and delete questions from all banks';

$message = '';
$message_type = '';
$active_tab = $_GET['tab'] ?? 'objective';

// Pagination settings
$items_per_page = 20;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $items_per_page;

// Get filter values
$filter_subject = isset($_GET['filter_subject']) ? (int)$_GET['filter_subject'] : 0;
$filter_topic = isset($_GET['filter_topic']) ? (int)$_GET['filter_topic'] : 0;
$filter_year = isset($_GET['filter_year']) ? (int)$_GET['filter_year'] : 0;
$filter_difficulty = isset($_GET['filter_difficulty']) ? $_GET['filter_difficulty'] : '';
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';

// Get counts for tabs
$obj_count = $pdo->query("SELECT COUNT(*) FROM objective_questions WHERE is_central = 1 OR school_id IS NULL")->fetchColumn();
$sub_count = $pdo->query("SELECT COUNT(*) FROM subjective_questions WHERE is_central = 1 OR school_id IS NULL")->fetchColumn();
$the_count = $pdo->query("SELECT COUNT(*) FROM theory_questions WHERE is_central = 1 OR school_id IS NULL")->fetchColumn();
$waec_count = $pdo->query("SELECT COUNT(*) FROM waec_questions WHERE is_active = 1")->fetchColumn();
$jamb_count = $pdo->query("SELECT COUNT(*) FROM jamb_questions WHERE is_active = 1")->fetchColumn();

// ============================================
// HANDLE DELETE
// ============================================
if (isset($_GET['delete']) && isset($_GET['type']) && isset($_GET['id'])) {
    $type = $_GET['type'];
    $id = (int)$_GET['id'];
    $table = '';
    
    switch ($type) {
        case 'objective': $table = 'objective_questions'; break;
        case 'subjective': $table = 'subjective_questions'; break;
        case 'theory': $table = 'theory_questions'; break;
        case 'waec': $table = 'waec_questions'; break;
        case 'jamb': $table = 'jamb_questions'; break;
        default: $message = "Invalid type"; $message_type = "error";
    }
    
    if ($table) {
        try {
            $pdo->prepare("DELETE FROM $table WHERE id = ?")->execute([$id]);
            $message = "Question deleted successfully!";
            $message_type = "success";
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// ============================================
// GET DATA FOR DROPDOWNS
// ============================================
$subjects = $pdo->query("SELECT id, subject_name FROM subjects WHERE is_central = 1 OR school_id IS NULL ORDER BY subject_name")->fetchAll();
$waec_subjects = $pdo->query("SELECT id, subject_name FROM waec_subjects WHERE is_active = 1 ORDER BY subject_name")->fetchAll();
$jamb_subjects = $pdo->query("SELECT id, subject_name FROM jamb_subjects WHERE is_active = 1 ORDER BY subject_name")->fetchAll();

// Get available years for WAEC/JAMB filtering
$years = [];
for ($y = date('Y'); $y >= 1990; $y--) { $years[] = $y; }

// ============================================
// BUILD QUERIES WITH FILTERS
// ============================================

// Objective Questions Query
$obj_sql = "SELECT oq.*, s.subject_name, t.topic_name 
            FROM objective_questions oq
            LEFT JOIN subjects s ON oq.subject_id = s.id
            LEFT JOIN topics t ON oq.topic_id = t.id
            WHERE (oq.is_central = 1 OR oq.school_id IS NULL)";
$obj_params = [];

if ($filter_subject && $active_tab === 'objective') {
    $obj_sql .= " AND oq.subject_id = ?";
    $obj_params[] = $filter_subject;
}
if ($filter_topic && $active_tab === 'objective') {
    $obj_sql .= " AND oq.topic_id = ?";
    $obj_params[] = $filter_topic;
}
if ($filter_difficulty && $active_tab === 'objective') {
    $obj_sql .= " AND oq.difficulty_level = ?";
    $obj_params[] = $filter_difficulty;
}
if ($search_term) {
    $obj_sql .= " AND (oq.question_text LIKE ?)";
    $obj_params[] = "%$search_term%";
}
$obj_sql .= " ORDER BY oq.id DESC LIMIT $items_per_page OFFSET $offset";
$obj_count_sql = str_replace("SELECT oq.*, s.subject_name, t.topic_name", "SELECT COUNT(*)", $obj_sql);
$obj_count_sql = preg_replace('/LIMIT \d+ OFFSET \d+/', '', $obj_count_sql);

$obj_total = $pdo->prepare($obj_count_sql);
$obj_total->execute($obj_params);
$obj_total_count = $obj_total->fetchColumn();
$obj_total_pages = ceil($obj_total_count / $items_per_page);

$stmt = $pdo->prepare($obj_sql);
$stmt->execute($obj_params);
$objective_qs = $stmt->fetchAll();

// Subjective Questions Query
$sub_sql = "SELECT sq.*, s.subject_name, t.topic_name 
            FROM subjective_questions sq
            LEFT JOIN subjects s ON sq.subject_id = s.id
            LEFT JOIN topics t ON sq.topic_id = t.id
            WHERE (sq.is_central = 1 OR sq.school_id IS NULL)";
$sub_params = [];

if ($filter_subject && $active_tab === 'subjective') {
    $sub_sql .= " AND sq.subject_id = ?";
    $sub_params[] = $filter_subject;
}
if ($filter_topic && $active_tab === 'subjective') {
    $sub_sql .= " AND sq.topic_id = ?";
    $sub_params[] = $filter_topic;
}
if ($filter_difficulty && $active_tab === 'subjective') {
    $sub_sql .= " AND sq.difficulty_level = ?";
    $sub_params[] = $filter_difficulty;
}
if ($search_term) {
    $sub_sql .= " AND (sq.question_text LIKE ?)";
    $sub_params[] = "%$search_term%";
}
$sub_sql .= " ORDER BY sq.id DESC LIMIT $items_per_page OFFSET $offset";

$sub_total_sql = str_replace("SELECT sq.*, s.subject_name, t.topic_name", "SELECT COUNT(*)", $sub_sql);
$sub_total_sql = preg_replace('/LIMIT \d+ OFFSET \d+/', '', $sub_total_sql);
$sub_total = $pdo->prepare($sub_total_sql);
$sub_total->execute($sub_params);
$sub_total_count = $sub_total->fetchColumn();
$sub_total_pages = ceil($sub_total_count / $items_per_page);

$stmt = $pdo->prepare($sub_sql);
$stmt->execute($sub_params);
$subjective_qs = $stmt->fetchAll();

// Theory Questions Query
$the_sql = "SELECT tq.*, s.subject_name, t.topic_name 
            FROM theory_questions tq
            LEFT JOIN subjects s ON tq.subject_id = s.id
            LEFT JOIN topics t ON tq.topic_id = t.id
            WHERE (tq.is_central = 1 OR tq.school_id IS NULL)";
$the_params = [];

if ($filter_subject && $active_tab === 'theory') {
    $the_sql .= " AND tq.subject_id = ?";
    $the_params[] = $filter_subject;
}
if ($filter_topic && $active_tab === 'theory') {
    $the_sql .= " AND tq.topic_id = ?";
    $the_params[] = $filter_topic;
}
if ($search_term) {
    $the_sql .= " AND (tq.question_text LIKE ?)";
    $the_params[] = "%$search_term%";
}
$the_sql .= " ORDER BY tq.id DESC LIMIT $items_per_page OFFSET $offset";

$the_total_sql = str_replace("SELECT tq.*, s.subject_name, t.topic_name", "SELECT COUNT(*)", $the_sql);
$the_total_sql = preg_replace('/LIMIT \d+ OFFSET \d+/', '', $the_total_sql);
$the_total = $pdo->prepare($the_total_sql);
$the_total->execute($the_params);
$the_total_count = $the_total->fetchColumn();
$the_total_pages = ceil($the_total_count / $items_per_page);

$stmt = $pdo->prepare($the_sql);
$stmt->execute($the_params);
$theory_qs = $stmt->fetchAll();

// WAEC Questions Query
$waec_sql = "SELECT wq.*, ws.subject_name, wt.topic_name 
             FROM waec_questions wq
             LEFT JOIN waec_subjects ws ON wq.waec_subject_id = ws.id
             LEFT JOIN waec_topics wt ON wq.waec_topic_id = wt.id
             WHERE wq.is_active = 1";
$waec_params = [];

if ($filter_subject && $active_tab === 'waec') {
    $waec_sql .= " AND wq.waec_subject_id = ?";
    $waec_params[] = $filter_subject;
}
if ($filter_topic && $active_tab === 'waec') {
    $waec_sql .= " AND wq.waec_topic_id = ?";
    $waec_params[] = $filter_topic;
}
if ($filter_year && $active_tab === 'waec') {
    $waec_sql .= " AND wq.exam_year = ?";
    $waec_params[] = $filter_year;
}
if ($filter_difficulty && $active_tab === 'waec') {
    $waec_sql .= " AND wq.difficulty_level = ?";
    $waec_params[] = $filter_difficulty;
}
if ($search_term) {
    $waec_sql .= " AND (wq.question_text LIKE ?)";
    $waec_params[] = "%$search_term%";
}
$waec_sql .= " ORDER BY wq.id DESC LIMIT $items_per_page OFFSET $offset";

$waec_total_sql = str_replace("SELECT wq.*, ws.subject_name, wt.topic_name", "SELECT COUNT(*)", $waec_sql);
$waec_total_sql = preg_replace('/LIMIT \d+ OFFSET \d+/', '', $waec_total_sql);
$waec_total = $pdo->prepare($waec_total_sql);
$waec_total->execute($waec_params);
$waec_total_count = $waec_total->fetchColumn();
$waec_total_pages = ceil($waec_total_count / $items_per_page);

$stmt = $pdo->prepare($waec_sql);
$stmt->execute($waec_params);
$waec_qs = $stmt->fetchAll();

// JAMB Questions Query
$jamb_sql = "SELECT jq.*, js.subject_name, jt.topic_name 
             FROM jamb_questions jq
             LEFT JOIN jamb_subjects js ON jq.jamb_subject_id = js.id
             LEFT JOIN jamb_topics jt ON jq.jamb_topic_id = jt.id
             WHERE jq.is_active = 1";
$jamb_params = [];

if ($filter_subject && $active_tab === 'jamb') {
    $jamb_sql .= " AND jq.jamb_subject_id = ?";
    $jamb_params[] = $filter_subject;
}
if ($filter_topic && $active_tab === 'jamb') {
    $jamb_sql .= " AND jq.jamb_topic_id = ?";
    $jamb_params[] = $filter_topic;
}
if ($filter_year && $active_tab === 'jamb') {
    $jamb_sql .= " AND jq.exam_year = ?";
    $jamb_params[] = $filter_year;
}
if ($filter_difficulty && $active_tab === 'jamb') {
    $jamb_sql .= " AND jq.difficulty_level = ?";
    $jamb_params[] = $filter_difficulty;
}
if ($search_term) {
    $jamb_sql .= " AND (jq.question_text LIKE ?)";
    $jamb_params[] = "%$search_term%";
}
$jamb_sql .= " ORDER BY jq.id DESC LIMIT $items_per_page OFFSET $offset";

$jamb_total_sql = str_replace("SELECT jq.*, js.subject_name, jt.topic_name", "SELECT COUNT(*)", $jamb_sql);
$jamb_total_sql = preg_replace('/LIMIT \d+ OFFSET \d+/', '', $jamb_total_sql);
$jamb_total = $pdo->prepare($jamb_total_sql);
$jamb_total->execute($jamb_params);
$jamb_total_count = $jamb_total->fetchColumn();
$jamb_total_pages = ceil($jamb_total_count / $items_per_page);

$stmt = $pdo->prepare($jamb_sql);
$stmt->execute($jamb_params);
$jamb_qs = $stmt->fetchAll();

// Get topics for filter (based on selected subject)
$topics_for_filter = [];
if ($filter_subject && $active_tab !== 'waec' && $active_tab !== 'jamb') {
    $stmt = $pdo->prepare("SELECT id, topic_name FROM topics WHERE subject_id = ? AND (is_central = 1 OR school_id IS NULL) ORDER BY topic_name");
    $stmt->execute([$filter_subject]);
    $topics_for_filter = $stmt->fetchAll();
} elseif ($filter_subject && $active_tab === 'waec') {
    $stmt = $pdo->prepare("SELECT id, topic_name FROM waec_topics WHERE waec_subject_id = ? AND is_active = 1 ORDER BY topic_name");
    $stmt->execute([$filter_subject]);
    $topics_for_filter = $stmt->fetchAll();
} elseif ($filter_subject && $active_tab === 'jamb') {
    $stmt = $pdo->prepare("SELECT id, topic_name FROM jamb_topics WHERE jamb_subject_id = ? AND is_active = 1 ORDER BY topic_name");
    $stmt->execute([$filter_subject]);
    $topics_for_filter = $stmt->fetchAll();
}

include 'includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<!-- Header with Add Button -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
    <div>
        <h2 style="margin: 0;">Manage Questions</h2>
        <p style="color: #666; margin-top: 5px;">View, filter, edit, and delete questions from all question banks</p>
    </div>
    <a href="add_question.php" class="btn btn-primary" style="background: #3498db; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none;">
        <i class="fas fa-plus-circle"></i> Add New Question
    </a>
</div>

<!-- Tabs -->
<div class="tabs-navigation" style="background: white; border-radius: 12px; margin-bottom: 20px; overflow-x: auto;">
    <div style="display: flex; border-bottom: 2px solid #eee; min-width: max-content;">
        <a href="?tab=objective&<?php echo http_build_query(array_filter(['filter_subject' => $filter_subject, 'filter_topic' => $filter_topic, 'filter_year' => $filter_year, 'filter_difficulty' => $filter_difficulty, 'search' => $search_term])); ?>" class="tab-link" style="padding: 12px 20px; text-decoration: none; color: <?php echo $active_tab === 'objective' ? '#3498db' : '#666'; ?>; border-bottom: 2px solid <?php echo $active_tab === 'objective' ? '#3498db' : 'transparent'; ?>; margin-bottom: -2px;"><i class="fas fa-check-circle"></i> Objective (<?php echo $obj_count; ?>)</a>
        <a href="?tab=subjective&<?php echo http_build_query(array_filter(['filter_subject' => $filter_subject, 'filter_topic' => $filter_topic, 'filter_year' => $filter_year, 'filter_difficulty' => $filter_difficulty, 'search' => $search_term])); ?>" class="tab-link" style="padding: 12px 20px; text-decoration: none; color: <?php echo $active_tab === 'subjective' ? '#3498db' : '#666'; ?>; border-bottom: 2px solid <?php echo $active_tab === 'subjective' ? '#3498db' : 'transparent'; ?>; margin-bottom: -2px;"><i class="fas fa-edit"></i> Subjective (<?php echo $sub_count; ?>)</a>
        <a href="?tab=theory&<?php echo http_build_query(array_filter(['filter_subject' => $filter_subject, 'filter_topic' => $filter_topic, 'filter_year' => $filter_year, 'filter_difficulty' => $filter_difficulty, 'search' => $search_term])); ?>" class="tab-link" style="padding: 12px 20px; text-decoration: none; color: <?php echo $active_tab === 'theory' ? '#3498db' : '#666'; ?>; border-bottom: 2px solid <?php echo $active_tab === 'theory' ? '#3498db' : 'transparent'; ?>; margin-bottom: -2px;"><i class="fas fa-file-alt"></i> Theory (<?php echo $the_count; ?>)</a>
        <a href="?tab=waec&<?php echo http_build_query(array_filter(['filter_subject' => $filter_subject, 'filter_topic' => $filter_topic, 'filter_year' => $filter_year, 'filter_difficulty' => $filter_difficulty, 'search' => $search_term])); ?>" class="tab-link" style="padding: 12px 20px; text-decoration: none; color: <?php echo $active_tab === 'waec' ? '#3498db' : '#666'; ?>; border-bottom: 2px solid <?php echo $active_tab === 'waec' ? '#3498db' : 'transparent'; ?>; margin-bottom: -2px;"><i class="fas fa-graduation-cap"></i> WAEC (<?php echo $waec_count; ?>)</a>
        <a href="?tab=jamb&<?php echo http_build_query(array_filter(['filter_subject' => $filter_subject, 'filter_topic' => $filter_topic, 'filter_year' => $filter_year, 'filter_difficulty' => $filter_difficulty, 'search' => $search_term])); ?>" class="tab-link" style="padding: 12px 20px; text-decoration: none; color: <?php echo $active_tab === 'jamb' ? '#3498db' : '#666'; ?>; border-bottom: 2px solid <?php echo $active_tab === 'jamb' ? '#3498db' : 'transparent'; ?>; margin-bottom: -2px;"><i class="fas fa-university"></i> JAMB (<?php echo $jamb_count; ?>)</a>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-card" style="background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px;">
    <form method="GET" id="filterForm" style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
        <input type="hidden" name="tab" value="<?php echo $active_tab; ?>">
        
        <div class="filter-group" style="flex: 1; min-width: 150px;">
            <label class="filter-label">Subject</label>
            <select name="filter_subject" class="form-control" onchange="this.form.submit()">
                <option value="0">All Subjects</option>
                <?php 
                $subject_list = [];
                if ($active_tab === 'waec') $subject_list = $waec_subjects;
                elseif ($active_tab === 'jamb') $subject_list = $jamb_subjects;
                else $subject_list = $subjects;
                foreach ($subject_list as $s): ?>
                    <option value="<?php echo $s['id']; ?>" <?php echo ($filter_subject == $s['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($s['subject_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="filter-group" style="flex: 1; min-width: 150px;">
            <label class="filter-label">Topic</label>
            <select name="filter_topic" class="form-control" onchange="this.form.submit()">
                <option value="0">All Topics</option>
                <?php foreach ($topics_for_filter as $t): ?>
                    <option value="<?php echo $t['id']; ?>" <?php echo ($filter_topic == $t['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($t['topic_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <?php if ($active_tab === 'waec' || $active_tab === 'jamb'): ?>
        <div class="filter-group" style="flex: 1; min-width: 120px;">
            <label class="filter-label">Year</label>
            <select name="filter_year" class="form-control" onchange="this.form.submit()">
                <option value="0">All Years</option>
                <?php foreach ($years as $y): ?>
                    <option value="<?php echo $y; ?>" <?php echo ($filter_year == $y) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        
        <div class="filter-group" style="flex: 1; min-width: 120px;">
            <label class="filter-label">Difficulty</label>
            <select name="filter_difficulty" class="form-control" onchange="this.form.submit()">
                <option value="">All Levels</option>
                <option value="easy" <?php echo $filter_difficulty === 'easy' ? 'selected' : ''; ?>>Easy</option>
                <option value="medium" <?php echo $filter_difficulty === 'medium' ? 'selected' : ''; ?>>Medium</option>
                <option value="hard" <?php echo $filter_difficulty === 'hard' ? 'selected' : ''; ?>>Hard</option>
            </select>
        </div>
        
        <div class="filter-group" style="flex: 2; min-width: 200px;">
            <label class="filter-label">Search</label>
            <input type="text" name="search" class="form-control" placeholder="Search questions..." value="<?php echo htmlspecialchars($search_term); ?>">
        </div>
        
        <div class="filter-group">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filter</button>
            <a href="?tab=<?php echo $active_tab; ?>" class="btn btn-outline btn-sm"><i class="fas fa-times"></i> Clear</a>
        </div>
    </form>
</div>

<!-- ============================================ -->
<!-- OBJECTIVE TAB -->
<!-- ============================================ -->
<?php if ($active_tab === 'objective'): ?>
<div class="card">
    <div class="table-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h3 style="margin: 0;">Objective Questions <?php echo $obj_total_count ? "({$obj_total_count} total)" : ''; ?></h3>
        <span class="badge">Showing <?php echo count($objective_qs); ?> of <?php echo $obj_total_count; ?></span>
    </div>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 50px;">ID</th>
                    <th>Question</th>
                    <th style="width: 150px;">Subject</th>
                    <th style="width: 130px;">Topic</th>
                    <th style="width: 200px;">Options</th>
                    <th style="width: 70px;">Correct</th>
                    <th style="width: 70px;">Marks</th>
                    <th style="width: 100px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($objective_qs as $q): ?>
                <tr class="question-row" data-id="<?php echo $q['id']; ?>" data-type="objective">
                    <td><?php echo $q['id']; ?></td>
                    <td class="question-text"><?php echo htmlspecialchars(substr($q['question_text'], 0, 80)) . (strlen($q['question_text']) > 80 ? '...' : ''); ?></td>
                    <td><?php echo htmlspecialchars($q['subject_name'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($q['topic_name'] ?? '-'); ?></td>
                    <td class="options-cell" style="font-size: 0.75rem;">
                        <span class="option-badge">A: <?php echo htmlspecialchars(substr($q['option_a'], 0, 25)); ?></span><br>
                        <span class="option-badge">B: <?php echo htmlspecialchars(substr($q['option_b'], 0, 25)); ?></span>
                    </td>
                    <td><strong class="correct-answer"><?php echo $q['correct_answer']; ?></strong></td>
                    <td><?php echo $q['marks']; ?></td>
                    <td>
                        <div class="action-buttons">
                            <button onclick="openViewModal('objective', <?php echo $q['id']; ?>)" class="btn btn-info btn-sm" title="View"><i class="fas fa-eye"></i></button>
                            <button onclick="openEditModal('objective', <?php echo $q['id']; ?>)" class="btn btn-warning btn-sm" title="Edit"><i class="fas fa-edit"></i></button>
                            <a href="?delete=1&type=objective&id=<?php echo $q['id']; ?>&tab=objective&page=<?php echo $current_page; ?><?php echo $filter_subject ? "&filter_subject=$filter_subject" : ''; ?><?php echo $filter_topic ? "&filter_topic=$filter_topic" : ''; ?><?php echo $search_term ? "&search=" . urlencode($search_term) : ''; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this question?')" title="Delete"><i class="fas fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($objective_qs)): ?>
                <tr><td colspan="8" style="text-align: center; padding: 40px;">No objective questions found. <a href="add_question.php?type=objective">Add one</a></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($obj_total_pages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $obj_total_pages; $i++): ?>
            <a href="?tab=objective&page=<?php echo $i; ?><?php echo $filter_subject ? "&filter_subject=$filter_subject" : ''; ?><?php echo $filter_topic ? "&filter_topic=$filter_topic" : ''; ?><?php echo $filter_difficulty ? "&filter_difficulty=$filter_difficulty" : ''; ?><?php echo $search_term ? "&search=" . urlencode($search_term) : ''; ?>" class="page-link <?php echo $i == $current_page ? 'active' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- SUBJECTIVE TAB -->
<!-- ============================================ -->
<?php if ($active_tab === 'subjective'): ?>
<div class="card">
    <div class="table-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h3 style="margin: 0;">Subjective Questions <?php echo $sub_total_count ? "({$sub_total_count} total)" : ''; ?></h3>
        <span class="badge">Showing <?php echo count($subjective_qs); ?> of <?php echo $sub_total_count; ?></span>
    </div>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr><th>ID</th><th>Question</th><th>Subject</th><th>Topic</th><th>Marks</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($subjective_qs as $q): ?>
                <tr>
                    <td><?php echo $q['id']; ?></td>
                    <td><?php echo htmlspecialchars(substr($q['question_text'], 0, 80)) . (strlen($q['question_text']) > 80 ? '...' : ''); ?></td>
                    <td><?php echo htmlspecialchars($q['subject_name'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($q['topic_name'] ?? '-'); ?></td>
                    <td><?php echo $q['marks']; ?></td>
                    <td>
                        <button onclick="openViewModal('subjective', <?php echo $q['id']; ?>)" class="btn btn-info btn-sm"><i class="fas fa-eye"></i></button>
                        <button onclick="openEditModal('subjective', <?php echo $q['id']; ?>)" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></button>
                        <a href="?delete=1&type=subjective&id=<?php echo $q['id']; ?>&tab=subjective&page=<?php echo $current_page; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($subjective_qs)): ?>
                <tr><td colspan="6" style="text-align: center; padding: 40px;">No subjective questions found. <a href="add_question.php?type=subjective">Add one</a></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($sub_total_pages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $sub_total_pages; $i++): ?>
            <a href="?tab=subjective&page=<?php echo $i; ?><?php echo $filter_subject ? "&filter_subject=$filter_subject" : ''; ?><?php echo $filter_topic ? "&filter_topic=$filter_topic" : ''; ?><?php echo $filter_difficulty ? "&filter_difficulty=$filter_difficulty" : ''; ?><?php echo $search_term ? "&search=" . urlencode($search_term) : ''; ?>" class="page-link <?php echo $i == $current_page ? 'active' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- THEORY TAB -->
<!-- ============================================ -->
<?php if ($active_tab === 'theory'): ?>
<div class="card">
    <div class="table-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h3 style="margin: 0;">Theory Questions <?php echo $the_total_count ? "({$the_total_count} total)" : ''; ?></h3>
        <span class="badge">Showing <?php echo count($theory_qs); ?> of <?php echo $the_total_count; ?></span>
    </div>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr><th>ID</th><th>Question</th><th>Subject</th><th>Topic</th><th>File</th><th>Marks</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($theory_qs as $q): ?>
                <tr>
                    <td><?php echo $q['id']; ?></td>
                    <td><?php echo htmlspecialchars(substr($q['question_text'] ?? '', 0, 80)) . (strlen($q['question_text'] ?? '') > 80 ? '...' : ''); ?></td>
                    <td><?php echo htmlspecialchars($q['subject_name'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($q['topic_name'] ?? '-'); ?></td>
                    <td><?php echo $q['question_file'] ? '<i class="fas fa-paperclip"></i>' : '—'; ?></td>
                    <td><?php echo $q['marks']; ?></td>
                    <td>
                        <button onclick="openViewModal('theory', <?php echo $q['id']; ?>)" class="btn btn-info btn-sm"><i class="fas fa-eye"></i></button>
                        <button onclick="openEditModal('theory', <?php echo $q['id']; ?>)" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></button>
                        <a href="?delete=1&type=theory&id=<?php echo $q['id']; ?>&tab=theory&page=<?php echo $current_page; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($theory_qs)): ?>
                <tr><td colspan="7" style="text-align: center; padding: 40px;">No theory questions found. <a href="add_question.php?type=theory">Add one</a></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($the_total_pages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $the_total_pages; $i++): ?>
            <a href="?tab=theory&page=<?php echo $i; ?><?php echo $filter_subject ? "&filter_subject=$filter_subject" : ''; ?><?php echo $filter_topic ? "&filter_topic=$filter_topic" : ''; ?><?php echo $search_term ? "&search=" . urlencode($search_term) : ''; ?>" class="page-link <?php echo $i == $current_page ? 'active' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- WAEC TAB -->
<!-- ============================================ -->
<?php if ($active_tab === 'waec'): ?>
<div class="card">
    <div class="table-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h3 style="margin: 0;">WAEC Questions <?php echo $waec_total_count ? "({$waec_total_count} total)" : ''; ?></h3>
        <span class="badge">Showing <?php echo count($waec_qs); ?> of <?php echo $waec_total_count; ?></span>
    </div>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr><th>ID</th><th>Year</th><th>Subject</th><th>Question</th><th>Correct</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($waec_qs as $q): ?>
                <tr>
                    <td><?php echo $q['id']; ?></td>
                    <td><?php echo $q['exam_year']; ?></td>
                    <td><strong><?php echo htmlspecialchars($q['subject_name'] ?? 'N/A'); ?></strong></td>
                    <td><?php echo htmlspecialchars(substr($q['question_text'], 0, 70)) . (strlen($q['question_text']) > 70 ? '...' : ''); ?></td>
                    <td><strong class="correct-answer"><?php echo $q['correct_answer']; ?></strong></td>
                    <td>
                        <button onclick="openViewModal('waec', <?php echo $q['id']; ?>)" class="btn btn-info btn-sm"><i class="fas fa-eye"></i></button>
                        <button onclick="openEditModal('waec', <?php echo $q['id']; ?>)" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></button>
                        <a href="?delete=1&type=waec&id=<?php echo $q['id']; ?>&tab=waec&page=<?php echo $current_page; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this WAEC question?')"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($waec_qs)): ?>
                <tr><td colspan="6" style="text-align: center; padding: 40px;">No WAEC questions found. <a href="add_question.php?type=waec">Add one</a></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($waec_total_pages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $waec_total_pages; $i++): ?>
            <a href="?tab=waec&page=<?php echo $i; ?><?php echo $filter_subject ? "&filter_subject=$filter_subject" : ''; ?><?php echo $filter_topic ? "&filter_topic=$filter_topic" : ''; ?><?php echo $filter_year ? "&filter_year=$filter_year" : ''; ?><?php echo $filter_difficulty ? "&filter_difficulty=$filter_difficulty" : ''; ?><?php echo $search_term ? "&search=" . urlencode($search_term) : ''; ?>" class="page-link <?php echo $i == $current_page ? 'active' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- JAMB TAB -->
<!-- ============================================ -->
<?php if ($active_tab === 'jamb'): ?>
<div class="card">
    <div class="table-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h3 style="margin: 0;">JAMB Questions <?php echo $jamb_total_count ? "({$jamb_total_count} total)" : ''; ?></h3>
        <span class="badge">Showing <?php echo count($jamb_qs); ?> of <?php echo $jamb_total_count; ?></span>
    </div>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr><th>ID</th><th>Year</th><th>Subject</th><th>Question</th><th>Correct</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($jamb_qs as $q): ?>
                <tr>
                    <td><?php echo $q['id']; ?></td>
                    <td><?php echo $q['exam_year']; ?></td>
                    <td><strong><?php echo htmlspecialchars($q['subject_name'] ?? 'N/A'); ?></strong></td>
                    <td><?php echo htmlspecialchars(substr($q['question_text'], 0, 70)) . (strlen($q['question_text']) > 70 ? '...' : ''); ?></td>
                    <td><strong class="correct-answer"><?php echo $q['correct_answer']; ?></strong></td>
                    <td>
                        <button onclick="openViewModal('jamb', <?php echo $q['id']; ?>)" class="btn btn-info btn-sm"><i class="fas fa-eye"></i></button>
                        <button onclick="openEditModal('jamb', <?php echo $q['id']; ?>)" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></button>
                        <a href="?delete=1&type=jamb&id=<?php echo $q['id']; ?>&tab=jamb&page=<?php echo $current_page; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this JAMB question?')"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($jamb_qs)): ?>
                <tr><td colspan="6" style="text-align: center; padding: 40px;">No JAMB questions found. <a href="add_question.php?type=jamb">Add one</a></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($jamb_total_pages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $jamb_total_pages; $i++): ?>
            <a href="?tab=jamb&page=<?php echo $i; ?><?php echo $filter_subject ? "&filter_subject=$filter_subject" : ''; ?><?php echo $filter_topic ? "&filter_topic=$filter_topic" : ''; ?><?php echo $filter_year ? "&filter_year=$filter_year" : ''; ?><?php echo $filter_difficulty ? "&filter_difficulty=$filter_difficulty" : ''; ?><?php echo $search_term ? "&search=" . urlencode($search_term) : ''; ?>" class="page-link <?php echo $i == $current_page ? 'active' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- View Modal -->
<div id="viewModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h3 id="viewModalTitle">Question Details</h3>
            <button onclick="closeViewModal()" style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
        </div>
        <div class="modal-body" id="viewModalBody">
            <div style="text-align: center; padding: 40px;">Loading...</div>
        </div>
        <div class="modal-footer">
            <button onclick="closeViewModal()" class="btn btn-outline">Close</button>
            <button id="viewEditBtn" class="btn btn-warning">Edit Question</button>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 800px; max-height: 85vh; overflow-y: auto;">
        <div class="modal-header">
            <h3 id="editModalTitle">Edit Question</h3>
            <button onclick="closeEditModal()" style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
        </div>
        <div class="modal-body" id="editModalBody">
            <div style="text-align: center; padding: 40px;">Loading...</div>
        </div>
    </div>
</div>

<script>
let currentViewQuestion = null;
let currentViewType = null;

function openViewModal(type, id) {
    currentViewType = type;
    currentViewQuestion = id;
    const modal = document.getElementById('viewModal');
    const modalTitle = document.getElementById('viewModalTitle');
    const modalBody = document.getElementById('viewModalBody');
    
    modalTitle.innerHTML = `${type.charAt(0).toUpperCase() + type.slice(1)} Question Details`;
    modalBody.innerHTML = '<div style="text-align: center; padding: 40px;">Loading question details...</div>';
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    fetch(`ajax/get_question_details.php?type=${type}&id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderViewModal(type, data.question);
            } else {
                modalBody.innerHTML = `<div style="color: red; padding: 20px;">Error: ${data.message}</div>`;
            }
        })
        .catch(err => {
            modalBody.innerHTML = `<div style="color: red; padding: 20px;">Failed to load: ${err.message}</div>`;
        });
}

function renderViewModal(type, q) {
    const modalBody = document.getElementById('viewModalBody');
    let html = '';
    
    if (type === 'objective') {
        html = `
            <div class="detail-section"><strong>Question ID:</strong> #${q.id}</div>
            <div class="detail-section"><strong>Question Text:</strong><div class="detail-box">${escapeHtml(q.question_text)}</div></div>
            <div class="detail-grid">
                <div><strong>Option A:</strong> ${escapeHtml(q.option_a)}</div>
                <div><strong>Option B:</strong> ${escapeHtml(q.option_b)}</div>
                <div><strong>Option C:</strong> ${escapeHtml(q.option_c || '—')}</div>
                <div><strong>Option D:</strong> ${escapeHtml(q.option_d || '—')}</div>
            </div>
            <div class="detail-section"><strong>Correct Answer:</strong> <span class="correct-badge">${q.correct_answer}</span></div>
            <div class="detail-grid">
                <div><strong>Subject:</strong> ${escapeHtml(q.subject_name || 'N/A')}</div>
                <div><strong>Topic:</strong> ${escapeHtml(q.topic_name || 'None')}</div>
                <div><strong>Difficulty:</strong> <span class="difficulty-${q.difficulty_level}">${q.difficulty_level || 'medium'}</span></div>
                <div><strong>Marks:</strong> ${q.marks}</div>
            </div>
            ${q.question_image ? `<div class="detail-section"><strong>Image:</strong><br><img src="../${q.question_image}" style="max-width: 100%; border-radius: 8px; margin-top: 10px;"></div>` : ''}
        `;
    } else if (type === 'subjective') {
        html = `
            <div class="detail-section"><strong>Question ID:</strong> #${q.id}</div>
            <div class="detail-section"><strong>Question Text:</strong><div class="detail-box">${escapeHtml(q.question_text)}</div></div>
            <div class="detail-section"><strong>Model Answer / Marking Guide:</strong><div class="detail-box" style="background: #e8f5e9;">${escapeHtml(q.correct_answer || 'Not provided')}</div></div>
            <div class="detail-grid">
                <div><strong>Subject:</strong> ${escapeHtml(q.subject_name || 'N/A')}</div>
                <div><strong>Topic:</strong> ${escapeHtml(q.topic_name || 'None')}</div>
                <div><strong>Difficulty:</strong> <span class="difficulty-${q.difficulty_level}">${q.difficulty_level || 'medium'}</span></div>
                <div><strong>Marks:</strong> ${q.marks}</div>
            </div>
        `;
    } else if (type === 'theory') {
        html = `
            <div class="detail-section"><strong>Question ID:</strong> #${q.id}</div>
            <div class="detail-section"><strong>Question Text:</strong><div class="detail-box">${escapeHtml(q.question_text || 'Content in attached file')}</div></div>
            ${q.question_file ? `<div class="detail-section"><strong>Attachment:</strong><br><a href="../${q.question_file}" target="_blank" class="btn btn-primary btn-sm"><i class="fas fa-download"></i> Download File</a></div>` : ''}
            <div class="detail-grid">
                <div><strong>Subject:</strong> ${escapeHtml(q.subject_name || 'N/A')}</div>
                <div><strong>Topic:</strong> ${escapeHtml(q.topic_name || 'None')}</div>
                <div><strong>Marks:</strong> ${q.marks}</div>
            </div>
        `;
    } else if (type === 'waec') {
        html = `
            <div class="detail-section"><strong>Question ID:</strong> #${q.id}</div>
            <div class="detail-section"><strong>Question Text:</strong><div class="detail-box">${escapeHtml(q.question_text)}</div></div>
            <div class="detail-grid">
                <div><strong>Option A:</strong> ${escapeHtml(q.option_a)}</div>
                <div><strong>Option B:</strong> ${escapeHtml(q.option_b)}</div>
                <div><strong>Option C:</strong> ${escapeHtml(q.option_c)}</div>
                <div><strong>Option D:</strong> ${escapeHtml(q.option_d || '—')}</div>
                ${q.option_e ? `<div><strong>Option E:</strong> ${escapeHtml(q.option_e)}</div>` : ''}
            </div>
            <div class="detail-section"><strong>Correct Answer:</strong> <span class="correct-badge">${q.correct_answer}</span></div>
            <div class="detail-section"><strong>Explanation:</strong><div class="detail-box" style="background: #eaf6ff;">${escapeHtml(q.explanation || 'No explanation provided')}</div></div>
            <div class="detail-grid">
                <div><strong>Subject:</strong> ${escapeHtml(q.subject_name || 'N/A')}</div>
                <div><strong>Topic:</strong> ${escapeHtml(q.topic_name || 'None')}</div>
                <div><strong>Year:</strong> ${q.exam_year}</div>
                <div><strong>Difficulty:</strong> <span class="difficulty-${q.difficulty_level}">${q.difficulty_level || 'medium'}</span></div>
            </div>
            ${q.question_image ? `<div class="detail-section"><strong>Image:</strong><br><img src="../${q.question_image}" style="max-width: 100%; border-radius: 8px; margin-top: 10px;"></div>` : ''}
        `;
    } else if (type === 'jamb') {
        html = `
            <div class="detail-section"><strong>Question ID:</strong> #${q.id}</div>
            <div class="detail-section"><strong>Question Text:</strong><div class="detail-box">${escapeHtml(q.question_text)}</div></div>
            <div class="detail-grid">
                <div><strong>Option A:</strong> ${escapeHtml(q.option_a)}</div>
                <div><strong>Option B:</strong> ${escapeHtml(q.option_b)}</div>
                <div><strong>Option C:</strong> ${escapeHtml(q.option_c)}</div>
                <div><strong>Option D:</strong> ${escapeHtml(q.option_d)}</div>
            </div>
            <div class="detail-section"><strong>Correct Answer:</strong> <span class="correct-badge">${q.correct_answer}</span></div>
            <div class="detail-section"><strong>Explanation:</strong><div class="detail-box" style="background: #eaf6ff;">${escapeHtml(q.explanation || 'No explanation provided')}</div></div>
            <div class="detail-grid">
                <div><strong>Subject:</strong> ${escapeHtml(q.subject_name || 'N/A')}</div>
                <div><strong>Topic:</strong> ${escapeHtml(q.topic_name || 'None')}</div>
                <div><strong>Year:</strong> ${q.exam_year}</div>
                <div><strong>Difficulty:</strong> <span class="difficulty-${q.difficulty_level}">${q.difficulty_level || 'medium'}</span></div>
            </div>
            ${q.question_image ? `<div class="detail-section"><strong>Image:</strong><br><img src="../${q.question_image}" style="max-width: 100%; border-radius: 8px; margin-top: 10px;"></div>` : ''}
        `;
    }
    
    modalBody.innerHTML = html;
    document.getElementById('viewEditBtn').onclick = () => {
        closeViewModal();
        openEditModal(currentViewType, currentViewQuestion);
    };
}

function closeViewModal() {
    document.getElementById('viewModal').style.display = 'none';
    document.body.style.overflow = '';
    currentViewQuestion = null;
    currentViewType = null;
}

function openEditModal(type, id) {
    const modal = document.getElementById('editModal');
    const modalTitle = document.getElementById('editModalTitle');
    const modalBody = document.getElementById('editModalBody');
    
    modalTitle.innerHTML = `Edit ${type.charAt(0).toUpperCase() + type.slice(1)} Question`;
    modalBody.innerHTML = '<div style="text-align: center; padding: 40px;">Loading question data...</div>';
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    fetch(`ajax/get_question_details.php?type=${type}&id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderEditForm(type, data.question);
            } else {
                modalBody.innerHTML = `<div style="color: red; padding: 20px;">Error: ${data.message}</div>`;
            }
        })
        .catch(err => {
            modalBody.innerHTML = `<div style="color: red; padding: 20px;">Failed to load: ${err.message}</div>`;
        });
}

function renderEditForm(type, q) {
    const modalBody = document.getElementById('editModalBody');
    let html = '';
    
    if (type === 'objective') {
        html = `
            <form method="POST">
                <input type="hidden" name="id" value="${q.id}">
                <input type="hidden" name="edit_objective" value="1">
                <div class="form-group"><label>Question Text *</label><textarea name="question_text" class="form-control" rows="4" required>${escapeHtml(q.question_text)}</textarea></div>
                <div style="display: grid; grid-template-columns: repeat(2,1fr); gap:15px;">
                    <div class="form-group"><label>Option A *</label><input type="text" name="option_a" class="form-control" value="${escapeHtml(q.option_a)}" required></div>
                    <div class="form-group"><label>Option B *</label><input type="text" name="option_b" class="form-control" value="${escapeHtml(q.option_b)}" required></div>
                    <div class="form-group"><label>Option C</label><input type="text" name="option_c" class="form-control" value="${escapeHtml(q.option_c || '')}"></div>
                    <div class="form-group"><label>Option D</label><input type="text" name="option_d" class="form-control" value="${escapeHtml(q.option_d || '')}"></div>
                </div>
                <div style="display: grid; grid-template-columns: repeat(4,1fr); gap:15px;">
                    <div class="form-group"><label>Correct Answer</label><select name="correct_answer" class="form-control"><option value="A" ${q.correct_answer === 'A' ? 'selected' : ''}>A</option><option value="B" ${q.correct_answer === 'B' ? 'selected' : ''}>B</option><option value="C" ${q.correct_answer === 'C' ? 'selected' : ''}>C</option><option value="D" ${q.correct_answer === 'D' ? 'selected' : ''}>D</option></select></div>
                    <div class="form-group"><label>Subject</label><select name="subject_id" class="form-control"><?php foreach ($subjects as $s): ?><option value="<?php echo $s['id']; ?>" ${q.subject_id == <?php echo $s['id']; ?> ? 'selected' : ''}>${escapeHtml('<?php echo addslashes($s['subject_name']); ?>')}</option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Difficulty</label><select name="difficulty" class="form-control"><option value="easy" ${q.difficulty_level === 'easy' ? 'selected' : ''}>Easy</option><option value="medium" ${q.difficulty_level === 'medium' ? 'selected' : ''}>Medium</option><option value="hard" ${q.difficulty_level === 'hard' ? 'selected' : ''}>Hard</option></select></div>
                    <div class="form-group"><label>Marks</label><input type="number" name="marks" class="form-control" value="${q.marks}" min="1"></div>
                </div>
                <div style="margin-top: 20px; text-align: right;"><button type="submit" class="btn btn-primary">Save Changes</button></div>
            </form>
        `;
    } else {
        // Simplified edit forms for other types...
        html = `<div style="padding: 20px; text-align: center;">Edit form for ${type} questions is being loaded. <a href="edit_question.php?type=${type}&id=${q.id}" target="_blank">Open full editor</a></div>`;
    }
    
    modalBody.innerHTML = html;
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
    document.body.style.overflow = '';
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close modals when clicking outside
window.onclick = function(event) {
    const viewModal = document.getElementById('viewModal');
    const editModal = document.getElementById('editModal');
    if (event.target === viewModal) closeViewModal();
    if (event.target === editModal) closeEditModal();
}
</script>

<style>
.filter-card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
.filter-group { flex: 1; min-width: 150px; }
.filter-label { display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; color: #666; }
.table-container { overflow-x: auto; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { padding: 12px 10px; text-align: left; border-bottom: 1px solid #eee; vertical-align: top; }
.data-table th { background: #f8f9fa; font-weight: 600; font-size: 0.8rem; }
.question-row:hover { background: #f5f7fa; }
.question-text { font-size: 0.85rem; }
.options-cell { font-size: 0.7rem; color: #555; }
.option-badge { display: inline-block; margin-bottom: 2px; }
.correct-answer { display: inline-block; background: #27ae60; color: white; padding: 3px 8px; border-radius: 15px; font-size: 0.7rem; }
.correct-badge { background: #27ae60; color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.8rem; }
.difficulty-easy { color: #27ae60; text-transform: capitalize; }
.difficulty-medium { color: #f39c12; text-transform: capitalize; }
.difficulty-hard { color: #e74c3c; text-transform: capitalize; }
.action-buttons { display: flex; gap: 5px; flex-wrap: wrap; }
.btn-sm { padding: 5px 10px; font-size: 0.7rem; border-radius: 6px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; text-decoration: none; }
.btn-info { background: #3498db; color: white; }
.btn-warning { background: #f39c12; color: white; }
.btn-danger { background: #e74c3c; color: white; }
.btn-primary { background: #3498db; color: white; }
.btn-outline { background: transparent; border: 1px solid #ddd; color: #333; }
.pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; flex-wrap: wrap; }
.page-link { padding: 6px 12px; border: 1px solid #ddd; border-radius: 6px; text-decoration: none; color: #333; }
.page-link.active { background: #3498db; color: white; border-color: #3498db; }
.badge { background: #e9ecef; padding: 4px 10px; border-radius: 20px; font-size: 0.7rem; }
.detail-section { margin-bottom: 20px; }
.detail-box { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 8px; }
.detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
.modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
.modal-content { background: white; border-radius: 12px; width: 90%; max-width: 800px; max-height: 85vh; overflow-y: auto; }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; border-bottom: 1px solid #ddd; position: sticky; top: 0; background: white; }
.modal-body { padding: 20px; }
.modal-footer { padding: 15px 20px; border-top: 1px solid #ddd; display: flex; justify-content: flex-end; gap: 10px; }
.form-group { margin-bottom: 15px; }
.form-group label { display: block; margin-bottom: 5px; font-weight: 500; font-size: 0.85rem; }
.form-control { width: 100%; padding: 8px 12px; border: 2px solid #e4e7eb; border-radius: 8px; font-family: inherit; font-size: 0.85rem; }
.alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
.alert-success { background: #d5f4e6; color: #27ae60; border-left: 4px solid #27ae60; }
.alert-error { background: #fbe9e7; color: #e74c3c; border-left: 4px solid #e74c3c; }
.card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
@media (max-width: 768px) {
    .filter-group { min-width: 100%; }
    .data-table th, .data-table td { padding: 8px; font-size: 0.75rem; }
    .action-buttons { flex-direction: column; }
}
</style>

<?php include 'includes/footer.php'; ?>
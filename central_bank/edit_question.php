<?php
// /central_bank/edit_question.php - Unified edit page for all question types

require_once 'includes/config.php';
require_once 'includes/auth.php';
require_super_admin();

$page_title = 'Edit Question';
$message = '';
$message_type = '';

// Get parameters
$type = $_GET['type'] ?? $_POST['type'] ?? '';
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if (!$type || !$id) {
    header("Location: manage_questions.php");
    exit();
}

// Fetch question data based on type
$question = null;
$subjects = [];
$topics = [];

// Common data for dropdowns
$subjects = $pdo->query("SELECT id, subject_name FROM subjects WHERE is_central = 1 OR school_id IS NULL ORDER BY subject_name")->fetchAll();
$waec_subjects = $pdo->query("SELECT id, subject_name FROM waec_subjects WHERE is_active = 1 ORDER BY subject_name")->fetchAll();
$jamb_subjects = $pdo->query("SELECT id, subject_name FROM jamb_subjects WHERE is_active = 1 ORDER BY subject_name")->fetchAll();

// Fetch question based on type
switch ($type) {
    case 'objective':
        $stmt = $pdo->prepare("
            SELECT oq.*, s.subject_name, t.topic_name 
            FROM objective_questions oq
            LEFT JOIN subjects s ON oq.subject_id = s.id
            LEFT JOIN topics t ON oq.topic_id = t.id
            WHERE oq.id = ? AND (oq.is_central = 1 OR oq.school_id IS NULL)
        ");
        $stmt->execute([$id]);
        $question = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get topics for this subject
        if ($question && $question['subject_id']) {
            $stmt = $pdo->prepare("SELECT id, topic_name FROM topics WHERE subject_id = ?");
            $stmt->execute([$question['subject_id']]);
            $topics = $stmt->fetchAll();
        }
        break;
        
    case 'subjective':
        $stmt = $pdo->prepare("
            SELECT sq.*, s.subject_name, t.topic_name 
            FROM subjective_questions sq
            LEFT JOIN subjects s ON sq.subject_id = s.id
            LEFT JOIN topics t ON sq.topic_id = t.id
            WHERE sq.id = ? AND (sq.is_central = 1 OR sq.school_id IS NULL)
        ");
        $stmt->execute([$id]);
        $question = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($question && $question['subject_id']) {
            $stmt = $pdo->prepare("SELECT id, topic_name FROM topics WHERE subject_id = ?");
            $stmt->execute([$question['subject_id']]);
            $topics = $stmt->fetchAll();
        }
        break;
        
    case 'theory':
        $stmt = $pdo->prepare("
            SELECT tq.*, s.subject_name, t.topic_name 
            FROM theory_questions tq
            LEFT JOIN subjects s ON tq.subject_id = s.id
            LEFT JOIN topics t ON tq.topic_id = t.id
            WHERE tq.id = ? AND (tq.is_central = 1 OR tq.school_id IS NULL)
        ");
        $stmt->execute([$id]);
        $question = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($question && $question['subject_id']) {
            $stmt = $pdo->prepare("SELECT id, topic_name FROM topics WHERE subject_id = ?");
            $stmt->execute([$question['subject_id']]);
            $topics = $stmt->fetchAll();
        }
        break;
        
    case 'waec':
        $stmt = $pdo->prepare("
            SELECT wq.*, ws.subject_name, wt.topic_name 
            FROM waec_questions wq
            LEFT JOIN waec_subjects ws ON wq.waec_subject_id = ws.id
            LEFT JOIN waec_topics wt ON wq.waec_topic_id = wt.id
            WHERE wq.id = ?
        ");
        $stmt->execute([$id]);
        $question = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($question && $question['waec_subject_id']) {
            $stmt = $pdo->prepare("SELECT id, topic_name FROM waec_topics WHERE waec_subject_id = ?");
            $stmt->execute([$question['waec_subject_id']]);
            $topics = $stmt->fetchAll();
        }
        break;
        
    case 'jamb':
        $stmt = $pdo->prepare("
            SELECT jq.*, js.subject_name, jt.topic_name 
            FROM jamb_questions jq
            LEFT JOIN jamb_subjects js ON jq.jamb_subject_id = js.id
            LEFT JOIN jamb_topics jt ON jq.jamb_topic_id = jt.id
            WHERE jq.id = ?
        ");
        $stmt->execute([$id]);
        $question = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($question && $question['jamb_subject_id']) {
            $stmt = $pdo->prepare("SELECT id, topic_name FROM jamb_topics WHERE jamb_subject_id = ?");
            $stmt->execute([$question['jamb_subject_id']]);
            $topics = $stmt->fetchAll();
        }
        break;
        
    default:
        header("Location: manage_questions.php");
        exit();
}

if (!$question) {
    header("Location: manage_questions.php?error=notfound");
    exit();
}

// ============================================
// PROCESS EDIT SUBMISSIONS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $redirect_tab = $_POST['tab'] ?? $type;
        $redirect_page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        
        $redirect_params = [];
        if (isset($_POST['filter_subject']) && $_POST['filter_subject']) $redirect_params['filter_subject'] = $_POST['filter_subject'];
        if (isset($_POST['filter_topic']) && $_POST['filter_topic']) $redirect_params['filter_topic'] = $_POST['filter_topic'];
        if (isset($_POST['filter_year']) && $_POST['filter_year']) $redirect_params['filter_year'] = $_POST['filter_year'];
        if (isset($_POST['filter_difficulty']) && $_POST['filter_difficulty']) $redirect_params['filter_difficulty'] = $_POST['filter_difficulty'];
        if (isset($_POST['search']) && $_POST['search']) $redirect_params['search'] = $_POST['search'];
        $redirect_params['page'] = $redirect_page;
        
        $redirect_url = "manage_questions.php?tab=" . urlencode($redirect_tab) . "&" . http_build_query($redirect_params);
        
        switch ($type) {
            case 'objective':
                $question_text = trim($_POST['question_text']);
                $option_a = trim($_POST['option_a']);
                $option_b = trim($_POST['option_b']);
                $option_c = trim($_POST['option_c'] ?? '');
                $option_d = trim($_POST['option_d'] ?? '');
                $correct_answer = strtoupper(trim($_POST['correct_answer']));
                $subject_id = (int)$_POST['subject_id'];
                $topic_id = (int)$_POST['topic_id'];
                $difficulty = $_POST['difficulty'];
                $marks = (int)$_POST['marks'];
                
                $stmt = $pdo->prepare("
                    UPDATE objective_questions 
                    SET question_text = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?,
                        correct_answer = ?, subject_id = ?, topic_id = ?, difficulty_level = ?, marks = ?
                    WHERE id = ? AND (is_central = 1 OR school_id IS NULL)
                ");
                $stmt->execute([$question_text, $option_a, $option_b, $option_c, $option_d, 
                               $correct_answer, $subject_id, $topic_id, $difficulty, $marks, $id]);
                
                $_SESSION['success_message'] = "Objective question updated successfully!";
                break;
                
            case 'subjective':
                $question_text = trim($_POST['question_text']);
                $correct_answer = trim($_POST['correct_answer'] ?? '');
                $subject_id = (int)$_POST['subject_id'];
                $topic_id = (int)$_POST['topic_id'];
                $difficulty = $_POST['difficulty'];
                $marks = (int)$_POST['marks'];
                
                $stmt = $pdo->prepare("
                    UPDATE subjective_questions 
                    SET question_text = ?, correct_answer = ?, subject_id = ?, topic_id = ?, 
                        difficulty_level = ?, marks = ?
                    WHERE id = ? AND (is_central = 1 OR school_id IS NULL)
                ");
                $stmt->execute([$question_text, $correct_answer, $subject_id, $topic_id, $difficulty, $marks, $id]);
                
                $_SESSION['success_message'] = "Subjective question updated successfully!";
                break;
                
            case 'theory':
                $question_text = trim($_POST['question_text']);
                $subject_id = (int)$_POST['subject_id'];
                $topic_id = (int)$_POST['topic_id'];
                $marks = (int)$_POST['marks'];
                
                $stmt = $pdo->prepare("
                    UPDATE theory_questions 
                    SET question_text = ?, subject_id = ?, topic_id = ?, marks = ?
                    WHERE id = ? AND (is_central = 1 OR school_id IS NULL)
                ");
                $stmt->execute([$question_text, $subject_id, $topic_id, $marks, $id]);
                
                // Handle file upload if provided
                if (isset($_FILES['question_file']) && $_FILES['question_file']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = '../uploads/central_questions/';
                    if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                    
                    if ($question['question_file'] && file_exists('../' . $question['question_file'])) {
                        unlink('../' . $question['question_file']);
                    }
                    
                    $ext = strtolower(pathinfo($_FILES['question_file']['name'], PATHINFO_EXTENSION));
                    $filename = 'theory_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                    move_uploaded_file($_FILES['question_file']['tmp_name'], $upload_dir . $filename);
                    $question_file = 'uploads/central_questions/' . $filename;
                    
                    $stmt = $pdo->prepare("UPDATE theory_questions SET question_file = ? WHERE id = ?");
                    $stmt->execute([$question_file, $id]);
                }
                
                $_SESSION['success_message'] = "Theory question updated successfully!";
                break;
                
            case 'waec':
                $subject_id = (int)$_POST['subject_id'];
                $topic_id = (int)$_POST['topic_id'];
                $exam_year = (int)$_POST['exam_year'];
                $question_text = trim($_POST['question_text']);
                $option_a = trim($_POST['option_a']);
                $option_b = trim($_POST['option_b']);
                $option_c = trim($_POST['option_c']);
                $option_d = trim($_POST['option_d'] ?? '');
                $option_e = trim($_POST['option_e'] ?? '');
                $correct_answer = strtoupper(trim($_POST['correct_answer']));
                $explanation = trim($_POST['explanation'] ?? '');
                $difficulty = $_POST['difficulty'] ?? 'medium';
                
                $stmt = $pdo->prepare("
                    UPDATE waec_questions 
                    SET waec_subject_id = ?, waec_topic_id = ?, exam_year = ?, question_text = ?,
                        option_a = ?, option_b = ?, option_c = ?, option_d = ?, option_e = ?,
                        correct_answer = ?, explanation = ?, difficulty_level = ?
                    WHERE id = ?
                ");
                $stmt->execute([$subject_id, $topic_id, $exam_year, $question_text, 
                               $option_a, $option_b, $option_c, $option_d, $option_e,
                               $correct_answer, $explanation, $difficulty, $id]);
                
                // Handle image upload
                if (isset($_FILES['question_image']) && $_FILES['question_image']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = '../uploads/central_questions/';
                    if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                    
                    if ($question['question_image'] && file_exists('../' . $question['question_image'])) {
                        unlink('../' . $question['question_image']);
                    }
                    
                    $ext = strtolower(pathinfo($_FILES['question_image']['name'], PATHINFO_EXTENSION));
                    $filename = 'waec_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                    move_uploaded_file($_FILES['question_image']['tmp_name'], $upload_dir . $filename);
                    $question_image = 'uploads/central_questions/' . $filename;
                    
                    $stmt = $pdo->prepare("UPDATE waec_questions SET question_image = ? WHERE id = ?");
                    $stmt->execute([$question_image, $id]);
                }
                
                $_SESSION['success_message'] = "WAEC question updated successfully!";
                break;
                
            case 'jamb':
                $subject_id = (int)$_POST['subject_id'];
                $topic_id = (int)$_POST['topic_id'];
                $exam_year = (int)$_POST['exam_year'];
                $question_text = trim($_POST['question_text']);
                $option_a = trim($_POST['option_a']);
                $option_b = trim($_POST['option_b']);
                $option_c = trim($_POST['option_c']);
                $option_d = trim($_POST['option_d']);
                $correct_answer = strtoupper(trim($_POST['correct_answer']));
                $explanation = trim($_POST['explanation'] ?? '');
                $difficulty = $_POST['difficulty'] ?? 'medium';
                
                $stmt = $pdo->prepare("
                    UPDATE jamb_questions 
                    SET jamb_subject_id = ?, jamb_topic_id = ?, exam_year = ?, question_text = ?,
                        option_a = ?, option_b = ?, option_c = ?, option_d = ?,
                        correct_answer = ?, explanation = ?, difficulty_level = ?
                    WHERE id = ?
                ");
                $stmt->execute([$subject_id, $topic_id, $exam_year, $question_text, 
                               $option_a, $option_b, $option_c, $option_d,
                               $correct_answer, $explanation, $difficulty, $id]);
                
                // Handle image upload
                if (isset($_FILES['question_image']) && $_FILES['question_image']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = '../uploads/central_questions/';
                    if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                    
                    if ($question['question_image'] && file_exists('../' . $question['question_image'])) {
                        unlink('../' . $question['question_image']);
                    }
                    
                    $ext = strtolower(pathinfo($_FILES['question_image']['name'], PATHINFO_EXTENSION));
                    $filename = 'jamb_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                    move_uploaded_file($_FILES['question_image']['tmp_name'], $upload_dir . $filename);
                    $question_image = 'uploads/central_questions/' . $filename;
                    
                    $stmt = $pdo->prepare("UPDATE jamb_questions SET question_image = ? WHERE id = ?");
                    $stmt->execute([$question_image, $id]);
                }
                
                $_SESSION['success_message'] = "JAMB question updated successfully!";
                break;
        }
        
        header("Location: " . $redirect_url);
        exit();
        
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

include 'includes/header.php';
?>

<div class="mq-page-header">
    <div class="mq-page-header__text">
        <h2 class="mq-page-header__title">Edit <?php echo ucfirst($type); ?> Question</h2>
        <p class="mq-page-header__sub">Question #<?php echo $id; ?></p>
    </div>
    <a href="manage_questions.php?tab=<?php echo $type; ?>" class="mq-btn mq-btn--ghost">
        <i class="fas fa-arrow-left"></i> Back to Manage
    </a>
</div>

<?php if ($message): ?>
<div class="mq-alert mq-alert--<?php echo $message_type; ?>">
    <?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<!-- Edit Form -->
<div class="mq-edit-form">
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="type" value="<?php echo $type; ?>">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        
        <?php if ($type === 'objective'): ?>
            <!-- Objective Form -->
            <div class="form-group">
                <label>Question Text *</label>
                <textarea name="question_text" class="form-control" rows="4" required><?php echo htmlspecialchars($question['question_text'] ?? ''); ?></textarea>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
                <div class="form-group">
                    <label>Option A *</label>
                    <input type="text" name="option_a" class="form-control" value="<?php echo htmlspecialchars($question['option_a'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label>Option B *</label>
                    <input type="text" name="option_b" class="form-control" value="<?php echo htmlspecialchars($question['option_b'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label>Option C</label>
                    <input type="text" name="option_c" class="form-control" value="<?php echo htmlspecialchars($question['option_c'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Option D</label>
                    <input type="text" name="option_d" class="form-control" value="<?php echo htmlspecialchars($question['option_d'] ?? ''); ?>">
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px;">
                <div class="form-group">
                    <label>Correct Answer *</label>
                    <select name="correct_answer" class="form-control" required>
                        <option value="A" <?php echo ($question['correct_answer'] ?? '') === 'A' ? 'selected' : ''; ?>>A</option>
                        <option value="B" <?php echo ($question['correct_answer'] ?? '') === 'B' ? 'selected' : ''; ?>>B</option>
                        <option value="C" <?php echo ($question['correct_answer'] ?? '') === 'C' ? 'selected' : ''; ?>>C</option>
                        <option value="D" <?php echo ($question['correct_answer'] ?? '') === 'D' ? 'selected' : ''; ?>>D</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Subject *</label>
                    <select name="subject_id" class="form-control" required onchange="loadTopics(this.value)">
                        <option value="">-- Select Subject --</option>
                        <?php foreach ($subjects as $s): ?>
                            <option value="<?php echo $s['id']; ?>" <?php echo ($question['subject_id'] ?? 0) == $s['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Topic</label>
                    <select name="topic_id" id="topic_select" class="form-control">
                        <option value="0">None</option>
                        <?php foreach ($topics as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo ($question['topic_id'] ?? 0) == $t['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['topic_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Difficulty</label>
                    <select name="difficulty" class="form-control">
                        <option value="easy" <?php echo ($question['difficulty_level'] ?? '') === 'easy' ? 'selected' : ''; ?>>Easy</option>
                        <option value="medium" <?php echo ($question['difficulty_level'] ?? '') === 'medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="hard" <?php echo ($question['difficulty_level'] ?? '') === 'hard' ? 'selected' : ''; ?>>Hard</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label>Marks</label>
                <input type="number" name="marks" class="form-control" value="<?php echo $question['marks'] ?? 1; ?>" min="1">
            </div>
            
        <?php elseif ($type === 'subjective'): ?>
            <!-- Subjective Form -->
            <div class="form-group">
                <label>Question Text *</label>
                <textarea name="question_text" class="form-control" rows="4" required><?php echo htmlspecialchars($question['question_text'] ?? ''); ?></textarea>
            </div>
            
            <div class="form-group">
                <label>Model Answer / Marking Guide</label>
                <textarea name="correct_answer" class="form-control" rows="3"><?php echo htmlspecialchars($question['correct_answer'] ?? ''); ?></textarea>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px;">
                <div class="form-group">
                    <label>Subject *</label>
                    <select name="subject_id" class="form-control" required onchange="loadTopics(this.value)">
                        <option value="">-- Select Subject --</option>
                        <?php foreach ($subjects as $s): ?>
                            <option value="<?php echo $s['id']; ?>" <?php echo ($question['subject_id'] ?? 0) == $s['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Topic</label>
                    <select name="topic_id" id="topic_select" class="form-control">
                        <option value="0">None</option>
                        <?php foreach ($topics as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo ($question['topic_id'] ?? 0) == $t['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['topic_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Difficulty</label>
                    <select name="difficulty" class="form-control">
                        <option value="easy" <?php echo ($question['difficulty_level'] ?? '') === 'easy' ? 'selected' : ''; ?>>Easy</option>
                        <option value="medium" <?php echo ($question['difficulty_level'] ?? '') === 'medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="hard" <?php echo ($question['difficulty_level'] ?? '') === 'hard' ? 'selected' : ''; ?>>Hard</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Marks</label>
                    <input type="number" name="marks" class="form-control" value="<?php echo $question['marks'] ?? 5; ?>" min="1">
                </div>
            </div>
            
        <?php elseif ($type === 'theory'): ?>
            <!-- Theory Form -->
            <div class="form-group">
                <label>Question Text</label>
                <textarea name="question_text" class="form-control" rows="4"><?php echo htmlspecialchars($question['question_text'] ?? ''); ?></textarea>
            </div>
            
            <?php if ($question['question_file']): ?>
                <div class="form-group">
                    <label>Current File</label>
                    <div>
                        <a href="../<?php echo $question['question_file']; ?>" target="_blank" class="btn btn-sm btn-info">View Current File</a>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="form-group">
                <label>Replace with New File (PDF, DOC, Image)</label>
                <input type="file" name="question_file" class="form-control" accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png">
                <small>Leave empty to keep current file</small>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                <div class="form-group">
                    <label>Subject *</label>
                    <select name="subject_id" class="form-control" required onchange="loadTopics(this.value)">
                        <option value="">-- Select Subject --</option>
                        <?php foreach ($subjects as $s): ?>
                            <option value="<?php echo $s['id']; ?>" <?php echo ($question['subject_id'] ?? 0) == $s['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Topic</label>
                    <select name="topic_id" id="topic_select" class="form-control">
                        <option value="0">None</option>
                        <?php foreach ($topics as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo ($question['topic_id'] ?? 0) == $t['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['topic_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Marks</label>
                    <input type="number" name="marks" class="form-control" value="<?php echo $question['marks'] ?? 10; ?>" min="1">
                </div>
            </div>
            
        <?php elseif ($type === 'waec'): ?>
            <!-- WAEC Form -->
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                <div class="form-group">
                    <label>Subject *</label>
                    <select name="subject_id" class="form-control" required onchange="loadWAECTopics(this.value)">
                        <option value="">-- Select Subject --</option>
                        <?php foreach ($waec_subjects as $s): ?>
                            <option value="<?php echo $s['id']; ?>" <?php echo ($question['waec_subject_id'] ?? 0) == $s['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Topic</label>
                    <select name="topic_id" id="topic_select" class="form-control">
                        <option value="0">None</option>
                        <?php foreach ($topics as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo ($question['waec_topic_id'] ?? 0) == $t['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['topic_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Exam Year *</label>
                    <select name="exam_year" class="form-control" required>
                        <option value="">-- Select Year --</option>
                        <?php for ($y = date('Y'); $y >= 1990; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo ($question['exam_year'] ?? 0) == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label>Question Text *</label>
                <textarea name="question_text" class="form-control" rows="4" required><?php echo htmlspecialchars($question['question_text'] ?? ''); ?></textarea>
            </div>
            
            <?php if ($question['question_image']): ?>
                <div class="form-group">
                    <label>Current Image</label>
                    <div>
                        <img src="../<?php echo $question['question_image']; ?>" style="max-width: 200px; max-height: 150px;" alt="Current image">
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="form-group">
                <label>Replace Image</label>
                <input type="file" name="question_image" class="form-control" accept="image/*">
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                <div class="form-group">
                    <label>Option A *</label>
                    <input type="text" name="option_a" class="form-control" value="<?php echo htmlspecialchars($question['option_a'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label>Option B *</label>
                    <input type="text" name="option_b" class="form-control" value="<?php echo htmlspecialchars($question['option_b'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label>Option C *</label>
                    <input type="text" name="option_c" class="form-control" value="<?php echo htmlspecialchars($question['option_c'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label>Option D</label>
                    <input type="text" name="option_d" class="form-control" value="<?php echo htmlspecialchars($question['option_d'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Option E</label>
                    <input type="text" name="option_e" class="form-control" value="<?php echo htmlspecialchars($question['option_e'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Correct Answer *</label>
                    <select name="correct_answer" class="form-control" required>
                        <option value="A" <?php echo ($question['correct_answer'] ?? '') === 'A' ? 'selected' : ''; ?>>A</option>
                        <option value="B" <?php echo ($question['correct_answer'] ?? '') === 'B' ? 'selected' : ''; ?>>B</option>
                        <option value="C" <?php echo ($question['correct_answer'] ?? '') === 'C' ? 'selected' : ''; ?>>C</option>
                        <option value="D" <?php echo ($question['correct_answer'] ?? '') === 'D' ? 'selected' : ''; ?>>D</option>
                        <option value="E" <?php echo ($question['correct_answer'] ?? '') === 'E' ? 'selected' : ''; ?>>E</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label>Explanation / Solution</label>
                <textarea name="explanation" class="form-control" rows="3"><?php echo htmlspecialchars($question['explanation'] ?? ''); ?></textarea>
            </div>
            
            <div class="form-group">
                <label>Difficulty</label>
                <select name="difficulty" class="form-control">
                    <option value="easy" <?php echo ($question['difficulty_level'] ?? '') === 'easy' ? 'selected' : ''; ?>>Easy</option>
                    <option value="medium" <?php echo ($question['difficulty_level'] ?? '') === 'medium' ? 'selected' : ''; ?>>Medium</option>
                    <option value="hard" <?php echo ($question['difficulty_level'] ?? '') === 'hard' ? 'selected' : ''; ?>>Hard</option>
                </select>
            </div>
            
        <?php elseif ($type === 'jamb'): ?>
            <!-- JAMB Form -->
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                <div class="form-group">
                    <label>Subject *</label>
                    <select name="subject_id" class="form-control" required onchange="loadJAMBTopics(this.value)">
                        <option value="">-- Select Subject --</option>
                        <?php foreach ($jamb_subjects as $s): ?>
                            <option value="<?php echo $s['id']; ?>" <?php echo ($question['jamb_subject_id'] ?? 0) == $s['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Topic</label>
                    <select name="topic_id" id="topic_select" class="form-control">
                        <option value="0">None</option>
                        <?php foreach ($topics as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo ($question['jamb_topic_id'] ?? 0) == $t['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['topic_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Exam Year *</label>
                    <select name="exam_year" class="form-control" required>
                        <option value="">-- Select Year --</option>
                        <?php for ($y = date('Y'); $y >= 1990; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo ($question['exam_year'] ?? 0) == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label>Question Text *</label>
                <textarea name="question_text" class="form-control" rows="4" required><?php echo htmlspecialchars($question['question_text'] ?? ''); ?></textarea>
            </div>
            
            <?php if ($question['question_image']): ?>
                <div class="form-group">
                    <label>Current Image</label>
                    <div>
                        <img src="../<?php echo $question['question_image']; ?>" style="max-width: 200px; max-height: 150px;" alt="Current image">
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="form-group">
                <label>Replace Image</label>
                <input type="file" name="question_image" class="form-control" accept="image/*">
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
                <div class="form-group">
                    <label>Option A *</label>
                    <input type="text" name="option_a" class="form-control" value="<?php echo htmlspecialchars($question['option_a'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label>Option B *</label>
                    <input type="text" name="option_b" class="form-control" value="<?php echo htmlspecialchars($question['option_b'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label>Option C *</label>
                    <input type="text" name="option_c" class="form-control" value="<?php echo htmlspecialchars($question['option_c'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label>Option D *</label>
                    <input type="text" name="option_d" class="form-control" value="<?php echo htmlspecialchars($question['option_d'] ?? ''); ?>" required>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                <div class="form-group">
                    <label>Correct Answer *</label>
                    <select name="correct_answer" class="form-control" required>
                        <option value="A" <?php echo ($question['correct_answer'] ?? '') === 'A' ? 'selected' : ''; ?>>A</option>
                        <option value="B" <?php echo ($question['correct_answer'] ?? '') === 'B' ? 'selected' : ''; ?>>B</option>
                        <option value="C" <?php echo ($question['correct_answer'] ?? '') === 'C' ? 'selected' : ''; ?>>C</option>
                        <option value="D" <?php echo ($question['correct_answer'] ?? '') === 'D' ? 'selected' : ''; ?>>D</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Difficulty</label>
                    <select name="difficulty" class="form-control">
                        <option value="easy" <?php echo ($question['difficulty_level'] ?? '') === 'easy' ? 'selected' : ''; ?>>Easy</option>
                        <option value="medium" <?php echo ($question['difficulty_level'] ?? '') === 'medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="hard" <?php echo ($question['difficulty_level'] ?? '') === 'hard' ? 'selected' : ''; ?>>Hard</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label>Explanation / Solution</label>
                <textarea name="explanation" class="form-control" rows="3"><?php echo htmlspecialchars($question['explanation'] ?? ''); ?></textarea>
            </div>
        <?php endif; ?>
        
        <!-- Hidden fields to preserve filter state when redirecting back -->
        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($_GET['tab'] ?? $type); ?>">
        <input type="hidden" name="page" value="<?php echo htmlspecialchars($_GET['page'] ?? 1); ?>">
        <input type="hidden" name="filter_subject" value="<?php echo htmlspecialchars($_GET['filter_subject'] ?? ''); ?>">
        <input type="hidden" name="filter_topic" value="<?php echo htmlspecialchars($_GET['filter_topic'] ?? ''); ?>">
        <input type="hidden" name="filter_year" value="<?php echo htmlspecialchars($_GET['filter_year'] ?? ''); ?>">
        <input type="hidden" name="filter_difficulty" value="<?php echo htmlspecialchars($_GET['filter_difficulty'] ?? ''); ?>">
        <input type="hidden" name="search" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
        
        <div style="display: flex; gap: 15px; margin-top: 20px;">
            <button type="submit" name="edit_<?php echo $type; ?>" class="mq-btn mq-btn--primary">Save Changes</button>
            <a href="manage_questions.php?tab=<?php echo $type; ?>" class="mq-btn mq-btn--ghost">Cancel</a>
        </div>
    </form>
</div>

<script>
function loadTopics(subjectId) {
    if (!subjectId) return;
    fetch(`api/get_topics.php?subject_id=${subjectId}`)
        .then(response => response.json())
        .then(data => {
            const select = document.getElementById('topic_select');
            if (select && data.success && data.topics) {
                select.innerHTML = '<option value="0">None</option>';
                data.topics.forEach(topic => {
                    select.innerHTML += `<option value="${topic.id}">${escapeHtml(topic.topic_name)}</option>`;
                });
            }
        })
        .catch(error => console.error('Error loading topics:', error));
}

function loadWAECTopics(subjectId) {
    if (!subjectId) return;
    fetch(`api/get_waec_topics.php?subject_id=${subjectId}`)
        .then(response => response.json())
        .then(data => {
            const select = document.getElementById('topic_select');
            if (select && data.success && data.topics) {
                select.innerHTML = '<option value="0">None</option>';
                data.topics.forEach(topic => {
                    select.innerHTML += `<option value="${topic.id}">${escapeHtml(topic.topic_name)}</option>`;
                });
            }
        })
        .catch(error => console.error('Error loading topics:', error));
}

function loadJAMBTopics(subjectId) {
    if (!subjectId) return;
    fetch(`api/get_jamb_topics.php?subject_id=${subjectId}`)
        .then(response => response.json())
        .then(data => {
            const select = document.getElementById('topic_select');
            if (select && data.success && data.topics) {
                select.innerHTML = '<option value="0">None</option>';
                data.topics.forEach(topic => {
                    select.innerHTML += `<option value="${topic.id}">${escapeHtml(topic.topic_name)}</option>`;
                });
            }
        })
        .catch(error => console.error('Error loading topics:', error));
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<style>
.mq-edit-form {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}
.form-group {
    margin-bottom: 20px;
}
.form-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    font-size: 0.85rem;
    color: #1e293b;
}
.form-control {
    width: 100%;
    padding: 10px 12px;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    font-family: inherit;
    font-size: 0.85rem;
}
.form-control:focus {
    outline: none;
    border-color: #1c3877;
}
textarea.form-control {
    resize: vertical;
}
.mq-alert {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 20px;
}
.mq-alert--success {
    background: #dcfce7;
    color: #166534;
    border-left: 4px solid #16a34a;
}
.mq-alert--error {
    background: #fee2e2;
    color: #991b1b;
    border-left: 4px solid #e23639;
}
.btn-sm {
    padding: 6px 12px;
    font-size: 0.8rem;
}
.btn-info {
    background: #e0f2fe;
    color: #0369a1;
    text-decoration: none;
    border-radius: 6px;
}
</style>

<?php include 'includes/footer.php'; ?>
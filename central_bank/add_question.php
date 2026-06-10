<?php
// /central_bank/add_question.php - Add questions to central bank
// Supports: Objective, Subjective, Theory, WAEC, JAMB

require_once 'includes/config.php';
require_once 'includes/auth.php';
require_super_admin();

$page_title = 'Add Question';
$page_subtitle = 'Add questions to the central bank (Objective, Subjective, Theory, WAEC, JAMB)';

$message = '';
$message_type = '';
$active_type = $_GET['type'] ?? 'objective';

// Get data for dropdowns
$subjects = $pdo->query("SELECT id, subject_name FROM subjects WHERE is_central = 1 OR school_id IS NULL ORDER BY subject_name")->fetchAll();
$waec_subjects = $pdo->query("SELECT id, subject_name, subject_code FROM waec_subjects WHERE is_active = 1 ORDER BY subject_name")->fetchAll();
$jamb_subjects = $pdo->query("SELECT id, subject_name, subject_code FROM jamb_subjects WHERE is_active = 1 ORDER BY subject_name")->fetchAll();

// Create uploads directory if not exists
$upload_dir = '../uploads/central_questions/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// ============================================
// HANDLE ADD OBJECTIVE QUESTION
// ============================================
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
        
        // Validate required fields
        if (empty($question_text)) throw new Exception("Question text is required");
        if (empty($option_a) || empty($option_b)) throw new Exception("Options A and B are required");
        if (empty($subject_id)) throw new Exception("Please select a subject");
        
        // Handle image upload
        $question_image = null;
        if (isset($_FILES['question_image']) && $_FILES['question_image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['question_image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (in_array($ext, $allowed)) {
                $filename = 'obj_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                move_uploaded_file($_FILES['question_image']['tmp_name'], $upload_dir . $filename);
                $question_image = 'uploads/central_questions/' . $filename;
            } else {
                throw new Exception("Invalid image format. Allowed: jpg, jpeg, png, gif, webp");
            }
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO objective_questions 
            (question_text, option_a, option_b, option_c, option_d, correct_answer, 
             subject_id, topic_id, difficulty_level, marks, question_image, is_central, school_id, source_type, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NULL, 'central', NOW())
        ");
        $stmt->execute([$question_text, $option_a, $option_b, $option_c, $option_d, $correct_answer, 
                       $subject_id, $topic_id, $difficulty, $marks, $question_image]);
        
        $message = "Objective question added to central bank successfully!";
        $message_type = "success";
        
        // Clear form if not staying
        if (!isset($_POST['stay_here'])) {
            header("Location: manage_questions.php?tab=objective&message=" . urlencode($message));
            exit();
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// ============================================
// HANDLE ADD SUBJECTIVE QUESTION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_subjective'])) {
    try {
        $question_text = trim($_POST['question_text']);
        $correct_answer = trim($_POST['correct_answer']);
        $subject_id = (int)$_POST['subject_id'];
        $topic_id = (int)$_POST['topic_id'];
        $difficulty = $_POST['difficulty'];
        $marks = (int)$_POST['marks'];
        
        if (empty($question_text)) throw new Exception("Question text is required");
        if (empty($subject_id)) throw new Exception("Please select a subject");
        
        $stmt = $pdo->prepare("
            INSERT INTO subjective_questions 
            (question_text, correct_answer, subject_id, topic_id, difficulty_level, marks, is_central, school_id, source_type, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, 1, NULL, 'central', NOW())
        ");
        $stmt->execute([$question_text, $correct_answer, $subject_id, $topic_id, $difficulty, $marks]);
        
        $message = "Subjective question added to central bank successfully!";
        $message_type = "success";
        
        if (!isset($_POST['stay_here'])) {
            header("Location: manage_questions.php?tab=subjective&message=" . urlencode($message));
            exit();
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// ============================================
// HANDLE ADD THEORY QUESTION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_theory'])) {
    try {
        $question_text = trim($_POST['question_text']);
        $subject_id = (int)$_POST['subject_id'];
        $topic_id = (int)$_POST['topic_id'];
        $marks = (int)$_POST['marks'];
        
        if (empty($question_text) && empty($_FILES['question_file']['name'])) {
            throw new Exception("Either question text or a file attachment is required");
        }
        if (empty($subject_id)) throw new Exception("Please select a subject");
        
        $question_file = null;
        if (isset($_FILES['question_file']) && $_FILES['question_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['question_file']['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png'];
            if (in_array($ext, $allowed)) {
                $filename = 'theory_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                move_uploaded_file($_FILES['question_file']['tmp_name'], $upload_dir . $filename);
                $question_file = 'uploads/central_questions/' . $filename;
            } else {
                throw new Exception("Invalid file format. Allowed: pdf, doc, docx, txt, jpg, jpeg, png");
            }
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO theory_questions 
            (question_text, question_file, subject_id, topic_id, marks, is_central, school_id, source_type, created_at) 
            VALUES (?, ?, ?, ?, ?, 1, NULL, 'central', NOW())
        ");
        $stmt->execute([$question_text, $question_file, $subject_id, $topic_id, $marks]);
        
        $message = "Theory question added to central bank successfully!";
        $message_type = "success";
        
        if (!isset($_POST['stay_here'])) {
            header("Location: manage_questions.php?tab=theory&message=" . urlencode($message));
            exit();
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// ============================================
// HANDLE ADD WAEC QUESTION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_waec'])) {
    try {
        $subject_id = (int)$_POST['waec_subject_id'];
        $topic_id = (int)$_POST['waec_topic_id'];
        $exam_year = (int)$_POST['exam_year'];
        $question_text = trim($_POST['question_text']);
        $option_a = trim($_POST['option_a']);
        $option_b = trim($_POST['option_b']);
        $option_c = trim($_POST['option_c']);
        $option_d = trim($_POST['option_d']);
        $option_e = trim($_POST['option_e']) ?: null;
        $correct_answer = strtoupper(trim($_POST['correct_answer']));
        $explanation = trim($_POST['explanation']);
        $difficulty = $_POST['difficulty'];
        
        // Validate required fields
        if (empty($subject_id)) throw new Exception("Please select a subject");
        if (empty($question_text)) throw new Exception("Question text is required");
        if (empty($option_a) || empty($option_b) || empty($option_c)) {
            throw new Exception("Options A, B, and C are required");
        }
        if (empty($correct_answer)) throw new Exception("Please select the correct answer");
        
        // Handle image upload
        $question_image = null;
        if (isset($_FILES['question_image']) && $_FILES['question_image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['question_image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (in_array($ext, $allowed)) {
                $filename = 'waec_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                move_uploaded_file($_FILES['question_image']['tmp_name'], $upload_dir . $filename);
                $question_image = 'uploads/central_questions/' . $filename;
            }
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO waec_questions 
            (waec_subject_id, waec_topic_id, exam_year, question_text, question_image, 
             option_a, option_b, option_c, option_d, option_e, correct_answer, 
             explanation, difficulty_level, is_active, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([$subject_id, $topic_id, $exam_year, $question_text, $question_image,
                       $option_a, $option_b, $option_c, $option_d, $option_e, $correct_answer,
                       $explanation, $difficulty]);
        
        $message = "WAEC question added successfully!";
        $message_type = "success";
        
        if (!isset($_POST['stay_here'])) {
            header("Location: manage_questions.php?tab=waec&message=" . urlencode($message));
            exit();
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// ============================================
// HANDLE ADD JAMB QUESTION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_jamb'])) {
    try {
        $subject_id = (int)$_POST['jamb_subject_id'];
        $topic_id = (int)$_POST['jamb_topic_id'];
        $exam_year = (int)$_POST['jamb_exam_year'];
        $question_text = trim($_POST['jamb_question_text']);
        $option_a = trim($_POST['jamb_option_a']);
        $option_b = trim($_POST['jamb_option_b']);
        $option_c = trim($_POST['jamb_option_c']);
        $option_d = trim($_POST['jamb_option_d']);
        $correct_answer = strtoupper(trim($_POST['jamb_correct_answer']));
        $explanation = trim($_POST['jamb_explanation']);
        $difficulty = $_POST['jamb_difficulty'];
        
        // Validate required fields
        if (empty($subject_id)) throw new Exception("Please select a subject");
        if (empty($question_text)) throw new Exception("Question text is required");
        if (empty($option_a) || empty($option_b) || empty($option_c) || empty($option_d)) {
            throw new Exception("All options A, B, C, and D are required");
        }
        if (empty($correct_answer)) throw new Exception("Please select the correct answer");
        
        // Handle image upload
        $question_image = null;
        if (isset($_FILES['jamb_question_image']) && $_FILES['jamb_question_image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['jamb_question_image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (in_array($ext, $allowed)) {
                $filename = 'jamb_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                move_uploaded_file($_FILES['jamb_question_image']['tmp_name'], $upload_dir . $filename);
                $question_image = 'uploads/central_questions/' . $filename;
            }
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO jamb_questions 
            (jamb_subject_id, jamb_topic_id, exam_year, question_text, question_image, 
             option_a, option_b, option_c, option_d, correct_answer, 
             explanation, difficulty_level, is_active, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([$subject_id, $topic_id, $exam_year, $question_text, $question_image,
                       $option_a, $option_b, $option_c, $option_d, $correct_answer,
                       $explanation, $difficulty]);
        
        $message = "JAMB question added successfully!";
        $message_type = "success";
        
        if (!isset($_POST['stay_here'])) {
            header("Location: manage_questions.php?tab=jamb&message=" . urlencode($message));
            exit();
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

include 'includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<!-- Header with Back Button -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
    <h2 style="margin: 0;">Add New Question to Central Bank</h2>
    <a href="manage_questions.php?tab=<?php echo $active_type; ?>" class="btn btn-secondary" style="background: #6c757d; color: white; padding: 8px 16px; border-radius: 8px; text-decoration: none;">
        <i class="fas fa-arrow-left"></i> Back to Manage Questions
    </a>
</div>

<!-- Question Type Tabs -->
<div class="tabs-navigation" style="background: white; border-radius: 12px; margin-bottom: 20px; overflow-x: auto;">
    <div style="display: flex; border-bottom: 2px solid #eee; min-width: max-content;">
        <a href="?type=objective" class="tab-link" style="padding: 12px 20px; text-decoration: none; color: <?php echo $active_type === 'objective' ? '#3498db' : '#666'; ?>; border-bottom: 2px solid <?php echo $active_type === 'objective' ? '#3498db' : 'transparent'; ?>; margin-bottom: -2px;"><i class="fas fa-check-circle"></i> Objective</a>
        <a href="?type=subjective" class="tab-link" style="padding: 12px 20px; text-decoration: none; color: <?php echo $active_type === 'subjective' ? '#3498db' : '#666'; ?>; border-bottom: 2px solid <?php echo $active_type === 'subjective' ? '#3498db' : 'transparent'; ?>; margin-bottom: -2px;"><i class="fas fa-edit"></i> Subjective</a>
        <a href="?type=theory" class="tab-link" style="padding: 12px 20px; text-decoration: none; color: <?php echo $active_type === 'theory' ? '#3498db' : '#666'; ?>; border-bottom: 2px solid <?php echo $active_type === 'theory' ? '#3498db' : 'transparent'; ?>; margin-bottom: -2px;"><i class="fas fa-file-alt"></i> Theory</a>
        <a href="?type=waec" class="tab-link" style="padding: 12px 20px; text-decoration: none; color: <?php echo $active_type === 'waec' ? '#3498db' : '#666'; ?>; border-bottom: 2px solid <?php echo $active_type === 'waec' ? '#3498db' : 'transparent'; ?>; margin-bottom: -2px;"><i class="fas fa-graduation-cap"></i> WAEC</a>
        <a href="?type=jamb" class="tab-link" style="padding: 12px 20px; text-decoration: none; color: <?php echo $active_type === 'jamb' ? '#3498db' : '#666'; ?>; border-bottom: 2px solid <?php echo $active_type === 'jamb' ? '#3498db' : 'transparent'; ?>; margin-bottom: -2px;"><i class="fas fa-university"></i> JAMB</a>
    </div>
</div>

<!-- ============================================ -->
<!-- OBJECTIVE FORM -->
<!-- ============================================ -->
<?php if ($active_type === 'objective'): ?>
<div class="card">
    <div class="card-header"><i class="fas fa-plus-circle"></i> Add Objective Question</div>
    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label>Question Text *</label>
            <textarea name="question_text" class="form-control" rows="4" required></textarea>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
            <div class="form-group">
                <label>Option A *</label>
                <input type="text" name="option_a" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Option B *</label>
                <input type="text" name="option_b" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Option C</label>
                <input type="text" name="option_c" class="form-control">
            </div>
            <div class="form-group">
                <label>Option D</label>
                <input type="text" name="option_d" class="form-control">
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px;">
            <div class="form-group">
                <label>Correct Answer *</label>
                <select name="correct_answer" class="form-control" required>
                    <option value="A">A</option>
                    <option value="B">B</option>
                    <option value="C">C</option>
                    <option value="D">D</option>
                </select>
            </div>
            <div class="form-group">
                <label>Subject *</label>
                <select name="subject_id" id="obj_subject" class="form-control" required onchange="loadTopics(this.value, 'obj_topic')">
                    <option value="">-- Select Subject --</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['subject_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Topic</label>
                <select name="topic_id" id="obj_topic" class="form-control">
                    <option value="0">None</option>
                </select>
            </div>
            <div class="form-group">
                <label>Difficulty</label>
                <select name="difficulty" class="form-control">
                    <option value="easy">Easy</option>
                    <option value="medium" selected>Medium</option>
                    <option value="hard">Hard</option>
                </select>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
            <div class="form-group">
                <label>Marks</label>
                <input type="number" name="marks" class="form-control" value="1" min="1">
            </div>
            <div class="form-group">
                <label>Question Image</label>
                <input type="file" name="question_image" class="form-control" accept="image/*">
                <small>Optional: Upload an image for the question</small>
            </div>
        </div>
        
        <div class="checkbox-group" style="margin: 15px 0;">
            <label><input type="checkbox" name="stay_here" value="1"> Stay here after adding (add another)</label>
        </div>
        
        <button type="submit" name="add_objective" class="btn btn-success"><i class="fas fa-save"></i> Add to Central Bank</button>
    </form>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- SUBJECTIVE FORM -->
<!-- ============================================ -->
<?php if ($active_type === 'subjective'): ?>
<div class="card">
    <div class="card-header"><i class="fas fa-plus-circle"></i> Add Subjective Question</div>
    <form method="POST">
        <div class="form-group">
            <label>Question Text *</label>
            <textarea name="question_text" class="form-control" rows="4" required></textarea>
        </div>
        <div class="form-group">
            <label>Model Answer / Marking Guide</label>
            <textarea name="correct_answer" class="form-control" rows="3" placeholder="Enter the expected answer or marking guide..."></textarea>
        </div>
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px;">
            <div class="form-group">
                <label>Subject *</label>
                <select name="subject_id" id="subj_subject" class="form-control" required onchange="loadTopics(this.value, 'subj_topic')">
                    <option value="">-- Select Subject --</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['subject_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Topic</label>
                <select name="topic_id" id="subj_topic" class="form-control">
                    <option value="0">None</option>
                </select>
            </div>
            <div class="form-group">
                <label>Difficulty</label>
                <select name="difficulty" class="form-control">
                    <option value="easy">Easy</option>
                    <option value="medium" selected>Medium</option>
                    <option value="hard">Hard</option>
                </select>
            </div>
            <div class="form-group">
                <label>Marks</label>
                <input type="number" name="marks" class="form-control" value="5" min="1">
            </div>
        </div>
        <div class="checkbox-group" style="margin: 15px 0;">
            <label><input type="checkbox" name="stay_here" value="1"> Stay here after adding (add another)</label>
        </div>
        <button type="submit" name="add_subjective" class="btn btn-success"><i class="fas fa-save"></i> Add to Central Bank</button>
    </form>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- THEORY FORM -->
<!-- ============================================ -->
<?php if ($active_type === 'theory'): ?>
<div class="card">
    <div class="card-header"><i class="fas fa-plus-circle"></i> Add Theory Question</div>
    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label>Question Text</label>
            <textarea name="question_text" class="form-control" rows="4" placeholder="Enter the question text (or upload a file below)"></textarea>
        </div>
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
            <div class="form-group">
                <label>Subject *</label>
                <select name="subject_id" id="theory_subject" class="form-control" required onchange="loadTopics(this.value, 'theory_topic')">
                    <option value="">-- Select Subject --</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['subject_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Topic</label>
                <select name="topic_id" id="theory_topic" class="form-control">
                    <option value="0">None</option>
                </select>
            </div>
            <div class="form-group">
                <label>Marks</label>
                <input type="number" name="marks" class="form-control" value="10" min="1">
            </div>
        </div>
        <div class="form-group">
            <label>Attach File (PDF, DOC, Image)</label>
            <input type="file" name="question_file" class="form-control" accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png">
            <small>Optional: Upload a file with the question content</small>
        </div>
        <div class="checkbox-group" style="margin: 15px 0;">
            <label><input type="checkbox" name="stay_here" value="1"> Stay here after adding (add another)</label>
        </div>
        <button type="submit" name="add_theory" class="btn btn-success"><i class="fas fa-save"></i> Add to Central Bank</button>
    </form>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- WAEC FORM -->
<!-- ============================================ -->
<?php if ($active_type === 'waec'): ?>
<div class="card">
    <div class="card-header"><i class="fas fa-plus-circle"></i> Add WAEC Question</div>
    <form method="POST" enctype="multipart/form-data">
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
            <div class="form-group">
                <label>Subject *</label>
                <select name="waec_subject_id" id="waec_subject" class="form-control" required onchange="loadWAECTopics()">
                    <option value="">-- Select Subject --</option>
                    <?php foreach ($waec_subjects as $s): ?>
                        <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['subject_name']); ?> (<?php echo $s['subject_code']; ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Topic</label>
                <select name="waec_topic_id" id="waec_topic" class="form-control">
                    <option value="0">None</option>
                </select>
            </div>
            <div class="form-group">
                <label>Exam Year *</label>
                <select name="exam_year" class="form-control" required>
                    <option value="">-- Select Year --</option>
                    <?php for ($y = date('Y'); $y >= 1990; $y--): ?>
                        <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>
        
        <div class="form-group">
            <label>Question Text *</label>
            <textarea name="question_text" class="form-control" rows="4" required></textarea>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
            <div class="form-group">
                <label>Option A *</label>
                <input type="text" name="option_a" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Option B *</label>
                <input type="text" name="option_b" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Option C *</label>
                <input type="text" name="option_c" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Option D</label>
                <input type="text" name="option_d" class="form-control">
            </div>
            <div class="form-group">
                <label>Option E (if any)</label>
                <input type="text" name="option_e" class="form-control">
            </div>
            <div class="form-group">
                <label>Correct Answer *</label>
                <select name="correct_answer" class="form-control" required>
                    <option value="A">A</option>
                    <option value="B">B</option>
                    <option value="C">C</option>
                    <option value="D">D</option>
                    <option value="E">E</option>
                </select>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
            <div class="form-group">
                <label>Difficulty</label>
                <select name="difficulty" class="form-control">
                    <option value="easy">Easy</option>
                    <option value="medium" selected>Medium</option>
                    <option value="hard">Hard</option>
                </select>
            </div>
            <div class="form-group">
                <label>Question Image</label>
                <input type="file" name="question_image" class="form-control" accept="image/*">
            </div>
        </div>
        
        <div class="form-group">
            <label>Explanation / Solution</label>
            <textarea name="explanation" class="form-control" rows="3" placeholder="Provide explanation for the correct answer..."></textarea>
        </div>
        
        <div class="checkbox-group" style="margin: 15px 0;">
            <label><input type="checkbox" name="stay_here" value="1"> Stay here after adding (add another)</label>
        </div>
        
        <button type="submit" name="add_waec" class="btn btn-success"><i class="fas fa-save"></i> Add WAEC Question</button>
    </form>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- JAMB FORM -->
<!-- ============================================ -->
<?php if ($active_type === 'jamb'): ?>
<div class="card">
    <div class="card-header"><i class="fas fa-plus-circle"></i> Add JAMB Question</div>
    <form method="POST" enctype="multipart/form-data">
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
            <div class="form-group">
                <label>Subject *</label>
                <select name="jamb_subject_id" id="jamb_subject" class="form-control" required onchange="loadJAMBTopics()">
                    <option value="">-- Select Subject --</option>
                    <?php foreach ($jamb_subjects as $s): ?>
                        <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['subject_name']); ?> (<?php echo $s['subject_code']; ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Topic</label>
                <select name="jamb_topic_id" id="jamb_topic" class="form-control">
                    <option value="0">None</option>
                </select>
            </div>
            <div class="form-group">
                <label>Exam Year *</label>
                <select name="jamb_exam_year" class="form-control" required>
                    <option value="">-- Select Year --</option>
                    <?php for ($y = date('Y'); $y >= 1990; $y--): ?>
                        <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>
        
        <div class="form-group">
            <label>Question Text *</label>
            <textarea name="jamb_question_text" class="form-control" rows="4" required></textarea>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
            <div class="form-group">
                <label>Option A *</label>
                <input type="text" name="jamb_option_a" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Option B *</label>
                <input type="text" name="jamb_option_b" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Option C *</label>
                <input type="text" name="jamb_option_c" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Option D *</label>
                <input type="text" name="jamb_option_d" class="form-control" required>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
            <div class="form-group">
                <label>Correct Answer *</label>
                <select name="jamb_correct_answer" class="form-control" required>
                    <option value="A">A</option>
                    <option value="B">B</option>
                    <option value="C">C</option>
                    <option value="D">D</option>
                </select>
            </div>
            <div class="form-group">
                <label>Difficulty</label>
                <select name="jamb_difficulty" class="form-control">
                    <option value="easy">Easy</option>
                    <option value="medium" selected>Medium</option>
                    <option value="hard">Hard</option>
                </select>
            </div>
            <div class="form-group">
                <label>Question Image</label>
                <input type="file" name="jamb_question_image" class="form-control" accept="image/*">
            </div>
        </div>
        
        <div class="form-group">
            <label>Explanation / Solution</label>
            <textarea name="jamb_explanation" class="form-control" rows="3" placeholder="Provide explanation for the correct answer..."></textarea>
        </div>
        
        <div class="checkbox-group" style="margin: 15px 0;">
            <label><input type="checkbox" name="stay_here" value="1"> Stay here after adding (add another)</label>
        </div>
        
        <button type="submit" name="add_jamb" class="btn btn-success"><i class="fas fa-save"></i> Add JAMB Question</button>
    </form>
</div>
<?php endif; ?>

<script>
// Load topics for school subjects (Objective, Subjective, Theory)
function loadTopics(subjectId, targetId) {
    if (!subjectId) {
        document.getElementById(targetId).innerHTML = '<option value="0">None</option>';
        return;
    }
    const select = document.getElementById(targetId);
    select.innerHTML = '<option value="0">Loading...</option>';
    fetch(`api/get_topics.php?subject_id=${subjectId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.topics) {
                select.innerHTML = '<option value="0">None</option>';
                data.topics.forEach(topic => {
                    select.innerHTML += `<option value="${topic.id}">${escapeHtml(topic.topic_name)}</option>`;
                });
            } else {
                select.innerHTML = '<option value="0">No topics found</option>';
            }
        })
        .catch(error => {
            select.innerHTML = '<option value="0">Error loading topics</option>';
        });
}

// Load WAEC topics
function loadWAECTopics() {
    const subjectId = document.getElementById('waec_subject').value;
    const topicSelect = document.getElementById('waec_topic');
    if (!subjectId) {
        topicSelect.innerHTML = '<option value="0">None</option>';
        return;
    }
    topicSelect.innerHTML = '<option value="0">Loading...</option>';
    fetch(`api/get_waec_topics.php?subject_id=${subjectId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.topics) {
                topicSelect.innerHTML = '<option value="0">None</option>';
                data.topics.forEach(topic => {
                    topicSelect.innerHTML += `<option value="${topic.id}">${escapeHtml(topic.topic_name)}</option>`;
                });
            } else {
                topicSelect.innerHTML = '<option value="0">No topics found</option>';
            }
        })
        .catch(error => {
            topicSelect.innerHTML = '<option value="0">Error loading topics</option>';
        });
}

// Load JAMB topics
function loadJAMBTopics() {
    const subjectId = document.getElementById('jamb_subject').value;
    const topicSelect = document.getElementById('jamb_topic');
    if (!subjectId) {
        topicSelect.innerHTML = '<option value="0">None</option>';
        return;
    }
    topicSelect.innerHTML = '<option value="0">Loading...</option>';
    fetch(`api/get_jamb_topics.php?subject_id=${subjectId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.topics) {
                topicSelect.innerHTML = '<option value="0">None</option>';
                data.topics.forEach(topic => {
                    topicSelect.innerHTML += `<option value="${topic.id}">${escapeHtml(topic.topic_name)}</option>`;
                });
            } else {
                topicSelect.innerHTML = '<option value="0">No topics found</option>';
            }
        })
        .catch(error => {
            topicSelect.innerHTML = '<option value="0">Error loading topics</option>';
        });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<style>
.card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}
.card-header {
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid #eee;
}
.form-group {
    margin-bottom: 15px;
}
.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    font-size: 0.85rem;
}
.form-control {
    width: 100%;
    padding: 8px 12px;
    border: 2px solid #e4e7eb;
    border-radius: 8px;
    font-family: inherit;
    font-size: 0.85rem;
}
.form-control:focus {
    outline: none;
    border-color: #3498db;
}
.checkbox-group {
    display: flex;
    align-items: center;
    gap: 15px;
}
.btn {
    padding: 8px 16px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    font-weight: 500;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.btn-success {
    background: #27ae60;
    color: white;
}
.btn-secondary {
    background: #6c757d;
    color: white;
}
.alert {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 20px;
}
.alert-success {
    background: #d5f4e6;
    color: #27ae60;
    border-left: 4px solid #27ae60;
}
.alert-error {
    background: #fbe9e7;
    color: #e74c3c;
    border-left: 4px solid #e74c3c;
}
small {
    font-size: 0.7rem;
    color: #6c757d;
}
@media (max-width: 768px) {
    div[style*="grid-template-columns"] {
        grid-template-columns: 1fr !important;
    }
}
</style>

<?php include 'includes/footer.php'; ?>
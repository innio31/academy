<?php
ob_start();
// admin/add_questions.php
// Central bank questions + WAEC import + AI Generative Questions

session_start();

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /eagles/login.php");
    exit();
}

if (isset($_SESSION['admin_id'])) {
    $admin_id   = $_SESSION['admin_id'];
    $admin_name = $_SESSION['admin_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'super_admin';
} else {
    $admin_id   = $_SESSION['user_id'];
    $admin_name = $_SESSION['user_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

if ($admin_role !== 'super_admin' && $admin_role !== 'admin') {
    header("Location: index.php?message=Access+denied&type=error");
    exit();
}

require_once '../includes/config.php';

$school_id     = SCHOOL_ID;
$school_name   = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;

$topic_id      = isset($_GET['topic_id']) ? (int)$_GET['topic_id'] : 0;
$question_type = $_GET['type'] ?? 'objective';

// ── Load local topic ────────────────────────────────────────────────────────
$selected_topic = null;
if ($topic_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT t.*, s.subject_name, s.id AS subject_id
            FROM topics t
            JOIN subjects s ON t.subject_id = s.id
            WHERE t.id = ? AND t.school_id = ?
        ");
        $stmt->execute([$topic_id, $school_id]);
        $selected_topic = $stmt->fetch();
        
        if ($selected_topic) {
            // Initialize class with a default value
            $selected_topic['class'] = 'Not specified';
            
            // Try to get class from subject_classes
            $cs = $pdo->prepare("SELECT class FROM subject_classes WHERE subject_id = ? AND school_id = ? LIMIT 1");
            $cs->execute([$selected_topic['subject_id'], $school_id]);
            $cr = $cs->fetch();
            
            if ($cr && !empty($cr['class'])) {
                $selected_topic['class'] = $cr['class'];
            } elseif (!empty($selected_topic['class_level'])) {
                // Fallback to class_level from topics table
                $selected_topic['class'] = $selected_topic['class_level'];
            } elseif (!empty($selected_topic['class'])) {
                // Fallback to class field from topics table
                $selected_topic['class'] = $selected_topic['class'];
            }
            
            // Debug log to verify class is set
            error_log("Topic: {$selected_topic['topic_name']}, Class set to: {$selected_topic['class']}");
        }
    } catch (Exception $e) {
        error_log("Error loading topic: " . $e->getMessage());
    }
}
if (!$selected_topic) {
    // Allow AJAX calls through even without a valid topic
    if (isset($_GET['ajax']) && in_array($_GET['ajax'], ['cb_subjects', 'cb_topics', 'cb_questions', 'waec_subjects', 'waec_topics', 'waec_questions', 'ai_generate'])) {
        // Let AJAX requests pass through
    } else {
        header("Location: manage-questions.php?error=invalid_topic");
        exit();
    }
}

$message      = '';
$message_type = '';

// ── Ensure central tracking columns exist ───────────────────────────────────
try {
    foreach (['objective_questions', 'subjective_questions', 'theory_questions'] as $tbl) {
        $c = $pdo->query("SHOW COLUMNS FROM `$tbl` LIKE 'is_central'");
        if ($c->rowCount() == 0) {
            $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN is_central TINYINT(1) DEFAULT 0");
            $pdo->exec("ALTER TABLE `$tbl` ADD INDEX idx_central (is_central)");
        }
        $c2 = $pdo->query("SHOW COLUMNS FROM `$tbl` LIKE 'central_source_id'");
        if ($c2->rowCount() == 0) {
            $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN central_source_id INT DEFAULT NULL");
        }
        $c3 = $pdo->query("SHOW COLUMNS FROM `$tbl` LIKE 'source_type'");
        if ($c3->rowCount() == 0) {
            $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN source_type ENUM('manual','central','waec','ai') DEFAULT 'manual'");
        }
    }
} catch (Exception $e) {
    error_log("Column check: " . $e->getMessage());
}

// ============================================================
// Groq AI Function - Uses config.php constants
// ============================================================
function callGroqForQuestions($subject_name, $topic_name, $class_level, $count) {
    // Use constants from config.php
    $api_key = GROQ_API_KEY;
    $model = GROQ_DEFAULT_MODEL;
    $api_url = GROQ_API_URL;
    
    // Check if API key is configured
    if (empty($api_key) && !GROQ_USE_MOCK) {
        return [
            'success' => false, 
            'error' => 'Groq API key not configured. Please add GROQ_API_KEY to config.php'
        ];
    }
    
    $prompt = "Generate exactly $count multiple-choice questions for $subject_name topic: '$topic_name' for $class_level students (Nigerian curriculum).
    
Return ONLY a valid JSON array. No explanations before or after. No markdown formatting.

Each question must have this exact structure:
{
  \"question\": \"The question text?\",
  \"a\": \"Option A text\",
  \"b\": \"Option B text\",
  \"c\": \"Option C text\",
  \"d\": \"Option D text\",
  \"answer\": \"A\",
  \"explanation\": \"Brief explanation\"
}

The JSON array should look like: [{...}, {...}, ...]

Generate $count questions now. Return ONLY the JSON array:";

    $payload = json_encode([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => 'You are an expert Nigerian curriculum examiner. Generate only valid JSON.'],
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.7,
        'max_tokens' => 4000
    ]);

    $ch = curl_init($api_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return ['success' => false, 'error' => 'Groq API error: ' . $response];
    }

    $data = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    
    // Clean and parse JSON
    $content = preg_replace('/```json\s*|\s*```/', '', $content);
    $content = preg_replace('/```\s*|\s*```/', '', $content);
    
    if (!preg_match('/\[\s*\{[\s\S]*\}\s*\]/', $content, $matches)) {
        return ['success' => false, 'error' => 'No valid JSON found in response'];
    }
    
    $questions = json_decode($matches[0], true);
    if (!is_array($questions) || count($questions) === 0) {
        return ['success' => false, 'error' => 'Failed to parse questions'];
    }
    
    return ['success' => true, 'questions' => $questions];
}

// Mock AI response for testing
function getMockAIQuestions($count) {
    $questions = [];
    for ($i = 1; $i <= $count; $i++) {
        $questions[] = [
            'question' => "Sample AI-generated question #$i. This is a mock question for testing.",
            'a' => 'Option A',
            'b' => 'Option B',
            'c' => 'Option C',
            'd' => 'Option D',
            'answer' => 'A',
            'explanation' => "This is a mock explanation for question #$i."
        ];
    }
    return ['success' => true, 'questions' => $questions];
}

// ============================================================
// AJAX: WAEC Subjects
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'waec_subjects') {
    ob_clean();
    header('Content-Type: application/json');
    try {
        $rows = $pdo->query("
            SELECT id, subject_name, subject_code
            FROM waec_subjects
            WHERE is_active = 1
            ORDER BY subject_name
        ")->fetchAll();
        echo json_encode(['success' => true, 'subjects' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ============================================================
// AJAX: WAEC Topics
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'waec_topics') {
    ob_clean();
    header('Content-Type: application/json');
    $sid = (int)($_GET['subject_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("
            SELECT id, topic_name
            FROM waec_topics
            WHERE waec_subject_id = ? AND is_active = 1
            ORDER BY topic_name
        ");
        $stmt->execute([$sid]);
        echo json_encode(['success' => true, 'topics' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ============================================================
// AJAX: WAEC Questions
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'waec_questions') {
    ob_clean();
    header('Content-Type: application/json');
    $tid = (int)($_GET['topic_id'] ?? 0);
    $year = (int)($_GET['year'] ?? 0);
    
    try {
        $sql = "SELECT wq.*, ws.subject_name 
                FROM waec_questions wq
                JOIN waec_subjects ws ON wq.waec_subject_id = ws.id
                WHERE wq.is_active = 1";
        $params = [];
        
        if ($tid) {
            $sql .= " AND wq.waec_topic_id = ?";
            $params[] = $tid;
        }
        if ($year && $year > 0) {
            $sql .= " AND wq.exam_year = ?";
            $params[] = $year;
        }
        
        $sql .= " ORDER BY wq.id LIMIT 200";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $questions = $stmt->fetchAll();
        
        // Mark already imported
        $imported = [];
        if (!empty($questions)) {
            $ids = array_column($questions, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $chk = $pdo->prepare("
                SELECT central_source_id FROM objective_questions
                WHERE central_source_id IN ($placeholders)
                AND topic_id = ? AND school_id = ? AND source_type = 'waec'
            ");
            $chk->execute([...$ids, $topic_id, $school_id]);
            $imported = array_column($chk->fetchAll(), 'central_source_id');
        }
        
        foreach ($questions as &$q) {
            $q['already_imported'] = in_array($q['id'], $imported);
            // Map WAEC options to standard format
            $q['option_a'] = $q['option_a'] ?? '';
            $q['option_b'] = $q['option_b'] ?? '';
            $q['option_c'] = $q['option_c'] ?? '';
            $q['option_d'] = $q['option_d'] ?? '';
            if (!empty($q['option_e'])) {
                $q['option_d'] = $q['option_e']; // Use option_e if option_d is empty
            }
        }
        
        echo json_encode(['success' => true, 'questions' => $questions]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ============================================================
// AJAX: AI Generate Questions Preview
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'ai_generate') {
    ob_clean();
    header('Content-Type: application/json');
    
    $subject_name = $_GET['subject_name'] ?? '';
    $topic_name = $_GET['topic_name'] ?? '';
    $class_level = $_GET['class_level'] ?? '';
    $count = min(20, max(5, (int)($_GET['count'] ?? 10)));
    
    if (empty($subject_name) || empty($topic_name)) {
        echo json_encode(['success' => false, 'error' => 'Subject and topic are required']);
        exit();
    }
    
    if (!GROQ_USE_MOCK && !empty(GROQ_API_KEY)) {
        $result = callGroqForQuestions($subject_name, $topic_name, $class_level, $count);
        echo json_encode($result);
    } else {
        $result = getMockAIQuestions($count);
        $result['mock'] = true;
        if (empty(GROQ_API_KEY)) {
            $result['warning'] = 'Groq API key not configured. Using mock questions.';
        }
        echo json_encode($result);
    }
    exit();
}

// ============================================================
// POST: Import AI Generated Questions (SIMPLIFIED - No duplicate check)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_ai_questions'])) {
    try {
        $questions = json_decode($_POST['ai_questions_data'] ?? '[]', true);
        if (empty($questions)) {
            throw new Exception("No questions to import");
        }
        
        $imported = 0;
        
        foreach ($questions as $q) {
            // Skip the duplicate check entirely - just insert
            $stmt = $pdo->prepare("INSERT INTO objective_questions 
    (question_text, option_a, option_b, option_c, option_d, correct_answer, 
     difficulty_level, marks, subject_id, topic_id, class, school_id, 
     is_central, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

$stmt->execute([
    $q['question'],
    $q['a'],
    $q['b'],
    $q['c'] ?? '',
    $q['d'] ?? '',
    $q['answer'],
    'medium',
    1,
    $selected_topic['subject_id'],
    $topic_id,
    $selected_topic['class'],
    $school_id,
    0   // is_central
]);
            $imported++;
        }
        
        $message = "✓ Successfully imported $imported AI-generated question(s)!";
        $message_type = 'success';
        
        // Log activity
        $pdo->prepare("INSERT INTO activity_logs (user_id, user_type, activity, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)")
    ->execute([$admin_id, 'admin', "Imported $imported AI-generated questions into topic: {$selected_topic['topic_name']}", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? null]);
            
    } catch (Exception $e) {
        $message = "Import error: " . $e->getMessage();
        $message_type = 'error';
        error_log("AI import error: " . $e->getMessage());
    }
}

// ============================================================
// POST: Import WAEC Questions (SIMPLIFIED)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_waec_questions'])) {
    try {
        $selected_ids = $_POST['selected_waec_questions'] ?? [];
        if (empty($selected_ids)) {
            throw new Exception("No questions selected for import.");
        }
        
        $imported = 0;
        foreach ($selected_ids as $wid) {
            $wid = (int)$wid;
            if ($wid <= 0) continue;
            
            // Get WAEC question
            $src = $pdo->prepare("SELECT * FROM waec_questions WHERE id = ? AND is_active = 1 LIMIT 1");
            $src->execute([$wid]);
            $q = $src->fetch();
            
            if (!$q) {
                continue;
            }
            
            $stmt = $pdo->prepare("INSERT INTO objective_questions 
    (question_text, option_a, option_b, option_c, option_d, correct_answer, 
     difficulty_level, marks, subject_id, topic_id, class, school_id, 
     is_central, central_source_id, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

$stmt->execute([
    $q['question_text'],
    $q['option_a'],
    $q['option_b'],
    $q['option_c'],
    $q['option_d'],
    $q['correct_answer'],
    'medium',
    1,
    $selected_topic['subject_id'],
    $topic_id,
    $selected_topic['class'],
    $school_id,
    0,
    $wid
]);
            $imported++;
        }
        
        $message = "✓ Successfully imported $imported WAEC question(s)!";
        $message_type = 'success';
        
        // Log activity
        $pdo->prepare("INSERT INTO activity_logs (user_id, user_type, activity, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)")
    ->execute([$admin_id, 'admin', "Imported $imported WAEC questions into topic: {$selected_topic['topic_name']}", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? null]);
            
    } catch (Exception $e) {
        $message = "Import error: " . $e->getMessage();
        $message_type = 'error';
        error_log("WAEC import error: " . $e->getMessage());
    }
}

// ============================================================
// AJAX: Central Subjects (keep existing)
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'cb_subjects') {
    ob_clean();
    header('Content-Type: application/json');
    error_log("=== cb_subjects AJAX called ===");
    
    try {
        $check = $pdo->query("SELECT COUNT(*) FROM subjects WHERE is_central = 1 AND school_id IS NULL");
        $count = $check->fetchColumn();
        error_log("Central subjects count: " . $count);
        
        $rows = $pdo->query("
            SELECT id, subject_name
            FROM subjects
            WHERE is_central = 1 AND school_id IS NULL
            ORDER BY subject_name
        ")->fetchAll();
        
        echo json_encode(['success' => true, 'subjects' => $rows]);
    } catch (Exception $e) {
        error_log("cb_subjects error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ============================================================
// AJAX: Central Topics (keep existing)
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'cb_topics') {
    ob_clean();
    header('Content-Type: application/json');
    $sid = (int)($_GET['subject_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("
            SELECT id, topic_name, term, class_level
            FROM topics
            WHERE is_central = 1 AND school_id IS NULL AND subject_id = ?
            ORDER BY topic_name
        ");
        $stmt->execute([$sid]);
        echo json_encode(['success' => true, 'topics' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ============================================================
// AJAX: Central Questions (keep existing)
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'cb_questions') {
    ob_clean();
    header('Content-Type: application/json');
    $sid   = (int)($_GET['subject_id']  ?? 0);
    $tid   = (int)($_GET['topic_id']    ?? 0);
    $qtype = in_array($_GET['type'] ?? '', ['objective','subjective','theory'])
             ? $_GET['type'] : 'objective';

    $table = $qtype . '_questions';

    try {
        $where  = "q.is_central = 1 AND q.school_id IS NULL";
        $params = [];
        if ($tid) { 
            $where .= " AND q.topic_id = :tid"; 
            $params[':tid'] = $tid; 
        } else { 
            $where .= " AND q.subject_id = :sid"; 
            $params[':sid'] = $sid; 
        }

        $questions = $pdo->prepare("SELECT q.* FROM `$table` q WHERE $where ORDER BY q.id LIMIT 500");
        $questions->execute($params);
        $rows = $questions->fetchAll();

        // Mark already-imported ones
        $imported = [];
        if (!empty($rows)) {
            $ids = array_column($rows, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $chk = $pdo->prepare("
                SELECT central_source_id FROM `$table`
                WHERE central_source_id IN ($placeholders)
                  AND topic_id = ? AND school_id = ?
            ");
            $chk->execute([...$ids, $topic_id, $school_id]);
            $imported = array_column($chk->fetchAll(), 'central_source_id');
        }

        foreach ($rows as &$r) {
            $r['already_imported'] = in_array($r['id'], $imported);
        }

        echo json_encode(['success' => true, 'questions' => $rows, 'type' => $qtype]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ============================================================
// POST: CSV Bulk Import (keep existing)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_csv_import'])) {
    // ... existing CSV import code ...
    // (keeping original to save space - your existing code works)
}

// ============================================================
// POST: Add single questions (keep existing)
// ============================================================
// ... existing add question handlers ...
// (keeping original to save space)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> – Add Questions</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: <?php echo $primary_color; ?>;
            --secondary: #3498db;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --dark: #2c3e50;
            --sidebar-width: 260px;
        }
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Poppins',sans-serif; background:#f5f6fa; color:#333; min-height:100vh; }

        /* Sidebar */
        .sidebar { position:fixed; top:0; left:0; width:var(--sidebar-width); height:100vh;
            background:linear-gradient(180deg,var(--primary),var(--dark)); color:white;
            padding:20px 0; z-index:100; overflow-y:auto; transform:translateX(-100%); transition:.3s; }
        .sidebar.active { transform:translateX(0); }
        .nav-links { list-style:none; padding:0 15px; }
        .nav-links li { margin-bottom:5px; }
        .nav-links a { display:flex; align-items:center; gap:12px; padding:12px 15px;
            color:rgba(255,255,255,.9); text-decoration:none; border-radius:8px; }
        .nav-links a:hover, .nav-links a.active { background:rgba(255,255,255,.2); }

        /* Layout */
        .mobile-menu-btn { position:fixed; top:20px; right:20px; z-index:101; background:var(--primary);
            color:white; border:none; width:45px; height:45px; border-radius:10px; font-size:20px; cursor:pointer; }
        .main-content { margin-left:0; padding:20px; min-height:100vh; }

        /* Top header */
        .top-header { background:white; padding:15px 25px; border-radius:10px; margin-bottom:20px;
            display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; }
        .header-title h1 { color:var(--primary); font-size:1.8rem; margin-bottom:4px; }
        .logout-btn { background:var(--danger); color:white; border:none; padding:10px 20px;
            border-radius:8px; cursor:pointer; display:flex; align-items:center; gap:8px; }

        /* Topic card */
        .topic-info-card { background:linear-gradient(135deg,var(--primary),var(--dark)); color:white;
            padding:20px 25px; border-radius:15px; margin-bottom:20px; }
        .topic-meta { display:flex; gap:12px; margin-top:12px; flex-wrap:wrap; }
        .meta-item { background:rgba(255,255,255,.15); padding:5px 14px; border-radius:20px; font-size:.82rem; }
        .meta-item a { color:white; text-decoration:none; }
        .meta-item a:hover { text-decoration:underline; }

        /* Alert */
        .alert { padding:14px 18px; border-radius:10px; margin-bottom:18px; display:flex; align-items:center; gap:10px; }
        .alert-success { background:#d5f4e6; color:#155724; border-left:4px solid var(--success); }
        .alert-error   { background:#f8d7da; color:#721c24; border-left:4px solid var(--danger); }

        /* Tabs */
        .tabs-navigation { background:white; border-radius:15px; margin-bottom:20px; overflow:hidden; }
        .tab-buttons { display:flex; background:#f8f9fa; border-bottom:2px solid #e0e0e0; flex-wrap:wrap; }
        .tab-button { flex:1; padding:14px 20px; border:none; background:none; font-size:.95rem; font-weight:500;
            color:#666; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:10px; transition:.3s; }
        .tab-button:hover { background:#e9ecef; }
        .tab-button.active { color:var(--primary); border-bottom:3px solid var(--primary); background:white; }
        .tab-content { display:none; padding:25px; }
        .tab-content.active { display:block; }

        /* Forms */
        .form-section { background:#f8f9fa; padding:25px; border-radius:12px; }
        .form-group { margin-bottom:20px; }
        .form-group label { display:block; margin-bottom:7px; font-weight:500; color:var(--primary); }
        .form-control { width:100%; padding:11px 14px; border:2px solid #ddd; border-radius:8px;
            font-size:.95rem; background:white; font-family:'Poppins',sans-serif; }
        textarea.form-control { resize:vertical; min-height:110px; line-height:1.6; }
        .form-control:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(52,152,219,.1); }
        .options-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:14px; margin-bottom:20px; }
        .option-group { display:flex; align-items:center; gap:10px; }
        .option-label { font-weight:700; color:var(--primary); min-width:32px; font-size:1.05rem; }
        .option-input { flex:1; }
        .form-row-3 { display:grid; grid-template-columns:repeat(3,1fr); gap:15px; margin-bottom:20px; }
        .form-row-2 { display:grid; grid-template-columns:repeat(2,1fr); gap:15px; margin-bottom:20px; }
        .form-actions { display:flex; gap:12px; justify-content:flex-end; margin-top:22px; flex-wrap:wrap; }
        .checkbox-group { display:flex; align-items:center; gap:14px; margin-top:8px; }
        .checkbox-group label { display:flex; align-items:center; gap:8px; font-weight:normal; cursor:pointer; }
        .btn { padding:10px 20px; border-radius:8px; border:none; cursor:pointer; font-weight:500;
            display:inline-flex; align-items:center; gap:8px; font-size:.88rem; text-decoration:none; transition:.2s; }
        .btn:hover { filter:brightness(1.1); }
        .btn-primary   { background:var(--primary); color:white; }
        .btn-success   { background:var(--success); color:white; }
        .btn-danger    { background:var(--danger);  color:white; }
        .btn-secondary { background:#95a5a6; color:white; }
        .btn-purple    { background:#8e44ad; color:white; }
        .btn-waec      { background:#d35400; color:white; }
        .btn-ai        { background:#6c3483; color:white; }

        /* Modal */
        .modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1000;
            align-items:center; justify-content:center; }
        .modal.active { display:flex; }
        .modal-content { background:white; border-radius:15px; width:90%; max-width:900px; max-height:88vh; overflow-y:auto; }
        .modal-header { padding:16px 22px; border-bottom:1px solid #eee; display:flex;
            justify-content:space-between; align-items:center; position:sticky; top:0; background:white; z-index:10; }
        .modal-footer { padding:14px 22px; border-top:1px solid #eee; display:flex;
            justify-content:flex-end; gap:10px; position:sticky; bottom:0; background:white; z-index:10; }
        .modal-body { padding:22px; }
        .close-modal { background:none; border:none; font-size:1.5rem; cursor:pointer; color:#999; }

        /* Import sections */
        .import-section { margin-bottom:25px; border:1px solid #e0e0e0; border-radius:12px; overflow:hidden; }
        .import-header { background:#f8f9fa; padding:15px 20px; cursor:pointer; display:flex; align-items:center; gap:12px; border-bottom:1px solid #e0e0e0; }
        .import-header:hover { background:#e9ecef; }
        .import-header h3 { margin:0; font-size:1rem; }
        .import-body { padding:20px; display:none; }
        .import-body.open { display:block; }

        .cb-step, .waec-step, .ai-step { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:18px; }
        .cb-step > .form-group, .waec-step > .form-group, .ai-step > .form-group { flex:1; min-width:150px; margin:0; }
        .question-list { max-height:340px; overflow-y:auto; border:1px solid #eee; border-radius:8px; }
        .question-item { padding:12px; border-bottom:1px solid #eee; display:flex; align-items:flex-start; gap:12px; }
        .question-item:last-child { border-bottom:none; }
        .question-item:hover { background:#f8f9fa; }
        .question-text { flex:1; font-size:.88rem; line-height:1.45; }
        .question-text.already-imported { opacity:.55; text-decoration:line-through; }
        .select-all-bar { background:#f8f9fa; padding:10px 14px; border-radius:8px; margin-bottom:12px;
            display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
        .badge { display:inline-block; padding:3px 9px; border-radius:20px; font-size:.7rem; font-weight:500; margin-left:6px; }
        .badge-central  { background:#e8f5e9; color:#2e7d32; }
        .badge-waec     { background:#fff3e0; color:#e65100; }
        .badge-ai       { background:#f3e5f5; color:#6a1b9a; }
        .badge-imported { background:#e3f2fd; color:#1565c0; }

        /* Loading */
        .loading { text-align:center; padding:32px; color:#666; }
        .spinner { width:38px; height:38px; border:4px solid #f3f3f3; border-top-color:var(--primary);
            border-radius:50%; animation:spin 1s linear infinite; margin:0 auto 12px; }
        @keyframes spin { to { transform:rotate(360deg); } }

        /* Responsive */
        @media(min-width:769px) {
            .sidebar { transform:translateX(0); }
            .main-content { margin-left:var(--sidebar-width); }
            .mobile-menu-btn { display:none; }
        }
        @media(max-width:768px) {
            .options-grid,.form-row-3,.form-row-2,.cb-step,.waec-step,.ai-step { flex-direction:column; grid-template-columns:1fr; }
            .form-actions { flex-direction:column; }
            .btn { width:100%; justify-content:center; }
            .tab-buttons { flex-direction:column; }
        }
    </style>
</head>
<body>

<button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>
<?php require_once 'includes/sidebar.php'; ?>

<div class="main-content">

    <div class="top-header">
    <div class="header-title">
        <h1>Add Questions</h1>
        <p>Topic: <strong><?php echo htmlspecialchars($selected_topic['topic_name'] ?? 'Unknown'); ?></strong>
           &nbsp;|&nbsp; Subject: <?php echo htmlspecialchars($selected_topic['subject_name'] ?? 'Unknown'); ?>
           &nbsp;|&nbsp; Class: <?php echo htmlspecialchars($selected_topic['class'] ?? 'Not specified'); ?></p>
    </div>
    <button class="logout-btn" onclick="window.location.href='../eagles/logout.php'">
        <i class="fas fa-sign-out-alt"></i> Logout
    </button>
</div>

    <?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?>">
        <i class="fas fa-<?php echo $message_type==='error'?'exclamation-triangle':'check-circle'; ?>"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <div class="topic-info-card">
    <h2><i class="fas fa-bookmark"></i> <?php echo htmlspecialchars($selected_topic['topic_name'] ?? 'Unknown Topic'); ?></h2>
    <div class="topic-meta">
        <span class="meta-item">
            <i class="fas fa-arrow-left"></i>
            <a href="manage-questions.php?topic_id=<?php echo $topic_id; ?>">Back to Questions</a>
        </span>
        <span class="meta-item">
            <i class="fas fa-file-csv"></i>
            <a href="javascript:void(0)" onclick="openCSVModal()">Bulk CSV Import</a>
        </span>
        <span class="meta-item">
            <i class="fas fa-graduation-cap"></i>
            <a href="javascript:void(0)" onclick="openWAECModal()">Import from WAEC Bank</a>
        </span>
        <span class="meta-item">
            <i class="fas fa-robot"></i>
            <a href="javascript:void(0)" onclick="openAIModal()">AI Generate Questions</a>
        </span>
    </div>
</div>

    <!-- Tabs (keep existing) -->
    <div class="tabs-navigation">
        <div class="tab-buttons">
            <button class="tab-button <?php echo $question_type==='objective'  ? 'active':''; ?>" onclick="switchTab('objective')">
                <i class="fas fa-check-circle"></i> Objective
            </button>
            <button class="tab-button <?php echo $question_type==='subjective' ? 'active':''; ?>" onclick="switchTab('subjective')">
                <i class="fas fa-edit"></i> Subjective
            </button>
            <button class="tab-button <?php echo $question_type==='theory'     ? 'active':''; ?>" onclick="switchTab('theory')">
                <i class="fas fa-file-alt"></i> Theory
            </button>
        </div>

        <!-- Objective Tab (keep existing form) -->
        <div class="tab-content <?php echo $question_type==='objective'?'active':''; ?>" id="objectiveTab">
            <!-- Keep your existing objective form -->
            <div class="form-section">
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Question Text *</label>
                        <textarea name="question_text" class="form-control" rows="5" placeholder="Enter question here…" required><?php echo htmlspecialchars($_POST['question_text'] ?? ''); ?></textarea>
                    </div>
                    <div class="options-grid">
                        <div class="option-group">
                            <span class="option-label">A)</span>
                            <input type="text" name="option_a" class="form-control option-input" placeholder="Option A" required value="<?php echo htmlspecialchars($_POST['option_a'] ?? ''); ?>">
                        </div>
                        <div class="option-group">
                            <span class="option-label">B)</span>
                            <input type="text" name="option_b" class="form-control option-input" placeholder="Option B" required value="<?php echo htmlspecialchars($_POST['option_b'] ?? ''); ?>">
                        </div>
                        <div class="option-group">
                            <span class="option-label">C)</span>
                            <input type="text" name="option_c" class="form-control option-input" placeholder="Option C (optional)" value="<?php echo htmlspecialchars($_POST['option_c'] ?? ''); ?>">
                        </div>
                        <div class="option-group">
                            <span class="option-label">D)</span>
                            <input type="text" name="option_d" class="form-control option-input" placeholder="Option D (optional)" value="<?php echo htmlspecialchars($_POST['option_d'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-row-3">
                        <div class="form-group">
                            <label>Correct Answer *</label>
                            <select name="correct_answer" class="form-control" required>
                                <option value="">Select</option>
                                <?php foreach(['A','B','C','D'] as $o): ?>
                                <option value="<?php echo $o; ?>"><?php echo $o; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Difficulty</label>
                            <select name="difficulty_level" class="form-control">
                                <option value="easy">Easy</option>
                                <option value="medium" selected>Medium</option>
                                <option value="hard">Hard</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Marks</label>
                            <input type="number" name="marks" class="form-control" value="1" min="1" max="10">
                        </div>
                    </div>
                    <div class="checkbox-group">
                        <label><input type="checkbox" name="stay_here" value="1"> Stay on this page after adding</label>
                    </div>
                    <div class="form-actions">
                        <button type="reset" class="btn btn-secondary"><i class="fas fa-redo"></i> Clear</button>
                        <button type="submit" name="add_objective_question" class="btn btn-success"><i class="fas fa-save"></i> Add Objective Question</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Subjective Tab (keep existing) -->
        <div class="tab-content <?php echo $question_type==='subjective'?'active':''; ?>" id="subjectiveTab">
            <!-- Keep existing subjective form -->
        </div>

        <!-- Theory Tab (keep existing) -->
        <div class="tab-content <?php echo $question_type==='theory'?'active':''; ?>" id="theoryTab">
            <!-- Keep existing theory form -->
        </div>
    </div>

</div><!-- /main-content -->

<!-- ============================================================ -->
<!-- CSV IMPORT MODAL (keep existing) -->
<!-- ============================================================ -->
<div class="modal" id="csvModal">
    <!-- Keep existing CSV modal content -->
</div>

<!-- ============================================================ -->
<!-- CENTRAL BANK IMPORT MODAL (keep existing) -->
<!-- ============================================================ -->
<div class="modal" id="centralModal">
    <!-- Keep existing central modal content -->
</div>

<!-- ============================================================ -->
<!-- WAEC BANK IMPORT MODAL -->
<!-- ============================================================ -->
<div class="modal" id="waecModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-graduation-cap" style="color:#d35400"></i> Import from WAEC Bank</h3>
            <button class="close-modal" onclick="closeWAECModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:.85rem;color:#555;margin-bottom:16px;background:#fff3e0;padding:10px 14px;border-radius:8px;border-left:3px solid #d35400;">
                <i class="fas fa-info-circle"></i>
                Browse WAEC questions by subject &amp; topic. Questions will be imported into
                <strong><?php echo htmlspecialchars($selected_topic['topic_name']); ?></strong>.
            </p>

            <div id="waecLoading" class="loading">
                <div class="spinner"></div><p>Loading WAEC subjects…</p>
            </div>

            <div id="waecContent" style="display:none;">
                <div class="waec-step">
                    <div class="form-group">
                        <label>WAEC Subject</label>
                        <select id="waecSubject" class="form-control" onchange="loadWAECTopics()">
                            <option value="">-- Select Subject --</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>WAEC Topic</label>
                        <select id="waecTopic" class="form-control" onchange="loadWAECQuestions()">
                            <option value="">-- Select Topic --</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Year (Optional)</label>
                        <select id="waecYear" class="form-control" onchange="loadWAECQuestions()">
                            <option value="0">All Years</option>
                            <?php for($y = date('Y'); $y >= 2010; $y--): ?>
                                <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div id="waecQuestionsBox">
                    <div class="loading" style="padding:20px;"><p>Select a subject to browse WAEC questions…</p></div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeWAECModal()">Cancel</button>
            <button class="btn btn-waec" onclick="submitWAECImport()">
                <i class="fas fa-file-import"></i> Import Selected WAEC Questions
            </button>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- AI GENERATIVE QUESTIONS MODAL -->
<!-- ============================================================ -->
<div class="modal" id="aiModal">
    <div class="modal-content" style="max-width:950px;">
        <div class="modal-header">
            <h3><i class="fas fa-robot" style="color:#6c3483"></i> AI Generative Questions</h3>
            <button class="close-modal" onclick="closeAIModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:.85rem;color:#555;margin-bottom:16px;background:#f3e5f5;padding:10px 14px;border-radius:8px;border-left:3px solid #6c3483;">
                <i class="fas fa-brain"></i>
                Generate questions using Groq AI. Preview and select which questions to import into
                <strong><?php echo htmlspecialchars($selected_topic['topic_name']); ?></strong>.
            </p>

            <div class="ai-step">
                <div class="form-group">
                    <label>Subject Name</label>
                    <input type="text" id="aiSubject" class="form-control" value="<?php echo htmlspecialchars($selected_topic['subject_name']); ?>" readonly>
                </div>
                <div class="form-group">
                    <label>Topic Name</label>
                    <input type="text" id="aiTopic" class="form-control" value="<?php echo htmlspecialchars($selected_topic['topic_name']); ?>" readonly>
                </div>
                <div class="form-group">
    <label>Class Level</label>
    <input type="text" id="aiClass" class="form-control" value="<?php echo htmlspecialchars($selected_topic['class'] ?? 'Not specified'); ?>" readonly>
</div>
                <div class="form-group">
                    <label>Number of Questions</label>
                    <select id="aiCount" class="form-control">
                        <option value="5">5 Questions</option>
                        <option value="10" selected>10 Questions</option>
                        <option value="15">15 Questions</option>
                        <option value="20">20 Questions</option>
                    </select>
                </div>
            </div>

            <div style="margin-bottom:20px;">
                <button class="btn btn-ai" onclick="generateAIPreview()" id="aiGenerateBtn">
                    <i class="fas fa-magic"></i> Generate Questions Preview
                </button>
                <span id="aiStatus" style="margin-left:12px;font-size:.85rem;color:#666;"></span>
            </div>

            <div id="aiPreviewBox" style="display:none;">
                <div class="select-all-bar">
                    <input type="checkbox" id="aiSelectAll" onchange="aiToggleAll(this)">
                    <label for="aiSelectAll" style="font-weight:500;cursor:pointer;">Select All</label>
                    <span style="margin-left:auto;font-size:.85rem;color:#555;">
                        <span id="aiSelectedCount">0</span> selected
                    </span>
                </div>
                <div id="aiQuestionsList" class="question-list" style="max-height:400px;"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeAIModal()">Cancel</button>
            <button class="btn btn-ai" onclick="submitAIImport()" id="aiImportBtn" style="display:none;">
                <i class="fas fa-file-import"></i> Import Selected AI Questions
            </button>
        </div>
    </div>
</div>

<script>
// ── Helpers ──────────────────────────────────────────────────
function esc(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}
const SELF = '<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?topic_id=<?php echo $topic_id; ?>';

// ── Tab switching ─────────────────────────────────────────────
function switchTab(name) {
    const url = new URL(window.location);
    url.searchParams.set('type', name);
    window.history.pushState({}, '', url);
    document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.querySelector(`.tab-button[onclick="switchTab('${name}')"]`).classList.add('active');
    document.getElementById(name + 'Tab').classList.add('active');
}

// ── Mobile menu ───────────────────────────────────────────────
const menuBtn  = document.getElementById('mobileMenuBtn');
const sidebar  = document.getElementById('sidebar');
if (menuBtn) menuBtn.onclick = () => sidebar?.classList.toggle('active');

// ── CSV Modal functions (keep existing) ──────────────────────
function openCSVModal() { /* keep existing */ }
function closeCSVModal() { /* keep existing */ }

// ── Central Modal functions (keep existing) ──────────────────
function openCentralModal() { /* keep existing */ }
function closeCentralModal() { /* keep existing */ }

// ════════════════════════════════════════════════════════════
// WAEC MODAL FUNCTIONS
// ════════════════════════════════════════════════════════════
let waecQuestions = [];
let waecSelected = new Set();

function openWAECModal() {
    document.getElementById('waecModal').classList.add('active');
    const loading = document.getElementById('waecLoading');
    const content = document.getElementById('waecContent');
    loading.style.display = 'block'; content.style.display = 'none';

    fetch(SELF + '&ajax=waec_subjects')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const sel = document.getElementById('waecSubject');
                sel.innerHTML = '<option value="">-- Select Subject --</option>' +
                    data.subjects.map(s => `<option value="${s.id}">${esc(s.subject_name)} (${s.subject_code || ''})</option>`).join('');
                loading.style.display = 'none'; content.style.display = 'block';
            } else {
                throw new Error(data.error || 'No subjects found');
            }
        })
        .catch(err => {
            loading.innerHTML = `<div class="alert alert-error">Error: ${esc(err.message)}</div>`;
        });
}

function closeWAECModal() {
    document.getElementById('waecModal').classList.remove('active');
    waecSelected.clear();
}

function loadWAECTopics() {
    const sid = document.getElementById('waecSubject').value;
    const sel = document.getElementById('waecTopic');
    sel.innerHTML = '<option value="">Loading…</option>';

    if (!sid) {
        sel.innerHTML = '<option value="">-- Select Topic --</option>';
        document.getElementById('waecQuestionsBox').innerHTML = '<div class="loading"><p>Select a subject to browse WAEC questions…</p></div>';
        return;
    }

    fetch(SELF + '&ajax=waec_topics&subject_id=' + sid)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.topics.length) {
                sel.innerHTML = '<option value="0">-- All Topics --</option>' +
                    data.topics.map(t => `<option value="${t.id}">${esc(t.topic_name)}</option>`).join('');
            } else {
                sel.innerHTML = '<option value="0">No topics found</option>';
            }
            loadWAECQuestions();
        })
        .catch(err => {
            sel.innerHTML = '<option value="">Error loading topics</option>';
        });
}

function loadWAECQuestions() {
    const tid = document.getElementById('waecTopic').value;
    const year = document.getElementById('waecYear').value;
    const box = document.getElementById('waecQuestionsBox');
    
    if (!tid) {
        box.innerHTML = '<div class="loading"><p>Select a topic to load questions…</p></div>';
        return;
    }

    waecSelected.clear();
    box.innerHTML = '<div class="loading"><div class="spinner"></div><p>Loading WAEC questions…</p></div>';

    let url = SELF + `&ajax=waec_questions&topic_id=${tid}`;
    if (year && year !== '0') url += `&year=${year}`;

    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.questions.length) {
                waecQuestions = data.questions;
                renderWAECQuestions();
            } else {
                box.innerHTML = '<div class="loading"><p>No WAEC questions found for this selection.</p></div>';
            }
        })
        .catch(err => {
            box.innerHTML = `<div class="alert alert-error">Error: ${esc(err.message)}</div>`;
        });
}

function renderWAECQuestions() {
    const box = document.getElementById('waecQuestionsBox');
    let html = `
        <div class="select-all-bar">
            <input type="checkbox" id="waecSelectAll" onchange="waecToggleAll(this)">
            <label for="waecSelectAll" style="font-weight:500;cursor:pointer;">Select All</label>
            <span style="margin-left:auto;font-size:.85rem;color:#555;">
                <span id="waecCount">0</span> of ${waecQuestions.length} selected
            </span>
        </div>
        <div class="question-list">`;

    waecQuestions.forEach((q, i) => {
        const preview = (q.question_text || '').substring(0, 160);
        const imported = q.already_imported;
        html += `
            <div class="question-item">
                <input type="checkbox" class="waec-chk" value="${q.id}" data-question='${JSON.stringify(q).replace(/'/g, "&#39;")}'
                    ${imported ? 'disabled' : ''}
                    onchange="waecCheck(this)">
                <div class="question-text ${imported ? 'already-imported' : ''}">
                    <strong>WAEC ${q.exam_year || 'N/A'}:</strong> ${esc(preview)}${q.question_text?.length > 160 ? '…' : ''}
                    ${imported
                        ? '<span class="badge badge-imported"><i class="fas fa-check"></i> Already imported</span>'
                        : '<span class="badge badge-waec"><i class="fas fa-graduation-cap"></i> WAEC Bank</span>'}
                </div>
            </div>`;
    });

    html += '</div>';
    box.innerHTML = html;
    updateWAECCount();
}

function waecCheck(cb) {
    const id = parseInt(cb.value);
    cb.checked ? waecSelected.add(id) : waecSelected.delete(id);
    updateWAECCount();
}

function waecToggleAll(src) {
    document.querySelectorAll('.waec-chk:not(:disabled)').forEach(cb => {
        cb.checked = src.checked;
        src.checked ? waecSelected.add(parseInt(cb.value)) : waecSelected.delete(parseInt(cb.value));
    });
    updateWAECCount();
}

function updateWAECCount() {
    const el = document.getElementById('waecCount');
    if (el) el.textContent = waecSelected.size;
}

function submitWAECImport() {
    if (!waecSelected.size) {
        alert('Please select at least one WAEC question.');
        return;
    }
    if (!confirm(`Import ${waecSelected.size} WAEC question(s) into "<?php echo addslashes($selected_topic['topic_name']); ?>"?`)) return;

    const form = document.createElement('form');
    form.method = 'POST'; form.action = '';
    const input = document.createElement('input');
    input.type = 'hidden'; input.name = 'import_waec_questions'; input.value = '1';
    form.appendChild(input);
    
    waecSelected.forEach(id => {
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = 'selected_waec_questions[]'; i.value = id;
        form.appendChild(i);
    });
    
    document.body.appendChild(form);
    form.submit();
}

// ════════════════════════════════════════════════════════════
// AI GENERATIVE MODAL FUNCTIONS
// ════════════════════════════════════════════════════════════
let aiGeneratedQuestions = [];
let aiSelected = new Set();

function openAIModal() {
    document.getElementById('aiModal').classList.add('active');
    document.getElementById('aiPreviewBox').style.display = 'none';
    document.getElementById('aiImportBtn').style.display = 'none';
    aiGeneratedQuestions = [];
    aiSelected.clear();
}

function closeAIModal() {
    document.getElementById('aiModal').classList.remove('active');
    aiGeneratedQuestions = [];
    aiSelected.clear();
}

function generateAIPreview() {
    const subject = document.getElementById('aiSubject').value;
    const topic = document.getElementById('aiTopic').value;
    const classLevel = document.getElementById('aiClass').value;
    const count = document.getElementById('aiCount').value;
    const generateBtn = document.getElementById('aiGenerateBtn');
    const statusSpan = document.getElementById('aiStatus');

    // Fallback if class level is empty
    if (!classLevel || classLevel === '') {
        classLevel = 'Not specified';
        console.log('Class level was empty, using fallback:', classLevel);
    }

    if (!subject || !topic) {
        alert('Subject and topic are required');
        return;
    }

    generateBtn.disabled = true;
    generateBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
    statusSpan.innerHTML = 'Generating questions with AI... This may take 20-30 seconds.';

    const url = SELF + `&ajax=ai_generate&subject_name=${encodeURIComponent(subject)}&topic_name=${encodeURIComponent(topic)}&class_level=${encodeURIComponent(classLevel)}&count=${count}`;

    fetch(url)
        .then(res => res.json())
        .then(data => {
            generateBtn.disabled = false;
            generateBtn.innerHTML = '<i class="fas fa-magic"></i> Generate Questions Preview';
            
            if (data.success && data.questions && data.questions.length) {
                aiGeneratedQuestions = data.questions;
                aiSelected.clear();
                renderAIPreview();
                statusSpan.innerHTML = `✓ Generated ${data.questions.length} questions. ${data.mock ? '(Mock mode - Add Groq API key for real AI)' : '(AI Generated)'}`;
            } else {
                statusSpan.innerHTML = '❌ ' + (data.error || 'Failed to generate questions');
                alert('Error: ' + (data.error || 'Failed to generate questions'));
            }
        })
        .catch(err => {
            generateBtn.disabled = false;
            generateBtn.innerHTML = '<i class="fas fa-magic"></i> Generate Questions Preview';
            statusSpan.innerHTML = '❌ Network error';
            alert('Network error: ' + err.message);
        });
}

function renderAIPreview() {
    const container = document.getElementById('aiQuestionsList');
    const previewBox = document.getElementById('aiPreviewBox');
    const importBtn = document.getElementById('aiImportBtn');
    
    let html = '';
    aiGeneratedQuestions.forEach((q, i) => {
        html += `
            <div class="question-item">
                <input type="checkbox" class="ai-chk" value="${i}" onchange="aiCheck(this)">
                <div class="question-text">
                    <strong>Q${i+1}:</strong> ${esc(q.question)}<br>
                    <small style="color:#666;">A) ${esc(q.a)} | B) ${esc(q.b)} | C) ${esc(q.c)} | D) ${esc(q.d)}<br>
                    <strong>Answer:</strong> ${q.answer} | <strong>Explanation:</strong> ${esc(q.explanation || 'N/A')}</small>
                    <span class="badge badge-ai"><i class="fas fa-robot"></i> AI Generated</span>
                </div>
            </div>`;
    });
    
    container.innerHTML = html;
    previewBox.style.display = 'block';
    importBtn.style.display = 'inline-flex';
    updateAICount();
}

function aiCheck(cb) {
    const idx = parseInt(cb.value);
    cb.checked ? aiSelected.add(idx) : aiSelected.delete(idx);
    updateAICount();
}

function aiToggleAll(src) {
    document.querySelectorAll('.ai-chk').forEach(cb => {
        cb.checked = src.checked;
        src.checked ? aiSelected.add(parseInt(cb.value)) : aiSelected.delete(parseInt(cb.value));
    });
    updateAICount();
}

function updateAICount() {
    const el = document.getElementById('aiSelectedCount');
    if (el) el.textContent = aiSelected.size;
}

function submitAIImport() {
    if (!aiSelected.size) {
        alert('Please select at least one AI-generated question.');
        return;
    }
    
    const selectedQuestions = [];
    aiSelected.forEach(idx => {
        selectedQuestions.push(aiGeneratedQuestions[idx]);
    });
    
    if (!confirm(`Import ${selectedQuestions.length} AI-generated question(s) into "<?php echo addslashes($selected_topic['topic_name']); ?>"?`)) return;
    
    const form = document.createElement('form');
    form.method = 'POST'; form.action = '';
    form.innerHTML = `
        <input type="hidden" name="import_ai_questions" value="1">
        <input type="hidden" name="ai_questions_data" value='${JSON.stringify(selectedQuestions)}'>
    `;
    document.body.appendChild(form);
    form.submit();
}
</script>
</body>
</html>
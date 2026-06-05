<?php
ob_start();
// admin/add_questions.php
// Central bank questions + WAEC import + AI Generative Questions

session_start();

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /ida/login.php");
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
$page_title    = "Add Questions";

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
            $selected_topic['class'] = 'Not specified';

            $cs = $pdo->prepare("SELECT class_name FROM classes WHERE school_id = ? LIMIT 1");
            $cs->execute([$school_id]);
            $cr = $cs->fetch();
            if ($cr && !empty($cr['class_name'])) {
                $selected_topic['class'] = $cr['class_name'];
            }
        }
    } catch (Exception $e) {
        error_log("Error loading topic: " . $e->getMessage());
    }
}
if (!$selected_topic && !(isset($_GET['ajax']) && in_array($_GET['ajax'], ['cb_subjects', 'cb_topics', 'cb_questions', 'waec_subjects', 'waec_topics', 'waec_questions', 'ai_generate']))) {
    header("Location: manage-questions.php?error=invalid_topic");
    exit();
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
// ADD SINGLE QUESTION HANDLERS
// ============================================================

// Add Objective Question
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_objective_question'])) {
    try {
        $question_text = trim($_POST['question_text']);
        $option_a = trim($_POST['option_a']);
        $option_b = trim($_POST['option_b']);
        $option_c = trim($_POST['option_c'] ?? '');
        $option_d = trim($_POST['option_d'] ?? '');
        $correct_answer = $_POST['correct_answer'];
        $difficulty_level = $_POST['difficulty_level'] ?? 'medium';
        $marks = (int)$_POST['marks'];

        if (empty($question_text)) throw new Exception("Question text is required");
        if (empty($option_a) || empty($option_b)) throw new Exception("At least options A and B are required");
        if (empty($correct_answer)) throw new Exception("Correct answer is required");

        $stmt = $pdo->prepare("INSERT INTO objective_questions 
            (question_text, option_a, option_b, option_c, option_d, correct_answer, 
             difficulty_level, marks, subject_id, topic_id, class, school_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $question_text,
            $option_a,
            $option_b,
            $option_c,
            $option_d,
            $correct_answer,
            $difficulty_level,
            $marks,
            $selected_topic['subject_id'],
            $topic_id,
            $selected_topic['class'],
            $school_id
        ]);

        $message = "Objective question added successfully!";
        $message_type = "success";

        if (!isset($_POST['stay_here'])) {
            header("Location: manage-questions.php?topic_id=$topic_id&type=objective");
            exit();
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// Add Subjective Question
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_subjective_question'])) {
    try {
        $question_text = trim($_POST['question_text']);
        $correct_answer = trim($_POST['correct_answer']);
        $difficulty_level = $_POST['difficulty_level'] ?? 'medium';
        $marks = (int)$_POST['marks'];

        if (empty($question_text)) throw new Exception("Question text is required");

        $stmt = $pdo->prepare("INSERT INTO subjective_questions 
            (question_text, correct_answer, difficulty_level, marks, subject_id, topic_id, class, school_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $question_text,
            $correct_answer,
            $difficulty_level,
            $marks,
            $selected_topic['subject_id'],
            $topic_id,
            $selected_topic['class'],
            $school_id
        ]);

        $message = "Subjective question added successfully!";
        $message_type = "success";

        if (!isset($_POST['stay_here'])) {
            header("Location: manage-questions.php?topic_id=$topic_id&type=subjective");
            exit();
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// Add Theory Question
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_theory_question'])) {
    try {
        $question_text = trim($_POST['question_text']);
        $marks = (int)$_POST['marks'];
        $difficulty_level = $_POST['difficulty_level'] ?? 'medium';

        if (empty($question_text)) throw new Exception("Question text is required");

        $question_file = null;
        if (isset($_FILES['question_file']) && $_FILES['question_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/questions/';
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
            $ext = pathinfo($_FILES['question_file']['name'], PATHINFO_EXTENSION);
            $filename = 'theory_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            move_uploaded_file($_FILES['question_file']['tmp_name'], $upload_dir . $filename);
            $question_file = 'uploads/questions/' . $filename;
        }

        $stmt = $pdo->prepare("INSERT INTO theory_questions 
            (question_text, question_file, difficulty_level, marks, subject_id, topic_id, class, school_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $question_text,
            $question_file,
            $difficulty_level,
            $marks,
            $selected_topic['subject_id'],
            $topic_id,
            $selected_topic['class'],
            $school_id
        ]);

        $message = "Theory question added successfully!";
        $message_type = "success";

        if (!isset($_POST['stay_here'])) {
            header("Location: manage-questions.php?topic_id=$topic_id&type=theory");
            exit();
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// ============================================================
// BULK CSV IMPORT HANDLER
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_csv_import'])) {
    try {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Please upload a valid CSV file");
        }

        $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $headers = fgetcsv($file);

        // Validate headers
        $expected_headers = ['question_text', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_answer', 'marks', 'difficulty'];
        if (!$headers || count(array_intersect($expected_headers, array_map('strtolower', $headers))) < 5) {
            throw new Exception("Invalid CSV format. Please use the template.");
        }

        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($file)) !== false) {
            $data = array_combine($headers, $row);
            if (empty($data['question_text']) || empty($data['option_a']) || empty($data['option_b'])) {
                $skipped++;
                continue;
            }

            $stmt = $pdo->prepare("INSERT INTO objective_questions 
                (question_text, option_a, option_b, option_c, option_d, correct_answer, 
                 marks, difficulty_level, subject_id, topic_id, class, school_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $data['question_text'],
                $data['option_a'],
                $data['option_b'],
                $data['option_c'] ?? '',
                $data['option_d'] ?? '',
                $data['correct_answer'] ?? 'A',
                (int)($data['marks'] ?? 1),
                $data['difficulty'] ?? 'medium',
                $selected_topic['subject_id'],
                $topic_id,
                $selected_topic['class'],
                $school_id
            ]);
            $imported++;
        }

        fclose($file);
        $message = "✓ Successfully imported $imported questions. Skipped: $skipped";
        $message_type = "success";
    } catch (Exception $e) {
        $message = "Import error: " . $e->getMessage();
        $message_type = "error";
    }
}

// ============================================================
// Groq AI Function
// ============================================================
function callGroqForQuestions($subject_name, $topic_name, $class_level, $count)
{
    $api_key = GROQ_API_KEY ?? '';
    $model = GROQ_DEFAULT_MODEL ?? 'mixtral-8x7b-32768';
    $api_url = GROQ_API_URL ?? 'https://api.groq.com/openai/v1/chat/completions';

    if (empty($api_key) && !GROQ_USE_MOCK) {
        return ['success' => false, 'error' => 'Groq API key not configured'];
    }

    $prompt = "Generate exactly $count multiple-choice questions for $subject_name topic: '$topic_name' for $class_level students (Nigerian curriculum).\n\nReturn ONLY a valid JSON array. No explanations before or after. No markdown formatting.\n\nEach question must have this exact structure:\n{\n  \"question\": \"The question text?\",\n  \"a\": \"Option A text\",\n  \"b\": \"Option B text\",\n  \"c\": \"Option C text\",\n  \"d\": \"Option D text\",\n  \"answer\": \"A\",\n  \"explanation\": \"Brief explanation\"\n}\n\nThe JSON array should look like: [{...}, {...}, ...]\n\nGenerate $count questions now. Return ONLY the JSON array:";

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

function getMockAIQuestions($count)
{
    $questions = [];
    for ($i = 1; $i <= $count; $i++) {
        $questions[] = [
            'question' => "Sample AI-generated question #$i. This is a mock question for testing purposes.",
            'a' => 'First option',
            'b' => 'Second option',
            'c' => 'Third option',
            'd' => 'Fourth option',
            'answer' => 'A',
            'explanation' => "This is a mock explanation for question #$i."
        ];
    }
    return ['success' => true, 'questions' => $questions];
}

// ============================================================
// AJAX HANDLERS
// ============================================================

// WAEC Subjects
if (isset($_GET['ajax']) && $_GET['ajax'] === 'waec_subjects') {
    ob_clean();
    header('Content-Type: application/json');
    try {
        $rows = $pdo->query("SELECT id, subject_name, subject_code FROM waec_subjects WHERE is_active = 1 ORDER BY subject_name")->fetchAll();
        echo json_encode(['success' => true, 'subjects' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// WAEC Topics
if (isset($_GET['ajax']) && $_GET['ajax'] === 'waec_topics') {
    ob_clean();
    header('Content-Type: application/json');
    $sid = (int)($_GET['subject_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT id, topic_name FROM waec_topics WHERE waec_subject_id = ? AND is_active = 1 ORDER BY topic_name");
        $stmt->execute([$sid]);
        echo json_encode(['success' => true, 'topics' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// WAEC Questions
if (isset($_GET['ajax']) && $_GET['ajax'] === 'waec_questions') {
    ob_clean();
    header('Content-Type: application/json');
    $tid = (int)($_GET['topic_id'] ?? 0);
    $year = (int)($_GET['year'] ?? 0);

    try {
        $sql = "SELECT wq.*, ws.subject_name FROM waec_questions wq JOIN waec_subjects ws ON wq.waec_subject_id = ws.id WHERE wq.is_active = 1";
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

        echo json_encode(['success' => true, 'questions' => $questions]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// AI Generate
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

// AI Import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_ai_questions'])) {
    try {
        $questions = json_decode($_POST['ai_questions_data'] ?? '[]', true);
        if (empty($questions)) throw new Exception("No questions to import");

        $imported = 0;
        foreach ($questions as $q) {
            $stmt = $pdo->prepare("INSERT INTO objective_questions 
                (question_text, option_a, option_b, option_c, option_d, correct_answer, 
                 difficulty_level, marks, subject_id, topic_id, class, school_id, source_type, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ai', NOW())");
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
                $school_id
            ]);
            $imported++;
        }

        $message = "✓ Successfully imported $imported AI-generated question(s)!";
        $message_type = 'success';
        header("Location: manage-questions.php?topic_id=$topic_id&type=objective&message=" . urlencode($message));
        exit();
    } catch (Exception $e) {
        $message = "Import error: " . $e->getMessage();
        $message_type = 'error';
    }
}

// WAEC Import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_waec_questions'])) {
    try {
        $selected_ids = $_POST['selected_waec_questions'] ?? [];
        if (empty($selected_ids)) throw new Exception("No questions selected for import.");

        $imported = 0;
        foreach ($selected_ids as $wid) {
            $wid = (int)$wid;
            $src = $pdo->prepare("SELECT * FROM waec_questions WHERE id = ? AND is_active = 1 LIMIT 1");
            $src->execute([$wid]);
            $q = $src->fetch();
            if (!$q) continue;

            $stmt = $pdo->prepare("INSERT INTO objective_questions 
                (question_text, option_a, option_b, option_c, option_d, correct_answer, 
                 difficulty_level, marks, subject_id, topic_id, class, school_id, source_type, central_source_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'waec', ?, NOW())");
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
                $wid
            ]);
            $imported++;
        }

        $message = "✓ Successfully imported $imported WAEC question(s)!";
        $message_type = 'success';
        header("Location: manage-questions.php?topic_id=$topic_id&type=objective&message=" . urlencode($message));
        exit();
    } catch (Exception $e) {
        $message = "Import error: " . $e->getMessage();
        $message_type = 'error';
    }
}

// CSV Template Download
if (isset($_GET['download_csv_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="questions_template.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['question_text', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_answer', 'marks', 'difficulty']);
    fputcsv($output, ['What is the capital of Nigeria?', 'Lagos', 'Abuja', 'Kano', 'Ibadan', 'B', '1', 'easy']);
    fputcsv($output, ['Who was the first President of Nigeria?', 'Olusegun Obasanjo', 'Nnamdi Azikiwe', 'Goodluck Jonathan', 'Muhammadu Buhari', 'B', '1', 'easy']);
    fclose($output);
    exit();
}

// Include sidebar
require_once 'includes/sidebar.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($school_name); ?> – Add Questions</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --primary-dark: #1a5a8a;
            --secondary: #3498db;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --purple: #8e44ad;
            --orange: #d35400;
            --gray-50: #f9fafb;
            --gray-100: #f0f2f5;
            --gray-200: #e4e7eb;
            --gray-300: #d1d5db;
            --gray-600: #6b7280;
            --gray-800: #1f2937;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 16px;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--gray-100);
            color: var(--gray-800);
            min-height: 100vh;
        }

        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        /* Top Header */
        .top-header {
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            box-shadow: var(--shadow-sm);
        }

        .header-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 4px;
        }

        .header-title p {
            color: var(--gray-600);
            font-size: 0.8rem;
        }

        /* Buttons */
        .btn {
            padding: 10px 18px;
            border-radius: var(--radius-md);
            border: none;
            font-weight: 500;
            font-size: 0.8rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.7rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-secondary {
            background: var(--gray-600);
            color: white;
        }

        .btn-purple {
            background: var(--purple);
            color: white;
        }

        .btn-orange {
            background: var(--orange);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--gray-300);
            color: var(--gray-800);
        }

        /* Topic Info Card */
        .topic-info-card {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            padding: 20px 25px;
            border-radius: var(--radius-lg);
            margin-bottom: 24px;
        }

        .topic-info-card h2 {
            margin-bottom: 8px;
        }

        .topic-meta {
            display: flex;
            gap: 15px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        .meta-item {
            background: rgba(255, 255, 255, 0.15);
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
        }

        .meta-item a {
            color: white;
            text-decoration: none;
        }

        .meta-item a:hover {
            text-decoration: underline;
        }

        /* Tabs */
        .tabs-navigation {
            background: white;
            border-radius: var(--radius-lg);
            margin-bottom: 24px;
            overflow: hidden;
        }

        .tab-buttons {
            display: flex;
            background: var(--gray-50);
            border-bottom: 2px solid var(--gray-200);
            flex-wrap: wrap;
        }

        .tab-button {
            flex: 1;
            padding: 14px 20px;
            border: none;
            background: none;
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--gray-600);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.2s;
        }

        .tab-button:hover {
            background: var(--gray-100);
        }

        .tab-button.active {
            color: var(--primary-color);
            border-bottom: 3px solid var(--primary-color);
            background: white;
        }

        .tab-content {
            display: none;
            padding: 25px;
        }

        .tab-content.active {
            display: block;
        }

        /* Form Section */
        .form-section {
            background: white;
            border-radius: var(--radius-lg);
            padding: 25px;
            box-shadow: var(--shadow-sm);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 0.85rem;
            color: var(--gray-800);
        }

        .form-control,
        .form-select {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 0.85rem;
            transition: all 0.2s;
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .options-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .option-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .option-label {
            font-weight: 700;
            color: var(--primary-color);
            min-width: 32px;
            font-size: 1rem;
        }

        .option-input {
            flex: 1;
        }

        .form-row-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-top: 10px;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        /* Alert */
        .alert {
            padding: 14px 18px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.85rem;
        }

        .alert-success {
            background: #d5f4e6;
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .alert-error {
            background: #fbe9e7;
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 900px;
            max-height: 85vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 18px 22px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
        }

        .modal-header h3 {
            font-size: 1.1rem;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--gray-600);
        }

        .modal-body {
            padding: 22px;
        }

        .modal-footer {
            padding: 16px 22px;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        /* Import Sections */
        .import-step {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .import-step .form-group {
            flex: 1;
            min-width: 150px;
            margin: 0;
        }

        .question-list {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-md);
        }

        .question-item {
            padding: 12px 15px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .question-item:last-child {
            border-bottom: none;
        }

        .question-item:hover {
            background: var(--gray-50);
        }

        .question-text {
            flex: 1;
            font-size: 0.85rem;
            line-height: 1.45;
        }

        .select-all-bar {
            background: var(--gray-50);
            padding: 10px 15px;
            border-radius: var(--radius-md);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
            margin-left: 8px;
        }

        .badge-waec {
            background: #fff3e0;
            color: var(--orange);
        }

        .badge-ai {
            background: #f3e5f5;
            color: var(--purple);
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: var(--gray-600);
        }

        .spinner {
            width: 38px;
            height: 38px;
            border: 4px solid var(--gray-200);
            border-top-color: var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 12px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }

            .top-header {
                flex-direction: column;
                text-align: center;
            }

            .tab-buttons {
                flex-direction: column;
            }

            .options-grid,
            .form-row-3 {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>

<body>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-plus-circle"></i> Add Questions</h1>
                <p>Topic: <strong><?php echo htmlspecialchars($selected_topic['topic_name'] ?? 'Unknown'); ?></strong> |
                    Subject: <?php echo htmlspecialchars($selected_topic['subject_name'] ?? 'Unknown'); ?> |
                    Class: <?php echo htmlspecialchars($selected_topic['class'] ?? 'Not specified'); ?></p>
            </div>
            <a href="manage-questions.php?topic_id=<?php echo $topic_id; ?>" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Back to Questions
            </a>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Import Options Bar -->
        <div class="topic-info-card">
            <h2><i class="fas fa-tools"></i> Question Import Options</h2>
            <div class="topic-meta">
                <span class="meta-item"><i class="fas fa-file-csv"></i> <a href="javascript:void(0)" onclick="openCSVModal()">Bulk CSV Import</a></span>
                <span class="meta-item"><i class="fas fa-graduation-cap"></i> <a href="javascript:void(0)" onclick="openWAECModal()">WAEC Question Bank</a></span>
                <span class="meta-item"><i class="fas fa-robot"></i> <a href="javascript:void(0)" onclick="openAIModal()">AI Generate Questions</a></span>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs-navigation">
            <div class="tab-buttons">
                <button class="tab-button <?php echo $question_type === 'objective' ? 'active' : ''; ?>" onclick="switchTab('objective')">
                    <i class="fas fa-check-circle"></i> Objective
                </button>
                <button class="tab-button <?php echo $question_type === 'subjective' ? 'active' : ''; ?>" onclick="switchTab('subjective')">
                    <i class="fas fa-edit"></i> Subjective
                </button>
                <button class="tab-button <?php echo $question_type === 'theory' ? 'active' : ''; ?>" onclick="switchTab('theory')">
                    <i class="fas fa-file-alt"></i> Theory
                </button>
            </div>

            <!-- Objective Tab -->
            <div class="tab-content <?php echo $question_type === 'objective' ? 'active' : ''; ?>" id="objectiveTab">
                <div class="form-section">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label>Question Text *</label>
                            <textarea name="question_text" class="form-control" rows="4" placeholder="Enter the question here..." required><?php echo htmlspecialchars($_POST['question_text'] ?? ''); ?></textarea>
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
                                <input type="text" name="option_c" class="form-control option-input" placeholder="Option C" value="<?php echo htmlspecialchars($_POST['option_c'] ?? ''); ?>">
                            </div>
                            <div class="option-group">
                                <span class="option-label">D)</span>
                                <input type="text" name="option_d" class="form-control option-input" placeholder="Option D" value="<?php echo htmlspecialchars($_POST['option_d'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-row-3">
                            <div class="form-group">
                                <label>Correct Answer *</label>
                                <select name="correct_answer" class="form-select" required>
                                    <option value="">Select</option>
                                    <?php foreach (['A', 'B', 'C', 'D'] as $o): ?>
                                        <option value="<?php echo $o; ?>"><?php echo $o; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Difficulty Level</label>
                                <select name="difficulty_level" class="form-select">
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
                            <button type="reset" class="btn btn-outline"><i class="fas fa-redo"></i> Clear</button>
                            <button type="submit" name="add_objective_question" class="btn btn-primary"><i class="fas fa-save"></i> Add Objective Question</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Subjective Tab -->
            <div class="tab-content <?php echo $question_type === 'subjective' ? 'active' : ''; ?>" id="subjectiveTab">
                <div class="form-section">
                    <form method="POST">
                        <div class="form-group">
                            <label>Question Text *</label>
                            <textarea name="question_text" class="form-control" rows="4" placeholder="Enter the question here..." required><?php echo htmlspecialchars($_POST['question_text'] ?? ''); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label>Model Answer / Marking Guide</label>
                            <textarea name="correct_answer" class="form-control" rows="3" placeholder="Enter the expected answer or marking guide..."><?php echo htmlspecialchars($_POST['correct_answer'] ?? ''); ?></textarea>
                        </div>
                        <div class="form-row-3">
                            <div class="form-group">
                                <label>Difficulty Level</label>
                                <select name="difficulty_level" class="form-select">
                                    <option value="easy">Easy</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="hard">Hard</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Marks</label>
                                <input type="number" name="marks" class="form-control" value="5" min="1" max="20">
                            </div>
                        </div>
                        <div class="checkbox-group">
                            <label><input type="checkbox" name="stay_here" value="1"> Stay on this page after adding</label>
                        </div>
                        <div class="form-actions">
                            <button type="reset" class="btn btn-outline"><i class="fas fa-redo"></i> Clear</button>
                            <button type="submit" name="add_subjective_question" class="btn btn-primary"><i class="fas fa-save"></i> Add Subjective Question</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Theory Tab -->
            <div class="tab-content <?php echo $question_type === 'theory' ? 'active' : ''; ?>" id="theoryTab">
                <div class="form-section">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label>Question Text</label>
                            <textarea name="question_text" class="form-control" rows="4" placeholder="Enter the question text (or upload a file)"><?php echo htmlspecialchars($_POST['question_text'] ?? ''); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label>Attachment (PDF/DOC/Image)</label>
                            <input type="file" name="question_file" class="form-control" accept=".pdf,.doc,.docx,.jpg,.png">
                            <small style="color: var(--gray-600);">Optional - upload a file with the question content</small>
                        </div>
                        <div class="form-row-3">
                            <div class="form-group">
                                <label>Difficulty Level</label>
                                <select name="difficulty_level" class="form-select">
                                    <option value="easy">Easy</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="hard">Hard</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Marks</label>
                                <input type="number" name="marks" class="form-control" value="10" min="1" max="30">
                            </div>
                        </div>
                        <div class="checkbox-group">
                            <label><input type="checkbox" name="stay_here" value="1"> Stay on this page after adding</label>
                        </div>
                        <div class="form-actions">
                            <button type="reset" class="btn btn-outline"><i class="fas fa-redo"></i> Clear</button>
                            <button type="submit" name="add_theory_question" class="btn btn-primary"><i class="fas fa-save"></i> Add Theory Question</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- CSV IMPORT MODAL -->
    <!-- ============================================================ -->
    <div class="modal" id="csvModal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3><i class="fas fa-file-csv"></i> Bulk CSV Import</h3>
                <button class="close-modal" onclick="closeModal('csvModal')">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="alert alert-info" style="background: #eaf6ff; border-left-color: var(--secondary); margin-bottom: 20px;">
                        <i class="fas fa-info-circle"></i>
                        Upload a CSV file with your questions. Each row should contain: question_text, option_a, option_b, option_c, option_d, correct_answer, marks, difficulty
                    </div>
                    <div class="form-group">
                        <label>CSV File</label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                    </div>
                    <div style="margin-top: 15px;">
                        <a href="?download_csv_template=1" class="btn btn-outline btn-sm" target="_blank">
                            <i class="fas fa-download"></i> Download Template CSV
                        </a>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('csvModal')">Cancel</button>
                    <button type="submit" name="bulk_csv_import" class="btn btn-primary"><i class="fas fa-upload"></i> Import CSV</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- WAEC BANK IMPORT MODAL -->
    <!-- ============================================================ -->
    <div class="modal" id="waecModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-graduation-cap" style="color: var(--orange);"></i> Import from WAEC Bank</h3>
                <button class="close-modal" onclick="closeModal('waecModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="waecLoading" class="loading">
                    <div class="spinner"></div>
                    <p>Loading WAEC subjects...</p>
                </div>
                <div id="waecContent" style="display:none;">
                    <div class="import-step">
                        <div class="form-group">
                            <label>WAEC Subject</label>
                            <select id="waecSubject" class="form-select" onchange="loadWAECTopics()">
                                <option value="">-- Select Subject --</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>WAEC Topic</label>
                            <select id="waecTopic" class="form-select" onchange="loadWAECQuestions()">
                                <option value="">-- Select Topic --</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Year (Optional)</label>
                            <select id="waecYear" class="form-select" onchange="loadWAECQuestions()">
                                <option value="0">All Years</option>
                                <?php for ($y = date('Y'); $y >= 2010; $y--): ?>
                                    <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div id="waecQuestionsBox">
                        <div class="loading">
                            <p>Select a subject and topic to browse WAEC questions...</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('waecModal')">Cancel</button>
                <button class="btn btn-orange" onclick="submitWAECImport()"><i class="fas fa-file-import"></i> Import Selected</button>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- AI GENERATIVE MODAL -->
    <!-- ============================================================ -->
    <div class="modal" id="aiModal">
        <div class="modal-content" style="max-width: 950px;">
            <div class="modal-header">
                <h3><i class="fas fa-robot" style="color: var(--purple);"></i> AI Generate Questions</h3>
                <button class="close-modal" onclick="closeModal('aiModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="import-step">
                    <div class="form-group">
                        <label>Subject</label>
                        <input type="text" id="aiSubject" class="form-control" value="<?php echo htmlspecialchars($selected_topic['subject_name']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Topic</label>
                        <input type="text" id="aiTopic" class="form-control" value="<?php echo htmlspecialchars($selected_topic['topic_name']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Class Level</label>
                        <input type="text" id="aiClass" class="form-control" value="<?php echo htmlspecialchars($selected_topic['class']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Number of Questions</label>
                        <select id="aiCount" class="form-select">
                            <option value="5">5 Questions</option>
                            <option value="10" selected>10 Questions</option>
                            <option value="15">15 Questions</option>
                            <option value="20">20 Questions</option>
                        </select>
                    </div>
                </div>
                <div style="margin-bottom: 20px;">
                    <button class="btn btn-purple" onclick="generateAIPreview()" id="aiGenerateBtn">
                        <i class="fas fa-magic"></i> Generate Questions Preview
                    </button>
                    <span id="aiStatus" style="margin-left: 12px; font-size: 0.8rem; color: var(--gray-600);"></span>
                </div>
                <div id="aiPreviewBox" style="display:none;">
                    <div class="select-all-bar">
                        <input type="checkbox" id="aiSelectAll" onchange="aiToggleAll(this)">
                        <label for="aiSelectAll" style="font-weight: 500; cursor: pointer;">Select All</label>
                        <span style="margin-left: auto; font-size: 0.85rem;"><span id="aiSelectedCount">0</span> selected</span>
                    </div>
                    <div id="aiQuestionsList" class="question-list"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('aiModal')">Cancel</button>
                <button class="btn btn-purple" onclick="submitAIImport()" id="aiImportBtn" style="display:none;"><i class="fas fa-file-import"></i> Import Selected</button>
            </div>
        </div>
    </div>

    <script>
        // Tab switching
        function switchTab(tabName) {
            const url = new URL(window.location);
            url.searchParams.set('type', tabName);
            window.history.pushState({}, '', url);
            document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            document.querySelector(`.tab-button[onclick="switchTab('${tabName}')"]`).classList.add('active');
            document.getElementById(tabName + 'Tab').classList.add('active');
        }

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = '';
        }

        function openCSVModal() {
            openModal('csvModal');
        }

        function openWAECModal() {
            openModal('waecModal');
            loadWAECSubjects();
        }

        function openAIModal() {
            openModal('aiModal');
        }

        // CSV Template Download
        function downloadCSVTemplate() {
            window.location.href = '?download_csv_template=1';
        }

        // ============================================================
        // WAEC MODAL FUNCTIONS
        // ============================================================
        let waecQuestions = [];
        let waecSelected = new Set();

        function loadWAECSubjects() {
            const loading = document.getElementById('waecLoading');
            const content = document.getElementById('waecContent');
            loading.style.display = 'block';
            content.style.display = 'none';

            fetch(`?ajax=waec_subjects`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const sel = document.getElementById('waecSubject');
                        sel.innerHTML = '<option value="">-- Select Subject --</option>' +
                            data.subjects.map(s => `<option value="${s.id}">${escapeHtml(s.subject_name)} (${s.subject_code || ''})</option>`).join('');
                        loading.style.display = 'none';
                        content.style.display = 'block';
                    } else {
                        throw new Error(data.error || 'No subjects found');
                    }
                })
                .catch(err => {
                    loading.innerHTML = `<div class="alert alert-error">Error: ${escapeHtml(err.message)}</div>`;
                });
        }

        function loadWAECTopics() {
            const sid = document.getElementById('waecSubject').value;
            const sel = document.getElementById('waecTopic');
            sel.innerHTML = '<option value="">Loading...</option>';
            if (!sid) {
                sel.innerHTML = '<option value="">-- Select Topic --</option>';
                document.getElementById('waecQuestionsBox').innerHTML = '<div class="loading"><p>Select a subject to browse WAEC questions...</p></div>';
                return;
            }
            fetch(`?ajax=waec_topics&subject_id=${sid}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.topics.length) {
                        sel.innerHTML = '<option value="0">-- All Topics --</option>' +
                            data.topics.map(t => `<option value="${t.id}">${escapeHtml(t.topic_name)}</option>`).join('');
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
                box.innerHTML = '<div class="loading"><p>Select a topic to load questions...</p></div>';
                return;
            }
            waecSelected.clear();
            box.innerHTML = '<div class="loading"><div class="spinner"></div><p>Loading WAEC questions...</p></div>';
            let url = `?ajax=waec_questions&topic_id=${tid}`;
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
                    box.innerHTML = `<div class="alert alert-error">Error: ${escapeHtml(err.message)}</div>`;
                });
        }

        function renderWAECQuestions() {
            const box = document.getElementById('waecQuestionsBox');
            let html = `
        <div class="select-all-bar">
            <input type="checkbox" id="waecSelectAll" onchange="waecToggleAll(this)">
            <label for="waecSelectAll" style="font-weight:500;">Select All</label>
            <span style="margin-left:auto;"><span id="waecCount">0</span> of ${waecQuestions.length} selected</span>
        </div>
        <div class="question-list">`;
            waecQuestions.forEach((q, i) => {
                const preview = (q.question_text || '').substring(0, 120);
                html += `
            <div class="question-item">
                <input type="checkbox" class="waec-chk" value="${q.id}" onchange="waecCheck(this)">
                <div class="question-text">
                    <strong>WAEC ${q.exam_year || 'N/A'}:</strong> ${escapeHtml(preview)}${q.question_text?.length > 120 ? '…' : ''}
                    <span class="badge badge-waec"><i class="fas fa-graduation-cap"></i> WAEC Bank</span>
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
            document.querySelectorAll('.waec-chk').forEach(cb => {
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
            if (!confirm(`Import ${waecSelected.size} WAEC question(s) into "${escapeHtml('<?php echo addslashes($selected_topic['topic_name']); ?>')}"?`)) return;
            const form = document.createElement('form');
            form.method = 'POST';
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'import_waec_questions';
            input.value = '1';
            form.appendChild(input);
            waecSelected.forEach(id => {
                const i = document.createElement('input');
                i.type = 'hidden';
                i.name = 'selected_waec_questions[]';
                i.value = id;
                form.appendChild(i);
            });
            document.body.appendChild(form);
            form.submit();
        }

        // ============================================================
        // AI MODAL FUNCTIONS
        // ============================================================
        let aiGeneratedQuestions = [];
        let aiSelected = new Set();

        function generateAIPreview() {
            const subject = document.getElementById('aiSubject').value;
            const topic = document.getElementById('aiTopic').value;
            const classLevel = document.getElementById('aiClass').value;
            const count = document.getElementById('aiCount').value;
            const generateBtn = document.getElementById('aiGenerateBtn');
            const statusSpan = document.getElementById('aiStatus');

            generateBtn.disabled = true;
            generateBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
            statusSpan.innerHTML = 'Generating questions with AI... This may take 20-30 seconds.';

            fetch(`?ajax=ai_generate&subject_name=${encodeURIComponent(subject)}&topic_name=${encodeURIComponent(topic)}&class_level=${encodeURIComponent(classLevel)}&count=${count}`)
                .then(res => res.json())
                .then(data => {
                    generateBtn.disabled = false;
                    generateBtn.innerHTML = '<i class="fas fa-magic"></i> Generate Questions Preview';
                    if (data.success && data.questions && data.questions.length) {
                        aiGeneratedQuestions = data.questions;
                        aiSelected.clear();
                        renderAIPreview();
                        statusSpan.innerHTML = `✓ Generated ${data.questions.length} questions.`;
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
                    <strong>Q${i+1}:</strong> ${escapeHtml(q.question)}<br>
                    <small style="color:#666;">A) ${escapeHtml(q.a)} | B) ${escapeHtml(q.b)} | C) ${escapeHtml(q.c)} | D) ${escapeHtml(q.d)}<br>
                    <strong>Answer:</strong> ${q.answer} | <strong>Explanation:</strong> ${escapeHtml(q.explanation || 'N/A')}</small>
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
            aiSelected.forEach(idx => selectedQuestions.push(aiGeneratedQuestions[idx]));
            if (!confirm(`Import ${selectedQuestions.length} AI-generated question(s) into "${escapeHtml('<?php echo addslashes($selected_topic['topic_name']); ?>')}"?`)) return;
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
        <input type="hidden" name="import_ai_questions" value="1">
        <input type="hidden" name="ai_questions_data" value='${JSON.stringify(selectedQuestions)}'>
    `;
            document.body.appendChild(form);
            form.submit();
        }

        // Helper functions
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close modals on outside click
        window.onclick = function(event) {
            if (event.target.classList && event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
                document.body.style.overflow = '';
            }
        };

        // Close modal on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal').forEach(modal => {
                    modal.classList.remove('active');
                });
                document.body.style.overflow = '';
            }
        });
    </script>
</body>

</html>
<?php
ob_start();
// admin/add_questions.php
// Central bank questions: is_central = 1, school_id IS NULL — same DB, no API needed

session_start();

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /msv/login.php");
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
            $cs = $pdo->prepare("SELECT class FROM subject_classes WHERE subject_id = ? AND school_id = ? LIMIT 1");
            $cs->execute([$selected_topic['subject_id'], $school_id]);
            $cr = $cs->fetch();
            $selected_topic['class'] = $cr['class'] ?? 'N/A';
        }
    } catch (Exception $e) {
        error_log("Error loading topic: " . $e->getMessage());
    }
}
if (!$selected_topic) {
    // Allow AJAX calls through even without a valid topic
    if (isset($_GET['ajax']) && $_GET['ajax'] === 'cb_questions') {
    ob_clean();
    header('Content-Type: application/json');
    $sid   = (int)($_GET['subject_id'] ?? 0);
    $tid   = (int)($_GET['topic_id']   ?? 0);
    $qtype = in_array($_GET['type'] ?? '', ['objective','subjective','theory'])
             ? $_GET['type'] : 'objective';
    $table = $qtype . '_questions';

    try {
        // Use direct interpolation since values are already cast to int
        $sql = "SELECT * FROM `$table` 
                WHERE is_central = 1 AND school_id IS NULL AND topic_id = $tid
                ORDER BY id LIMIT 500";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        // Mark already-imported ones
        $imported = [];
        if (!empty($rows)) {
            $ids = array_column($rows, 'id');
            $placeholders = implode(',', $ids); // safe, all ints
            $chk = $pdo->query("
                SELECT central_source_id FROM `$table`
                WHERE central_source_id IN ($placeholders)
                  AND topic_id = $topic_id AND school_id = '$school_id'
            ");
            $imported = array_column($chk->fetchAll(PDO::FETCH_ASSOC), 'central_source_id');
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
    }
} catch (Exception $e) {
    error_log("Column check: " . $e->getMessage());
}

// ============================================================
// AJAX: fetch central subjects  GET ?ajax=cb_subjects
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'cb_subjects') {
    ob_clean();
    header('Content-Type: application/json');
    error_log("=== cb_subjects AJAX called ==="); // Debug log
    
    try {
        // First, check if the table exists and has data
        $check = $pdo->query("SELECT COUNT(*) FROM subjects WHERE is_central = 1 AND school_id IS NULL");
        $count = $check->fetchColumn();
        error_log("Central subjects count: " . $count);
        
        $rows = $pdo->query("
            SELECT id, subject_name
            FROM subjects
            WHERE is_central = 1 AND school_id IS NULL
            ORDER BY subject_name
        ")->fetchAll();
        
        error_log("Found subjects: " . json_encode($rows));
        
        echo json_encode(['success' => true, 'subjects' => $rows]);
    } catch (Exception $e) {
        error_log("cb_subjects error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── AJAX: fetch central topics  GET ?ajax=cb_topics&subject_id=X ────────────
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

// ── AJAX: fetch central questions  GET ?ajax=cb_questions&... ───────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'cb_questions') {
	ob_clean();
    header('Content-Type: application/json');
    $sid   = (int)($_GET['subject_id']  ?? 0);
    $tid   = (int)($_GET['topic_id']    ?? 0);   // central topic id
    $qtype = in_array($_GET['type'] ?? '', ['objective','subjective','theory'])
             ? $_GET['type'] : 'objective';

    $table = $qtype . '_questions';

    try {
        // Base: central questions for this subject
        $where  = "q.is_central = 1 AND q.school_id IS NULL";
$params = [];
if ($tid) { $where .= " AND q.topic_id = :tid"; $params[':tid'] = $tid; }
else { $where .= " AND q.subject_id = :sid"; $params[':sid'] = $sid; }

        $questions = $pdo->prepare("SELECT q.* FROM `$table` q WHERE $where ORDER BY q.id LIMIT 500");
        $questions->execute($params);
        $rows = $questions->fetchAll();

        // Mark already-imported ones (same central_source_id exists in local school's topic)
        $imported = [];
        if (!empty($rows)) {
            $ids       = array_column($rows, 'id');
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
// POST: CSV Bulk Import
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_csv_import'])) {
    try {
        $csv_type = $_POST['csv_question_type'] ?? 'objective';

        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK)
            throw new Exception("Please upload a valid CSV file.");
        if (strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION)) !== 'csv')
            throw new Exception("Only .csv files are accepted.");

        $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$fh) throw new Exception("Could not read the uploaded file.");

        $raw_headers = fgetcsv($fh);
        if (!$raw_headers) throw new Exception("CSV file is empty or malformed.");
        $headers = array_map(fn($h) => strtolower(trim(str_replace(['"',"'","\xEF\xBB\xBF"], '', $h))), $raw_headers);

        $imported = 0; $failed = 0; $errors = [];

        while (($row = fgetcsv($fh)) !== false) {
            if (!array_filter($row)) continue;
            $d = array_combine($headers, array_pad($row, count($headers), ''));

            try {
                if ($csv_type === 'objective') {
                    $q   = trim($d['question_text'] ?? $d['question'] ?? '');
                    $oa  = trim($d['option_a'] ?? $d['a'] ?? '');
                    $ob  = trim($d['option_b'] ?? $d['b'] ?? '');
                    $oc  = trim($d['option_c'] ?? $d['c'] ?? '');
                    $od  = trim($d['option_d'] ?? $d['d'] ?? '');
                    $ans = strtoupper(trim($d['correct_answer'] ?? $d['answer'] ?? ''));
                    $dif = strtolower(trim($d['difficulty_level'] ?? $d['difficulty'] ?? 'medium'));
                    $mk  = (int)($d['marks'] ?? 1);
                    if (empty($q)||empty($oa)||empty($ob)||!in_array($ans,['A','B','C','D']))
                        throw new Exception("Missing required fields or invalid answer");
                    if (!in_array($dif,['easy','medium','hard'])) $dif = 'medium';

                    $pdo->prepare("INSERT INTO objective_questions
                        (question_text,option_a,option_b,option_c,option_d,correct_answer,
                         difficulty_level,marks,subject_id,topic_id,class,school_id,is_central,created_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,NOW())")
                        ->execute([$q,$oa,$ob,$oc,$od,$ans,$dif,$mk,
                            $selected_topic['subject_id'],$topic_id,$selected_topic['class'],$school_id]);

                } elseif ($csv_type === 'subjective') {
                    $q   = trim($d['question_text'] ?? $d['question'] ?? '');
                    $ans = trim($d['correct_answer'] ?? $d['answer'] ?? $d['model_answer'] ?? '');
                    $dif = strtolower(trim($d['difficulty_level'] ?? $d['difficulty'] ?? 'medium'));
                    $mk  = (int)($d['marks'] ?? 1);
                    if (empty($q)) throw new Exception("Missing question text");
                    if (!in_array($dif,['easy','medium','hard'])) $dif = 'medium';

                    $pdo->prepare("INSERT INTO subjective_questions
                        (question_text,correct_answer,difficulty_level,marks,subject_id,topic_id,class,school_id,is_central,created_at)
                        VALUES (?,?,?,?,?,?,?,?,0,NOW())")
                        ->execute([$q,$ans,$dif,$mk,
                            $selected_topic['subject_id'],$topic_id,$selected_topic['class'],$school_id]);

                } elseif ($csv_type === 'theory') {
                    $q  = trim($d['question_text'] ?? $d['question'] ?? '');
                    $mk = (int)($d['marks'] ?? 5);
                    if (empty($q)) throw new Exception("Missing question text");

                    $pdo->prepare("INSERT INTO theory_questions
                        (question_text,marks,subject_id,topic_id,class,school_id,is_central,created_at)
                        VALUES (?,?,?,?,?,?,0,NOW())")
                        ->execute([$q,$mk,
                            $selected_topic['subject_id'],$topic_id,$selected_topic['class'],$school_id]);
                }
                $imported++;
            } catch (Exception $re) {
                $failed++;
                $errors[] = "Row ".($imported+$failed).": ".$re->getMessage();
            }
        }
        fclose($fh);

        $message = "CSV Import: $imported question(s) imported.";
        if ($failed) $message .= " $failed skipped (".implode('; ', array_slice($errors,0,3)).($failed>3?'…':'').")";
        $message_type = ($failed && !$imported) ? 'error' : 'success';

        $pdo->prepare("INSERT INTO activity_logs (user_id,user_type,activity,ip_address,user_agent,school_id) VALUES (?,?,?,?,?,?)")
            ->execute([$admin_id,'admin',"CSV imported $imported $csv_type questions → topic: {$selected_topic['topic_name']}",$_SERVER['REMOTE_ADDR'],$_SERVER['HTTP_USER_AGENT']??null,$school_id]);

    } catch (Exception $e) { $message = "CSV error: ".$e->getMessage(); $message_type = 'error'; }
}

// ============================================================
// POST: Import selected central bank questions (direct DB copy)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_from_central'])) {
    try {
        $selected_ids = $_POST['selected_central_questions'] ?? [];
        $import_type  = in_array($_POST['import_question_type']??'',['objective','subjective','theory'])
                        ? $_POST['import_question_type'] : 'objective';
        $table        = $import_type . '_questions';

        if (empty($selected_ids)) throw new Exception("Please select at least one question.");

        $imported = $skipped = $failed = 0;

        foreach ($selected_ids as $cid) {
            $cid = (int)$cid;

            // Fetch from central bank (same DB, is_central=1, school_id IS NULL)
            $src = $pdo->prepare("SELECT * FROM `$table` WHERE id = ? AND is_central = 1 AND school_id IS NULL LIMIT 1");
            $src->execute([$cid]);
            $q = $src->fetch();
            if (!$q) { $failed++; continue; }

            // Duplicate check
            $chk = $pdo->prepare("SELECT id FROM `$table` WHERE central_source_id=? AND topic_id=? AND school_id=? LIMIT 1");
            $chk->execute([$cid, $topic_id, $school_id]);
            if ($chk->fetch()) { $skipped++; continue; }

            // Insert into school's question bank using LOCAL subject_id and topic_id
            if ($import_type === 'objective') {
                $pdo->prepare("INSERT INTO objective_questions
                    (question_text,option_a,option_b,option_c,option_d,correct_answer,
                     difficulty_level,marks,subject_id,topic_id,class,school_id,
                     question_image,central_source_id,is_central,created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,NOW())")
                    ->execute([
                        $q['question_text'],$q['option_a'],$q['option_b'],
                        $q['option_c']??'',$q['option_d']??'',$q['correct_answer'],
                        $q['difficulty_level']??'medium',$q['marks']??1,
                        $selected_topic['subject_id'], $topic_id, $selected_topic['class'],
                        $school_id, $q['question_image']??null, $cid
                    ]);
            } elseif ($import_type === 'subjective') {
                $pdo->prepare("INSERT INTO subjective_questions
                    (question_text,correct_answer,difficulty_level,marks,subject_id,
                     topic_id,class,school_id,central_source_id,is_central,created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,0,NOW())")
                    ->execute([
                        $q['question_text'],$q['correct_answer']??'',
                        $q['difficulty_level']??'medium',$q['marks']??1,
                        $selected_topic['subject_id'],$topic_id,$selected_topic['class'],
                        $school_id,$cid
                    ]);
            } elseif ($import_type === 'theory') {
                $pdo->prepare("INSERT INTO theory_questions
                    (question_text,question_file,marks,subject_id,topic_id,class,
                     school_id,central_source_id,is_central,created_at)
                    VALUES (?,?,?,?,?,?,?,?,0,NOW())")
                    ->execute([
                        $q['question_text'],$q['question_file']??null,$q['marks']??5,
                        $selected_topic['subject_id'],$topic_id,$selected_topic['class'],
                        $school_id,$cid
                    ]);
            }
            $imported++;
        }

        $message = "Central Bank: $imported question(s) imported.";
        if ($skipped) $message .= " $skipped already existed.";
        if ($failed)  $message .= " $failed not found.";
        $message_type = 'success';

        $pdo->prepare("INSERT INTO activity_logs (user_id,user_type,activity,ip_address,user_agent,school_id) VALUES (?,?,?,?,?,?)")
            ->execute([$admin_id,'admin',"Imported $imported central $import_type questions → topic: {$selected_topic['topic_name']}",$_SERVER['REMOTE_ADDR'],$_SERVER['HTTP_USER_AGENT']??null,$school_id]);

    } catch (Exception $e) { $message = "Import error: ".$e->getMessage(); $message_type = 'error'; }
}

// ============================================================
// POST: Add single Objective
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_objective_question'])) {
    try {
        $qt  = trim($_POST['question_text']  ?? '');
        $oa  = trim($_POST['option_a']       ?? '');
        $ob  = trim($_POST['option_b']       ?? '');
        $oc  = trim($_POST['option_c']       ?? '');
        $od  = trim($_POST['option_d']       ?? '');
        $ans = strtoupper(trim($_POST['correct_answer'] ?? ''));
        $dif = $_POST['difficulty_level']    ?? 'medium';
        $mk  = (int)($_POST['marks']         ?? 1);

        if (empty($qt)||empty($oa)||empty($ob)||empty($ans)) throw new Exception("Fill in all required fields.");
        if (!in_array($ans,['A','B','C','D'])) throw new Exception("Correct answer must be A, B, C, or D.");

        $qi = null;
        if (isset($_FILES['question_image']) && $_FILES['question_image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['question_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext,['jpg','jpeg','png','gif','webp'])) {
                $dir = '../uploads/questions/';
                if (!file_exists($dir)) mkdir($dir,0777,true);
                $fn = 'q_'.time().'_'.rand(1000,9999).'.'.$ext;
                if (move_uploaded_file($_FILES['question_image']['tmp_name'],$dir.$fn)) $qi='uploads/questions/'.$fn;
            }
        }

        $pdo->prepare("INSERT INTO objective_questions
            (question_text,option_a,option_b,option_c,option_d,correct_answer,
             difficulty_level,marks,subject_id,topic_id,class,school_id,question_image,is_central,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,NOW())")
            ->execute([$qt,$oa,$ob,$oc,$od,$ans,$dif,$mk,
                $selected_topic['subject_id'],$topic_id,$selected_topic['class'],$school_id,$qi]);

        $message = "Objective question added!"; $message_type = 'success';
        if (!isset($_POST['stay_here'])) { header("Location: add_questions.php?topic_id=$topic_id&type=objective&success=1"); exit(); }
    } catch (Exception $e) { $message = $e->getMessage(); $message_type = 'error'; }
}

// ============================================================
// POST: Add single Subjective
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_subjective_question'])) {
    try {
        $qt  = trim($_POST['subjective_question_text']   ?? '');
        $ans = trim($_POST['subjective_correct_answer']  ?? '');
        $dif = $_POST['subjective_difficulty_level']     ?? 'medium';
        $mk  = (int)($_POST['subjective_marks']          ?? 1);
        if (empty($qt)) throw new Exception("Please enter the question text.");

        $pdo->prepare("INSERT INTO subjective_questions
            (question_text,correct_answer,difficulty_level,marks,subject_id,topic_id,class,school_id,is_central,created_at)
            VALUES (?,?,?,?,?,?,?,?,0,NOW())")
            ->execute([$qt,$ans,$dif,$mk,
                $selected_topic['subject_id'],$topic_id,$selected_topic['class'],$school_id]);

        $message = "Subjective question added!"; $message_type = 'success';
        if (!isset($_POST['stay_here'])) { header("Location: add_questions.php?topic_id=$topic_id&type=subjective&success=1"); exit(); }
    } catch (Exception $e) { $message = $e->getMessage(); $message_type = 'error'; }
}

// ============================================================
// POST: Add single Theory
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_theory_question'])) {
    try {
        $qt = trim($_POST['theory_question_text'] ?? '');
        $mk = (int)($_POST['theory_marks']        ?? 5);
        if (empty($qt)) throw new Exception("Please enter the question text.");

        $qf = null;
        if (isset($_FILES['theory_question_file']) && $_FILES['theory_question_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['theory_question_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext,['pdf','doc','docx','txt','jpg','jpeg','png'])) {
                $dir = '../uploads/theory/';
                if (!file_exists($dir)) mkdir($dir,0777,true);
                $fn = 'th_'.time().'_'.rand(1000,9999).'.'.$ext;
                if (move_uploaded_file($_FILES['theory_question_file']['tmp_name'],$dir.$fn)) $qf='uploads/theory/'.$fn;
            }
        }

        $pdo->prepare("INSERT INTO theory_questions
            (question_text,question_file,marks,subject_id,topic_id,class,school_id,is_central,created_at)
            VALUES (?,?,?,?,?,?,?,0,NOW())")
            ->execute([$qt,$qf,$mk,
                $selected_topic['subject_id'],$topic_id,$selected_topic['class'],$school_id]);

        $message = "Theory question added!"; $message_type = 'success';
        if (!isset($_POST['stay_here'])) { header("Location: add_questions.php?topic_id=$topic_id&type=theory&success=1"); exit(); }
    } catch (Exception $e) { $message = $e->getMessage(); $message_type = 'error'; }
}
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

        /* CSV help box */
        .csv-help { background:#eef5ff; border:1px dashed #3498db; border-radius:8px; padding:16px; margin-bottom:18px; }
        .csv-help pre { background:white; border-radius:6px; padding:12px; font-size:.78rem;
            overflow-x:auto; border:1px solid #d0dce8; margin-top:10px; white-space:pre-wrap; }
        .csv-help ul { margin-top:10px; padding-left:20px; font-size:.83rem; color:#444; }
        .csv-help li { margin-bottom:4px; }
        .dl-link { color:var(--secondary); text-decoration:underline; cursor:pointer; }

        /* Modal */
        .modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1000;
            align-items:center; justify-content:center; }
        .modal.active { display:flex; }
        .modal-content { background:white; border-radius:15px; width:90%; max-width:860px; max-height:88vh; overflow-y:auto; }
        .modal-header { padding:16px 22px; border-bottom:1px solid #eee; display:flex;
            justify-content:space-between; align-items:center; position:sticky; top:0; background:white; z-index:10; }
        .modal-footer { padding:14px 22px; border-top:1px solid #eee; display:flex;
            justify-content:flex-end; gap:10px; position:sticky; bottom:0; background:white; z-index:10; }
        .modal-body { padding:22px; }
        .close-modal { background:none; border:none; font-size:1.5rem; cursor:pointer; color:#999; }

        /* Central bank UI */
        .cb-step { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:18px; }
        .cb-step > .form-group { flex:1; min-width:150px; margin:0; }
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
        .badge-imported { background:#e3f2fd; color:#1565c0; }

        /* Spinner */
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
            .options-grid,.form-row-3,.form-row-2,.cb-step { flex-direction:column; grid-template-columns:1fr; }
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
            <p>Topic: <strong><?php echo htmlspecialchars($selected_topic['topic_name']); ?></strong>
               &nbsp;|&nbsp; Subject: <?php echo htmlspecialchars($selected_topic['subject_name']); ?>
               &nbsp;|&nbsp; Class: <?php echo htmlspecialchars($selected_topic['class']); ?></p>
        </div>
        <button class="logout-btn" onclick="window.location.href='../msv/logout.php'">
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
        <h2><i class="fas fa-bookmark"></i> <?php echo htmlspecialchars($selected_topic['topic_name']); ?></h2>
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
                <i class="fas fa-database"></i>
                <a href="javascript:void(0)" onclick="openCentralModal()">Import from Central Bank</a>
            </span>
        </div>
    </div>

    <!-- Tabs -->
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

        <!-- Objective -->
        <div class="tab-content <?php echo $question_type==='objective'?'active':''; ?>" id="objectiveTab">
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
                    <div class="form-group">
                        <label>Question Image (optional)</label>
                        <input type="file" name="question_image" class="form-control" accept="image/*">
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

        <!-- Subjective -->
        <div class="tab-content <?php echo $question_type==='subjective'?'active':''; ?>" id="subjectiveTab">
            <div class="form-section">
                <form method="POST">
                    <div class="form-group">
                        <label>Question Text *</label>
                        <textarea name="subjective_question_text" class="form-control" rows="5" placeholder="Enter question here…" required><?php echo htmlspecialchars($_POST['subjective_question_text'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Model Answer / Marking Guide</label>
                        <textarea name="subjective_correct_answer" class="form-control" rows="3" placeholder="Enter model answer…"><?php echo htmlspecialchars($_POST['subjective_correct_answer'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label>Difficulty</label>
                            <select name="subjective_difficulty_level" class="form-control">
                                <option value="easy">Easy</option>
                                <option value="medium" selected>Medium</option>
                                <option value="hard">Hard</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Marks</label>
                            <input type="number" name="subjective_marks" class="form-control" value="1" min="1" max="20">
                        </div>
                    </div>
                    <div class="checkbox-group">
                        <label><input type="checkbox" name="stay_here" value="1"> Stay on this page after adding</label>
                    </div>
                    <div class="form-actions">
                        <button type="reset" class="btn btn-secondary"><i class="fas fa-redo"></i> Clear</button>
                        <button type="submit" name="add_subjective_question" class="btn btn-success"><i class="fas fa-save"></i> Add Subjective Question</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Theory -->
        <div class="tab-content <?php echo $question_type==='theory'?'active':''; ?>" id="theoryTab">
            <div class="form-section">
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Question Text *</label>
                        <textarea name="theory_question_text" class="form-control" rows="5" placeholder="Enter question here…" required><?php echo htmlspecialchars($_POST['theory_question_text'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label>Marks</label>
                            <input type="number" name="theory_marks" class="form-control" value="5" min="1" max="50">
                        </div>
                        <div class="form-group">
                            <label>Attach File (optional)</label>
                            <input type="file" name="theory_question_file" class="form-control" accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png">
                        </div>
                    </div>
                    <div class="checkbox-group">
                        <label><input type="checkbox" name="stay_here" value="1"> Stay on this page after adding</label>
                    </div>
                    <div class="form-actions">
                        <button type="reset" class="btn btn-secondary"><i class="fas fa-redo"></i> Clear</button>
                        <button type="submit" name="add_theory_question" class="btn btn-success"><i class="fas fa-save"></i> Add Theory Question</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div><!-- /main-content -->

<!-- ══════════════════════════════════════
     CSV IMPORT MODAL
══════════════════════════════════════ -->
<div class="modal" id="csvModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-file-csv" style="color:#8e44ad"></i> Bulk CSV Import</h3>
            <button class="close-modal" onclick="closeCSVModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Question Type</label>
                <select id="csvType" class="form-control" onchange="refreshCSVHelp()">
                    <option value="objective">Objective</option>
                    <option value="subjective">Subjective</option>
                    <option value="theory">Theory</option>
                </select>
            </div>
            <div class="csv-help" id="csvHelp"></div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="bulk_csv_import" value="1">
                <input type="hidden" name="csv_question_type" id="csvTypeHidden" value="objective">
                <div class="form-group">
                    <label>Choose CSV File *</label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                    <small style="color:#777;margin-top:6px;display:block;">
                        <i class="fas fa-info-circle"></i> UTF-8 encoded. First row must be the header.
                    </small>
                </div>
                <div class="modal-footer" style="padding:0;border:none;position:static;margin-top:14px;">
                    <button type="button" class="btn btn-secondary" onclick="closeCSVModal()">Cancel</button>
                    <button type="submit" class="btn btn-purple"><i class="fas fa-upload"></i> Import</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════
     CENTRAL BANK IMPORT MODAL
══════════════════════════════════════ -->
<div class="modal" id="centralModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-database" style="color:var(--success)"></i> Import from Central Bank</h3>
            <button class="close-modal" onclick="closeCentralModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:.85rem;color:#555;margin-bottom:16px;background:#f0faf4;padding:10px 14px;border-radius:8px;border-left:3px solid var(--success);">
                <i class="fas fa-info-circle"></i>
                Browse by <strong>central bank subject &amp; topic</strong>. Questions will be imported into
                <strong><?php echo htmlspecialchars($selected_topic['topic_name']); ?></strong>
                using this school's subject and topic IDs — central IDs are only used to detect duplicates.
            </p>

            <div id="cbLoading" class="loading">
                <div class="spinner"></div><p>Loading central subjects…</p>
            </div>

            <div id="cbContent" style="display:none;">
                <div class="cb-step">
                    <div class="form-group">
                        <label>Central Subject</label>
                        <select id="cbSubject" class="form-control" onchange="loadCBTopics()">
                            <option value="">-- Select Subject --</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Central Topic</label>
                        <select id="cbTopic" class="form-control" onchange="loadCBQuestions()">
                            <option value="">-- Select Topic --</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Question Type</label>
                        <select id="cbQType" class="form-control" onchange="loadCBQuestions()">
                            <option value="objective">Objective</option>
                            <option value="subjective">Subjective</option>
                            <option value="theory">Theory</option>
                        </select>
                    </div>
                </div>
                <div id="cbQuestionsBox">
                    <div class="loading" style="padding:20px;"><p>Select a subject to browse questions…</p></div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeCentralModal()">Cancel</button>
            <button class="btn btn-success" onclick="submitCentralImport()">
                <i class="fas fa-file-import"></i> Import Selected
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
const urlType = new URLSearchParams(window.location.search).get('type');
if (urlType && ['objective','subjective','theory'].includes(urlType)) switchTab(urlType);

// ── Mobile menu ───────────────────────────────────────────────
const menuBtn  = document.getElementById('mobileMenuBtn');
const sidebar  = document.getElementById('sidebar');
if (menuBtn) menuBtn.onclick = () => sidebar?.classList.toggle('active');
document.addEventListener('click', e => {
    if (window.innerWidth <= 768 && sidebar && menuBtn &&
        !sidebar.contains(e.target) && !menuBtn.contains(e.target))
        sidebar.classList.remove('active');
});

// ── Auto-dismiss alerts ───────────────────────────────────────
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(el => {
        el.style.transition = 'opacity .5s'; el.style.opacity = '0';
        setTimeout(() => el.remove(), 500);
    });
}, 6000);

// ════════════════════════════════════════════════════════════
// CSV MODAL
// ════════════════════════════════════════════════════════════
const CSV_TPL = {
    objective: {
        headers: 'question_text,option_a,option_b,option_c,option_d,correct_answer,difficulty_level,marks',
        example: 'What is 2+2?,1,3,4,5,C,easy,1\nCapital of Nigeria?,Lagos,Abuja,Kano,Ibadan,B,medium,1',
        notes: [
            '<strong>correct_answer</strong>: A / B / C / D',
            '<strong>option_c, option_d</strong>: optional',
            '<strong>difficulty_level</strong>: easy | medium | hard',
            '<strong>marks</strong>: integer (default 1)',
        ]
    },
    subjective: {
        headers: 'question_text,correct_answer,difficulty_level,marks',
        example: 'Define photosynthesis.,Process by which plants make food using sunlight.,medium,2',
        notes: [
            '<strong>correct_answer</strong>: model answer or marking guide',
            '<strong>difficulty_level</strong>: easy | medium | hard',
        ]
    },
    theory: {
        headers: 'question_text,marks',
        example: 'Discuss the causes of the Nigerian Civil War.,10',
        notes: ['<strong>marks</strong>: integer (default 5)', 'Answers are marked manually — no answer column needed']
    }
};

function refreshCSVHelp() {
    const type = document.getElementById('csvType').value;
    document.getElementById('csvTypeHidden').value = type;
    const t = CSV_TPL[type];
    document.getElementById('csvHelp').innerHTML = `
        <p><strong><i class="fas fa-info-circle"></i> CSV format for <em>${type}</em> questions:</strong></p>
        <pre>${esc(t.headers)}\n${esc(t.example)}</pre>
        <ul>
            ${t.notes.map(n => `<li>${n}</li>`).join('')}
            <li><span class="dl-link" onclick="downloadCSVTemplate('${type}')">
                <i class="fas fa-download"></i> Download template
            </span></li>
        </ul>`;
}

function downloadCSVTemplate(type) {
    const t   = CSV_TPL[type];
    const blob = new Blob([t.headers + '\n' + t.example], { type: 'text/csv' });
    const a   = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `template_${type}_questions.csv`;
    a.click();
}

function openCSVModal()  { document.getElementById('csvModal').classList.add('active'); refreshCSVHelp(); }
function closeCSVModal() { document.getElementById('csvModal').classList.remove('active'); }

// ════════════════════════════════════════════════════════════
// CENTRAL BANK MODAL  — plain fetch to same-page AJAX endpoints
// ════════════════════════════════════════════════════════════
let cbQuestions = [];
let cbSelected  = new Set();

async function openCentralModal() {
    document.getElementById('centralModal').classList.add('active');
    const loading = document.getElementById('cbLoading');
    const content = document.getElementById('cbContent');
    loading.style.display = 'block'; content.style.display = 'none';

    try {
        const res  = await fetch(SELF + '&ajax=cb_subjects');
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'No subjects found');

        const sel = document.getElementById('cbSubject');
        sel.innerHTML = '<option value="">-- Select Subject --</option>' +
            data.subjects.map(s => `<option value="${s.id}">${esc(s.subject_name)}</option>`).join('');

        loading.style.display = 'none'; content.style.display = 'block';
    } catch (err) {
        loading.innerHTML = `<div class="alert alert-error" style="text-align:left;">
            <i class="fas fa-exclamation-circle"></i>
            <div><strong>Could not load central subjects</strong><br>${esc(err.message)}</div>
        </div>`;
    }
}

function closeCentralModal() {
    document.getElementById('centralModal').classList.remove('active');
    cbSelected.clear();
}

async function loadCBTopics() {
    const sid = document.getElementById('cbSubject').value;
    const sel = document.getElementById('cbTopic');
    sel.innerHTML = '<option value="">Loading…</option>';
    document.getElementById('cbQuestionsBox').innerHTML =
        '<div class="loading" style="padding:20px;"><p>Select a topic…</p></div>';

    if (!sid) { sel.innerHTML = '<option value="">-- Select Topic --</option>'; return; }

    try {
        const res  = await fetch(SELF + '&ajax=cb_topics&subject_id=' + sid);
        const data = await res.json();
        if (data.success && data.topics.length) {
            sel.innerHTML = '<option value="0">-- All Topics --</option>' +
                data.topics.map(t => `<option value="${t.id}">${esc(t.topic_name)}${t.term ? ' ('+t.term+')' : ''}</option>`).join('');
        } else {
            sel.innerHTML = '<option value="0">No topics found</option>';
        }
    } catch (err) {
        sel.innerHTML = '<option value="">Error loading topics</option>';
    }
}

async function loadCBQuestions() {
    const sid   = document.getElementById('cbSubject').value;
    const tid   = document.getElementById('cbTopic').value;
    const qtype = document.getElementById('cbQType').value;
    const box   = document.getElementById('cbQuestionsBox');
    if (!sid) return;

    cbSelected.clear();
    box.innerHTML = '<div class="loading"><div class="spinner"></div><p>Loading questions…</p></div>';

    try {
        let url = SELF + `&ajax=cb_questions&subject_id=${sid}&type=${qtype}`;
        if (tid && tid !== '0') url += '&topic_id=' + tid;
        const res  = await fetch(url);
        const data = await res.json();

        if (data.success && data.questions.length) {
            cbQuestions = data.questions;
            renderCBQuestions();
        } else {
            box.innerHTML = '<div class="loading"><p>No questions found for this selection.</p></div>';
        }
    } catch (err) {
        box.innerHTML = `<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> ${esc(err.message)}</div>`;
    }
}

function renderCBQuestions() {
    const box = document.getElementById('cbQuestionsBox');
    let html = `
        <div class="select-all-bar">
            <input type="checkbox" id="cbSelectAll" onchange="cbToggleAll(this)">
            <label for="cbSelectAll" style="font-weight:500;cursor:pointer;">Select All</label>
            <span style="margin-left:auto;font-size:.85rem;color:#555;">
                <span id="cbCount">0</span> of ${cbQuestions.length} selected
            </span>
        </div>
        <div class="question-list">`;

    cbQuestions.forEach((q, i) => {
        const preview  = (q.question_text || '').substring(0, 160);
        const imported = q.already_imported;
        html += `
            <div class="question-item">
                <input type="checkbox" class="cb-chk" value="${q.id}"
                    ${imported ? 'disabled' : ''}
                    onchange="cbCheck(this)">
                <div class="question-text ${imported ? 'already-imported' : ''}">
                    <strong>Q${i+1}:</strong> ${esc(preview)}${q.question_text?.length > 160 ? '…' : ''}
                    ${imported
                        ? '<span class="badge badge-imported"><i class="fas fa-check"></i> Already imported</span>'
                        : '<span class="badge badge-central"><i class="fas fa-database"></i> Central</span>'}
                </div>
            </div>`;
    });

    html += '</div>';
    box.innerHTML = html;
    updateCBCount();
}

function cbCheck(cb) {
    const id = parseInt(cb.value);
    cb.checked ? cbSelected.add(id) : cbSelected.delete(id);
    updateCBCount();
}
function cbToggleAll(src) {
    document.querySelectorAll('.cb-chk:not(:disabled)').forEach(cb => {
        cb.checked = src.checked;
        src.checked ? cbSelected.add(parseInt(cb.value)) : cbSelected.delete(parseInt(cb.value));
    });
    updateCBCount();
}
function updateCBCount() {
    const el = document.getElementById('cbCount');
    if (el) el.textContent = cbSelected.size;
}

function submitCentralImport() {
    if (!cbSelected.size) { alert('Please select at least one question.'); return; }
    const qtype = document.getElementById('cbQType').value;
    const tid   = document.getElementById('cbTopic').value || '0';
    if (!confirm(`Import ${cbSelected.size} ${qtype} question(s) into "<?php echo addslashes($selected_topic['topic_name']); ?>"?`)) return;

    const form = document.createElement('form');
    form.method = 'POST'; form.action = '';
    [
        ['import_from_central',  '1'],
        ['source_topic_id',      tid],
        ['import_question_type', qtype],
    ].forEach(([n, v]) => {
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = n; i.value = v; form.appendChild(i);
    });
    cbSelected.forEach(id => {
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = 'selected_central_questions[]'; i.value = id; form.appendChild(i);
    });
    document.body.appendChild(form);
    form.submit();
}
</script>
</body>
</html>
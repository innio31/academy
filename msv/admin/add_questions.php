<?php
// admin/add_questions.php - Add Questions with Import from Central Bank (Multi-School)
// Central Bank questions have is_central = 1 AND school_id IS NULL

session_start();

// Check if admin is logged in (support both session styles)
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /msv/login.php");
    exit();
}

// Get admin info
if (isset($_SESSION['admin_id'])) {
    $admin_id = $_SESSION['admin_id'];
    $admin_name = $_SESSION['admin_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'super_admin';
} else {
    $admin_id = $_SESSION['user_id'];
    $admin_name = $_SESSION['user_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

// Check permission
if ($admin_role !== 'super_admin' && $admin_role !== 'admin') {
    header("Location: index.php?message=Access+denied&type=error");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;

// Get parameters
$topic_id = isset($_GET['topic_id']) ? (int)$_GET['topic_id'] : 0;
$question_type = isset($_GET['type']) ? $_GET['type'] : 'objective';

// Validate topic and get details
$selected_topic = null;
if ($topic_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT t.*, s.subject_name, s.id as subject_id 
            FROM topics t 
            JOIN subjects s ON t.subject_id = s.id 
            WHERE t.id = ? AND t.school_id = ?
        ");
        $stmt->execute([$topic_id, $school_id]);
        $selected_topic = $stmt->fetch();

        if ($selected_topic) {
            $class_stmt = $pdo->prepare("
                SELECT class FROM subject_classes 
                WHERE subject_id = ? AND school_id = ? LIMIT 1
            ");
            $class_stmt->execute([$selected_topic['subject_id'], $school_id]);
            $class_row = $class_stmt->fetch();
            $selected_topic['class'] = $class_row['class'] ?? 'N/A';
        }
    } catch (Exception $e) {
        error_log("Error loading topic: " . $e->getMessage());
    }
}

if (!$selected_topic) {
    header("Location: manage-questions.php?error=invalid_topic");
    exit();
}

$message = '';
$message_type = '';

// ============================================
// CENTRAL BANK API CONFIGURATION
// ============================================
define('CENTRAL_API_URL', 'https://acad.com.ng/central_bank/api/');
define('CENTRAL_API_KEY', '33118913968799983134133712965617');

// ============================================
// ENSURE CENTRAL COLUMNS EXIST
// ============================================

try {
    // Add is_central column if not exists
    $check = $pdo->query("SHOW COLUMNS FROM objective_questions LIKE 'is_central'");
    if ($check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE objective_questions ADD COLUMN is_central TINYINT(1) DEFAULT 0");
        $pdo->exec("ALTER TABLE objective_questions ADD INDEX idx_central (is_central)");
    }

    $check = $pdo->query("SHOW COLUMNS FROM subjective_questions LIKE 'is_central'");
    if ($check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE subjective_questions ADD COLUMN is_central TINYINT(1) DEFAULT 0");
        $pdo->exec("ALTER TABLE subjective_questions ADD INDEX idx_central (is_central)");
    }

    $check = $pdo->query("SHOW COLUMNS FROM theory_questions LIKE 'is_central'");
    if ($check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE theory_questions ADD COLUMN is_central TINYINT(1) DEFAULT 0");
        $pdo->exec("ALTER TABLE theory_questions ADD INDEX idx_central (is_central)");
    }

    // Add central_source_id to track imported questions
    $check = $pdo->query("SHOW COLUMNS FROM objective_questions LIKE 'central_source_id'");
    if ($check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE objective_questions ADD COLUMN central_source_id INT DEFAULT NULL");
        $pdo->exec("ALTER TABLE subjective_questions ADD COLUMN central_source_id INT DEFAULT NULL");
        $pdo->exec("ALTER TABLE theory_questions ADD COLUMN central_source_id INT DEFAULT NULL");
    }
} catch (Exception $e) {
    error_log("Error adding columns: " . $e->getMessage());
}

// ============================================
// HANDLE IMPORT FROM CENTRAL BANK (UPDATED)
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_from_central'])) {
    try {
        $selected_central_question_ids = isset($_POST['selected_central_questions']) ? $_POST['selected_central_questions'] : [];
        $source_topic_id = (int)$_POST['source_topic_id']; // Central bank's topic ID
        $import_type = $_POST['import_question_type']; // objective, subjective, or theory

        if (empty($selected_central_question_ids)) {
            throw new Exception("Please select at least one question to import");
        }

        $imported_count = 0;
        $skipped_count = 0;
        $failed_count = 0;

        foreach ($selected_central_question_ids as $central_question_id) {
            // Fetch the full question from Central Bank API
            $api_url = CENTRAL_API_URL . "index.php?action=get_question_by_id&question_id={$central_question_id}&type={$import_type}";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'X-API-Key: ' . CENTRAL_API_KEY
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code !== 200 || !$response) {
                $failed_count++;
                continue;
            }

            $data = json_decode($response, true);
            
            if (!$data['success'] || !$data['question']) {
                $failed_count++;
                continue;
            }

            $central_q = $data['question'];

            // Check if already imported using central_source_id
            $check = $pdo->prepare("
                SELECT id FROM {$import_type}_questions 
                WHERE central_source_id = ? AND topic_id = ? AND school_id = ?
                LIMIT 1
            ");
            $check->execute([$central_question_id, $topic_id, $school_id]);

            if ($check->fetch()) {
                $skipped_count++;
                continue;
            }

            if ($import_type == 'objective') {
                $insert = $pdo->prepare("
                    INSERT INTO objective_questions 
                    (question_text, option_a, option_b, option_c, option_d, correct_answer, 
                     difficulty_level, marks, subject_id, topic_id, class, school_id, 
                     question_image, central_source_id, is_central, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
                ");
                $insert->execute([
                    $central_q['question_text'],
                    $central_q['option_a'],
                    $central_q['option_b'],
                    $central_q['option_c'] ?? '',
                    $central_q['option_d'] ?? '',
                    $central_q['correct_answer'],
                    $central_q['difficulty_level'] ?? 'medium',
                    $central_q['marks'] ?? 1,
                    $selected_topic['subject_id'], // LOCAL subject ID
                    $topic_id, // LOCAL topic ID
                    $selected_topic['class'],
                    $school_id,
                    $central_q['question_image'] ?? null,
                    $central_question_id
                ]);
                $imported_count++;
            } elseif ($import_type == 'subjective') {
                $insert = $pdo->prepare("
                    INSERT INTO subjective_questions 
                    (question_text, correct_answer, difficulty_level, marks, subject_id, 
                     topic_id, class, school_id, central_source_id, is_central, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
                ");
                $insert->execute([
                    $central_q['question_text'],
                    $central_q['correct_answer'] ?? '',
                    $central_q['difficulty_level'] ?? 'medium',
                    $central_q['marks'] ?? 1,
                    $selected_topic['subject_id'], // LOCAL subject ID
                    $topic_id, // LOCAL topic ID
                    $selected_topic['class'],
                    $school_id,
                    $central_question_id
                ]);
                $imported_count++;
            } elseif ($import_type == 'theory') {
                $insert = $pdo->prepare("
                    INSERT INTO theory_questions 
                    (question_text, question_file, marks, subject_id, topic_id, class, 
                     school_id, central_source_id, is_central, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
                ");
                $insert->execute([
                    $central_q['question_text'],
                    $central_q['question_file'] ?? null,
                    $central_q['marks'] ?? 5,
                    $selected_topic['subject_id'], // LOCAL subject ID
                    $topic_id, // LOCAL topic ID
                    $selected_topic['class'],
                    $school_id,
                    $central_question_id
                ]);
                $imported_count++;
            }
        }

        $message = "Successfully imported $imported_count question(s) from Central Bank.";
        if ($skipped_count > 0) {
            $message .= " $skipped_count question(s) were already imported.";
        }
        if ($failed_count > 0) {
            $message .= " $failed_count question(s) failed to import.";
        }
        $message_type = "success";

        // Log activity
        $log_stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, user_type, activity, ip_address, user_agent, school_id) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $log_stmt->execute([
            $admin_id,
            'admin',
            "Imported $imported_count central $import_type questions to topic: {$selected_topic['topic_name']}",
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $school_id
        ]);
    } catch (Exception $e) {
        $message = "Import error: " . $e->getMessage();
        $message_type = "error";
    }
}

// ============================================
// HANDLE REGULAR QUESTION SUBMISSIONS
// ============================================

// Handle Objective Question
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_objective_question'])) {
    try {
        $question_text = trim($_POST['question_text'] ?? '');
        $option_a = trim($_POST['option_a'] ?? '');
        $option_b = trim($_POST['option_b'] ?? '');
        $option_c = trim($_POST['option_c'] ?? '');
        $option_d = trim($_POST['option_d'] ?? '');
        $correct_answer = strtoupper(trim($_POST['correct_answer'] ?? ''));
        $difficulty_level = $_POST['difficulty_level'] ?? 'medium';
        $marks = (int)($_POST['marks'] ?? 1);

        if (empty($question_text) || empty($option_a) || empty($option_b) || empty($correct_answer)) {
            throw new Exception("Please fill in all required fields");
        }

        if (!in_array($correct_answer, ['A', 'B', 'C', 'D'])) {
            throw new Exception("Correct answer must be A, B, C, or D");
        }

        // Handle image upload
        $question_image = null;
        if (isset($_FILES['question_image']) && $_FILES['question_image']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $ext = strtolower(pathinfo($_FILES['question_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $upload_dir = '../uploads/questions/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                $new_filename = 'question_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                if (move_uploaded_file($_FILES['question_image']['tmp_name'], $upload_dir . $new_filename)) {
                    $question_image = 'uploads/questions/' . $new_filename;
                }
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO objective_questions 
            (question_text, option_a, option_b, option_c, option_d, correct_answer, 
             difficulty_level, marks, subject_id, topic_id, class, school_id, question_image, 
             is_central, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
        ");
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
            $school_id,
            $question_image
        ]);

        $message = "Objective question added successfully!";
        $message_type = "success";

        if (!isset($_POST['stay_here'])) {
            header("Location: add_questions.php?topic_id=$topic_id&type=objective&success=1");
            exit();
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Handle Subjective Question
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_subjective_question'])) {
    try {
        $question_text = trim($_POST['subjective_question_text'] ?? '');
        $correct_answer = trim($_POST['subjective_correct_answer'] ?? '');
        $difficulty_level = $_POST['subjective_difficulty_level'] ?? 'medium';
        $marks = (int)($_POST['subjective_marks'] ?? 1);

        if (empty($question_text)) {
            throw new Exception("Please enter the question text");
        }

        $stmt = $pdo->prepare("
            INSERT INTO subjective_questions 
            (question_text, correct_answer, difficulty_level, marks, subject_id, topic_id, 
             class, school_id, is_central, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
        ");
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
            header("Location: add_questions.php?topic_id=$topic_id&type=subjective&success=1");
            exit();
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Handle Theory Question
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_theory_question'])) {
    try {
        $question_text = trim($_POST['theory_question_text'] ?? '');
        $marks = (int)($_POST['theory_marks'] ?? 5);

        if (empty($question_text)) {
            throw new Exception("Please enter the question text");
        }

        $question_file = null;
        if (isset($_FILES['theory_question_file']) && $_FILES['theory_question_file']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png'];
            $ext = strtolower(pathinfo($_FILES['theory_question_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $upload_dir = '../uploads/theory/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                $new_filename = 'theory_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                if (move_uploaded_file($_FILES['theory_question_file']['tmp_name'], $upload_dir . $new_filename)) {
                    $question_file = 'uploads/theory/' . $new_filename;
                }
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO theory_questions 
            (question_text, question_file, marks, subject_id, topic_id, class, school_id, is_central, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())
        ");
        $stmt->execute([
            $question_text,
            $question_file,
            $marks,
            $selected_topic['subject_id'],
            $topic_id,
            $selected_topic['class'],
            $school_id
        ]);

        $message = "Theory question added successfully!";
        $message_type = "success";

        if (!isset($_POST['stay_here'])) {
            header("Location: add_questions.php?topic_id=$topic_id&type=theory&success=1");
            exit();
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> - Add Questions</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: #3498db;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --sidebar-width: 260px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            color: #333;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-color), var(--dark-color));
            color: white;
            padding: 20px 0;
            transition: all 0.3s ease;
            z-index: 100;
            overflow-y: auto;
            transform: translateX(-100%);
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 20px;
            margin-bottom: 15px;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: var(--secondary-color);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .admin-info {
            text-align: center;
            padding: 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            margin: 0 15px 20px;
        }

        .nav-links {
            list-style: none;
            padding: 0 15px;
        }

        .nav-links li {
            margin-bottom: 5px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            border-radius: 8px;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255, 255, 255, 0.2);
        }

        /* Main Content */
        .main-content {
            margin-left: 0;
            padding: 20px;
            min-height: 100vh;
        }

        .mobile-menu-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 101;
            background: var(--primary-color);
            color: white;
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 10px;
            font-size: 20px;
            cursor: pointer;
        }

        .top-header {
            background: white;
            padding: 15px 25px;
            border-radius: 10px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.8rem;
            margin-bottom: 5px;
        }

        .logout-btn {
            background: var(--danger-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Tabs */
        .tabs-navigation {
            background: white;
            border-radius: 15px;
            margin-bottom: 30px;
            overflow: hidden;
        }

        .tab-buttons {
            display: flex;
            background: #f8f9fa;
            border-bottom: 2px solid #e0e0e0;
            flex-wrap: wrap;
        }

        .tab-button {
            flex: 1;
            padding: 15px 20px;
            border: none;
            background: none;
            font-size: 1rem;
            font-weight: 500;
            color: #666;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s;
        }

        .tab-button:hover {
            background: #e9ecef;
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

        /* Form Styles */
        .form-section {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 12px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--primary-color);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            font-family: monospace;
            background: white;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 120px;
            font-family: monospace;
            line-height: 1.5;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
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
            font-weight: bold;
            color: var(--primary-color);
            min-width: 35px;
            font-size: 1.1rem;
        }

        .option-input {
            flex: 1;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .btn-info {
            background: var(--secondary-color);
            color: white;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d5f4e6;
            color: #155724;
            border-left: 4px solid var(--success-color);
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger-color);
        }

        .topic-info-card {
            background: linear-gradient(135deg, var(--primary-color), var(--dark-color));
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
        }

        .topic-meta {
            display: flex;
            gap: 20px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        .meta-item {
            background: rgba(255, 255, 255, 0.1);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
        }

        .meta-item a {
            color: white;
            text-decoration: none;
        }

        .meta-item a:hover {
            text-decoration: underline;
        }

        /* Import Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 800px;
            max-height: 85vh;
            overflow-y: auto;
        }

        .modal-header,
        .modal-footer {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            background: white;
            top: 0;
            z-index: 10;
        }

        .modal-footer {
            border-top: 1px solid #eee;
            border-bottom: none;
            justify-content: flex-end;
            gap: 10px;
        }

        .modal-body {
            padding: 20px;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #999;
        }

        .question-list {
            max-height: 350px;
            overflow-y: auto;
            border: 1px solid #eee;
            border-radius: 8px;
        }

        .question-item {
            padding: 12px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .question-item:hover {
            background: #f8f9fa;
        }

        .question-checkbox {
            margin-top: 2px;
        }

        .question-text {
            flex: 1;
            font-size: 0.9rem;
            line-height: 1.4;
        }

        .question-text.already-imported {
            opacity: 0.6;
            text-decoration: line-through;
        }

        .loading {
            text-align: center;
            padding: 40px;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .select-all-bar {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-top: 10px;
        }

        .checkbox-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: normal;
            cursor: pointer;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .badge-central {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .badge-info {
            background: #e3f2fd;
            color: #1565c0;
        }

        .central-icon {
            color: var(--success-color);
            margin-left: 8px;
            font-size: 0.8rem;
        }

        @media (min-width: 769px) {
            .sidebar {
                transform: translateX(0);
            }

            .main-content {
                margin-left: var(--sidebar-width);
            }

            .mobile-menu-btn {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .options-grid {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .tab-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>

    <?php
    // Include sidebar at the end (it will be positioned fixed)
    require_once 'includes/sidebar.php';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1>Add Questions</h1>
                <p>Topic: <strong><?php echo htmlspecialchars($selected_topic['topic_name']); ?></strong> |
                    Subject: <?php echo htmlspecialchars($selected_topic['subject_name']); ?> |
                    Class: <?php echo htmlspecialchars($selected_topic['class']); ?></p>
            </div>
            <button class="logout-btn" onclick="window.location.href='../msv/logout.php'"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="topic-info-card">
            <h2><i class="fas fa-bookmark"></i> <?php echo htmlspecialchars($selected_topic['topic_name']); ?></h2>
            <div class="topic-meta">
                <span class="meta-item"><i class="fas fa-arrow-left"></i> <a href="manage-questions.php?topic_id=<?php echo $topic_id; ?>">Back to Questions</a></span>
                <span class="meta-item"><i class="fas fa-database"></i> <a href="javascript:void(0)" onclick="openCentralImportModal()">Import from Central Bank</a> <i class="fas fa-check-circle central-icon" title="Verified by Developer Team"></i></span>
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
                    <form method="POST" action="" enctype="multipart/form-data">
                        <div class="form-group">
                            <label>Question Text *</label>
                            <textarea name="question_text" class="form-control" rows="5" placeholder="Enter question here..." required><?php echo htmlspecialchars($_POST['question_text'] ?? ''); ?></textarea>
                        </div>

                        <div class="options-grid">
                            <div class="option-group">
                                <span class="option-label">A)</span>
                                <input type="text" name="option_a" class="form-control" placeholder="Option A" required value="<?php echo htmlspecialchars($_POST['option_a'] ?? ''); ?>">
                            </div>
                            <div class="option-group">
                                <span class="option-label">B)</span>
                                <input type="text" name="option_b" class="form-control" placeholder="Option B" required value="<?php echo htmlspecialchars($_POST['option_b'] ?? ''); ?>">
                            </div>
                            <div class="option-group">
                                <span class="option-label">C)</span>
                                <input type="text" name="option_c" class="form-control" placeholder="Option C (Optional)" value="<?php echo htmlspecialchars($_POST['option_c'] ?? ''); ?>">
                            </div>
                            <div class="option-group">
                                <span class="option-label">D)</span>
                                <input type="text" name="option_d" class="form-control" placeholder="Option D (Optional)" value="<?php echo htmlspecialchars($_POST['option_d'] ?? ''); ?>">
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                            <div class="form-group">
                                <label>Correct Answer *</label>
                                <select name="correct_answer" class="form-control" required>
                                    <option value="">Select</option>
                                    <option value="A">A</option>
                                    <option value="B">B</option>
                                    <option value="C">C</option>
                                    <option value="D">D</option>
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
                            <label>Question Image (Optional)</label>
                            <input type="file" name="question_image" class="form-control" accept="image/*">
                        </div>

                        <div class="checkbox-group">
                            <label><input type="checkbox" name="stay_here" value="1"> Stay on this page after adding (add another)</label>
                        </div>

                        <div class="form-actions">
                            <button type="reset" class="btn btn-secondary"><i class="fas fa-redo"></i> Clear</button>
                            <button type="submit" name="add_objective_question" class="btn btn-success"><i class="fas fa-save"></i> Add Objective Question</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Subjective Tab -->
            <div class="tab-content <?php echo $question_type === 'subjective' ? 'active' : ''; ?>" id="subjectiveTab">
                <div class="form-section">
                    <form method="POST" action="">
                        <div class="form-group">
                            <label>Question Text *</label>
                            <textarea name="subjective_question_text" class="form-control" rows="5" placeholder="Enter question here..." required><?php echo htmlspecialchars($_POST['subjective_question_text'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label>Model Answer / Answer Guide</label>
                            <textarea name="subjective_correct_answer" class="form-control" rows="3" placeholder="Enter model answer or marking guide..."><?php echo htmlspecialchars($_POST['subjective_correct_answer'] ?? ''); ?></textarea>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
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

            <!-- Theory Tab -->
            <div class="tab-content <?php echo $question_type === 'theory' ? 'active' : ''; ?>" id="theoryTab">
                <div class="form-section">
                    <form method="POST" action="" enctype="multipart/form-data">
                        <div class="form-group">
                            <label>Question Text *</label>
                            <textarea name="theory_question_text" class="form-control" rows="5" placeholder="Enter question here..." required><?php echo htmlspecialchars($_POST['theory_question_text'] ?? ''); ?></textarea>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div class="form-group">
                                <label>Marks</label>
                                <input type="number" name="theory_marks" class="form-control" value="5" min="1" max="50">
                            </div>
                            <div class="form-group">
                                <label>Attach File (Optional)</label>
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
    </div>

    <!-- Import from Central Bank Modal -->
    <div class="modal" id="centralImportModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-database"></i> Import from Central Bank <span class="badge badge-central">Verified by Developer Team</span></h3>
                <button class="close-modal" onclick="closeCentralImportModal()">&times;</button>
            </div>
            <div class="modal-body" id="centralImportModalBody">
                <div class="loading" id="centralImportLoading">
                    <div class="spinner"></div>
                    <p>Loading central question bank...</p>
                </div>
                <div id="centralImportContent" style="display: none;"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeCentralImportModal()">Cancel</button>
                <button class="btn btn-success" id="importSelectedBtn" onclick="importSelectedCentralQuestions()">Import Selected</button>
            </div>
        </div>
    </div>

    <script>
        // ============================================
// CENTRAL BANK API FUNCTIONS (CORS FIXED)
// ============================================

// Use the exact domain that matches your central bank
const CENTRAL_API_BASE = 'https://acad.com.ng/central_bank/api/';
const CENTRAL_API_KEY = '33118913968799983134133712965617';

let centralQuestions = [];
let selectedCentralQuestionIds = new Set();

// Helper function for API calls with better error handling
async function callCentralAPI(endpoint, params = {}) {
    // Build URL
    const url = new URL(CENTRAL_API_BASE + 'index.php');
    url.searchParams.append('action', endpoint);
    
    for (const [key, value] of Object.entries(params)) {
        if (value !== undefined && value !== null && value !== '') {
            url.searchParams.append(key, value);
        }
    }
    
    console.log('Fetching:', url.toString()); // Debug log
    
    try {
        const response = await fetch(url.toString(), {
            method: 'GET',
            headers: {
                'X-API-Key': CENTRAL_API_KEY,
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            },
            mode: 'cors',
            credentials: 'omit'
        });
        
        console.log('Response status:', response.status); // Debug log
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const data = await response.json();
        console.log('Response data:', data); // Debug log
        
        return data;
    } catch (error) {
        console.error('API call failed:', error);
        throw error;
    }
}

async function openCentralImportModal() {
    console.log('Opening central import modal...'); // Debug log
    
    const modal = document.getElementById('centralImportModal');
    if (modal) {
        modal.classList.add('active');
        await loadCentralSubjects();
    }
}

function closeCentralImportModal() {
    const modal = document.getElementById('centralImportModal');
    if (modal) {
        modal.classList.remove('active');
    }
    selectedCentralQuestionIds.clear();
}

async function loadCentralSubjects() {
    const container = document.getElementById('centralImportContent');
    const loadingDiv = document.getElementById('centralImportLoading');

    if (!container) {
        console.error('Container not found');
        return;
    }

    if (loadingDiv) {
        loadingDiv.style.display = 'block';
        container.style.display = 'none';
    }

    try {
        console.log('Loading subjects from central bank...');
        const data = await callCentralAPI('get_subjects');
        
        console.log('Subjects response:', data);

        if (data.success && data.subjects && data.subjects.length > 0) {
            buildCentralImportUI(data.subjects);
            if (loadingDiv) {
                loadingDiv.style.display = 'none';
                container.style.display = 'block';
            }
        } else {
            throw new Error(data.error || 'No subjects found in central bank');
        }
    } catch (error) {
        console.error('Error loading subjects:', error);
        const errorMsg = `
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> 
                <div>
                    <strong>Error connecting to Central Bank:</strong><br>
                    ${error.message}<br><br>
                    <small>Please ensure:
                        <ul>
                            <li>The central bank API is accessible at ${CENTRAL_API_BASE}</li>
                            <li>Your API key is valid</li>
                            <li>Network connection is working</li>
                        </ul>
                    </small>
                </div>
            </div>
        `;
        if (loadingDiv) {
            loadingDiv.innerHTML = errorMsg;
        } else {
            container.innerHTML = errorMsg;
        }
    }
}

function buildCentralImportUI(subjects) {
    const container = document.getElementById('centralImportContent');
    if (!container) return;

    container.innerHTML = `
        <div class="form-group">
            <label>Select Subject</label>
            <select id="centralSubjectSelect" class="form-control" onchange="loadCentralTopics()">
                <option value="">-- Select Subject --</option>
                ${subjects.map(s => `<option value="${s.id}">${escapeHtml(s.subject_name)}</option>`).join('')}
            </select>
        </div>
        <div class="form-group">
            <label>Select Topic</label>
            <select id="centralTopicSelect" class="form-control" onchange="loadCentralQuestions()">
                <option value="">-- Select Topic --</option>
            </select>
        </div>
        <div class="form-group">
            <label>Question Type</label>
            <select id="centralQuestionType" class="form-control" onchange="loadCentralQuestions()">
                <option value="objective">Objective Questions</option>
                <option value="subjective">Subjective Questions</option>
                <option value="theory">Theory Questions</option>
            </select>
        </div>
        <div id="centralQuestionsContainer">
            <div class="loading"><p>Select a subject to view questions...</p></div>
        </div>
    `;
}

async function loadCentralTopics() {
    const subjectId = document.getElementById('centralSubjectSelect')?.value;
    const topicSelect = document.getElementById('centralTopicSelect');

    if (!subjectId || !topicSelect) {
        if (topicSelect) topicSelect.innerHTML = '<option value="">-- Select Topic --</option>';
        return;
    }

    topicSelect.innerHTML = '<option value="">Loading topics...</option>';

    try {
        const data = await callCentralAPI('get_topics', { subject_id: subjectId });
        
        if (data.success && data.topics && data.topics.length > 0) {
            topicSelect.innerHTML = '<option value="0">-- All Topics --</option>';
            data.topics.forEach(topic => {
                topicSelect.innerHTML += `<option value="${topic.id}">${escapeHtml(topic.topic_name)}</option>`;
            });
        } else {
            topicSelect.innerHTML = '<option value="">No topics found</option>';
        }
    } catch (error) {
        console.error('Error loading topics:', error);
        topicSelect.innerHTML = '<option value="">Error loading topics</option>';
    }
}

async function loadCentralQuestions() {
    const subjectId = document.getElementById('centralSubjectSelect')?.value;
    const topicId = document.getElementById('centralTopicSelect')?.value;
    const questionType = document.getElementById('centralQuestionType')?.value;
    const container = document.getElementById('centralQuestionsContainer');

    if (!subjectId || !container) {
        if (container) container.innerHTML = '<div class="loading"><p>Select a subject to view questions...</p></div>';
        return;
    }

    container.innerHTML = '<div class="loading"><div class="spinner"></div><p>Loading questions from central bank...</p></div>';

    try {
        const params = {
            subject_id: subjectId,
            type: questionType
        };
        if (topicId && topicId !== '0') {
            params.topic_id = topicId;
        }
        
        const data = await callCentralAPI('get_questions', params);

        if (data.success && data.questions && data.questions.length > 0) {
            centralQuestions = data.questions;
            selectedCentralQuestionIds.clear();
            renderCentralQuestionsList();
        } else {
            container.innerHTML = '<div class="loading"><p>No questions found for this selection.</p></div>';
        }
    } catch (error) {
        console.error('Error loading questions:', error);
        container.innerHTML = `<div class="alert alert-error">Error loading questions: ${error.message}</div>`;
    }
}

function renderCentralQuestionsList() {
    const container = document.getElementById('centralQuestionsContainer');
    const questions = centralQuestions;

    if (!container) return;

    if (!questions || questions.length === 0) {
        container.innerHTML = '<div class="loading"><p>No central questions found for this selection.</p></div>';
        return;
    }

    let html = `
        <div class="select-all-bar">
            <input type="checkbox" id="selectAllCentralCheckbox" onchange="toggleSelectAllCentral(this)">
            <label for="selectAllCentralCheckbox">Select All</label>
            <span style="margin-left: auto;"><span id="centralSelectedCount">0</span> selected</span>
        </div>
        <div class="question-list">
    `;

    questions.forEach((q, index) => {
        const isChecked = selectedCentralQuestionIds.has(q.id);
        const preview = q.question_text ? q.question_text.substring(0, 150) : '';
        const isAlreadyImported = q.already_imported || false;
        const disabledAttr = isAlreadyImported ? 'disabled' : '';
        const disabledClass = isAlreadyImported ? 'already-imported' : '';

        html += `
            <div class="question-item">
                <div class="question-checkbox">
                    <input type="checkbox" class="central-question-check" value="${q.id}" ${isChecked ? 'checked' : ''} ${disabledAttr}>
                </div>
                <div class="question-text ${disabledClass}">
                    <strong>Q${index + 1}:</strong> ${escapeHtml(preview)}${q.question_text && q.question_text.length > 150 ? '...' : ''}
                    ${isAlreadyImported ? '<span class="badge badge-info"><i class="fas fa-check"></i> Already imported</span>' : '<span class="badge badge-central"><i class="fas fa-database"></i> Central Verified</span>'}
                </div>
            </div>
        `;
    });

    html += `</div>`;
    container.innerHTML = html;

    // Attach event listeners
    document.querySelectorAll('.central-question-check:not(:disabled)').forEach(cb => {
        cb.addEventListener('change', function(e) {
            const value = parseInt(this.value);
            if (this.checked) {
                selectedCentralQuestionIds.add(value);
            } else {
                selectedCentralQuestionIds.delete(value);
            }
            updateCentralSelectedCount();
        });
    });

    updateCentralSelectedCount();
}

function toggleSelectAllCentral(source) {
    const checkboxes = document.querySelectorAll('.central-question-check:not(:disabled)');
    checkboxes.forEach(cb => {
        cb.checked = source.checked;
        const value = parseInt(cb.value);
        if (source.checked) {
            selectedCentralQuestionIds.add(value);
        } else {
            selectedCentralQuestionIds.delete(value);
        }
    });
    updateCentralSelectedCount();
}

function updateCentralSelectedCount() {
    const count = selectedCentralQuestionIds.size;
    const countSpan = document.getElementById('centralSelectedCount');
    if (countSpan) countSpan.textContent = count;
}

async function importSelectedCentralQuestions() {
    if (selectedCentralQuestionIds.size === 0) {
        alert('Please select at least one question to import.');
        return;
    }

    const subjectId = document.getElementById('centralSubjectSelect')?.value;
    const topicId = document.getElementById('centralTopicSelect')?.value;
    const questionType = document.getElementById('centralQuestionType')?.value;

    if (!confirm(`Import ${selectedCentralQuestionIds.size} ${questionType} question(s) from the Central Bank?\n\nThese questions are verified and will be added to your local question bank.`)) {
        return;
    }

    // Create form and submit
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '';

    const importField = document.createElement('input');
    importField.type = 'hidden';
    importField.name = 'import_from_central';
    importField.value = '1';
    form.appendChild(importField);

    const sourceTopicField = document.createElement('input');
    sourceTopicField.type = 'hidden';
    sourceTopicField.name = 'source_topic_id';
    sourceTopicField.value = topicId || '0';
    form.appendChild(sourceTopicField);

    const importTypeField = document.createElement('input');
    importTypeField.type = 'hidden';
    importTypeField.name = 'import_question_type';
    importTypeField.value = questionType;
    form.appendChild(importTypeField);

    selectedCentralQuestionIds.forEach(id => {
        const field = document.createElement('input');
        field.type = 'hidden';
        field.name = 'selected_central_questions[]';
        field.value = id;
        form.appendChild(field);
    });

    document.body.appendChild(form);
    form.submit();
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

        // ============================================
        // MOBILE MENU & UI UTILITIES
        // ============================================

        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebarElement = document.getElementById('sidebar');
        if (mobileMenuBtn) {
            mobileMenuBtn.onclick = () => {
                if (sidebarElement) sidebarElement.classList.toggle('active');
            };
        }

        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768 && sidebarElement && mobileMenuBtn) {
                if (!sidebarElement.contains(e.target) && !mobileMenuBtn.contains(e.target)) {
                    sidebarElement.classList.remove('active');
                }
            }
        });

        // Tab switching
        window.switchTab = function(tabName) {
            const url = new URL(window.location);
            url.searchParams.set('type', tabName);
            window.history.pushState({}, '', url);

            document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

            document.querySelector(`.tab-button[onclick="switchTab('${tabName}')"]`).classList.add('active');
            document.getElementById(tabName + 'Tab').classList.add('active');
        }

        // Auto-hide alert after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Preserve tab from URL
        const urlParams = new URLSearchParams(window.location.search);
        const typeParam = urlParams.get('type');
        if (typeParam && ['objective', 'subjective', 'theory'].includes(typeParam)) {
            window.switchTab(typeParam);
        }
    </script>
</body>

</html>
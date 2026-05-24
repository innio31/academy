<?php
// admin/add_questions.php - Add Questions with Import from Central Bank & CSV Bulk Import
// Central Bank questions are stored locally with is_central = 1 AND school_id IS NULL

session_start();

// Check if admin is logged in (support both session styles)
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /tbis/login.php");
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
            SELECT t.*, s.subject_name, s.id as subject_id,
                   COALESCE(t.class_level, t.class, '') as class
            FROM topics t 
            JOIN subjects s ON t.subject_id = s.id 
            WHERE t.id = ? AND t.school_id = ?
        ");
        $stmt->execute([$topic_id, $school_id]);
        $selected_topic = $stmt->fetch();
    } catch (Exception $e) {
        error_log("Error loading topic: " . $e->getMessage());
    }
}

if (!$selected_topic && !isset($_GET['ajax'])) {
    header("Location: manage-questions.php?error=invalid_topic");
    exit();
}

$message = '';
$message_type = '';

// ============================================
// ENSURE CENTRAL COLUMNS EXIST
// ============================================

try {
    // Add is_central column if not exists
    $tables = ['objective_questions', 'subjective_questions', 'theory_questions'];
    foreach ($tables as $table) {
        $check = $pdo->query("SHOW COLUMNS FROM $table LIKE 'is_central'");
        if ($check->rowCount() == 0) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN is_central TINYINT(1) DEFAULT 0");
            $pdo->exec("ALTER TABLE $table ADD INDEX idx_central (is_central)");
        }

        $check = $pdo->query("SHOW COLUMNS FROM $table LIKE 'central_source_id'");
        if ($check->rowCount() == 0) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN central_source_id INT DEFAULT NULL");
        }

        // Add class column if not exists (for storing the class at import time)
        $check = $pdo->query("SHOW COLUMNS FROM $table LIKE 'class'");
        if ($check->rowCount() == 0) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN class VARCHAR(50) DEFAULT NULL");
        }
    }
} catch (Exception $e) {
    error_log("Error adding columns: " . $e->getMessage());
}

// ============================================
// AJAX HANDLERS FOR CENTRAL BANK IMPORT (LOCAL DB)
// ============================================

// Get central subjects (subjects that have central questions)
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_central_subjects') {
    header('Content-Type: application/json');
    try {
        $question_type = $_GET['question_type'] ?? 'objective';
        $allowed_types = ['objective', 'subjective', 'theory'];
        if (!in_array($question_type, $allowed_types)) {
            throw new Exception("Invalid question type");
        }
        $table = $question_type . '_questions';

        $stmt = $pdo->prepare("
            SELECT DISTINCT s.id, s.subject_name 
            FROM subjects s
            JOIN $table q ON q.subject_id = s.id
            WHERE q.is_central = 1 
              AND q.school_id IS NULL
              AND s.school_id IS NULL
            ORDER BY s.subject_name
        ");
        $stmt->execute();
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'subjects' => $subjects]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Get central topics for a subject
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_central_topics') {
    header('Content-Type: application/json');
    try {
        $subject_id = (int)$_GET['subject_id'];
        $question_type = $_GET['question_type'] ?? 'objective';
        $allowed_types = ['objective', 'subjective', 'theory'];
        if (!in_array($question_type, $allowed_types)) {
            throw new Exception("Invalid question type");
        }
        $table = $question_type . '_questions';

        $stmt = $pdo->prepare("
            SELECT DISTINCT t.id, t.topic_name 
            FROM topics t
            JOIN $table q ON q.topic_id = t.id
            WHERE t.subject_id = ? 
              AND q.is_central = 1 
              AND q.school_id IS NULL
              AND t.school_id IS NULL
            ORDER BY t.topic_name
        ");
        $stmt->execute([$subject_id]);
        $topics = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'topics' => $topics]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Get central questions for a topic
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_central_questions') {
    header('Content-Type: application/json');
    try {
        $source_topic_id = (int)$_GET['topic_id'];
        $question_type = $_GET['question_type'] ?? 'objective';
        $allowed_types = ['objective', 'subjective', 'theory'];
        if (!in_array($question_type, $allowed_types)) {
            throw new Exception("Invalid question type");
        }
        $current_topic_id = (int)$_GET['current_topic_id'];
        $current_school_id = $school_id;
        $table = $question_type . '_questions';

        // Get central questions from source topic
        $stmt = $pdo->prepare("
            SELECT q.* 
            FROM $table q
            WHERE q.topic_id = ? AND q.is_central = 1 AND q.school_id IS NULL
            ORDER BY q.id
        ");
        $stmt->execute([$source_topic_id]);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Mark which questions already exist in current topic (by checking central_source_id or duplicate text)
        foreach ($questions as &$q) {
            // Check if already imported to this topic
            $check = $pdo->prepare("
                SELECT id FROM $table 
                WHERE central_source_id = ? AND topic_id = ? AND school_id = ?
                LIMIT 1
            ");
            $check->execute([$q['id'], $current_topic_id, $current_school_id]);
            $q['already_imported'] = $check->rowCount() > 0;
        }

        echo json_encode(['success' => true, 'questions' => $questions]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ============================================
// HANDLE IMPORT FROM CENTRAL BANK (LOCAL)
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_from_central'])) {
    try {
        $selected_questions = isset($_POST['selected_questions']) ? $_POST['selected_questions'] : [];
        $source_topic_id = (int)$_POST['source_topic_id'];
        $import_type = $_POST['import_question_type'];

        $allowed_import_types = ['objective', 'subjective', 'theory'];
        if (!in_array($import_type, $allowed_import_types)) {
            throw new Exception("Invalid question type");
        }

        if (empty($selected_questions)) {
            throw new Exception("Please select at least one question to import");
        }

        $imported_count = 0;
        $skipped_count = 0;

        foreach ($selected_questions as $question_id) {
            if ($import_type == 'objective') {
                // Get the central question
                $stmt = $pdo->prepare("
                    SELECT * FROM objective_questions 
                    WHERE id = ? AND is_central = 1 AND school_id IS NULL
                ");
                $stmt->execute([$question_id]);
                $q = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($q) {
                    // Check if already imported to this topic
                    $check = $pdo->prepare("
                        SELECT id FROM objective_questions 
                        WHERE central_source_id = ? AND topic_id = ? AND school_id = ?
                        LIMIT 1
                    ");
                    $check->execute([$q['id'], $topic_id, $school_id]);

                    if ($check->fetch()) {
                        $skipped_count++;
                        continue;
                    }

                    // Insert copy with source reference
                    $insert = $pdo->prepare("
                        INSERT INTO objective_questions 
                        (question_text, option_a, option_b, option_c, option_d, correct_answer, 
                         difficulty_level, marks, subject_id, topic_id, class, school_id, 
                         question_image, central_source_id, is_central, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
                    ");
                    $insert->execute([
                        $q['question_text'],
                        $q['option_a'],
                        $q['option_b'],
                        $q['option_c'] ?? '',
                        $q['option_d'] ?? '',
                        $q['correct_answer'],
                        $q['difficulty_level'] ?? 'medium',
                        $q['marks'] ?? 1,
                        $selected_topic['subject_id'],
                        $topic_id,
                        $selected_topic['class'],
                        $school_id,
                        $q['question_image'] ?? null,
                        $q['id']
                    ]);
                    $imported_count++;
                }
            } elseif ($import_type == 'subjective') {
                $stmt = $pdo->prepare("
                    SELECT * FROM subjective_questions 
                    WHERE id = ? AND is_central = 1 AND school_id IS NULL
                ");
                $stmt->execute([$question_id]);
                $q = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($q) {
                    $check = $pdo->prepare("
                        SELECT id FROM subjective_questions 
                        WHERE central_source_id = ? AND topic_id = ? AND school_id = ?
                        LIMIT 1
                    ");
                    $check->execute([$q['id'], $topic_id, $school_id]);

                    if ($check->fetch()) {
                        $skipped_count++;
                        continue;
                    }

                    $insert = $pdo->prepare("
                        INSERT INTO subjective_questions 
                        (question_text, correct_answer, difficulty_level, marks, subject_id, 
                         topic_id, class, school_id, central_source_id, is_central, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
                    ");
                    $insert->execute([
                        $q['question_text'],
                        $q['correct_answer'] ?? '',
                        $q['difficulty_level'] ?? 'medium',
                        $q['marks'] ?? 1,
                        $selected_topic['subject_id'],
                        $topic_id,
                        $selected_topic['class'],
                        $school_id,
                        $q['id']
                    ]);
                    $imported_count++;
                }
            } elseif ($import_type == 'theory') {
                $stmt = $pdo->prepare("
                    SELECT * FROM theory_questions 
                    WHERE id = ? AND is_central = 1 AND school_id IS NULL
                ");
                $stmt->execute([$question_id]);
                $q = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($q) {
                    $check = $pdo->prepare("
                        SELECT id FROM theory_questions 
                        WHERE central_source_id = ? AND topic_id = ? AND school_id = ?
                        LIMIT 1
                    ");
                    $check->execute([$q['id'], $topic_id, $school_id]);

                    if ($check->fetch()) {
                        $skipped_count++;
                        continue;
                    }

                    $insert = $pdo->prepare("
                        INSERT INTO theory_questions 
                        (question_text, question_file, marks, subject_id, topic_id, class, 
                         school_id, central_source_id, is_central, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
                    ");
                    $insert->execute([
                        $q['question_text'],
                        $q['question_file'] ?? null,
                        $q['marks'] ?? 5,
                        $selected_topic['subject_id'],
                        $topic_id,
                        $selected_topic['class'],
                        $school_id,
                        $q['id']
                    ]);
                    $imported_count++;
                }
            }
        }

        $message = "Successfully imported $imported_count question(s) from Central Bank.";
        if ($skipped_count > 0) {
            $message .= " $skipped_count question(s) were already imported to this topic.";
        }
        $message_type = "success";

        // Log activity
        $log_stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, user_type, activity, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $log_stmt->execute([
            $admin_id,
            'admin',
            "Imported $imported_count central $import_type questions to topic: {$selected_topic['topic_name']}",
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        $message = "Import error: " . $e->getMessage();
        $message_type = "error";
    }
}

// ============================================
// HANDLE CSV BULK IMPORT
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csv_import'])) {
    try {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Please upload a valid CSV file");
        }

        $csv_file = $_FILES['csv_file']['tmp_name'];
        $import_type = $_POST['csv_import_type'];

        // Open and read CSV
        $handle = fopen($csv_file, 'r');
        if (!$handle) {
            throw new Exception("Could not read CSV file");
        }

        // Get header row
        $headers = fgetcsv($handle);

        // Expected headers based on type
        if ($import_type == 'objective') {
            $expected = ['question_text', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_answer', 'difficulty_level', 'marks'];
        } elseif ($import_type == 'subjective') {
            $expected = ['question_text', 'correct_answer', 'difficulty_level', 'marks'];
        } else {
            $expected = ['question_text', 'marks'];
        }

        // Map headers to indices
        $header_map = [];
        foreach ($expected as $field) {
            $index = array_search($field, $headers);
            if ($index === false) {
                throw new Exception("CSV missing required column: $field");
            }
            $header_map[$field] = $index;
        }

        $imported_count = 0;
        $error_count = 0;
        $errors = [];
        $row_num = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $row_num++;

            try {
                if ($import_type == 'objective') {
                    $question_text = trim($row[$header_map['question_text']] ?? '');
                    $option_a = trim($row[$header_map['option_a']] ?? '');
                    $option_b = trim($row[$header_map['option_b']] ?? '');
                    $option_c = trim($row[$header_map['option_c']] ?? '');
                    $option_d = trim($row[$header_map['option_d']] ?? '');
                    $correct_answer = strtoupper(trim($row[$header_map['correct_answer']] ?? ''));
                    $difficulty_level = trim($row[$header_map['difficulty_level']] ?? 'medium');
                    $marks = (int)($row[$header_map['marks']] ?? 1);

                    if (empty($question_text) || empty($option_a) || empty($option_b) || empty($correct_answer)) {
                        throw new Exception("Missing required fields");
                    }

                    if (!in_array($correct_answer, ['A', 'B', 'C', 'D'])) {
                        throw new Exception("Correct answer must be A, B, C, or D");
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO objective_questions 
                        (question_text, option_a, option_b, option_c, option_d, correct_answer, 
                         difficulty_level, marks, subject_id, topic_id, class, school_id, is_central, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
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
                        $school_id
                    ]);
                    $imported_count++;
                } elseif ($import_type == 'subjective') {
                    $question_text = trim($row[$header_map['question_text']] ?? '');
                    $correct_answer = trim($row[$header_map['correct_answer']] ?? '');
                    $difficulty_level = trim($row[$header_map['difficulty_level']] ?? 'medium');
                    $marks = (int)($row[$header_map['marks']] ?? 1);

                    if (empty($question_text)) {
                        throw new Exception("Question text is required");
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO subjective_questions 
                        (question_text, correct_answer, difficulty_level, marks, subject_id, 
                         topic_id, class, school_id, is_central, created_at) 
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
                    $imported_count++;
                } else {
                    $question_text = trim($row[$header_map['question_text']] ?? '');
                    $marks = (int)($row[$header_map['marks']] ?? 5);

                    if (empty($question_text)) {
                        throw new Exception("Question text is required");
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO theory_questions 
                        (question_text, marks, subject_id, topic_id, class, school_id, is_central, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
                    ");
                    $stmt->execute([
                        $question_text,
                        $marks,
                        $selected_topic['subject_id'],
                        $topic_id,
                        $selected_topic['class'],
                        $school_id
                    ]);
                    $imported_count++;
                }
            } catch (Exception $e) {
                $error_count++;
                $errors[] = "Row $row_num: " . $e->getMessage();
            }
        }

        fclose($handle);

        $message = "Successfully imported $imported_count $import_type question(s) via CSV.";
        if ($error_count > 0) {
            $message .= " $error_count row(s) had errors.";
        }
        $message_type = "success";
    } catch (Exception $e) {
        $message = "CSV Import error: " . $e->getMessage();
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

// Download CSV Template
if (isset($_GET['download_template']) && $_GET['download_template'] == 1) {
    $type = $_GET['type'] ?? 'objective';

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $type . '_questions_template.csv"');

    $output = fopen('php://output', 'w');

    if ($type == 'objective') {
        fputcsv($output, ['question_text', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_answer', 'difficulty_level', 'marks']);
        fputcsv($output, ['What is 2 + 2?', '3', '4', '5', '6', 'B', 'easy', '1']);
        fputcsv($output, ['Who wrote "Things Fall Apart"?', 'Chinua Achebe', 'Wole Soyinka', 'Chimamanda Adichie', 'Ben Okri', 'A', 'medium', '1']);
    } elseif ($type == 'subjective') {
        fputcsv($output, ['question_text', 'correct_answer', 'difficulty_level', 'marks']);
        fputcsv($output, ['Explain the concept of photosynthesis', 'Photosynthesis is the process by which plants make their own food using sunlight, water, and carbon dioxide.', 'medium', '5']);
    } else {
        fputcsv($output, ['question_text', 'marks']);
        fputcsv($output, ['Discuss the causes of World War I', '10']);
    }

    fclose($output);
    exit();
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

        .btn-warning {
            background: var(--warning-color);
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

        .badge-warning {
            background: #fff3e0;
            color: #ef6c00;
        }

        .central-icon {
            color: var(--success-color);
            margin-left: 8px;
            font-size: 0.8rem;
        }

        /* CSV Import Section */
        .csv-section {
            margin-bottom: 30px;
            border: 2px dashed #ddd;
            background: #fafafa;
        }

        .csv-section .form-header {
            background: #f0f0f0;
            padding: 15px 20px;
            border-radius: 12px 12px 0 0;
        }

        .template-link {
            margin-top: 10px;
            font-size: 0.85rem;
        }

        .template-link a {
            color: var(--primary-color);
            text-decoration: none;
        }

        .template-link a:hover {
            text-decoration: underline;
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

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-icon">
                <?php
                // Check for logo at multiple possible locations
                $logo_path = '/tbis/assets/logos/logo.png';
                $logo_full_path = $_SERVER['DOCUMENT_ROOT'] . $logo_path;

                // Also check if SCHOOL_LOGO constant is defined (for backward compatibility)
                if (defined('SCHOOL_LOGO') && SCHOOL_LOGO && file_exists($_SERVER['DOCUMENT_ROOT'] . SCHOOL_LOGO)) {
                    $logo_path = SCHOOL_LOGO;
                } elseif (file_exists($logo_full_path)) {
                    // Use the default logo path
                    $logo_path = '/tbis/assets/logos/logo.png';
                } else {
                    $logo_path = null;
                }

                if ($logo_path && file_exists($_SERVER['DOCUMENT_ROOT'] . $logo_path)):
                ?>
                    <img src="<?php echo $logo_path; ?>" alt="<?php echo htmlspecialchars($school_name); ?>" style="width: 40px; height: 40px; object-fit: contain; border-radius: 8px;">
                <?php else: ?>
                    <i class="fas fa-graduation-cap"></i>
                <?php endif; ?>
            </div>
            <div class="logo-text">
                <h3><?php echo htmlspecialchars($school_name); ?></h3>
                <p>Admin Panel</p>
            </div>
        </div>
        <div class="admin-info">
            <h4><?php echo htmlspecialchars($admin_name); ?></h4>
            <p><?php echo ucfirst($admin_role); ?></p>
        </div>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-students.php"><i class="fas fa-users"></i> Manage Students</a></li>
            <li><a href="manage-staff.php"><i class="fas fa-chalkboard-teacher"></i> Manage Staff</a></li>
            <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Manage Subjects</a></li>
            <li><a href="manage-topics.php"><i class="fas fa-list"></i> Manage Topics</a></li>
            <li><a href="manage-questions.php"><i class="fas fa-question-circle"></i> Manage Questions</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="attendance.php"><i class="fas fa-calendar-check"></i> Attendance</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="sync.php"><i class="fas fa-sync-alt"></i> Sync</a></li>
            <li><a href="../tbis/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1>Add Questions</h1>
                <p>Topic: <strong><?php echo htmlspecialchars($selected_topic['topic_name']); ?></strong> |
                    Subject: <?php echo htmlspecialchars($selected_topic['subject_name']); ?></p>
            </div>
            <button class="logout-btn" onclick="window.location.href='/tbis/logout.php'"><i class="fas fa-sign-out-alt"></i> Logout</button>
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
                <span class="meta-item"><i class="fas fa-database"></i> <a href="#" onclick="openCentralImportModal()">Import from Central Bank</a> <i class="fas fa-check-circle central-icon" title="Verified by Developer Team"></i></span>
                <span class="meta-item"><i class="fas fa-file-csv"></i> <a href="#" onclick="document.getElementById('csvModal').classList.add('active')">Bulk Import from CSV/Excel</a></span>
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
                <h3><i class="fas fa-database"></i> Import from Central Bank <span class="badge badge-central">Verified Questions</span></h3>
                <button class="close-modal" onclick="closeCentralImportModal()">&times;</button>
            </div>
            <div class="modal-body" id="centralImportModalBody">
                <div class="loading" id="centralImportLoading">
                    <div class="spinner"></div>
                    <p>Loading central question bank...</p>
                </div>
                <div id="centralImportContent" style="display: none;">
                    <div class="form-group">
                        <label>Select Subject</label>
                        <select id="centralSubjectSelect" class="form-control" onchange="loadCentralTopics()">
                            <option value="">-- Select Subject --</option>
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
                        <div class="loading" style="padding: 20px;">
                            <div class="spinner" style="width: 30px; height: 30px;"></div>
                            <p>Select a subject and topic to view questions...</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeCentralImportModal()">Cancel</button>
                <button class="btn btn-success" id="importSelectedBtn" onclick="importSelectedCentralQuestions()">Import Selected</button>
            </div>
        </div>
    </div>

    <!-- CSV Bulk Import Modal -->
    <div class="modal" id="csvModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-file-csv"></i> Bulk Import Questions from CSV/Excel</h3>
                <button class="close-modal" onclick="closeCSVModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="info-note" style="background: #e8f4fd; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                    <i class="fas fa-info-circle"></i>
                    <span>Upload a CSV file with your questions. Download the template below for the correct format.</span>
                </div>

                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Question Type</label>
                        <select name="csv_import_type" id="csv_import_type" class="form-control" required>
                            <option value="objective">Objective Questions</option>
                            <option value="subjective">Subjective Questions</option>
                            <option value="theory">Theory Questions</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>CSV File</label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                    </div>

                    <div class="template-link">
                        <i class="fas fa-download"></i>
                        <a href="#" onclick="downloadTemplate()">Download CSV Template</a> for the selected question type
                    </div>

                    <div class="form-actions" style="margin-top: 20px;">
                        <button type="button" class="btn btn-secondary" onclick="closeCSVModal()">Cancel</button>
                        <button type="submit" name="csv_import" class="btn btn-success"><i class="fas fa-upload"></i> Import Questions</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // ============================================
        // MOBILE MENU & UI UTILITIES
        // ============================================

        // Mobile menu toggle
        const mobileBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        if (mobileBtn) {
            mobileBtn.onclick = () => sidebar.classList.toggle('active');
        }

        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768 && sidebar && mobileBtn) {
                if (!sidebar.contains(e.target) && !mobileBtn.contains(e.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

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
            switchTab(typeParam);
        }

        // ============================================
        // CENTRAL BANK IMPORT FUNCTIONS (LOCAL DB)
        // ============================================

        const currentTopicId = <?php echo $topic_id; ?>;

        async function openCentralImportModal() {
            const modal = document.getElementById('centralImportModal');
            if (modal) {
                modal.classList.add('active');
                await loadCentralSubjectsFromLocal();
            }
        }

        function closeCentralImportModal() {
            const modal = document.getElementById('centralImportModal');
            if (modal) {
                modal.classList.remove('active');
            }
            selectedCentralQuestionIds.clear();
        }

        function closeCSVModal() {
            document.getElementById('csvModal').classList.remove('active');
        }

        function downloadTemplate() {
            const type = document.getElementById('csv_import_type').value;
            window.location.href = `add_questions.php?topic_id=<?php echo $topic_id; ?>&download_template=1&type=${type}`;
        }

        let selectedCentralQuestionIds = new Set();

        async function loadCentralSubjectsFromLocal() {
            const container = document.getElementById('centralImportContent');
            const loadingDiv = document.getElementById('centralImportLoading');

            if (!container) return;

            if (loadingDiv) {
                loadingDiv.style.display = 'block';
                container.style.display = 'none';
            }

            const questionType = document.getElementById('centralQuestionType')?.value || 'objective';

            try {
                const response = await fetch(`add_questions.php?ajax=get_central_subjects&question_type=${questionType}`);
                const data = await response.json();

                if (data.success) {
                    const subjectSelect = document.getElementById('centralSubjectSelect');
                    if (subjectSelect) {
                        subjectSelect.innerHTML = '<option value="">-- Select Subject --</option>';
                        data.subjects.forEach(subject => {
                            subjectSelect.innerHTML += `<option value="${subject.id}">${escapeHtml(subject.subject_name)}</option>`;
                        });
                    }

                    if (loadingDiv) {
                        loadingDiv.style.display = 'none';
                        container.style.display = 'block';
                    }
                } else {
                    throw new Error(data.error || 'Failed to load subjects');
                }
            } catch (error) {
                const errorMsg = `<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> Error: ${error.message}<br><br>Make sure central questions have been added to the database with is_central = 1.</div>`;
                if (loadingDiv) {
                    loadingDiv.innerHTML = errorMsg;
                } else {
                    container.innerHTML = errorMsg;
                }
            }
        }

        async function loadCentralTopics() {
            const subjectId = document.getElementById('centralSubjectSelect')?.value;
            const topicSelect = document.getElementById('centralTopicSelect');
            const questionType = document.getElementById('centralQuestionType')?.value || 'objective';

            if (!subjectId || !topicSelect) {
                if (topicSelect) topicSelect.innerHTML = '<option value="">-- Select Topic --</option>';
                return;
            }

            topicSelect.innerHTML = '<option value="">Loading topics...</option>';

            try {
                const response = await fetch(`add_questions.php?ajax=get_central_topics&subject_id=${subjectId}&question_type=${questionType}`);
                const data = await response.json();

                if (data.success) {
                    topicSelect.innerHTML = '<option value="">-- Select Topic --</option>';
                    data.topics.forEach(topic => {
                        topicSelect.innerHTML += `<option value="${topic.id}">${escapeHtml(topic.topic_name)}</option>`;
                    });
                } else {
                    topicSelect.innerHTML = '<option value="">Error loading topics</option>';
                }
            } catch (error) {
                topicSelect.innerHTML = `<option value="">Error: ${error.message}</option>`;
            }
        }

        async function loadCentralQuestions() {
            const topicId = document.getElementById('centralTopicSelect')?.value;
            const questionType = document.getElementById('centralQuestionType')?.value || 'objective';
            const container = document.getElementById('centralQuestionsContainer');

            if (!topicId || !container) {
                if (container) container.innerHTML = '<div class="loading" style="padding: 20px;"><p>Select a topic to view questions...</p></div>';
                return;
            }

            container.innerHTML = '<div class="loading" style="padding: 20px;"><div class="spinner" style="width: 30px; height: 30px;"></div><p>Loading questions...</p></div>';

            try {
                const response = await fetch(`add_questions.php?ajax=get_central_questions&topic_id=${topicId}&question_type=${questionType}&current_topic_id=${currentTopicId}`);
                const data = await response.json();

                if (data.success && data.questions) {
                    selectedCentralQuestionIds.clear();
                    renderCentralQuestionsList(data.questions);
                } else {
                    container.innerHTML = `<div class="loading" style="padding: 20px;"><p>No questions found for this topic.</p></div>`;
                }
            } catch (error) {
                container.innerHTML = `<div class="alert alert-error">Error: ${error.message}</div>`;
            }
        }

        function renderCentralQuestionsList(questions) {
            const container = document.getElementById('centralQuestionsContainer');

            if (questions.length === 0) {
                container.innerHTML = '<div class="loading" style="padding: 20px;"><p>No questions available for this topic.</p></div>';
                return;
            }

            let html = `
            <div class="select-all-bar">
                <input type="checkbox" id="selectAllCentralCheckbox" onchange="toggleSelectAllCentral(this)">
                <label for="selectAllCentralCheckbox">Select All New Questions</label>
                <span style="margin-left: auto;"><span id="centralSelectedCount">0</span> selected</span>
            </div>
            <div class="question-list">
        `;

            questions.forEach((q, index) => {
                const isChecked = selectedCentralQuestionIds.has(q.id);
                const isImported = q.already_imported || false;
                const preview = q.question_text ? q.question_text.substring(0, 150) : '';
                const disabledAttr = isImported ? 'disabled' : '';
                const disabledClass = isImported ? 'already-imported' : '';

                html += `
                <div class="question-item">
                    <div class="question-checkbox">
                        <input type="checkbox" class="central-question-check" value="${q.id}" ${isChecked ? 'checked' : ''} ${disabledAttr} data-question-id="${q.id}">
                    </div>
                    <div class="question-text ${disabledClass}">
                        <strong>Q${index + 1}:</strong> ${escapeHtml(preview)}${q.question_text && q.question_text.length > 150 ? '...' : ''}
                        ${isImported ? '<span class="badge badge-info"><i class="fas fa-check"></i> Already imported</span>' : '<span class="badge badge-central"><i class="fas fa-database"></i> Central Verified</span>'}
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
                    updateSelectAllCentralCheckbox();
                });
            });

            updateCentralSelectedCount();
            updateSelectAllCentralCheckbox();
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
            updateSelectAllCentralCheckbox();
        }

        function updateCentralSelectedCount() {
            const count = selectedCentralQuestionIds.size;
            const countSpan = document.getElementById('centralSelectedCount');
            if (countSpan) countSpan.textContent = count;
        }

        function updateSelectAllCentralCheckbox() {
            const selectAll = document.getElementById('selectAllCentralCheckbox');
            if (selectAll) {
                const total = document.querySelectorAll('.central-question-check:not(:disabled)').length;
                const selected = selectedCentralQuestionIds.size;
                if (selected === 0) {
                    selectAll.checked = false;
                    selectAll.indeterminate = false;
                } else if (selected === total && total > 0) {
                    selectAll.checked = true;
                    selectAll.indeterminate = false;
                } else {
                    selectAll.indeterminate = true;
                }
            }
        }

        function importSelectedCentralQuestions() {
            if (selectedCentralQuestionIds.size === 0) {
                alert('Please select at least one question to import.');
                return;
            }

            const topicId = document.getElementById('centralTopicSelect')?.value;
            const questionType = document.getElementById('centralQuestionType')?.value;

            if (!confirm(`Import ${selectedCentralQuestionIds.size} ${questionType} question(s) from the Central Bank?\n\nThese questions are verified and will be added to your local question bank.`)) {
                return;
            }

            // Create a form and submit via POST
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
            importTypeField.value = questionType || '';
            form.appendChild(importTypeField);

            selectedCentralQuestionIds.forEach(id => {
                const field = document.createElement('input');
                field.type = 'hidden';
                field.name = 'selected_questions[]';
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
    </script>
</body>

</html>
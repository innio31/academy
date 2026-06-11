<?php
// staff/manage-questions.php - Staff Manage Questions (Full CRUD for assigned subjects/topics)
session_start();

// Check if staff is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: /ida/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$staff_id = $_SESSION['user_id'];
$staff_name = $_SESSION['user_name'] ?? 'Staff Member';
$staff_role = $_SESSION['staff_role'] ?? 'staff';

$message = '';
$message_type = '';
$current_tab = $_GET['type'] ?? 'objective';

// Get the staff_id string from staff table (used in staff_subjects)
$staff_id_string = '';
try {
    $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
    $stmt->execute([$staff_id, $school_id]);
    $staff_id_string = $stmt->fetchColumn();
    if (!$staff_id_string) {
        $staff_id_string = $_SESSION['staff_id'] ?? $staff_id;
    }
} catch (Exception $e) {
    $staff_id_string = $_SESSION['staff_id'] ?? $staff_id;
}

// Get assigned subject IDs for this staff member
$assigned_subject_ids = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT subject_id 
        FROM staff_subjects 
        WHERE staff_id = ? AND school_id = ?
    ");
    $stmt->execute([$staff_id_string, $school_id]);
    $assigned_subject_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Error fetching staff subjects: " . $e->getMessage());
}

// If no subjects assigned, redirect or show error
$has_assigned_subjects = !empty($assigned_subject_ids);

// Get parameters
$selected_subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
$topic_id = isset($_GET['topic_id']) ? (int)$_GET['topic_id'] : 0;
$selected_topic = null;
$selected_subject = null;

// Get selected subject details (only if assigned)
if ($selected_subject_id && in_array($selected_subject_id, $assigned_subject_ids)) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM subjects WHERE id = ? AND school_id = ?");
        $stmt->execute([$selected_subject_id, $school_id]);
        $selected_subject = $stmt->fetch();
    } catch (Exception $e) {
        $message = "Error loading subject: " . $e->getMessage();
        $message_type = "error";
    }
}

// Get selected topic and details (only if subject is assigned)
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
            // Verify staff has access to this topic's subject
            if (!in_array($selected_topic['subject_id'], $assigned_subject_ids)) {
                $selected_topic = null;
                $message = "You don't have access to this topic";
                $message_type = "error";
            } else {
                $selected_subject_id = $selected_topic['subject_id'];
                $stmt = $pdo->prepare("SELECT * FROM subjects WHERE id = ? AND school_id = ?");
                $stmt->execute([$selected_subject_id, $school_id]);
                $selected_subject = $stmt->fetch();
            }
        }
    } catch (Exception $e) {
        $message = "Error loading topic: " . $e->getMessage();
        $message_type = "error";
    }
}

// Handle Question Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_question'])) {
    $question_id = (int)$_POST['question_id'];
    $question_type = $_POST['question_type'];

    try {
        $table_name = '';
        $file_column = '';

        switch ($question_type) {
            case 'objective':
                $table_name = 'objective_questions';
                $file_column = 'question_image';
                break;
            case 'subjective':
                $table_name = 'subjective_questions';
                $file_column = '';
                break;
            case 'theory':
                $table_name = 'theory_questions';
                $file_column = 'question_file';
                break;
            default:
                throw new Exception("Invalid question type");
        }

        // First verify staff has access to this question via topic -> subject
        $sql = "SELECT q.*, t.subject_id 
                FROM $table_name q
                JOIN topics t ON q.topic_id = t.id
                WHERE q.id = ? AND q.school_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$question_id, $school_id]);
        $question = $stmt->fetch();

        if (!$question) {
            throw new Exception("Question not found or access denied");
        }

        // Verify staff has access to the subject
        if (!in_array($question['subject_id'], $assigned_subject_ids)) {
            throw new Exception("You don't have permission to delete this question");
        }

        // Delete file if exists
        if ($file_column && !empty($question[$file_column])) {
            $file_path = '../' . $question[$file_column];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }

        $delete_sql = "DELETE FROM $table_name WHERE id = ? AND school_id = ?";
        $stmt = $pdo->prepare($delete_sql);
        $stmt->execute([$question_id, $school_id]);

        $message = "Question deleted successfully!";
        $message_type = "success";
        
        // Redirect to refresh the page
        header("Location: manage-questions.php?topic_id=$topic_id&type=$current_tab&message=" . urlencode($message));
        exit();
    } catch (Exception $e) {
        $message = "Error deleting question: " . $e->getMessage();
        $message_type = "error";
    }
}

// Handle Question Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_question'])) {
    $question_id = (int)$_POST['question_id'];
    $question_type = $_POST['question_type'];
    
    try {
        $table_name = '';
        switch ($question_type) {
            case 'objective':
                $table_name = 'objective_questions';
                break;
            case 'subjective':
                $table_name = 'subjective_questions';
                break;
            case 'theory':
                $table_name = 'theory_questions';
                break;
            default:
                throw new Exception("Invalid question type");
        }
        
        // Verify staff has access
        $sql = "SELECT q.*, t.subject_id 
                FROM $table_name q
                JOIN topics t ON q.topic_id = t.id
                WHERE q.id = ? AND q.school_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$question_id, $school_id]);
        $existing = $stmt->fetch();
        
        if (!$existing || !in_array($existing['subject_id'], $assigned_subject_ids)) {
            throw new Exception("You don't have permission to edit this question");
        }
        
        if ($question_type === 'objective') {
            $question_text = trim($_POST['question_text']);
            $option_a = trim($_POST['option_a']);
            $option_b = trim($_POST['option_b']);
            $option_c = trim($_POST['option_c'] ?? '');
            $option_d = trim($_POST['option_d'] ?? '');
            $correct_answer = $_POST['correct_answer'];
            $difficulty_level = $_POST['difficulty_level'] ?? 'medium';
            $marks = (int)$_POST['marks'];
            
            // Handle image upload
            $question_image = $existing['question_image'];
            if (isset($_FILES['question_image']) && $_FILES['question_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/questions/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                
                // Delete old image
                if ($question_image && file_exists('../' . $question_image)) {
                    unlink('../' . $question_image);
                }
                
                $ext = strtolower(pathinfo($_FILES['question_image']['name'], PATHINFO_EXTENSION));
                $filename = 'obj_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                move_uploaded_file($_FILES['question_image']['tmp_name'], $upload_dir . $filename);
                $question_image = 'uploads/questions/' . $filename;
            }
            
            $stmt = $pdo->prepare("UPDATE objective_questions SET 
                question_text = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?,
                correct_answer = ?, difficulty_level = ?, marks = ?, question_image = ?
                WHERE id = ?");
            $stmt->execute([$question_text, $option_a, $option_b, $option_c, $option_d,
                           $correct_answer, $difficulty_level, $marks, $question_image, $question_id]);
                           
        } elseif ($question_type === 'subjective') {
            $question_text = trim($_POST['question_text']);
            $correct_answer = trim($_POST['correct_answer'] ?? '');
            $difficulty_level = $_POST['difficulty_level'] ?? 'medium';
            $marks = (int)$_POST['marks'];
            
            $stmt = $pdo->prepare("UPDATE subjective_questions SET 
                question_text = ?, correct_answer = ?, difficulty_level = ?, marks = ?
                WHERE id = ?");
            $stmt->execute([$question_text, $correct_answer, $difficulty_level, $marks, $question_id]);
            
        } elseif ($question_type === 'theory') {
            $question_text = trim($_POST['question_text']);
            $difficulty_level = $_POST['difficulty_level'] ?? 'medium';
            $marks = (int)$_POST['marks'];
            $question_file = $existing['question_file'];
            
            if (isset($_FILES['question_file']) && $_FILES['question_file']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/questions/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                
                if ($question_file && file_exists('../' . $question_file)) {
                    unlink('../' . $question_file);
                }
                
                $ext = pathinfo($_FILES['question_file']['name'], PATHINFO_EXTENSION);
                $filename = 'theory_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                move_uploaded_file($_FILES['question_file']['tmp_name'], $upload_dir . $filename);
                $question_file = 'uploads/questions/' . $filename;
            }
            
            $stmt = $pdo->prepare("UPDATE theory_questions SET 
                question_text = ?, question_file = ?, difficulty_level = ?, marks = ?
                WHERE id = ?");
            $stmt->execute([$question_text, $question_file, $difficulty_level, $marks, $question_id]);
        }
        
        $message = "Question updated successfully!";
        $message_type = "success";
        header("Location: manage-questions.php?topic_id=$topic_id&type=$current_tab&message=" . urlencode($message));
        exit();
        
    } catch (Exception $e) {
        $message = "Error updating question: " . $e->getMessage();
        $message_type = "error";
    }
}

// Get all subjects assigned to staff for dropdown
$subjects = [];
if ($has_assigned_subjects) {
    $placeholders = str_repeat('?,', count($assigned_subject_ids) - 1) . '?';
    try {
        $stmt = $pdo->prepare("
            SELECT s.*, 
                   (SELECT COUNT(*) FROM topics WHERE subject_id = s.id AND school_id = s.school_id) as topic_count
            FROM subjects s
            WHERE s.id IN ($placeholders) AND s.school_id = ?
            ORDER BY s.subject_name
        ");
        $params = array_merge($assigned_subject_ids, [$school_id]);
        $stmt->execute($params);
        $subjects = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error loading subjects: " . $e->getMessage());
    }
}

// Get topics for selected subject (only if assigned)
$topics = [];
if ($selected_subject_id && in_array($selected_subject_id, $assigned_subject_ids)) {
    try {
        $stmt = $pdo->prepare("
            SELECT t.* 
            FROM topics t
            WHERE t.subject_id = ? AND t.school_id = ?
            ORDER BY 
                CASE 
                    WHEN t.term = 'First' THEN 1
                    WHEN t.term = 'Second' THEN 2
                    WHEN t.term = 'Third' THEN 3
                    ELSE 4
                END,
                t.topic_name
        ");
        $stmt->execute([$selected_subject_id, $school_id]);
        $topics = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error loading topics: " . $e->getMessage());
    }
}

// Get questions for selected topic
$objective_questions = [];
$subjective_questions = [];
$theory_questions = [];

if ($selected_topic && in_array($selected_topic['subject_id'], $assigned_subject_ids)) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM objective_questions WHERE topic_id = ? AND school_id = ? ORDER BY id DESC");
        $stmt->execute([$topic_id, $school_id]);
        $objective_questions = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT * FROM subjective_questions WHERE topic_id = ? AND school_id = ? ORDER BY id DESC");
        $stmt->execute([$topic_id, $school_id]);
        $subjective_questions = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT * FROM theory_questions WHERE topic_id = ? AND school_id = ? ORDER BY id DESC");
        $stmt->execute([$topic_id, $school_id]);
        $theory_questions = $stmt->fetchAll();
    } catch (Exception $e) {
        $message = "Error loading questions: " . $e->getMessage();
        $message_type = "error";
    }
}

// Include staff sidebar
require_once 'includes/staff_sidebar.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($school_name); ?> - Manage Questions</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --primary-dark: #1a5a8a;
            --secondary-color: #3498db;
            --success: #27ae60;
            --success-light: #d5f4e6;
            --warning: #f39c12;
            --warning-light: #fef5e7;
            --danger: #e74c3c;
            --danger-light: #fbe9e7;
            --info: #3498db;
            --info-light: #eaf6ff;
            --purple: #9b59b6;
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

        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

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
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 4px;
        }

        .header-title p {
            color: var(--gray-600);
            font-size: 0.8rem;
        }

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

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--gray-300);
            color: var(--gray-800);
        }

        .filter-section {
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-bottom: 24px;
        }

        .filter-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-group label {
            display: block;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--gray-600);
            margin-bottom: 6px;
        }

        .filter-group select {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 0.85rem;
            background: white;
            cursor: pointer;
        }

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
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
        }

        .stats-row {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .stat-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 15px 20px;
            flex: 1;
            min-width: 100px;
            text-align: center;
            box-shadow: var(--shadow-sm);
            cursor: pointer;
            transition: all 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-card.active {
            border-bottom: 3px solid var(--primary-color);
        }

        .stat-card .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .stat-card .stat-label {
            font-size: 0.7rem;
            color: var(--gray-600);
        }

        .questions-grid {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .question-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 18px;
            box-shadow: var(--shadow-sm);
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid var(--gray-200);
        }

        .question-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary-color);
        }

        .question-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }

        .question-id {
            font-size: 0.7rem;
            font-family: monospace;
            background: var(--gray-100);
            padding: 3px 8px;
            border-radius: 15px;
            color: var(--gray-600);
        }

        .question-text-preview {
            font-size: 0.85rem;
            color: var(--gray-800);
            line-height: 1.4;
            margin-bottom: 12px;
        }

        .options-preview {
            margin: 10px 0;
            padding: 10px;
            background: var(--gray-50);
            border-radius: var(--radius-md);
        }

        .option-item {
            font-size: 0.75rem;
            padding: 4px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .correct-option {
            background: var(--success-light);
            color: var(--success);
            padding: 2px 6px;
            border-radius: 12px;
            font-size: 0.65rem;
            margin-left: 8px;
        }

        .marks-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.7rem;
            background: var(--info-light);
            color: var(--info);
        }

        .file-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.7rem;
            background: var(--warning-light);
            color: var(--warning);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: var(--radius-lg);
        }

        .empty-state i {
            font-size: 48px;
            color: var(--gray-300);
            margin-bottom: 16px;
        }

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
            max-width: 800px;
            max-height: 85vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 18px 20px;
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
            padding: 20px;
        }

        .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .info-row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid var(--gray-200);
        }

        .info-label {
            width: 120px;
            font-weight: 600;
            color: var(--gray-600);
            font-size: 0.8rem;
        }

        .info-value {
            flex: 1;
            color: var(--gray-800);
            font-size: 0.85rem;
        }

        .modal-action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid var(--gray-200);
        }

        .modal-action-btn {
            flex: 1;
            min-width: 100px;
            justify-content: center;
        }

        .edit-form-group {
            margin-bottom: 15px;
        }

        .edit-form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            font-size: 0.85rem;
        }

        .edit-form-control {
            width: 100%;
            padding: 8px 12px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 0.85rem;
        }

        .edit-form-control:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .edit-options-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 15px;
        }

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
            background: var(--success-light);
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .alert-error {
            background: var(--danger-light);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }

        .loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
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

            .filter-row {
                flex-direction: column;
            }

            .filter-group {
                width: 100%;
            }

            .info-row {
                flex-direction: column;
            }

            .info-label {
                width: 100%;
                margin-bottom: 5px;
            }

            .modal-action-buttons {
                flex-direction: column;
            }

            .modal-action-btn {
                width: 100%;
            }

            .stats-row {
                flex-direction: column;
            }

            .edit-options-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-question-circle"></i> Manage Questions</h1>
                <p><?php echo $selected_topic ? 'Topic: ' . htmlspecialchars($selected_topic['topic_name']) : 'Select a subject and topic to manage questions'; ?></p>
            </div>
            <?php if ($selected_topic): ?>
                <a href="add_questions.php?topic_id=<?php echo $topic_id; ?>" class="btn btn-primary">
                    <i class="fas fa-plus-circle"></i> Add Questions
                </a>
            <?php endif; ?>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Subject & Topic Selection -->
        <div class="filter-section">
            <form method="GET" id="selectionForm">
                <div class="filter-row">
                    <div class="filter-group">
                        <label><i class="fas fa-book"></i> Select Subject</label>
                        <select name="subject_id" id="subject_id" onchange="this.form.submit()">
                            <option value="">-- Select a subject --</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>" <?php echo ($selected_subject_id == $subject['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    <?php if ($subject['topic_count'] > 0): ?>
                                        (<?php echo $subject['topic_count']; ?> topics)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-list"></i> Select Topic</label>
                        <select name="topic_id" id="topic_id" onchange="this.form.submit()" <?php echo empty($topics) ? 'disabled' : ''; ?>>
                            <option value="">-- Select a topic --</option>
                            <?php foreach ($topics as $topic): ?>
                                <option value="<?php echo $topic['id']; ?>" <?php echo ($topic_id == $topic['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($topic['topic_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($selected_subject_id && empty($topics)): ?>
                            <small style="color: var(--warning); display: block; margin-top: 5px;">
                                <i class="fas fa-exclamation-triangle"></i> No topics found.
                                <a href="manage-topics.php?subject_id=<?php echo $selected_subject_id; ?>">Add topics first</a>
                            </small>
                        <?php endif; ?>
                    </div>
                    <?php if ($selected_topic): ?>
                        <div class="filter-group">
                            <a href="manage-questions.php" class="btn btn-outline" style="margin-top: 26px;">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php if ($selected_topic): ?>
            <!-- Topic Info Card -->
            <div class="topic-info-card">
                <h2><i class="fas fa-bookmark"></i> <?php echo htmlspecialchars($selected_topic['topic_name']); ?></h2>
                <p><i class="fas fa-book"></i> Subject: <?php echo htmlspecialchars($selected_topic['subject_name']); ?></p>
                <?php if ($selected_topic['term']): ?>
                    <p><i class="fas fa-calendar-alt"></i> Term: <?php echo $selected_topic['term']; ?> Term</p>
                <?php endif; ?>
                <?php if ($selected_topic['description']): ?>
                    <p><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($selected_topic['description']); ?></p>
                <?php endif; ?>
                <div class="topic-meta">
                    <span class="meta-item"><i class="fas fa-check-circle"></i> Objective: <?php echo count($objective_questions); ?></span>
                    <span class="meta-item"><i class="fas fa-edit"></i> Subjective: <?php echo count($subjective_questions); ?></span>
                    <span class="meta-item"><i class="fas fa-file-alt"></i> Theory: <?php echo count($theory_questions); ?></span>
                </div>
            </div>

            <!-- Stats Row (Tab Switchers) -->
            <div class="stats-row">
                <div class="stat-card <?php echo $current_tab === 'objective' ? 'active' : ''; ?>" onclick="switchTab('objective')">
                    <div class="stat-value"><?php echo count($objective_questions); ?></div>
                    <div class="stat-label">Objective</div>
                </div>
                <div class="stat-card <?php echo $current_tab === 'subjective' ? 'active' : ''; ?>" onclick="switchTab('subjective')">
                    <div class="stat-value"><?php echo count($subjective_questions); ?></div>
                    <div class="stat-label">Subjective</div>
                </div>
                <div class="stat-card <?php echo $current_tab === 'theory' ? 'active' : ''; ?>" onclick="switchTab('theory')">
                    <div class="stat-value"><?php echo count($theory_questions); ?></div>
                    <div class="stat-label">Theory</div>
                </div>
            </div>

            <!-- Objective Questions Section -->
            <div id="objective-section" style="display: <?php echo $current_tab === 'objective' ? 'block' : 'none'; ?>">
                <?php if (empty($objective_questions)): ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <h3>No Objective Questions</h3>
                        <p>Click "Add Questions" to create your first objective question.</p>
                        <a href="add_questions.php?topic_id=<?php echo $topic_id; ?>&type=objective" class="btn btn-primary" style="margin-top: 15px;">
                            <i class="fas fa-plus"></i> Add Objective Question
                        </a>
                    </div>
                <?php else: ?>
                    <div class="questions-grid">
                        <?php foreach ($objective_questions as $q): ?>
                            <div class="question-card" data-question-id="<?php echo $q['id']; ?>" data-question-type="objective">
                                <div class="question-card-header">
                                    <span class="question-id">ID: <?php echo $q['id']; ?></span>
                                    <span class="marks-badge"><i class="fas fa-star"></i> <?php echo $q['marks']; ?> marks</span>
                                </div>
                                <div class="question-text-preview">
                                    <?php echo htmlspecialchars(substr($q['question_text'], 0, 120)) . (strlen($q['question_text']) > 120 ? '...' : ''); ?>
                                </div>
                                <div class="options-preview">
                                    <div class="option-item"><strong>A:</strong> <?php echo htmlspecialchars(substr($q['option_a'], 0, 40)); ?></div>
                                    <div class="option-item"><strong>B:</strong> <?php echo htmlspecialchars(substr($q['option_b'], 0, 40)); ?></div>
                                    <div class="option-item"><strong>C:</strong> <?php echo htmlspecialchars(substr($q['option_c'], 0, 40)); ?></div>
                                    <div class="option-item"><strong>D:</strong> <?php echo htmlspecialchars(substr($q['option_d'], 0, 40)); ?>
                                        <span class="correct-option">✓ Correct: <?php echo $q['correct_answer']; ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Subjective Questions Section -->
            <div id="subjective-section" style="display: <?php echo $current_tab === 'subjective' ? 'block' : 'none'; ?>">
                <?php if (empty($subjective_questions)): ?>
                    <div class="empty-state">
                        <i class="fas fa-edit"></i>
                        <h3>No Subjective Questions</h3>
                        <p>Click "Add Questions" to create your first subjective question.</p>
                        <a href="add_questions.php?topic_id=<?php echo $topic_id; ?>&type=subjective" class="btn btn-primary" style="margin-top: 15px;">
                            <i class="fas fa-plus"></i> Add Subjective Question
                        </a>
                    </div>
                <?php else: ?>
                    <div class="questions-grid">
                        <?php foreach ($subjective_questions as $q): ?>
                            <div class="question-card" data-question-id="<?php echo $q['id']; ?>" data-question-type="subjective">
                                <div class="question-card-header">
                                    <span class="question-id">ID: <?php echo $q['id']; ?></span>
                                    <span class="marks-badge"><i class="fas fa-star"></i> <?php echo $q['marks']; ?> marks</span>
                                </div>
                                <div class="question-text-preview">
                                    <?php echo htmlspecialchars(substr($q['question_text'], 0, 150)) . (strlen($q['question_text']) > 150 ? '...' : ''); ?>
                                </div>
                                <div class="options-preview" style="background: var(--success-light);">
                                    <div class="option-item"><strong>Answer Guide:</strong> <?php echo htmlspecialchars(substr($q['correct_answer'] ?? '', 0, 80)) . (strlen($q['correct_answer'] ?? '') > 80 ? '...' : ''); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Theory Questions Section -->
            <div id="theory-section" style="display: <?php echo $current_tab === 'theory' ? 'block' : 'none'; ?>">
                <?php if (empty($theory_questions)): ?>
                    <div class="empty-state">
                        <i class="fas fa-file-alt"></i>
                        <h3>No Theory Questions</h3>
                        <p>Click "Add Questions" to create your first theory question.</p>
                        <a href="add_questions.php?topic_id=<?php echo $topic_id; ?>&type=theory" class="btn btn-primary" style="margin-top: 15px;">
                            <i class="fas fa-plus"></i> Add Theory Question
                        </a>
                    </div>
                <?php else: ?>
                    <div class="questions-grid">
                        <?php foreach ($theory_questions as $q): ?>
                            <div class="question-card" data-question-id="<?php echo $q['id']; ?>" data-question-type="theory">
                                <div class="question-card-header">
                                    <span class="question-id">ID: <?php echo $q['id']; ?></span>
                                    <span class="marks-badge"><i class="fas fa-star"></i> <?php echo $q['marks']; ?> marks</span>
                                    <?php if ($q['question_file']): ?>
                                        <span class="file-badge"><i class="fas fa-paperclip"></i> Has Attachment</span>
                                    <?php endif; ?>
                                </div>
                                <div class="question-text-preview">
                                    <?php if ($q['question_text']): ?>
                                        <?php echo htmlspecialchars(substr($q['question_text'], 0, 150)) . (strlen($q['question_text']) > 150 ? '...' : ''); ?>
                                    <?php else: ?>
                                        <em>Question content in attached file</em>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($selected_subject_id && empty($topics)): ?>
            <div class="empty-state">
                <i class="fas fa-list"></i>
                <h3>No Topics Found</h3>
                <p>This subject doesn't have any topics yet.</p>
                <a href="manage-topics.php?subject_id=<?php echo $selected_subject_id; ?>" class="btn btn-primary" style="margin-top: 15px;">
                    <i class="fas fa-plus"></i> Add Topics to <?php echo htmlspecialchars($selected_subject['subject_name']); ?>
                </a>
            </div>
        <?php elseif (!$selected_subject_id && !$has_assigned_subjects): ?>
            <div class="empty-state">
                <i class="fas fa-book-open"></i>
                <h3>No Subjects Assigned</h3>
                <p>You haven't been assigned any subjects yet. Please contact the school administrator.</p>
            </div>
        <?php elseif (!$selected_subject_id): ?>
            <div class="empty-state">
                <i class="fas fa-question-circle"></i>
                <h3>Select a Subject & Topic</h3>
                <p>Use the dropdown above to select a subject, then choose a topic to manage its questions.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Question Detail Modal (View) -->
    <div class="modal" id="questionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Question Details</h3>
                <button class="close-modal" onclick="closeModal('questionModal')">&times;</button>
            </div>
            <div class="modal-body" id="questionModalBody">
                <div style="text-align: center; padding: 40px;">
                    <div class="loading"></div>
                    <p>Loading...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Question Modal -->
    <div class="modal" id="editQuestionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="editModalTitle">Edit Question</h3>
                <button class="close-modal" onclick="closeModal('editQuestionModal')">&times;</button>
            </div>
            <form method="POST" id="editQuestionForm" enctype="multipart/form-data">
                <div class="modal-body" id="editModalBody">
                    <div style="text-align: center; padding: 40px;">
                        <div class="loading"></div>
                        <p>Loading...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('editQuestionModal')">Cancel</button>
                    <button type="submit" name="update_question" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Tab switching
        function switchTab(tabName) {
            const url = new URL(window.location);
            url.searchParams.set('type', tabName);
            window.history.pushState({}, '', url);

            document.querySelectorAll('.stat-card').forEach(card => card.classList.remove('active'));
            
            const statCards = document.querySelectorAll('.stat-card');
            if (tabName === 'objective') statCards[0].classList.add('active');
            else if (tabName === 'subjective') statCards[1].classList.add('active');
            else if (tabName === 'theory') statCards[2].classList.add('active');

            document.getElementById('objective-section').style.display = tabName === 'objective' ? 'block' : 'none';
            document.getElementById('subjective-section').style.display = tabName === 'subjective' ? 'block' : 'none';
            document.getElementById('theory-section').style.display = tabName === 'theory' ? 'block' : 'none';
        }

        // Make stat cards clickable
        document.querySelectorAll('.stat-card').forEach((card, index) => {
            card.addEventListener('click', function() {
                if (index === 0) switchTab('objective');
                else if (index === 1) switchTab('subjective');
                else if (index === 2) switchTab('theory');
            });
        });

        // Question card click handler - opens view modal
        document.querySelectorAll('.question-card').forEach(card => {
            card.addEventListener('click', function(e) {
                if (e.target.closest('.btn') || e.target.closest('a') || e.target.closest('form')) return;
                const questionId = this.getAttribute('data-question-id');
                const questionType = this.getAttribute('data-question-type');
                viewQuestion(questionId, questionType);
            });
        });

        function viewQuestion(id, type) {
            const modal = document.getElementById('questionModal');
            const modalBody = document.getElementById('questionModalBody');
            const modalTitle = document.getElementById('modalTitle');

            modalTitle.innerHTML = `<i class="fas fa-eye"></i> ${type.charAt(0).toUpperCase() + type.slice(1)} Question Details`;
            modalBody.innerHTML = '<div style="text-align: center; padding: 40px;"><div class="loading"></div><p>Loading...</p></div>';
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';

            fetch(`../admin/ajax/get_question.php?id=${id}&type=${type}&school_id=<?php echo $school_id; ?>`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let html = '';
                        if (type === 'objective') {
                            const q = data.question;
                            let imageHtml = '';
                            if (q.question_image && q.question_image.trim() !== '') {
                                let filename = q.question_image;
                                if (filename.includes('/')) {
                                    filename = filename.split('/').pop();
                                }
                                let imgSrc = '../../uploads/central_questions/' + filename;
                                imageHtml = `
                                    <div class="info-row">
                                        <div class="info-label">Image:</div>
                                        <div class="info-value">
                                            <img src="${escapeHtml(imgSrc)}" 
                                                 style="max-width: 100%; max-height: 300px; border-radius: 8px; cursor: pointer;" 
                                                 onclick="window.open('${escapeHtml(imgSrc)}', '_blank')"
                                                 onerror="this.onerror=null; this.style.display='none'">
                                        </div>
                                    </div>`;
                            }
                            html = `
                                <div class="info-row">
                                    <div class="info-label">Question ID:</div>
                                    <div class="info-value">#${q.id}</div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Source:</div>
                                    <div class="info-value">${q.source_type ? q.source_type.toUpperCase() : 'Manual'}</div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Question:</div>
                                    <div class="info-value">${escapeHtml(q.question_text)}</div>
                                </div>
                                ${imageHtml}
                                <div class="info-row">
                                    <div class="info-label">Options:</div>
                                    <div class="info-value">
                                        <div><strong>A:</strong> ${escapeHtml(q.option_a)} ${q.correct_answer === 'A' ? '<span class="correct-option">✓ Correct</span>' : ''}</div>
                                        <div><strong>B:</strong> ${escapeHtml(q.option_b)} ${q.correct_answer === 'B' ? '<span class="correct-option">✓ Correct</span>' : ''}</div>
                                        <div><strong>C:</strong> ${escapeHtml(q.option_c)} ${q.correct_answer === 'C' ? '<span class="correct-option">✓ Correct</span>' : ''}</div>
                                        <div><strong>D:</strong> ${escapeHtml(q.option_d)} ${q.correct_answer === 'D' ? '<span class="correct-option">✓ Correct</span>' : ''}</div>
                                    </div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Marks:</div>
                                    <div class="info-value">${q.marks}</div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Difficulty:</div>
                                    <div class="info-value">${q.difficulty_level || 'Medium'}</div>
                                </div>
                            `;
                        } else if (type === 'subjective') {
                            const q = data.question;
                            html = `
                                <div class="info-row">
                                    <div class="info-label">Question ID:</div>
                                    <div class="info-value">#${q.id}</div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Question:</div>
                                    <div class="info-value">${escapeHtml(q.question_text)}</div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Answer Guide:</div>
                                    <div class="info-value" style="background: var(--success-light); padding: 10px; border-radius: 8px;">${escapeHtml(q.correct_answer || 'No answer guide provided')}</div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Marks:</div>
                                    <div class="info-value">${q.marks}</div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Difficulty:</div>
                                    <div class="info-value">${q.difficulty_level || 'Medium'}</div>
                                </div>
                            `;
                        } else if (type === 'theory') {
                            const q = data.question;
                            html = `
                                <div class="info-row">
                                    <div class="info-label">Question ID:</div>
                                    <div class="info-value">#${q.id}</div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Question:</div>
                                    <div class="info-value">${escapeHtml(q.question_text || 'Content in attached file')}</div>
                                </div>
                                ${q.question_file ? `<div class="info-row"><div class="info-label">Attachment:</div><div class="info-value"><a href="../../${q.question_file}" target="_blank" class="btn btn-primary btn-sm"><i class="fas fa-download"></i> Download File</a></div></div>` : ''}
                                <div class="info-row">
                                    <div class="info-label">Marks:</div>
                                    <div class="info-value">${q.marks}</div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Difficulty:</div>
                                    <div class="info-value">${q.difficulty_level || 'Medium'}</div>
                                </div>
                            `;
                        }

                        html += `
                            <div class="modal-action-buttons">
                                <button class="btn btn-warning modal-action-btn" onclick="closeModal('questionModal'); openEditModal(${id}, '${type}')">
                                    <i class="fas fa-edit"></i> Edit Question
                                </button>
                                <form method="POST" onsubmit="return confirm('Delete this question?')" style="width: 100%;">
                                    <input type="hidden" name="question_id" value="${id}">
                                    <input type="hidden" name="question_type" value="${type}">
                                    <button type="submit" name="delete_question" class="btn btn-danger modal-action-btn"><i class="fas fa-trash"></i> Delete Question</button>
                                </form>
                            </div>
                        `;
                        modalBody.innerHTML = html;
                    } else {
                        modalBody.innerHTML = `<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> ${escapeHtml(data.message)}</div>`;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    modalBody.innerHTML = `<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Failed to load question details.</div>`;
                });
        }

        function openEditModal(id, type) {
            const modal = document.getElementById('editQuestionModal');
            const modalBody = document.getElementById('editModalBody');
            const modalTitle = document.getElementById('editModalTitle');
            const form = document.getElementById('editQuestionForm');

            modalTitle.innerHTML = `<i class="fas fa-edit"></i> Edit ${type.charAt(0).toUpperCase() + type.slice(1)} Question`;
            modalBody.innerHTML = '<div style="text-align: center; padding: 40px;"><div class="loading"></div><p>Loading...</p></div>';
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';

            fetch(`../admin/ajax/get_question.php?id=${id}&type=${type}&school_id=<?php echo $school_id; ?>`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const q = data.question;
                        let editFormHtml = '';

                        if (type === 'objective') {
                            let imagePreviewHtml = '';
                            if (q.question_image && q.question_image.trim() !== '') {
                                let filename = q.question_image;
                                if (filename.includes('/')) {
                                    filename = filename.split('/').pop();
                                }
                                let imgSrc = '../../uploads/central_questions/' + filename;
                                imagePreviewHtml = `
                                    <div class="edit-form-group">
                                        <label>Current Image:</label>
                                        <div><img src="${escapeHtml(imgSrc)}" style="max-width: 150px; max-height: 150px; border-radius: 8px; margin-bottom: 10px;" onerror="this.style.display='none'"></div>
                                        <label>Replace Image (optional):</label>
                                        <input type="file" name="question_image" class="edit-form-control" accept="image/*">
                                    </div>`;
                            } else {
                                imagePreviewHtml = `
                                    <div class="edit-form-group">
                                        <label>Upload Image (optional):</label>
                                        <input type="file" name="question_image" class="edit-form-control" accept="image/*">
                                    </div>`;
                            }
                            
                            editFormHtml = `
                                <input type="hidden" name="question_id" value="${q.id}">
                                <input type="hidden" name="question_type" value="objective">
                                <div class="edit-form-group">
                                    <label>Question Text *</label>
                                    <textarea name="question_text" class="edit-form-control" rows="4" required>${escapeHtml(q.question_text)}</textarea>
                                </div>
                                <div class="edit-options-grid">
                                    <div class="edit-form-group">
                                        <label>Option A *</label>
                                        <input type="text" name="option_a" class="edit-form-control" value="${escapeHtml(q.option_a)}" required>
                                    </div>
                                    <div class="edit-form-group">
                                        <label>Option B *</label>
                                        <input type="text" name="option_b" class="edit-form-control" value="${escapeHtml(q.option_b)}" required>
                                    </div>
                                    <div class="edit-form-group">
                                        <label>Option C</label>
                                        <input type="text" name="option_c" class="edit-form-control" value="${escapeHtml(q.option_c)}">
                                    </div>
                                    <div class="edit-form-group">
                                        <label>Option D</label>
                                        <input type="text" name="option_d" class="edit-form-control" value="${escapeHtml(q.option_d)}">
                                    </div>
                                </div>
                                <div class="edit-form-group">
                                    <label>Correct Answer *</label>
                                    <select name="correct_answer" class="edit-form-control" required>
                                        <option value="">Select</option>
                                        <option value="A" ${q.correct_answer === 'A' ? 'selected' : ''}>A</option>
                                        <option value="B" ${q.correct_answer === 'B' ? 'selected' : ''}>B</option>
                                        <option value="C" ${q.correct_answer === 'C' ? 'selected' : ''}>C</option>
                                        <option value="D" ${q.correct_answer === 'D' ? 'selected' : ''}>D</option>
                                    </select>
                                </div>
                                ${imagePreviewHtml}
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                    <div class="edit-form-group">
                                        <label>Difficulty Level</label>
                                        <select name="difficulty_level" class="edit-form-control">
                                            <option value="easy" ${q.difficulty_level === 'easy' ? 'selected' : ''}>Easy</option>
                                            <option value="medium" ${q.difficulty_level === 'medium' ? 'selected' : ''}>Medium</option>
                                            <option value="hard" ${q.difficulty_level === 'hard' ? 'selected' : ''}>Hard</option>
                                        </select>
                                    </div>
                                    <div class="edit-form-group">
                                        <label>Marks</label>
                                        <input type="number" name="marks" class="edit-form-control" value="${q.marks}" min="1" max="10">
                                    </div>
                                </div>
                            `;
                        } else if (type === 'subjective') {
                            editFormHtml = `
                                <input type="hidden" name="question_id" value="${q.id}">
                                <input type="hidden" name="question_type" value="subjective">
                                <div class="edit-form-group">
                                    <label>Question Text *</label>
                                    <textarea name="question_text" class="edit-form-control" rows="4" required>${escapeHtml(q.question_text)}</textarea>
                                </div>
                                <div class="edit-form-group">
                                    <label>Answer Guide / Marking Scheme</label>
                                    <textarea name="correct_answer" class="edit-form-control" rows="3">${escapeHtml(q.correct_answer || '')}</textarea>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                    <div class="edit-form-group">
                                        <label>Difficulty Level</label>
                                        <select name="difficulty_level" class="edit-form-control">
                                            <option value="easy" ${q.difficulty_level === 'easy' ? 'selected' : ''}>Easy</option>
                                            <option value="medium" ${q.difficulty_level === 'medium' ? 'selected' : ''}>Medium</option>
                                            <option value="hard" ${q.difficulty_level === 'hard' ? 'selected' : ''}>Hard</option>
                                        </select>
                                    </div>
                                    <div class="edit-form-group">
                                        <label>Marks</label>
                                        <input type="number" name="marks" class="edit-form-control" value="${q.marks}" min="1" max="20">
                                    </div>
                                </div>
                            `;
                        } else if (type === 'theory') {
                            editFormHtml = `
                                <input type="hidden" name="question_id" value="${q.id}">
                                <input type="hidden" name="question_type" value="theory">
                                <div class="edit-form-group">
                                    <label>Question Text</label>
                                    <textarea name="question_text" class="edit-form-control" rows="4">${escapeHtml(q.question_text || '')}</textarea>
                                </div>
                                ${q.question_file ? `<div class="edit-form-group"><label>Current File:</label><p><a href="../../${q.question_file}" target="_blank">${escapeHtml(basename(q.question_file))}</a></p><label>Replace File (optional):</label><input type="file" name="question_file" class="edit-form-control" accept=".pdf,.doc,.docx,.jpg,.png"></div>` : '<div class="edit-form-group"><label>Upload File</label><input type="file" name="question_file" class="edit-form-control" accept=".pdf,.doc,.docx,.jpg,.png"></div>'}
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                    <div class="edit-form-group">
                                        <label>Difficulty Level</label>
                                        <select name="difficulty_level" class="edit-form-control">
                                            <option value="easy" ${q.difficulty_level === 'easy' ? 'selected' : ''}>Easy</option>
                                            <option value="medium" ${q.difficulty_level === 'medium' ? 'selected' : ''}>Medium</option>
                                            <option value="hard" ${q.difficulty_level === 'hard' ? 'selected' : ''}>Hard</option>
                                        </select>
                                    </div>
                                    <div class="edit-form-group">
                                        <label>Marks</label>
                                        <input type="number" name="marks" class="edit-form-control" value="${q.marks}" min="1" max="30">
                                    </div>
                                </div>
                            `;
                        }

                        modalBody.innerHTML = editFormHtml;
                        form.action = '';
                        form.method = 'POST';
                        form.enctype = 'multipart/form-data';
                    } else {
                        modalBody.innerHTML = `<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> ${escapeHtml(data.message)}</div>`;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    modalBody.innerHTML = `<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Failed to load question for editing.</div>`;
                });
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = '';
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function basename(path) {
            if (!path) return '';
            return path.split('/').pop();
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

        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>

</html>
<?php
// msv/student/take-exam.php - Take Exam Page
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: /msv/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;
$accent_color = SCHOOL_ACCENT;
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'] ?? 'Student';

// Get student details including class
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND school_id = ?");
$stmt->execute([$student_id, $school_id]);
$student = $stmt->fetch();
$student_class = $student['class'] ?? '';
$admission_number = $student['admission_number'] ?? '';
$profile_picture = !empty($student['profile_picture']) ? $student['profile_picture'] : '/assets/uploads/default-avatar.png';

$exam_id = $_GET['exam_id'] ?? 0;
$resume_session = $_GET['resume'] ?? 0;

$exam = null;
$session = null;
$questions = [];
$saved_answers = [];

// Resume existing session
if ($resume_session) {
    $stmt = $pdo->prepare("
        SELECT es.*, e.*, s.subject_name 
        FROM exam_sessions es
        JOIN exams e ON es.exam_id = e.id
        JOIN subjects s ON e.subject_id = s.id
        WHERE es.id = ? AND es.student_id = ? AND es.status = 'in_progress'
    ");
    $stmt->execute([$resume_session, $student_id]);
    $session = $stmt->fetch();
    if ($session) {
        $exam = $session;
        // Get questions for this session
        $stmt = $pdo->prepare("
            SELECT q.*, esq.question_id 
            FROM exam_session_questions esq
            JOIN objective_questions q ON esq.question_id = q.id
            WHERE esq.session_id = ?
        ");
        $stmt->execute([$session['id']]);
        $questions = $stmt->fetchAll();
        
        // Load saved answers
        if ($session['objective_answers']) {
            $saved_answers = json_decode($session['objective_answers'], true);
        }
    }
}
// Start new exam
elseif ($exam_id) {
    $stmt = $pdo->prepare("
        SELECT e.*, s.subject_name 
        FROM exams e
        JOIN subjects s ON e.subject_id = s.id
        WHERE e.id = ? AND e.school_id = ? AND e.class = ? AND e.is_active = 1
    ");
    $stmt->execute([$exam_id, $school_id, $student_class]);
    $exam = $stmt->fetch();

    if ($exam) {
        // Get random questions
        $stmt = $pdo->prepare("
            SELECT * FROM objective_questions 
            WHERE (school_id = ? OR school_id IS NULL) AND subject_id = ? AND (class = ? OR class IS NULL)
            ORDER BY RAND()
            LIMIT ?
        ");
        $stmt->execute([$school_id, $exam['subject_id'], $student_class, $exam['objective_count']]);
        $questions = $stmt->fetchAll();

        if (count($questions) > 0) {
            // Create exam session
            $start_time = date('Y-m-d H:i:s');
            $end_time = date('Y-m-d H:i:s', strtotime("+{$exam['duration_minutes']} minutes"));

            $stmt = $pdo->prepare("
                INSERT INTO exam_sessions (student_id, exam_id, exam_type, start_time, end_time, status, school_id)
                VALUES (?, ?, ?, ?, ?, 'in_progress', ?)
            ");
            $stmt->execute([$student_id, $exam_id, $exam['exam_type'], $start_time, $end_time, $school_id]);
            $session_id = $pdo->lastInsertId();

            // Link questions to session
            foreach ($questions as $question) {
                $stmt = $pdo->prepare("INSERT INTO exam_session_questions (session_id, question_id, school_id) VALUES (?, ?, ?)");
                $stmt->execute([$session_id, $question['id'], $school_id]);
            }

            // Get session
            $stmt = $pdo->prepare("SELECT * FROM exam_sessions WHERE id = ?");
            $stmt->execute([$session_id]);
            $session = $stmt->fetch();
            $saved_answers = [];
        }
    }
}

// Handle answer submission via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_answer'])) {
    $session_id = $_POST['session_id'];
    $question_id = $_POST['question_id'];
    $answer = $_POST['answer'];

    $answers = [];
    $stmt = $pdo->prepare("SELECT objective_answers FROM exam_sessions WHERE id = ?");
    $stmt->execute([$session_id]);
    $current = $stmt->fetch();
    if ($current && $current['objective_answers']) {
        $answers = json_decode($current['objective_answers'], true);
    }
    $answers[$question_id] = $answer;

    $stmt = $pdo->prepare("UPDATE exam_sessions SET objective_answers = ? WHERE id = ?");
    $stmt->execute([json_encode($answers), $session_id]);

    echo json_encode(['success' => true]);
    exit;
}

// Submit exam
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_exam'])) {
    $session_id = $_POST['session_id'];

    // Get session and answers
    $stmt = $pdo->prepare("SELECT * FROM exam_sessions WHERE id = ? AND student_id = ?");
    $stmt->execute([$session_id, $student_id]);
    $session = $stmt->fetch();

    if ($session) {
        $answers = json_decode($session['objective_answers'], true) ?: [];
        $correct_count = 0;

        if (!empty($answers)) {
            // Calculate score
            $placeholders = implode(',', array_fill(0, count($answers), '?'));
            $stmt = $pdo->prepare("SELECT id, correct_answer FROM objective_questions WHERE id IN ($placeholders)");
            $stmt->execute(array_keys($answers));
            $questions_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            foreach ($answers as $qid => $answer) {
                if (isset($questions_data[$qid]) && $questions_data[$qid] === $answer) {
                    $correct_count++;
                }
            }
        }

        $total_questions = count($questions) > 0 ? count($questions) : (count($answers) ?: 1);
        $score = $total_questions > 0 ? ($correct_count / $total_questions) * 100 : 0;
        $score = round($score, 2);

        // Determine grade
        if ($score >= 70) $grade = 'A';
        elseif ($score >= 60) $grade = 'B';
        elseif ($score >= 50) $grade = 'C';
        elseif ($score >= 45) $grade = 'D';
        else $grade = 'F';

        // Update session
        $stmt = $pdo->prepare("
            UPDATE exam_sessions 
            SET status = 'completed', submitted_at = NOW(), 
                score = ?, correct_answers = ?, total_questions = ?, percentage = ?, grade = ?
            WHERE id = ?
        ");
        $stmt->execute([$correct_count, $correct_count, $total_questions, $score, $grade, $session_id]);

        // Save result
        $stmt = $pdo->prepare("
            INSERT INTO results (student_id, exam_id, objective_score, total_score, percentage, grade, submitted_at, school_id)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
        $stmt->execute([$student_id, $session['exam_id'], $correct_count, $correct_count, $score, $grade, $school_id]);

        header("Location: view-results.php?exam_id=" . $session['exam_id'] . "&success=1");
        exit;
    }
}

// If no exam or session, show available exams list instead of redirecting
if (!$exam || !$session) {
    // Get available exams for this student's class
    $stmt = $pdo->prepare("
        SELECT e.*, s.subject_name 
        FROM exams e
        JOIN subjects s ON e.subject_id = s.id
        WHERE e.school_id = ? AND e.class = ? AND e.is_active = 1
        AND e.id NOT IN (
            SELECT exam_id FROM exam_sessions 
            WHERE student_id = ? AND status = 'completed'
        )
        ORDER BY e.created_at DESC
    ");
    $stmt->execute([$school_id, $student_class, $student_id]);
    $available_exams = $stmt->fetchAll();
    
    // Show available exams page
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Available Exams - <?php echo htmlspecialchars($school_name); ?></title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Poppins', sans-serif; background: #f5f6fa; }
            
            :root {
                --primary-color: <?php echo $primary_color; ?>;
                --secondary-color: <?php echo $secondary_color; ?>;
                --sidebar-width: 280px;
            }
            
            .student-sidebar {
                width: var(--sidebar-width);
                height: 100vh;
                background: <?php echo $primary_color; ?>;
                position: fixed;
                top: 0;
                left: 0;
                overflow-y: auto;
                z-index: 1000;
            }
            
            .mobile-menu-btn {
                position: fixed; top: 20px; right: 20px; z-index: 1001;
                background: var(--primary-color); color: white;
                border: none; width: 45px; height: 45px;
                border-radius: 10px; font-size: 20px;
                display: none; cursor: pointer;
            }
            
            .main-content {
                margin-left: var(--sidebar-width);
                padding: 20px;
                min-height: 100vh;
            }
            
            .content-card {
                background: white;
                border-radius: 16px;
                padding: 25px;
                margin-bottom: 20px;
                box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            }
            
            .card-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                padding-bottom: 15px;
                border-bottom: 2px solid #f0f0f0;
            }
            
            .card-header h2 { color: var(--primary-color); font-size: 1.3rem; }
            
            .exam-item {
                padding: 15px;
                border-bottom: 1px solid #eee;
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 15px;
            }
            
            .exam-info h3 { font-size: 1rem; margin-bottom: 5px; }
            .exam-meta { font-size: 0.75rem; color: #888; }
            
            .btn {
                padding: 10px 20px;
                border-radius: 8px;
                border: none;
                cursor: pointer;
                text-decoration: none;
                font-weight: 500;
            }
            .btn-primary { background: var(--primary-color); color: white; }
            .btn-primary:hover { opacity: 0.9; }
            
            .no-data { text-align: center; padding: 40px; color: #999; }
            
            @media (max-width: 768px) {
                .student-sidebar { transform: translateX(-100%); transition: transform 0.3s; }
                .student-sidebar.active { transform: translateX(0); }
                .main-content { margin-left: 0; }
                .mobile-menu-btn { display: block; }
            }
        </style>
    </head>
    <body>
        <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>
        <?php require_once 'includes/student_sidebar.php'; ?>
        <div class="main-content">
            <div class="content-card">
                <div class="card-header">
                    <h2><i class="fas fa-file-alt"></i> Available Exams</h2>
                </div>
                <?php if (empty($available_exams)): ?>
                    <div class="no-data">
                        <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px; display: block; opacity: 0.5;"></i>
                        No exams available at the moment.
                        <p style="margin-top: 10px;"><a href="index.php" class="btn btn-primary">Back to Dashboard</a></p>
                    </div>
                <?php else: ?>
                    <?php foreach ($available_exams as $exam_item): ?>
                        <div class="exam-item">
                            <div class="exam-info">
                                <h3><?php echo htmlspecialchars($exam_item['exam_name']); ?></h3>
                                <div class="exam-meta">
                                    <?php echo htmlspecialchars($exam_item['subject_name']); ?> | 
                                    Duration: <?php echo $exam_item['duration_minutes']; ?> minutes | 
                                    Questions: <?php echo $exam_item['objective_count']; ?>
                                </div>
                            </div>
                            <a href="take-exam.php?exam_id=<?php echo $exam_item['id']; ?>" class="btn btn-primary">
                                <i class="fas fa-play"></i> Start Exam
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <script>
            document.getElementById('mobileMenuBtn')?.addEventListener('click', function() {
                document.querySelector('.student-sidebar').classList.toggle('active');
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

$time_remaining = max(0, strtotime($session['end_time']) - time());
$answered_count = count($saved_answers);
$progress_percent = count($questions) > 0 ? ($answered_count / count($questions)) * 100 : 0;
$current_question_index = 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($exam['exam_name']); ?> - <?php echo htmlspecialchars($school_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: <?php echo $secondary_color; ?>;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --light: #ecf0f1;
            --dark: #2c3e50;
            --sidebar-width: 280px;
            --shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            --radius: 12px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background: #f5f6fa; overflow-x: hidden; }

        .student-sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            background: <?php echo $primary_color; ?>;
            position: fixed;
            top: 0;
            left: 0;
            overflow-y: auto;
            z-index: 1000;
            transition: transform 0.3s ease;
        }

        .mobile-menu-btn {
            position: fixed; top: 15px; left: 15px; z-index: 1001;
            width: 44px; height: 44px; background: var(--primary-color);
            color: white; border: none; border-radius: 10px;
            font-size: 20px; cursor: pointer; display: none;
            align-items: center; justify-content: center;
            box-shadow: var(--shadow);
        }

        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px;
            min-height: 100vh;
        }

        .exam-container { max-width: 900px; margin: 0 auto; }

        .back-link {
            display: inline-flex; align-items: center; gap: 8px;
            color: var(--primary-color); text-decoration: none;
            font-size: 0.85rem; margin-bottom: 15px;
        }

        .exam-header {
            background: white; border-radius: var(--radius); padding: 20px;
            margin-bottom: 20px; box-shadow: var(--shadow);
            position: sticky; top: 20px; z-index: 99;
        }

        .exam-title { font-size: 1.3rem; font-weight: 700; color: var(--primary-color); margin-bottom: 10px; }
        .exam-meta { display: flex; gap: 20px; flex-wrap: wrap; font-size: 0.85rem; color: #666; margin-bottom: 15px; }

        .timer {
            background: #f8f9fa; padding: 6px 12px; border-radius: 8px;
            font-weight: 600; font-family: monospace; font-size: 1rem;
        }
        .timer.warning { background: #fee2e2; color: var(--danger); animation: pulse 1s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.7; } 100% { opacity: 1; } }

        .progress-section { margin-top: 15px; }
        .progress-label { display: flex; justify-content: space-between; font-size: 0.75rem; color: #666; margin-bottom: 5px; }
        .progress-bar-container { background: var(--light); border-radius: 10px; height: 8px; overflow: hidden; }
        .progress-fill { background: linear-gradient(90deg, var(--primary-color), var(--secondary-color)); height: 100%; border-radius: 10px; transition: width 0.3s; }

        .question-card {
            background: white; border-radius: var(--radius); padding: 28px;
            margin-bottom: 20px; box-shadow: var(--shadow);
            display: none;
        }
        .question-card.active { display: block; animation: fadeIn 0.3s; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .question-number {
            background: var(--primary-color); color: white;
            display: inline-block; padding: 4px 12px; border-radius: 20px;
            font-size: 0.7rem; margin-bottom: 15px;
        }

        .question-text { font-weight: 500; margin-bottom: 25px; line-height: 1.6; font-size: 1rem; }

        .options { list-style: none; }
        .options li {
            padding: 14px 18px; margin-bottom: 12px;
            border: 2px solid #e0e0e0; border-radius: 12px;
            cursor: pointer; transition: all 0.2s;
            display: flex; align-items: center; gap: 14px;
        }
        .options li:hover { border-color: var(--primary-color); background: rgba(0,0,0,0.02); }
        .options li.selected { border-color: var(--primary-color); background: rgba(114, 47, 55, 0.08); }
        .options input[type="radio"] { width: 18px; height: 18px; cursor: pointer; accent-color: var(--primary-color); }
        .option-text { flex: 1; }

        .nav-buttons {
            display: flex; justify-content: space-between; gap: 15px;
            margin-top: 20px;
        }
        .nav-btn-lg {
            padding: 12px 24px; border-radius: 10px; border: none;
            font-weight: 600; cursor: pointer; transition: all 0.2s;
            background: var(--light); color: var(--dark);
        }
        .nav-btn-lg.primary { background: var(--primary-color); color: white; }
        .nav-btn-lg:hover { transform: translateY(-2px); opacity: 0.9; }

        .question-nav-section {
            background: white; border-radius: var(--radius); padding: 20px;
            margin-top: 20px; box-shadow: var(--shadow);
        }
        .question-nav-title { font-size: 0.85rem; font-weight: 600; color: var(--primary-color); margin-bottom: 12px; }
        .question-nav { display: flex; flex-wrap: wrap; gap: 8px; }
        .nav-btn {
            width: 40px; height: 40px; border-radius: 10px;
            border: 2px solid #e0e0e0; background: white;
            cursor: pointer; font-weight: 600; transition: all 0.2s;
        }
        .nav-btn:hover { border-color: var(--primary-color); }
        .nav-btn.answered { background: var(--primary-color); color: white; border-color: var(--primary-color); }
        .nav-btn.current { border-color: var(--primary-color); border-width: 3px; transform: scale(1.05); }

        .submit-btn {
            background: var(--primary-color); color: white; border: none;
            padding: 14px 28px; border-radius: 10px; font-size: 1rem;
            font-weight: 600; cursor: pointer; width: 100%;
            margin-top: 20px; transition: all 0.2s;
        }
        .submit-btn:hover { opacity: 0.9; transform: translateY(-2px); }

        .keyboard-hint {
            background: #f8f9fa; padding: 8px 15px; border-radius: 8px;
            font-size: 0.7rem; color: #666; text-align: center;
            margin-top: 15px;
        }
        .keyboard-hint kbd {
            background: #e0e0e0; padding: 2px 6px; border-radius: 4px;
            font-family: monospace; font-weight: 600;
        }

        @media (max-width: 768px) {
            .student-sidebar { transform: translateX(-100%); }
            .student-sidebar.active { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 70px 15px 20px; }
            .mobile-menu-btn { display: flex; }
            .exam-header { top: 70px; }
            .question-card { padding: 18px; }
            .options li { padding: 10px 14px; }
        }

        @media (min-width: 769px) {
            .student-sidebar { transform: translateX(0); }
        }
    </style>
</head>
<body>

<button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>
<?php require_once 'includes/student_sidebar.php'; ?>

<div class="main-content">
    <div class="exam-container">
        
        <a href="index.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>

        <div class="exam-header">
            <div class="exam-title"><?php echo htmlspecialchars($exam['exam_name']); ?></div>
            <div class="exam-meta">
                <span><i class="fas fa-book"></i> <?php echo htmlspecialchars($exam['subject_name']); ?></span>
                <span><i class="fas fa-clock"></i> Duration: <?php echo $exam['duration_minutes']; ?> minutes</span>
                <span><i class="fas fa-question-circle"></i> Questions: <?php echo count($questions); ?></span>
                <span class="timer" id="timer"><?php echo gmdate("H:i:s", $time_remaining); ?></span>
            </div>
            <div class="progress-section">
                <div class="progress-label">
                    <span>Progress</span>
                    <span id="progressText"><?php echo $answered_count; ?>/<?php echo count($questions); ?> answered</span>
                </div>
                <div class="progress-bar-container">
                    <div class="progress-fill" id="progressFill" style="width: <?php echo $progress_percent; ?>%"></div>
                </div>
            </div>
        </div>

        <form method="POST" id="examForm">
            <input type="hidden" name="session_id" value="<?php echo $session['id']; ?>">
            <input type="hidden" name="submit_exam" value="1">
            
            <div id="questions-container">
                <?php foreach ($questions as $index => $question): 
                    $saved_answer = $saved_answers[$question['id']] ?? '';
                ?>
                    <div class="question-card" data-qid="<?php echo $question['id']; ?>" data-index="<?php echo $index; ?>" id="q_<?php echo $index; ?>">
                        <div class="question-number">Question <?php echo $index + 1; ?> of <?php echo count($questions); ?></div>
                        <div class="question-text">
                            <?php echo nl2br(htmlspecialchars($question['question_text'])); ?>
                        </div>
                        <ul class="options">
                            <li data-opt="A" class="<?php echo $saved_answer === 'A' ? 'selected' : ''; ?>">
                                <input type="radio" name="q_<?php echo $question['id']; ?>" value="A" <?php echo $saved_answer === 'A' ? 'checked' : ''; ?>>
                                <span class="option-text"><strong>A.</strong> <?php echo htmlspecialchars($question['option_a']); ?></span>
                            </li>
                            <li data-opt="B" class="<?php echo $saved_answer === 'B' ? 'selected' : ''; ?>">
                                <input type="radio" name="q_<?php echo $question['id']; ?>" value="B" <?php echo $saved_answer === 'B' ? 'checked' : ''; ?>>
                                <span class="option-text"><strong>B.</strong> <?php echo htmlspecialchars($question['option_b']); ?></span>
                            </li>
                            <li data-opt="C" class="<?php echo $saved_answer === 'C' ? 'selected' : ''; ?>">
                                <input type="radio" name="q_<?php echo $question['id']; ?>" value="C" <?php echo $saved_answer === 'C' ? 'checked' : ''; ?>>
                                <span class="option-text"><strong>C.</strong> <?php echo htmlspecialchars($question['option_c']); ?></span>
                            </li>
                            <li data-opt="D" class="<?php echo $saved_answer === 'D' ? 'selected' : ''; ?>">
                                <input type="radio" name="q_<?php echo $question['id']; ?>" value="D" <?php echo $saved_answer === 'D' ? 'checked' : ''; ?>>
                                <span class="option-text"><strong>D.</strong> <?php echo htmlspecialchars($question['option_d']); ?></span>
                            </li>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="nav-buttons">
                <button type="button" class="nav-btn-lg" id="prevBtn"><i class="fas fa-arrow-left"></i> Previous</button>
                <button type="button" class="nav-btn-lg primary" id="nextBtn">Next <i class="fas fa-arrow-right"></i></button>
            </div>

            <div class="question-nav-section">
                <div class="question-nav-title"><i class="fas fa-th"></i> Question Navigator (Click to jump)</div>
                <div class="question-nav" id="questionNav">
                    <?php foreach ($questions as $index => $question): 
                        $is_answered = isset($saved_answers[$question['id']]);
                    ?>
                        <button type="button" class="nav-btn <?php echo $is_answered ? 'answered' : ''; ?>" data-index="<?php echo $index; ?>">
                            <?php echo $index + 1; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="keyboard-hint">
                <i class="fas fa-keyboard"></i> Keyboard Shortcuts: 
                <kbd>N</kbd> Next | <kbd>P</kbd> Previous | 
                <kbd>A</kbd> Option A | <kbd>B</kbd> Option B | 
                <kbd>C</kbd> Option C | <kbd>D</kbd> Option D
            </div>

            <button type="submit" class="submit-btn" onclick="return confirmSubmit();">
                <i class="fas fa-check-circle"></i> Submit Exam
            </button>
        </form>
    </div>
</div>

<script>
    let timeRemaining = <?php echo $time_remaining; ?>;
    const sessionId = <?php echo $session['id']; ?>;
    const totalQuestions = <?php echo count($questions); ?>;
    let answered = <?php echo json_encode($saved_answers ?? []); ?>;
    let currentIndex = 0;
    
    // Show first question
    function showQuestion(index) {
        document.querySelectorAll('.question-card').forEach((q, i) => {
            q.classList.toggle('active', i === index);
        });
        document.querySelectorAll('.nav-btn').forEach(btn => {
            btn.classList.remove('current');
            if (parseInt(btn.dataset.index) === index) {
                btn.classList.add('current');
            }
        });
        currentIndex = index;
    }
    
    // Update timer
    function updateTimer() {
        if (timeRemaining <= 0) {
            document.getElementById('examForm').submit();
            return;
        }
        timeRemaining--;
        const hours = Math.floor(timeRemaining / 3600);
        const minutes = Math.floor((timeRemaining % 3600) / 60);
        const seconds = timeRemaining % 60;
        const timerEl = document.getElementById('timer');
        timerEl.textContent = `${hours.toString().padStart(2,'0')}:${minutes.toString().padStart(2,'0')}:${seconds.toString().padStart(2,'0')}`;
        if (timeRemaining < 60) timerEl.classList.add('warning');
    }
    setInterval(updateTimer, 1000);

    // Save answer to server
    function saveAnswer(qid, answer) {
        answered[qid] = true;
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `save_answer=1&session_id=${sessionId}&question_id=${qid}&answer=${answer}`
        });
        updateProgress();
    }

    // Handle radio button changes
    document.querySelectorAll('input[type="radio"]').forEach(radio => {
        radio.addEventListener('change', function() {
            const qid = this.name.split('_')[1];
            const answer = this.value;
            answered[qid] = true;
            
            const questionCard = this.closest('.question-card');
            const options = questionCard.querySelectorAll('.options li');
            options.forEach(opt => opt.classList.remove('selected'));
            this.closest('li').classList.add('selected');
            
            const index = parseInt(questionCard.dataset.index);
            const navBtn = document.querySelector(`.nav-btn[data-index="${index}"]`);
            if (navBtn) navBtn.classList.add('answered');
            
            saveAnswer(qid, answer);
        });
    });

    // Click on option li to select radio
    document.querySelectorAll('.options li').forEach(li => {
        li.addEventListener('click', function(e) {
            if (e.target.type !== 'radio') {
                const radio = this.querySelector('input[type="radio"]');
                if (radio && !radio.checked) {
                    radio.checked = true;
                    radio.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        });
    });

    // Navigation buttons
    document.getElementById('prevBtn').addEventListener('click', () => {
        if (currentIndex > 0) showQuestion(currentIndex - 1);
    });
    document.getElementById('nextBtn').addEventListener('click', () => {
        if (currentIndex < totalQuestions - 1) showQuestion(currentIndex + 1);
    });

    // Question navigation clicks
    document.querySelectorAll('.nav-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const index = parseInt(this.dataset.index);
            showQuestion(index);
        });
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        const key = e.key.toLowerCase();
        
        // Next (N) or Right Arrow
        if (key === 'n' || key === 'arrowright') {
            e.preventDefault();
            if (currentIndex < totalQuestions - 1) showQuestion(currentIndex + 1);
        }
        // Previous (P) or Left Arrow
        else if (key === 'p' || key === 'arrowleft') {
            e.preventDefault();
            if (currentIndex > 0) showQuestion(currentIndex - 1);
        }
        // Option A
        else if (key === 'a') {
            e.preventDefault();
            const currentCard = document.querySelector(`.question-card[data-index="${currentIndex}"]`);
            const radio = currentCard?.querySelector('input[value="A"]');
            if (radio && !radio.checked) {
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
        // Option B
        else if (key === 'b') {
            e.preventDefault();
            const currentCard = document.querySelector(`.question-card[data-index="${currentIndex}"]`);
            const radio = currentCard?.querySelector('input[value="B"]');
            if (radio && !radio.checked) {
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
        // Option C
        else if (key === 'c') {
            e.preventDefault();
            const currentCard = document.querySelector(`.question-card[data-index="${currentIndex}"]`);
            const radio = currentCard?.querySelector('input[value="C"]');
            if (radio && !radio.checked) {
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
        // Option D
        else if (key === 'd') {
            e.preventDefault();
            const currentCard = document.querySelector(`.question-card[data-index="${currentIndex}"]`);
            const radio = currentCard?.querySelector('input[value="D"]');
            if (radio && !radio.checked) {
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    });

    function updateProgress() {
        const answeredCount = Object.keys(answered).filter(key => answered[key] === true).length;
        const percent = (answeredCount / totalQuestions) * 100;
        document.getElementById('progressFill').style.width = percent + '%';
        document.getElementById('progressText').textContent = `${answeredCount}/${totalQuestions} answered`;
    }

    function confirmSubmit() {
        const unansweredCount = totalQuestions - Object.keys(answered).filter(key => answered[key] === true).length;
        if (unansweredCount > 0) {
            return confirm(`⚠️ You have ${unansweredCount} unanswered question(s).\n\nAre you sure you want to submit?`);
        }
        return confirm('✅ You have answered all questions.\n\nAre you ready to submit your exam?');
    }

    // Warn before leaving
    let formSubmitted = false;
    document.getElementById('examForm').addEventListener('submit', () => { formSubmitted = true; });
    window.addEventListener('beforeunload', (e) => {
        if (!formSubmitted) {
            e.preventDefault();
            e.returnValue = 'You have not submitted your exam. Are you sure you want to leave?';
        }
    });

    // Initialize - show first question
    showQuestion(0);
    updateProgress();

    // Mobile sidebar toggle
    document.getElementById('mobileMenuBtn')?.addEventListener('click', function() {
        document.querySelector('.student-sidebar').classList.toggle('active');
    });
</script>

</body>
</html>
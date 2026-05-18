<?php
// gos/student/take-exam.php - Take Exam Page
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: /gos/login.php");
    exit();
}

require_once '../includes/config.php';
$student = $student ?? null;
include '../includes/student_sidebar.php';


$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'] ?? 'Student';
$student_class = $_SESSION['user_class'] ?? '';

$exam_id = $_GET['exam_id'] ?? 0;
$resume_session = $_GET['resume'] ?? 0;

$exam = null;
$session = null;
$questions = [];

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
            WHERE school_id = ? AND subject_id = ? AND (class = ? OR class IS NULL)
            ORDER BY RAND()
            LIMIT ?
        ");
        $stmt->execute([$school_id, $exam['subject_id'], $student_class, $exam['objective_count']]);
        $questions = $stmt->fetchAll();

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
        $answers = json_decode($session['objective_answers'], true);
        $correct_count = 0;

        // Calculate score
        $stmt = $pdo->prepare("SELECT id, correct_answer FROM objective_questions WHERE id IN (" . implode(',', array_keys($answers)) . ")");
        $stmt->execute();
        $questions_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($answers as $qid => $answer) {
            if (isset($questions_data[$qid]) && $questions_data[$qid] === $answer) {
                $correct_count++;
            }
        }

        $total_questions = count($answers);
        $score = ($correct_count / $total_questions) * 100;

        // Update session
        $stmt = $pdo->prepare("
            UPDATE exam_sessions 
            SET status = 'completed', submitted_at = NOW(), 
                score = ?, correct_answers = ?, total_questions = ?, percentage = ?
            WHERE id = ?
        ");
        $stmt->execute([$correct_count, $correct_count, $total_questions, $score, $session_id]);

        // Save result
        $stmt = $pdo->prepare("
            INSERT INTO results (student_id, exam_id, objective_score, total_score, percentage, grade, submitted_at, school_id)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
        $grade = $score >= 70 ? 'A' : ($score >= 60 ? 'B' : ($score >= 50 ? 'C' : ($score >= 45 ? 'D' : 'F')));
        $stmt->execute([$student_id, $session['exam_id'], $correct_count, $correct_count, $score, $grade, $school_id]);

        header("Location: view-results.php?exam_id=" . $session['exam_id']);
        exit;
    }
}

if (!$exam || !$session) {
    header("Location: index.php");
    exit;
}

$time_remaining = max(0, strtotime($session['end_time']) - time());
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($exam['exam_name']); ?> - <?php echo htmlspecialchars($school_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            padding: 20px;
        }

        .exam-container {
            max-width: 900px;
            margin: 0 auto;
        }

        .exam-header {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .exam-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        .exam-meta {
            display: flex;
            gap: 20px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .timer {
            background: #f8f9fa;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 600;
        }

        .timer.warning {
            background: #fee2e2;
            color: #e74c3c;
        }

        .question-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .question-text {
            font-weight: 500;
            margin-bottom: 15px;
            line-height: 1.5;
        }

        .options {
            list-style: none;
        }

        .options li {
            padding: 12px;
            margin-bottom: 8px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .options li:hover {
            border-color: var(--primary-color);
            background: rgba(114, 47, 55, 0.05);
        }

        .options li.selected {
            border-color: var(--primary-color);
            background: rgba(114, 47, 55, 0.1);
        }

        .options input {
            margin-right: 12px;
        }

        .submit-btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            margin-top: 20px;
        }

        .progress {
            background: #e0e0e0;
            border-radius: 10px;
            height: 8px;
            margin-bottom: 20px;
        }

        .progress-bar {
            background: var(--primary-color);
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s;
        }

        .question-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 20px;
        }

        .nav-btn {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            border: 2px solid #e0e0e0;
            background: white;
            cursor: pointer;
        }

        .nav-btn.answered {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .nav-btn.current {
            border-color: var(--primary-color);
            border-width: 3px;
        }

        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            .exam-meta {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>

<body>
    <div class="exam-container">
        <div class="exam-header">
            <div class="exam-title"><?php echo htmlspecialchars($exam['exam_name']); ?></div>
            <div class="exam-meta">
                <span><i class="fas fa-book"></i> <?php echo htmlspecialchars($exam['subject_name']); ?></span>
                <span><i class="fas fa-clock"></i> Duration: <?php echo $exam['duration_minutes']; ?> minutes</span>
                <span class="timer" id="timer"><?php echo gmdate("H:i:s", $time_remaining); ?></span>
            </div>
            <div class="progress">
                <div class="progress-bar" id="progressBar" style="width: 0%"></div>
            </div>
        </div>

        <form method="POST" id="examForm">
            <input type="hidden" name="session_id" value="<?php echo $session['id']; ?>">
            <input type="hidden" name="submit_exam" value="1">
            <div id="questions-container">
                <?php foreach ($questions as $index => $question): ?>
                    <div class="question-card" data-qid="<?php echo $question['id']; ?>" data-index="<?php echo $index; ?>">
                        <div class="question-text">
                            <strong>Q<?php echo $index + 1; ?>.</strong> <?php echo nl2br(htmlspecialchars($question['question_text'])); ?>
                        </div>
                        <ul class="options">
                            <li data-opt="A"><input type="radio" name="q_<?php echo $question['id']; ?>" value="A"> <strong>A.</strong> <?php echo htmlspecialchars($question['option_a']); ?></li>
                            <li data-opt="B"><input type="radio" name="q_<?php echo $question['id']; ?>" value="B"> <strong>B.</strong> <?php echo htmlspecialchars($question['option_b']); ?></li>
                            <li data-opt="C"><input type="radio" name="q_<?php echo $question['id']; ?>" value="C"> <strong>C.</strong> <?php echo htmlspecialchars($question['option_c']); ?></li>
                            <li data-opt="D"><input type="radio" name="q_<?php echo $question['id']; ?>" value="D"> <strong>D.</strong> <?php echo htmlspecialchars($question['option_d']); ?></li>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="submit-btn" onclick="return confirm('Are you sure you want to submit your exam?');"><i class="fas fa-check-circle"></i> Submit Exam</button>
        </form>
    </div>

    <script>
        let timeRemaining = <?php echo $time_remaining; ?>;
        const sessionId = <?php echo $session['id']; ?>;
        const totalQuestions = <?php echo count($questions); ?>;
        let answered = {};

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

        // Save answer on change
        document.querySelectorAll('input[type="radio"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const qid = this.name.split('_')[1];
                const answer = this.value;
                answered[qid] = true;
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `save_answer=1&session_id=${sessionId}&question_id=${qid}&answer=${answer}`
                });
                updateProgress();
            });
        });

        function updateProgress() {
            const answeredCount = Object.keys(answered).length;
            const percent = (answeredCount / totalQuestions) * 100;
            document.getElementById('progressBar').style.width = percent + '%';
        }

        // Warn before leaving
        window.addEventListener('beforeunload', (e) => {
            e.preventDefault();
            e.returnValue = 'You have unsaved answers. Are you sure you want to leave?';
        });
    </script>
</body>

</html>
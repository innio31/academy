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
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'] ?? 'Student';

// Get student details including class
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND school_id = ?");
$stmt->execute([$student_id, $school_id]);
$student = $stmt->fetch();
$student_class = $student['class'] ?? '';

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
        $saved_answers = [];
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

        $total_questions = count($answers) ?: count($questions);
        $score = $total_questions > 0 ? ($correct_count / $total_questions) * 100 : 0;

        // Update session
        $stmt = $pdo->prepare("
            UPDATE exam_sessions 
            SET status = 'completed', submitted_at = NOW(), 
                score = ?, correct_answers = ?, total_questions = ?, percentage = ?
            WHERE id = ?
        ");
        $stmt->execute([$correct_count, $correct_count, $total_questions, $score, $session_id]);

        // Determine grade
        if ($score >= 70) $grade = 'A';
        elseif ($score >= 60) $grade = 'B';
        elseif ($score >= 50) $grade = 'C';
        elseif ($score >= 45) $grade = 'D';
        else $grade = 'F';

        // Save result
        $stmt = $pdo->prepare("
            INSERT INTO results (student_id, exam_id, objective_score, total_score, percentage, grade, submitted_at, school_id)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
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
$answered_count = isset($saved_answers) ? count($saved_answers) : 0;
$progress_percent = count($questions) > 0 ? ($answered_count / count($questions)) * 100 : 0;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($exam['exam_name']); ?> - <?php echo htmlspecialchars($school_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            --shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            --radius: 12px;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Mobile Menu Button */
        .mobile-menu-btn {
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            width: 44px;
            height: 44px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 20px;
            cursor: pointer;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Main Content */
        .main-content {
            min-height: 100vh;
            padding: 20px;
            transition: var(--transition);
        }

        /* Exam Container */
        .exam-container {
            max-width: 900px;
            margin: 0 auto;
        }

        .exam-header {
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            position: sticky;
            top: 20px;
            z-index: 99;
        }

        .exam-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 10px;
        }

        .exam-meta {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            font-size: 0.85rem;
            color: #666;
        }

        .timer {
            background: #f8f9fa;
            padding: 6px 12px;
            border-radius: 8px;
            font-weight: 600;
            font-family: monospace;
            font-size: 1rem;
        }

        .timer.warning {
            background: #fee2e2;
            color: var(--danger);
            animation: pulse 1s infinite;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }

        .progress-section {
            margin-top: 15px;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: #666;
            margin-bottom: 5px;
        }

        .progress-bar-container {
            background: var(--light);
            border-radius: 10px;
            height: 8px;
            overflow: hidden;
        }

        .progress-fill {
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s ease;
        }

        /* Question Card */
        .question-card {
            background: white;
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            transition: var(--transition);
        }

        .question-text {
            font-weight: 500;
            margin-bottom: 20px;
            line-height: 1.6;
            font-size: 1rem;
        }

        .options {
            list-style: none;
        }

        .options li {
            padding: 12px 16px;
            margin-bottom: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .options li:hover {
            border-color: var(--primary-color);
            background: rgba(0, 0, 0, 0.02);
        }

        .options li.selected {
            border-color: var(--primary-color);
            background: rgba(114, 47, 55, 0.08);
        }

        .options input[type="radio"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary-color);
        }

        .option-text {
            flex: 1;
        }

        /* Submit Button */
        .submit-btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            margin-top: 20px;
            transition: var(--transition);
        }

        .submit-btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }

        /* Question Navigation */
        .question-nav-section {
            background: white;
            border-radius: var(--radius);
            padding: 15px;
            margin-top: 20px;
            box-shadow: var(--shadow);
        }

        .question-nav-title {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 12px;
        }

        .question-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .nav-btn {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            border: 2px solid #e0e0e0;
            background: white;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .nav-btn:hover {
            border-color: var(--primary-color);
        }

        .nav-btn.answered {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .nav-btn.current {
            border-color: var(--primary-color);
            border-width: 3px;
            transform: scale(1.05);
        }

        /* Back to Dashboard Link */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.85rem;
            margin-bottom: 15px;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        /* Desktop */
        @media (min-width: 769px) {
            .mobile-menu-btn {
                display: none;
            }

            .main-content {
                margin-left: var(--sidebar-width);
            }
        }

        /* Mobile */
        @media (max-width: 768px) {
            .main-content {
                padding: 70px 15px 20px;
            }

            .exam-header {
                top: 70px;
            }

            .question-card {
                padding: 16px;
            }

            .options li {
                padding: 10px 12px;
            }
        }
    </style>
</head>

<body>

    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>

    <!-- Include Student Sidebar -->
    <?php require_once 'includes/student_sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="exam-container">
            
            <a href="index.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>

            <div class="exam-header">
                <div class="exam-title"><?php echo htmlspecialchars($exam['exam_name']); ?></div>
                <div class="exam-meta">
                    <span><i class="fas fa-book"></i> <?php echo htmlspecialchars($exam['subject_name']); ?></span>
                    <span><i class="fas fa-clock"></i> Duration: <?php echo $exam['duration_minutes']; ?> minutes</span>
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
                        <div class="question-card" data-qid="<?php echo $question['id']; ?>" data-index="<?php echo $index; ?>">
                            <div class="question-text">
                                <strong>Question <?php echo $index + 1; ?> of <?php echo count($questions); ?>:</strong><br>
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

                <div class="question-nav-section">
                    <div class="question-nav-title"><i class="fas fa-th"></i> Question Navigator</div>
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

        // Save answer on change
        document.querySelectorAll('input[type="radio"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const qid = this.name.split('_')[1];
                const answer = this.value;
                answered[qid] = true;
                
                // Update UI
                const questionCard = this.closest('.question-card');
                const options = questionCard.querySelectorAll('.options li');
                options.forEach(opt => opt.classList.remove('selected'));
                this.closest('li').classList.add('selected');
                
                // Update nav button
                const index = questionCard.dataset.index;
                const navBtn = document.querySelector(`.nav-btn[data-index="${index}"]`);
                if (navBtn) navBtn.classList.add('answered');
                
                // Save to server
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

        // Click on option li to select radio
        document.querySelectorAll('.options li').forEach(li => {
            li.addEventListener('click', function(e) {
                if (e.target.type !== 'radio') {
                    const radio = this.querySelector('input[type="radio"]');
                    if (radio) radio.checked = true;
                    // Trigger change event
                    const event = new Event('change', { bubbles: true });
                    radio.dispatchEvent(event);
                }
            });
        });

        // Question navigation
        document.querySelectorAll('.nav-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const index = parseInt(this.dataset.index);
                const questionCard = document.querySelector(`.question-card[data-index="${index}"]`);
                if (questionCard) {
                    questionCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    
                    // Highlight current nav button
                    document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('current'));
                    this.classList.add('current');
                    
                    // Add temporary highlight to question card
                    questionCard.style.transform = 'scale(1.01)';
                    setTimeout(() => {
                        questionCard.style.transform = '';
                    }, 300);
                }
            });
        });

        function updateProgress() {
            const answeredCount = Object.keys(answered).length;
            const percent = (answeredCount / totalQuestions) * 100;
            document.getElementById('progressFill').style.width = percent + '%';
            document.getElementById('progressText').textContent = `${answeredCount}/${totalQuestions} answered`;
        }

        function confirmSubmit() {
            const unanswered = totalQuestions - Object.keys(answered).length;
            if (unanswered > 0) {
                return confirm(`You have ${unanswered} unanswered question(s). Are you sure you want to submit?`);
            }
            return confirm('Are you sure you want to submit your exam?');
        }

        // Warn before leaving
        let formSubmitted = false;
        document.getElementById('examForm').addEventListener('submit', function() {
            formSubmitted = true;
        });
        
        window.addEventListener('beforeunload', (e) => {
            if (!formSubmitted) {
                e.preventDefault();
                e.returnValue = 'You have not submitted your exam. Are you sure you want to leave?';
            }
        });
        
        // Update progress on load
        updateProgress();
    </script>

</body>

</html>
<?php
// msv/student/waec-practice-take.php
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
  header("Location: /msv/login.php");
  exit();
}

global $pdo;

$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
$student_id = $_SESSION['user_id'];

if (!$session_id) {
  header("Location: waec-practices.php");
  exit();
}

try {
  // Get session details
  $session_query = "SELECT ws.*, wsub.subject_name 
                      FROM waec_practice_sessions ws
                      JOIN waec_subjects wsub ON ws.waec_subject_id = wsub.id
                      WHERE ws.id = ? AND ws.student_id = ? AND ws.status = 'in_progress'";
  $stmt = $pdo->prepare($session_query);
  $stmt->execute([$session_id, $student_id]);
  $session = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$session) {
    header("Location: waec-practices.php?error=session_not_found");
    exit();
  }

  // Get questions for this session with answers
  $questions_query = "SELECT waec_questions.*, waec_practice_answers.id as answer_id, 
                               waec_practice_answers.student_answer, waec_practice_answers.is_flagged,
                               waec_practice_answers.question_order
                        FROM waec_practice_answers
                        JOIN waec_questions ON waec_practice_answers.waec_question_id = waec_questions.id
                        WHERE waec_practice_answers.session_id = ?
                        ORDER BY waec_practice_answers.question_order";
  $stmt = $pdo->prepare($questions_query);
  $stmt->execute([$session_id]);
  $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  error_log("WAEC Take Test Error: " . $e->getMessage());
  header("Location: waec-practices.php?error=system_error");
  exit();
}

$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;
$total_questions = $session['total_questions'];
$duration_seconds = $session['duration_minutes'] * 60;

ob_start();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>WAEC Practice - <?= htmlspecialchars($session['subject_name']) ?> | <?= htmlspecialchars($school_name) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <style>
    :root {
      --primary: <?= htmlspecialchars($primary_color ?? '#1a6b3c') ?>;
      --bg: #0a0f0d;
      --surface: #111710;
      --card: #172118;
      --border: rgba(255, 255, 255, .07);
      --text: #e8f0ea;
      --muted: #7a9982;
      --accent: #4ade80;
      --warning: #fbbf24;
      --radius: 16px;
      --mono: 'Space Mono', monospace;
      --sans: 'Sora', sans-serif;
    }

    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: var(--sans);
      background: var(--bg);
      color: var(--text);
      min-height: 100vh;
      overflow: hidden;
    }

    .timer-bar {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: rgba(74, 222, 128, .2);
      z-index: 100;
    }

    .timer-progress {
      height: 100%;
      width: 100%;
      background: linear-gradient(90deg, var(--primary), var(--accent));
      transition: width 1s linear;
    }

    .exam-container {
      display: flex;
      height: 100vh;
    }

    .question-panel {
      flex: 1;
      overflow-y: auto;
      padding: 32px 48px;
    }

    .question-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 32px;
      flex-wrap: wrap;
      gap: 16px;
    }

    .question-counter {
      font-family: var(--mono);
      color: var(--accent);
      font-size: 0.875rem;
    }

    .question-actions {
      display: flex;
      gap: 12px;
    }

    .action-btn {
      background: var(--surface);
      border: 1px solid var(--border);
      padding: 8px 16px;
      border-radius: 8px;
      color: var(--text);
      cursor: pointer;
      font-family: var(--sans);
      font-size: 0.875rem;
      transition: all .2s;
    }

    .action-btn.flagged {
      background: rgba(251, 191, 36, .15);
      border-color: var(--warning);
      color: var(--warning);
    }

    .question-text {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 32px;
      margin-bottom: 32px;
    }

    .question-text h3 {
      font-size: 1.25rem;
      margin-bottom: 20px;
    }

    .question-body {
      font-size: 1rem;
      line-height: 1.6;
    }

    .options {
      display: flex;
      flex-direction: column;
      gap: 16px;
      margin-bottom: 32px;
    }

    .option {
      display: flex;
      align-items: center;
      gap: 16px;
      padding: 16px 20px;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 12px;
      cursor: pointer;
      transition: all .2s;
    }

    .option:hover {
      border-color: var(--accent);
      background: rgba(74, 222, 128, .05);
    }

    .option.selected {
      border-color: var(--accent);
      background: rgba(74, 222, 128, .1);
    }

    .option-prefix {
      font-family: var(--mono);
      font-weight: 700;
      color: var(--accent);
      width: 32px;
    }

    .option-text {
      flex: 1;
    }

    .nav-buttons {
      display: flex;
      justify-content: space-between;
      gap: 16px;
    }

    .nav-btn {
      padding: 12px 24px;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 10px;
      color: var(--text);
      cursor: pointer;
      font-weight: 600;
    }

    .nav-btn.primary {
      background: linear-gradient(135deg, var(--primary), #2d9e5f);
      border: none;
    }

    .palette-panel {
      width: 280px;
      background: var(--card);
      border-left: 1px solid var(--border);
      padding: 24px;
      display: flex;
      flex-direction: column;
      overflow-y: auto;
    }

    .palette-header {
      margin-bottom: 20px;
    }

    .palette-header h3 {
      font-size: 1rem;
      margin-bottom: 8px;
    }

    .palette-stats {
      display: flex;
      gap: 16px;
      font-size: 0.75rem;
      color: var(--muted);
    }

    .palette-grid {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 10px;
      margin-bottom: 24px;
    }

    .palette-btn {
      aspect-ratio: 1;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 8px;
      color: var(--text);
      font-family: var(--mono);
      font-size: 0.75rem;
      cursor: pointer;
      transition: all .2s;
    }

    .palette-btn.answered {
      background: rgba(74, 222, 128, .15);
      border-color: var(--accent);
      color: var(--accent);
    }

    .palette-btn.current {
      background: linear-gradient(135deg, var(--primary), #2d9e5f);
      border: none;
      color: white;
    }

    .palette-btn.flagged {
      border-color: var(--warning);
      color: var(--warning);
    }

    .submit-btn {
      width: 100%;
      padding: 14px;
      background: var(--accent);
      border: none;
      border-radius: 12px;
      color: var(--bg);
      font-weight: 700;
      cursor: pointer;
      margin-top: auto;
    }

    @media (max-width: 768px) {
      .palette-panel {
        display: none;
      }

      .question-panel {
        padding: 20px;
      }
    }
  </style>
</head>

<body>

  <div class="timer-bar">
    <div class="timer-progress" id="timerProgress" style="width: 100%"></div>
  </div>

  <div class="exam-container">
    <div class="question-panel">
      <div class="question-header">
        <div class="question-counter">
          Question <span id="currentQNum">1</span> of <?= $total_questions ?>
        </div>
        <div class="question-actions">
          <button class="action-btn" id="flagBtn" onclick="toggleFlag()">
            <i class="fa-regular fa-flag"></i> Flag for review
          </button>
          <div class="timer-display" style="font-family: var(--mono);">
            <i class="fa-regular fa-clock"></i> <span id="timer"><?= gmdate("i:s", $duration_seconds) ?></span>
          </div>
        </div>
      </div>

      <div id="questionContainer"></div>

      <div class="nav-buttons">
        <button class="nav-btn" id="prevBtn" onclick="prevQuestion()">
          <i class="fa-solid fa-chevron-left"></i> Previous
        </button>
        <button class="nav-btn primary" id="nextBtn" onclick="nextQuestion()">
          Next <i class="fa-solid fa-chevron-right"></i>
        </button>
      </div>
    </div>

    <div class="palette-panel">
      <div class="palette-header">
        <h3>Question Palette</h3>
        <div class="palette-stats">
          <span><i class="fa-solid fa-check-circle" style="color: var(--accent);"></i> Answered: <span id="answeredCount">0</span></span>
          <span><i class="fa-regular fa-flag" style="color: var(--warning);"></i> Flagged: <span id="flaggedCount">0</span></span>
        </div>
      </div>
      <div class="palette-grid" id="paletteGrid"></div>
      <button class="submit-btn" onclick="submitExam()">
        <i class="fa-solid fa-check-circle"></i> Submit Practice
      </button>
    </div>
  </div>

  <script>
    const totalQuestions = <?= $total_questions ?>;
    const sessionId = <?= $session_id ?>;
    let currentQuestion = 0;
    let questionsData = [];
    let answers = {};
    let flagged = {};
    let timerSeconds = <?= $duration_seconds ?>;
    let timerInterval;

    // Load questions data
    <?php
    $questions_json = [];
    foreach ($questions as $q) {
      $questions_json[] = [
        'id' => $q['id'],
        'answer_id' => $q['answer_id'],
        'question_text' => $q['question_text'],
        'option_a' => $q['option_a'],
        'option_b' => $q['option_b'],
        'option_c' => $q['option_c'],
        'option_d' => $q['option_d'],
        'option_e' => $q['option_e'] ?? null,
        'student_answer' => $q['student_answer'],
        'is_flagged' => $q['is_flagged'] ? true : false
      ];
    }
    echo "questionsData = " . json_encode($questions_json) . ";\n";
    ?>

    for (let i = 0; i < questionsData.length; i++) {
      if (questionsData[i].student_answer) {
        answers[i] = questionsData[i].student_answer;
      }
      if (questionsData[i].is_flagged) {
        flagged[i] = true;
      }
    }

    function renderQuestion() {
      const q = questionsData[currentQuestion];
      const container = document.getElementById('questionContainer');

      let optionsHtml = '';
      const options = ['A', 'B', 'C', 'D'];
      if (q.option_e) options.push('E');

      for (let opt of options) {
        const optKey = `option_${opt.toLowerCase()}`;
        const optText = q[optKey];
        if (optText) {
          const isSelected = answers[currentQuestion] === opt;
          optionsHtml += `
                <div class="option ${isSelected ? 'selected' : ''}" onclick="selectAnswer('${opt}')">
                    <div class="option-prefix">${opt}.</div>
                    <div class="option-text">${escapeHtml(optText)}</div>
                </div>
            `;
        }
      }

      container.innerHTML = `
        <div class="question-text">
            <h3>Question ${currentQuestion + 1}</h3>
            <div class="question-body">${escapeHtml(q.question_text)}</div>
        </div>
        <div class="options">
            ${optionsHtml}
        </div>
    `;

      const flagBtn = document.getElementById('flagBtn');
      if (flagged[currentQuestion]) {
        flagBtn.classList.add('flagged');
        flagBtn.innerHTML = '<i class="fa-solid fa-flag"></i> Flagged for review';
      } else {
        flagBtn.classList.remove('flagged');
        flagBtn.innerHTML = '<i class="fa-regular fa-flag"></i> Flag for review';
      }

      updatePalette();
      updateStats();
    }

    function selectAnswer(option) {
      answers[currentQuestion] = option;
      renderQuestion();
      saveAnswer();
    }

    function toggleFlag() {
      flagged[currentQuestion] = !flagged[currentQuestion];
      renderQuestion();
      saveFlag();
    }

    function saveAnswer() {
      const answer = answers[currentQuestion] || null;
      fetch('api/save_answer.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          session_id: sessionId,
          question_order: currentQuestion + 1,
          answer: answer
        })
      }).catch(err => console.error('Error saving answer:', err));
    }

    function saveFlag() {
      fetch('api/save_flag.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          session_id: sessionId,
          question_order: currentQuestion + 1,
          flagged: flagged[currentQuestion] ? 1 : 0
        })
      }).catch(err => console.error('Error saving flag:', err));
    }

    function prevQuestion() {
      if (currentQuestion > 0) {
        currentQuestion--;
        renderQuestion();
        updateCurrentQuestionDisplay();
      }
    }

    function nextQuestion() {
      if (currentQuestion < totalQuestions - 1) {
        currentQuestion++;
        renderQuestion();
        updateCurrentQuestionDisplay();
      }
    }

    function updateCurrentQuestionDisplay() {
      document.getElementById('currentQNum').innerText = currentQuestion + 1;
    }

    function updatePalette() {
      const grid = document.getElementById('paletteGrid');
      let html = '';
      for (let i = 0; i < totalQuestions; i++) {
        let classes = '';
        if (answers[i]) classes += 'answered ';
        if (flagged[i]) classes += 'flagged ';
        if (i === currentQuestion) classes += 'current';
        html += `<button class="palette-btn ${classes}" onclick="goToQuestion(${i})">${i + 1}</button>`;
      }
      grid.innerHTML = html;
    }

    function updateStats() {
      const answered = Object.keys(answers).length;
      const flaggedCount = Object.keys(flagged).filter(k => flagged[k]).length;
      document.getElementById('answeredCount').innerText = answered;
      document.getElementById('flaggedCount').innerText = flaggedCount;
    }

    function goToQuestion(index) {
      currentQuestion = index;
      renderQuestion();
      updateCurrentQuestionDisplay();
    }

    function submitExam() {
      if (confirm('Are you sure you want to submit? You cannot change your answers after submission.')) {
        fetch('api/submit_session.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({
              session_id: sessionId
            })
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              window.location.href = `waec-results.php?session_id=${sessionId}`;
            } else {
              alert('Error submitting: ' + data.error);
            }
          })
          .catch(err => {
            console.error('Error:', err);
            alert('Error submitting practice session');
          });
      }
    }

    function escapeHtml(text) {
      if (!text) return '';
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    function startTimer() {
      timerInterval = setInterval(() => {
        if (timerSeconds <= 0) {
          clearInterval(timerInterval);
          submitExam();
        } else {
          timerSeconds--;
          const minutes = Math.floor(timerSeconds / 60);
          const seconds = timerSeconds % 60;
          document.getElementById('timer').innerText = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
          const progressPercent = (timerSeconds / <?= $duration_seconds ?>) * 100;
          document.getElementById('timerProgress').style.width = `${progressPercent}%`;
        }
      }, 1000);
    }

    renderQuestion();
    updatePalette();
    startTimer();

    document.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowLeft') prevQuestion();
      if (e.key === 'ArrowRight') nextQuestion();
    });
  </script>
</body>

</html>
<?php
echo ob_get_clean();
?>
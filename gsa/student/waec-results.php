<?php
// gsa/student/waec-results.php
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
  header("Location: /gsa/login.php");
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
  // Get session results
  $session_query = "SELECT ws.*, wsub.subject_name 
                      FROM waec_practice_sessions ws
                      JOIN waec_subjects wsub ON ws.waec_subject_id = wsub.id
                      WHERE ws.id = ? AND ws.student_id = ?";
  $stmt = $pdo->prepare($session_query);
  $stmt->execute([$session_id, $student_id]);
  $session = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$session) {
    header("Location: waec-practices.php");
    exit();
  }

  // Get questions with answers
  $questions_query = "SELECT waec_questions.*, waec_practice_answers.student_answer, 
                               waec_practice_answers.is_correct, waec_practice_answers.question_order,
                               waec_topics.topic_name
                        FROM waec_practice_answers
                        JOIN waec_questions ON waec_practice_answers.waec_question_id = waec_questions.id
                        LEFT JOIN waec_topics ON waec_questions.waec_topic_id = waec_topics.id
                        WHERE waec_practice_answers.session_id = ?
                        ORDER BY waec_practice_answers.question_order";
  $stmt = $pdo->prepare($questions_query);
  $stmt->execute([$session_id]);
  $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  error_log("WAEC Results Error: " . $e->getMessage());
  header("Location: waec-practices.php?error=system_error");
  exit();
}

$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;
$percentage = round($session['percentage'] ?? 0);
$score = $session['score'] ?? 0;
$total = $session['total_attempted'] ?? 0;

// Determine grade and feedback
if ($percentage >= 80) {
  $grade = 'Excellent';
  $grade_color = '#4ade80';
  $feedback = 'Outstanding performance! You\'ve mastered this subject well.';
} elseif ($percentage >= 60) {
  $grade = 'Good';
  $grade_color = '#fbbf24';
  $feedback = 'Good job! Keep practicing to improve further.';
} elseif ($percentage >= 40) {
  $grade = 'Fair';
  $grade_color = '#fb923c';
  $feedback = 'Fair attempt. Focus on topics where you scored low.';
} else {
  $grade = 'Needs Improvement';
  $grade_color = '#fb7185';
  $feedback = 'Don\'t worry! Review the explanations and try again.';
}

ob_start();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>WAEC Results - <?= htmlspecialchars($session['subject_name']) ?> | <?= htmlspecialchars($school_name) ?></title>
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
    }

    body::before {
      content: '';
      position: fixed;
      inset: 0;
      background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");
      pointer-events: none;
      z-index: 0;
    }

    .blob {
      position: fixed;
      border-radius: 50%;
      filter: blur(120px);
      opacity: .15;
      pointer-events: none;
      z-index: 0;
    }

    .blob-1 {
      width: 600px;
      height: 600px;
      background: var(--primary);
      top: -200px;
      right: -150px;
    }

    .blob-2 {
      width: 400px;
      height: 400px;
      background: var(--accent);
      bottom: -100px;
      left: -100px;
    }

    .container {
      max-width: 1400px;
      margin: 0 auto;
      padding: 32px 40px;
      position: relative;
      z-index: 1;
    }

    .top-nav {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 32px;
      padding-bottom: 20px;
      border-bottom: 1px solid var(--border);
      flex-wrap: wrap;
      gap: 16px;
    }

    .logo-area h1 {
      font-size: 1.5rem;
      font-weight: 700;
      background: linear-gradient(135deg, #fff 30%, var(--accent));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    .logo-area p {
      color: var(--muted);
      font-size: 0.875rem;
      margin-top: 4px;
    }

    .back-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 20px;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 12px;
      color: var(--text);
      text-decoration: none;
      font-weight: 500;
      transition: all .25s;
    }

    .back-btn:hover {
      background: rgba(74, 222, 128, .1);
      border-color: var(--accent);
    }

    .score-card {
      background: linear-gradient(135deg, var(--card), var(--surface));
      border: 1px solid var(--border);
      border-radius: 24px;
      padding: 32px;
      text-align: center;
      margin-bottom: 32px;
    }

    .score-circle {
      width: 160px;
      height: 160px;
      margin: 0 auto 20px;
      position: relative;
    }

    .score-circle svg {
      transform: rotate(-90deg);
    }

    .score-text {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      text-align: center;
    }

    .score-value {
      font-size: 2.5rem;
      font-weight: 800;
      font-family: var(--mono);
      color: <?= $grade_color ?>;
    }

    .grade {
      font-size: 1.25rem;
      font-weight: 600;
      color: <?= $grade_color ?>;
      margin-top: 8px;
    }

    .feedback {
      color: var(--muted);
      margin-top: 16px;
    }

    .stats-row {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 20px;
      margin-bottom: 32px;
    }

    .stat-item {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 20px;
      text-align: center;
    }

    .stat-value {
      font-size: 1.75rem;
      font-weight: 700;
      font-family: var(--mono);
    }

    .stat-label {
      color: var(--muted);
      font-size: 0.75rem;
      margin-top: 4px;
    }

    .review-section {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 24px;
      overflow: hidden;
    }

    .review-header {
      padding: 20px 24px;
      border-bottom: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 12px;
    }

    .filter-buttons {
      display: flex;
      gap: 8px;
    }

    .filter-btn {
      padding: 6px 12px;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 8px;
      color: var(--muted);
      cursor: pointer;
      font-size: 0.75rem;
    }

    .filter-btn.active {
      background: var(--primary);
      color: white;
      border-color: var(--primary);
    }

    .question-review {
      padding: 24px;
      border-bottom: 1px solid var(--border);
    }

    .question-review.correct {
      border-left: 4px solid var(--accent);
    }

    .question-review.wrong {
      border-left: 4px solid #fb7185;
    }

    .question-header {
      display: flex;
      justify-content: space-between;
      margin-bottom: 12px;
    }

    .status-badge {
      font-size: 0.75rem;
      padding: 4px 8px;
      border-radius: 6px;
    }

    .status-correct {
      background: rgba(74, 222, 128, .15);
      color: var(--accent);
    }

    .status-wrong {
      background: rgba(251, 113, 133, .15);
      color: #fb7185;
    }

    .question-text {
      margin-bottom: 16px;
      line-height: 1.6;
    }

    .answer-details {
      background: var(--surface);
      padding: 16px;
      border-radius: 12px;
      margin-top: 12px;
    }

    .your-answer {
      color: #fbbf24;
    }

    .correct-answer {
      color: var(--accent);
    }

    .explanation {
      margin-top: 12px;
      padding-top: 12px;
      border-top: 1px solid var(--border);
      color: var(--muted);
      font-size: 0.875rem;
    }

    .action-buttons {
      display: flex;
      gap: 16px;
      margin-top: 32px;
      justify-content: center;
    }

    .btn {
      padding: 12px 24px;
      border-radius: 12px;
      text-decoration: none;
      font-weight: 600;
    }

    .btn-primary {
      background: linear-gradient(135deg, var(--primary), #2d9e5f);
      color: white;
    }

    .btn-secondary {
      background: var(--surface);
      border: 1px solid var(--border);
      color: var(--text);
    }

    @media (max-width: 768px) {
      .container {
        padding: 20px;
      }

      .stats-row {
        grid-template-columns: repeat(2, 1fr);
      }

      .top-nav {
        flex-direction: column;
        text-align: center;
      }
    }
  </style>
</head>

<body>

  <div class="blob blob-1"></div>
  <div class="blob blob-2"></div>

  <div class="container">
    <div class="top-nav">
      <div class="logo-area">
        <h1><i class="fa-solid fa-chart-line"></i> Practice Results</h1>
        <p><?= htmlspecialchars($session['subject_name']) ?> • <?= ucfirst($session['practice_mode']) ?> Mode</p>
      </div>
      <a href="waec-practices.php" class="back-btn">
        <i class="fa-solid fa-arrow-left"></i> Back to Practice
      </a>
    </div>

    <div class="score-card">
      <div class="score-circle">
        <svg width="160" height="160">
          <circle cx="80" cy="80" r="70" fill="none" stroke="rgba(255,255,255,.1)" stroke-width="12" />
          <circle cx="80" cy="80" r="70" fill="none" stroke="<?= $grade_color ?>" stroke-width="12"
            stroke-dasharray="<?= 2 * pi() * 70 ?>"
            stroke-dashoffset="<?= (2 * pi() * 70) * (1 - $percentage / 100) ?>" />
        </svg>
        <div class="score-text">
          <div class="score-value"><?= $percentage ?>%</div>
          <div class="grade"><?= $grade ?></div>
        </div>
      </div>
      <div class="feedback"><?= $feedback ?></div>
    </div>

    <div class="stats-row">
      <div class="stat-item">
        <div class="stat-value"><?= $score ?>/<?= $total ?></div>
        <div class="stat-label">Correct / Total</div>
      </div>
      <div class="stat-item">
        <div class="stat-value"><?= $session['duration_minutes'] ?> min</div>
        <div class="stat-label">Time Allowed</div>
      </div>
      <div class="stat-item">
        <div class="stat-value"><?= date('M j, Y', strtotime($session['created_at'])) ?></div>
        <div class="stat-label">Date</div>
      </div>
    </div>

    <div class="review-section">
      <div class="review-header">
        <h3><i class="fa-solid fa-list-check"></i> Question Review</h3>
        <div class="filter-buttons">
          <button class="filter-btn active" onclick="filterQuestions('all')">All</button>
          <button class="filter-btn" onclick="filterQuestions('correct')">Correct</button>
          <button class="filter-btn" onclick="filterQuestions('wrong')">Wrong</button>
        </div>
      </div>
      <div id="questionsContainer">
        <?php foreach ($questions as $idx => $q):
          $is_correct = $q['is_correct'];
        ?>
          <div class="question-review <?= $is_correct ? 'correct' : 'wrong' ?>" data-status="<?= $is_correct ? 'correct' : 'wrong' ?>">
            <div class="question-header">
              <strong>Question <?= $idx + 1 ?></strong>
              <span class="status-badge <?= $is_correct ? 'status-correct' : 'status-wrong' ?>">
                <?= $is_correct ? '<i class="fa-solid fa-check"></i> Correct' : '<i class="fa-solid fa-times"></i> Wrong' ?>
              </span>
            </div>
            <div class="question-text"><?= nl2br(htmlspecialchars($q['question_text'])) ?></div>
            <div class="answer-details">
              <div>Your answer: <span class="your-answer"><strong><?= htmlspecialchars($q['student_answer'] ?? 'Not answered') ?></strong></span></div>
              <div>Correct answer: <span class="correct-answer"><strong><?= htmlspecialchars($q['correct_answer']) ?></strong></span></div>
              <?php if ($q['explanation']): ?>
                <div class="explanation">
                  <i class="fa-regular fa-lightbulb"></i> <?= nl2br(htmlspecialchars($q['explanation'])) ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="action-buttons">
      <a href="waec-practices.php" class="btn btn-primary">
        <i class="fa-solid fa-arrow-left"></i> Back to Practice
      </a>
      <a href="waec-session.php?mode=<?= $session['practice_mode'] ?>&subject_id=<?= $session['waec_subject_id'] ?>" class="btn btn-secondary">
        <i class="fa-solid fa-rotate-right"></i> Try Again
      </a>
    </div>
  </div>

  <script>
    function filterQuestions(status) {
      const questions = document.querySelectorAll('.question-review');
      const buttons = document.querySelectorAll('.filter-btn');

      buttons.forEach(btn => btn.classList.remove('active'));
      event.target.classList.add('active');

      questions.forEach(q => {
        if (status === 'all') {
          q.style.display = 'block';
        } else {
          q.style.display = q.dataset.status === status ? 'block' : 'none';
        }
      });
    }
  </script>
</body>

</html>
<?php
echo ob_get_clean();
?>
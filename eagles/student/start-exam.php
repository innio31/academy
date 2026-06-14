<?php
// eagles/student/start-exam.php  — Full-screen locked exam interface
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: /eagles/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id    = SCHOOL_ID;
$student_id   = $_SESSION['user_id'];

// ══════════════════════════════════════════════════════════════
// ── AJAX / POST handlers MUST come first — before any session
//    creation logic — to prevent the duplicate-session bug where
//    every saveAnswer() call re-ran the entire start-exam flow.
// ══════════════════════════════════════════════════════════════

// ── AJAX: save single answer ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_answer'])) {
    $s_id = (int)$_POST['session_id'];
    $q_id = (int)$_POST['question_id'];
    $ans  = trim($_POST['answer']);

    $stmt = $pdo->prepare("
        SELECT objective_answers FROM exam_sessions
        WHERE id = ? AND student_id = ? AND status = 'in_progress'
    ");
    $stmt->execute([$s_id, $student_id]);
    $cur = $stmt->fetch();

    if ($cur) {
        $answers         = $cur['objective_answers'] ? json_decode($cur['objective_answers'], true) : [];
        $answers[$q_id]  = $ans;
        $stmt = $pdo->prepare("UPDATE exam_sessions SET objective_answers = ? WHERE id = ?");
        $stmt->execute([json_encode($answers), $s_id]);
        echo json_encode(['success' => true, 'answered' => count($answers)]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// ── POST: submit exam ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_exam'])) {
    $s_id = (int)$_POST['session_id'];

    $stmt = $pdo->prepare("
        SELECT * FROM exam_sessions
        WHERE id = ? AND student_id = ? AND status = 'in_progress'
    ");
    $stmt->execute([$s_id, $student_id]);
    $sess = $stmt->fetch();

    if ($sess) {
        $answers = json_decode($sess['objective_answers'], true) ?: [];

        // Fetch all questions for this session to get the accurate total
        $stmt = $pdo->prepare("
            SELECT q.id, q.correct_answer
            FROM exam_session_questions esq
            JOIN objective_questions q ON esq.question_id = q.id
            WHERE esq.session_id = ?
        ");
        $stmt->execute([$s_id]);
        $all_questions = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // id => correct_answer
        $total         = count($all_questions);

        $correct = 0;
        foreach ($answers as $qid => $ans) {
            if (isset($all_questions[$qid]) && strtoupper($all_questions[$qid]) === strtoupper($ans)) {
                $correct++;
            }
        }

        $score = $total > 0 ? round(($correct / $total) * 100, 2) : 0;
        $grade = match (true) {
            $score >= 70 => 'A',
            $score >= 60 => 'B',
            $score >= 50 => 'C',
            $score >= 45 => 'D',
            default      => 'F',
        };

        $stmt = $pdo->prepare("
            UPDATE exam_sessions
            SET status = 'completed', submitted_at = NOW(),
                score = ?, correct_answers = ?, total_questions = ?, percentage = ?, grade = ?
            WHERE id = ?
        ");
        $stmt->execute([$correct, $correct, $total, $score, $grade, $s_id]);

        $stmt = $pdo->prepare("
            INSERT INTO results (student_id, exam_id, objective_score, total_score, percentage, grade, submitted_at, school_id)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
        $stmt->execute([$student_id, $sess['exam_id'], $correct, $correct, $score, $grade, $school_id]);

        header("Location: view-results.php?exam_id=" . $sess['exam_id'] . "&success=1");
        exit;
    }
}

// ══════════════════════════════════════════════════════════════
// ── GET: load / start / resume session
// ══════════════════════════════════════════════════════════════

$school_name     = SCHOOL_NAME;
$primary_color   = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;
$student_name    = $_SESSION['user_name'] ?? 'Student';

// Get student with class_id
$stmt = $pdo->prepare("
    SELECT s.*, c.id as class_id, c.class_name
    FROM students s
    LEFT JOIN classes c ON c.class_name = s.class AND c.school_id = s.school_id
    WHERE s.id = ? AND s.school_id = ?
");
$stmt->execute([$student_id, $school_id]);
$student = $stmt->fetch();

$student_class    = $student['class']    ?? '';
$student_class_id = $student['class_id'] ?? 0;

$exam_id        = (int)($_GET['exam_id'] ?? 0);
$resume_session = (int)($_GET['resume']  ?? 0);

$exam = $session = null;
$questions = $saved_answers = [];

// ── Resume via ?resume=session_id ────────────────────────────
if ($resume_session) {
    $stmt = $pdo->prepare("
        SELECT es.*, e.*, s.subject_name
        FROM exam_sessions es
        JOIN exams e ON es.exam_id = e.id
        LEFT JOIN subjects s ON e.subject_id = s.id
        WHERE es.id = ? AND es.student_id = ? AND es.status = 'in_progress'
    ");
    $stmt->execute([$resume_session, $student_id]);
    $session = $stmt->fetch();

    if ($session) {
        $exam = $session;
        $stmt = $pdo->prepare("
            SELECT q.*, esq.question_id
            FROM exam_session_questions esq
            JOIN objective_questions q ON esq.question_id = q.id
            WHERE esq.session_id = ?
        ");
        $stmt->execute([$session['id']]);
        $questions = $stmt->fetchAll();
        if ($session['objective_answers']) {
            $saved_answers = json_decode($session['objective_answers'], true) ?? [];
        }
    }

    // ── Start / auto-resume via ?exam_id ─────────────────────────
} elseif ($exam_id) {
    $stmt = $pdo->prepare("
        SELECT e.*, s.subject_name
        FROM exams e
        JOIN subjects s ON e.subject_id = s.id
        WHERE e.id = ? AND e.school_id = ? AND e.is_active = 1
    ");
    $stmt->execute([$exam_id, $school_id]);
    $exam = $stmt->fetch();

    if ($exam) {
        // Already completed?
        $stmt = $pdo->prepare("
            SELECT id FROM exam_sessions
            WHERE student_id = ? AND exam_id = ? AND status = 'completed'
        ");
        $stmt->execute([$student_id, $exam_id]);
        if ($stmt->fetch()) {
            header("Location: view-results.php?exam_id=$exam_id&already=1");
            exit();
        }

        // Existing in-progress session? Auto-resume — no new session created
        $stmt = $pdo->prepare("
            SELECT * FROM exam_sessions
            WHERE student_id = ? AND exam_id = ? AND status = 'in_progress' AND end_time > NOW()
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$student_id, $exam_id]);
        $existing = $stmt->fetch();

        if ($existing) {
            $session       = $existing;
            $saved_answers = json_decode($session['objective_answers'], true) ?? [];
            $stmt = $pdo->prepare("
                SELECT q.* FROM exam_session_questions esq
                JOIN objective_questions q ON esq.question_id = q.id
                WHERE esq.session_id = ?
            ");
            $stmt->execute([$session['id']]);
            $questions = $stmt->fetchAll();
        } else {
            // Brand-new session — pick random questions
            $topic_ids = [];
            if (!empty($exam['topics'])) {
                $decoded = json_decode($exam['topics'], true);
                if (is_array($decoded)) $topic_ids = array_map('intval', $decoded);
            }

            if (!empty($topic_ids)) {
                $ph   = implode(',', array_fill(0, count($topic_ids), '?'));
                $stmt = $pdo->prepare("
                    SELECT * FROM objective_questions
                    WHERE subject_id = ? AND topic_id IN ($ph)
                    ORDER BY RAND() LIMIT ?
                ");
                $stmt->execute([$exam['subject_id'], ...$topic_ids, (int)$exam['objective_count']]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT * FROM objective_questions
                    WHERE subject_id = ?
                    ORDER BY RAND() LIMIT ?
                ");
                $stmt->execute([$exam['subject_id'], (int)$exam['objective_count']]);
            }
            $questions = $stmt->fetchAll();

            if (count($questions) > 0) {
                $start_time = date('Y-m-d H:i:s');
                $end_time   = date('Y-m-d H:i:s', strtotime("+{$exam['duration_minutes']} minutes"));

                $stmt = $pdo->prepare("
                    INSERT INTO exam_sessions (student_id, exam_id, exam_type, start_time, end_time, status, school_id)
                    VALUES (?, ?, ?, ?, ?, 'in_progress', ?)
                ");
                $stmt->execute([$student_id, $exam_id, $exam['exam_type'], $start_time, $end_time, $school_id]);
                $session_id = $pdo->lastInsertId();

                // Bulk-insert session questions
                $ins = $pdo->prepare("
                    INSERT INTO exam_session_questions (session_id, question_id, school_id)
                    VALUES (?, ?, ?)
                ");
                foreach ($questions as $q) {
                    $ins->execute([$session_id, $q['id'], $school_id]);
                }

                $stmt = $pdo->prepare("SELECT * FROM exam_sessions WHERE id = ?");
                $stmt->execute([$session_id]);
                $session       = $stmt->fetch();
                $saved_answers = [];
            } else {
                header("Location: exams.php?error=no_questions");
                exit();
            }
        }
    }
}

// ── Guard: bounce if nothing valid ───────────────────────────
if (!$exam || !$session) {
    header("Location: exams.php?error=invalid");
    exit();
}

$time_remaining   = max(0, strtotime($session['end_time']) - time());
$answered_count   = count($saved_answers);
$total_questions  = count($questions);
$progress_percent = $total_questions > 0 ? round(($answered_count / $total_questions) * 100) : 0;

$has_option_e = false;
foreach ($questions as $q) {
    if (!empty($q['option_e'])) {
        $has_option_e = true;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($exam['exam_name']); ?> — <?php echo htmlspecialchars($school_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ── Reset ─────────────────────────────────────────── */
        *,
        *::before,
        *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: <?php echo $primary_color; ?>;
            --secondary: <?php echo $secondary_color; ?>;
            --bg: #0f172a;
            --surface: #1e293b;
            --surface2: #273348;
            --border: rgba(255, 255, 255, .08);
            --text: #e2e8f0;
            --muted: #94a3b8;
            --success: #22c55e;
            --warning: #f59e0b;
            --danger: #ef4444;
            --radius: 14px;
            --radius-sm: 8px;
        }

        /* ── Full-screen lock ──────────────────────────────── */
        html,
        body {
            width: 100%;
            height: 100%;
            overflow: hidden;
            font-family: 'Poppins', sans-serif;
            background: var(--bg);
            color: var(--text);
            user-select: none;
        }

        body {
            -webkit-touch-callout: none;
        }

        /* ── Layout ────────────────────────────────────────── */
        .exam-layout {
            display: grid;
            grid-template-rows: auto 1fr;
            height: 100vh;
        }

        /* ── Top bar ───────────────────────────────────────── */
        .topbar {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 0 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            min-height: 64px;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .school-badge {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .school-badge-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--secondary), var(--primary));
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .school-badge-icon i {
            color: #fff;
            font-size: .9rem;
        }

        .exam-title-bar {
            font-size: .9rem;
            font-weight: 700;
            color: #fff;
            line-height: 1.2;
        }

        .exam-subject-bar {
            font-size: .7rem;
            color: var(--muted);
        }

        .topbar-center {
            display: flex;
            align-items: center;
            gap: 24px;
        }

        /* Timer */
        .timer-block {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--surface2);
            border-radius: 10px;
            padding: 8px 16px;
        }

        .timer-icon {
            color: var(--secondary);
            font-size: 1rem;
        }

        .timer-display {
            font-family: 'Courier New', monospace;
            font-size: 1.3rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: .05em;
        }

        .timer-display.warning {
            color: var(--warning);
            animation: blink .8s ease infinite;
        }

        .timer-display.danger {
            color: var(--danger);
            animation: blink .4s ease infinite;
        }

        @keyframes blink {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: .4;
            }
        }

        /* Progress chip */
        .progress-chip {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--surface2);
            border-radius: 10px;
            padding: 8px 16px;
        }

        .progress-text {
            font-size: .75rem;
            color: var(--muted);
        }

        .progress-ring-wrap {
            position: relative;
            width: 34px;
            height: 34px;
        }

        .progress-ring {
            transform: rotate(-90deg);
        }

        .progress-ring-bg {
            stroke: #334155;
            fill: none;
        }

        .progress-ring-fill {
            fill: none;
            stroke: var(--success);
            stroke-linecap: round;
            transition: stroke-dashoffset .4s ease;
        }

        .progress-ring-number {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .55rem;
            font-weight: 700;
            color: #fff;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .student-chip {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--surface2);
            border-radius: 10px;
            padding: 6px 12px;
        }

        .student-chip i {
            color: var(--secondary);
        }

        .student-chip span {
            font-size: .75rem;
            color: var(--muted);
        }

        /* Calculator toggle button in topbar */
        .calc-toggle-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 10px;
            border: 1.5px solid var(--border);
            background: var(--surface2);
            color: var(--muted);
            font-size: .78rem;
            font-weight: 600;
            cursor: pointer;
            transition: all .2s;
            font-family: 'Poppins', sans-serif;
        }

        .calc-toggle-btn:hover,
        .calc-toggle-btn.active {
            border-color: var(--secondary);
            color: var(--secondary);
            background: rgba(255, 255, 255, .04);
        }

        .calc-toggle-btn i {
            font-size: .85rem;
        }

        /* ── Body ─────────────────────────────────────────── */
        .exam-body {
            display: grid;
            grid-template-columns: 1fr 260px;
            overflow: hidden;
            position: relative;
        }

        /* ── Question pane ───────────────────────────────── */
        .question-pane {
            overflow-y: auto;
            padding: 28px 32px;
            scroll-behavior: smooth;
        }

        .question-pane::-webkit-scrollbar {
            width: 4px;
        }

        .question-pane::-webkit-scrollbar-track {
            background: transparent;
        }

        .question-pane::-webkit-scrollbar-thumb {
            background: var(--surface2);
            border-radius: 4px;
        }

        .question-slide {
            display: none;
            max-width: 760px;
            margin: 0 auto;
            animation: slideIn .25s ease;
        }

        .question-slide.active {
            display: block;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .question-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .question-counter {
            font-size: .72rem;
            font-weight: 700;
            color: var(--secondary);
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .question-flag-btn {
            background: none;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            padding: 5px 12px;
            cursor: pointer;
            color: var(--muted);
            font-size: .72rem;
            transition: all .2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .question-flag-btn.flagged {
            border-color: var(--warning);
            color: var(--warning);
            background: rgba(245, 158, 11, .08);
        }

        .question-flag-btn:hover {
            border-color: var(--warning);
            color: var(--warning);
        }

        .question-text-box {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px 28px;
            margin-bottom: 20px;
        }

        .question-number-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--secondary), var(--primary));
            font-weight: 800;
            font-size: .85rem;
            color: #fff;
            margin-bottom: 14px;
        }

        .question-text {
            font-size: 1.02rem;
            line-height: 1.7;
            color: var(--text);
            font-weight: 500;
        }

        /* Options */
        .options-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .option-item {
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 14px;
            transition: all .18s;
            background: var(--surface);
            position: relative;
            overflow: hidden;
        }

        .option-item::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, var(--primary), transparent);
            opacity: 0;
            transition: opacity .2s;
        }

        .option-item:hover {
            border-color: rgba(255, 255, 255, .2);
            transform: translateX(3px);
        }

        .option-item:hover::before {
            opacity: .04;
        }

        .option-item.selected {
            border-color: var(--secondary);
            background: rgba(var(--secondary-rgb, 60, 100, 200), .12);
        }

        .option-key {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: var(--surface2);
            border: 1.5px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: .78rem;
            color: var(--muted);
            flex-shrink: 0;
            transition: all .18s;
        }

        .option-item.selected .option-key {
            background: var(--secondary);
            border-color: var(--secondary);
            color: #fff;
        }

        .option-item input[type="radio"] {
            display: none;
        }

        .option-text {
            flex: 1;
            font-size: .9rem;
            line-height: 1.5;
            position: relative;
            z-index: 1;
        }

        /* Navigation buttons */
        .nav-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 28px;
            gap: 12px;
        }

        .nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 22px;
            border-radius: 10px;
            border: none;
            font-size: .82rem;
            font-weight: 600;
            cursor: pointer;
            transition: all .2s;
        }

        .nav-btn-prev {
            background: var(--surface);
            color: var(--muted);
            border: 1.5px solid var(--border);
        }

        .nav-btn-prev:hover {
            border-color: rgba(255, 255, 255, .2);
            color: #fff;
        }

        .nav-btn-next {
            background: var(--primary);
            color: #fff;
        }

        .nav-btn-next:hover {
            opacity: .88;
            transform: translateY(-1px);
        }

        .nav-btn:disabled {
            opacity: .35;
            cursor: not-allowed;
            transform: none !important;
        }

        .keyboard-tips {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
        }

        .kbt {
            font-size: .65rem;
            color: var(--muted);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .kbt kbd {
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 2px 6px;
            font-family: monospace;
            font-size: .65rem;
            color: var(--text);
        }

        /* ── Right sidebar ───────────────────────────────── */
        .exam-sidebar {
            background: var(--surface);
            border-left: 1px solid var(--border);
            padding: 20px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .exam-sidebar::-webkit-scrollbar {
            width: 3px;
        }

        .exam-sidebar::-webkit-scrollbar-thumb {
            background: var(--surface2);
        }

        .sidebar-section-title {
            font-size: .7rem;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .08em;
            margin-bottom: 10px;
        }

        /* Question grid */
        .q-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 6px;
        }

        .q-cell {
            aspect-ratio: 1;
            border-radius: 6px;
            border: 1.5px solid var(--border);
            background: var(--surface2);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .7rem;
            font-weight: 700;
            color: var(--muted);
            transition: all .18s;
        }

        .q-cell:hover {
            border-color: var(--secondary);
            color: #fff;
        }

        .q-cell.answered {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }

        .q-cell.current {
            border: 2px solid var(--secondary);
            color: #fff;
            transform: scale(1.08);
        }

        .q-cell.flagged {
            background: rgba(245, 158, 11, .15);
            border-color: var(--warning);
            color: var(--warning);
        }

        .q-cell.answered.flagged {
            background: rgba(245, 158, 11, .25);
            border-color: var(--warning);
            color: var(--warning);
        }

        /* Legend */
        .legend {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: .72rem;
            color: var(--muted);
        }

        .legend-dot {
            width: 12px;
            height: 12px;
            border-radius: 3px;
            flex-shrink: 0;
        }

        /* Submit section */
        .submit-section {
            margin-top: auto;
            padding-top: 16px;
            border-top: 1px solid var(--border);
        }

        .unanswered-warn {
            background: rgba(245, 158, 11, .1);
            border: 1px solid rgba(245, 158, 11, .25);
            border-radius: 8px;
            padding: 10px 12px;
            font-size: .72rem;
            color: var(--warning);
            margin-bottom: 12px;
            display: none;
            align-items: center;
            gap: 8px;
        }

        .submit-btn {
            width: 100%;
            padding: 13px;
            border-radius: 10px;
            border: none;
            background: linear-gradient(135deg, var(--secondary), var(--primary));
            color: #fff;
            font-size: .88rem;
            font-weight: 700;
            cursor: pointer;
            transition: all .2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .submit-btn:hover {
            opacity: .9;
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, .3);
        }

        /* ── Modals ───────────────────────────────────────── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .7);
            backdrop-filter: blur(4px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal-box {
            background: var(--surface);
            border-radius: var(--radius);
            max-width: 460px;
            width: 100%;
            padding: 32px;
            box-shadow: 0 24px 64px rgba(0, 0, 0, .5);
            animation: popIn .25s ease;
        }

        @keyframes popIn {
            from {
                transform: scale(.92);
                opacity: 0;
            }

            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        .modal-icon {
            font-size: 2.4rem;
            margin-bottom: 16px;
            display: block;
            text-align: center;
        }

        .modal-title {
            font-size: 1.2rem;
            font-weight: 700;
            text-align: center;
            margin-bottom: 8px;
        }

        .modal-body {
            font-size: .85rem;
            color: var(--muted);
            text-align: center;
            line-height: 1.6;
            margin-bottom: 24px;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
        }

        .modal-actions .btn-cancel {
            flex: 1;
            padding: 11px;
            border-radius: 10px;
            background: var(--surface2);
            color: var(--muted);
            border: none;
            cursor: pointer;
            font-size: .85rem;
            font-weight: 600;
        }

        .modal-actions .btn-confirm {
            flex: 2;
            padding: 11px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--secondary), var(--primary));
            color: #fff;
            border: none;
            cursor: pointer;
            font-size: .85rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        /* ── Submitting overlay ───────────────────────────── */
        .submitted-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: var(--bg);
            z-index: 99999;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 16px;
        }

        .submitted-overlay.show {
            display: flex;
        }

        .submitted-spinner {
            width: 56px;
            height: 56px;
            border: 4px solid var(--surface2);
            border-top: 4px solid var(--secondary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* ══════════════════════════════════════════════════
           ── Scientific Calculator ─────────────────────────
           ══════════════════════════════════════════════════ */
        .calc-panel {
            position: fixed;
            bottom: 24px;
            right: 280px;
            /* sits just left of the sidebar */
            width: 320px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: 0 16px 48px rgba(0, 0, 0, .5);
            z-index: 8000;
            display: none;
            flex-direction: column;
            overflow: hidden;
            animation: popIn .2s ease;
        }

        .calc-panel.open {
            display: flex;
        }

        /* Drag handle / header */
        .calc-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            background: var(--surface2);
            border-bottom: 1px solid var(--border);
            cursor: move;
            user-select: none;
        }

        .calc-header-title {
            font-size: .72rem;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .07em;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .calc-close-btn {
            background: none;
            border: none;
            color: var(--muted);
            cursor: pointer;
            font-size: .85rem;
            padding: 2px 6px;
            border-radius: 4px;
            transition: color .15s;
        }

        .calc-close-btn:hover {
            color: var(--danger);
        }

        /* Display */
        .calc-display {
            padding: 12px 16px;
            background: #0a1120;
            text-align: right;
        }

        .calc-expr {
            font-size: .72rem;
            color: var(--muted);
            font-family: 'Courier New', monospace;
            min-height: 18px;
            word-break: break-all;
        }

        .calc-screen {
            font-size: 1.7rem;
            font-weight: 700;
            color: #fff;
            font-family: 'Courier New', monospace;
            word-break: break-all;
            line-height: 1.2;
            margin-top: 4px;
        }

        .calc-screen.error {
            color: var(--danger);
            font-size: 1.1rem;
        }

        /* Button grid */
        .calc-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 1px;
            background: var(--border);
        }

        .calc-btn {
            padding: 11px 4px;
            border: none;
            background: var(--surface2);
            color: var(--text);
            font-size: .78rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .12s, color .12s;
            font-family: 'Poppins', sans-serif;
            text-align: center;
        }

        .calc-btn:hover {
            background: var(--surface);
        }

        .calc-btn:active {
            background: #1a2840;
        }

        /* Button colour variants */
        .calc-btn.op {
            color: var(--secondary);
        }

        .calc-btn.fn {
            color: #a78bfa;
            font-size: .7rem;
        }

        .calc-btn.eq {
            background: linear-gradient(135deg, var(--secondary), var(--primary));
            color: #fff;
            grid-column: span 1;
        }

        .calc-btn.eq:hover {
            opacity: .88;
        }

        .calc-btn.clr {
            color: var(--danger);
        }

        .calc-btn.mem {
            color: var(--success);
            font-size: .7rem;
        }

        .calc-btn.zero {
            grid-column: span 2;
        }

        /* Mode toggle (DEG/RAD) */
        .calc-mode-bar {
            display: flex;
            gap: 0;
            border-top: 1px solid var(--border);
        }

        .calc-mode-btn {
            flex: 1;
            padding: 7px;
            border: none;
            background: var(--surface2);
            color: var(--muted);
            font-size: .68rem;
            font-weight: 700;
            cursor: pointer;
            transition: all .15s;
            font-family: 'Poppins', sans-serif;
            letter-spacing: .04em;
        }

        .calc-mode-btn.active {
            background: var(--primary);
            color: #fff;
        }

        /* ── Responsiveness ───────────────────────────────── */
        @media (max-width:900px) {
            .exam-body {
                grid-template-columns: 1fr;
            }

            .exam-sidebar {
                display: none;
            }

            .topbar-center {
                display: none;
            }

            .topbar {
                justify-content: space-between;
            }

            .question-pane {
                padding: 18px;
            }

            .calc-panel {
                right: 12px;
                bottom: 12px;
                width: 300px;
            }
        }
    </style>
</head>

<body oncontextmenu="return false;">

    <div class="exam-layout">

        <!-- ===== TOP BAR ===== -->
        <div class="topbar">
            <div class="topbar-left">
                <div class="school-badge">
                    <div class="school-badge-icon"><i class="fas fa-graduation-cap"></i></div>
                    <div>
                        <div class="exam-title-bar"><?php echo htmlspecialchars($exam['exam_name']); ?></div>
                        <div class="exam-subject-bar"><?php echo htmlspecialchars($exam['subject_name'] ?? 'Exam'); ?> &bull; <?php echo htmlspecialchars($school_name); ?></div>
                    </div>
                </div>
            </div>

            <div class="topbar-center">
                <!-- Timer -->
                <div class="timer-block">
                    <i class="fas fa-clock timer-icon"></i>
                    <div class="timer-display" id="timerDisplay"><?php echo gmdate('H:i:s', $time_remaining); ?></div>
                </div>

                <!-- Progress ring -->
                <div class="progress-chip">
                    <div>
                        <div style="font-size:.75rem; font-weight:700; color:#fff;" id="answeredCount"><?php echo $answered_count; ?>/<?php echo $total_questions; ?></div>
                        <div class="progress-text">answered</div>
                    </div>
                    <div class="progress-ring-wrap">
                        <svg class="progress-ring" width="34" height="34" viewBox="0 0 34 34">
                            <circle class="progress-ring-bg" cx="17" cy="17" r="14" stroke-width="3" />
                            <circle class="progress-ring-fill" id="progressRing"
                                cx="17" cy="17" r="14" stroke-width="3"
                                stroke-dasharray="<?php echo round(2 * M_PI * 14, 2); ?>"
                                stroke-dashoffset="<?php echo round(2 * M_PI * 14 * (1 - $progress_percent / 100), 2); ?>" />
                        </svg>
                        <div class="progress-ring-number" id="progressPct"><?php echo $progress_percent; ?>%</div>
                    </div>
                </div>
            </div>

            <div class="topbar-right">
                <!-- Scientific calculator toggle -->
                <button class="calc-toggle-btn" id="calcToggleBtn" onclick="toggleCalc()" title="Scientific Calculator">
                    <i class="fas fa-calculator"></i> Calc
                </button>

                <div class="student-chip">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo htmlspecialchars($student_name); ?></span>
                </div>

                <button class="nav-btn nav-btn-next" id="topSubmitBtn" onclick="openSubmitModal()" style="padding:8px 16px;">
                    <i class="fas fa-paper-plane"></i> Submit
                </button>
            </div>
        </div>

        <!-- ===== BODY ===== -->
        <div class="exam-body">

            <!-- Question pane -->
            <div class="question-pane">
                <form id="examForm" method="POST">
                    <input type="hidden" name="session_id" value="<?php echo $session['id']; ?>">
                    <input type="hidden" name="submit_exam" value="1">

                    <?php foreach ($questions as $idx => $q):
                        $saved = $saved_answers[$q['id']] ?? '';
                        $opts  = [
                            'A' => $q['option_a'] ?? '',
                            'B' => $q['option_b'] ?? '',
                            'C' => $q['option_c'] ?? '',
                            'D' => $q['option_d'] ?? '',
                        ];
                        if (!empty($q['option_e'])) $opts['E'] = $q['option_e'];
                    ?>
                        <div class="question-slide <?php echo $idx === 0 ? 'active' : ''; ?>"
                            id="qs_<?php echo $idx; ?>"
                            data-qid="<?php echo $q['id']; ?>"
                            data-index="<?php echo $idx; ?>">

                            <div class="question-header">
                                <span class="question-counter">Question <?php echo $idx + 1; ?> of <?php echo $total_questions; ?></span>
                                <button type="button" class="question-flag-btn" id="flag_<?php echo $idx; ?>" onclick="toggleFlag(<?php echo $idx; ?>)">
                                    <i class="fas fa-flag"></i> Flag
                                </button>
                            </div>

                            <div class="question-text-box">
                                <div class="question-number-badge"><?php echo $idx + 1; ?></div>
                                <div class="question-text"><?php echo nl2br(htmlspecialchars($q['question_text'])); ?></div>
                            </div>

                            <ul class="options-list">
                                <?php foreach ($opts as $letter => $text): if (!$text) continue; ?>
                                    <li class="option-item <?php echo strtoupper($saved) === strtoupper($letter) ? 'selected' : ''; ?>"
                                        data-opt="<?php echo $letter; ?>"
                                        onclick="selectOption(this, <?php echo $q['id']; ?>, '<?php echo $letter; ?>', <?php echo $idx; ?>)">
                                        <input type="radio" name="q_<?php echo $q['id']; ?>" value="<?php echo $letter; ?>"
                                            <?php echo strtoupper($saved) === strtoupper($letter) ? 'checked' : ''; ?>>
                                        <div class="option-key"><?php echo $letter; ?></div>
                                        <span class="option-text"><?php echo htmlspecialchars($text); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>

                            <div class="nav-row">
                                <button type="button" class="nav-btn nav-btn-prev" onclick="goTo(<?php echo $idx - 1; ?>)"
                                    <?php echo $idx === 0 ? 'disabled' : ''; ?>>
                                    <i class="fas fa-arrow-left"></i> Previous
                                </button>
                                <?php if ($idx < $total_questions - 1): ?>
                                    <button type="button" class="nav-btn nav-btn-next" onclick="goTo(<?php echo $idx + 1; ?>)">
                                        Next <i class="fas fa-arrow-right"></i>
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="nav-btn nav-btn-next" onclick="openSubmitModal()">
                                        <i class="fas fa-paper-plane"></i> Submit Exam
                                    </button>
                                <?php endif; ?>
                            </div>

                            <div class="keyboard-tips">
                                <span class="kbt"><kbd>N</kbd> / <kbd>→</kbd> Next</span>
                                <span class="kbt"><kbd>P</kbd> / <kbd>←</kbd> Prev</span>
                                <span class="kbt"><kbd>A</kbd>&ndash;<?php echo $has_option_e ? '<kbd>E</kbd>' : '<kbd>D</kbd>'; ?> Select</span>
                                <span class="kbt"><kbd>F</kbd> Flag</span>
                                <span class="kbt"><kbd>S</kbd> Submit</span>
                                <span class="kbt"><kbd>C</kbd> Calculator</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </form>
            </div>

            <!-- Right sidebar -->
            <div class="exam-sidebar">
                <div>
                    <div class="sidebar-section-title">Question Navigator</div>
                    <div class="q-grid" id="qGrid">
                        <?php foreach ($questions as $i => $q):
                            $ans = $saved_answers[$q['id']] ?? '';
                            $cls = $ans ? 'answered' : '';
                            $cls .= $i === 0 ? ' current' : '';
                        ?>
                            <div class="q-cell <?php echo trim($cls); ?>"
                                id="qc_<?php echo $i; ?>"
                                onclick="goTo(<?php echo $i; ?>)"
                                title="Question <?php echo $i + 1; ?>"><?php echo $i + 1; ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <div class="sidebar-section-title">Legend</div>
                    <div class="legend">
                        <div class="legend-item">
                            <div class="legend-dot" style="background:var(--primary);"></div> Answered
                        </div>
                        <div class="legend-item">
                            <div class="legend-dot" style="background:var(--surface2); border:1.5px solid var(--border);"></div> Not answered
                        </div>
                        <div class="legend-item">
                            <div class="legend-dot" style="background:rgba(245,158,11,.2); border:1.5px solid var(--warning);"></div> Flagged for review
                        </div>
                        <div class="legend-item">
                            <div class="legend-dot" style="border:2px solid var(--secondary);"></div> Current question
                        </div>
                    </div>
                </div>

                <div class="submit-section">
                    <div class="unanswered-warn" id="unansweredWarn">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span id="unansweredText"></span>
                    </div>
                    <button class="submit-btn" onclick="openSubmitModal()">
                        <i class="fas fa-paper-plane"></i> Submit Exam
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== Scientific Calculator Panel ===== -->
    <div class="calc-panel" id="calcPanel">
        <div class="calc-header" id="calcDragHandle">
            <div class="calc-header-title"><i class="fas fa-calculator"></i> Scientific Calculator</div>
            <button class="calc-close-btn" onclick="toggleCalc()" title="Close"><i class="fas fa-times"></i></button>
        </div>

        <div class="calc-display">
            <div class="calc-expr" id="calcExpr">&nbsp;</div>
            <div class="calc-screen" id="calcScreen">0</div>
        </div>

        <!-- Button grid: 5 columns -->
        <div class="calc-grid">
            <!-- Row 1 -->
            <button class="calc-btn mem" onclick="calcMem('MS')">MS</button>
            <button class="calc-btn mem" onclick="calcMem('MR')">MR</button>
            <button class="calc-btn mem" onclick="calcMem('M+')">M+</button>
            <button class="calc-btn mem" onclick="calcMem('M-')">M&minus;</button>
            <button class="calc-btn mem" onclick="calcMem('MC')">MC</button>

            <!-- Row 2 -->
            <button class="calc-btn fn" onclick="calcFn('sin')">sin</button>
            <button class="calc-btn fn" onclick="calcFn('cos')">cos</button>
            <button class="calc-btn fn" onclick="calcFn('tan')">tan</button>
            <button class="calc-btn fn" onclick="calcFn('log')">log</button>
            <button class="calc-btn fn" onclick="calcFn('ln')">ln</button>

            <!-- Row 3 -->
            <button class="calc-btn fn" onclick="calcFn('asin')">sin⁻¹</button>
            <button class="calc-btn fn" onclick="calcFn('acos')">cos⁻¹</button>
            <button class="calc-btn fn" onclick="calcFn('atan')">tan⁻¹</button>
            <button class="calc-btn fn" onclick="calcFn('sqrt')">√</button>
            <button class="calc-btn fn" onclick="calcFn('cbrt')">∛</button>

            <!-- Row 4 -->
            <button class="calc-btn fn" onclick="calcInput('Math.PI')">π</button>
            <button class="calc-btn fn" onclick="calcInput('Math.E')">e</button>
            <button class="calc-btn op" onclick="calcInput('^')">xʸ</button>
            <button class="calc-btn op" onclick="calcInput('%')">%</button>
            <button class="calc-btn fn" onclick="calcFn('fact')">n!</button>

            <!-- Row 5 -->
            <button class="calc-btn op" onclick="calcInput('(')">(</button>
            <button class="calc-btn op" onclick="calcInput(')')">)</button>
            <button class="calc-btn fn" onclick="calcFn('inv')">1/x</button>
            <button class="calc-btn fn" onclick="calcFn('sq')">x²</button>
            <button class="calc-btn fn" onclick="calcFn('abs')">|x|</button>

            <!-- Row 6: digits + ops -->
            <button class="calc-btn" onclick="calcInput('7')">7</button>
            <button class="calc-btn" onclick="calcInput('8')">8</button>
            <button class="calc-btn" onclick="calcInput('9')">9</button>
            <button class="calc-btn op" onclick="calcInput('/')">÷</button>
            <button class="calc-btn clr" onclick="calcDel()">⌫</button>

            <!-- Row 7 -->
            <button class="calc-btn" onclick="calcInput('4')">4</button>
            <button class="calc-btn" onclick="calcInput('5')">5</button>
            <button class="calc-btn" onclick="calcInput('6')">6</button>
            <button class="calc-btn op" onclick="calcInput('*')">×</button>
            <button class="calc-btn clr" onclick="calcClear()">C</button>

            <!-- Row 8 -->
            <button class="calc-btn" onclick="calcInput('1')">1</button>
            <button class="calc-btn" onclick="calcInput('2')">2</button>
            <button class="calc-btn" onclick="calcInput('3')">3</button>
            <button class="calc-btn op" onclick="calcInput('-')">−</button>
            <button class="calc-btn op" onclick="calcToggleSign()">±</button>

            <!-- Row 9 -->
            <button class="calc-btn zero" onclick="calcInput('0')">0</button>
            <button class="calc-btn" onclick="calcInput('.')">.</button>
            <button class="calc-btn op" onclick="calcInput('+')">+</button>
            <button class="calc-btn eq" onclick="calcEval()">=</button>
        </div>

        <!-- DEG / RAD toggle -->
        <div class="calc-mode-bar">
            <button class="calc-mode-btn active" id="modeDeg" onclick="setAngleMode('deg')">DEG</button>
            <button class="calc-mode-btn" id="modeRad" onclick="setAngleMode('rad')">RAD</button>
        </div>
    </div>

    <!-- ===== Submit Confirmation Modal ===== -->
    <div class="modal-overlay" id="submitModal">
        <div class="modal-box">
            <span class="modal-icon" id="modalIcon">📋</span>
            <div class="modal-title" id="modalTitle">Submit Exam?</div>
            <div class="modal-body" id="modalBody">Are you sure you want to submit your exam? This action cannot be undone.</div>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="closeModal()">Cancel</button>
                <button class="btn-confirm" onclick="submitExam()">
                    <i class="fas fa-paper-plane"></i> Submit Now
                </button>
            </div>
        </div>
    </div>

    <!-- ===== Submitting overlay ===== -->
    <div class="submitted-overlay" id="submittingOverlay">
        <div class="submitted-spinner"></div>
        <div style="font-size:1rem; font-weight:600; color:var(--muted);">Submitting your exam…</div>
        <div style="font-size:.8rem; color:var(--muted);">Please wait, do not close this window.</div>
    </div>

    <script>
        // ═══════════════════════════════════════════════════════════
        // Exam core
        // ═══════════════════════════════════════════════════════════
        const TOTAL = <?php echo $total_questions; ?>;
        const SESSION = <?php echo $session['id']; ?>;
        const CIRCUMF = <?php echo round(2 * M_PI * 14, 4); ?>;

        let currentIdx = 0;
        let timeLeft = <?php echo $time_remaining; ?>;
        let answered = <?php echo json_encode(array_map(fn($v) => strtoupper($v), $saved_answers)); ?>;
        let flagged = {};
        let formSubmitted = false;

        // ── Timer ───────────────────────────────────────────────────
        const timerEl = document.getElementById('timerDisplay');

        function tick() {
            if (timeLeft <= 0) {
                autoSubmit();
                return;
            }
            timeLeft--;
            const h = String(Math.floor(timeLeft / 3600)).padStart(2, '0');
            const m = String(Math.floor((timeLeft % 3600) / 60)).padStart(2, '0');
            const s = String(timeLeft % 60).padStart(2, '0');
            timerEl.textContent = `${h}:${m}:${s}`;
            if (timeLeft <= 60) timerEl.className = 'timer-display danger';
            else if (timeLeft <= 300) timerEl.className = 'timer-display warning';
            updateUnanswered();
        }
        setInterval(tick, 1000);

        // ── Navigation ──────────────────────────────────────────────
        function goTo(idx) {
            if (idx < 0 || idx >= TOTAL) return;
            document.querySelectorAll('.question-slide').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.q-cell').forEach(el => el.classList.remove('current'));
            document.getElementById('qs_' + idx).classList.add('active');
            document.getElementById('qc_' + idx)?.classList.add('current');
            currentIdx = idx;
            document.querySelector('.question-pane').scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }

        // ── Select option ───────────────────────────────────────────
        function selectOption(li, qid, letter, idx) {
            li.closest('.options-list').querySelectorAll('.option-item').forEach(el => {
                el.classList.remove('selected');
                el.querySelector('input').checked = false;
            });
            li.classList.add('selected');
            li.querySelector('input').checked = true;
            answered[qid] = letter;
            document.getElementById('qc_' + idx)?.classList.add('answered');
            updateProgress();
            saveAnswer(qid, letter);
        }

        // ── Flag ────────────────────────────────────────────────────
        function toggleFlag(idx) {
            flagged[idx] = !flagged[idx];
            const btn = document.getElementById('flag_' + idx);
            const cell = document.getElementById('qc_' + idx);
            btn.classList.toggle('flagged', !!flagged[idx]);
            btn.innerHTML = flagged[idx] ?
                '<i class="fas fa-flag"></i> Flagged' :
                '<i class="fas fa-flag"></i> Flag';
            cell?.classList.toggle('flagged', !!flagged[idx]);
        }

        // ── Save answer to server (AJAX) ────────────────────────────
        function saveAnswer(qid, letter) {
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `save_answer=1&session_id=${SESSION}&question_id=${qid}&answer=${letter}`
            });
        }

        // ── Progress ────────────────────────────────────────────────
        function updateProgress() {
            const count = Object.keys(answered).length;
            const pct = TOTAL > 0 ? Math.round((count / TOTAL) * 100) : 0;
            const offset = CIRCUMF * (1 - pct / 100);
            document.getElementById('answeredCount').textContent = `${count}/${TOTAL}`;
            document.getElementById('progressPct').textContent = `${pct}%`;
            document.getElementById('progressRing').style.strokeDashoffset = offset;
        }

        function updateUnanswered() {
            const ua = TOTAL - Object.keys(answered).length;
            const el = document.getElementById('unansweredWarn');
            const tx = document.getElementById('unansweredText');
            if (ua > 0) {
                tx.textContent = `${ua} question${ua > 1 ? 's' : ''} unanswered`;
                el.style.display = 'flex';
            } else {
                el.style.display = 'none';
            }
        }

        // ── Submit modal ────────────────────────────────────────────
        function openSubmitModal() {
            const ua = TOTAL - Object.keys(answered).length;
            const icon = document.getElementById('modalIcon');
            const title = document.getElementById('modalTitle');
            const body = document.getElementById('modalBody');
            if (ua > 0) {
                icon.textContent = '⚠️';
                title.textContent = `${ua} Question${ua > 1 ? 's' : ''} Unanswered`;
                body.textContent = `You still have ${ua} unanswered question${ua > 1 ? 's' : ''}. Once submitted, you cannot return. Are you sure?`;
            } else {
                icon.textContent = '🎉';
                title.textContent = 'Ready to Submit?';
                body.textContent = 'You have answered all questions. Click Submit Now to finalize your exam.';
            }
            document.getElementById('submitModal').classList.add('open');
        }

        function closeModal() {
            document.getElementById('submitModal').classList.remove('open');
        }

        function submitExam() {
            closeModal();
            formSubmitted = true;
            document.getElementById('submittingOverlay').classList.add('show');
            document.getElementById('examForm').submit();
        }

        function autoSubmit() {
            formSubmitted = true;
            document.getElementById('submittingOverlay').classList.add('show');
            document.getElementById('examForm').submit();
        }

        // ── Keyboard shortcuts ──────────────────────────────────────
        document.addEventListener('keydown', function(e) {
            // Let the calculator's own inputs type freely
            if (calcIsOpen() && ['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName)) return;
            // Suppress exam shortcuts while the calc panel is focused (user is interacting with calc buttons)
            if (document.getElementById('calcPanel').contains(document.activeElement)) return;

            const k = e.key.toLowerCase();
            if (k === 'n' || k === 'arrowright') {
                e.preventDefault();
                goTo(currentIdx + 1);
            } else if (k === 'p' || k === 'arrowleft') {
                e.preventDefault();
                goTo(currentIdx - 1);
            } else if (k === 'f') {
                e.preventDefault();
                toggleFlag(currentIdx);
            } else if (k === 's') {
                e.preventDefault();
                openSubmitModal();
            } else if (k === 'c') {
                e.preventDefault();
                toggleCalc();
            } else if (['a', 'b', 'd'].includes(k)) {
                // Only fire A/B/D as option selectors when calc is closed
                if (calcIsOpen()) return;
                e.preventDefault();
                const slide = document.getElementById('qs_' + currentIdx);
                const li = slide?.querySelector(`.option-item[data-opt="${k.toUpperCase()}"]`);
                if (li) selectOption(li, parseInt(slide.dataset.qid), k.toUpperCase(), currentIdx);
            } else if (k === 'e') {
                if (calcIsOpen()) return;
                e.preventDefault();
                const slide = document.getElementById('qs_' + currentIdx);
                const li = slide?.querySelector('.option-item[data-opt="E"]');
                if (li) selectOption(li, parseInt(slide.dataset.qid), 'E', currentIdx);
            }
        });

        // ── Prevent navigation away ─────────────────────────────────
        window.addEventListener('beforeunload', e => {
            if (!formSubmitted) {
                e.preventDefault();
                e.returnValue = 'Your exam is still in progress. Are you sure you want to leave?';
            }
        });
        history.pushState(null, '', location.href);
        window.addEventListener('popstate', () => {
            if (!formSubmitted) history.pushState(null, '', location.href);
        });

        // ═══════════════════════════════════════════════════════════
        // Scientific Calculator
        // ═══════════════════════════════════════════════════════════
        let calcBuffer = ''; // raw expression string
        let calcResult = null; // last evaluated result
        let calcMemory = 0;
        let angleMode = 'deg'; // 'deg' | 'rad'
        let calcJustEvaled = false;

        const calcPanel = document.getElementById('calcPanel');
        const calcScreen = document.getElementById('calcScreen');
        const calcExpr = document.getElementById('calcExpr');
        const calcToggle = document.getElementById('calcToggleBtn');

        function calcIsOpen() {
            return calcPanel.classList.contains('open');
        }

        function toggleCalc() {
            calcPanel.classList.toggle('open');
            calcToggle.classList.toggle('active', calcIsOpen());
        }

        function setAngleMode(mode) {
            angleMode = mode;
            document.getElementById('modeDeg').classList.toggle('active', mode === 'deg');
            document.getElementById('modeRad').classList.toggle('active', mode === 'rad');
        }

        function toRad(deg) {
            return deg * Math.PI / 180;
        }

        function toDeg(rad) {
            return rad * 180 / Math.PI;
        }

        function calcRender() {
            calcScreen.className = 'calc-screen';
            calcScreen.textContent = calcBuffer || '0';
            calcExpr.textContent = calcResult !== null ? String(calcResult) : '\u00a0';
        }

        function calcInput(token) {
            // If user starts typing after an eval, clear the buffer
            if (calcJustEvaled && /[\d.(]/.test(token)) {
                calcBuffer = '';
                calcResult = null;
            }
            calcJustEvaled = false;

            // Replace Math constants with their values for display clarity
            if (token === 'Math.PI') {
                calcBuffer += 'π';
            } else if (token === 'Math.E') {
                calcBuffer += 'e';
            } else if (token === '^') {
                calcBuffer += '^';
            } else {
                calcBuffer += token;
            }

            calcScreen.className = 'calc-screen';
            calcScreen.textContent = calcBuffer || '0';
            calcExpr.textContent = '\u00a0';
        }

        function calcDel() {
            calcJustEvaled = false;
            calcBuffer = calcBuffer.slice(0, -1);
            calcScreen.textContent = calcBuffer || '0';
        }

        function calcClear() {
            calcBuffer = '';
            calcResult = null;
            calcJustEvaled = false;
            calcScreen.className = 'calc-screen';
            calcScreen.textContent = '0';
            calcExpr.textContent = '\u00a0';
        }

        function calcEval() {
            if (!calcBuffer) return;
            try {
                // Build eval-safe expression
                let expr = calcBuffer
                    .replace(/π/g, 'Math.PI')
                    .replace(/e(?![a-z])/g, 'Math.E') // lone 'e' → Euler's number
                    .replace(/\^/g, '**')
                    .replace(/÷/g, '/')
                    .replace(/×/g, '*');

                // Angle conversions for trig (wrap them)
                if (angleMode === 'deg') {
                    expr = expr.replace(/Math\.(sin|cos|tan)\(/g, (_, fn) =>
                        `Math.${fn}(Math.PI/180*`
                    ).replace(/Math\.a(sin|cos|tan)\(/g, (_, fn) =>
                        `(180/Math.PI)*Math.a${fn}(`
                    );
                }

                // Safety: allow only math-related tokens
                const safe = expr.replace(/Math\.[a-z]+/g, '').replace(/[\d\s()\+\-\*\/\.\,\%\*\*]/g, '');
                if (safe.length > 0) throw new Error('Unsafe expression');

                // eslint-disable-next-line no-new-func
                const result = Function('"use strict"; return (' + expr + ')')();

                if (!isFinite(result)) throw new Error('Result is not finite');

                const display = parseFloat(result.toPrecision(12)).toString();
                calcResult = calcBuffer;
                calcBuffer = display;
                calcJustEvaled = true;

                calcScreen.className = 'calc-screen';
                calcScreen.textContent = display;
                calcExpr.textContent = calcResult + ' =';
            } catch {
                calcScreen.className = 'calc-screen error';
                calcScreen.textContent = 'Error';
                calcExpr.textContent = calcBuffer;
                calcBuffer = '';
                calcResult = null;
                calcJustEvaled = false;
            }
        }

        function calcFn(fn) {
            const raw = calcBuffer;
            let val = parseFloat(raw);

            // If buffer is empty or not a number, use last result
            if (isNaN(val) && calcResult !== null) val = parseFloat(calcResult);
            if (isNaN(val)) val = 0;

            let result;
            try {
                switch (fn) {
                    case 'sin':
                        result = Math.sin(angleMode === 'deg' ? toRad(val) : val);
                        break;
                    case 'cos':
                        result = Math.cos(angleMode === 'deg' ? toRad(val) : val);
                        break;
                    case 'tan':
                        result = Math.tan(angleMode === 'deg' ? toRad(val) : val);
                        break;
                    case 'asin':
                        result = angleMode === 'deg' ? toDeg(Math.asin(val)) : Math.asin(val);
                        break;
                    case 'acos':
                        result = angleMode === 'deg' ? toDeg(Math.acos(val)) : Math.acos(val);
                        break;
                    case 'atan':
                        result = angleMode === 'deg' ? toDeg(Math.atan(val)) : Math.atan(val);
                        break;
                    case 'log':
                        result = Math.log10(val);
                        break;
                    case 'ln':
                        result = Math.log(val);
                        break;
                    case 'sqrt':
                        result = Math.sqrt(val);
                        break;
                    case 'cbrt':
                        result = Math.cbrt(val);
                        break;
                    case 'sq':
                        result = val * val;
                        break;
                    case 'inv':
                        result = 1 / val;
                        break;
                    case 'abs':
                        result = Math.abs(val);
                        break;
                    case 'fact': {
                        if (val < 0 || !Number.isInteger(val)) throw new Error();
                        result = 1;
                        for (let i = 2; i <= val; i++) result *= i;
                        break;
                    }
                }
                if (!isFinite(result)) throw new Error('Not finite');
                const display = parseFloat(result.toPrecision(12)).toString();
                calcExpr.textContent = `${fn}(${raw}) =`;
                calcBuffer = display;
                calcJustEvaled = true;
                calcScreen.className = 'calc-screen';
                calcScreen.textContent = display;
            } catch {
                calcScreen.className = 'calc-screen error';
                calcScreen.textContent = 'Error';
                calcBuffer = '';
                calcJustEvaled = false;
            }
        }

        function calcToggleSign() {
            if (!calcBuffer) return;
            if (calcBuffer.startsWith('-')) {
                calcBuffer = calcBuffer.slice(1);
            } else {
                calcBuffer = '-' + calcBuffer;
            }
            calcScreen.textContent = calcBuffer;
        }

        function calcMem(op) {
            const val = parseFloat(calcBuffer) || 0;
            switch (op) {
                case 'MS':
                    calcMemory = val;
                    break;
                case 'MR':
                    calcBuffer = String(calcMemory);
                    calcScreen.textContent = calcBuffer;
                    break;
                case 'M+':
                    calcMemory += val;
                    break;
                case 'M-':
                    calcMemory -= val;
                    break;
                case 'MC':
                    calcMemory = 0;
                    break;
            }
            // Flash memory label briefly
            if (['MS', 'M+', 'M-'].includes(op)) {
                calcExpr.textContent = `M = ${calcMemory}`;
            }
        }

        // ── Draggable calculator ────────────────────────────────────
        (function() {
            const handle = document.getElementById('calcDragHandle');
            let dragging = false,
                ox = 0,
                oy = 0;

            handle.addEventListener('mousedown', e => {
                dragging = true;
                const rect = calcPanel.getBoundingClientRect();
                ox = e.clientX - rect.left;
                oy = e.clientY - rect.top;
                calcPanel.style.right = 'auto'; // switch from right-anchored to left-anchored
                calcPanel.style.bottom = 'auto';
                calcPanel.style.left = rect.left + 'px';
                calcPanel.style.top = rect.top + 'px';
            });
            document.addEventListener('mousemove', e => {
                if (!dragging) return;
                calcPanel.style.left = (e.clientX - ox) + 'px';
                calcPanel.style.top = (e.clientY - oy) + 'px';
            });
            document.addEventListener('mouseup', () => {
                dragging = false;
            });
        })();

        // ── Init ─────────────────────────────────────────────────────
        goTo(0);
        updateProgress();
        updateUnanswered();
    </script>
</body>

</html>
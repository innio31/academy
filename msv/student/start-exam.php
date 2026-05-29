<?php
// msv/student/start-exam.php  — Full-screen locked exam interface
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: /msv/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id   = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color   = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;
$student_id  = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'] ?? 'Student';

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

// ── Resume existing session ──────────────────────────────────
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
}
// ── Start new exam ───────────────────────────────────────────
elseif ($exam_id) {
    $stmt = $pdo->prepare("
        SELECT e.*, s.subject_name
        FROM exams e
        JOIN subjects s ON e.subject_id = s.id
        WHERE e.id = ? AND e.school_id = ? AND e.class_id = ? AND e.is_active = 1
    ");
    $stmt->execute([$exam_id, $school_id, $student_class_id]);
    $exam = $stmt->fetch();

    if ($exam) {
        // Check not already completed
        $stmt = $pdo->prepare("SELECT id FROM exam_sessions WHERE student_id = ? AND exam_id = ? AND status = 'completed'");
        $stmt->execute([$student_id, $exam_id]);
        if ($stmt->fetch()) {
            header("Location: view-results.php?exam_id=$exam_id&already=1");
            exit();
        }

        // Check for orphaned in-progress session
        $stmt = $pdo->prepare("SELECT id FROM exam_sessions WHERE student_id = ? AND exam_id = ? AND status = 'in_progress' AND end_time > NOW()");
        $stmt->execute([$student_id, $exam_id]);
        $existing = $stmt->fetch();
        if ($existing) {
            header("Location: start-exam.php?resume=" . $existing['id']);
            exit();
        }

        // Get random questions
        // Decode topic IDs stored on the exam record
        $topic_ids = [];
        if (!empty($exam['topics'])) {
            $decoded = json_decode($exam['topics'], true);
            if (is_array($decoded)) {
                $topic_ids = array_map('intval', $decoded);
            }
        }

        if (!empty($topic_ids)) {
            $ph = implode(',', array_fill(0, count($topic_ids), '?'));
            $stmt = $pdo->prepare("
                SELECT * FROM objective_questions
                WHERE subject_id = ?
                  AND topic_id IN ($ph)
                ORDER BY RAND()
                LIMIT ?
            ");
            $stmt->execute([$exam['subject_id'], ...$topic_ids, (int)$exam['objective_count']]);
        } else {
            // Fallback: exam has no topics set, use subject only
            $stmt = $pdo->prepare("
                SELECT * FROM objective_questions
                WHERE subject_id = ?
                ORDER BY RAND()
                LIMIT ?
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

            foreach ($questions as $q) {
                $stmt = $pdo->prepare("INSERT INTO exam_session_questions (session_id, question_id, school_id) VALUES (?, ?, ?)");
                $stmt->execute([$session_id, $q['id'], $school_id]);
            }

            $stmt = $pdo->prepare("SELECT * FROM exam_sessions WHERE id = ?");
            $stmt->execute([$session_id]);
            $session = $stmt->fetch();
            $saved_answers = [];
        } else {
            // No questions available — redirect back gracefully
            header("Location: exams.php?error=no_questions");
            exit();
        }
    }
}

// ── AJAX: save single answer ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_answer'])) {
    $s_id  = (int)$_POST['session_id'];
    $q_id  = (int)$_POST['question_id'];
    $ans   = trim($_POST['answer']);

    // Validate session belongs to this student
    $stmt = $pdo->prepare("SELECT objective_answers FROM exam_sessions WHERE id = ? AND student_id = ? AND status = 'in_progress'");
    $stmt->execute([$s_id, $student_id]);
    $cur = $stmt->fetch();
    if ($cur) {
        $answers = $cur['objective_answers'] ? json_decode($cur['objective_answers'], true) : [];
        $answers[$q_id] = $ans;
        $stmt = $pdo->prepare("UPDATE exam_sessions SET objective_answers = ? WHERE id = ?");
        $stmt->execute([json_encode($answers), $s_id]);
        echo json_encode(['success' => true, 'answered' => count($answers)]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// ── POST: submit exam ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_exam'])) {
    $s_id = (int)$_POST['session_id'];

    $stmt = $pdo->prepare("SELECT * FROM exam_sessions WHERE id = ? AND student_id = ? AND status = 'in_progress'");
    $stmt->execute([$s_id, $student_id]);
    $sess = $stmt->fetch();

    if ($sess) {
        $answers = json_decode($sess['objective_answers'], true) ?: [];
        $correct = 0;
        $total   = count($questions);

        if (!empty($answers) && $total > 0) {
            $ph = implode(',', array_fill(0, count($answers), '?'));
            $stmt = $pdo->prepare("SELECT id, correct_answer FROM objective_questions WHERE id IN ($ph)");
            $stmt->execute(array_keys($answers));
            $qdata = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($answers as $qid => $ans) {
                if (isset($qdata[$qid]) && strtoupper($qdata[$qid]) === strtoupper($ans)) $correct++;
            }
        }

        $score = $total > 0 ? round(($correct / $total) * 100, 2) : 0;
        $grade = match(true) {
            $score >= 70 => 'A',
            $score >= 60 => 'B',
            $score >= 50 => 'C',
            $score >= 45 => 'D',
            default      => 'F',
        };

        $stmt = $pdo->prepare("
            UPDATE exam_sessions
            SET status='completed', submitted_at=NOW(),
                score=?, correct_answers=?, total_questions=?, percentage=?, grade=?
            WHERE id=?
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

// ── Guard: if no valid session, bounce back ──────────────────
if (!$exam || !$session) {
    header("Location: exams.php?error=invalid");
    exit();
}

$time_remaining   = max(0, strtotime($session['end_time']) - time());
$answered_count   = count($saved_answers);
$total_questions  = count($questions);
$progress_percent = $total_questions > 0 ? round(($answered_count / $total_questions) * 100) : 0;

// Determine if any option has E
$has_option_e = false;
foreach ($questions as $q) {
    if (!empty($q['option_e'])) { $has_option_e = true; break; }
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
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

        :root {
            --primary:      <?php echo $primary_color; ?>;
            --secondary:    <?php echo $secondary_color; ?>;
            --bg:           #0f172a;
            --surface:      #1e293b;
            --surface2:     #273348;
            --border:       rgba(255,255,255,.08);
            --text:         #e2e8f0;
            --muted:        #94a3b8;
            --success:      #22c55e;
            --warning:      #f59e0b;
            --danger:       #ef4444;
            --radius:       14px;
            --radius-sm:    8px;
        }

        /* ── Full-screen lock ──────────────────────────────── */
        html, body {
            width:100%; height:100%; overflow:hidden;
            font-family:'Poppins', sans-serif;
            background:var(--bg); color:var(--text);
            user-select:none;
        }
        /* Prevent right-click */
        body { -webkit-touch-callout:none; }

        /* ── Layout: top bar + split pane ─────────────────── */
        .exam-layout {
            display:grid;
            grid-template-rows: auto 1fr;
            height:100vh;
        }

        /* ── Top bar ───────────────────────────────────────── */
        .topbar {
            background:var(--surface);
            border-bottom:1px solid var(--border);
            padding:0 24px;
            display:flex; align-items:center; justify-content:space-between;
            gap:16px; flex-wrap:wrap;
            min-height:64px;
        }
        .topbar-left { display:flex; align-items:center; gap:16px; }
        .school-badge {
            display:flex; align-items:center; gap:10px;
        }
        .school-badge-icon {
            width:36px; height:36px; border-radius:8px;
            background:linear-gradient(135deg, var(--secondary), var(--primary));
            display:flex; align-items:center; justify-content:center;
        }
        .school-badge-icon i { color:#fff; font-size:.9rem; }
        .exam-title-bar { font-size:.9rem; font-weight:700; color:#fff; line-height:1.2; }
        .exam-subject-bar { font-size:.7rem; color:var(--muted); }

        .topbar-center { display:flex; align-items:center; gap:24px; }

        /* Timer */
        .timer-block {
            display:flex; align-items:center; gap:10px;
            background:var(--surface2); border-radius:10px; padding:8px 16px;
        }
        .timer-icon { color:var(--secondary); font-size:1rem; }
        .timer-display {
            font-family:'Courier New', monospace;
            font-size:1.3rem; font-weight:800;
            color:#fff; letter-spacing:.05em;
        }
        .timer-display.warning { color:var(--warning); animation:blink .8s ease infinite; }
        .timer-display.danger  { color:var(--danger);  animation:blink .4s ease infinite; }
        @keyframes blink { 0%,100% { opacity:1; } 50% { opacity:.4; } }

        /* Progress chip */
        .progress-chip {
            display:flex; align-items:center; gap:10px;
            background:var(--surface2); border-radius:10px; padding:8px 16px;
        }
        .progress-text { font-size:.75rem; color:var(--muted); }
        .progress-ring-wrap { position:relative; width:34px; height:34px; }
        .progress-ring { transform:rotate(-90deg); }
        .progress-ring-bg { stroke:#334155; fill:none; }
        .progress-ring-fill {
            fill:none; stroke:var(--success);
            stroke-linecap:round;
            transition:stroke-dashoffset .4s ease;
        }
        .progress-ring-number {
            position:absolute; inset:0;
            display:flex; align-items:center; justify-content:center;
            font-size:.55rem; font-weight:700; color:#fff;
        }

        .topbar-right { display:flex; align-items:center; gap:10px; }

        .student-chip {
            display:flex; align-items:center; gap:8px;
            background:var(--surface2); border-radius:10px; padding:6px 12px;
        }
        .student-chip i { color:var(--secondary); }
        .student-chip span { font-size:.75rem; color:var(--muted); }

        /* ── Body ─────────────────────────────────────────── */
        .exam-body {
            display:grid;
            grid-template-columns: 1fr 260px;
            overflow:hidden;
        }

        /* ── Question pane ───────────────────────────────── */
        .question-pane {
            overflow-y:auto;
            padding:28px 32px;
            scroll-behavior:smooth;
        }
        /* Hide scrollbar */
        .question-pane::-webkit-scrollbar { width:4px; }
        .question-pane::-webkit-scrollbar-track { background:transparent; }
        .question-pane::-webkit-scrollbar-thumb { background:var(--surface2); border-radius:4px; }

        .question-slide {
            display:none;
            max-width:760px; margin:0 auto;
            animation:slideIn .25s ease;
        }
        .question-slide.active { display:block; }
        @keyframes slideIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }

        .question-header {
            display:flex; align-items:center; justify-content:space-between;
            margin-bottom:20px;
        }
        .question-counter {
            font-size:.72rem; font-weight:700; color:var(--secondary);
            text-transform:uppercase; letter-spacing:.08em;
        }
        .question-flag-btn {
            background:none; border:1.5px solid var(--border); border-radius:8px;
            padding:5px 12px; cursor:pointer; color:var(--muted);
            font-size:.72rem; transition:all .2s; display:flex; align-items:center; gap:6px;
        }
        .question-flag-btn.flagged { border-color:var(--warning); color:var(--warning); background:rgba(245,158,11,.08); }
        .question-flag-btn:hover { border-color:var(--warning); color:var(--warning); }

        .question-text-box {
            background:var(--surface);
            border:1px solid var(--border);
            border-radius:var(--radius);
            padding:24px 28px;
            margin-bottom:20px;
        }
        .question-number-badge {
            display:inline-flex; align-items:center; justify-content:center;
            width:32px; height:32px; border-radius:8px;
            background:linear-gradient(135deg, var(--secondary), var(--primary));
            font-weight:800; font-size:.85rem; color:#fff;
            margin-bottom:14px;
        }
        .question-text {
            font-size:1.02rem; line-height:1.7; color:var(--text);
            font-weight:500;
        }

        /* Options */
        .options-list { list-style:none; display:flex; flex-direction:column; gap:10px; }
        .option-item {
            border:1.5px solid var(--border);
            border-radius:var(--radius);
            padding:14px 18px;
            cursor:pointer;
            display:flex; align-items:center; gap:14px;
            transition:all .18s;
            background:var(--surface);
            position:relative; overflow:hidden;
        }
        .option-item::before {
            content:''; position:absolute; inset:0;
            background:linear-gradient(90deg, var(--primary), transparent);
            opacity:0; transition:opacity .2s;
        }
        .option-item:hover { border-color:rgba(255,255,255,.2); transform:translateX(3px); }
        .option-item:hover::before { opacity:.04; }
        .option-item.selected {
            border-color:var(--secondary);
            background:rgba(var(--secondary-rgb, 60,100,200),.12);
        }
        .option-key {
            width:32px; height:32px; border-radius:8px;
            background:var(--surface2); border:1.5px solid var(--border);
            display:flex; align-items:center; justify-content:center;
            font-weight:700; font-size:.78rem; color:var(--muted);
            flex-shrink:0; transition:all .18s;
        }
        .option-item.selected .option-key {
            background:var(--secondary);
            border-color:var(--secondary);
            color:#fff;
        }
        .option-item input[type="radio"] { display:none; }
        .option-text { flex:1; font-size:.9rem; line-height:1.5; position:relative; z-index:1; }

        /* Navigation buttons */
        .nav-row {
            display:flex; align-items:center; justify-content:space-between;
            margin-top:28px; gap:12px;
        }
        .nav-btn {
            display:inline-flex; align-items:center; gap:8px;
            padding:11px 22px; border-radius:10px; border:none;
            font-size:.82rem; font-weight:600; cursor:pointer;
            transition:all .2s;
        }
        .nav-btn-prev { background:var(--surface); color:var(--muted); border:1.5px solid var(--border); }
        .nav-btn-prev:hover { border-color:rgba(255,255,255,.2); color:#fff; }
        .nav-btn-next { background:var(--primary); color:#fff; }
        .nav-btn-next:hover { opacity:.88; transform:translateY(-1px); }
        .nav-btn:disabled { opacity:.35; cursor:not-allowed; transform:none !important; }

        .keyboard-tips {
            display:flex; flex-wrap:wrap; gap:6px; margin-top:20px; padding-top:16px;
            border-top:1px solid var(--border);
        }
        .kbt { font-size:.65rem; color:var(--muted); display:flex; align-items:center; gap:4px; }
        .kbt kbd {
            background:var(--surface2); border:1px solid var(--border);
            border-radius:4px; padding:2px 6px;
            font-family:monospace; font-size:.65rem; color:var(--text);
        }

        /* ── Right sidebar ───────────────────────────────── */
        .exam-sidebar {
            background:var(--surface);
            border-left:1px solid var(--border);
            padding:20px;
            overflow-y:auto;
            display:flex; flex-direction:column; gap:20px;
        }
        .exam-sidebar::-webkit-scrollbar { width:3px; }
        .exam-sidebar::-webkit-scrollbar-thumb { background:var(--surface2); }

        .sidebar-section-title {
            font-size:.7rem; font-weight:700; color:var(--muted);
            text-transform:uppercase; letter-spacing:.08em;
            margin-bottom:10px;
        }

        /* Question grid */
        .q-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:6px; }
        .q-cell {
            aspect-ratio:1; border-radius:6px; border:1.5px solid var(--border);
            background:var(--surface2); cursor:pointer;
            display:flex; align-items:center; justify-content:center;
            font-size:.7rem; font-weight:700; color:var(--muted);
            transition:all .18s;
        }
        .q-cell:hover { border-color:var(--secondary); color:#fff; }
        .q-cell.answered { background:var(--primary); border-color:var(--primary); color:#fff; }
        .q-cell.current  { border:2px solid var(--secondary); color:#fff; transform:scale(1.08); }
        .q-cell.flagged  { background:rgba(245,158,11,.15); border-color:var(--warning); color:var(--warning); }
        .q-cell.answered.flagged { background:rgba(245,158,11,.25); border-color:var(--warning); color:var(--warning); }

        /* Legend */
        .legend { display:flex; flex-direction:column; gap:6px; }
        .legend-item { display:flex; align-items:center; gap:8px; font-size:.72rem; color:var(--muted); }
        .legend-dot {
            width:12px; height:12px; border-radius:3px; flex-shrink:0;
        }

        /* Submit section */
        .submit-section { margin-top:auto; padding-top:16px; border-top:1px solid var(--border); }
        .unanswered-warn {
            background:rgba(245,158,11,.1); border:1px solid rgba(245,158,11,.25);
            border-radius:8px; padding:10px 12px; font-size:.72rem;
            color:var(--warning); margin-bottom:12px; display:none;
            align-items:center; gap:8px;
        }
        .submit-btn {
            width:100%; padding:13px; border-radius:10px; border:none;
            background:linear-gradient(135deg, var(--secondary), var(--primary));
            color:#fff; font-size:.88rem; font-weight:700; cursor:pointer;
            transition:all .2s; display:flex; align-items:center; justify-content:center; gap:8px;
        }
        .submit-btn:hover { opacity:.9; transform:translateY(-2px); box-shadow:0 4px 20px rgba(0,0,0,.3); }

        /* ── Overlay modal ────────────────────────────────── */
        .modal-overlay {
            display:none; position:fixed; inset:0;
            background:rgba(0,0,0,.7); backdrop-filter:blur(4px);
            z-index:9999; align-items:center; justify-content:center; padding:20px;
        }
        .modal-overlay.open { display:flex; }
        .modal-box {
            background:var(--surface); border-radius:var(--radius);
            max-width:460px; width:100%; padding:32px;
            box-shadow:0 24px 64px rgba(0,0,0,.5);
            animation:popIn .25s ease;
        }
        @keyframes popIn { from { transform:scale(.92); opacity:0; } to { transform:scale(1); opacity:1; } }
        .modal-icon { font-size:2.4rem; margin-bottom:16px; display:block; text-align:center; }
        .modal-title { font-size:1.2rem; font-weight:700; text-align:center; margin-bottom:8px; }
        .modal-body  { font-size:.85rem; color:var(--muted); text-align:center; line-height:1.6; margin-bottom:24px; }
        .modal-actions { display:flex; gap:10px; }
        .modal-actions .btn-cancel {
            flex:1; padding:11px; border-radius:10px;
            background:var(--surface2); color:var(--muted); border:none; cursor:pointer;
            font-size:.85rem; font-weight:600;
        }
        .modal-actions .btn-confirm {
            flex:2; padding:11px; border-radius:10px;
            background:linear-gradient(135deg, var(--secondary), var(--primary));
            color:#fff; border:none; cursor:pointer; font-size:.85rem; font-weight:700;
            display:flex; align-items:center; justify-content:center; gap:8px;
        }

        /* ── Submitted overlay ────────────────────────────── */
        .submitted-overlay {
            display:none; position:fixed; inset:0;
            background:var(--bg); z-index:99999;
            flex-direction:column; align-items:center; justify-content:center; gap:16px;
        }
        .submitted-overlay.show { display:flex; }
        .submitted-spinner {
            width:56px; height:56px; border:4px solid var(--surface2);
            border-top:4px solid var(--secondary); border-radius:50%;
            animation:spin 1s linear infinite;
        }
        @keyframes spin { to { transform:rotate(360deg); } }

        /* ── Responsiveness ───────────────────────────────── */
        @media (max-width:900px) {
            .exam-body { grid-template-columns:1fr; }
            .exam-sidebar {
                display:none; /* shown via toggle on mobile */
            }
            .topbar-center { display:none; }
            .topbar { justify-content:space-between; }
            .question-pane { padding:18px; }
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
                        <circle class="progress-ring-bg" cx="17" cy="17" r="14" stroke-width="3"/>
                        <circle class="progress-ring-fill" id="progressRing"
                            cx="17" cy="17" r="14" stroke-width="3"
                            stroke-dasharray="<?php echo round(2*M_PI*14, 2); ?>"
                            stroke-dashoffset="<?php echo round(2*M_PI*14*(1-$progress_percent/100), 2); ?>"/>
                    </svg>
                    <div class="progress-ring-number" id="progressPct"><?php echo $progress_percent; ?>%</div>
                </div>
            </div>
        </div>

        <div class="topbar-right">
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
                <input type="hidden" name="session_id"  value="<?php echo $session['id']; ?>">
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
                <div class="question-slide <?php echo $idx===0?'active':''; ?>"
                     id="qs_<?php echo $idx; ?>"
                     data-qid="<?php echo $q['id']; ?>"
                     data-index="<?php echo $idx; ?>">

                    <div class="question-header">
                        <span class="question-counter">Question <?php echo $idx+1; ?> of <?php echo $total_questions; ?></span>
                        <button type="button" class="question-flag-btn" id="flag_<?php echo $idx; ?>" onclick="toggleFlag(<?php echo $idx; ?>)">
                            <i class="fas fa-flag"></i> Flag
                        </button>
                    </div>

                    <div class="question-text-box">
                        <div class="question-number-badge"><?php echo $idx+1; ?></div>
                        <div class="question-text"><?php echo nl2br(htmlspecialchars($q['question_text'])); ?></div>
                    </div>

                    <ul class="options-list">
                        <?php foreach ($opts as $letter => $text): if (!$text) continue; ?>
                        <li class="option-item <?php echo strtoupper($saved)===strtoupper($letter)?'selected':''; ?>"
                            data-opt="<?php echo $letter; ?>"
                            onclick="selectOption(this, <?php echo $q['id']; ?>, '<?php echo $letter; ?>', <?php echo $idx; ?>)">
                            <input type="radio" name="q_<?php echo $q['id']; ?>" value="<?php echo $letter; ?>"
                                   <?php echo strtoupper($saved)===strtoupper($letter)?'checked':''; ?>>
                            <div class="option-key"><?php echo $letter; ?></div>
                            <span class="option-text"><?php echo htmlspecialchars($text); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="nav-row">
                        <button type="button" class="nav-btn nav-btn-prev" onclick="goTo(<?php echo $idx-1; ?>)"
                                <?php echo $idx===0?'disabled':''; ?>>
                            <i class="fas fa-arrow-left"></i> Previous
                        </button>
                        <?php if ($idx < $total_questions-1): ?>
                        <button type="button" class="nav-btn nav-btn-next" onclick="goTo(<?php echo $idx+1; ?>)">
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
                        <span class="kbt"><kbd>A</kbd>&ndash;<?php echo $has_option_e?'<kbd>E</kbd>':'<kbd>D</kbd>'; ?> Select</span>
                        <span class="kbt"><kbd>F</kbd> Flag</span>
                        <span class="kbt"><kbd>S</kbd> Submit</span>
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
                        $cls .= $i===0 ? ' current' : '';
                    ?>
                    <div class="q-cell <?php echo trim($cls); ?>"
                         id="qc_<?php echo $i; ?>"
                         onclick="goTo(<?php echo $i; ?>)"
                         title="Question <?php echo $i+1; ?>"><?php echo $i+1; ?></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <div class="sidebar-section-title">Legend</div>
                <div class="legend">
                    <div class="legend-item"><div class="legend-dot" style="background:var(--primary);"></div> Answered</div>
                    <div class="legend-item"><div class="legend-dot" style="background:var(--surface2); border:1.5px solid var(--border);"></div> Not answered</div>
                    <div class="legend-item"><div class="legend-dot" style="background:rgba(245,158,11,.2); border:1.5px solid var(--warning);"></div> Flagged for review</div>
                    <div class="legend-item"><div class="legend-dot" style="border:2px solid var(--secondary);"></div> Current question</div>
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
const TOTAL    = <?php echo $total_questions; ?>;
const SESSION  = <?php echo $session['id']; ?>;
const CIRCUMF  = <?php echo round(2*M_PI*14, 4); ?>;

let currentIdx = 0;
let timeLeft   = <?php echo $time_remaining; ?>;
let answered   = <?php echo json_encode(array_map(fn($v)=>strtoupper($v), $saved_answers)); // qid => letter ?>;
let flagged    = {};
let formSubmitted = false;

// ── Timer ───────────────────────────────────────────────────
const timerEl = document.getElementById('timerDisplay');
function tick() {
    if (timeLeft <= 0) { autoSubmit(); return; }
    timeLeft--;
    const h = String(Math.floor(timeLeft/3600)).padStart(2,'0');
    const m = String(Math.floor((timeLeft%3600)/60)).padStart(2,'0');
    const s = String(timeLeft%60).padStart(2,'0');
    timerEl.textContent = `${h}:${m}:${s}`;
    if (timeLeft <= 60)  timerEl.className = 'timer-display danger';
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
    document.querySelector('.question-pane').scrollTo({ top: 0, behavior: 'smooth' });
}

// ── Select option ───────────────────────────────────────────
function selectOption(li, qid, letter, idx) {
    // Deselect siblings
    li.closest('.options-list').querySelectorAll('.option-item').forEach(el => {
        el.classList.remove('selected');
        el.querySelector('input').checked = false;
    });
    li.classList.add('selected');
    li.querySelector('input').checked = true;

    answered[qid] = letter;

    // Mark grid cell answered
    const cell = document.getElementById('qc_' + idx);
    if (cell) cell.classList.add('answered');

    updateProgress();
    saveAnswer(qid, letter);
}

// ── Flag ────────────────────────────────────────────────────
function toggleFlag(idx) {
    flagged[idx] = !flagged[idx];
    const btn  = document.getElementById('flag_' + idx);
    const cell = document.getElementById('qc_' + idx);
    btn.classList.toggle('flagged', !!flagged[idx]);
    btn.innerHTML = flagged[idx]
        ? '<i class="fas fa-flag"></i> Flagged'
        : '<i class="fas fa-flag"></i> Flag';
    cell?.classList.toggle('flagged', !!flagged[idx]);
}

// ── Save to server ──────────────────────────────────────────
function saveAnswer(qid, letter) {
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `save_answer=1&session_id=${SESSION}&question_id=${qid}&answer=${letter}`
    });
}

// ── Progress ────────────────────────────────────────────────
function updateProgress() {
    const count = Object.keys(answered).length;
    const pct   = TOTAL > 0 ? Math.round((count / TOTAL) * 100) : 0;
    const offset = CIRCUMF * (1 - pct/100);

    document.getElementById('answeredCount').textContent = `${count}/${TOTAL}`;
    document.getElementById('progressPct').textContent   = `${pct}%`;
    document.getElementById('progressRing').style.strokeDashoffset = offset;
}

function updateUnanswered() {
    const ua = TOTAL - Object.keys(answered).length;
    const el = document.getElementById('unansweredWarn');
    const tx = document.getElementById('unansweredText');
    if (ua > 0) {
        tx.textContent = `${ua} question${ua>1?'s':''} unanswered`;
        el.style.display = 'flex';
    } else {
        el.style.display = 'none';
    }
}

// ── Modal ───────────────────────────────────────────────────
function openSubmitModal() {
    const ua = TOTAL - Object.keys(answered).length;
    const icon  = document.getElementById('modalIcon');
    const title = document.getElementById('modalTitle');
    const body  = document.getElementById('modalBody');

    if (ua > 0) {
        icon.textContent  = '⚠️';
        title.textContent = `${ua} Question${ua>1?'s':''} Unanswered`;
        body.textContent  = `You still have ${ua} unanswered question${ua>1?'s':''}. Once submitted, you cannot return. Are you sure you want to submit?`;
    } else {
        icon.textContent  = '🎉';
        title.textContent = 'Ready to Submit?';
        body.textContent  = 'You have answered all questions. Click Submit Now to finalize your exam.';
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
    // Don't hijack when typing in inputs
    if (['INPUT','TEXTAREA'].includes(document.activeElement.tagName)) return;

    const k = e.key.toLowerCase();
    if (k === 'n' || k === 'arrowright') { e.preventDefault(); goTo(currentIdx + 1); }
    else if (k === 'p' || k === 'arrowleft') { e.preventDefault(); goTo(currentIdx - 1); }
    else if (k === 'f') { e.preventDefault(); toggleFlag(currentIdx); }
    else if (k === 's') { e.preventDefault(); openSubmitModal(); }
    else if (['a','b','c','d','e'].includes(k)) {
        e.preventDefault();
        const slide = document.getElementById('qs_' + currentIdx);
        const li = slide?.querySelector(`.option-item[data-opt="${k.toUpperCase()}"]`);
        if (li) {
            const qid = parseInt(slide.dataset.qid);
            selectOption(li, qid, k.toUpperCase(), currentIdx);
        }
    }
});

// ── Prevent navigation away ─────────────────────────────────
window.addEventListener('beforeunload', e => {
    if (!formSubmitted) {
        e.preventDefault();
        e.returnValue = 'Your exam is still in progress. Are you sure you want to leave?';
    }
});

// Prevent back navigation
history.pushState(null, '', location.href);
window.addEventListener('popstate', () => {
    if (!formSubmitted) {
        history.pushState(null, '', location.href);
    }
});

// ── Init ────────────────────────────────────────────────────
goTo(0);
updateProgress();
updateUnanswered();
</script>
</body>
</html>
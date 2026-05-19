<?php
// admin/ai-tools.php - AI Teaching Tools
session_start();

// ── Auth check (same pattern as your index.php) ──────────────────────────────
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_username'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../includes/config.php';
require_once '../includes/auth.php';

$admin_name = $_SESSION['admin_name'] ?? 'Administrator';
$admin_role = $_SESSION['admin_role'] ?? 'super_admin';

// ── API Key ───────────────────────────────────────────────────────────────────
// IMPORTANT: Move this to your includes/config.php as a constant:
//   define('ANTHROPIC_API_KEY', 'sk-ant-xxxxxxxxxxxx');
// Then use:  $api_key = ANTHROPIC_API_KEY;
$api_key = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : 'sk-ant-YOUR-KEY-HERE';

// ── System Prompts (one per tool) ─────────────────────────────────────────────
$system_prompts = [

    'lesson' => "You are an expert Nigerian curriculum teacher and lesson plan writer.
When given a subject, topic, class level and duration, produce a complete lesson note in this format:

LESSON NOTE
Subject: [subject] | Topic: [topic] | Class: [level] | Duration: [duration]

BEHAVIOURAL OBJECTIVES:
By the end of this lesson, students should be able to:
1. [objective]
2. [objective]
3. [objective]

INSTRUCTIONAL MATERIALS:
- [list materials]

ENTRY BEHAVIOUR:
[Prior knowledge required]

INTRODUCTION / SET INDUCTION (5 mins):
[Engaging opening to capture attention]

PRESENTATION / DEVELOPMENT:

Step 1 — [First concept]
[Explanation]

Step 2 — [Second concept]
[Explanation]

Step 3 — [Third concept]
[Explanation]

WORKED EXAMPLES:
[2-3 clear examples]

CLASS ACTIVITY:
[In-class group or individual activity]

EVALUATION:
1. [question]
2. [question]
3. [question]

ASSIGNMENT:
[Take-home task]

CONCLUSION:
[Summary and transition to next lesson]

Follow Nigerian curriculum standards. Be thorough and age-appropriate.",

    'explain' => "You are a brilliant, warm Nigerian teacher who explains concepts simply for any age group.

Given a concept, subject, student level and preferred style, structure your response as:

CONCEPT: [concept name]
Level: [class level]

1. SIMPLE DEFINITION
One sentence a child could understand.

2. REAL-LIFE ANALOGY
Use everyday Nigerian/African contexts (market, farm, kitchen, football, etc.)

3. STEP-BY-STEP BREAKDOWN
Explain in 3-5 simple numbered steps.

4. VISUAL DESCRIPTION
Describe how to picture or sketch it mentally.

5. COMMON MISTAKES STUDENTS MAKE
What do students often misunderstand?

6. MEMORY TRICK
A mnemonic, rhyme, or shortcut to remember it.

7. COMPREHENSION CHECK (for the teacher)
Two quick questions to ask the class.

Adjust complexity to the student level. Be encouraging.",

    'questions' => "You are an experienced examination question setter following Nigerian WAEC/NECO/JAMB standards.

Given subject, topic, class level, number, type and difficulty, generate questions as follows:

Header line: [Subject] — [Topic] | [Level] | [Type] | [Difficulty]

For OBJECTIVE (MCQ):
1. [Question]
   A. [option]   B. [option]   C. [option]   D. [option]
   Answer: [letter] — [brief reason]

For THEORY:
1. [Question] ([marks])
   Model Answer: [detailed answer]

For MIXED: group objectives first, then theory, clearly labelled.

Rules:
- Cover a spread of subtopics within the given topic
- Match the difficulty requested
- Follow Nigerian curriculum scope and sequence
- Always include answers/model answers
- Number all questions clearly",

];

// ── Handle AJAX POST from the page's fetch() call ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === '1') {

    header('Content-Type: application/json');

    $tool    = $_POST['tool']    ?? '';
    $message = $_POST['message'] ?? '';

    if (!$tool || !$message || !isset($system_prompts[$tool])) {
        echo json_encode(['success' => false, 'error' => 'Invalid request.']);
        exit();
    }

    // ── Optional: log usage to your activity_logs table ──────────────────────
    try {
        $log_stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, user_type, action, description, created_at)
            VALUES (?, 'admin', 'ai_tool_used', ?, NOW())
        ");
        $log_stmt->execute([
            $_SESSION['admin_id'],
            "AI Tool: $tool | " . substr($message, 0, 100)
        ]);
    } catch (Exception $e) {
        // Non-fatal — continue even if logging fails
    }

    // ── Call Anthropic API ────────────────────────────────────────────────────
    $payload = json_encode([
        'model'      => 'claude-sonnet-4-20250514',
        'max_tokens' => 1500,
        'system'     => $system_prompts[$tool],
        'messages'   => [
            ['role' => 'user', 'content' => $message]
        ]
    ]);

    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        =>
            "Content-Type: application/json\r\n" .
                "x-api-key: $api_key\r\n" .
                "anthropic-version: 2023-06-01\r\n",
            'content'       => $payload,
            'timeout'       => 60,
            'ignore_errors' => true,
        ]
    ]);

    $raw = @file_get_contents('https://api.anthropic.com/v1/messages', false, $context);

    if ($raw === false) {
        echo json_encode(['success' => false, 'error' => 'Could not reach the AI server. Check your internet connection or API key.']);
        exit();
    }

    $data = json_decode($raw, true);

    if (isset($data['content'][0]['text'])) {
        echo json_encode(['success' => true, 'result' => $data['content'][0]['text']]);
    } else {
        $err = $data['error']['message'] ?? 'Unexpected API response.';
        echo json_encode(['success' => false, 'error' => $err]);
    }

    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>AI Teaching Tools - Digital CBT System</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* ── CSS variables match your existing portal exactly ──────────────── */
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --accent-color: #e74c3c;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --sidebar-width: 260px;
            --header-height: 70px;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, .08);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, .1);
            --shadow-lg: 0 8px 24px rgba(0, 0, 0, .12);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --transition: all 0.3s ease;

            /* AI tool accent colours */
            --ai-lesson: #27ae60;
            --ai-explain: #3498db;
            --ai-question: #9b59b6;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            color: #333;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* ── Mobile toggle (copy of your index.php) ────────────────────────── */
        .mobile-menu-toggle {
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            width: 44px;
            height: 44px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            cursor: pointer;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
        }

        .mobile-menu-toggle:hover {
            background: #1a252f;
            transform: scale(1.05);
        }

        /* ── Sidebar (copy of your index.php) ──────────────────────────────── */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-color), var(--dark-color));
            color: white;
            padding: 20px 0 0;
            transition: transform 0.3s cubic-bezier(.4, 0, .2, 1);
            z-index: 1000;
            box-shadow: 2px 0 15px rgba(0, 0, 0, .1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transform: translateX(-100%);
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, .1);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            width: 44px;
            height: 44px;
            background: var(--secondary-color);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .logo-text h3 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 2px;
            line-height: 1.2;
        }

        .logo-text p {
            font-size: .8rem;
            opacity: .8;
        }

        .admin-info {
            padding: 16px 20px;
            margin: 16px 20px;
            background: rgba(255, 255, 255, .1);
            border-radius: var(--radius-md);
            text-align: center;
            border: 1px solid rgba(255, 255, 255, .05);
        }

        .admin-info h4 {
            font-size: .95rem;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .admin-info p {
            font-size: .8rem;
            opacity: .8;
            text-transform: capitalize;
        }

        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 0 0 20px;
        }

        .sidebar-content::-webkit-scrollbar {
            width: 4px;
        }

        .sidebar-content::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, .2);
            border-radius: 10px;
        }

        .nav-links {
            list-style: none;
            padding: 0 16px;
        }

        .nav-links li {
            margin-bottom: 4px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 16px;
            color: rgba(255, 255, 255, .9);
            text-decoration: none;
            transition: var(--transition);
            border-radius: var(--radius-sm);
            border-left: 3px solid transparent;
            font-size: .95rem;
            font-weight: 500;
        }

        .nav-links a:hover {
            background: rgba(255, 255, 255, .1);
            color: white;
            border-left-color: var(--secondary-color);
            transform: translateX(4px);
        }

        .nav-links a.active {
            background: rgba(255, 255, 255, .15);
            color: white;
            border-left-color: var(--secondary-color);
            font-weight: 600;
        }

        .nav-links i {
            width: 20px;
            text-align: center;
            font-size: 18px;
        }

        /* ── Overlay ────────────────────────────────────────────────────────── */
        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .5);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
            backdrop-filter: blur(2px);
        }

        .sidebar-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        /* ── Main content ───────────────────────────────────────────────────── */
        .main-content {
            min-height: 100vh;
            padding: 80px 20px 20px;
            transition: var(--transition);
        }

        /* ── Top header (matches your portal style) ─────────────────────────── */
        .top-header {
            background: white;
            padding: 20px;
            border-radius: var(--radius-md);
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .header-title p {
            color: #666;
            font-size: .9rem;
        }

        .ai-badge {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: .8rem;
            font-weight: 600;
            letter-spacing: .5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* ── Disclaimer banner ──────────────────────────────────────────────── */
        .disclaimer {
            background: #fff8e1;
            border: 1px solid #f39c12;
            border-left: 4px solid #f39c12;
            border-radius: var(--radius-sm);
            padding: 12px 16px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: .85rem;
            color: #7d6608;
            line-height: 1.5;
        }

        .disclaimer i {
            color: #f39c12;
            margin-top: 2px;
            flex-shrink: 0;
        }

        /* ── Tool tabs ──────────────────────────────────────────────────────── */
        .tool-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            overflow-x: auto;
            padding-bottom: 4px;
        }

        .tool-tab {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: var(--radius-sm);
            border: 2px solid transparent;
            background: white;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            font-size: .9rem;
            font-weight: 500;
            color: #666;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            white-space: nowrap;
        }

        .tool-tab:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .tool-tab.active[data-tool="lesson"] {
            background: #e8f8f0;
            border-color: var(--ai-lesson);
            color: var(--ai-lesson);
        }

        .tool-tab.active[data-tool="explain"] {
            background: #eaf4fb;
            border-color: var(--ai-explain);
            color: var(--ai-explain);
        }

        .tool-tab.active[data-tool="question"] {
            background: #f5eef8;
            border-color: var(--ai-question);
            color: var(--ai-question);
        }

        /* ── Two-column workspace ───────────────────────────────────────────── */
        .workspace {
            display: grid;
            grid-template-columns: 1fr 1.5fr;
            gap: 20px;
            align-items: start;
        }

        /* ── Card (shared) ──────────────────────────────────────────────────── */
        .card {
            background: white;
            border-radius: var(--radius-md);
            padding: 24px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .card:hover {
            box-shadow: var(--shadow-md);
        }

        .card-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--light-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ── Form fields ────────────────────────────────────────────────────── */
        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: .85rem;
            font-weight: 600;
            color: #444;
            margin-bottom: 6px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid #dde1e7;
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: .9rem;
            color: #333;
            background: #fafbfc;
            transition: var(--transition);
            outline: none;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--secondary-color);
            background: white;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, .1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        /* ── Generate button ────────────────────────────────────────────────── */
        .btn-generate {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 8px;
        }

        .btn-generate:disabled {
            background: #ccc !important;
            color: #888;
            cursor: not-allowed;
        }

        .btn-lesson {
            background: var(--ai-lesson);
            color: white;
        }

        .btn-explain {
            background: var(--ai-explain);
            color: white;
        }

        .btn-question {
            background: var(--ai-question);
            color: white;
        }

        .btn-generate:not(:disabled):hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* ── Output panel ───────────────────────────────────────────────────── */
        .output-panel {
            display: flex;
            flex-direction: column;
            gap: 0;
        }

        .output-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }

        .output-label {
            font-size: .8rem;
            font-weight: 600;
            color: #888;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .output-actions {
            display: flex;
            gap: 8px;
        }

        .btn-action {
            padding: 7px 14px;
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: .8rem;
            font-weight: 500;
            cursor: pointer;
            border: 1.5px solid #dde1e7;
            background: white;
            color: #555;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .btn-action:hover {
            border-color: var(--secondary-color);
            color: var(--secondary-color);
        }

        .btn-action.copied {
            border-color: var(--success-color);
            color: var(--success-color);
            background: #e8f8f0;
        }

        .output-box {
            background: #f8fafc;
            border: 1.5px solid #dde1e7;
            border-radius: var(--radius-sm);
            padding: 20px;
            min-height: 400px;
            font-size: .9rem;
            line-height: 1.85;
            color: #2c3e50;
            white-space: pre-wrap;
            overflow-y: auto;
            max-height: 70vh;
        }

        .output-box.empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #bdc3c7;
            gap: 12px;
        }

        .output-box.empty i {
            font-size: 3rem;
        }

        .output-box.empty p {
            font-size: .9rem;
            text-align: center;
            line-height: 1.6;
        }

        /* Loading spinner */
        .spinner {
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 16px;
            min-height: 400px;
            color: #888;
            font-size: .9rem;
        }

        .spinner.active {
            display: flex;
        }

        .spin-ring {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: 4px solid #ecf0f1;
            border-top-color: var(--secondary-color);
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* ── Footer ─────────────────────────────────────────────────────────── */
        .dashboard-footer {
            text-align: center;
            padding: 20px;
            color: #888;
            font-size: .85rem;
            margin-top: 24px;
        }

        /* ── Responsive ─────────────────────────────────────────────────────── */
        @media (max-width:767px) {
            .workspace {
                grid-template-columns: 1fr;
            }

            .tool-tabs {
                flex-wrap: nowrap;
            }

            .output-box {
                max-height: 50vh;
            }
        }

        @supports (padding: max(0px)) {
            .main-content {
                padding-left: max(16px, env(safe-area-inset-left));
                padding-right: max(16px, env(safe-area-inset-right));
                padding-top: max(80px, calc(env(safe-area-inset-top) + 60px));
            }
        }

        @media print {

            .sidebar,
            .mobile-menu-toggle,
            .tool-tabs,
            .card:first-child,
            .output-toolbar,
            .disclaimer {
                display: none !important;
            }

            .workspace {
                grid-template-columns: 1fr;
            }

            .output-box {
                border: none;
                max-height: none;
            }
        }
    </style>
</head>

<body>

    <!-- Mobile Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle menu">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
                <div class="logo-text">
                    <h3><?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'The Climax Brains Academy'; ?></h3>
                    <p>Admin Panel</p>
                </div>
            </div>
        </div>

        <div class="admin-info">
            <h4><?php echo htmlspecialchars($admin_name); ?></h4>
            <p><?php echo ucfirst(str_replace('_', ' ', $admin_role)); ?></p>
        </div>

        <div class="sidebar-content">
            <ul class="nav-links">
                <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="manage-students.php"><i class="fas fa-users"></i> Manage Students</a></li>
                <li><a href="manage-staff.php"><i class="fas fa-chalkboard-teacher"></i> Manage Staff</a></li>
                <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Manage Subjects</a></li>
                <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
                <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
                <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
                <li><a href="report_card_dashboard.php"><i class="fas fa-cog"></i> Process Result</a></li>
                <!-- ✅ NEW AI TOOLS LINK -->
                <li><a href="ai-tools.php" class="active"><i class="fas fa-robot"></i> AI Teaching Tools</a></li>
                <li><a href="updater.php"><i class="fas fa-sync-alt"></i> System Update</a></li>
                <li><a href="db_update.php"><i class="fas fa-database"></i> Database Update</a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">

        <!-- Top Header -->
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-robot" style="color:var(--secondary-color);margin-right:8px;"></i>AI Teaching Tools</h1>
                <p>Generate lesson notes, explain concepts and create exam questions instantly</p>
            </div>
            <div class="ai-badge"><i class="fas fa-sparkles"></i> Powered by AI</div>
        </div>

        <!-- Disclaimer -->
        <div class="disclaimer">
            <i class="fas fa-exclamation-triangle"></i>
            <span><strong>Note:</strong> All AI-generated content is a <em>draft</em>. Please review for accuracy before use in class or examinations. The AI follows Nigerian curriculum standards but teacher judgment is always final.</span>
        </div>

        <!-- Tool Tabs -->
        <div class="tool-tabs">
            <button class="tool-tab active" data-tool="lesson" onclick="switchTool('lesson')">
                <i class="fas fa-book-open"></i> Lesson Note Drafter
            </button>
            <button class="tool-tab" data-tool="explain" onclick="switchTool('explain')">
                <i class="fas fa-lightbulb"></i> Concept Explainer
            </button>
            <button class="tool-tab" data-tool="question" onclick="switchTool('question')">
                <i class="fas fa-question-circle"></i> Question Generator
            </button>
        </div>

        <!-- Workspace -->
        <div class="workspace">

            <!-- LEFT: Input Form -->
            <div class="card">

                <!-- LESSON NOTE FORM -->
                <div id="form-lesson">
                    <div class="card-title"><i class="fas fa-book-open" style="color:var(--ai-lesson);"></i> Lesson Note Details</div>
                    <div class="form-group">
                        <label>Subject</label>
                        <input type="text" id="l-subject" placeholder="e.g. Mathematics, Biology, Economics...">
                    </div>
                    <div class="form-group">
                        <label>Topic</label>
                        <input type="text" id="l-topic" placeholder="e.g. Quadratic Equations, Photosynthesis...">
                    </div>
                    <div class="form-group">
                        <label>Class / Level</label>
                        <input type="text" id="l-level" placeholder="e.g. JSS 2, SS 3, Primary 5...">
                    </div>
                    <div class="form-group">
                        <label>Lesson Duration</label>
                        <input type="text" id="l-duration" placeholder="e.g. 40 minutes, 1 hour...">
                    </div>
                    <button class="btn-generate btn-lesson" onclick="generate('lesson')">
                        <i class="fas fa-magic"></i> Generate Lesson Note
                    </button>
                </div>

                <!-- CONCEPT EXPLAINER FORM -->
                <div id="form-explain" style="display:none;">
                    <div class="card-title"><i class="fas fa-lightbulb" style="color:var(--ai-explain);"></i> Concept Details</div>
                    <div class="form-group">
                        <label>Concept to Explain</label>
                        <input type="text" id="e-concept" placeholder="e.g. Osmosis, Democracy, Newton's Laws...">
                    </div>
                    <div class="form-group">
                        <label>Subject Area</label>
                        <input type="text" id="e-subject" placeholder="e.g. Biology, Civic Education, Physics...">
                    </div>
                    <div class="form-group">
                        <label>Student Level</label>
                        <input type="text" id="e-level" placeholder="e.g. JSS 1, SS 2, Primary 4...">
                    </div>
                    <div class="form-group">
                        <label>Preferred Style</label>
                        <select id="e-style">
                            <option value="Simple analogy and real-life examples">Simple analogy & real-life examples</option>
                            <option value="Story-based narrative">Story-based narrative</option>
                            <option value="Step-by-step with diagrams described">Step-by-step with diagrams described</option>
                            <option value="Question and answer format">Question & answer format</option>
                        </select>
                    </div>
                    <button class="btn-generate btn-explain" onclick="generate('explain')">
                        <i class="fas fa-magic"></i> Explain Concept
                    </button>
                </div>

                <!-- QUESTION GENERATOR FORM -->
                <div id="form-question" style="display:none;">
                    <div class="card-title"><i class="fas fa-question-circle" style="color:var(--ai-question);"></i> Exam / Test Details</div>
                    <div class="form-group">
                        <label>Subject</label>
                        <input type="text" id="q-subject" placeholder="e.g. Chemistry, English Language...">
                    </div>
                    <div class="form-group">
                        <label>Topic</label>
                        <input type="text" id="q-topic" placeholder="e.g. Acids & Bases, Essay Writing...">
                    </div>
                    <div class="form-group">
                        <label>Class / Level</label>
                        <input type="text" id="q-level" placeholder="e.g. SS 3, JSS 2...">
                    </div>
                    <div class="form-group">
                        <label>Number of Questions</label>
                        <select id="q-count">
                            <option value="5">5 questions</option>
                            <option value="10" selected>10 questions</option>
                            <option value="20">20 questions</option>
                            <option value="30">30 questions</option>
                            <option value="40">40 questions</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Question Type</label>
                        <select id="q-type">
                            <option value="Objectives (MCQ with 4 options A B C D)">Objectives (MCQ)</option>
                            <option value="Theory (with model answers)">Theory</option>
                            <option value="Mixed (Objectives then Theory)">Mixed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Difficulty Level</label>
                        <select id="q-difficulty">
                            <option value="Easy">Easy</option>
                            <option value="Medium" selected>Medium</option>
                            <option value="Hard">Hard</option>
                            <option value="Mixed (Easy, Medium and Hard)">Mixed</option>
                        </select>
                    </div>
                    <button class="btn-generate btn-question" onclick="generate('question')">
                        <i class="fas fa-magic"></i> Generate Questions
                    </button>
                </div>

            </div><!-- /card -->

            <!-- RIGHT: Output -->
            <div class="card output-panel">

                <div class="output-toolbar">
                    <span class="output-label"><i class="fas fa-file-alt"></i> &nbsp;AI Output</span>
                    <div class="output-actions">
                        <button class="btn-action" id="btnCopy" onclick="copyOutput()" style="display:none;">
                            <i class="fas fa-copy"></i> Copy
                        </button>
                        <button class="btn-action" onclick="printOutput()" id="btnPrint" style="display:none;">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </div>

                <!-- Spinner -->
                <div class="spinner" id="spinner">
                    <div class="spin-ring"></div>
                    <span>AI is preparing your content&hellip;</span>
                </div>

                <!-- Output Box -->
                <div class="output-box empty" id="outputBox">
                    <i class="fas fa-robot"></i>
                    <p>Select a tool, fill in the details<br>and click <strong>Generate</strong><br><br>
                        Your content will appear here.</p>
                </div>

            </div><!-- /card -->
        </div><!-- /workspace -->

        <div class="dashboard-footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'The Climax Brains Academy'; ?> &mdash; Digital CBT System</p>
            <p style="margin-top:5px;font-size:.8rem;color:#aaa;">AI Teaching Tools &mdash; Powered by Claude AI</p>
        </div>

    </div><!-- /main-content -->

    <script>
        // ── Sidebar (identical to your index.php) ─────────────────────────────────
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function toggleSidebar() {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        }

        function closeSidebar() {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
            document.body.style.overflow = '';
        }
        mobileMenuToggle.addEventListener('click', toggleSidebar);
        sidebarOverlay.addEventListener('click', closeSidebar);
        document.querySelectorAll('.nav-links a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 767) closeSidebar();
            });
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeSidebar();
        });

        // ── Tool switcher ─────────────────────────────────────────────────────────
        let currentTool = 'lesson';

        function switchTool(tool) {
            currentTool = tool;
            document.querySelectorAll('.tool-tab').forEach(t => t.classList.remove('active'));
            document.querySelector(`.tool-tab[data-tool="${tool}"]`).classList.add('active');

            ['lesson', 'explain', 'question'].forEach(t => {
                document.getElementById(`form-${t}`).style.display = t === tool ? 'block' : 'none';
            });

            // Reset output
            resetOutput();
        }

        function resetOutput() {
            const box = document.getElementById('outputBox');
            box.className = 'output-box empty';
            box.innerHTML = '<i class="fas fa-robot"></i><p>Select a tool, fill in the details<br>and click <strong>Generate</strong><br><br>Your content will appear here.</p>';
            document.getElementById('btnCopy').style.display = 'none';
            document.getElementById('btnPrint').style.display = 'none';
            document.getElementById('spinner').classList.remove('active');
        }

        // ── Build user message from form ──────────────────────────────────────────
        function buildMessage(tool) {
            if (tool === 'lesson') {
                const s = v('l-subject'),
                    t = v('l-topic'),
                    l = v('l-level'),
                    d = v('l-duration');
                if (!s || !t || !l || !d) return null;
                return `Subject: ${s}\nTopic: ${t}\nClass Level: ${l}\nDuration: ${d}`;
            }
            if (tool === 'explain') {
                const c = v('e-concept'),
                    s = v('e-subject'),
                    l = v('e-level'),
                    st = v('e-style');
                if (!c || !s || !l) return null;
                return `Concept: ${c}\nSubject: ${s}\nStudent Level: ${l}\nExplanation Style: ${st}`;
            }
            if (tool === 'question') {
                const s = v('q-subject'),
                    t = v('q-topic'),
                    l = v('q-level');
                const n = v('q-count'),
                    tp = v('q-type'),
                    d = v('q-difficulty');
                if (!s || !t || !l) return null;
                return `Subject: ${s}\nTopic: ${t}\nClass Level: ${l}\nNumber of Questions: ${n}\nQuestion Type: ${tp}\nDifficulty: ${d}`;
            }
        }

        function v(id) {
            return document.getElementById(id)?.value?.trim() || '';
        }

        // ── Generate ──────────────────────────────────────────────────────────────
        async function generate(tool) {
            const message = buildMessage(tool);
            if (!message) {
                alert('Please fill in all required fields before generating.');
                return;
            }

            // Show spinner
            const spinner = document.getElementById('spinner');
            const box = document.getElementById('outputBox');
            spinner.classList.add('active');
            box.style.display = 'none';
            document.getElementById('btnCopy').style.display = 'none';
            document.getElementById('btnPrint').style.display = 'none';

            try {
                const formData = new FormData();
                formData.append('ajax', '1');
                formData.append('tool', tool);
                formData.append('message', message);

                const resp = await fetch('ai-tools.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await resp.json();

                spinner.classList.remove('active');
                box.style.display = 'block';

                if (data.success) {
                    box.className = 'output-box';
                    box.textContent = data.result;
                    document.getElementById('btnCopy').style.display = 'flex';
                    document.getElementById('btnPrint').style.display = 'flex';
                } else {
                    box.className = 'output-box empty';
                    box.innerHTML = `<i class="fas fa-exclamation-triangle" style="color:#e74c3c;"></i>
                    <p style="color:#e74c3c;"><strong>Error:</strong><br>${data.error}</p>`;
                }
            } catch (err) {
                document.getElementById('spinner').classList.remove('active');
                box.style.display = 'block';
                box.className = 'output-box empty';
                box.innerHTML = `<i class="fas fa-exclamation-triangle" style="color:#e74c3c;"></i>
                <p style="color:#e74c3c;"><strong>Network Error:</strong><br>${err.message}</p>`;
            }
        }

        // ── Copy & Print ──────────────────────────────────────────────────────────
        function copyOutput() {
            const text = document.getElementById('outputBox').textContent;
            navigator.clipboard.writeText(text).then(() => {
                const btn = document.getElementById('btnCopy');
                btn.classList.add('copied');
                btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                setTimeout(() => {
                    btn.classList.remove('copied');
                    btn.innerHTML = '<i class="fas fa-copy"></i> Copy';
                }, 2500);
            });
        }

        function printOutput() {
            window.print();
        }

        // Touch feedback (matches your index.php)
        document.querySelectorAll('.btn-generate, .btn-action, .tool-tab').forEach(el => {
            el.addEventListener('touchstart', () => el.style.opacity = '.8');
            el.addEventListener('touchend', () => el.style.opacity = '1');
        });
    </script>
</body>

</html>
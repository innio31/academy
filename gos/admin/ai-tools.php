<?php
// admin/ai-tools.php - AI Teaching Tools with Gemini API
session_start();

// ── Debug logging (remove in production) ──────────────────────────────────────
error_log("AI Tools - Session check: " . print_r($_SESSION, true));

// ── Auth check with better error handling ─────────────────────────────────────
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_username'])) {
    error_log("AI Tools - Auth failed. Session data: " . print_r($_SESSION, true));
    header("Location: ../login.php?error=session_expired");
    exit();
}

require_once '../includes/config.php';
require_once '../includes/auth.php';

// Verify the config file loaded correctly
if (!isset($pdo)) {
    error_log("AI Tools - Database connection not established");
    // Don't logout, just continue without DB logging
}

$admin_name = $_SESSION['admin_name'] ?? 'Administrator';
$admin_role = $_SESSION['admin_role'] ?? 'super_admin';

// ── AI Configuration ─────────────────────────────────────────────────────────
// Options: 'gemini', 'mock' (for testing without API)
$ai_provider = 'mock';  // Start with mock mode for testing
$use_mock = true;       // Set to false when you have API key

// Google Gemini API Key - Get from https://makersuite.google.com/app/apikey
$gemini_api_key = 'YOUR_GEMINI_API_KEY_HERE'; // Replace with your actual key

// Only use Gemini if we have a valid API key and mock mode is off
if (!$use_mock && $gemini_api_key !== 'YOUR_GEMINI_API_KEY_HERE') {
    $ai_provider = 'gemini';
}

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

// ── Mock responses for testing without API ────────────────────────────────────
function getMockResponse($tool, $message)
{
    $mock_responses = [
        'lesson' => "LESSON NOTE
Subject: Sample Subject | Topic: Sample Topic | Class: Sample Class | Duration: 40 minutes

BEHAVIOURAL OBJECTIVES:
By the end of this lesson, students should be able to:
1. Understand the key concepts
2. Apply knowledge to real situations
3. Analyze related problems

INSTRUCTIONAL MATERIALS:
- Whiteboard and markers
- Handouts
- Visual aids

ENTRY BEHAVIOUR:
Students have basic understanding of prerequisite concepts.

INTRODUCTION / SET INDUCTION (5 mins):
Engaging question to capture attention and relate to prior knowledge.

PRESENTATION / DEVELOPMENT:

Step 1 — Introduction to Concept
Clear explanation of the main concept.

Step 2 — Key Principles
Breakdown of important principles with examples.

Step 3 — Practical Application
How this applies in real-world Nigerian context.

WORKED EXAMPLES:
1. Example one with step-by-step solution
2. Example two showing different application

CLASS ACTIVITY:
Group work or individual exercise to practice the concept.

EVALUATION:
1. Question to check understanding
2. Application-based question
3. Analysis question

ASSIGNMENT:
Take-home task to reinforce learning.

CONCLUSION:
Summary and preview of next lesson.

[📝 MOCK MODE: This is a sample response. To get real AI-generated content, get a free Gemini API key from https://makersuite.google.com/app/apikey and update the configuration.]",

        'explain' => "CONCEPT: Sample Concept
Level: Sample Level

1. SIMPLE DEFINITION
A clear, child-friendly explanation of the concept.

2. REAL-LIFE ANALOGY
A relatable Nigerian context example (market, home, school, etc.)

3. STEP-BY-STEP BREAKDOWN
Step 1: First thing to understand
Step 2: Second key point
Step 3: How it all connects

4. VISUAL DESCRIPTION
Description of how to picture or draw this concept.

5. COMMON MISTAKES STUDENTS MAKE
Typical misunderstandings and how to avoid them.

6. MEMORY TRICK
Easy way to remember the key points.

7. COMPREHENSION CHECK
Two questions teachers can ask students.

[📝 MOCK MODE: This is a sample response. To get real AI-generated content, get a free Gemini API key from https://makersuite.google.com/app/apikey and update the configuration.]",

        'questions' => "SUBJECT — TOPIC | LEVEL | MIXED | MEDIUM

OBJECTIVE QUESTIONS:

1. What is the capital of Nigeria?
   A. Lagos   B. Abuja   C. Kano   D. Ibadan
   Answer: B — Abuja is the federal capital territory

2. Which of the following is a primary color?
   A. Green   B. Purple   C. Red   D. Orange
   Answer: C — Red, blue, and yellow are primary colors

THEORY QUESTIONS:

1. Define the term and give two examples. (5 marks)
   Model Answer: A clear definition with relevant examples.

2. Explain the importance of this concept in daily life. (5 marks)
   Model Answer: Practical applications and real-world significance.

[📝 MOCK MODE: This is a sample response. To get real AI-generated content, get a free Gemini API key from https://makersuite.google.com/app/apikey and update the configuration.]"
    ];

    return $mock_responses[$tool] ?? "Mock response for testing. Please fill in all required fields.\n\n[📝 MOCK MODE: This is a sample response.]";
}

// ── Function to call Google Gemini API ────────────────────────────────────────
function callGeminiAPI($system_prompt, $user_message, $api_key)
{
    // Prepare the full prompt
    $full_prompt = $system_prompt . "\n\nUser Request:\n" . $user_message;

    $payload = json_encode([
        'contents' => [
            [
                'parts' => [
                    ['text' => $full_prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 2000,
            'topP' => 0.95,
            'topK' => 40
        ]
    ]);

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key=" . $api_key;

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 60,
            'ignore_errors' => true
        ]
    ];

    $context = stream_context_create($options);
    $raw = @file_get_contents($url, false, $context);

    if ($raw === false) {
        return ['success' => false, 'error' => 'Could not reach Google Gemini API. Check your internet connection.'];
    }

    $data = json_decode($raw, true);

    // Check for errors
    if (isset($data['error'])) {
        $error_msg = $data['error']['message'] ?? 'Unknown API error';
        return ['success' => false, 'error' => 'Gemini API Error: ' . $error_msg];
    }

    // Extract response
    if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        return ['success' => true, 'result' => $data['candidates'][0]['content']['parts'][0]['text']];
    } else {
        return ['success' => false, 'error' => 'Unexpected response format from Gemini API.'];
    }
}

// ── Handle AJAX POST ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === '1') {

    header('Content-Type: application/json');

    $tool    = $_POST['tool']    ?? '';
    $message = $_POST['message'] ?? '';

    if (!$tool || !$message || !isset($system_prompts[$tool])) {
        echo json_encode(['success' => false, 'error' => 'Invalid request. Please fill in all fields.']);
        exit();
    }

    // ── Log usage (only if PDO is available) ──────────────────────────────────
    if (isset($pdo)) {
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
            // Non-fatal - log error but continue
            error_log("Failed to log AI usage: " . $e->getMessage());
        }
    }

    // ── Use mock mode or real API ─────────────────────────────────────────────
    global $use_mock, $ai_provider, $gemini_api_key;

    if ($use_mock) {
        // Return mock response
        $mock_result = getMockResponse($tool, $message);
        echo json_encode(['success' => true, 'result' => $mock_result]);
        exit();
    } elseif ($ai_provider === 'gemini' && $gemini_api_key !== 'YOUR_GEMINI_API_KEY_HERE') {
        $response = callGeminiAPI($system_prompts[$tool], $message, $gemini_api_key);
        echo json_encode($response);
        exit();
    } else {
        echo json_encode(['success' => false, 'error' => 'No AI provider configured. Please set up Gemini API key or enable mock mode.']);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>AI Teaching Tools - Digital CBT System</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* ── CSS variables ──────────────────────────────────────────────────── */
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
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, .08);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, .1);
            --radius-sm: 8px;
            --radius-md: 12px;
            --transition: all 0.3s ease;
            --ai-lesson: #27ae60;
            --ai-explain: #3498db;
            --ai-question: #9b59b6;
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
        }

        /* Mobile Toggle */
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
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            cursor: pointer;
            box-shadow: var(--shadow-md);
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
            padding: 20px 0 0;
            z-index: 1000;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
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
        }

        .logo-text h3 {
            font-size: 1rem;
            font-weight: 600;
        }

        .logo-text p {
            font-size: .75rem;
            opacity: .8;
        }

        .admin-info {
            padding: 16px 20px;
            margin: 16px 20px;
            background: rgba(255, 255, 255, .1);
            border-radius: var(--radius-md);
            text-align: center;
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
            padding: 12px 16px;
            color: rgba(255, 255, 255, .9);
            text-decoration: none;
            border-radius: var(--radius-sm);
            transition: var(--transition);
        }

        .nav-links a:hover {
            background: rgba(255, 255, 255, .1);
        }

        .nav-links a.active {
            background: rgba(255, 255, 255, .15);
            font-weight: 600;
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            padding: 20px;
        }

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
        }

        .ai-badge {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: .8rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .status-indicator {
            background: #fff8e1;
            border: 1px solid #f39c12;
            border-radius: var(--radius-sm);
            padding: 4px 10px;
            font-size: .7rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-left: 12px;
            color: #7d6608;
        }

        .disclaimer {
            background: #fff8e1;
            border-left: 4px solid #f39c12;
            border-radius: var(--radius-sm);
            padding: 12px 16px;
            margin-bottom: 24px;
            font-size: .85rem;
            color: #7d6608;
        }

        .tool-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .tool-tab {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: var(--radius-sm);
            background: white;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            font-size: .9rem;
            font-weight: 500;
            color: #666;
            box-shadow: var(--shadow-sm);
            border: 2px solid transparent;
            transition: var(--transition);
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

        .workspace {
            display: grid;
            grid-template-columns: 1fr 1.5fr;
            gap: 20px;
        }

        .card {
            background: white;
            border-radius: var(--radius-md);
            padding: 24px;
            box-shadow: var(--shadow-sm);
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
            padding: 10px 12px;
            border: 1.5px solid #dde1e7;
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: .9rem;
            outline: none;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: var(--secondary-color);
        }

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

        .output-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }

        .btn-action {
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            font-size: .8rem;
            cursor: pointer;
            border: 1.5px solid #dde1e7;
            background: white;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-action:hover {
            border-color: var(--secondary-color);
            color: var(--secondary-color);
        }

        .output-box {
            background: #f8fafc;
            border: 1.5px solid #dde1e7;
            border-radius: var(--radius-sm);
            padding: 20px;
            min-height: 400px;
            font-size: .9rem;
            line-height: 1.6;
            white-space: pre-wrap;
            overflow-y: auto;
            max-height: 60vh;
        }

        .output-box.empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #bdc3c7;
            text-align: center;
        }

        .spinner {
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 16px;
            min-height: 400px;
        }

        .spinner.active {
            display: flex;
        }

        .spin-ring {
            width: 48px;
            height: 48px;
            border: 4px solid #ecf0f1;
            border-top-color: var(--secondary-color);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .dashboard-footer {
            text-align: center;
            padding: 20px;
            color: #888;
            font-size: .8rem;
            margin-top: 24px;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .mobile-menu-toggle {
                display: flex;
            }

            .main-content {
                margin-left: 0;
                padding-top: 70px;
            }

            .workspace {
                grid-template-columns: 1fr;
            }
        }

        @media print {

            .sidebar,
            .mobile-menu-toggle,
            .tool-tabs,
            .card:first-child,
            .output-toolbar {
                display: none;
            }

            .main-content {
                margin-left: 0;
                padding: 0;
            }
        }
    </style>
</head>

<body>

    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>

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
                <li><a href="ai-tools.php" class="active"><i class="fas fa-robot"></i> AI Teaching Tools</a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-robot" style="color:var(--secondary-color);margin-right:8px;"></i>AI Teaching Tools
                    <span class="status-indicator">
                        <i class="fas fa-flask"></i> MOCK MODE (Testing)
                    </span>
                </h1>
                <p>Generate lesson notes, explain concepts and create exam questions instantly</p>
            </div>
            <div class="ai-badge"><i class="fas fa-sparkles"></i> Free AI Tools</div>
        </div>

        <div class="disclaimer">
            <i class="fas fa-info-circle"></i>
            <strong>Testing Mode Active:</strong> Currently using mock responses. To use real AI, get a free Gemini API key from <a href="https://makersuite.google.com/app/apikey" target="_blank">Google AI Studio</a> and update the configuration.
        </div>

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

        <div class="workspace">
            <div class="card">
                <div id="form-lesson">
                    <div class="card-title"><i class="fas fa-book-open" style="color:var(--ai-lesson);"></i> Lesson Note Details</div>
                    <div class="form-group">
                        <label>Subject *</label>
                        <input type="text" id="l-subject" placeholder="e.g. Mathematics, Biology, Economics">
                    </div>
                    <div class="form-group">
                        <label>Topic *</label>
                        <input type="text" id="l-topic" placeholder="e.g. Quadratic Equations, Photosynthesis">
                    </div>
                    <div class="form-group">
                        <label>Class / Level *</label>
                        <input type="text" id="l-level" placeholder="e.g. JSS 2, SS 3, Primary 5">
                    </div>
                    <div class="form-group">
                        <label>Lesson Duration *</label>
                        <input type="text" id="l-duration" placeholder="e.g. 40 minutes, 1 hour">
                    </div>
                    <button class="btn-generate btn-lesson" onclick="generate('lesson')">
                        <i class="fas fa-magic"></i> Generate Lesson Note
                    </button>
                </div>

                <div id="form-explain" style="display:none;">
                    <div class="card-title"><i class="fas fa-lightbulb" style="color:var(--ai-explain);"></i> Concept Details</div>
                    <div class="form-group">
                        <label>Concept to Explain *</label>
                        <input type="text" id="e-concept" placeholder="e.g. Osmosis, Democracy, Newton's Laws">
                    </div>
                    <div class="form-group">
                        <label>Subject Area *</label>
                        <input type="text" id="e-subject" placeholder="e.g. Biology, Civic Education, Physics">
                    </div>
                    <div class="form-group">
                        <label>Student Level *</label>
                        <input type="text" id="e-level" placeholder="e.g. JSS 1, SS 2, Primary 4">
                    </div>
                    <div class="form-group">
                        <label>Preferred Style</label>
                        <select id="e-style">
                            <option value="Simple analogy and real-life examples">Simple analogy & real-life examples</option>
                            <option value="Story-based narrative">Story-based narrative</option>
                            <option value="Step-by-step with diagrams described">Step-by-step with diagrams described</option>
                        </select>
                    </div>
                    <button class="btn-generate btn-explain" onclick="generate('explain')">
                        <i class="fas fa-magic"></i> Explain Concept
                    </button>
                </div>

                <div id="form-question" style="display:none;">
                    <div class="card-title"><i class="fas fa-question-circle" style="color:var(--ai-question);"></i> Exam Details</div>
                    <div class="form-group">
                        <label>Subject *</label>
                        <input type="text" id="q-subject" placeholder="e.g. Chemistry, English Language">
                    </div>
                    <div class="form-group">
                        <label>Topic *</label>
                        <input type="text" id="q-topic" placeholder="e.g. Acids & Bases, Essay Writing">
                    </div>
                    <div class="form-group">
                        <label>Class / Level *</label>
                        <input type="text" id="q-level" placeholder="e.g. SS 3, JSS 2">
                    </div>
                    <div class="form-group">
                        <label>Number of Questions</label>
                        <select id="q-count">
                            <option value="5">5 questions</option>
                            <option value="10" selected>10 questions</option>
                            <option value="20">20 questions</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Question Type</label>
                        <select id="q-type">
                            <option value="Objectives (MCQ)">Objectives (MCQ)</option>
                            <option value="Theory">Theory</option>
                            <option value="Mixed">Mixed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Difficulty</label>
                        <select id="q-difficulty">
                            <option value="Easy">Easy</option>
                            <option value="Medium" selected>Medium</option>
                            <option value="Hard">Hard</option>
                        </select>
                    </div>
                    <button class="btn-generate btn-question" onclick="generate('question')">
                        <i class="fas fa-magic"></i> Generate Questions
                    </button>
                </div>
            </div>

            <div class="card">
                <div class="output-toolbar">
                    <span><i class="fas fa-file-alt"></i> AI Output</span>
                    <div>
                        <button class="btn-action" id="btnCopy" onclick="copyOutput()" style="display:none;">
                            <i class="fas fa-copy"></i> Copy
                        </button>
                        <button class="btn-action" onclick="printOutput()" id="btnPrint" style="display:none;">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </div>

                <div class="spinner" id="spinner">
                    <div class="spin-ring"></div>
                    <span>Generating content...</span>
                </div>

                <div class="output-box empty" id="outputBox">
                    <i class="fas fa-robot" style="font-size: 3rem;"></i>
                    <p>Select a tool, fill in the details<br>and click <strong>Generate</strong></p>
                </div>
            </div>
        </div>

        <div class="dashboard-footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'The Climax Brains Academy'; ?> - AI Teaching Tools (Mock Mode)</p>
        </div>
    </div>

    <script>
        // Mobile menu
        const toggleBtn = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');

        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('active');
        });

        function switchTool(tool) {
            document.querySelectorAll('.tool-tab').forEach(t => t.classList.remove('active'));
            document.querySelector(`.tool-tab[data-tool="${tool}"]`).classList.add('active');

            document.getElementById('form-lesson').style.display = tool === 'lesson' ? 'block' : 'none';
            document.getElementById('form-explain').style.display = tool === 'explain' ? 'block' : 'none';
            document.getElementById('form-question').style.display = tool === 'question' ? 'block' : 'none';

            resetOutput();
        }

        function resetOutput() {
            const box = document.getElementById('outputBox');
            box.className = 'output-box empty';
            box.innerHTML = '<i class="fas fa-robot" style="font-size: 3rem;"></i><p>Select a tool, fill in the details<br>and click <strong>Generate</strong></p>';
            document.getElementById('btnCopy').style.display = 'none';
            document.getElementById('btnPrint').style.display = 'none';
        }

        function buildMessage(tool) {
            if (tool === 'lesson') {
                const s = document.getElementById('l-subject')?.value.trim();
                const t = document.getElementById('l-topic')?.value.trim();
                const l = document.getElementById('l-level')?.value.trim();
                const d = document.getElementById('l-duration')?.value.trim();
                if (!s || !t || !l || !d) return null;
                return `Subject: ${s}\nTopic: ${t}\nClass Level: ${l}\nDuration: ${d}`;
            }
            if (tool === 'explain') {
                const c = document.getElementById('e-concept')?.value.trim();
                const s = document.getElementById('e-subject')?.value.trim();
                const l = document.getElementById('e-level')?.value.trim();
                const st = document.getElementById('e-style')?.value;
                if (!c || !s || !l) return null;
                return `Concept: ${c}\nSubject: ${s}\nStudent Level: ${l}\nStyle: ${st}`;
            }
            if (tool === 'question') {
                const s = document.getElementById('q-subject')?.value.trim();
                const t = document.getElementById('q-topic')?.value.trim();
                const l = document.getElementById('q-level')?.value.trim();
                if (!s || !t || !l) return null;
                const n = document.getElementById('q-count')?.value;
                const tp = document.getElementById('q-type')?.value;
                const d = document.getElementById('q-difficulty')?.value;
                return `Subject: ${s}\nTopic: ${t}\nClass: ${l}\nQuestions: ${n}\nType: ${tp}\nDifficulty: ${d}`;
            }
            return null;
        }

        async function generate(tool) {
            const message = buildMessage(tool);
            if (!message) {
                alert('Please fill in all required fields.');
                return;
            }

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

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                spinner.classList.remove('active');
                box.style.display = 'block';

                if (data.success) {
                    box.className = 'output-box';
                    box.textContent = data.result;
                    document.getElementById('btnCopy').style.display = 'inline-flex';
                    document.getElementById('btnPrint').style.display = 'inline-flex';
                } else {
                    box.className = 'output-box empty';
                    box.innerHTML = `<i class="fas fa-exclamation-triangle" style="color:#e74c3c;font-size:2rem;"></i>
                    <p style="color:#e74c3c;">Error: ${data.error}</p>`;
                }
            } catch (err) {
                spinner.classList.remove('active');
                box.style.display = 'block';
                box.className = 'output-box empty';
                box.innerHTML = `<i class="fas fa-exclamation-triangle" style="color:#e74c3c;font-size:2rem;"></i>
                <p style="color:#e74c3c;">Network Error: ${err.message}</p>`;
            }
        }

        async function copyOutput() {
            const text = document.getElementById('outputBox').textContent;
            try {
                await navigator.clipboard.writeText(text);
                const btn = document.getElementById('btnCopy');
                const originalHtml = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                setTimeout(() => {
                    btn.innerHTML = originalHtml;
                }, 2000);
            } catch (err) {
                alert('Could not copy. Please select and copy manually.');
            }
        }

        function printOutput() {
            window.print();
        }
    </script>
</body>

</html>
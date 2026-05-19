<?php
// admin/ai-tools.php - AI Teaching Tools with Gemini API
session_start();

// ── Simple auth check ─────────────────────────────────────────
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['admin_username']) && !isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$admin_name = $_SESSION['admin_name'] ?? $_SESSION['username'] ?? $_SESSION['fullname'] ?? 'Administrator';
$admin_role = $_SESSION['admin_role'] ?? $_SESSION['role'] ?? 'admin';

// ── ============================================= ──
// ── 👇 PASTE YOUR GEMINI API KEY HERE 👇          ──
// ── ============================================= ──
$gemini_api_key = 'AIzaSyD3fG5hJkL9mNpQrStUvWxYz1234567890'; // <- Replace with your actual key!

// ── Set to false when you have a valid API key ──
$use_mock = false;  // Change to false to use real AI

// ── System Prompts for Nigerian Curriculum ──────────────────
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
[Engaging opening]

PRESENTATION / DEVELOPMENT:
Step 1 — [First concept]
Step 2 — [Second concept]
Step 3 — [Third concept]

WORKED EXAMPLES:
[2-3 clear examples]

CLASS ACTIVITY:
[In-class activity]

EVALUATION:
1. [question]
2. [question]
3. [question]

ASSIGNMENT:
[Take-home task]

CONCLUSION:
[Summary]

Follow Nigerian curriculum standards. Be thorough and age-appropriate.",

    'explain' => "You are a brilliant, warm Nigerian teacher who explains concepts simply.

Given a concept, subject, student level, structure your response as:

CONCEPT: [name] | Level: [level]

1. SIMPLE DEFINITION
One sentence a child could understand.

2. REAL-LIFE ANALOGY
Use everyday Nigerian contexts (market, farm, kitchen, football, etc.)

3. STEP-BY-STEP BREAKDOWN
Explain in 3-5 simple numbered steps.

4. COMMON MISTAKES
What do students often misunderstand?

5. MEMORY TRICK
A mnemonic or rhyme to remember it.

6. QUICK CHECK
Two questions for the teacher to ask.

Adjust complexity to the student level. Be encouraging.",

    'questions' => "You are an experienced examiner following Nigerian WAEC/NECO standards.

Given subject, topic, class level, number and difficulty, generate questions:

Header: [Subject] — [Topic] | [Level] | [Difficulty]

For OBJECTIVE (MCQ):
1. [Question]
   A. [option]   B. [option]   C. [option]   D. [option]
   Answer: [letter] — [brief reason]

For THEORY:
1. [Question] ([marks])
   Model Answer: [detailed answer]

Rules:
- Cover subtopics within the topic
- Match difficulty requested
- Include answers/model answers
- Number all questions clearly",
];

// ── Gemini API Function ─────────────────────────────────────
function callGeminiAPI($system_prompt, $user_message, $api_key)
{
    $full_prompt = $system_prompt . "\n\nUser Request:\n" . $user_message;

    $payload = json_encode([
        'contents' => [
            ['parts' => [['text' => $full_prompt]]]
        ],
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 2000,
        ]
    ]);

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key=" . $api_key;

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 60,
        ]
    ];

    $context = stream_context_create($options);
    $raw = @file_get_contents($url, false, $context);

    if ($raw === false) {
        return ['success' => false, 'error' => 'Cannot reach Gemini API. Check internet connection.'];
    }

    $data = json_decode($raw, true);

    if (isset($data['error'])) {
        return ['success' => false, 'error' => 'API Error: ' . ($data['error']['message'] ?? 'Unknown')];
    }

    if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        return ['success' => true, 'result' => $data['candidates'][0]['content']['parts'][0]['text']];
    }

    return ['success' => false, 'error' => 'Unexpected API response'];
}

// ── Mock Response (fallback) ────────────────────────────────
function getMockResponse($tool, $message)
{
    $sample = "========================================\n";
    $sample .= "🔧 MOCK MODE RESPONSE\n";
    $sample .= "========================================\n\n";
    $sample .= "To get real AI-generated content:\n";
    $sample .= "1. Get a free API key from https://makersuite.google.com/app/apikey\n";
    $sample .= "2. Add it to the \$gemini_api_key variable\n";
    $sample .= "3. Set \$use_mock = false\n\n";
    $sample .= "Based on your request: " . substr($message, 0, 100) . "...\n";
    $sample .= "\n[Sample response for testing purposes]";
    return $sample;
}

// ── Handle AJAX Request ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');

    $tool = $_POST['tool'] ?? '';
    $message = $_POST['message'] ?? '';

    if (!$tool || !$message || !isset($system_prompts[$tool])) {
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit();
    }

    // Use real API or mock
    global $use_mock, $gemini_api_key;

    if (!$use_mock && $gemini_api_key && $gemini_api_key !== 'AIzaSyD3fG5hJkL9mNpQrStUvWxYz1234567890') {
        $response = callGeminiAPI($system_prompts[$tool], $message, $gemini_api_key);
        echo json_encode($response);
    } else {
        $response = getMockResponse($tool, $message);
        echo json_encode(['success' => true, 'result' => $response]);
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Teaching Tools - Digital CBT System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background: #f5f7fa;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Header */
        .header {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .header h1 {
            color: #2c3e50;
            font-size: 24px;
            margin-bottom: 5px;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            margin-left: 10px;
            vertical-align: middle;
        }

        .badge.mock {
            background: #f39c12;
            color: white;
        }

        .badge.gemini {
            background: #27ae60;
            color: white;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .tab {
            background: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            color: #666;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .tab.active {
            background: #3498db;
            color: white;
        }

        /* Layout */
        .workspace {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .card h2 {
            font-size: 18px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #ecf0f1;
        }

        /* Forms */
        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #333;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }

        .form-group input:focus {
            outline: none;
            border-color: #3498db;
        }

        /* Buttons */
        .btn-generate {
            width: 100%;
            padding: 12px;
            background: #27ae60;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 10px;
        }

        .btn-generate:hover {
            background: #229954;
        }

        /* Output */
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .btn-action {
            padding: 6px 12px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
        }

        .output-area {
            min-height: 500px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 20px;
            white-space: pre-wrap;
            font-family: monospace;
            font-size: 14px;
            line-height: 1.6;
            overflow-y: auto;
        }

        .output-area.empty {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #999;
            min-height: 400px;
        }

        /* Spinner */
        .spinner {
            display: none;
            text-align: center;
            padding: 40px;
        }

        .spinner.active {
            display: block;
        }

        .loader {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3498db;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .footer {
            text-align: center;
            margin-top: 20px;
            padding: 20px;
            color: #999;
        }

        @media (max-width: 768px) {
            .workspace {
                grid-template-columns: 1fr;
            }

            body {
                padding: 10px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>
                <i class="fas fa-robot"></i> AI Teaching Tools
                <span class="badge <?php echo (!$use_mock && $gemini_api_key !== 'AIzaSyD3fG5hJkL9mNpQrStUvWxYz1234567890') ? 'gemini' : 'mock'; ?>">
                    <i class="fas <?php echo (!$use_mock && $gemini_api_key !== 'AIzaSyD3fG5hJkL9mNpQrStUvWxYz1234567890') ? 'fa-cloud' : 'fa-flask'; ?>"></i>
                    <?php echo (!$use_mock && $gemini_api_key !== 'AIzaSyD3fG5hJkL9mNpQrStUvWxYz1234567890') ? 'Gemini AI Active' : 'Mock Mode'; ?>
                </span>
            </h1>
            <p>Welcome, <?php echo htmlspecialchars($admin_name); ?></p>
        </div>

        <div class="tabs">
            <button class="tab active" onclick="switchTool('lesson')">📚 Lesson Note</button>
            <button class="tab" onclick="switchTool('explain')">💡 Explain Concept</button>
            <button class="tab" onclick="switchTool('question')">📝 Generate Questions</button>
        </div>

        <div class="workspace">
            <div class="card">
                <!-- Lesson Form -->
                <div id="form-lesson">
                    <h2><i class="fas fa-book"></i> Lesson Note Details</h2>
                    <div class="form-group">
                        <label>Subject *</label>
                        <input type="text" id="subject" placeholder="e.g. Mathematics, English, Biology">
                    </div>
                    <div class="form-group">
                        <label>Topic *</label>
                        <input type="text" id="topic" placeholder="e.g. Quadratic Equations, Fractions">
                    </div>
                    <div class="form-group">
                        <label>Class Level *</label>
                        <input type="text" id="level" placeholder="e.g. JSS 2, SS 1, Primary 5">
                    </div>
                    <div class="form-group">
                        <label>Duration</label>
                        <input type="text" id="duration" placeholder="e.g. 40 minutes" value="40 minutes">
                    </div>
                    <button class="btn-generate" onclick="generate('lesson')">
                        <i class="fas fa-magic"></i> Generate Lesson Note
                    </button>
                </div>

                <!-- Explain Form -->
                <div id="form-explain" style="display:none;">
                    <h2><i class="fas fa-lightbulb"></i> Concept Details</h2>
                    <div class="form-group">
                        <label>Concept *</label>
                        <input type="text" id="concept" placeholder="e.g. Photosynthesis, Democracy, Fractions">
                    </div>
                    <div class="form-group">
                        <label>Subject Area *</label>
                        <input type="text" id="subject_area" placeholder="e.g. Biology, Civic Education">
                    </div>
                    <div class="form-group">
                        <label>Student Level *</label>
                        <input type="text" id="student_level" placeholder="e.g. JSS 2, Primary 4">
                    </div>
                    <button class="btn-generate" onclick="generate('explain')">
                        <i class="fas fa-magic"></i> Explain Concept
                    </button>
                </div>

                <!-- Questions Form -->
                <div id="form-question" style="display:none;">
                    <h2><i class="fas fa-question-circle"></i> Exam Details</h2>
                    <div class="form-group">
                        <label>Subject *</label>
                        <input type="text" id="q_subject" placeholder="e.g. Mathematics, English">
                    </div>
                    <div class="form-group">
                        <label>Topic *</label>
                        <input type="text" id="q_topic" placeholder="e.g. Algebra, Comprehension">
                    </div>
                    <div class="form-group">
                        <label>Class Level *</label>
                        <input type="text" id="q_level" placeholder="e.g. SS 2, JSS 3">
                    </div>
                    <div class="form-group">
                        <label>Number of Questions</label>
                        <select id="q_count">
                            <option value="5">5 Questions</option>
                            <option value="10" selected>10 Questions</option>
                            <option value="20">20 Questions</option>
                        </select>
                    </div>
                    <button class="btn-generate" onclick="generate('question')">
                        <i class="fas fa-magic"></i> Generate Questions
                    </button>
                </div>
            </div>

            <div class="card">
                <div class="toolbar">
                    <span><i class="fas fa-file-alt"></i> AI Output</span>
                    <div>
                        <button class="btn-action" id="copyBtn" onclick="copyOutput()" style="display:none;">
                            <i class="fas fa-copy"></i> Copy
                        </button>
                        <button class="btn-action" onclick="printOutput()" id="printBtn" style="display:none;">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </div>

                <div id="spinner" class="spinner">
                    <div class="loader"></div>
                    <p>Generating content with AI...</p>
                </div>

                <div id="output" class="output-area empty">
                    <i class="fas fa-robot" style="font-size: 48px; margin-bottom: 10px;"></i>
                    <p>Fill in the form and click Generate</p>
                </div>
            </div>
        </div>

        <div class="footer">
            <p>AI Teaching Tools | <a href="index.php">Dashboard</a> | <a href="../logout.php">Logout</a></p>
        </div>
    </div>

    <script>
        let currentTool = 'lesson';

        function switchTool(tool) {
            currentTool = tool;
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            event.target.classList.add('active');

            document.getElementById('form-lesson').style.display = tool === 'lesson' ? 'block' : 'none';
            document.getElementById('form-explain').style.display = tool === 'explain' ? 'block' : 'none';
            document.getElementById('form-question').style.display = tool === 'question' ? 'block' : 'none';

            resetOutput();
        }

        function resetOutput() {
            const output = document.getElementById('output');
            output.className = 'output-area empty';
            output.innerHTML = '<i class="fas fa-robot" style="font-size: 48px; margin-bottom: 10px;"></i><p>Fill in the form and click Generate</p>';
            document.getElementById('copyBtn').style.display = 'none';
            document.getElementById('printBtn').style.display = 'none';
        }

        function getMessage() {
            if (currentTool === 'lesson') {
                const subject = document.getElementById('subject').value.trim();
                const topic = document.getElementById('topic').value.trim();
                const level = document.getElementById('level').value.trim();
                const duration = document.getElementById('duration').value.trim();
                if (!subject || !topic || !level) {
                    alert('Please fill in Subject, Topic, and Class Level');
                    return null;
                }
                return `Create a lesson note for ${subject}, topic: ${topic}, class: ${level}, duration: ${duration}`;
            }
            if (currentTool === 'explain') {
                const concept = document.getElementById('concept').value.trim();
                const subject = document.getElementById('subject_area').value.trim();
                const level = document.getElementById('student_level').value.trim();
                if (!concept || !subject || !level) {
                    alert('Please fill in Concept, Subject, and Student Level');
                    return null;
                }
                return `Explain ${concept} (${subject}) to ${level} students in simple terms with Nigerian examples`;
            }
            if (currentTool === 'question') {
                const subject = document.getElementById('q_subject').value.trim();
                const topic = document.getElementById('q_topic').value.trim();
                const level = document.getElementById('q_level').value.trim();
                const count = document.getElementById('q_count').value;
                if (!subject || !topic || !level) {
                    alert('Please fill in Subject, Topic, and Class Level');
                    return null;
                }
                return `Generate ${count} ${subject} questions on ${topic} for ${level} level with answers`;
            }
            return null;
        }

        async function generate(tool) {
            const message = getMessage();
            if (!message) return;

            const spinner = document.getElementById('spinner');
            const output = document.getElementById('output');
            spinner.classList.add('active');
            output.style.display = 'none';
            document.getElementById('copyBtn').style.display = 'none';
            document.getElementById('printBtn').style.display = 'none';

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
                output.style.display = 'block';

                if (data.success) {
                    output.className = 'output-area';
                    output.textContent = data.result;
                    document.getElementById('copyBtn').style.display = 'inline-block';
                    document.getElementById('printBtn').style.display = 'inline-block';
                } else {
                    output.className = 'output-area empty';
                    output.innerHTML = `<p style="color:red;">❌ Error: ${data.error}</p><p style="margin-top:10px;">💡 Tip: Make sure you have a valid Gemini API key and internet connection.</p>`;
                }
            } catch (err) {
                spinner.classList.remove('active');
                output.style.display = 'block';
                output.className = 'output-area empty';
                output.innerHTML = `<p style="color:red;">❌ Network Error: ${err.message}</p>`;
            }
        }

        function copyOutput() {
            const text = document.getElementById('output').textContent;
            navigator.clipboard.writeText(text).then(() => {
                const btn = document.getElementById('copyBtn');
                const original = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                setTimeout(() => btn.innerHTML = original, 2000);
            });
        }

        function printOutput() {
            window.print();
        }
    </script>
</body>

</html>
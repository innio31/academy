<?php
// admin/ai-tools.php - AI Teaching Tools with Groq API
session_start();

// ── Simple auth check ─────────────────────────────────────────
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['admin_username']) && !isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

// Include database config
require_once '../includes/config.php';

$admin_name = $_SESSION['admin_name'] ?? $_SESSION['username'] ?? $_SESSION['fullname'] ?? 'Administrator';
$admin_role = $_SESSION['admin_role'] ?? $_SESSION['role'] ?? 'admin';
$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;

// Groq API configuration is now in config.php
// Use constants defined there
$groq_api_key = GROQ_API_KEY;
$use_mock = GROQ_USE_MOCK;
$selected_model = GROQ_DEFAULT_MODEL;

// ── Fetch Subjects from Database (school-specific) ───────────────────
function getSubjects($pdo, $school_id) {
    try {
        $stmt = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE school_id = ? ORDER BY subject_name");
        $stmt->execute([$school_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// ── Fetch Topics from Database (school-specific) ─────────────────────
function getTopics($pdo, $school_id, $subject_id = null) {
    try {
        if ($subject_id) {
            $stmt = $pdo->prepare("SELECT id, topic_name FROM topics WHERE school_id = ? AND subject_id = ? ORDER BY topic_name");
            $stmt->execute([$school_id, $subject_id]);
        } else {
            $stmt = $pdo->prepare("SELECT id, topic_name, subject_id FROM topics WHERE school_id = ? ORDER BY topic_name");
            $stmt->execute([$school_id]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// ── Fetch Classes from Database ─────────────────────────────────────
function getClasses($pdo, $school_id) {
    try {
        $stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? AND status = 'active' ORDER BY sort_order, class_name");
        $stmt->execute([$school_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// Get data from database
$subjects = getSubjects($pdo, $school_id);
$topics = getTopics($pdo, $school_id);
$classes = getClasses($pdo, $school_id);

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

NOTE ON THE BOARD (Students copy this):
[Topic Title]

Definition: [A clear, detailed definition of the topic in 4-5 sentences suitable for the class level]

[Sub-heading 1]:
[Write 7-9 full sentences explaining this aspect thoroughly. Include relevant details, facts, and descriptions a student needs to understand the concept completely.]

[Sub-heading 2]:
[Write 7-9 full sentences explaining this aspect thoroughly. Include relevant details, facts, and descriptions.]

[Sub-heading 3]:
[Write 7-9 full sentences explaining this aspect thoroughly. Include relevant details, facts, and descriptions.]

Key Points to Remember:
i. [Detailed point]
ii. [Detailed point]
iii. [Detailed point]
iv. [Detailed point]

Worked Examples:
Example 1: [Full step-by-step worked example with explanation]
Example 2: [Full step-by-step worked example with explanation]

IMPORTANT: The NOTE ON THE BOARD must be detailed enough that a student reading it alone can understand the topic without needing the teacher's verbal explanation. Minimum 300 words for the note section.

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

Adjust complexity to the student level. Be encouraging."
];

// ── Groq API Function ─────────────────────────────────────
function callGroqAPI($system_prompt, $user_message, $api_key, $model = null) {
    // Use default model from config if not specified
    if ($model === null) {
        $model = GROQ_DEFAULT_MODEL;
    }
    
    $full_prompt = $system_prompt . "\n\nUser Request:\n" . $user_message;

    $maxRetries = 3;
    $attempt = 0;

    while ($attempt < $maxRetries) {
        $payload = json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful Nigerian curriculum teaching assistant.'],
                ['role' => 'user', 'content' => $full_prompt]
            ],
            'temperature' => 0.7,
            'max_tokens' => 4000,
            'top_p' => 0.9
        ]);

        $ch = curl_init(GROQ_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key,
            ],
            CURLOPT_TIMEOUT => 90,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            $attempt++;
            if ($attempt >= $maxRetries) {
                return ['success' => false, 'error' => 'cURL error: ' . $curlError];
            }
            sleep(2);
            continue;
        }

        if ($httpCode === 429) {
            $attempt++;
            if ($attempt < $maxRetries) {
                sleep(3);
                continue;
            }
            return ['success' => false, 'error' => 'Rate limit exceeded. Please try again in a moment.'];
        }

        if ($httpCode !== 200) {
            $decoded = json_decode($response, true);
            $errorMsg = $decoded['error']['message'] ?? $response;
            return ['success' => false, 'error' => 'Groq API Error: ' . $errorMsg];
        }

        $data = json_decode($response, true);

        if (isset($data['choices'][0]['message']['content'])) {
            return ['success' => true, 'result' => $data['choices'][0]['message']['content']];
        }

        return ['success' => false, 'error' => 'Unexpected API response structure'];
    }

    return ['success' => false, 'error' => 'Max retries exceeded'];
}

// ── Mock Response (fallback) ────────────────────────────────
function getMockResponse($tool, $message)
{
    $sample = "========================================\n";
    $sample .= "🔧 MOCK MODE RESPONSE\n";
    $sample .= "========================================\n\n";
    $sample .= "To get real AI-generated content:\n";
    $sample .= "1. Get a free API key from https://console.groq.com\n";
    $sample .= "2. Add it to the \$groq_api_key variable\n";
    $sample .= "3. Set \$use_mock = false\n\n";
    $sample .= "Based on your request: " . substr($message, 0, 100) . "...\n";
    $sample .= "\n[Sample response for testing purposes]\n\n";
    $sample .= "📚 MOCK LESSON NOTE EXAMPLE:\n";
    $sample .= "Subject: Sample | Topic: Testing | Class: SS 1\n\n";
    $sample .= "BEHAVIOURAL OBJECTIVES:\n";
    $sample .= "1. Understand the concept\n";
    $sample .= "2. Apply knowledge to real situations\n";
    $sample .= "3. Evaluate different scenarios\n\n";
    $sample .= "NOTE: This is a mock response. Get your Groq API key for real AI generation!";
    return $sample;
}

// ── AJAX endpoint to get topics based on subject ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['get_topics'])) {
    header('Content-Type: application/json');
    $subject_id = $_POST['subject_id'] ?? 0;
    
    if ($subject_id) {
        $stmt = $pdo->prepare("SELECT id, topic_name FROM topics WHERE school_id = ? AND subject_id = ? ORDER BY topic_name");
        $stmt->execute([$school_id, $subject_id]);
        $topics = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'topics' => $topics]);
    } else {
        echo json_encode(['success' => false, 'topics' => []]);
    }
    exit();
}

// ── Handle AJAX Request for AI Generation ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');

    $tool = $_POST['tool'] ?? '';
    $message = $_POST['message'] ?? '';
    $model = $_POST['model'] ?? GROQ_DEFAULT_MODEL;

    if (!$tool || !$message || !isset($system_prompts[$tool])) {
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit();
    }

    // Use constants from config.php
    if (!GROQ_USE_MOCK && !empty(GROQ_API_KEY)) {
        $response = callGroqAPI($system_prompts[$tool], $message, GROQ_API_KEY, $model);
        echo json_encode($response);
    } else {
        $response = getMockResponse($tool, $message);
        echo json_encode(['success' => true, 'result' => $response, 'mock' => true]);
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>AI Teaching Tools - <?php echo $school_name; ?></title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: <?php echo $secondary_color; ?>;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.1);
            --radius-sm: 8px;
            --radius-md: 12px;
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
            color: #333;
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Header */
        .header {
            background: white;
            padding: 20px 24px;
            border-radius: var(--radius-md);
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header h1 {
            color: var(--primary-color);
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .header p {
            color: #666;
            font-size: 0.85rem;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            margin-left: 10px;
            vertical-align: middle;
        }

        .badge.mock {
            background: var(--warning-color);
            color: white;
        }

        .badge.groq {
            background: #8B5CF6;
            color: white;
        }

        .btn-dashboard {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            transition: var(--transition);
        }

        .btn-dashboard:hover {
            background: var(--secondary-color);
            transform: translateY(-2px);
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .tab {
            background: white;
            border: none;
            padding: 12px 28px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 15px;
            font-weight: 500;
            color: #666;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .tab.active {
            background: var(--primary-color);
            color: white;
        }

        /* Layout */
        .workspace {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        .card {
            background: white;
            border-radius: var(--radius-md);
            padding: 24px;
            box-shadow: var(--shadow-sm);
        }

        .card h2 {
            font-size: 1.1rem;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--light-color);
            color: var(--primary-color);
        }

        /* Forms */
        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #333;
            font-size: 0.85rem;
        }

        .form-group label .required {
            color: var(--danger-color);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        /* Buttons */
        .btn-generate {
            width: 100%;
            padding: 12px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 10px;
            transition: var(--transition);
        }

        .btn-generate:hover {
            background: var(--secondary-color);
            transform: translateY(-2px);
        }

        /* Output */
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .btn-action {
            padding: 6px 12px;
            background: white;
            border: 1px solid #ddd;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-action:hover {
            background: var(--light-color);
        }

        .output-area {
            min-height: 500px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: var(--radius-sm);
            padding: 20px;
            white-space: pre-wrap;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.6;
            overflow-y: auto;
            max-height: 600px;
        }

        .output-area.empty {
            display: flex;
            flex-direction: column;
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
            border-top: 3px solid var(--primary-color);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .footer {
            text-align: center;
            margin-top: 30px;
            padding: 20px;
            color: #999;
            font-size: 0.8rem;
        }

        .model-selector {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--light-color);
        }

        .info-text {
            font-size: 11px;
            color: #666;
            margin-top: 5px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .workspace {
                grid-template-columns: 1fr;
            }
            
            .container {
                padding: 15px;
            }
            
            .header {
                flex-direction: column;
                text-align: center;
            }
            
            .header h1 {
                font-size: 1.2rem;
            }
            
            .tab {
                padding: 8px 16px;
                font-size: 13px;
            }
        }
        
        /* Scrollbar */
        .output-area::-webkit-scrollbar {
            width: 8px;
        }
        
        .output-area::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .output-area::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }
        
        .output-area::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
    </style>
</head>

<body>

<div class="container">
    <div class="header">
        <div>
            <h1>
                <i class="fas fa-robot"></i> AI Teaching Tools
                <span class="badge <?php echo (!GROQ_USE_MOCK && !empty(GROQ_API_KEY)) ? 'groq' : 'mock'; ?>">
    <i class="fas <?php echo (!GROQ_USE_MOCK && !empty(GROQ_API_KEY)) ? 'fa-cloud' : 'fa-flask'; ?>"></i>
    <?php echo (!GROQ_USE_MOCK && !empty(GROQ_API_KEY)) ? 'Groq AI Active' : 'Mock Mode'; ?>
</span>
            </h1>
            <p>Welcome back, <?php echo htmlspecialchars($admin_name); ?>! Generate lesson notes and explain concepts with AI.</p>
        </div>
        <a href="index.php" class="btn-dashboard">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>

    <div class="tabs">
        <button class="tab active" onclick="switchTool('lesson')">📚 Lesson Note</button>
        <button class="tab" onclick="switchTool('explain')">💡 Explain Concept</button>
    </div>

    <div class="workspace">
        <div class="card">
            <!-- Lesson Form -->
            <div id="form-lesson">
                <h2><i class="fas fa-book"></i> Lesson Note Details</h2>
                <div class="form-group">
                    <label>Subject <span class="required">*</span></label>
                    <select id="subject" onchange="loadTopics()">
                        <option value="">-- Select Subject --</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo $subject['id']; ?>" data-name="<?php echo htmlspecialchars($subject['subject_name']); ?>">
                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Topic <span class="required">*</span></label>
                    <select id="topic">
                        <option value="">-- Select Topic --</option>
                        <?php foreach ($topics as $topic): ?>
                            <option value="<?php echo htmlspecialchars($topic['topic_name']); ?>" data-subject-id="<?php echo $topic['subject_id']; ?>">
                                <?php echo htmlspecialchars($topic['topic_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Class Level <span class="required">*</span></label>
                    <select id="level">
                        <option value="">-- Select Class --</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo htmlspecialchars($class['class_name']); ?>">
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
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
                    <label>Concept <span class="required">*</span></label>
                    <input type="text" id="concept" placeholder="e.g. Photosynthesis, Democracy, Fractions">
                </div>
                <div class="form-group">
                    <label>Subject Area <span class="required">*</span></label>
                    <select id="subject_area">
                        <option value="">-- Select Subject --</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo htmlspecialchars($subject['subject_name']); ?>">
                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Student Level <span class="required">*</span></label>
                    <select id="student_level">
                        <option value="">-- Select Class --</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo htmlspecialchars($class['class_name']); ?>">
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn-generate" onclick="generate('explain')">
                    <i class="fas fa-magic"></i> Explain Concept
                </button>
            </div>

            <!-- Model Selector -->
<div class="model-selector">
    <div class="form-group">
        <label><i class="fas fa-microchip"></i> AI Model</label>
        <select id="ai_model">
            <?php foreach ($GROQ_AVAILABLE_MODELS as $model_key => $model_name): ?>
                <option value="<?php echo $model_key; ?>" <?php echo $model_key === GROQ_DEFAULT_MODEL ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($model_name); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div class="info-text">
            <i class="fas fa-info-circle"></i>
            Higher request limits: Llama 3.1 8B (14,400/day) | Best quality: Llama 3.3 70B
        </div>
    </div>
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
                <p>Generating content with Groq AI... (15-30 seconds)</p>
            </div>

            <div id="output" class="output-area empty">
                <i class="fas fa-robot" style="font-size: 48px; margin-bottom: 10px;"></i>
                <p>Fill in the form and click Generate</p>
            </div>
        </div>
    </div>

    <div class="footer">
        <p>&copy; <?php echo date('Y'); ?> <?php echo $school_name; ?> - AI Teaching Tools | Powered by Groq Cloud</p>
    </div>
</div>

<script>
    let currentTool = 'lesson';
    
    // Store subjects data
    const subjects = <?php echo json_encode($subjects); ?>;
    const allTopics = <?php echo json_encode($topics); ?>;

    function loadTopics() {
        const subjectSelect = document.getElementById('subject');
        const topicSelect = document.getElementById('topic');
        const selectedSubjectId = subjectSelect.value;
        const selectedSubjectName = subjectSelect.options[subjectSelect.selectedIndex]?.getAttribute('data-name');
        
        // Clear current options
        topicSelect.innerHTML = '<option value="">-- Select Topic --</option>';
        
        if (selectedSubjectId) {
            // Filter topics by selected subject
            const filteredTopics = allTopics.filter(topic => topic.subject_id == selectedSubjectId);
            
            filteredTopics.forEach(topic => {
                const option = document.createElement('option');
                option.value = topic.topic_name;
                option.textContent = topic.topic_name;
                topicSelect.appendChild(option);
            });
        }
    }
    
    // Initial load of topics based on default selected subject
    document.addEventListener('DOMContentLoaded', function() {
        if (document.getElementById('subject').value) {
            loadTopics();
        }
    });

    function switchTool(tool) {
        currentTool = tool;
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        event.target.classList.add('active');

        document.getElementById('form-lesson').style.display = tool === 'lesson' ? 'block' : 'none';
        document.getElementById('form-explain').style.display = tool === 'explain' ? 'block' : 'none';

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
            const subjectSelect = document.getElementById('subject');
            const subject = subjectSelect.options[subjectSelect.selectedIndex]?.getAttribute('data-name') || subjectSelect.value;
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
        return null;
    }

    async function generate(tool) {
        const message = getMessage();
        if (!message) return;

        const spinner = document.getElementById('spinner');
        const output = document.getElementById('output');
        const model = document.getElementById('ai_model').value;

        spinner.classList.add('active');
        output.style.display = 'none';
        document.getElementById('copyBtn').style.display = 'none';
        document.getElementById('printBtn').style.display = 'none';

        try {
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('tool', tool);
            formData.append('message', message);
            formData.append('model', model);

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

                if (data.mock) {
                    console.warn('Using mock mode - add Groq API key for real AI');
                }
            } else {
                output.className = 'output-area empty';
                let errorHtml = `<p style="color:red;">❌ Error: ${data.error}</p>`;
                if (data.error && data.error.includes('API')) {
                    errorHtml += `<p style="margin-top:10px;">💡 Tip: Make sure you have a valid Groq API key.<br>
                    Get one for free at: <a href="https://console.groq.com" target="_blank">https://console.groq.com</a></p>`;
                } else if (data.error && data.error.includes('Rate limit')) {
                    errorHtml += `<p style="margin-top:10px;">⏱️ Rate limit hit. Try using Llama 3.1 8B which has higher limits (14,400 requests/day).</p>`;
                } else {
                    errorHtml += `<p style="margin-top:10px;">💡 Tip: Check your internet connection and try again.</p>`;
                }
                output.innerHTML = errorHtml;
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
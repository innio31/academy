<?php
// msv/student/waec-practice.php - Complete Fixed Version
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: /msv/login.php");
    exit();
}

global $pdo;

$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;
$student_name = $_SESSION['user_name'] ?? 'Student';
$student_id = $_SESSION['user_id'];
$school_id = $_SESSION['school_id'] ?? SCHOOL_ID;

// Initialize variables
$performance_stats = ['total_sessions' => 0, 'avg_percentage' => 0, 'total_questions_attempted' => 0, 'avg_score' => 0];
$recent_sessions = [];
$weak_topics = [];
$subjects = [];

try {
    // Get overall performance stats
    $stats_query = "SELECT 
                        COUNT(DISTINCT id) as total_sessions,
                        COALESCE(AVG(percentage), 0) as avg_percentage,
                        COALESCE(SUM(total_attempted), 0) as total_questions_attempted,
                        COALESCE(AVG(score), 0) as avg_score
                    FROM waec_practice_sessions 
                    WHERE student_id = ? AND school_id = ? AND status = 'completed'";
    $stmt = $pdo->prepare($stats_query);
    $stmt->execute([$student_id, $school_id]);
    $performance_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get recent sessions
    $recent_query = "SELECT ws.*, wsub.subject_name 
                     FROM waec_practice_sessions ws
                     LEFT JOIN waec_subjects wsub ON ws.waec_subject_id = wsub.id
                     WHERE ws.student_id = ? AND ws.school_id = ? AND ws.status = 'completed'
                     ORDER BY ws.created_at DESC LIMIT 5";
    $stmt = $pdo->prepare($recent_query);
    $stmt->execute([$student_id, $school_id]);
    $recent_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get weak topics
    $weak_query = "SELECT wtp.*, wsub.subject_name, wt.topic_name
                   FROM waec_topic_performance wtp
                   JOIN waec_topics wt ON wtp.waec_topic_id = wt.id
                   JOIN waec_subjects wsub ON wtp.waec_subject_id = wsub.id
                   WHERE wtp.student_id = ? AND wtp.school_id = ? 
                   AND wtp.mastery_level IN ('needs_work', 'not_started')
                   ORDER BY wtp.avg_percentage ASC LIMIT 5";
    $stmt = $pdo->prepare($weak_query);
    $stmt->execute([$student_id, $school_id]);
    $weak_topics = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get subjects for dropdown
    $subjects_query = "SELECT id, subject_name FROM waec_subjects WHERE is_active = 1 ORDER BY subject_name";
    $subjects = $pdo->query($subjects_query)->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("WAEC Practice Error: " . $e->getMessage());
}

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WAEC Practice | <?= htmlspecialchars($school_name) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
  :root {
    --primary: <?= htmlspecialchars($primary_color ?? '#1a6b3c') ?>;
    --bg: #0a0f0d;
    --surface: #111710;
    --card: #172118;
    --border: rgba(255,255,255,.07);
    --text: #e8f0ea;
    --muted: #7a9982;
    --accent: #4ade80;
    --radius: 16px;
    --mono: 'Space Mono', monospace;
    --sans: 'Sora', sans-serif;
  }
  
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  
  body {
    font-family: var(--sans);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
  }
  
  body::before {
    content: '';
    position: fixed; inset: 0;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");
    pointer-events: none; z-index: 0;
  }
  
  .blob {
    position: fixed;
    border-radius: 50%;
    filter: blur(120px);
    opacity: .15;
    pointer-events: none;
    z-index: 0;
  }
  .blob-1 { width: 600px; height: 600px; background: var(--primary); top: -200px; right: -150px; }
  .blob-2 { width: 400px; height: 400px; background: var(--accent); bottom: -100px; left: -100px; }
  
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
    background: rgba(74,222,128,.1);
    border-color: var(--accent);
  }
  
  .alert-error {
    background: rgba(251,113,133,.15);
    border: 1px solid #fb7185;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .alert-warning {
    background: rgba(251,191,36,.15);
    border: 1px solid #fbbf24;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
  }
  
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 40px;
  }
  .stat-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    transition: all .25s;
  }
  .stat-card:hover {
    border-color: rgba(74,222,128,.3);
    transform: translateY(-2px);
  }
  .stat-icon {
    width: 48px;
    height: 48px;
    background: rgba(74,222,128,.1);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 16px;
    font-size: 1.5rem;
    color: var(--accent);
  }
  .stat-value {
    font-size: 2rem;
    font-weight: 700;
    font-family: var(--mono);
    color: var(--text);
  }
  .stat-label {
    color: var(--muted);
    font-size: 0.875rem;
    margin-top: 4px;
  }
  
  .mode-section {
    margin-bottom: 48px;
  }
  .section-title {
    font-size: 1.25rem;
    font-weight: 600;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .section-title i {
    color: var(--accent);
  }
  .mode-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
  }
  .mode-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 24px;
    cursor: pointer;
    transition: all .25s;
  }
  .mode-card:hover {
    border-color: var(--accent);
    transform: translateY(-4px);
    background: rgba(74,222,128,.05);
  }
  .mode-icon {
    width: 56px;
    height: 56px;
    background: linear-gradient(135deg, var(--primary), #2d9e5f);
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
    margin-bottom: 20px;
  }
  .mode-card h3 {
    font-size: 1.25rem;
    margin-bottom: 8px;
  }
  .mode-card p {
    color: var(--muted);
    font-size: 0.875rem;
    line-height: 1.5;
  }
  
  .modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,.8);
    backdrop-filter: blur(8px);
    z-index: 1000;
    align-items: center;
    justify-content: center;
  }
  .modal.active {
    display: flex;
  }
  .modal-content {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 24px;
    padding: 32px;
    max-width: 500px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
  }
  .modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
  }
  .modal-header h2 {
    font-size: 1.5rem;
  }
  .close-modal {
    background: none;
    border: none;
    color: var(--muted);
    font-size: 1.5rem;
    cursor: pointer;
  }
  .form-group {
    margin-bottom: 20px;
  }
  .form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
  }
  .form-group select, .form-group input {
    width: 100%;
    padding: 12px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text);
    font-family: var(--sans);
  }
  .radio-group {
    display: flex;
    gap: 20px;
    margin-top: 8px;
  }
  .radio-group label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
  }
  .btn-submit {
    width: 100%;
    padding: 14px;
    background: linear-gradient(135deg, var(--primary), #2d9e5f);
    border: none;
    border-radius: 12px;
    color: white;
    font-weight: 600;
    cursor: pointer;
    margin-top: 12px;
  }
  
  .sessions-table {
    width: 100%;
    border-collapse: collapse;
  }
  .sessions-table th,
  .sessions-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid var(--border);
  }
  .sessions-table th {
    color: var(--muted);
    font-weight: 500;
    font-size: 0.75rem;
    text-transform: uppercase;
  }
  .badge-score {
    background: rgba(74,222,128,.15);
    color: var(--accent);
    padding: 4px 8px;
    border-radius: 6px;
    font-family: var(--mono);
    font-size: 0.75rem;
  }
  .btn-review {
    background: rgba(255,255,255,.1);
    border: none;
    padding: 6px 12px;
    border-radius: 6px;
    color: var(--text);
    cursor: pointer;
    font-size: 0.75rem;
  }
  
  .topic-list {
    list-style: none;
  }
  .topic-list li {
    padding: 12px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
  }
  .topic-name {
    font-weight: 500;
  }
  .topic-subject {
    font-size: 0.75rem;
    color: var(--muted);
  }
  .mastery-badge {
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 0.7rem;
    background: rgba(251,113,133,.15);
    color: #fb7185;
  }
  
  @media (max-width: 768px) {
    .container { padding: 20px; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
    .mode-cards { grid-template-columns: 1fr; }
    .top-nav { flex-direction: column; text-align: center; }
  }
</style>
</head>
<body>

<div class="blob blob-1"></div>
<div class="blob blob-2"></div>

<div class="container">
  <div class="top-nav">
    <div class="logo-area">
      <h1><i class="fa-solid fa-pen-to-square"></i> WAEC Practice</h1>
      <p>Master your subjects with past questions and personalized drills</p>
    </div>
    <a href="index.php" class="back-btn">
      <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
    </a>
  </div>
  
  <!-- Display error/warning messages -->
  <?php if (isset($_SESSION['waec_error'])): ?>
  <div class="alert-error">
    <i class="fa-solid fa-circle-exclamation" style="color: #fb7185; font-size: 1.25rem;"></i>
    <div style="flex: 1;">
      <strong style="color: #fb7185;">Notice:</strong> <?= htmlspecialchars($_SESSION['waec_error']) ?>
    </div>
    <button onclick="this.parentElement.remove()" style="background: none; border: none; color: var(--muted); cursor: pointer; font-size: 1.25rem;">&times;</button>
  </div>
  <?php unset($_SESSION['waec_error']); endif; ?>
  
  <?php if (isset($_SESSION['waec_warning'])): ?>
  <div class="alert-warning">
    <i class="fa-solid fa-triangle-exclamation" style="color: #fbbf24; font-size: 1.25rem;"></i>
    <div style="flex: 1;">
      <strong style="color: #fbbf24;">Warning:</strong> <?= htmlspecialchars($_SESSION['waec_warning']) ?>
    </div>
    <button onclick="this.parentElement.remove()" style="background: none; border: none; color: var(--muted); cursor: pointer; font-size: 1.25rem;">&times;</button>
  </div>
  <?php unset($_SESSION['waec_warning']); endif; ?>
  
  <!-- Stats Cards -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-icon"><i class="fa-solid fa-chart-simple"></i></div>
      <div class="stat-value"><?= round($performance_stats['avg_percentage'] ?? 0) ?>%</div>
      <div class="stat-label">Average Score</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
      <div class="stat-value"><?= $performance_stats['total_sessions'] ?? 0 ?></div>
      <div class="stat-label">Practice Sessions</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon"><i class="fa-solid fa-question"></i></div>
      <div class="stat-value"><?= number_format($performance_stats['total_questions_attempted'] ?? 0) ?></div>
      <div class="stat-label">Questions Attempted</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon"><i class="fa-solid fa-trophy"></i></div>
      <div class="stat-value"><?= round(($performance_stats['avg_score'] ?? 0)) ?></div>
      <div class="stat-label">Avg Correct</div>
    </div>
  </div>
  
  <!-- Practice Mode Selection -->
  <div class="mode-section">
    <div class="section-title">
      <i class="fa-solid fa-flask"></i>
      <span>Choose Practice Mode</span>
    </div>
    <div class="mode-cards">
      <div class="mode-card" onclick="openModeModal('year')">
        <div class="mode-icon"><i class="fa-solid fa-calendar-days"></i></div>
        <h3>Year-Based Practice</h3>
        <p>Practice by selecting a specific WAEC year and subject. Experience the actual exam format.</p>
      </div>
      <div class="mode-card" onclick="openModeModal('topical')">
        <div class="mode-icon"><i class="fa-solid fa-layer-group"></i></div>
        <h3>Topical Practice</h3>
        <p>Focus on specific topics across multiple years. Perfect for mastering difficult areas.</p>
      </div>
    </div>
  </div>
  
  <!-- Recent Sessions -->
  <?php if (!empty($recent_sessions)): ?>
  <div class="mode-section">
    <div class="section-title">
      <i class="fa-solid fa-clock-rotate-left"></i>
      <span>Recent Practice Sessions</span>
    </div>
    <div class="stat-card" style="padding: 0; overflow: hidden;">
      <table class="sessions-table">
        <thead>
          <tr><th>Subject</th><th>Mode</th><th>Score</th><th>Date</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($recent_sessions as $session): ?>
          <tr>
            <td><?= htmlspecialchars($session['subject_name'] ?? 'N/A') ?></td>
            <td><?= ucfirst($session['practice_mode']) ?></td>
            <td><span class="badge-score"><?= round($session['percentage'] ?? 0) ?>%</span></td>
            <td><?= date('M j, Y', strtotime($session['created_at'])) ?></td>
            <td><button class="btn-review" onclick="viewSession(<?= $session['id'] ?>)">Review</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
  
  <!-- Weak Topics -->
  <?php if (!empty($weak_topics)): ?>
  <div class="mode-section">
    <div class="section-title">
      <i class="fa-solid fa-lightbulb"></i>
      <span>Areas Needing Improvement</span>
    </div>
    <div class="stat-card" style="padding: 0;">
      <ul class="topic-list">
        <?php foreach ($weak_topics as $topic): ?>
        <li>
          <div>
            <div class="topic-name"><?= htmlspecialchars($topic['topic_name']) ?></div>
            <div class="topic-subject"><?= htmlspecialchars($topic['subject_name']) ?></div>
          </div>
          <div>
            <span class="mastery-badge"><?= str_replace('_', ' ', $topic['mastery_level']) ?></span>
            <button class="btn-review" style="margin-left: 12px;" onclick="practiceTopic(<?= $topic['waec_topic_id'] ?>, <?= $topic['waec_subject_id'] ?>)">Practice</button>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Mode Selection Modal - FIXED VERSION -->
<div id="modeModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2 id="modalTitle">Select Practice Details</h2>
      <button class="close-modal" onclick="closeModal()">&times;</button>
    </div>
    <form id="practiceForm" action="waec-session.php" method="POST" onsubmit="return validateForm()">
      <input type="hidden" name="mode" id="practiceMode" value="">
      
      <!-- Year Mode Fields -->
      <div id="yearFields" style="display: none;">
        <div class="form-group">
          <label>Select Year</label>
          <select name="exam_year" id="examYear">
            <option value="">Choose Year</option>
            <?php for($y = date('Y'); $y >= 2000; $y--): ?>
            <option value="<?= $y ?>"><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Select Subject</label>
          <select name="subject_id" id="subjectYearSelect">
            <option value="">Choose Subject</option>
            <?php foreach ($subjects as $subject): ?>
            <option value="<?= $subject['id'] ?>"><?= htmlspecialchars($subject['subject_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      
      <!-- Topical Mode Fields -->
      <div id="topicFields" style="display: none;">
        <div class="form-group">
          <label>Select Subject</label>
          <select name="subject_id" id="subjectSelect" onchange="loadTopics()">
            <option value="">Choose Subject</option>
            <?php foreach ($subjects as $subject): ?>
            <option value="<?= $subject['id'] ?>"><?= htmlspecialchars($subject['subject_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Select Topic</label>
          <select name="topic_id" id="topicSelect">
            <option value="">First select a subject</option>
          </select>
        </div>
      </div>
      
      <div class="form-group">
        <label>Session Mode</label>
        <div class="radio-group">
          <label><input type="radio" name="session_mode" value="standard" checked onchange="toggleCustomSettings()"> Standard (WAEC Settings)</label>
          <label><input type="radio" name="session_mode" value="practice" onchange="toggleCustomSettings()"> Custom Settings</label>
        </div>
      </div>
      
      <div id="customSettings" style="display: none;">
        <div class="form-group">
          <label>Number of Questions</label>
          <input type="number" name="custom_questions" id="customQuestions" min="5" max="100" value="20">
        </div>
        <div class="form-group">
          <label>Duration (minutes)</label>
          <input type="number" name="custom_duration" id="customDuration" min="5" max="180" value="30">
        </div>
      </div>
      
      <button type="submit" class="btn-submit">Start Practice <i class="fa-solid fa-arrow-right"></i></button>
    </form>
  </div>
</div>

<script>
let currentMode = '';

function openModeModal(mode) {
    currentMode = mode;
    document.getElementById('practiceMode').value = mode;
    document.getElementById('modalTitle').innerHTML = mode === 'year' ? 'Year-Based Practice' : 'Topical Practice';
    
    // Hide all field groups
    document.getElementById('yearFields').style.display = 'none';
    document.getElementById('topicFields').style.display = 'none';
    document.getElementById('subjectYearFields').style.display = 'none';
    
    if (mode === 'year') {
        document.getElementById('yearFields').style.display = 'block';
        document.getElementById('subjectYearFields').style.display = 'block';
    } else {
        document.getElementById('topicFields').style.display = 'block';
    }
    
    document.getElementById('modeModal').classList.add('active');
}

function closeModal() {
    document.getElementById('modeModal').classList.remove('active');
}

function toggleCustomSettings() {
    const customDiv = document.getElementById('customSettings');
    const practiceRadio = document.querySelector('input[name="session_mode"][value="practice"]');
    customDiv.style.display = practiceRadio.checked ? 'block' : 'none';
}

function loadTopics() {
    const subjectId = document.getElementById('subjectSelect').value;
    const topicSelect = document.getElementById('topicSelect');
    
    if (!subjectId) {
        topicSelect.innerHTML = '<option value="">First select a subject</option>';
        return;
    }
    
    topicSelect.innerHTML = '<option value="">Loading...</option>';
    
    fetch(`api/get_topics.php?subject_id=${subjectId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.topics.length > 0) {
                topicSelect.innerHTML = '<option value="">Select Topic</option>';
                data.topics.forEach(topic => {
                    topicSelect.innerHTML += `<option value="${topic.id}">${escapeHtml(topic.topic_name)}</option>`;
                });
            } else {
                topicSelect.innerHTML = '<option value="">No topics available</option>';
            }
        })
        .catch(error => {
            console.error('Error loading topics:', error);
            topicSelect.innerHTML = '<option value="">Error loading topics</option>';
        });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// VALIDATION FUNCTION - Fixed for topical mode
function validateForm() {
    const mode = document.getElementById('practiceMode').value;
    
    console.log('Validating form for mode:', mode); // For debugging
    
    if (mode === 'year') {
        const subjectSelect = document.getElementById('subjectYearSelect');
        const yearSelect = document.getElementById('examYear');
        
        if (!subjectSelect || !subjectSelect.value) {
            alert('Please select a subject');
            return false;
        }
        
        if (!yearSelect || !yearSelect.value) {
            alert('Please select a year');
            return false;
        }
        
        console.log('Year mode validation passed - Subject:', subjectSelect.value, 'Year:', yearSelect.value);
        return true;
    }
    
    if (mode === 'topical') {
        const subjectSelect = document.getElementById('subjectSelect');
        const topicSelect = document.getElementById('topicSelect');
        
        if (!subjectSelect || !subjectSelect.value) {
            alert('Please select a subject');
            return false;
        }
        
        if (!topicSelect || !topicSelect.value) {
            alert('Please select a topic');
            return false;
        }
        
        console.log('Topical mode validation passed - Subject:', subjectSelect.value, 'Topic:', topicSelect.value);
        return true;
    }
    
    alert('Invalid practice mode selected');
    return false;
}

function viewSession(sessionId) {
    window.location.href = `waec-results.php?session_id=${sessionId}`;
}

function practiceTopic(topicId, subjectId) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'waec-session.php';
    
    const inputs = {
        mode: 'topical',
        topic_id: topicId,
        subject_id: subjectId,
        session_mode: 'standard'
    };
    
    for (const [name, value] of Object.entries(inputs)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }
    
    document.body.appendChild(form);
    form.submit();
}

// Close modal on outside click
window.onclick = function(event) {
    const modal = document.getElementById('modeModal');
    if (event.target === modal) {
        closeModal();
    }
}
</script>
</body>
</html>
<?php
echo ob_get_clean();
?>
<?php
// msv/student/view-results.php - View Results
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

// Get student details
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND school_id = ?");
$stmt->execute([$student_id, $school_id]);
$student_data = $stmt->fetch();

if (!$student_data) {
    header("Location: /msv/login.php");
    exit();
}

$student_class = $student_data['class'];
$admission_number = $student_data['admission_number'];

// Get profile picture path
$profile_picture = !empty($student_data['profile_picture']) ? $student_data['profile_picture'] : '/assets/uploads/default-avatar.png';
if (!empty($student_data['profile_picture']) && strpos($student_data['profile_picture'], '/') !== 0) {
    $profile_picture = '/uploads/' . $student_data['profile_picture'];
}

$exam_id = $_GET['exam_id'] ?? 0;

// Get all exams taken by student
$stmt = $pdo->prepare("
    SELECT r.*, e.exam_name, s.subject_name, e.exam_type, e.duration_minutes,
           es.total_questions as exam_total_questions
    FROM results r
    JOIN exams e ON r.exam_id = e.id
    JOIN subjects s ON e.subject_id = s.id
    LEFT JOIN exam_sessions es ON es.exam_id = r.exam_id AND es.student_id = r.student_id AND es.status = 'completed'
    WHERE r.student_id = ? AND r.school_id = ?
    ORDER BY r.submitted_at DESC
");
$stmt->execute([$student_id, $school_id]);
$results = $stmt->fetchAll();

// Calculate overall statistics
$total_exams = count($results);
$average_percentage = 0;
$best_score = 0;
$best_subject = '';
$grade_distribution = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];

foreach ($results as $result) {
    $percentage = $result['percentage'] ?? 0;
    $average_percentage += $percentage;
    
    if ($percentage > $best_score) {
        $best_score = $percentage;
        $best_subject = $result['subject_name'];
    }
    
    $grade = $result['grade'] ?? 'F';
    if (isset($grade_distribution[$grade])) {
        $grade_distribution[$grade]++;
    }
}

if ($total_exams > 0) {
    $average_percentage = $average_percentage / $total_exams;
}

// Get specific exam details if selected
$exam_result = null;
if ($exam_id) {
    foreach ($results as $r) {
        if ($r['exam_id'] == $exam_id) {
            $exam_result = $r;
            break;
        }
    }
    
    // If exam result found but we need more details, fetch detailed scores
    if ($exam_result) {
        // Fetch session and decode answers from objective_answers JSON
        $stmt = $pdo->prepare("
            SELECT es.objective_answers, es.id as session_id
            FROM exam_sessions es
            WHERE es.student_id = ? AND es.exam_id = ? AND es.status = 'completed'
            ORDER BY es.id DESC LIMIT 1
        ");
        $stmt->execute([$student_id, $exam_id]);
        $session_row = $stmt->fetch();

        $detailed_answers = [];
        $correct_count = 0;

        if ($session_row) {
            $saved_answers = json_decode($session_row['objective_answers'], true) ?? [];

            $stmt = $pdo->prepare("
                SELECT q.*
                FROM exam_session_questions esq
                JOIN objective_questions q ON esq.question_id = q.id
                WHERE esq.session_id = ?
            ");
            $stmt->execute([$session_row['session_id']]);
            $questions_data = $stmt->fetchAll();

            foreach ($questions_data as $q) {
                $selected = $saved_answers[$q['id']] ?? null;
                $is_correct = $selected && strtoupper($selected) === strtoupper($q['correct_answer']) ? 1 : 0;
                $q['selected_answer'] = $selected;
                $q['is_correct'] = $is_correct;
                $detailed_answers[] = $q;
                if ($is_correct) $correct_count++;
            }
        }
    }
}

// Get performance by subject
$subject_performance = [];
foreach ($results as $result) {
    $subject = $result['subject_name'];
    if (!isset($subject_performance[$subject])) {
        $subject_performance[$subject] = ['total' => 0, 'count' => 0, 'best' => 0];
    }
    $percentage = $result['percentage'] ?? 0;
    $subject_performance[$subject]['total'] += $percentage;
    $subject_performance[$subject]['count']++;
    if ($percentage > $subject_performance[$subject]['best']) {
        $subject_performance[$subject]['best'] = $percentage;
    }
}

foreach ($subject_performance as $subject => $data) {
    $subject_performance[$subject]['average'] = $data['total'] / $data['count'];
}

// Get grade helper function
function getGradeClass($grade) {
    switch ($grade) {
        case 'A': return 'grade-a';
        case 'B': return 'grade-b';
        case 'C': return 'grade-c';
        case 'D': return 'grade-d';
        default: return 'grade-f';
    }
}

function getGradeColor($grade) {
    switch ($grade) {
        case 'A': return '#27ae60';
        case 'B': return '#2ecc71';
        case 'C': return '#f39c12';
        case 'D': return '#e67e22';
        default: return '#e74c3c';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($school_name); ?> - My Results</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: <?php echo $secondary_color; ?>;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
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

        /* Top Header */
        .top-header {
            background: white;
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 25px;
            background: linear-gradient(135deg, var(--primary-color), var(--dark));
            color: white;
        }

        .welcome-banner {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .welcome-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #d4af7a;
            background: #f0f0f0;
        }

        .welcome-text h1 {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .welcome-text p {
            opacity: 0.9;
            font-size: 0.85rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            text-align: center;
            box-shadow: var(--shadow);
            transition: var(--transition);
            border-top: 3px solid var(--primary-color);
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .stat-label {
            color: #666;
            font-size: 0.8rem;
            margin-top: 5px;
        }

        .stat-sub {
            font-size: 0.7rem;
            color: #999;
            margin-top: 5px;
        }

        /* Cards */
        .content-card {
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: var(--shadow);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--light);
            flex-wrap: wrap;
            gap: 10px;
        }

        .card-header h3 {
            color: var(--primary-color);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Grade Colors */
        .grade-a {
            color: #27ae60;
            font-weight: 700;
        }
        .grade-b {
            color: #2ecc71;
            font-weight: 700;
        }
        .grade-c {
            color: #f39c12;
            font-weight: 700;
        }
        .grade-d {
            color: #e67e22;
            font-weight: 700;
        }
        .grade-f {
            color: #e74c3c;
            font-weight: 700;
        }

        /* Result Summary */
        .result-summary {
            text-align: center;
            padding: 30px;
            background: linear-gradient(135deg, #f8f9fa, #fff);
            border-radius: var(--radius);
            margin-bottom: 20px;
        }

        .result-score {
            font-size: 3.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .result-details {
            display: flex;
            justify-content: center;
            gap: 30px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .result-detail-item {
            text-align: center;
        }

        .result-detail-label {
            font-size: 0.75rem;
            color: #666;
        }

        .result-detail-value {
            font-size: 1.1rem;
            font-weight: 600;
        }

        /* Data Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .data-table th {
            background: #f8f9fa;
            font-weight: 600;
            font-size: 0.85rem;
            color: #555;
        }

        .data-table tr:hover {
            background: #f9f9f9;
        }

        /* Subject Performance */
        .subject-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .subject-item {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .subject-name {
            width: 120px;
            font-weight: 500;
        }

        .subject-progress {
            flex: 1;
            height: 8px;
            background: var(--light);
            border-radius: 10px;
            overflow: hidden;
        }

        .subject-progress-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.5s ease;
        }

        .subject-score {
            width: 80px;
            text-align: right;
            font-weight: 600;
        }

        /* Grade Distribution */
        .grade-distribution {
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
            gap: 15px;
        }

        .grade-item {
            text-align: center;
            flex: 1;
            min-width: 50px;
        }

        .grade-count {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .grade-letter {
            font-size: 0.8rem;
            color: #666;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 8px 18px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.8rem;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .btn-outline {
            border: 1px solid #e0e0e0;
            background: white;
            color: var(--dark);
        }

        .btn-outline:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        .btn-sm {
            padding: 5px 12px;
            font-size: 0.7rem;
        }

        /* Certificate Button */
        .certificate-btn {
            margin-top: 15px;
            background: #d4af7a;
            color: #1a2a3a;
        }

        /* Back Link */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.85rem;
            margin-bottom: 20px;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        /* Footer */
        .footer {
            text-align: center;
            padding: 20px;
            color: #666;
            font-size: 0.8rem;
            border-top: 1px solid var(--light);
            margin-top: 20px;
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

            .welcome-banner {
                flex-direction: column;
                text-align: center;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .data-table {
                font-size: 0.75rem;
            }

            .data-table th,
            .data-table td {
                padding: 8px;
            }

            .subject-name {
                width: 100px;
                font-size: 0.8rem;
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

        <div class="top-header">
            <div class="welcome-banner">
                <img src="<?php echo htmlspecialchars($profile_picture); ?>"
                    alt="Profile Picture"
                    class="welcome-avatar"
                    onerror="this.src='/assets/uploads/default-avatar.png'">
                <div class="welcome-text">
                    <h1><i class="fas fa-chart-bar"></i> My Results</h1>
                    <p><i class="fas fa-graduation-cap"></i> Class: <?php echo htmlspecialchars($student_class); ?> | <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($admission_number); ?></p>
                </div>
            </div>
        </div>

        <?php if ($exam_result): ?>
            <!-- Detailed Exam Result View -->
            <a href="view-results.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to All Results</a>
            
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($exam_result['exam_name']); ?></h3>
                    <span class="grade-<?php echo $exam_result['grade']; ?>" style="font-size: 1.2rem; font-weight: 700;">
                        Grade: <?php echo $exam_result['grade']; ?>
                    </span>
                </div>
                
                <div class="result-summary">
                    <div class="result-score"><?php echo number_format($exam_result['percentage'] ?? 0, 1); ?>%</div>
                    <div class="result-details">
                        <div class="result-detail-item">
                            <div class="result-detail-label">Score</div>
                            <div class="result-detail-value"><?php echo $exam_result['total_score']; ?> / <?php echo $exam_result['exam_total_questions'] ?? $exam_result['total_score']; ?></div>
                        </div>
                        <div class="result-detail-item">
                            <div class="result-detail-label">Subject</div>
                            <div class="result-detail-value"><?php echo htmlspecialchars($exam_result['subject_name']); ?></div>
                        </div>
                        <div class="result-detail-item">
                            <div class="result-detail-label">Date Taken</div>
                            <div class="result-detail-value"><?php echo date('M d, Y', strtotime($exam_result['submitted_at'])); ?></div>
                        </div>
                        <div class="result-detail-item">
                            <div class="result-detail-label">Time</div>
                            <div class="result-detail-value"><?php echo date('g:i A', strtotime($exam_result['submitted_at'])); ?></div>
                        </div>
                    </div>
                </div>
                
                <?php if (isset($detailed_answers) && !empty($detailed_answers)): ?>
                    <div class="card-header" style="margin-top: 20px;">
                        <h3><i class="fas fa-list-check"></i> Question Breakdown</h3>
                    </div>
                    <?php
                    // Helper: get the full option text for a given letter
                    function getOptionText($qa, $letter) {
                        if (!$letter) return null;
                        $key = 'option_' . strtolower($letter);
                        return $qa[$key] ?? null;
                    }
                    ?>
                    <div style="display:flex; flex-direction:column; gap:20px; margin-top:10px;">
                        <?php foreach ($detailed_answers as $index => $qa):
                            $selected = $qa['selected_answer'] ?? null;
                            $correct  = strtoupper($qa['correct_answer']);
                            $options  = ['A','B','C','D'];
                            if (!empty($qa['option_e'])) $options[] = 'E';
                        ?>
                        <div style="background:#fff; border-radius:12px; padding:20px; box-shadow:0 2px 8px rgba(0,0,0,0.07); border-left:4px solid <?php echo $qa['is_correct'] ? '#27ae60' : '#e74c3c'; ?>;">
                            
                            <!-- Question -->
                            <div style="display:flex; gap:12px; margin-bottom:14px;">
                                <span style="background:<?php echo $qa['is_correct'] ? '#27ae60' : '#e74c3c'; ?>; color:#fff; border-radius:8px; padding:2px 10px; font-size:.75rem; font-weight:700; flex-shrink:0; height:fit-content;">
                                    Q<?php echo $index + 1; ?>
                                </span>
                                <div style="font-size:.9rem; font-weight:600; color:#1e293b; line-height:1.5;">
                                    <?php echo htmlspecialchars($qa['question_text']); ?>
                                </div>
                            </div>

                            <!-- Options -->
                            <div style="display:flex; flex-direction:column; gap:7px; margin-bottom:14px;">
                                <?php foreach ($options as $letter):
                                    $text = getOptionText($qa, $letter);
                                    if (!$text) continue;
                                    $isSelected = strtoupper($selected) === $letter;
                                    $isCorrect  = $letter === $correct;

                                    if ($isSelected && $isCorrect) {
                                        $bg = '#f0fdf4'; $border = '#22c55e'; $textColor = '#15803d'; $icon = '✓';
                                    } elseif ($isSelected && !$isCorrect) {
                                        $bg = '#fef2f2'; $border = '#ef4444'; $textColor = '#b91c1c'; $icon = '✗';
                                    } elseif ($isCorrect) {
                                        $bg = '#f0fdf4'; $border = '#22c55e'; $textColor = '#15803d'; $icon = '✓';
                                    } else {
                                        $bg = '#f8fafc'; $border = '#e2e8f0'; $textColor = '#64748b'; $icon = '';
                                    }
                                ?>
                                <div style="display:flex; align-items:center; gap:10px; background:<?php echo $bg; ?>; border:1.5px solid <?php echo $border; ?>; border-radius:8px; padding:9px 14px;">
                                    <span style="width:26px; height:26px; border-radius:6px; background:<?php echo $border; ?>; color:#fff; display:flex; align-items:center; justify-content:center; font-size:.72rem; font-weight:800; flex-shrink:0;">
                                        <?php echo $letter; ?>
                                    </span>
                                    <span style="font-size:.85rem; color:<?php echo $textColor; ?>; flex:1;">
                                        <?php echo htmlspecialchars($text); ?>
                                    </span>
                                    <?php if ($icon): ?>
                                    <span style="font-size:1rem; font-weight:800; color:<?php echo $textColor; ?>;"><?php echo $icon; ?></span>
                                    <?php endif; ?>
                                    <?php if ($isSelected && !$isCorrect): ?>
                                        <span style="font-size:.68rem; color:#b91c1c; font-weight:600;">Your answer</span>
                                    <?php elseif ($isCorrect && !$isSelected): ?>
                                        <span style="font-size:.68rem; color:#15803d; font-weight:600;">Correct answer</span>
                                    <?php elseif ($isCorrect && $isSelected): ?>
                                        <span style="font-size:.68rem; color:#15803d; font-weight:600;">Your answer ✓</span>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Not answered note -->
                            <?php if (!$selected): ?>
                            <div style="font-size:.78rem; color:#f59e0b; font-weight:600;">
                                <i class="fas fa-exclamation-triangle"></i> Not answered — correct answer was <strong><?php echo $correct; ?>: <?php echo htmlspecialchars(getOptionText($qa, $correct)); ?></strong>
                            </div>
                            <?php endif; ?>

                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <div style="display: flex; gap: 10px; margin-top: 20px; justify-content: center;">
                    <button onclick="window.print();" class="btn btn-outline">
                        <i class="fas fa-print"></i> Print Result
                    </button>
                    <button onclick="window.location.href='view-certificate.php?exam_id=<?php echo $exam_result['exam_id']; ?>'" class="btn btn-primary certificate-btn">
                        <i class="fas fa-certificate"></i> Download Certificate
                    </button>
                </div>
            </div>
        <?php else: ?>
            <!-- Statistics Dashboard -->
            <?php if ($total_exams > 0): ?>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $total_exams; ?></div>
                        <div class="stat-label">Exams Taken</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo number_format($average_percentage, 1); ?>%</div>
                        <div class="stat-label">Average Score</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo number_format($best_score, 1); ?>%</div>
                        <div class="stat-label">Best Score</div>
                        <div class="stat-sub"><?php echo htmlspecialchars($best_subject); ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Subject Performance -->
            <?php if (!empty($subject_performance)): ?>
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-line"></i> Performance by Subject</h3>
                    </div>
                    <div class="subject-list">
                        <?php foreach ($subject_performance as $subject => $data): 
                            $avg = round($data['average'], 1);
                            $color = $avg >= 70 ? '#27ae60' : ($avg >= 60 ? '#2ecc71' : ($avg >= 50 ? '#f39c12' : ($avg >= 40 ? '#e67e22' : '#e74c3c')));
                        ?>
                            <div class="subject-item">
                                <div class="subject-name"><?php echo htmlspecialchars($subject); ?></div>
                                <div class="subject-progress">
                                    <div class="subject-progress-fill" style="width: <?php echo $avg; ?>%; background: <?php echo $color; ?>;"></div>
                                </div>
                                <div class="subject-score" style="color: <?php echo $color; ?>;"><?php echo $avg; ?>%</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Grade Distribution -->
            <?php if ($total_exams > 0): ?>
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-pie"></i> Grade Distribution</h3>
                    </div>
                    <div class="grade-distribution">
                        <?php foreach (['A', 'B', 'C', 'D', 'F'] as $grade): ?>
                            <div class="grade-item">
                                <div class="grade-count" style="color: <?php echo getGradeColor($grade); ?>;"><?php echo $grade_distribution[$grade] ?? 0; ?></div>
                                <div class="grade-letter">Grade <?php echo $grade; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- All Results Table -->
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-history"></i> All Exam Results</h3>
                    <span class="badge-count"><?php echo $total_exams; ?> exams</span>
                </div>
                
                <?php if (empty($results)): ?>
                    <div class="empty-state">
                        <i class="fas fa-chart-line"></i>
                        <p>No results available yet.</p>
                        <p style="font-size: 0.8rem; margin-top: 10px;">Take some exams to see your performance here.</p>
                        <a href="take-exam.php" class="btn btn-primary" style="margin-top: 20px;">
                            <i class="fas fa-play"></i> Take an Exam
                        </a>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Exam Name</th>
                                    <th>Subject</th>
                                    <th>Score</th>
                                    <th>Percentage</th>
                                    <th>Grade</th>
                                    <th>Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $result): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($result['exam_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($result['subject_name']); ?></td>
                                        <td><?php echo $result['total_score']; ?> / <?php echo $result['exam_total_questions'] ?? $result['total_score']; ?></td>
                                        <td><?php echo number_format($result['percentage'] ?? 0, 1); ?>%</td>
                                        <td class="grade-<?php echo $result['grade']; ?>"><?php echo $result['grade']; ?></td>
                                        <td><?php echo date('M d, Y', strtotime($result['submitted_at'])); ?></td>
                                        <td>
                                            <a href="view-results.php?exam_id=<?php echo $result['exam_id']; ?>" class="btn btn-primary btn-sm">
                                                <i class="fas fa-eye"></i> Details
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> - Student Portal</p>
        </div>
    </div>

    <script>
        // Mobile sidebar toggle
        const mobileBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('studentSidebar');
        
        if (mobileBtn && sidebar) {
            mobileBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                sidebar.classList.toggle('active');
                const overlay = document.getElementById('sidebarOverlay');
                if (overlay) overlay.classList.toggle('active');
                document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
            });
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768 && sidebar && 
                !sidebar.contains(e.target) && 
                !mobileBtn.contains(e.target)) {
                sidebar.classList.remove('active');
                const overlay = document.getElementById('sidebarOverlay');
                if (overlay) overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });

        // Animate progress bars on load
        document.addEventListener('DOMContentLoaded', function() {
            const progressBars = document.querySelectorAll('.subject-progress-fill');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.width = width;
                }, 100);
            });
        });
    </script>

    <style>
        .badge-count {
            background: var(--light);
            padding: 3px 12px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--dark);
        }
    </style>
</body>

</html>
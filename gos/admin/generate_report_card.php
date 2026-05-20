<?php
// gos/admin/generate_report_card.php - Generate Report Card with Template Selection
session_start();

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /gos/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id      = SCHOOL_ID;
$primary_color  = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;
$school_logo    = defined('SCHOOL_LOGO') ? SCHOOL_LOGO : '/assets/logos/default.png';

// Fetch full school record
$stmt = $pdo->prepare("SELECT * FROM schools WHERE id = ? LIMIT 1");
$stmt->execute([$school_id]);
$school = $stmt->fetch();

$school_name    = $school['school_name'] ?? SCHOOL_NAME;
$school_address = $school['contact_email'] ?? '';
$school_email   = $school['contact_email'] ?? '';
$school_phone   = $school['contact_phone'] ?? '';
$school_motto   = $school['motto'] ?? (defined('SCHOOL_MOTTO') ? SCHOOL_MOTTO : '');
$school_logo    = !empty($school['logo_path']) ? $school['logo_path'] : $school_logo;
$primary_color  = !empty($school['primary_color']) ? $school['primary_color'] : $primary_color;
$secondary_color = !empty($school['secondary_color']) ? $school['secondary_color'] : $secondary_color;
$accent_color   = !empty($school['accent_color']) ? $school['accent_color'] : '#ffffff';

$student_id = $_GET['student_id'] ?? null;
$session    = $_GET['session']    ?? date('Y') . '/' . (date('Y') + 1);
$term       = $_GET['term']       ?? 'First';
$format     = $_GET['format']     ?? 'html';
$template   = $_GET['template']   ?? '';

// ─── STUDENT SELECTION FORM (shown when no student_id) ─────────────────────
if (!$student_id) {
    // Get classes for dropdown
    $stmt = $pdo->prepare("SELECT DISTINCT class FROM students WHERE school_id = ? AND status = 'active' ORDER BY class");
    $stmt->execute([$school_id]);
    $classes = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Get selected class from POST or default
    $selected_class = $_POST['class'] ?? ($classes[0] ?? '');
    $selected_session = $_POST['session'] ?? $session;
    $selected_term = $_POST['term'] ?? $term;

    // Get students for selected class
    $students = [];
    if ($selected_class) {
        $stmt = $pdo->prepare("SELECT id, full_name, admission_number, class FROM students WHERE school_id = ? AND class = ? AND status = 'active' ORDER BY full_name");
        $stmt->execute([$school_id, $selected_class]);
        $students = $stmt->fetchAll();
    }

    ob_start();
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Select Student - Generate Report Card</title>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'Plus Jakarta Sans', sans-serif;
                background: #f1f5f9;
                min-height: 100vh;
                padding: 40px 20px;
                color: #1e293b;
            }

            .container {
                max-width: 800px;
                margin: 0 auto;
            }

            .card {
                background: #fff;
                border-radius: 20px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
                overflow: hidden;
            }

            .card-header {
                background: <?php echo htmlspecialchars($primary_color); ?>;
                color: #fff;
                padding: 25px 30px;
            }

            .card-header h1 {
                font-size: 1.5rem;
                margin-bottom: 5px;
            }

            .card-header p {
                opacity: 0.9;
                font-size: 0.9rem;
            }

            .card-body {
                padding: 30px;
            }

            .form-group {
                margin-bottom: 20px;
            }

            label {
                display: block;
                font-weight: 600;
                margin-bottom: 8px;
                color: #334155;
            }

            select,
            input {
                width: 100%;
                padding: 12px 15px;
                border: 2px solid #e2e8f0;
                border-radius: 12px;
                font-size: 0.95rem;
                font-family: inherit;
                transition: all 0.2s;
            }

            select:focus,
            input:focus {
                outline: none;
                border-color: <?php echo htmlspecialchars($primary_color); ?>;
            }

            .btn {
                padding: 12px 24px;
                border-radius: 12px;
                font-weight: 600;
                cursor: pointer;
                text-decoration: none;
                border: none;
                font-family: inherit;
                display: inline-flex;
                align-items: center;
                gap: 8px;
            }

            .btn-primary {
                background: <?php echo htmlspecialchars($primary_color); ?>;
                color: #fff;
            }

            .btn-primary:hover {
                opacity: 0.9;
            }

            .btn-secondary {
                background: #64748b;
                color: #fff;
            }

            .student-list {
                margin-top: 25px;
                border-top: 1px solid #e2e8f0;
                padding-top: 20px;
            }

            .student-list h3 {
                margin-bottom: 15px;
                color: #1e293b;
            }

            .student-grid {
                display: grid;
                gap: 12px;
            }

            .student-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 15px;
                background: #f8fafc;
                border-radius: 12px;
                transition: all 0.2s;
            }

            .student-item:hover {
                background: #f1f5f9;
            }

            .student-info {
                display: flex;
                flex-direction: column;
            }

            .student-name {
                font-weight: 600;
                color: #1e293b;
            }

            .student-adm {
                font-size: 0.8rem;
                color: #64748b;
            }

            .btn-sm {
                padding: 8px 16px;
                font-size: 0.85rem;
            }

            .no-students {
                text-align: center;
                padding: 40px;
                color: #64748b;
            }

            .back-link {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                color: #64748b;
                text-decoration: none;
                margin-bottom: 20px;
            }

            .back-link:hover {
                color: #1e293b;
            }

            .row-2 {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
            }

            @media (max-width: 640px) {
                .row-2 {
                    grid-template-columns: 1fr;
                }
            }
        </style>
    </head>

    <body>
        <div class="container">
            <a href="report_card_dashboard.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>

            <div class="card">
                <div class="card-header">
                    <h1><i class="fas fa-file-contract"></i> Generate Report Card</h1>
                    <p>Select a student to generate their report card</p>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <div class="row-2">
                            <div class="form-group">
                                <label><i class="fas fa-chalkboard"></i> Select Class</label>
                                <select name="class" id="classSelect" required>
                                    <option value="">-- Select Class --</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo htmlspecialchars($class); ?>" <?php echo $selected_class == $class ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($class); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-calendar"></i> Session</label>
                                <input type="text" name="session" value="<?php echo htmlspecialchars($selected_session); ?>" placeholder="e.g., 2024/2025">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-clock"></i> Term</label>
                                <select name="term">
                                    <option value="First" <?php echo $selected_term == 'First' ? 'selected' : ''; ?>>First Term</option>
                                    <option value="Second" <?php echo $selected_term == 'Second' ? 'selected' : ''; ?>>Second Term</option>
                                    <option value="Third" <?php echo $selected_term == 'Third' ? 'selected' : ''; ?>>Third Term</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <button type="submit" name="filter" class="btn btn-primary" style="width:100%">
                                    <i class="fas fa-search"></i> Filter Students
                                </button>
                            </div>
                        </div>
                    </form>

                    <?php if ($selected_class && !empty($students)): ?>
                        <div class="student-list">
                            <h3><i class="fas fa-users"></i> Students in <?php echo htmlspecialchars($selected_class); ?></h3>
                            <div class="student-grid">
                                <?php foreach ($students as $student): ?>
                                    <div class="student-item">
                                        <div class="student-info">
                                            <span class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></span>
                                            <span class="student-adm">Adm: <?php echo htmlspecialchars($student['admission_number']); ?></span>
                                        </div>
                                        <a href="?student_id=<?php echo $student['id']; ?>&session=<?php echo urlencode($selected_session); ?>&term=<?php echo urlencode($selected_term); ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-file-pdf"></i> Generate
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php elseif ($selected_class && empty($students)): ?>
                        <div class="no-students">
                            <i class="fas fa-user-graduate" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                            <p>No active students found in <?php echo htmlspecialchars($selected_class); ?></p>
                            <a href="add_student.php" class="btn btn-primary" style="margin-top: 15px;">
                                <i class="fas fa-plus"></i> Add Student
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <script>
            // Auto-submit when class changes
            document.getElementById('classSelect')?.addEventListener('change', function() {
                this.form.submit();
            });
        </script>
    </body>

    </html>
<?php
    echo ob_get_clean();
    exit;
}

// ... (rest of the original code continues below)
// ═══════════════════════════════════════════════════════════════════════════════
// CONTINUE WITH ORIGINAL CODE FROM HERE (the existing code after student selection)
// ═══════════════════════════════════════════════════════════════════════════════

// ─── Fetch student ────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT s.*,
           TIMESTAMPDIFF(YEAR, s.dob, CURDATE()) AS age_years
    FROM students s
    WHERE s.id = ? AND s.school_id = ?
");
$stmt->execute([$student_id, $school_id]);
$student = $stmt->fetch();

if (!$student) die("Student not found!");

// ─── Fetch scores ─────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT ss.*, sub.subject_name
    FROM student_scores ss
    JOIN subjects sub ON ss.subject_id = sub.id AND sub.school_id = ?
    WHERE ss.student_id = ? AND ss.session = ? AND ss.term = ?
    ORDER BY sub.subject_name
");
$stmt->execute([$school_id, $student_id, $session, $term]);
$scores = $stmt->fetchAll();

// ─── Fetch position ───────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT sp.*,
           (SELECT COUNT(*) FROM students WHERE class = ? AND school_id = ? AND status = 'active') AS class_total
    FROM student_positions sp
    WHERE sp.student_id = ? AND sp.session = ? AND sp.term = ?
");
$stmt->execute([$student['class'], $school_id, $student_id, $session, $term]);
$position    = $stmt->fetch();
$class_total = $position['class_total'] ?? 0;

// ─── Fetch comments ───────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT * FROM student_comments
    WHERE student_id = ? AND session = ? AND term = ?
");
$stmt->execute([$student_id, $session, $term]);
$comments = $stmt->fetch();

// ─── Fetch affective traits ───────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT * FROM affective_traits
    WHERE student_id = ? AND session = ? AND term = ?
");
$stmt->execute([$student_id, $session, $term]);
$affective = $stmt->fetch();

// ─── Fetch psychomotor skills ─────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT * FROM psychomotor_skills
    WHERE student_id = ? AND session = ? AND term = ?
");
$stmt->execute([$student_id, $session, $term]);
$psychomotor = $stmt->fetch();

// ─── Fetch settings ───────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT * FROM report_card_settings
    WHERE class = ? AND session = ? AND term = ? AND school_id = ?
");
$stmt->execute([$student['class'], $session, $term, $school_id]);
$settings = $stmt->fetch();

if (!$settings) {
    $settings = [
        'max_score'                 => 100,
        'score_types'               => json_encode([
            ['name' => 'CA 1',  'max_score' => 20],
            ['name' => 'CA 2',  'max_score' => 20],
            ['name' => 'Exam',  'max_score' => 60],
        ]),
        'grading_system'            => 'simple',
        'next_resumption_date'      => null,
        'current_resumption_date'   => null,
        'current_closing_date'      => null,
        'days_school_opened'        => 90,
        'show_class_position'       => 1,
        'show_subject_position'     => 1,
        'show_promoted_to'          => 1,
        'show_lowest_highest_avg'   => 1,
        'show_lowest_highest_class' => 1,
        'template'                  => 'classic',
    ];
}

// If a template was saved in settings and none passed via GET, use it
if (empty($template)) {
    $template = $settings['template'] ?? 'classic';
}

$score_types = json_decode($settings['score_types'], true);

// ─── Calculations ─────────────────────────────────────────────────────────────
$total_marks    = 0;
$subject_count  = count($scores);
foreach ($scores as $score) {
    $total_marks += $score['total_score'];
}
$overall_average    = $subject_count > 0 ? ($total_marks / $subject_count) : 0;
$overall_percentage = $subject_count > 0 && $settings['max_score'] > 0
    ? ($total_marks / ($subject_count * $settings['max_score']) * 100)
    : 0;

// Class highest/lowest
$stmt = $pdo->prepare("
    SELECT MAX(sp.average) AS highest, MIN(sp.average) AS lowest
    FROM student_positions sp
    JOIN students s ON sp.student_id = s.id
    WHERE s.class = ? AND s.school_id = ? AND sp.session = ? AND sp.term = ? AND sp.average > 0
");
$stmt->execute([$student['class'], $school_id, $session, $term]);
$class_stats     = $stmt->fetch();
$highest_average = $class_stats['highest'] ?? 0;
$lowest_average  = $class_stats['lowest']  ?? 0;

// Attendance
$days_present        = $comments['days_present']  ?? 0;
$days_absent         = $comments['days_absent']   ?? 0;
$days_school_opened  = $settings['days_school_opened'] ?? 90;
$attendance_pct      = $days_school_opened > 0
    ? round(($days_present / $days_school_opened) * 100, 1) : 0;

// Age
$age_display = '';
if ($student['dob']) {
    $age_display = floor((time() - strtotime($student['dob'])) / 31556926) . ' yrs';
}

// ─── Helper functions ─────────────────────────────────────────────────────────
function ordinal($n)
{
    if (!is_numeric($n)) return $n;
    $ends = ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'];
    if (($n % 100) >= 11 && ($n % 100) <= 13) return $n . 'th';
    return $n . $ends[$n % 10];
}

function getGrade($pct, $system = 'simple')
{
    switch ($system) {
        case 'american':
            if ($pct >= 97) return 'A+';
            if ($pct >= 93) return 'A';
            if ($pct >= 90) return 'A-';
            if ($pct >= 87) return 'B+';
            if ($pct >= 83) return 'B';
            if ($pct >= 80) return 'B-';
            if ($pct >= 77) return 'C+';
            if ($pct >= 73) return 'C';
            if ($pct >= 70) return 'C-';
            if ($pct >= 67) return 'D+';
            if ($pct >= 63) return 'D';
            if ($pct >= 60) return 'D-';
            return 'F';
        case 'waec':
            if ($pct >= 75) return 'A1';
            if ($pct >= 70) return 'B2';
            if ($pct >= 65) return 'B3';
            if ($pct >= 60) return 'C4';
            if ($pct >= 55) return 'C5';
            if ($pct >= 50) return 'C6';
            if ($pct >= 45) return 'D7';
            if ($pct >= 40) return 'E8';
            return 'F9';
        default:
            if ($pct >= 70) return 'A';
            if ($pct >= 60) return 'B';
            if ($pct >= 50) return 'C';
            if ($pct >= 45) return 'D';
            if ($pct >= 40) return 'E';
            return 'F';
    }
}

function getRemark($pct)
{
    if ($pct >= 70) return 'Excellent';
    if ($pct >= 60) return 'Very Good';
    if ($pct >= 50) return 'Good';
    if ($pct >= 45) return 'Average';
    if ($pct >= 40) return 'Below Avg';
    return 'Fail';
}

function gradeColor($grade)
{
    $g = strtoupper(substr($grade, 0, 1));
    $map = ['A' => '#16a34a', 'B' => '#2563eb', 'C' => '#d97706', 'D' => '#ea580c', 'E' => '#dc2626', 'F' => '#991b1b'];
    return $map[$g] ?? '#374151';
}

function traitLabel($grade)
{
    $map = ['A' => 'Excellent', 'B' => 'Very Good', 'C' => 'Good', 'D' => 'Average', 'E' => 'Poor'];
    return $map[strtoupper($grade)] ?? '—';
}

function photoTag($student, $size = 90)
{
    $pic = !empty($student['profile_picture']) ? htmlspecialchars($student['profile_picture']) : '';
    if ($pic) {
        return "<img src=\"{$pic}\" alt=\"Student Photo\" style=\"width:{$size}px;height:{$size}px;object-fit:cover;border-radius:6px;display:block;\">";
    }
    $initials = strtoupper(substr($student['full_name'] ?? 'S', 0, 1));
    return "<div style=\"width:{$size}px;height:{$size}px;border-radius:6px;background:#cbd5e1;display:flex;align-items:center;justify-content:center;font-size:" . round($size * 0.38) . "px;font-weight:700;color:#475569;\">{$initials}</div>";
}

function logoTag($logo_path, $h = 60)
{
    if (empty($logo_path)) return '';
    return "<img src=\"" . htmlspecialchars($logo_path) . "\" alt=\"School Logo\" style=\"height:{$h}px;width:auto;display:block;\">";
}

// ═══════════════════════════════════════════════════════════════════════════════
// TEMPLATE PICKER (shown when no template is selected via GET)
// ═══════════════════════════════════════════════════════════════════════════════
function showTemplatePicker($student, $session, $term, $primary_color, $secondary_color, $school_name, $school_logo)
{
    $sid = (int)$student['id'];
    $s   = htmlspecialchars($session);
    $t   = htmlspecialchars($term);

    $templates = [
        'classic'  => ['name' => 'Classic',  'desc' => 'Traditional layout with bordered tables and structured grid. Familiar, professional.',      'icon' => '🏫', 'bg' => '#1B2A4A', 'accent' => '#d4af37'],
        'modern'   => ['name' => 'Modern',   'desc' => 'Clean cards with coloured score badges and a two-tone header. Sleek and contemporary.',     'icon' => '✦',  'bg' => '#0f172a', 'accent' => '#38bdf8'],
        'elegant'  => ['name' => 'Elegant',  'desc' => 'Serif typography, wide margins, gold rule lines. Refined and distinguished.',               'icon' => '◈',  'bg' => '#2d1b00', 'accent' => '#c9933a'],
        'minimal'  => ['name' => 'Minimal',  'desc' => 'Pure white with thin lines and generous spacing. Quietly confident and uncluttered.',       'icon' => '○',  'bg' => '#18181b', 'accent' => '#a1a1aa'],
        'bold'     => ['name' => 'Bold',     'desc' => 'Full-bleed colour header, large score tiles, high-contrast. Energetic and eye-catching.',   'icon' => '▲',  'bg' => '#7c3aed', 'accent' => '#fbbf24'],
    ];

    ob_start();
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Select Report Card Template</title>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
            *,
            *::before,
            *::after {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }

            body {
                font-family: 'Plus Jakarta Sans', sans-serif;
                background: #f1f5f9;
                min-height: 100vh;
                padding: 40px 20px 60px;
                color: #1e293b;
            }

            .page-header {
                text-align: center;
                margin-bottom: 40px;
            }

            .page-header .school-logo-sm {
                height: 52px;
                margin-bottom: 14px;
            }

            .page-header h1 {
                font-size: 1.65rem;
                font-weight: 800;
                color: #0f172a;
                margin-bottom: 6px;
            }

            .page-header p {
                color: #64748b;
                font-size: .95rem;
            }

            .student-badge {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                background: #fff;
                border: 1px solid #e2e8f0;
                border-radius: 50px;
                padding: 8px 18px 8px 8px;
                margin-top: 16px;
                font-size: .88rem;
                color: #334155;
                box-shadow: 0 1px 4px rgba(0, 0, 0, .06);
            }

            .student-badge span.dot {
                width: 32px;
                height: 32px;
                border-radius: 50%;
                background: <?php echo htmlspecialchars($primary_color); ?>;
                color: #fff;
                display: grid;
                place-items: center;
                font-weight: 700;
                font-size: .9rem;
            }

            .grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                gap: 22px;
                max-width: 1100px;
                margin: 0 auto;
            }

            .card {
                background: #fff;
                border-radius: 16px;
                overflow: hidden;
                border: 2px solid transparent;
                box-shadow: 0 2px 10px rgba(0, 0, 0, .07);
                transition: transform .2s, box-shadow .2s, border-color .2s;
                cursor: pointer;
                text-decoration: none;
                color: inherit;
                display: flex;
                flex-direction: column;
            }

            .card:hover {
                transform: translateY(-4px);
                box-shadow: 0 12px 32px rgba(0, 0, 0, .13);
                border-color: <?php echo htmlspecialchars($primary_color); ?>;
            }

            .card-preview {
                height: 150px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 3rem;
                position: relative;
                overflow: hidden;
            }

            .card-preview .stripe {
                position: absolute;
                bottom: 0;
                left: 0;
                right: 0;
                height: 6px;
            }

            .card-body {
                padding: 20px 22px 24px;
            }

            .card-body h3 {
                font-size: 1.05rem;
                font-weight: 700;
                margin-bottom: 6px;
            }

            .card-body p {
                font-size: .84rem;
                color: #64748b;
                line-height: 1.55;
            }

            .card-body .cta {
                display: inline-block;
                margin-top: 16px;
                padding: 9px 20px;
                border-radius: 8px;
                font-size: .85rem;
                font-weight: 600;
                text-decoration: none;
                transition: opacity .15s;
            }

            .card-body .cta:hover {
                opacity: .85;
            }

            /* individual card previews */
            .preview-classic {
                background: linear-gradient(145deg, #1B2A4A 0%, #2d4373 100%);
            }

            .preview-modern {
                background: linear-gradient(145deg, #0f172a 0%, #1e3a5f 100%);
            }

            .preview-elegant {
                background: linear-gradient(145deg, #2d1b00 0%, #5c3d11 100%);
            }

            .preview-minimal {
                background: linear-gradient(145deg, #f8fafc 0%, #e2e8f0 100%);
                border-bottom: 1px solid #e2e8f0;
            }

            .preview-bold {
                background: linear-gradient(145deg, #7c3aed 0%, #a855f7 100%);
            }

            .back-link {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                color: #64748b;
                font-size: .88rem;
                text-decoration: none;
                margin-bottom: 28px;
            }

            .back-link:hover {
                color: #1e293b;
            }
        </style>
    </head>

    <body>

        <a href="report_cards.php" class="back-link">← Back to Report Cards</a>

        <div class="page-header">
            <?php if ($school_logo): ?>
                <img src="<?php echo htmlspecialchars($school_logo); ?>" class="school-logo-sm" alt="Logo">
            <?php endif; ?>
            <h1>Choose a Report Card Template</h1>
            <p>Select the style you want for this report card. You can change it any time.</p>
            <div class="student-badge">
                <span class="dot"><?php echo strtoupper(substr($student['full_name'], 0, 1)); ?></span>
                <strong><?php echo htmlspecialchars(strtoupper($student['full_name'])); ?></strong>
                &nbsp;·&nbsp; <?php echo htmlspecialchars($student['class']); ?>
                &nbsp;·&nbsp; <?php echo htmlspecialchars($term); ?> Term <?php echo htmlspecialchars($session); ?>
            </div>
        </div>

        <div class="grid">
            <?php foreach ($templates as $key => $tpl): ?>
                <a class="card" href="?student_id=<?php echo $sid; ?>&session=<?php echo $s; ?>&term=<?php echo $t; ?>&template=<?php echo $key; ?>">
                    <div class="card-preview preview-<?php echo $key; ?>">
                        <?php echo $tpl['icon']; ?>
                        <div class="stripe" style="background:<?php echo $tpl['accent']; ?>;"></div>
                    </div>
                    <div class="card-body">
                        <h3><?php echo $tpl['name']; ?></h3>
                        <p><?php echo $tpl['desc']; ?></p>
                        <span class="cta" style="background:<?php echo $tpl['bg']; ?>;color:<?php echo $tpl['accent']; ?>;">
                            Use <?php echo $tpl['name']; ?> →
                        </span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

    </body>

    </html>
<?php
    return ob_get_clean();
}

// ─── Show picker if no template chosen ────────────────────────────────────────
$valid_templates = ['classic', 'modern', 'elegant', 'minimal', 'bold'];
if (!in_array($template, $valid_templates)) {
    echo showTemplatePicker($student, $session, $term, $primary_color, $secondary_color, $school_name, $school_logo);
    exit;
}


// ═══════════════════════════════════════════════════════════════════════════════
// BUILD DATA ARRAYS used by all templates
// ═══════════════════════════════════════════════════════════════════════════════
$affective_traits = [
    'punctuality'  => 'Punctuality',
    'neatness'     => 'Neatness',
    'honesty'      => 'Honesty',
    'reliability'  => 'Reliability',
    'relationship' => 'Relationship',
    'politeness'   => 'Politeness',
    'self_control' => 'Self Control',
];
$psychomotor_skills = [
    'handwriting'     => 'Handwriting',
    'verbal_fluency'  => 'Verbal Fluency',
    'sports'          => 'Sports',
    'handling_tools'  => 'Handling Tools',
    'drawing_painting' => 'Drawing/Painting',
    'musical_skills'  => 'Musical Skills',
];

$show_pos     = !empty($settings['show_class_position']);
$show_sub_pos = !empty($settings['show_subject_position']);
$show_promo   = !empty($settings['show_promoted_to']);
$show_hilo    = !empty($settings['show_lowest_highest_avg']);
$show_hilo_cls = !empty($settings['show_lowest_highest_class']);

$overall_grade = getGrade($overall_percentage, $settings['grading_system']);

// ═══════════════════════════════════════════════════════════════════════════════
// TEMPLATE: CLASSIC
// ═══════════════════════════════════════════════════════════════════════════════
function tpl_classic($d)
{
    extract($d);
    ob_start();
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <title>Report Card – <?php echo htmlspecialchars($student['full_name']); ?></title>
        <link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@400;600;700&family=Source+Sans+3:wght@400;600&display=swap" rel="stylesheet">
        <style>
            @media print {
                .no-print {
                    display: none !important
                }

                body {
                    padding: 0
                }

                .page {
                    padding: 14mm 12mm
                }
            }

            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0
            }

            body {
                font-family: 'Source Sans 3', sans-serif;
                font-size: 9pt;
                background: #f4f4f0;
                padding: 20px
            }

            .page {
                max-width: 210mm;
                margin: 0 auto;
                background: #fff;
                padding: 16mm 14mm;
                border: 1px solid #ccc
            }

            /* header */
            .hdr {
                display: flex;
                align-items: center;
                gap: 14px;
                border-bottom: 3px double <?php echo $pc; ?>;
                padding-bottom: 10px;
                margin-bottom: 8px
            }

            .hdr-logo {
                flex-shrink: 0
            }

            .hdr-info {
                flex: 1;
                text-align: center
            }

            .hdr-info .sname {
                font-family: 'Source Serif 4', serif;
                font-size: 17pt;
                font-weight: 700;
                color: <?php echo $pc; ?>
            }

            .hdr-info .motto {
                font-style: italic;
                font-size: 8pt;
                color: #555;
                margin-top: 2px
            }

            .hdr-info .sdetails {
                font-size: 7.5pt;
                color: #666;
                margin-top: 3px
            }

            .hdr-photo {
                flex-shrink: 0
            }

            /* section bar */
            .sec {
                background: <?php echo $pc; ?>;
                color: #fff;
                text-align: center;
                padding: 4px;
                font-weight: 600;
                font-size: 8.5pt;
                margin: 10px 0 5px;
                letter-spacing: .05em
            }

            /* info table */
            table.info {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 6px
            }

            table.info td {
                border: 1px solid #aaa;
                padding: 4px 7px;
                font-size: 8.5pt;
                vertical-align: middle
            }

            table.info .lbl {
                background: #f0ede8;
                font-weight: 600;
                width: 13%;
                white-space: nowrap
            }

            /* scores */
            table.scores {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 6px
            }

            table.scores th {
                background: <?php echo $pc; ?>;
                color: #fff;
                padding: 5px 6px;
                font-size: 8pt;
                border: 1px solid <?php echo $pc; ?>
            }

            table.scores td {
                border: 1px solid #bbb;
                padding: 4px 6px;
                font-size: 8pt;
                text-align: center
            }

            table.scores td.sname {
                text-align: left
            }

            /* traits */
            .traits-wrap {
                display: flex;
                gap: 10px
            }

            .trait-tbl {
                flex: 1
            }

            table.trait {
                width: 100%;
                border-collapse: collapse
            }

            table.trait td {
                border: 1px solid #bbb;
                padding: 3px 6px;
                font-size: 8pt
            }

            table.trait .lbl {
                background: #f0ede8;
                font-weight: 600
            }

            /* summary */
            table.summary {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 6px
            }

            table.summary td {
                border: 1px solid #bbb;
                padding: 4px 8px;
                font-size: 8.5pt;
                text-align: center
            }

            table.summary .big {
                font-size: 12pt;
                font-weight: 700;
                color: <?php echo $pc; ?>
            }

            /* comments */
            .cmt-box {
                border: 1px solid #bbb;
                padding: 7px 8px;
                margin-bottom: 6px;
                font-size: 8.5pt;
                min-height: 36px
            }

            .cmt-box strong {
                display: block;
                margin-bottom: 3px;
                color: <?php echo $pc; ?>
            }

            /* footer */
            .ftr {
                text-align: center;
                font-size: 7.5pt;
                color: #888;
                margin-top: 10px;
                padding-top: 7px;
                border-top: 1px solid #ccc
            }

            /* action buttons */
            .actions {
                text-align: center;
                margin-bottom: 18px;
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
                justify-content: center
            }

            .btn {
                padding: 8px 18px;
                border-radius: 6px;
                font-size: 11px;
                font-weight: 600;
                cursor: pointer;
                text-decoration: none;
                border: none;
                display: inline-flex;
                align-items: center;
                gap: 5px
            }

            .btn-print {
                background: #2563eb;
                color: #fff
            }

            .btn-pdf {
                background: #dc2626;
                color: #fff
            }

            .btn-back {
                background: #64748b;
                color: #fff
            }

            .btn-tpl {
                background: <?php echo $pc; ?>;
                color: #fff
            }

            .grade-good {
                color: #16a34a;
                font-weight: 700
            }

            .grade-ok {
                color: #d97706;
                font-weight: 700
            }

            .grade-bad {
                color: #dc2626;
                font-weight: 700
            }
        </style>
    </head>

    <body>

        <div class="no-print actions">
            <a href="?student_id=<?php echo $student['id']; ?>&session=<?php echo urlencode($session); ?>&term=<?php echo urlencode($term); ?>" class="btn btn-tpl">⊞ Change Template</a>
            <button class="btn btn-print" onclick="window.print()">🖨 Print</button>
            <a href="?student_id=<?php echo $student['id']; ?>&session=<?php echo urlencode($session); ?>&term=<?php echo urlencode($term); ?>&template=<?php echo $template; ?>&format=pdf" class="btn btn-pdf">⬇ Download PDF</a>
            <a href="report_cards.php" class="btn btn-back">← Back</a>
        </div>

        <div class="page">

            <!-- Header -->
            <div class="hdr">
                <div class="hdr-logo"><?php echo logoTag($school_logo, 65); ?></div>
                <div class="hdr-info">
                    <div class="sname"><?php echo htmlspecialchars($school_name); ?></div>
                    <?php if ($school_motto): ?><div class="motto">"<?php echo htmlspecialchars($school_motto); ?>"</div><?php endif; ?>
                    <div class="sdetails">
                        <?php echo htmlspecialchars($school_phone); ?>
                        <?php if ($school_phone && $school_email): ?> &nbsp;|&nbsp; <?php endif; ?>
                        <?php echo htmlspecialchars($school_email); ?>
                    </div>
                </div>
                <div class="hdr-photo"><?php echo photoTag($student, 75); ?></div>
            </div>

            <div style="text-align:center;font-weight:700;font-size:10.5pt;letter-spacing:.08em;color:<?php echo $pc; ?>;margin-bottom:8px">
                <?php echo strtoupper($term); ?> TERM REPORT CARD &nbsp;–&nbsp; <?php echo htmlspecialchars($session); ?> SESSION
            </div>

            <!-- Student Info -->
            <table class="info">
                <tr>
                    <td class="lbl">Student Name</td>
                    <td colspan="3"><strong><?php echo strtoupper(htmlspecialchars($student['full_name'])); ?></strong></td>
                    <td class="lbl">Admission No.</td>
                    <td><?php echo htmlspecialchars($student['admission_number']); ?></td>
                </tr>
                <tr>
                    <td class="lbl">Class</td>
                    <td><?php echo htmlspecialchars($student['class']); ?></td>
                    <td class="lbl">Gender</td>
                    <td><?php echo ucfirst($student['gender'] ?? 'N/A'); ?></td>
                    <td class="lbl">Age</td>
                    <td><?php echo $age_display ?: 'N/A'; ?></td>
                </tr>
                <tr>
                    <td class="lbl">Days Opened</td>
                    <td><?php echo $days_school_opened; ?></td>
                    <td class="lbl">Days Present</td>
                    <td><?php echo $days_present; ?></td>
                    <td class="lbl">Days Absent</td>
                    <td><?php echo $days_absent; ?></td>
                </tr>
                <tr>
                    <td class="lbl">Attendance</td>
                    <td><?php echo $attendance_pct; ?>%</td>
                    <td class="lbl">Term</td>
                    <td><?php echo htmlspecialchars($term); ?></td>
                    <td class="lbl">Session</td>
                    <td><?php echo htmlspecialchars($session); ?></td>
                </tr>
            </table>

            <!-- Scores -->
            <div class="sec">ACADEMIC PERFORMANCE</div>
            <table class="scores">
                <thead>
                    <tr>
                        <th style="text-align:left;width:22%">Subject</th>
                        <?php foreach ($score_types as $st): ?><th><?php echo htmlspecialchars($st['name']); ?></th><?php endforeach; ?>
                        <th>Total</th>
                        <th>%</th>
                        <th>Grade</th>
                        <?php if ($show_sub_pos): ?><th>Pos.</th><?php endif; ?>
                        <th>Remark</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1;
                    foreach ($scores as $sc):
                        $sd   = json_decode($sc['score_data'], true);
                        $perc = $sc['percentage'];
                        $gr   = getGrade($perc, $settings['grading_system']);
                        $gc   = $perc >= 60 ? 'grade-good' : ($perc >= 45 ? 'grade-ok' : 'grade-bad');
                    ?>
                        <tr>
                            <td class="sname"><?php echo $i++ . '. ' . htmlspecialchars($sc['subject_name']); ?></td>
                            <?php foreach ($score_types as $st): ?>
                                <td><?php echo isset($sd[$st['name']]) ? number_format($sd[$st['name']], 1) : '—'; ?></td>
                            <?php endforeach; ?>
                            <td><strong><?php echo number_format($sc['total_score'], 1); ?></strong></td>
                            <td><?php echo number_format($perc, 1); ?></td>
                            <td class="<?php echo $gc; ?>"><?php echo $gr; ?></td>
                            <?php if ($show_sub_pos): ?><td><?php echo ordinal($sc['subject_position'] ?? '—'); ?></td><?php endif; ?>
                            <td><?php echo getRemark($perc); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <!-- empty rows to fill up to 15 subjects -->
                    <?php for ($j = count($scores) + 1; $j <= 15; $j++): ?>
                        <tr>
                            <td class="sname" style="color:#bbb"><?php echo $j; ?>.</td>
                            <?php foreach ($score_types as $st): ?><td>—</td><?php endforeach; ?>
                            <td>—</td>
                            <td>—</td>
                            <td>—</td>
                            <?php if ($show_sub_pos): ?><td>—</td><?php endif; ?>
                            <td>—</td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>

            <!-- Summary -->
            <table class="summary">
                <tr>
                    <td>Total Marks<br><span class="big"><?php echo number_format($total_marks, 1); ?></span></td>
                    <td>Average<br><span class="big"><?php echo number_format($overall_average, 1); ?></span></td>
                    <td>Overall %<br><span class="big"><?php echo number_format($overall_percentage, 1); ?>%</span></td>
                    <td>Grade<br><span class="big" style="color:<?php echo gradeColor($overall_grade); ?>"><?php echo $overall_grade; ?></span></td>
                    <?php if ($show_pos): ?>
                        <td>Class Position<br><span class="big"><?php echo ordinal($position['class_position'] ?? '—'); ?></span></td>
                        <td>Out of<br><span class="big"><?php echo $class_total; ?></span></td>
                    <?php endif; ?>
                    <?php if ($show_hilo || $show_hilo_cls): ?>
                        <td>Highest Avg<br><span class="big"><?php echo number_format($highest_average, 1); ?></span></td>
                        <td>Lowest Avg<br><span class="big"><?php echo number_format($lowest_average, 1); ?></span></td>
                    <?php endif; ?>
                </tr>
            </table>

            <!-- Traits & Skills -->
            <div class="sec">BEHAVIOURAL ASSESSMENT</div>
            <div class="traits-wrap">
                <div class="trait-tbl">
                    <div style="font-weight:700;font-size:8pt;margin-bottom:4px;color:<?php echo $pc; ?>">AFFECTIVE TRAITS</div>
                    <table class="trait">
                        <tbody>
                            <?php foreach ($affective_traits as $key => $label):
                                $val = strtoupper($affective[$key] ?? ''); ?>
                                <tr>
                                    <td class="lbl"><?php echo $label; ?></td>
                                    <td><strong><?php echo $val ?: '—'; ?></strong><?php if ($val): ?> – <?php echo traitLabel($val); ?><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="trait-tbl">
                    <div style="font-weight:700;font-size:8pt;margin-bottom:4px;color:<?php echo $pc; ?>">PSYCHOMOTOR SKILLS</div>
                    <table class="trait">
                        <tbody>
                            <?php foreach ($psychomotor_skills as $key => $label):
                                $val = strtoupper($psychomotor[$key] ?? ''); ?>
                                <tr>
                                    <td class="lbl"><?php echo $label; ?></td>
                                    <td><strong><?php echo $val ?: '—'; ?></strong><?php if ($val): ?> – <?php echo traitLabel($val); ?><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div style="font-size:7.5pt;color:#888;margin:5px 0 8px;text-align:center">
                A – Excellent &nbsp;|&nbsp; B – Very Good &nbsp;|&nbsp; C – Good &nbsp;|&nbsp; D – Average &nbsp;|&nbsp; E – Poor
            </div>

            <!-- Comments -->
            <div class="sec">COMMENTS</div>
            <div class="cmt-box"><strong>Class Teacher (<?php echo htmlspecialchars($comments['class_teachers_name'] ?? ''); ?>):</strong>
                <?php echo nl2br(htmlspecialchars($comments['teachers_comment'] ?? 'No comment.')); ?></div>
            <div class="cmt-box"><strong>Principal (<?php echo htmlspecialchars($comments['principals_name'] ?? ''); ?>):</strong>
                <?php echo nl2br(htmlspecialchars($comments['principals_comment'] ?? 'No comment.')); ?></div>

            <?php if ($show_promo && !empty($position['promoted_to'])): ?>
                <div style="text-align:center;font-weight:700;font-size:9.5pt;margin:8px 0;color:<?php echo $pc; ?>">
                    Promoted to: <?php echo htmlspecialchars($position['promoted_to']); ?>
                </div>
            <?php endif; ?>

            <!-- Footer -->
            <div class="ftr">
                <strong>Next Term Resumes:</strong>
                <?php echo !empty($settings['next_resumption_date']) ? date('F j, Y', strtotime($settings['next_resumption_date'])) : 'TBA'; ?>
                &nbsp;&nbsp;|&nbsp;&nbsp;
                Generated: <?php echo date('F j, Y \a\t g:i A'); ?>
            </div>

        </div>
    </body>

    </html>
<?php
    return ob_get_clean();
}


// ═══════════════════════════════════════════════════════════════════════════════
// TEMPLATE: MODERN
// ═══════════════════════════════════════════════════════════════════════════════
function tpl_modern($d)
{
    extract($d);
    ob_start();
    // Derive lighter tint for header bar
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <title>Report Card – <?php echo htmlspecialchars($student['full_name']); ?></title>
        <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@500&display=swap" rel="stylesheet">
        <style>
            @media print {
                .no-print {
                    display: none !important
                }

                body {
                    background: #fff;
                    padding: 0
                }

                .page {
                    box-shadow: none
                }
            }

            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0
            }

            body {
                font-family: 'DM Sans', sans-serif;
                font-size: 9pt;
                background: #e8ecf0;
                padding: 24px
            }

            .page {
                max-width: 210mm;
                margin: 0 auto;
                background: #fff;
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 4px 30px rgba(0, 0, 0, .12)
            }

            /* top bar */
            .top-bar {
                background: <?php echo $pc; ?>;
                padding: 20px 24px;
                display: flex;
                align-items: center;
                gap: 16px
            }

            .top-bar-info {
                flex: 1;
                color: #fff
            }

            .top-bar-info .sname {
                font-size: 15pt;
                font-weight: 700;
                letter-spacing: -.01em
            }

            .top-bar-info .smeta {
                font-size: 8pt;
                opacity: .8;
                margin-top: 3px
            }

            .top-bar-photo {
                border: 3px solid rgba(255, 255, 255, .35);
                border-radius: 8px;
                overflow: hidden
            }

            /* accent strip */
            .accent-strip {
                background: <?php echo $sc; ?>;
                height: 5px
            }

            /* content */
            .content {
                padding: 18px 24px
            }

            /* card grid */
            .info-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 10px;
                margin-bottom: 16px
            }

            .info-card {
                background: #f8fafc;
                border-radius: 8px;
                padding: 10px 12px;
                border-left: 3px solid <?php echo $pc; ?>
            }

            .info-card .key {
                font-size: 7pt;
                text-transform: uppercase;
                letter-spacing: .08em;
                color: #94a3b8;
                font-weight: 600
            }

            .info-card .val {
                font-size: 10pt;
                font-weight: 700;
                color: #1e293b;
                margin-top: 2px
            }

            /* section */
            .sec {
                display: flex;
                align-items: center;
                gap: 8px;
                margin: 16px 0 8px
            }

            .sec-line {
                flex: 1;
                height: 1px;
                background: #e2e8f0
            }

            .sec-label {
                font-size: 8pt;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .1em;
                color: <?php echo $pc; ?>;
                white-space: nowrap
            }

            /* score table */
            table.scores {
                width: 100%;
                border-collapse: collapse
            }

            table.scores th {
                background: #1e293b;
                color: #fff;
                padding: 6px 8px;
                font-size: 7.5pt;
                font-weight: 600;
                text-align: center
            }

            table.scores th:first-child {
                text-align: left
            }

            table.scores td {
                padding: 5px 8px;
                font-size: 8.5pt;
                border-bottom: 1px solid #f1f5f9;
                text-align: center
            }

            table.scores td:first-child {
                text-align: left
            }

            table.scores tr:nth-child(even) td {
                background: #f8fafc
            }

            .badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 20px;
                font-size: 7.5pt;
                font-weight: 700
            }

            .badge-A,
            .badge-A1,
            .badge-B2,
            .badge-B3 {
                background: #dcfce7;
                color: #166534
            }

            .badge-B {
                background: #dbeafe;
                color: #1e40af
            }

            .badge-C,
            .badge-C4,
            .badge-C5,
            .badge-C6 {
                background: #fef9c3;
                color: #854d0e
            }

            .badge-D,
            .badge-D7,
            .badge-E8 {
                background: #ffedd5;
                color: #9a3412
            }

            .badge-E,
            .badge-F,
            .badge-F9 {
                background: #fee2e2;
                color: #991b1b
            }

            /* summary cards */
            .sum-row {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 10px;
                margin-bottom: 16px
            }

            .sum-card {
                background: <?php echo $pc; ?>;
                color: #fff;
                border-radius: 10px;
                padding: 12px;
                text-align: center
            }

            .sum-card .sk {
                font-size: 7pt;
                opacity: .75;
                text-transform: uppercase;
                letter-spacing: .07em
            }

            .sum-card .sv {
                font-size: 14pt;
                font-weight: 700;
                margin-top: 4px
            }

            /* traits */
            .trait-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 16px
            }

            .trait-list {}

            .trait-list h4 {
                font-size: 8pt;
                text-transform: uppercase;
                letter-spacing: .09em;
                color: #64748b;
                font-weight: 700;
                margin-bottom: 8px
            }

            .trait-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 5px 0;
                border-bottom: 1px solid #f1f5f9;
                font-size: 8.5pt
            }

            .trait-val {
                font-weight: 700;
                font-size: 9pt;
                color: <?php echo $pc; ?>
            }

            /* comments */
            .cmt-card {
                background: #f8fafc;
                border-radius: 8px;
                padding: 12px 14px;
                margin-bottom: 10px;
                font-size: 8.5pt;
                border-left: 3px solid <?php echo $sc; ?>
            }

            .cmt-card strong {
                display: block;
                margin-bottom: 4px;
                font-size: 8pt;
                text-transform: uppercase;
                letter-spacing: .06em;
                color: #64748b
            }

            /* footer */
            .ftr {
                background: #f8fafc;
                padding: 12px 24px;
                display: flex;
                justify-content: space-between;
                font-size: 7.5pt;
                color: #94a3b8;
                border-top: 1px solid #e2e8f0
            }

            /* actions */
            .actions {
                text-align: center;
                margin-bottom: 20px;
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
                justify-content: center
            }

            .btn {
                padding: 9px 20px;
                border-radius: 8px;
                font-size: 11px;
                font-weight: 600;
                cursor: pointer;
                text-decoration: none;
                border: none;
                display: inline-flex;
                align-items: center;
                gap: 5px
            }

            .btn-print {
                background: #1e293b;
                color: #fff
            }

            .btn-pdf {
                background: #dc2626;
                color: #fff
            }

            .btn-back {
                background: #94a3b8;
                color: #fff
            }

            .btn-tpl {
                background: <?php echo $pc; ?>;
                color: #fff
            }
        </style>
    </head>

    <body>

        <div class="no-print actions">
            <a href="?student_id=<?php echo $student['id']; ?>&session=<?php echo urlencode($session); ?>&term=<?php echo urlencode($term); ?>" class="btn btn-tpl">⊞ Change Template</a>
            <button class="btn btn-print" onclick="window.print()">🖨 Print</button>
            <a href="?student_id=<?php echo $student['id']; ?>&session=<?php echo urlencode($session); ?>&term=<?php echo urlencode($term); ?>&template=<?php echo $template; ?>&format=pdf" class="btn btn-pdf">⬇ Download PDF</a>
            <a href="report_cards.php" class="btn btn-back">← Back</a>
        </div>

        <div class="page">
            <div class="top-bar">
                <?php echo logoTag($school_logo, 58); ?>
                <div class="top-bar-info">
                    <div class="sname"><?php echo htmlspecialchars($school_name); ?></div>
                    <div class="smeta">
                        <?php if ($school_motto): ?>"<?php echo htmlspecialchars($school_motto); ?>" &nbsp;·&nbsp; <?php endif; ?>
                    <?php echo htmlspecialchars($school_phone); ?>
                    <?php if ($school_phone && $school_email): ?> &nbsp;·&nbsp; <?php endif; ?>
                    <?php echo htmlspecialchars($school_email); ?>
                    </div>
                    <div class="smeta" style="margin-top:6px;font-size:9pt;font-weight:600;opacity:1">
                        <?php echo strtoupper($term); ?> TERM REPORT · <?php echo htmlspecialchars($session); ?>
                    </div>
                </div>
                <div class="top-bar-photo"><?php echo photoTag($student, 72); ?></div>
            </div>
            <div class="accent-strip"></div>

            <div class="content">

                <div class="info-grid">
                    <?php
                    $infos = [
                        'Student Name'   => strtoupper($student['full_name']),
                        'Admission No.'  => $student['admission_number'],
                        'Class'          => $student['class'],
                        'Gender'         => ucfirst($student['gender'] ?? 'N/A'),
                        'Age'            => $age_display ?: 'N/A',
                        'Attendance'     => $attendance_pct . '%  (' . $days_present . '/' . $days_school_opened . ' days)',
                    ];
                    foreach ($infos as $k => $v): ?>
                        <div class="info-card">
                            <div class="key"><?php echo $k; ?></div>
                            <div class="val"><?php echo htmlspecialchars($v); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="sec">
                    <div class="sec-label">Academic Performance</div>
                    <div class="sec-line"></div>
                </div>

                <table class="scores">
                    <thead>
                        <tr>
                            <th style="text-align:left">Subject</th>
                            <?php foreach ($score_types as $st): ?><th><?php echo htmlspecialchars($st['name']); ?></th><?php endforeach; ?>
                            <th>Total</th>
                            <th>%</th>
                            <th>Grade</th>
                            <?php if ($show_sub_pos): ?><th>Pos.</th><?php endif; ?>
                            <th>Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1;
                        foreach ($scores as $sc):
                            $sd   = json_decode($sc['score_data'], true);
                            $perc = $sc['percentage'];
                            $gr   = getGrade($perc, $settings['grading_system']);
                            $bc   = 'badge-' . str_replace('+', 'plus', $gr);
                        ?>
                            <tr>
                                <td><?php echo $i++ . '. ' . htmlspecialchars($sc['subject_name']); ?></td>
                                <?php foreach ($score_types as $st): ?>
                                    <td><?php echo isset($sd[$st['name']]) ? number_format($sd[$st['name']], 1) : '—'; ?></td>
                                <?php endforeach; ?>
                                <td><strong><?php echo number_format($sc['total_score'], 1); ?></strong></td>
                                <td><?php echo number_format($perc, 1); ?></td>
                                <td><span class="badge <?php echo $bc; ?>"><?php echo $gr; ?></span></td>
                                <?php if ($show_sub_pos): ?><td><?php echo ordinal($sc['subject_position'] ?? '—'); ?></td><?php endif; ?>
                                <td><?php echo getRemark($perc); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Summary -->
                <div class="sec" style="margin-top:18px">
                    <div class="sec-label">Summary</div>
                    <div class="sec-line"></div>
                </div>
                <div class="sum-row">
                    <div class="sum-card">
                        <div class="sk">Total Marks</div>
                        <div class="sv"><?php echo number_format($total_marks, 1); ?></div>
                    </div>
                    <div class="sum-card">
                        <div class="sk">Average</div>
                        <div class="sv"><?php echo number_format($overall_average, 1); ?></div>
                    </div>
                    <div class="sum-card">
                        <div class="sk">Overall %</div>
                        <div class="sv"><?php echo number_format($overall_percentage, 1); ?>%</div>
                    </div>
                    <?php if ($show_pos): ?>
                        <div class="sum-card">
                            <div class="sk">Class Position</div>
                            <div class="sv"><?php echo ordinal($position['class_position'] ?? '—'); ?> / <?php echo $class_total; ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($show_hilo || $show_hilo_cls): ?>
                        <div class="sum-card">
                            <div class="sk">Highest Avg</div>
                            <div class="sv"><?php echo number_format($highest_average, 1); ?></div>
                        </div>
                        <div class="sum-card">
                            <div class="sk">Lowest Avg</div>
                            <div class="sv"><?php echo number_format($lowest_average, 1); ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Traits -->
                <div class="sec">
                    <div class="sec-label">Behavioural Assessment</div>
                    <div class="sec-line"></div>
                </div>
                <div class="trait-grid">
                    <div class="trait-list">
                        <h4>Affective Traits</h4>
                        <?php foreach ($affective_traits as $key => $label):
                            $val = strtoupper($affective[$key] ?? ''); ?>
                            <div class="trait-row">
                                <span><?php echo $label; ?></span>
                                <span class="trait-val"><?php echo $val ?: '—'; ?><?php if ($val): ?> <small style="font-weight:400;font-size:7.5pt;color:#94a3b8"><?php echo traitLabel($val); ?></small><?php endif; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="trait-list">
                        <h4>Psychomotor Skills</h4>
                        <?php foreach ($psychomotor_skills as $key => $label):
                            $val = strtoupper($psychomotor[$key] ?? ''); ?>
                            <div class="trait-row">
                                <span><?php echo $label; ?></span>
                                <span class="trait-val"><?php echo $val ?: '—'; ?><?php if ($val): ?> <small style="font-weight:400;font-size:7.5pt;color:#94a3b8"><?php echo traitLabel($val); ?></small><?php endif; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div style="font-size:7pt;color:#94a3b8;margin:6px 0 14px;text-align:center">A=Excellent &nbsp; B=Very Good &nbsp; C=Good &nbsp; D=Average &nbsp; E=Poor</div>

                <!-- Comments -->
                <div class="sec">
                    <div class="sec-label">Comments</div>
                    <div class="sec-line"></div>
                </div>
                <div class="cmt-card"><strong>Class Teacher <?php if (!empty($comments['class_teachers_name'])): ?>(<?php echo htmlspecialchars($comments['class_teachers_name']); ?>)<?php endif; ?></strong>
                    <?php echo nl2br(htmlspecialchars($comments['teachers_comment'] ?? 'No comment.')); ?></div>
                <div class="cmt-card"><strong>Principal <?php if (!empty($comments['principals_name'])): ?>(<?php echo htmlspecialchars($comments['principals_name']); ?>)<?php endif; ?></strong>
                    <?php echo nl2br(htmlspecialchars($comments['principals_comment'] ?? 'No comment.')); ?></div>

                <?php if ($show_promo && !empty($position['promoted_to'])): ?>
                    <div style="text-align:center;font-weight:700;font-size:9.5pt;color:<?php echo $pc; ?>;margin:10px 0">
                        ✓ Promoted to: <?php echo htmlspecialchars($position['promoted_to']); ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="ftr">
                <span>Next Term Resumes: <strong><?php echo !empty($settings['next_resumption_date']) ? date('F j, Y', strtotime($settings['next_resumption_date'])) : 'TBA'; ?></strong></span>
                <span>Generated: <?php echo date('F j, Y \a\t g:i A'); ?></span>
            </div>
        </div>
    </body>

    </html>
<?php
    return ob_get_clean();
}


// ═══════════════════════════════════════════════════════════════════════════════
// TEMPLATE: ELEGANT
// ═══════════════════════════════════════════════════════════════════════════════
function tpl_elegant($d)
{
    extract($d);
    $gold = '#b8882a';
    ob_start();
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <title>Report Card – <?php echo htmlspecialchars($student['full_name']); ?></title>
        <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
        <style>
            @media print {
                .no-print {
                    display: none !important
                }

                body {
                    background: #fff;
                    padding: 0
                }
            }

            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0
            }

            body {
                font-family: 'Jost', sans-serif;
                font-size: 9pt;
                background: #f9f6f0;
                padding: 24px
            }

            .page {
                max-width: 210mm;
                margin: 0 auto;
                background: #fff;
                padding: 16mm 15mm;
                border: 1px solid #ddd
            }

            /* decorative rules */
            .rule-gold {
                height: 2px;
                background: linear-gradient(90deg, transparent, <?php echo $gold; ?>, transparent);
                margin: 8px 0
            }

            .rule-thin {
                height: 1px;
                background: linear-gradient(90deg, transparent, #ccc, transparent);
                margin: 6px 0
            }

            /* header */
            .hdr {
                display: flex;
                align-items: flex-start;
                gap: 16px;
                margin-bottom: 6px
            }

            .hdr-center {
                flex: 1;
                text-align: center
            }

            .hdr-center .sname {
                font-family: 'Cormorant Garamond', serif;
                font-size: 22pt;
                font-weight: 700;
                color: #1a1209;
                letter-spacing: .02em
            }

            .hdr-center .motto {
                font-family: 'Cormorant Garamond', serif;
                font-style: italic;
                font-size: 10pt;
                color: <?php echo $gold; ?>;
                margin-top: 4px
            }

            .hdr-center .details {
                font-size: 7.5pt;
                color: #888;
                letter-spacing: .05em;
                margin-top: 4px
            }

            .hdr-center .title {
                font-size: 9pt;
                font-weight: 600;
                letter-spacing: .18em;
                text-transform: uppercase;
                color: #333;
                margin-top: 8px
            }

            /* info table */
            .info-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 0
            }

            .info-table td {
                padding: 5px 8px;
                font-size: 8.5pt;
                border: 1px solid #e8e0d0
            }

            .info-table .lbl {
                background: #faf7f2;
                font-weight: 600;
                color: #5c4b1a;
                font-size: 8pt;
                letter-spacing: .03em;
                width: 14%
            }

            /* section heading */
            .sec-hdr {
                text-align: center;
                margin: 14px 0 7px
            }

            .sec-hdr span {
                font-family: 'Cormorant Garamond', serif;
                font-size: 12pt;
                font-weight: 600;
                letter-spacing: .15em;
                text-transform: uppercase;
                color: #1a1209
            }

            /* scores */
            table.scores {
                width: 100%;
                border-collapse: collapse
            }

            table.scores thead tr th {
                background: #1a1209;
                color: #e8d89a;
                padding: 6px 7px;
                font-size: 7.5pt;
                font-weight: 600;
                letter-spacing: .06em;
                text-transform: uppercase;
                text-align: center
            }

            table.scores thead tr th:first-child {
                text-align: left
            }

            table.scores tbody tr td {
                padding: 4px 7px;
                border-bottom: 1px solid #f0ebe0;
                font-size: 8.5pt;
                text-align: center
            }

            table.scores tbody tr td:first-child {
                text-align: left
            }

            table.scores tbody tr:nth-child(even) td {
                background: #fdfaf4
            }

            .gr {
                font-weight: 700;
                font-family: 'Cormorant Garamond', serif;
                font-size: 11pt
            }

            /* summary band */
            .sum-band {
                background: #1a1209;
                color: #e8d89a;
                display: flex;
                justify-content: space-around;
                padding: 10px 0;
                margin: 12px 0;
                border-radius: 2px
            }

            .sum-item {
                text-align: center
            }

            .sum-item .sk {
                font-size: 7pt;
                text-transform: uppercase;
                letter-spacing: .1em;
                opacity: .7
            }

            .sum-item .sv {
                font-size: 13pt;
                font-family: 'Cormorant Garamond', serif;
                font-weight: 700;
                margin-top: 2px
            }

            /* traits */
            .traits-outer {
                display: flex;
                gap: 14px
            }

            .trait-col {
                flex: 1
            }

            .trait-col h4 {
                font-family: 'Cormorant Garamond', serif;
                font-size: 11pt;
                font-weight: 600;
                letter-spacing: .1em;
                text-transform: uppercase;
                color: #1a1209;
                margin-bottom: 6px
            }

            table.trait {
                width: 100%;
                border-collapse: collapse
            }

            table.trait td {
                border: 1px solid #e8e0d0;
                padding: 4px 7px;
                font-size: 8.5pt
            }

            table.trait .lbl {
                background: #faf7f2;
                font-weight: 500;
                color: #5c4b1a;
                width: 55%
            }

            table.trait .val {
                font-weight: 700;
                color: #1a1209;
                font-family: 'Cormorant Garamond', serif;
                font-size: 10pt
            }

            /* comments */
            .cmt {
                border: 1px solid #e8e0d0;
                padding: 9px 10px;
                margin-bottom: 8px;
                min-height: 38px;
                font-size: 8.5pt
            }

            .cmt .who {
                font-weight: 600;
                font-size: 7.5pt;
                letter-spacing: .07em;
                text-transform: uppercase;
                color: <?php echo $gold; ?>;
                margin-bottom: 4px
            }

            .ftr {
                text-align: center;
                font-size: 7.5pt;
                color: #aaa;
                margin-top: 12px
            }

            .actions {
                text-align: center;
                margin-bottom: 20px;
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
                justify-content: center
            }

            .btn {
                padding: 9px 20px;
                border-radius: 6px;
                font-size: 11px;
                font-weight: 600;
                cursor: pointer;
                text-decoration: none;
                border: none;
                display: inline-flex;
                align-items: center;
                gap: 5px
            }

            .btn-print {
                background: #1a1209;
                color: #e8d89a
            }

            .btn-pdf {
                background: #dc2626;
                color: #fff
            }

            .btn-back {
                background: #94a3b8;
                color: #fff
            }

            .btn-tpl {
                background: <?php echo $gold; ?>;
                color: #fff
            }
        </style>
    </head>

    <body>

        <div class="no-print actions">
            <a href="?student_id=<?php echo $student['id']; ?>&session=<?php echo urlencode($session); ?>&term=<?php echo urlencode($term); ?>" class="btn btn-tpl">⊞ Change Template</a>
            <button class="btn btn-print" onclick="window.print()">🖨 Print</button>
            <a href="?student_id=<?php echo $student['id']; ?>&session=<?php echo urlencode($session); ?>&term=<?php echo urlencode($term); ?>&template=<?php echo $template; ?>&format=pdf" class="btn btn-pdf">⬇ Download PDF</a>
            <a href="report_cards.php" class="btn btn-back">← Back</a>
        </div>

        <div class="page">
            <div class="hdr">
                <?php echo logoTag($school_logo, 68); ?>
                <div class="hdr-center">
                    <div class="sname"><?php echo htmlspecialchars($school_name); ?></div>
                    <?php if ($school_motto): ?><div class="motto"><?php echo htmlspecialchars($school_motto); ?></div><?php endif; ?>
                    <div class="details"><?php echo htmlspecialchars($school_phone); ?><?php if ($school_phone && $school_email): ?> &nbsp;·&nbsp; <?php endif; ?><?php echo htmlspecialchars($school_email); ?></div>
                    <div class="title"><?php echo strtoupper($term); ?> Term Report Card &nbsp;·&nbsp; <?php echo htmlspecialchars($session); ?></div>
                </div>
                <?php echo photoTag($student, 68); ?>
            </div>
            <div class="rule-gold"></div>

            <table class="info-table" style="margin-bottom:4px">
                <tr>
                    <td class="lbl">Student</td>
                    <td colspan="3"><strong><?php echo strtoupper(htmlspecialchars($student['full_name'])); ?></strong></td>
                    <td class="lbl">Adm. No.</td>
                    <td><?php echo htmlspecialchars($student['admission_number']); ?></td>
                </tr>
                <tr>
                    <td class="lbl">Class</td>
                    <td><?php echo htmlspecialchars($student['class']); ?></td>
                    <td class="lbl">Gender</td>
                    <td><?php echo ucfirst($student['gender'] ?? 'N/A'); ?></td>
                    <td class="lbl">Age</td>
                    <td><?php echo $age_display ?: 'N/A'; ?></td>
                </tr>
                <tr>
                    <td class="lbl">Attendance</td>
                    <td><?php echo $attendance_pct; ?>%</td>
                    <td class="lbl">Days Present</td>
                    <td><?php echo $days_present; ?></td>
                    <td class="lbl">Days Absent</td>
                    <td><?php echo $days_absent; ?></td>
                </tr>
            </table>
            <div class="rule-thin"></div>

            <div class="sec-hdr"><span>Academic Performance</span></div>

            <table class="scores">
                <thead>
                    <tr>
                        <th style="text-align:left">Subject</th>
                        <?php foreach ($score_types as $st): ?><th><?php echo htmlspecialchars($st['name']); ?></th><?php endforeach; ?>
                        <th>Total</th>
                        <th>%</th>
                        <th>Grade</th>
                        <?php if ($show_sub_pos): ?><th>Pos.</th><?php endif; ?>
                        <th>Remark</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1;
                    foreach ($scores as $sc):
                        $sd   = json_decode($sc['score_data'], true);
                        $perc = $sc['percentage'];
                        $gr   = getGrade($perc, $settings['grading_system']);
                        $gc   = gradeColor($gr);
                    ?>
                        <tr>
                            <td><?php echo $i++ . '. ' . htmlspecialchars($sc['subject_name']); ?></td>
                            <?php foreach ($score_types as $st): ?>
                                <td><?php echo isset($sd[$st['name']]) ? number_format($sd[$st['name']], 1) : '—'; ?></td>
                            <?php endforeach; ?>
                            <td><strong><?php echo number_format($sc['total_score'], 1); ?></strong></td>
                            <td><?php echo number_format($perc, 1); ?></td>
                            <td class="gr" style="color:<?php echo $gc; ?>"><?php echo $gr; ?></td>
                            <?php if ($show_sub_pos): ?><td><?php echo ordinal($sc['subject_position'] ?? '—'); ?></td><?php endif; ?>
                            <td><?php echo getRemark($perc); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="sum-band">
                <div class="sum-item">
                    <div class="sk">Total Marks</div>
                    <div class="sv"><?php echo number_format($total_marks, 1); ?></div>
                </div>
                <div class="sum-item">
                    <div class="sk">Average</div>
                    <div class="sv"><?php echo number_format($overall_average, 1); ?></div>
                </div>
                <div class="sum-item">
                    <div class="sk">Overall %</div>
                    <div class="sv"><?php echo number_format($overall_percentage, 1); ?>%</div>
                </div>
                <?php if ($show_pos): ?>
                    <div class="sum-item">
                        <div class="sk">Position</div>
                        <div class="sv"><?php echo ordinal($position['class_position'] ?? '—'); ?> / <?php echo $class_total; ?></div>
                    </div>
                <?php endif; ?>
                <?php if ($show_hilo || $show_hilo_cls): ?>
                    <div class="sum-item">
                        <div class="sk">Class High</div>
                        <div class="sv"><?php echo number_format($highest_average, 1); ?></div>
                    </div>
                    <div class="sum-item">
                        <div class="sk">Class Low</div>
                        <div class="sv"><?php echo number_format($lowest_average, 1); ?></div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="rule-thin"></div>
            <div class="sec-hdr"><span>Behavioural Assessment</span></div>

            <div class="traits-outer">
                <div class="trait-col">
                    <h4>Affective Traits</h4>
                    <table class="trait">
                        <tbody>
                            <?php foreach ($affective_traits as $key => $label):
                                $val = strtoupper($affective[$key] ?? ''); ?>
                                <tr>
                                    <td class="lbl"><?php echo $label; ?></td>
                                    <td class="val"><?php echo $val ?: '—'; ?></td>
                                    <td style="font-size:8pt;color:#888"><?php echo $val ? traitLabel($val) : ''; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="trait-col">
                    <h4>Psychomotor Skills</h4>
                    <table class="trait">
                        <tbody>
                            <?php foreach ($psychomotor_skills as $key => $label):
                                $val = strtoupper($psychomotor[$key] ?? ''); ?>
                                <tr>
                                    <td class="lbl"><?php echo $label; ?></td>
                                    <td class="val"><?php echo $val ?: '—'; ?></td>
                                    <td style="font-size:8pt;color:#888"><?php echo $val ? traitLabel($val) : ''; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rule-thin" style="margin:10px 0"></div>
            <div class="sec-hdr"><span>Comments</span></div>

            <div class="cmt">
                <div class="who">Class Teacher <?php if (!empty($comments['class_teachers_name'])): ?>(<?php echo htmlspecialchars($comments['class_teachers_name']); ?>)<?php endif; ?></div>
                <?php echo nl2br(htmlspecialchars($comments['teachers_comment'] ?? 'No comment.')); ?>
            </div>
            <div class="cmt">
                <div class="who">Principal <?php if (!empty($comments['principals_name'])): ?>(<?php echo htmlspecialchars($comments['principals_name']); ?>)<?php endif; ?></div>
                <?php echo nl2br(htmlspecialchars($comments['principals_comment'] ?? 'No comment.')); ?>
            </div>

            <?php if ($show_promo && !empty($position['promoted_to'])): ?>
                <div style="text-align:center;font-family:'Cormorant Garamond',serif;font-size:11pt;font-weight:700;color:<?php echo $gold; ?>;margin:8px 0">
                    Promoted to: <?php echo htmlspecialchars($position['promoted_to']); ?>
                </div>
            <?php endif; ?>

            <div class="rule-gold"></div>
            <div class="ftr">
                Next Term Resumes: <?php echo !empty($settings['next_resumption_date']) ? date('F j, Y', strtotime($settings['next_resumption_date'])) : 'TBA'; ?>
                &nbsp;&nbsp;·&nbsp;&nbsp; Generated: <?php echo date('F j, Y \a\t g:i A'); ?>
            </div>
        </div>
    </body>

    </html>
<?php
    return ob_get_clean();
}


// ═══════════════════════════════════════════════════════════════════════════════
// TEMPLATE: MINIMAL
// ═══════════════════════════════════════════════════════════════════════════════
function tpl_minimal($d)
{
    extract($d);
    ob_start();
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <title>Report Card – <?php echo htmlspecialchars($student['full_name']); ?></title>
        <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:ital,wght@0,300;0,400;0,600;1,300&family=IBM+Plex+Mono:wght@400;600&display=swap" rel="stylesheet">
        <style>
            @media print {
                .no-print {
                    display: none !important
                }

                body {
                    background: #fff;
                    padding: 0
                }
            }

            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0
            }

            body {
                font-family: 'IBM Plex Sans', sans-serif;
                font-size: 9pt;
                background: #f5f5f5;
                padding: 24px;
                color: #111
            }

            .page {
                max-width: 210mm;
                margin: 0 auto;
                background: #fff;
                padding: 15mm 14mm
            }

            /* header */
            .hdr {
                display: flex;
                align-items: center;
                gap: 14px;
                margin-bottom: 16px
            }

            .hdr-mid {
                flex: 1
            }

            .hdr-mid .sname {
                font-size: 14pt;
                font-weight: 600;
                letter-spacing: -.01em
            }

            .hdr-mid .sub {
                font-size: 8pt;
                color: #888;
                margin-top: 3px;
                font-weight: 300
            }

            .hdr-mid .title {
                margin-top: 8px;
                font-size: 9pt;
                font-weight: 600;
                color: <?php echo $pc; ?>
            }

            /* divider */
            hr.thick {
                border: none;
                border-top: 2px solid #111;
                margin: 10px 0
            }

            hr.thin {
                border: none;
                border-top: 1px solid #ddd;
                margin: 8px 0
            }

            /* info */
            .info-row {
                display: flex;
                flex-wrap: wrap;
                gap: 0;
                border: 1px solid #ddd
            }

            .info-cell {
                flex: 1;
                min-width: 120px;
                padding: 6px 10px;
                border-right: 1px solid #ddd;
                border-bottom: 1px solid #ddd
            }

            .info-cell:last-child {
                border-right: none
            }

            .info-cell .ik {
                font-size: 7pt;
                text-transform: uppercase;
                letter-spacing: .09em;
                color: #999;
                font-weight: 400
            }

            .info-cell .iv {
                font-size: 9pt;
                font-weight: 600;
                margin-top: 2px
            }

            /* scores */
            table.scores {
                width: 100%;
                border-collapse: collapse;
                font-size: 8.5pt
            }

            table.scores thead tr {
                border-bottom: 2px solid #111
            }

            table.scores thead th {
                padding: 6px 7px;
                font-weight: 600;
                text-align: center;
                font-size: 8pt;
                text-transform: uppercase;
                letter-spacing: .05em
            }

            table.scores thead th:first-child {
                text-align: left
            }

            table.scores tbody td {
                padding: 5px 7px;
                border-bottom: 1px solid #f0f0f0;
                text-align: center
            }

            table.scores tbody td:first-child {
                text-align: left
            }

            .grd {
                font-family: 'IBM Plex Mono', monospace;
                font-weight: 600
            }

            /* summary */
            .sum-flex {
                display: flex;
                gap: 0;
                border: 1px solid #ddd;
                margin: 12px 0
            }

            .sum-item {
                flex: 1;
                padding: 10px;
                text-align: center;
                border-right: 1px solid #ddd
            }

            .sum-item:last-child {
                border-right: none
            }

            .sum-item .sk {
                font-size: 7pt;
                text-transform: uppercase;
                letter-spacing: .09em;
                color: #999
            }

            .sum-item .sv {
                font-size: 13pt;
                font-weight: 600;
                font-family: 'IBM Plex Mono', monospace;
                margin-top: 3px
            }

            /* traits */
            .traits-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                margin: 10px 0
            }

            .traits-grid h4 {
                font-size: 8pt;
                text-transform: uppercase;
                letter-spacing: .1em;
                font-weight: 600;
                color: #999;
                margin-bottom: 6px
            }

            .trait-row {
                display: flex;
                justify-content: space-between;
                padding: 4px 0;
                border-bottom: 1px solid #f0f0f0;
                font-size: 8.5pt
            }

            .trait-row .tv {
                font-family: 'IBM Plex Mono', monospace;
                font-weight: 600
            }

            /* comments */
            .cmt {
                margin-bottom: 10px;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee
            }

            .cmt .who {
                font-size: 7.5pt;
                text-transform: uppercase;
                letter-spacing: .09em;
                color: #999;
                margin-bottom: 4px
            }

            .cmt .body {
                font-size: 8.5pt;
                font-weight: 300;
                line-height: 1.55
            }

            /* footer */
            .ftr {
                display: flex;
                justify-content: space-between;
                font-size: 7.5pt;
                color: #bbb;
                margin-top: 14px
            }

            /* actions */
            .actions {
                text-align: center;
                margin-bottom: 20px;
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
                justify-content: center
            }

            .btn {
                padding: 9px 20px;
                border-radius: 4px;
                font-size: 11px;
                font-weight: 600;
                cursor: pointer;
                text-decoration: none;
                border: none;
                font-family: 'IBM Plex Sans', sans-serif;
                display: inline-flex;
                align-items: center;
                gap: 5px
            }

            .btn-print {
                background: #111;
                color: #fff
            }

            .btn-pdf {
                background: #dc2626;
                color: #fff
            }

            .btn-back {
                background: #999;
                color: #fff
            }

            .btn-tpl {
                background: <?php echo $pc; ?>;
                color: #fff
            }
        </style>
    </head>

    <body>

        <div class="no-print actions">
            <a href="?student_id=<?php echo $student['id']; ?>&session=<?php echo urlencode($session); ?>&term=<?php echo urlencode($term); ?>" class="btn btn-tpl">⊞ Change Template</a>
            <button class="btn btn-print" onclick="window.print()">🖨 Print</button>
            <a href="?student_id=<?php echo $student['id']; ?>&session=<?php echo urlencode($session); ?>&term=<?php echo urlencode($term); ?>&template=<?php echo $template; ?>&format=pdf" class="btn btn-pdf">⬇ Download PDF</a>
            <a href="report_cards.php" class="btn btn-back">← Back</a>
        </div>

        <div class="page">
            <div class="hdr">
                <?php echo logoTag($school_logo, 56); ?>
                <div class="hdr-mid">
                    <div class="sname"><?php echo htmlspecialchars($school_name); ?></div>
                    <div class="sub">
                        <?php if ($school_motto): ?><?php echo htmlspecialchars($school_motto); ?> &nbsp;·&nbsp; <?php endif; ?>
                    <?php echo htmlspecialchars($school_phone); ?>
                    <?php if ($school_phone && $school_email): ?> &nbsp;·&nbsp; <?php endif; ?>
                    <?php echo htmlspecialchars($school_email); ?>
                    </div>
                    <div class="title"><?php echo strtoupper($term); ?> TERM REPORT &nbsp;/&nbsp; <?php echo htmlspecialchars($session); ?></div>
                </div>
                <?php echo photoTag($student, 60); ?>
            </div>
            <hr class="thick">

            <div class="info-row">
                <?php
                $cells = [
                    'Student Name'  => strtoupper($student['full_name']),
                    'Admission No.' => $student['admission_number'],
                    'Class'         => $student['class'],
                    'Gender'        => ucfirst($student['gender'] ?? 'N/A'),
                    'Age'           => $age_display ?: 'N/A',
                    'Attendance'    => $attendance_pct . '%',
                ];
                foreach ($cells as $k => $v): ?>
                    <div class="info-cell">
                        <div class="ik"><?php echo $k; ?></div>
                        <div class="iv"><?php echo htmlspecialchars($v); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <hr class="thin" style="margin-top:12px">
            <div style="font-size:8pt;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:#999;margin:10px 0 6px">Academic Performance</div>

            <table class="scores">
                <thead>
                    <tr>
                        <th style="text-align:left">Subject</th>
                        <?php foreach ($score_types as $st): ?><th><?php echo htmlspecialchars($st['name']); ?></th><?php endforeach; ?>
                        <th>Total</th>
                        <th>%</th>
                        <th>Grade</th>
                        <?php if ($show_sub_pos): ?><th>Pos.</th><?php endif; ?>
                        <th>Remark</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1;
                    foreach ($scores as $sc):
                        $sd   = json_decode($sc['score_data'], true);
                        $perc = $sc['percentage'];
                        $gr   = getGrade($perc, $settings['grading_system']);
                        $gc   = gradeColor($gr);
                    ?>
                        <tr>
                            <td><?php echo $i++ . '. ' . htmlspecialchars($sc['subject_name']); ?></td>
                            <?php foreach ($score_types as $st): ?>
                                <td><?php echo isset($sd[$st['name']]) ? number_format($sd[$st['name']], 1) : '—'; ?></td>
                            <?php endforeach; ?>
                            <td><strong><?php echo number_format($sc['total_score'], 1); ?></strong></td>
                            <td><?php echo number_format($perc, 1); ?></td>
                            <td class="grd" style="color:<?php echo $gc; ?>"><?php echo $gr; ?></td>
                            <?php if ($show_sub_pos): ?><td><?php echo ordinal($sc['subject_position'] ?? '—'); ?></td><?php endif; ?>
                            <td><?php echo getRemark($perc); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="sum-flex">
                <div class="sum-item">
                    <div class="sk">Total</div>
                    <div class="sv"><?php echo number_format($total_marks, 1); ?></div>
                </div>
                <div class="sum-item">
                    <div class="sk">Average</div>
                    <div class="sv"><?php echo number_format($overall_average, 1); ?></div>
                </div>
                <div class="sum-item">
                    <div class="sk">%</div>
                    <div class="sv"><?php echo number_format($overall_percentage, 1); ?></div>
                </div>
                <div class="sum-item">
                    <div class="sk">Grade</div>
                    <div class="sv" style="color:<?php echo gradeColor($overall_grade); ?>"><?php echo $overall_grade; ?></div>
                </div>
                <?php if ($show_pos): ?>
                    <div class="sum-item">
                        <div class="sk">Position</div>
                        <div class="sv"><?php echo ordinal($position['class_position'] ?? '—'); ?></div>
                    </div>
                    <div class="sum-item">
                        <div class="sk">Of</div>
                        <div class="sv"><?php echo $class_total; ?></div>
                    </div>
                <?php endif; ?>
                <?php if ($show_hilo || $show_hilo_cls): ?>
                    <div class="sum-item">
                        <div class="sk">Hi Avg</div>
                        <div class="sv"><?php echo number_format($highest_average, 1); ?></div>
                    </div>
                    <div class="sum-item">
                        <div class="sk">Lo Avg</div>
                        <div class="sv"><?php echo number_format($lowest_average, 1); ?></div>
                    </div>
                <?php endif; ?>
            </div>

            <hr class="thin">
            <div style="font-size:8pt;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:#999;margin:10px 0 6px">Behavioural Assessment</div>

            <div class="traits-grid">
                <div>
                    <h4>Affective Traits</h4>
                    <?php foreach ($affective_traits as $key => $label):
                        $val = strtoupper($affective[$key] ?? ''); ?>
                        <div class="trait-row">
                            <span><?php echo $label; ?></span>
                            <span class="tv" style="color:<?php echo $val ? gradeColor($val) : '#bbb'; ?>"><?php echo $val ?: '—'; ?><?php if ($val): ?> <small style="font-weight:400;font-size:7pt;color:#aaa;font-family:'IBM Plex Sans',sans-serif"><?php echo traitLabel($val); ?></small><?php endif; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div>
                    <h4>Psychomotor Skills</h4>
                    <?php foreach ($psychomotor_skills as $key => $label):
                        $val = strtoupper($psychomotor[$key] ?? ''); ?>
                        <div class="trait-row">
                            <span><?php echo $label; ?></span>
                            <span class="tv" style="color:<?php echo $val ? gradeColor($val) : '#bbb'; ?>"><?php echo $val ?: '—'; ?><?php if ($val): ?> <small style="font-weight:400;font-size:7pt;color:#aaa;font-family:'IBM Plex Sans',sans-serif"><?php echo traitLabel($val); ?></small><?php endif; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <hr class="thin">
            <div style="font-size:8pt;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:#999;margin:10px 0 6px">Comments</div>
            <div class="cmt">
                <div class="who">Class Teacher<?php if (!empty($comments['class_teachers_name'])): ?> — <?php echo htmlspecialchars($comments['class_teachers_name']); ?><?php endif; ?></div>
                <div class="body"><?php echo nl2br(htmlspecialchars($comments['teachers_comment'] ?? 'No comment.')); ?></div>
            </div>
            <div class="cmt">
                <div class="who">Principal<?php if (!empty($comments['principals_name'])): ?> — <?php echo htmlspecialchars($comments['principals_name']); ?><?php endif; ?></div>
                <div class="body"><?php echo nl2br(htmlspecialchars($comments['principals_comment'] ?? 'No comment.')); ?></div>
            </div>

            <?php if ($show_promo && !empty($position['promoted_to'])): ?>
                <div style="font-weight:600;font-size:9pt;color:<?php echo $pc; ?>;margin:6px 0">Promoted to: <?php echo htmlspecialchars($position['promoted_to']); ?></div>
            <?php endif; ?>

            <hr class="thick" style="margin-top:12px">
            <div class="ftr">
                <span>Next Term: <?php echo !empty($settings['next_resumption_date']) ? date('F j, Y', strtotime($settings['next_resumption_date'])) : 'TBA'; ?></span>
                <span>Generated <?php echo date('F j, Y'); ?></span>
            </div>
        </div>
    </body>

    </html>
<?php
    return ob_get_clean();
}


// ═══════════════════════════════════════════════════════════════════════════════
// TEMPLATE: BOLD
// ═══════════════════════════════════════════════════════════════════════════════
function tpl_bold($d)
{
    extract($d);
    ob_start();
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <title>Report Card – <?php echo htmlspecialchars($student['full_name']); ?></title>
        <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet">
        <style>
            @media print {
                .no-print {
                    display: none !important
                }

                body {
                    background: #fff;
                    padding: 0
                }

                .page {
                    border-radius: 0
                }
            }

            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0
            }

            body {
                font-family: 'Sora', sans-serif;
                font-size: 9pt;
                background: #e5e7eb;
                padding: 24px
            }

            .page {
                max-width: 210mm;
                margin: 0 auto;
                background: #fff;
                border-radius: 0;
                overflow: hidden
            }

            /* hero header */
            .hero {
                background: <?php echo $pc; ?>;
                padding: 22px 24px;
                display: flex;
                align-items: center;
                gap: 18px;
                position: relative;
                overflow: hidden
            }

            .hero::after {
                content: '';
                position: absolute;
                right: -30px;
                top: -30px;
                width: 180px;
                height: 180px;
                background: rgba(255, 255, 255, .07);
                border-radius: 50%
            }

            .hero::before {
                content: '';
                position: absolute;
                right: 60px;
                bottom: -50px;
                width: 120px;
                height: 120px;
                background: rgba(255, 255, 255, .05);
                border-radius: 50%
            }

            .hero-logo {
                flex-shrink: 0;
                background: rgba(255, 255, 255, .15);
                border-radius: 10px;
                padding: 6px
            }

            .hero-info {
                flex: 1;
                color: #fff
            }

            .hero-info .sname {
                font-size: 16pt;
                font-weight: 800;
                letter-spacing: -.02em
            }

            .hero-info .smeta {
                font-size: 8pt;
                opacity: .75;
                margin-top: 4px
            }

            .hero-info .title-tag {
                display: inline-block;
                background: <?php echo $sc; ?>;
                color: #fff;
                font-size: 8.5pt;
                font-weight: 700;
                padding: 4px 12px;
                border-radius: 4px;
                margin-top: 8px;
                letter-spacing: .03em
            }

            .hero-photo {
                border: 3px solid rgba(255, 255, 255, .4);
                border-radius: 10px;
                overflow: hidden;
                flex-shrink: 0
            }

            /* content */
            .content {
                padding: 18px 24px
            }

            /* student tiles */
            .tiles {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 8px;
                margin-bottom: 16px
            }

            .tile {
                background: #f9fafb;
                border-radius: 8px;
                padding: 10px 12px;
                border-top: 3px solid <?php echo $pc; ?>
            }

            .tile .tk {
                font-size: 7pt;
                text-transform: uppercase;
                letter-spacing: .08em;
                color: #9ca3af;
                font-weight: 600
            }

            .tile .tv {
                font-size: 10pt;
                font-weight: 700;
                color: #111;
                margin-top: 3px
            }

            /* section bar */
            .sec {
                display: flex;
                align-items: center;
                gap: 10px;
                margin: 16px 0 8px
            }

            .sec-tag {
                background: <?php echo $pc; ?>;
                color: #fff;
                font-size: 8pt;
                font-weight: 700;
                padding: 4px 14px;
                border-radius: 4px;
                letter-spacing: .06em;
                text-transform: uppercase;
                white-space: nowrap
            }

            .sec-line {
                flex: 1;
                height: 2px;
                background: #f3f4f6
            }

            /* scores */
            table.scores {
                width: 100%;
                border-collapse: collapse
            }

            table.scores thead tr {
                background: #111
            }

            table.scores thead th {
                color: #fff;
                padding: 7px 8px;
                font-size: 7.5pt;
                font-weight: 700;
                text-align: center;
                text-transform: uppercase;
                letter-spacing: .05em
            }

            table.scores thead th:first-child {
                text-align: left
            }

            table.scores tbody td {
                padding: 6px 8px;
                font-size: 8.5pt;
                border-bottom: 1px solid #f3f4f6;
                text-align: center
            }

            table.scores tbody td:first-child {
                text-align: left;
                font-weight: 500
            }

            table.scores tbody tr:hover td {
                background: #f9fafb
            }

            .score-pill {
                display: inline-block;
                padding: 2px 10px;
                border-radius: 20px;
                font-size: 8pt;
                font-weight: 700
            }

            .pill-A,
            .pill-A1 {
                background: #d1fae5;
                color: #065f46
            }

            .pill-B,
            .pill-B2,
            .pill-B3 {
                background: #dbeafe;
                color: #1e3a8a
            }

            .pill-C,
            .pill-C4,
            .pill-C5,
            .pill-C6 {
                background: #fef3c7;
                color: #92400e
            }

            .pill-D,
            .pill-D7,
            .pill-E8 {
                background: #ffedd5;
                color: #9a3412
            }

            .pill-E,
            .pill-F,
            .pill-F9 {
                background: #fee2e2;
                color: #991b1b
            }

            /* summary hero strip */
            .sum-strip {
                display: flex;
                background: #111;
                border-radius: 10px;
                overflow: hidden;
                margin: 14px 0
            }

            .sum-block {
                flex: 1;
                padding: 12px 6px;
                text-align: center;
                border-right: 1px solid #222
            }

            .sum-block:last-child {
                border-right: none
            }

            .sum-block .sk {
                font-size: 7pt;
                color: #9ca3af;
                text-transform: uppercase;
                letter-spacing: .08em
            }

            .sum-block .sv {
                font-size: 14pt;
                font-weight: 800;
                color: #fff;
                margin-top: 3px
            }

            .sum-block .sv.grade {
                color: <?php echo $sc; ?>
            }

            /* traits */
            .traits-wrap {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 16px
            }

            .trait-section h4 {
                font-size: 8pt;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .1em;
                color: <?php echo $pc; ?>;
                margin-bottom: 8px
            }

            .trait-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 5px 0;
                border-bottom: 1px solid #f3f4f6;
                font-size: 8.5pt
            }

            .trait-badge {
                font-weight: 800;
                font-size: 9pt;
                min-width: 24px;
                text-align: center;
                color: <?php echo $pc; ?>
            }

            /* comments */
            .cmt-wrap {
                background: #f9fafb;
                border-radius: 10px;
                padding: 14px;
                margin-bottom: 10px;
                border-left: 4px solid <?php echo $pc; ?>
            }

            .cmt-wrap .who {
                font-size: 7.5pt;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .08em;
                color: <?php echo $pc; ?>;
                margin-bottom: 5px
            }

            /* footer */
            .ftr {
                background: <?php echo $pc; ?>;
                padding: 12px 24px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                color: rgba(255, 255, 255, .8);
                font-size: 7.5pt
            }

            .ftr strong {
                color: #fff
            }

            /* actions */
            .actions {
                text-align: center;
                margin-bottom: 20px;
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
                justify-content: center
            }

            .btn {
                padding: 9px 20px;
                border-radius: 8px;
                font-size: 11px;
                font-weight: 700;
                cursor: pointer;
                text-decoration: none;
                border: none;
                font-family: 'Sora', sans-serif;
                display: inline-flex;
                align-items: center;
                gap: 5px
            }

            .btn-print {
                background: #111;
                color: #fff
            }

            .btn-pdf {
                background: #dc2626;
                color: #fff
            }

            .btn-back {
                background: #9ca3af;
                color: #fff
            }

            .btn-tpl {
                background: <?php echo $pc; ?>;
                color: #fff
            }
        </style>
    </head>

    <body>

        <div class="no-print actions">
            <a href="?student_id=<?php echo $student['id']; ?>&session=<?php echo urlencode($session); ?>&term=<?php echo urlencode($term); ?>" class="btn btn-tpl">⊞ Change Template</a>
            <button class="btn btn-print" onclick="window.print()">🖨 Print</button>
            <a href="?student_id=<?php echo $student['id']; ?>&session=<?php echo urlencode($session); ?>&term=<?php echo urlencode($term); ?>&template=<?php echo $template; ?>&format=pdf" class="btn btn-pdf">⬇ Download PDF</a>
            <a href="report_cards.php" class="btn btn-back">← Back</a>
        </div>

        <div class="page">
            <div class="hero">
                <div class="hero-logo"><?php echo logoTag($school_logo, 60); ?></div>
                <div class="hero-info">
                    <div class="sname"><?php echo htmlspecialchars($school_name); ?></div>
                    <div class="smeta">
                        <?php if ($school_motto): ?><?php echo htmlspecialchars($school_motto); ?> &nbsp;·&nbsp; <?php endif; ?>
                    <?php echo htmlspecialchars($school_phone); ?>
                    <?php if ($school_phone && $school_email): ?> &nbsp;·&nbsp; <?php endif; ?>
                    <?php echo htmlspecialchars($school_email); ?>
                    </div>
                    <div class="title-tag"><?php echo strtoupper($term); ?> TERM · <?php echo htmlspecialchars($session); ?> · REPORT CARD</div>
                </div>
                <div class="hero-photo"><?php echo photoTag($student, 76); ?></div>
            </div>

            <div class="content">

                <div class="tiles">
                    <?php $tiledata = [
                        'Student Name'  => strtoupper($student['full_name']),
                        'Admission No.' => $student['admission_number'],
                        'Class'         => $student['class'],
                        'Gender'        => ucfirst($student['gender'] ?? 'N/A'),
                        'Age'           => $age_display ?: 'N/A',
                        'Attendance'    => $attendance_pct . '% (' . $days_present . '/' . $days_school_opened . ')',
                    ];
                    foreach ($tiledata as $k => $v): ?>
                        <div class="tile">
                            <div class="tk"><?php echo $k; ?></div>
                            <div class="tv"><?php echo htmlspecialchars($v); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="sec"><span class="sec-tag">Academic Performance</span>
                    <div class="sec-line"></div>
                </div>

                <table class="scores">
                    <thead>
                        <tr>
                            <th style="text-align:left">Subject</th>
                            <?php foreach ($score_types as $st): ?><th><?php echo htmlspecialchars($st['name']); ?></th><?php endforeach; ?>
                            <th>Total</th>
                            <th>%</th>
                            <th>Grade</th>
                            <?php if ($show_sub_pos): ?><th>Pos.</th><?php endif; ?>
                            <th>Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1;
                        foreach ($scores as $sc):
                            $sd   = json_decode($sc['score_data'], true);
                            $perc = $sc['percentage'];
                            $gr   = getGrade($perc, $settings['grading_system']);
                            $pc2  = 'pill-' . str_replace('+', 'plus', $gr);
                        ?>
                            <tr>
                                <td><?php echo $i++ . '. ' . htmlspecialchars($sc['subject_name']); ?></td>
                                <?php foreach ($score_types as $st): ?>
                                    <td><?php echo isset($sd[$st['name']]) ? number_format($sd[$st['name']], 1) : '—'; ?></td>
                                <?php endforeach; ?>
                                <td><strong><?php echo number_format($sc['total_score'], 1); ?></strong></td>
                                <td><?php echo number_format($perc, 1); ?></td>
                                <td><span class="score-pill <?php echo $pc2; ?>"><?php echo $gr; ?></span></td>
                                <?php if ($show_sub_pos): ?><td><?php echo ordinal($sc['subject_position'] ?? '—'); ?></td><?php endif; ?>
                                <td><?php echo getRemark($perc); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="sum-strip">
                    <div class="sum-block">
                        <div class="sk">Total Marks</div>
                        <div class="sv"><?php echo number_format($total_marks, 1); ?></div>
                    </div>
                    <div class="sum-block">
                        <div class="sk">Average</div>
                        <div class="sv"><?php echo number_format($overall_average, 1); ?></div>
                    </div>
                    <div class="sum-block">
                        <div class="sk">Overall %</div>
                        <div class="sv"><?php echo number_format($overall_percentage, 1); ?>%</div>
                    </div>
                    <div class="sum-block">
                        <div class="sk">Grade</div>
                        <div class="sv grade"><?php echo $overall_grade; ?></div>
                    </div>
                    <?php if ($show_pos): ?>
                        <div class="sum-block">
                            <div class="sk">Position</div>
                            <div class="sv"><?php echo ordinal($position['class_position'] ?? '—'); ?></div>
                        </div>
                        <div class="sum-block">
                            <div class="sk">Out Of</div>
                            <div class="sv"><?php echo $class_total; ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($show_hilo || $show_hilo_cls): ?>
                        <div class="sum-block">
                            <div class="sk">Class High</div>
                            <div class="sv"><?php echo number_format($highest_average, 1); ?></div>
                        </div>
                        <div class="sum-block">
                            <div class="sk">Class Low</div>
                            <div class="sv"><?php echo number_format($lowest_average, 1); ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="sec"><span class="sec-tag">Behavioural Assessment</span>
                    <div class="sec-line"></div>
                </div>
                <div class="traits-wrap">
                    <div class="trait-section">
                        <h4>Affective Traits</h4>
                        <?php foreach ($affective_traits as $key => $label):
                            $val = strtoupper($affective[$key] ?? ''); ?>
                            <div class="trait-item">
                                <span><?php echo $label; ?></span>
                                <span>
                                    <span class="trait-badge"><?php echo $val ?: '—'; ?></span>
                                    <?php if ($val): ?><small style="color:#9ca3af;font-size:7.5pt"> <?php echo traitLabel($val); ?></small><?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="trait-section">
                        <h4>Psychomotor Skills</h4>
                        <?php foreach ($psychomotor_skills as $key => $label):
                            $val = strtoupper($psychomotor[$key] ?? ''); ?>
                            <div class="trait-item">
                                <span><?php echo $label; ?></span>
                                <span>
                                    <span class="trait-badge"><?php echo $val ?: '—'; ?></span>
                                    <?php if ($val): ?><small style="color:#9ca3af;font-size:7.5pt"> <?php echo traitLabel($val); ?></small><?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div style="font-size:7pt;color:#9ca3af;margin:5px 0 12px;text-align:center">A=Excellent · B=Very Good · C=Good · D=Average · E=Poor</div>

                <div class="sec"><span class="sec-tag">Comments</span>
                    <div class="sec-line"></div>
                </div>
                <div class="cmt-wrap">
                    <div class="who">Class Teacher<?php if (!empty($comments['class_teachers_name'])): ?> — <?php echo htmlspecialchars($comments['class_teachers_name']); ?><?php endif; ?></div>
                    <?php echo nl2br(htmlspecialchars($comments['teachers_comment'] ?? 'No comment.')); ?>
                </div>
                <div class="cmt-wrap">
                    <div class="who">Principal<?php if (!empty($comments['principals_name'])): ?> — <?php echo htmlspecialchars($comments['principals_name']); ?><?php endif; ?></div>
                    <?php echo nl2br(htmlspecialchars($comments['principals_comment'] ?? 'No comment.')); ?>
                </div>

                <?php if ($show_promo && !empty($position['promoted_to'])): ?>
                    <div style="text-align:center;font-weight:800;font-size:10pt;color:<?php echo $pc; ?>;margin:10px 0">
                        ✓ Promoted to: <?php echo htmlspecialchars($position['promoted_to']); ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="ftr">
                <span>Next Term Resumes: <strong><?php echo !empty($settings['next_resumption_date']) ? date('F j, Y', strtotime($settings['next_resumption_date'])) : 'TBA'; ?></strong></span>
                <span>Generated: <?php echo date('F j, Y \a\t g:i A'); ?></span>
            </div>
        </div>
    </body>

    </html>
<?php
    return ob_get_clean();
}


// ═══════════════════════════════════════════════════════════════════════════════
// BUILD DATA PACKAGE & DISPATCH
// ═══════════════════════════════════════════════════════════════════════════════
$data = compact(
    'student',
    'scores',
    'position',
    'comments',
    'affective',
    'psychomotor',
    'settings',
    'score_types',
    'total_marks',
    'overall_average',
    'overall_percentage',
    'overall_grade',
    'class_total',
    'highest_average',
    'lowest_average',
    'days_present',
    'days_absent',
    'days_school_opened',
    'attendance_pct',
    'age_display',
    'school_name',
    'school_logo',
    'school_phone',
    'school_email',
    'school_motto',
    'primary_color',
    'secondary_color',
    'accent_color',
    'session',
    'term',
    'template',
    'affective_traits',
    'psychomotor_skills',
    'show_pos',
    'show_sub_pos',
    'show_promo',
    'show_hilo',
    'show_hilo_cls'
);

// Shorthand aliases used inside templates
$data['pc'] = $primary_color;
$data['sc'] = $secondary_color;

$tpl_fn = 'tpl_' . $template;
$html   = $tpl_fn($data);

// ─── PDF output ───────────────────────────────────────────────────────────────
if ($format === 'pdf') {
    require_once '../includes/tcpdf/tcpdf.php';

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 9);
    $pdf->writeHTML($html, true, false, true, false, '');

    $filename = 'report_card_'
        . preg_replace('/[^a-zA-Z0-9]/', '_', $student['full_name'])
        . '_' . $session . '_' . $term . '_' . $template . '.pdf';
    $pdf->Output($filename, 'D');
    exit;
}

// ─── HTML output ──────────────────────────────────────────────────────────────
echo $html;
?>
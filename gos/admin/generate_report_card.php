<?php
// gos/admin/generate_report_card.php - Generate Single Report Card (PDF/HTML)
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /gos/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;

$student_id = $_GET['student_id'] ?? null;
$session = $_GET['session'] ?? date('Y') . '/' . (date('Y') + 1);
$term = $_GET['term'] ?? 'First';
$format = $_GET['format'] ?? 'html'; // html or pdf

if (!$student_id) {
    die("Student ID is required!");
}

// Fetch student data with school filter
$stmt = $pdo->prepare("
    SELECT s.*, 
           TIMESTAMPDIFF(YEAR, s.dob, CURDATE()) as age_years,
           TIMESTAMPDIFF(MONTH, s.dob, CURDATE()) % 12 as age_months
    FROM students s 
    WHERE s.id = ? AND s.school_id = ?
");
$stmt->execute([$student_id, $school_id]);
$student = $stmt->fetch();

if (!$student) {
    die("Student not found!");
}

// Fetch scores with subject names
$stmt = $pdo->prepare("
    SELECT ss.*, sub.subject_name 
    FROM student_scores ss 
    JOIN subjects sub ON ss.subject_id = sub.id AND sub.school_id = ?
    WHERE ss.student_id = ? AND ss.session = ? AND ss.term = ?
    ORDER BY sub.subject_name
");
$stmt->execute([$school_id, $student_id, $session, $term]);
$scores = $stmt->fetchAll();

// Fetch position
$stmt = $pdo->prepare("
    SELECT sp.*, 
           (SELECT COUNT(*) FROM students WHERE class = ? AND school_id = ? AND status = 'active') as class_total
    FROM student_positions sp 
    WHERE sp.student_id = ? AND sp.session = ? AND sp.term = ?
");
$stmt->execute([$student['class'], $school_id, $student_id, $session, $term]);
$position = $stmt->fetch();

// Get class total
$class_total = $position ? $position['class_total'] : 0;

// Fetch comments
$stmt = $pdo->prepare("
    SELECT * FROM student_comments 
    WHERE student_id = ? AND session = ? AND term = ?
");
$stmt->execute([$student_id, $session, $term]);
$comments = $stmt->fetch();

// Fetch affective traits
$stmt = $pdo->prepare("
    SELECT * FROM affective_traits 
    WHERE student_id = ? AND session = ? AND term = ?
");
$stmt->execute([$student_id, $session, $term]);
$affective = $stmt->fetch();

// Fetch psychomotor skills
$stmt = $pdo->prepare("
    SELECT * FROM psychomotor_skills 
    WHERE student_id = ? AND session = ? AND term = ?
");
$stmt->execute([$student_id, $session, $term]);
$psychomotor = $stmt->fetch();

// Fetch settings
$stmt = $pdo->prepare("
    SELECT * FROM report_card_settings 
    WHERE class = ? AND session = ? AND term = ? AND school_id = ?
");
$stmt->execute([$student['class'], $session, $term, $school_id]);
$settings = $stmt->fetch();

// Default settings if not found
if (!$settings) {
    $settings = [
        'max_score' => 100,
        'score_types' => json_encode([
            ['name' => 'CA 1', 'max_score' => 20],
            ['name' => 'CA 2', 'max_score' => 20],
            ['name' => 'Exam', 'max_score' => 60]
        ]),
        'grading_system' => 'simple',
        'next_resumption_date' => null,
        'current_resumption_date' => null,
        'current_closing_date' => null,
        'days_school_opened' => 90,
        'show_class_position' => 1,
        'show_subject_position' => 1
    ];
}

$score_types = json_decode($settings['score_types'], true);

// Calculate totals
$total_marks = 0;
$subject_count = count($scores);
foreach ($scores as $score) {
    $total_marks += $score['total_score'];
}
$overall_average = $subject_count > 0 ? ($total_marks / $subject_count) : 0;
$overall_percentage = $settings['max_score'] > 0 ? ($total_marks / ($subject_count * $settings['max_score']) * 100) : 0;

// Get highest/lowest averages in class
$stmt = $pdo->prepare("
    SELECT MAX(sp.average) as highest, MIN(sp.average) as lowest 
    FROM student_positions sp 
    JOIN students s ON sp.student_id = s.id 
    WHERE s.class = ? AND s.school_id = ? AND sp.session = ? AND sp.term = ? AND sp.average > 0
");
$stmt->execute([$student['class'], $school_id, $session, $term]);
$class_stats = $stmt->fetch();
$highest_average = $class_stats['highest'] ?? 0;
$lowest_average = $class_stats['lowest'] ?? 0;

// Attendance
$days_present = $comments['days_present'] ?? 0;
$days_absent = $comments['days_absent'] ?? 0;
$days_school_opened = $settings['days_school_opened'] ?? 90;
$attendance_percentage = $days_school_opened > 0 ? round(($days_present / $days_school_opened) * 100, 1) : 0;

// Age display
$age_display = '';
if ($student['dob']) {
    $age_years = floor((time() - strtotime($student['dob'])) / 31556926);
    $age_display = $age_years . 'yrs';
}

// Helper functions
function ordinal($number)
{
    if (!is_numeric($number)) return $number;
    $ends = array('th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th');
    if ((($number % 100) >= 11) && (($number % 100) <= 13)) return $number . 'th';
    return $number . $ends[$number % 10];
}

function getGrade($percentage, $grading_system = 'simple')
{
    switch ($grading_system) {
        case 'american':
            if ($percentage >= 97) return 'A+';
            if ($percentage >= 93) return 'A';
            if ($percentage >= 90) return 'A-';
            if ($percentage >= 87) return 'B+';
            if ($percentage >= 83) return 'B';
            if ($percentage >= 80) return 'B-';
            if ($percentage >= 77) return 'C+';
            if ($percentage >= 73) return 'C';
            if ($percentage >= 70) return 'C-';
            if ($percentage >= 67) return 'D+';
            if ($percentage >= 63) return 'D';
            if ($percentage >= 60) return 'D-';
            return 'F';
        case 'waec':
            if ($percentage >= 75) return 'A1';
            if ($percentage >= 70) return 'B2';
            if ($percentage >= 65) return 'B3';
            if ($percentage >= 60) return 'C4';
            if ($percentage >= 55) return 'C5';
            if ($percentage >= 50) return 'C6';
            if ($percentage >= 45) return 'D7';
            if ($percentage >= 40) return 'E8';
            return 'F9';
        default:
            if ($percentage >= 70) return 'A';
            if ($percentage >= 60) return 'B';
            if ($percentage >= 50) return 'C';
            if ($percentage >= 45) return 'D';
            if ($percentage >= 40) return 'E';
            return 'F';
    }
}

function convertGradeToRating($grade)
{
    $ratings = ['A' => '5', 'B' => '4', 'C' => '3', 'D' => '2', 'E' => '1', 'F' => ''];
    return $ratings[$grade] ?? '';
}

// Generate HTML
function generateHTML(
    $student,
    $scores,
    $position,
    $comments,
    $affective,
    $psychomotor,
    $settings,
    $score_types,
    $total_marks,
    $overall_average,
    $overall_percentage,
    $class_total,
    $highest_average,
    $lowest_average,
    $days_present,
    $days_absent,
    $days_school_opened,
    $attendance_percentage,
    $age_display,
    $school_name,
    $primary_color,
    $session,
    $term
) {
    ob_start();
?>
    <!DOCTYPE html>
    <html>

    <head>
        <meta charset="UTF-8">
        <title>Report Card - <?php echo htmlspecialchars($student['full_name']); ?></title>
        <style>
            @media print {
                body {
                    margin: 0;
                    padding: 0;
                }

                .no-print {
                    display: none !important;
                }
            }

            * {
                box-sizing: border-box;
            }

            body {
                font-family: 'Segoe UI', Arial, sans-serif;
                font-size: 9pt;
                background: white;
                margin: 0;
                padding: 15px;
            }

            .report-card {
                max-width: 210mm;
                margin: 0 auto;
                background: white;
            }

            .header {
                text-align: center;
                margin-bottom: 10px;
                padding-bottom: 10px;
                border-bottom: 3px solid <?php echo $primary_color; ?>;
            }

            .school-name {
                font-size: 18pt;
                font-weight: bold;
                color: <?php echo $primary_color; ?>;
            }

            .section-title {
                background: <?php echo $primary_color; ?>;
                color: white;
                text-align: center;
                padding: 5px;
                margin: 10px 0;
                font-weight: bold;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 8px;
            }

            table,
            th,
            td {
                border: 1px solid #000;
            }

            td,
            th {
                padding: 5px 8px;
                vertical-align: top;
            }

            .label {
                font-weight: bold;
                background: #f5f5f5;
                width: 18%;
            }

            .scores-table th {
                background: <?php echo $primary_color; ?>;
                color: white;
                text-align: center;
            }

            .scores-table td {
                text-align: center;
            }

            .scores-table td:first-child {
                text-align: left;
            }

            .grade-A,
            .grade-A1,
            .grade-Aplus {
                color: #27ae60;
                font-weight: bold;
            }

            .grade-B,
            .grade-B2,
            .grade-B3,
            .grade-Bplus {
                color: #2ecc71;
                font-weight: bold;
            }

            .grade-C,
            .grade-C4,
            .grade-C5,
            .grade-C6,
            .grade-Cplus {
                color: #f39c12;
                font-weight: bold;
            }

            .grade-D,
            .grade-D7,
            .grade-Dplus {
                color: #e67e22;
                font-weight: bold;
            }

            .grade-E,
            .grade-E8,
            .grade-F,
            .grade-F9 {
                color: #e74c3c;
                font-weight: bold;
            }

            .traits-row {
                display: flex;
                gap: 20px;
                margin: 10px 0;
            }

            .traits-column {
                flex: 1;
            }

            .rating-circle {
                display: inline-block;
                width: 22px;
                height: 22px;
                line-height: 22px;
                text-align: center;
                border-radius: 50%;
                font-size: 10px;
                font-weight: bold;
                margin-left: 5px;
            }

            .rating-5 {
                background: #27ae60;
                color: white;
            }

            .rating-4 {
                background: #2ecc71;
                color: white;
            }

            .rating-3 {
                background: #f39c12;
                color: white;
            }

            .rating-2 {
                background: #e67e22;
                color: white;
            }

            .rating-1 {
                background: #e74c3c;
                color: white;
            }

            .comments-section div {
                border: 1px solid #ddd;
                padding: 8px;
                margin: 5px 0;
                min-height: 50px;
            }

            .footer {
                text-align: center;
                font-size: 8pt;
                margin-top: 15px;
                padding-top: 10px;
                border-top: 1px solid #ccc;
            }

            .btn {
                display: inline-block;
                padding: 8px 16px;
                margin: 5px;
                background: <?php echo $primary_color; ?>;
                color: white;
                text-decoration: none;
                border-radius: 5px;
                font-size: 12px;
            }

            .btn-print {
                background: #3498db;
            }

            .btn-pdf {
                background: #e74c3c;
            }
        </style>
    </head>

    <body>
        <div class="no-print" style="text-align:center; margin-bottom:15px;">
            <button class="btn btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            <a href="?student_id=<?php echo $student['id']; ?>&session=<?php echo $session; ?>&term=<?php echo $term; ?>&format=pdf" class="btn btn-pdf"><i class="fas fa-file-pdf"></i> Download PDF</a>
            <a href="report_cards.php" class="btn" style="background:#95a5a6;"><i class="fas fa-arrow-left"></i> Back</a>
        </div>

        <div class="report-card">
            <div class="header">
                <div class="school-name"><?php echo htmlspecialchars($school_name); ?></div>
                <div><small><?php echo defined('SCHOOL_MOTTO') ? htmlspecialchars(SCHOOL_MOTTO) : 'Knowledge Shared is Power'; ?></small></div>
                <div><small><?php echo defined('SCHOOL_ADDRESS') ? htmlspecialchars(SCHOOL_ADDRESS) : ''; ?></small></div>
            </div>

            <div class="section-title"><?php echo strtoupper($term); ?> TERM <?php echo $session; ?> REPORT CARD</div>

            <table>
                <tr>
                    <td class="label">Student Name</td>
                    <td colspan="3"><strong><?php echo strtoupper(htmlspecialchars($student['full_name'])); ?></strong></td>
                    <td class="label">Admission No</td>
                    <td><?php echo htmlspecialchars($student['admission_number']); ?></td>
                </tr>
                <tr>
                    <td class="label">Class</td>
                    <td><?php echo htmlspecialchars($student['class']); ?></td>
                    <td class="label">Term</td>
                    <td><?php echo $term; ?> Term</td>
                    <td class="label">Session</td>
                    <td><?php echo $session; ?></td>
                </tr>
                <tr>
                    <td class="label">Age</td>
                    <td><?php echo $age_display; ?></td>
                    <td class="label">Gender</td>
                    <td><?php echo ucfirst($student['gender'] ?? 'N/A'); ?></td>
                    <td class="label">Days School Opened</td>
                    <td><?php echo $days_school_opened; ?></td>
                </tr>
                <tr>
                    <td class="label">Days Present</td>
                    <td><?php echo $days_present; ?></td>
                    <td class="label">Days Absent</td>
                    <td><?php echo $days_absent; ?></td>
                    <td class="label">Attendance %</td>
                    <td><?php echo $attendance_percentage; ?>%</td>
                </tr>
            </table>

            <div class="section-title">ACADEMIC PERFORMANCE</div>
            <table class="scores-table">
                <thead>
                    <tr>
                        <th>SUBJECT</th><?php foreach ($score_types as $type): ?><th><?php echo htmlspecialchars($type['name']); ?></th><?php endforeach; ?><th>Total</th>
                        <th>%</th>
                        <th>Grade</th>
                        <th>Position</th>
                        <th>Remark</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $counter = 1;
                    foreach ($scores as $score):
                        $score_data = json_decode($score['score_data'], true);
                        $perc = $score['percentage'];
                        $grade = getGrade($perc, $settings['grading_system']);
                        $grade_class = 'grade-' . str_replace('+', 'plus', $grade);
                    ?>
                        <tr>
                            <td style="text-align:left"><?php echo $counter++ . '. ' . htmlspecialchars($score['subject_name']); ?></td>
                            <?php foreach ($score_types as $type): ?>
                                <td><?php echo isset($score_data[$type['name']]) ? number_format($score_data[$type['name']], 1) : '-'; ?></td>
                            <?php endforeach; ?>
                            <td><?php echo number_format($score['total_score'], 1); ?></td>
                            <td><?php echo number_format($perc, 1); ?>%</td>
                            <td class="<?php echo $grade_class; ?>"><?php echo $grade; ?></td>
                            <td><?php echo ordinal($score['subject_position'] ?? '-'); ?></td>
                            <td><?php echo $perc >= 50 ? 'Pass' : 'Fail'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php for ($i = count($scores) + 1; $i <= 15; $i++): ?>
                        <tr>
                            <td style="text-align:left"><?php echo $i; ?>. </td><?php foreach ($score_types as $type): ?><td>-</td><?php endforeach; ?><td>-</td>
                            <td>-</td>
                            <td>-</td>
                            <td>-</td>
                            <td>-</td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>

            <div class="traits-row">
                <div class="traits-column">
                    <div class="section-title" style="background: <?php echo $primary_color; ?>;">AFFECTIVE TRAITS</div>
                    <table>
                        <?php $traits = ['punctuality' => 'Punctuality', 'neatness' => 'Neatness', 'honesty' => 'Honesty', 'reliability' => 'Reliability', 'relationship' => 'Relationship', 'politeness' => 'Politeness']; ?>
                        <?php foreach ($traits as $key => $label): ?>
                            <?php $val = $affective[$key] ?? '';
                            $num = convertGradeToRating($val); ?>
                            <tr>
                                <td><?php echo $label; ?></td>
                                <td><strong><?php echo $val ?: '-'; ?></strong> <?php if ($num): ?><span class="rating-circle rating-<?php echo $num; ?>"><?php echo $num; ?></span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <div class="traits-column">
                    <div class="section-title" style="background: <?php echo $primary_color; ?>;">PSYCHOMOTOR SKILLS</div>
                    <table>
                        <?php $skills = ['handwriting' => 'Handwriting', 'verbal_fluency' => 'Verbal Fluency', 'sports' => 'Sports', 'handling_tools' => 'Handling Tools', 'drawing_painting' => 'Drawing/Painting', 'musical_skills' => 'Musical Skills']; ?>
                        <?php foreach ($skills as $key => $label): ?>
                            <?php $val = $psychomotor[$key] ?? '';
                            $num = convertGradeToRating($val); ?>
                            <tr>
                                <td><?php echo $label; ?></td>
                                <td><strong><?php echo $val ?: '-'; ?></strong> <?php if ($num): ?><span class="rating-circle rating-<?php echo $num; ?>"><?php echo $num; ?></span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>

            <table class="rating-key" style="width:auto; margin:10px auto;">
                <tr>
                    <td>5: Excellent</td>
                    <td>4: Very Good</td>
                    <td>3: Good</td>
                    <td>2: Pass</td>
                    <td>1: Poor</td>
                </tr>
            </table>

            <div class="comments-section">
                <div><strong>Teacher's Comment:</strong> <?php echo nl2br(htmlspecialchars($comments['teachers_comment'] ?? 'No comment.')); ?></div>
                <div><strong>Principal's Comment:</strong> <?php echo nl2br(htmlspecialchars($comments['principals_comment'] ?? 'No comment.')); ?></div>
            </div>

            <div class="footer">
                <strong>Next Term Resumption:</strong> <?php echo !empty($settings['next_resumption_date']) ? date('F j, Y', strtotime($settings['next_resumption_date'])) : 'TBA'; ?>
                <br><i>Generated on: <?php echo date('F j, Y \a\t g:i A'); ?></i>
            </div>
        </div>
    </body>

    </html>
<?php
    return ob_get_clean();
}

// Handle PDF download
if ($format === 'pdf') {
    $html = generateHTML(
        $student,
        $scores,
        $position,
        $comments,
        $affective,
        $psychomotor,
        $settings,
        $score_types,
        $total_marks,
        $overall_average,
        $overall_percentage,
        $class_total,
        $highest_average,
        $lowest_average,
        $days_present,
        $days_absent,
        $days_school_opened,
        $attendance_percentage,
        $age_display,
        $school_name,
        $primary_color,
        $session,
        $term
    );

    require_once '../includes/tcpdf/tcpdf.php';

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 9);
    $pdf->writeHTML($html, true, false, true, false, '');

    $filename = 'report_card_' . preg_replace('/[^a-zA-Z0-9]/', '_', $student['full_name']) . '_' . $session . '_' . $term . '.pdf';
    $pdf->Output($filename, 'D');
    exit;
}

// Output HTML
echo generateHTML(
    $student,
    $scores,
    $position,
    $comments,
    $affective,
    $psychomotor,
    $settings,
    $score_types,
    $total_marks,
    $overall_average,
    $overall_percentage,
    $class_total,
    $highest_average,
    $lowest_average,
    $days_present,
    $days_absent,
    $days_school_opened,
    $attendance_percentage,
    $age_display,
    $school_name,
    $primary_color,
    $session,
    $term
);
?>